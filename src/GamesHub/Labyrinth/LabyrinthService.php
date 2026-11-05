<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Labyrinth;

use Kai\Tools\GamesHub\RoomRepository;

/**
 * Orchestriert Lobby, Spielzüge, Bots und die Zero-Knowledge-Projektion
 * für "Das verrückte Labyrinth".
 *
 * Der komplette Spielzustand (inkl. verdeckter Zielkarten) liegt atomar in
 * games_hub_rooms.state_json; Schreibzugriffe laufen über die state_version
 * (optimistisches Locking), damit parallele Requests keine Züge doppeln.
 */
class LabyrinthService
{
    public const MAX_PLAYERS = 4;
    private const BOT_DELAY_SECONDS = 2;
    private const PAWN_COLORS = ['yellow', 'green', 'red', 'blue'];

    private RoomRepository $repo;

    public function __construct(?RoomRepository $repo = null)
    {
        $this->repo = $repo ?? new RoomRepository();
    }

    public function getInitialState(string $gameMode = 'standard', string $botDifficulty = LabyrinthBot::DIFFICULTY_NORMAL): array
    {
        if (!array_key_exists($botDifficulty, LabyrinthBot::DIFFICULTIES)) {
            $botDifficulty = LabyrinthBot::DIFFICULTY_NORMAL;
        }

        return [
            'game_mode' => $gameMode,
            'bot_difficulty' => $botDifficulty,
            'status' => 'lobby',
            'cards_per_player' => 0,
            'phase' => 'shift',
            'active_player_id' => 0,
            'order' => [],
            'board' => [],
            'spare' => null,
            'forbidden' => null,
            'pawns' => [],
            'winner_id' => null,
            'winner_name' => null,
            'last_shift' => null,
            'last_event' => $this->event('room_created', 'Labyrinth-Raum eröffnet. Warte auf Mitspieler...'),
        ];
    }

    public function addBot(int $roomId, string $botName): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'lobby') {
            return ['success' => false, 'message' => 'Bots können nur in der Lobby hinzugefügt werden'];
        }
        if (count($this->repo->getPlayersForRoom($roomId)) >= self::MAX_PLAYERS) {
            return ['success' => false, 'message' => 'Tisch ist voll (maximal 4 Spieler)'];
        }

        $botTokenHash = hash('sha256', 'bot_' . bin2hex(random_bytes(16)));
        $player = $this->repo->joinPlayer($roomId, $botTokenHash, $botName, false, ['is_bot' => true]);

        return ['success' => true, 'player' => ['id' => $player['id'], 'player_name' => $botName, 'is_bot' => true]];
    }

    public function removeBot(int $roomId, int $botPlayerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'lobby') {
            return ['success' => false, 'message' => 'Bots können nur in der Lobby entfernt werden'];
        }
        $bot = $this->repo->getPlayerById($botPlayerId);
        if (!$bot || (int)$bot['room_id'] !== $roomId || empty($bot['sheet']['is_bot'])) {
            return ['success' => false, 'message' => 'Bot nicht gefunden'];
        }
        $this->repo->removePlayer($roomId, $botPlayerId);
        return ['success' => true];
    }

    public function startGame(int $roomId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'lobby') {
            return ['success' => false, 'message' => 'Spiel kann nicht gestartet werden'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $count = count($players);
        if ($count < 2 || $count > self::MAX_PLAYERS) {
            return ['success' => false, 'message' => 'Es werden 2 bis 4 Spieler (oder Bots) benötigt'];
        }

        $state = $room['state'];
        $cardsPerPlayer = match ($state['game_mode'] ?? 'standard') {
            'fast' => 3,
            'classic' => intdiv(count(LabyrinthBoard::TREASURES), $count),
            default => 5,
        };

        $generated = LabyrinthBoard::generate();
        $deck = array_keys(LabyrinthBoard::TREASURES);
        shuffle($deck);

        $pawns = [];
        $order = [];
        foreach ($players as $seat => $player) {
            $color = self::PAWN_COLORS[$seat];
            $start = LabyrinthBoard::START_CORNERS[$color];
            $pawns[(int)$player['id']] = [
                'color' => $color,
                'start' => $start,
                'pos' => $start,
                'deck' => array_splice($deck, 0, $cardsPerPlayer),
                'collected' => [],
            ];
            $order[] = (int)$player['id'];
        }

        $state['status'] = 'active';
        $state['cards_per_player'] = $cardsPerPlayer;
        $state['board'] = $generated['board'];
        $state['spare'] = $generated['spare'];
        $state['phase'] = 'shift';
        $state['order'] = $order;
        $state['active_player_id'] = $order[0];
        $state['forbidden'] = null;
        $state['pawns'] = $pawns;
        $state['winner_id'] = null;
        $state['winner_name'] = null;
        $state['last_event'] = $this->event('game_started', "Das Spiel beginnt! {$players[0]['player_name']} ist am Zug.");

        if (!$this->persist($room, $state, 'active')) {
            return ['success' => false, 'message' => 'Zustand hat sich geändert, bitte erneut versuchen'];
        }
        return ['success' => true];
    }

    /**
     * Phase 1: freie Kachel mit der gewählten Drehung einschieben.
     */
    public function shiftTile(int $roomId, int $playerId, string $side, int $index, int $rotation): array
    {
        $room = $this->repo->getRoomById($roomId);
        $check = $this->assertTurn($room, $playerId, 'shift');
        if ($check !== null) {
            return $check;
        }

        $state = $room['state'];
        if (!LabyrinthBoard::isValidShift($side, $index)) {
            return ['success' => false, 'message' => 'Ungültiger Einschub'];
        }
        if (!in_array($rotation, [0, 90, 180, 270], true)) {
            return ['success' => false, 'message' => 'Ungültige Drehung'];
        }
        if ($state['forbidden'] && $state['forbidden']['side'] === $side && (int)$state['forbidden']['index'] === $index) {
            return ['success' => false, 'message' => 'Der letzte Einschub darf nicht direkt rückgängig gemacht werden'];
        }

        $state = $this->applyShift($state, $playerId, $side, $index, $rotation, $this->playerName($roomId, $playerId));
        if (!$this->persist($room, $state)) {
            return ['success' => false, 'message' => 'Zustand hat sich geändert, bitte erneut versuchen'];
        }
        return ['success' => true];
    }

    /**
     * Phase 2: Figur auf ein erreichbares Feld ziehen und Zug beenden.
     */
    public function movePawn(int $roomId, int $playerId, int $row, int $col): array
    {
        $room = $this->repo->getRoomById($roomId);
        $check = $this->assertTurn($room, $playerId, 'move');
        if ($check !== null) {
            return $check;
        }

        $state = $room['state'];
        $pos = $state['pawns'][$playerId]['pos'];
        $reachable = LabyrinthBoard::reachable($state['board'], (int)$pos[0], (int)$pos[1]);
        if (!in_array([$row, $col], $reachable, true)) {
            return ['success' => false, 'message' => 'Dieses Feld ist nicht erreichbar'];
        }

        $state = $this->applyMove($state, $playerId, [$row, $col], $this->playerName($roomId, $playerId));
        $status = $state['status'] === 'finished' ? 'finished' : null;
        if (!$this->persist($room, $state, $status)) {
            return ['success' => false, 'message' => 'Zustand hat sich geändert, bitte erneut versuchen'];
        }
        return ['success' => true, 'game_finished' => $state['status'] === 'finished'];
    }

    /**
     * Ändert die Bot-Schwierigkeitsstufe in der Lobby (nur Host).
     */
    public function setBotDifficulty(int $roomId, string $difficulty): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'lobby') {
            return ['success' => false, 'message' => 'Schwierigkeitsgrad kann nur in der Lobby geändert werden'];
        }
        if (!array_key_exists($difficulty, LabyrinthBot::DIFFICULTIES)) {
            return ['success' => false, 'message' => 'Ungültiger Schwierigkeitsgrad'];
        }

        $state = $room['state'];
        $state['bot_difficulty'] = $difficulty;
        $label = LabyrinthBot::DIFFICULTIES[$difficulty];
        $state['last_event'] = $this->event('difficulty_changed', "Bot-Stufe wurde auf {$label} gesetzt.");

        if (!$this->persist($room, $state)) {
            return ['success' => false, 'message' => 'Zustand hat sich geändert, bitte erneut versuchen'];
        }
        return ['success' => true, 'bot_difficulty' => $difficulty];
    }

    /**
     * Setzt ein beendetes Spiel zurück in die Lobby (Revanche im selben Raum).
     */
    public function rematch(int $roomId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'finished') {
            return ['success' => false, 'message' => 'Revanche ist nur nach Spielende möglich'];
        }
        $state = $this->getInitialState(
            (string)($room['state']['game_mode'] ?? 'standard'),
            (string)($room['state']['bot_difficulty'] ?? LabyrinthBot::DIFFICULTY_NORMAL)
        );
        $state['last_event'] = $this->event('rematch', 'Neue Runde! Der Host kann das Spiel erneut starten.');
        if (!$this->persist($room, $state, 'lobby')) {
            return ['success' => false, 'message' => 'Zustand hat sich geändert, bitte erneut versuchen'];
        }
        return ['success' => true];
    }

    /**
     * Führt den kompletten Zug des aktiven Bots aus (nach kurzer Denkpause).
     */
    public function triggerBotTurnIfNeeded(int $roomId): bool
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'active') {
            return false;
        }

        $state = $room['state'];
        if (time() - (int)($state['last_event']['time'] ?? 0) < self::BOT_DELAY_SECONDS) {
            return false;
        }

        $botId = (int)$state['active_player_id'];
        $bot = null;
        foreach ($this->repo->getPlayersForRoom($roomId) as $player) {
            if ((int)$player['id'] === $botId && !empty($player['sheet']['is_bot'])) {
                $bot = $player;
            }
        }
        if ($bot === null || !isset($state['pawns'][$botId])) {
            return false;
        }

        $pawn = $state['pawns'][$botId];
        $positions = array_map(fn(array $p) => $p['pos'], $state['pawns']);
        $botDifficulty = (string)($state['bot_difficulty'] ?? LabyrinthBot::DIFFICULTY_NORMAL);

        $decision = LabyrinthBot::decide(
            $state['board'],
            $state['spare'],
            $state['forbidden'],
            $positions,
            $botId,
            $pawn['deck'][0] ?? null,
            $pawn['start'],
            $botDifficulty
        );

        $state = $this->applyShift($state, $botId, $decision['side'], $decision['index'], $decision['rotation'], $bot['player_name']);
        $state = $this->applyMove($state, $botId, $decision['move_to'], $bot['player_name'], $state['last_event']['message']);

        return $this->persist($room, $state, $state['status'] === 'finished' ? 'finished' : null);
    }

    /**
     * Zero-Knowledge-Projektion: Zielkarten anderer Spieler bleiben verborgen.
     */
    public function getPublicStateForPlayer(array $room, array $myPlayer, array $players): array
    {
        $state = $room['state'] ?? [];
        $active = ($state['status'] ?? 'lobby') === 'active';
        $activeId = (int)($state['active_player_id'] ?? 0);
        $myId = (int)$myPlayer['id'];
        $pawns = $state['pawns'] ?? [];

        $publicPlayers = [];
        foreach ($players as $p) {
            $pid = (int)$p['id'];
            $pawn = $pawns[$pid] ?? null;
            $isMe = $pid === $myId;
            $publicPlayers[] = [
                'id' => $pid,
                'player_name' => $p['player_name'],
                'is_host' => (bool)$p['is_host'],
                'is_bot' => (bool)($p['sheet']['is_bot'] ?? false),
                'is_active' => $pid === $activeId && $active,
                'is_me' => $isMe,
                'color' => $pawn['color'] ?? null,
                'pos' => $pawn['pos'] ?? null,
                'start' => $pawn['start'] ?? null,
                'remaining' => $pawn ? count($pawn['deck']) : 0,
                'collected' => $pawn['collected'] ?? [],
                'returning_home' => $pawn && $active && count($pawn['deck']) === 0,
            ];
        }

        $myTarget = null;
        $myTargetCell = null;
        $reachable = [];
        if ($active && isset($pawns[$myId])) {
            $mine = $pawns[$myId];
            $myTarget = $mine['deck'][0] ?? 'home';
            $myTargetCell = $myTarget === 'home'
                ? $mine['start']
                : LabyrinthBoard::findTreasure($state['board'], $myTarget);
            if ($activeId === $myId && ($state['phase'] ?? '') === 'move') {
                $reachable = LabyrinthBoard::reachable($state['board'], (int)$mine['pos'][0], (int)$mine['pos'][1]);
            }
        }

        $catalog = [];
        foreach (LabyrinthBoard::TREASURES as $id => [$emoji, $name]) {
            $catalog[$id] = ['emoji' => $emoji, 'name' => $name];
        }

        return [
            'room_code' => $room['room_code'],
            'status' => $state['status'] ?? 'lobby',
            'game_mode' => $state['game_mode'] ?? 'standard',
            'bot_difficulty' => $state['bot_difficulty'] ?? LabyrinthBot::DIFFICULTY_NORMAL,
            'cards_per_player' => (int)($state['cards_per_player'] ?? 0),
            'phase' => $state['phase'] ?? 'shift',
            'active_player_id' => $activeId,
            'is_my_turn' => $active && $activeId === $myId,
            'board' => array_map(
                fn(array $row) => array_map([$this, 'withOpenings'], $row),
                $state['board'] ?? []
            ),
            'spare' => isset($state['spare']) ? $this->withOpenings($state['spare']) : null,
            'forbidden' => $state['forbidden'] ?? null,
            'treasures' => $catalog,
            'players' => $publicPlayers,
            'my_target' => $myTarget,
            'my_target_cell' => $myTargetCell,
            'reachable' => $reachable,
            'winner_id' => $state['winner_id'] ?? null,
            'winner_name' => $state['winner_name'] ?? null,
            'last_shift' => $state['last_shift'] ?? null,
            'last_event' => $state['last_event'] ?? null,
        ];
    }

    private function applyShift(array $state, int $playerId, string $side, int $index, int $rotation, string $playerName): array
    {
        $spare = $state['spare'];
        $spare['rot'] = $rotation;

        $positions = array_map(fn(array $p) => $p['pos'], $state['pawns']);
        $result = LabyrinthBoard::shift($state['board'], $spare, $side, $index, $positions);

        $state['board'] = $result['board'];
        $state['spare'] = $result['spare'];
        foreach ($result['pawns'] as $pid => $pos) {
            $state['pawns'][$pid]['pos'] = $pos;
        }
        $state['forbidden'] = ['side' => LabyrinthBoard::oppositeSide($side), 'index' => $index];
        $state['phase'] = 'move';

        $where = match ($side) {
            'TOP' => 'oben in Spalte ' . ($index + 1),
            'BOTTOM' => 'unten in Spalte ' . ($index + 1),
            'LEFT' => 'links in Reihe ' . ($index + 1),
            default => 'rechts in Reihe ' . ($index + 1),
        };
        $state['last_shift'] = [
            'side' => $side,
            'index' => $index,
            'rotation' => $rotation,
            'player_id' => $playerId,
            'player_name' => $playerName,
            'where' => $where,
            'shift_id' => 'sh_' . microtime(true) . '_' . bin2hex(random_bytes(2)),
        ];
        $state['last_event'] = $this->event('shift', "{$playerName} hat die Kachel {$where} eingeschoben.", [
            'player_id' => $playerId,
            'side' => $side,
            'index' => $index,
        ]);

        return $state;
    }

    private function applyMove(array $state, int $playerId, array $cell, string $playerName, string $prefix = ''): array
    {
        $pawn = &$state['pawns'][$playerId];
        $pawn['pos'] = [(int)$cell[0], (int)$cell[1]];

        $message = 'hat seine Figur bewegt.';
        $tile = $state['board'][$cell[0]][$cell[1]];
        if (!empty($pawn['deck']) && ($tile['treasure'] ?? null) === $pawn['deck'][0]) {
            $found = array_shift($pawn['deck']);
            $pawn['collected'][] = $found;
            [$emoji, $name] = LabyrinthBoard::TREASURES[$found];
            $message = "hat {$emoji} {$name} gefunden!";
            if (empty($pawn['deck'])) {
                $message .= ' Jetzt zurück zum Startfeld!';
            }
        }

        $type = 'move';
        if (empty($pawn['deck']) && $pawn['pos'] === $pawn['start'] && !empty($pawn['collected'])) {
            $state['status'] = 'finished';
            $state['winner_id'] = $playerId;
            $state['winner_name'] = $playerName;
            $message = 'ist zurück am Startfeld und gewinnt das Labyrinth! 🏆';
            $type = 'game_won';
        }
        unset($pawn);

        $text = trim($prefix . ' ' . "{$playerName} {$message}");
        if ($state['status'] === 'active') {
            $state['phase'] = 'shift';
            $order = $state['order'];
            $next = $order[(array_search($playerId, $order, true) + 1) % count($order)];
            $state['active_player_id'] = $next;
        }
        $state['last_event'] = $this->event($type, $text, ['player_id' => $playerId]);

        return $state;
    }

    /**
     * Prüft Raum, Spielstatus, Zugrecht und Phase. Gibt bei Fehler die Antwort zurück, sonst null.
     */
    private function assertTurn(?array $room, int $playerId, string $phase): ?array
    {
        if (!$room || $room['status'] !== 'active') {
            return ['success' => false, 'message' => 'Spiel ist nicht aktiv'];
        }
        $state = $room['state'];
        if ((int)$state['active_player_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Du bist nicht am Zug'];
        }
        if (($state['phase'] ?? '') !== $phase) {
            return ['success' => false, 'message' => $phase === 'shift' ? 'Du musst die Figur ziehen' : 'Zuerst die Kachel einschieben'];
        }
        return null;
    }

    private function persist(array $room, array $state, ?string $status = null): bool
    {
        return $this->repo->updateRoomStateIfVersion((int)$room['id'], $state, (int)$room['state_version'], $status);
    }

    private function playerName(int $roomId, int $playerId): string
    {
        $player = $this->repo->getPlayerById($playerId);
        return $player && (int)$player['room_id'] === $roomId ? (string)$player['player_name'] : 'Spieler';
    }

    /**
     * Ergänzt eine Kachel um ihre offenen Seiten (0=N,1=E,2=S,3=W) für die Darstellung.
     */
    private function withOpenings(array $tile): array
    {
        $tile['open'] = LabyrinthBoard::openings($tile);
        return $tile;
    }

    private function event(string $type, string $message, array $extra = []): array
    {
        return $extra + [
            'type' => $type,
            'event_id' => 'lb_evt_' . microtime(true) . '_' . bin2hex(random_bytes(3)),
            'message' => $message,
            'time' => time(),
        ];
    }
}
