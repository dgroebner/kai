<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Loch6;

use Kai\Tools\GamesHub\RoomRepository;

class Loch6Service
{
    public const DEFAULT_STONES = 15;
    public const MINERAL_TYPES = ['amethyst', 'smaragd', 'pyrit', 'rosenquarz', 'lapislazuli', 'obsidian'];

    private RoomRepository $repo;

    public function __construct(?RoomRepository $repo = null)
    {
        $this->repo = $repo ?? new RoomRepository();
    }

    /**
     * Ermittelt die optimale Anzahl an Startsteinen anhand der Teilnehmerzahl am Tisch.
     * Sorgt für ideale Spieldauer und maximale Push-Your-Luck Spannung auf dem 15-Mulden-Brett.
     */
    public static function getRecommendedStones(int $playerCount): int
    {
        return match (true) {
            $playerCount <= 2 => 15, // 2 Spieler: 15 Steine laut Original-Regel (30 im Spiel)
            $playerCount === 3 => 10, // 3 Spieler: 10 Steine (30 im Spiel)
            $playerCount === 4 => 8,  // 4 Spieler: 8 Steine (32 im Spiel)
            $playerCount === 5 => 6,  // 5 Spieler: 6 Steine (30 im Spiel)
            default => 5,             // 6 oder mehr Spieler: 5 Steine (30 im Spiel)
        };
    }

    /**
     * Synchronisiert die Steine aller Spieler in der Lobby, falls dynamischer Modus aktiv ist.
     */
    public function syncLobbyPlayerStones(int $roomId): void
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'lobby') {
            return;
        }

        if (!empty($room['state']['starting_stones_fixed'])) {
            return;
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $playerCount = count($players);
        $stones = self::getRecommendedStones($playerCount);

        foreach ($players as $p) {
            $isBot = !empty($p['sheet']['is_bot']);
            $newSheet = $this->getInitialSheet($stones, $isBot);
            $this->repo->updatePlayerSheet((int)$p['id'], $newSheet, $stones);
        }

        $room['state']['starting_stones'] = $stones;
        $this->repo->updateRoomState($roomId, $room['state']);
    }

    /**
     * Erzeugt das initiale Spielblatt für einen Spieler mit prozeduralen Mineral-Steinen.
     */
    public function getInitialSheet(int $stoneCount = self::DEFAULT_STONES, bool $isBot = false): array
    {
        $stones = [];
        $typesCount = count(self::MINERAL_TYPES);
        for ($i = 0; $i < $stoneCount; $i++) {
            $type = self::MINERAL_TYPES[$i % $typesCount];
            $stones[] = [
                'id' => bin2hex(random_bytes(4)),
                'type' => $type,
                'seed' => random_int(100, 999),
            ];
        }

        return [
            'stones' => $stones,
            'is_bot' => $isBot,
            'initial_count' => $stoneCount,
            'bust_count' => 0,
            'hole6_count' => 0,
        ];
    }

    /**
     * Erzeugt den Anfangszustand eines Loch-6-Spiels.
     */
    public function getInitialState(int $stoneCount = self::DEFAULT_STONES): array
    {
        return [
            'starting_stones' => $stoneCount,
            'round_number' => 1,
            'active_player_id' => null,
            'active_player_index' => 0,
            'rolls_in_turn' => 0,
            'can_pass' => false,
            'dice' => [1],
            'has_rolled' => false,
            'roll_seq' => 0,
            'board' => [
                1 => [null],
                2 => [null, null],
                3 => [null, null, null],
                4 => [null, null, null, null],
                5 => [null, null, null, null, null],
            ],
            'hole6_count' => 0,
            'last_event' => [
                'type' => 'lobby_created',
                'message' => 'Lobby für Loch 6 erstellt. Warte auf Mitspieler...',
                'time' => time(),
            ],
            'winner_id' => null,
            'winner_name' => null,
        ];
    }

    /**
     * Startet das Spiel aus der Lobby.
     */
    public function startGame(int $roomId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'lobby') {
            return ['success' => false, 'message' => 'Spiel kann nicht gestartet werden'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $playerCount = count($players);
        if ($playerCount < 1) {
            return ['success' => false, 'message' => 'Mindestens 1 Spieler erforderlich'];
        }

        $isFixed = !empty($room['state']['starting_stones_fixed']);
        if ($isFixed && is_numeric($room['state']['starting_stones'])) {
            $finalStones = (int)$room['state']['starting_stones'];
        } else {
            $finalStones = self::getRecommendedStones($playerCount);
        }

        $state = $this->getInitialState($finalStones);
        $state['starting_stones_fixed'] = $isFixed;
        $state['starting_stones_mode'] = $isFixed ? 'fixed' : 'auto';
        $startPlayer = $players[0];
        $state['active_player_id'] = (int)$startPlayer['id'];
        $state['active_player_index'] = 0;
        $state['last_event'] = [
            'type' => 'game_started',
            'message' => "Runde 1 (Pflichtwurf) mit {$finalStones} Steinen gestartet! {$startPlayer['player_name']} beginnt.",
            'time' => time(),
        ];

        // Alle Spieler initialisieren
        foreach ($players as $p) {
            $isBot = !empty($p['sheet']['is_bot']);
            $sheet = $this->getInitialSheet($finalStones, $isBot);
            $this->repo->updatePlayerSheet((int)$p['id'], $sheet, count($sheet['stones']));
        }

        $this->repo->updateRoomState($roomId, $state, 'active');

        // Falls Startspieler ein Bot ist: Zug ausführen
        $this->triggerBotMovesIfNeeded($roomId, $state, $players);

        return ['success' => true];
    }

    /**
     * Führt einen Wurf für den aktiven Spieler aus.
     */
    public function rollDice(int $roomId, int $playerId, ?string $selectedStoneId = null): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'active') {
            return ['success' => false, 'message' => 'Spiel ist nicht aktiv'];
        }

        $state = $room['state'];
        if ((int)$state['active_player_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Du bist nicht am Zug'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $playerIdx = -1;
        $player = null;
        foreach ($players as $idx => $p) {
            if ((int)$p['id'] === $playerId) {
                $playerIdx = $idx;
                $player = $p;
                break;
            }
        }

        if ($playerIdx === -1 || !$player) {
            return ['success' => false, 'message' => 'Spieler nicht gefunden'];
        }

        $stones = $player['sheet']['stones'] ?? [];
        if (empty($stones)) {
            return ['success' => false, 'message' => 'Keine Steine mehr vorhanden'];
        }

        // 1 W6 würfeln
        $die = random_int(1, 6);
        $state['dice'] = [$die];
        $state['has_rolled'] = true;
        $state['roll_seq'] = ((int)($state['roll_seq'] ?? 0)) + 1;
        $state['rolls_in_turn'] = ((int)($state['rolls_in_turn'] ?? 0)) + 1;

        $currentRound = (int)($state['round_number'] ?? 1);

        // 1 Stein aus dem Vorrat entnehmen (Wunschstein oder letzter Stein)
        $placedStone = null;
        if ($selectedStoneId !== null && $selectedStoneId !== '') {
            foreach ($stones as $k => $s) {
                if (($s['id'] ?? '') === $selectedStoneId) {
                    $placedStone = $s;
                    array_splice($stones, $k, 1);
                    break;
                }
            }
        }
        if ($placedStone === null) {
            $placedStone = array_pop($stones);
        }
        $player['sheet']['stones'] = $stones;

        // --- FALL 1: WÜRFEL 6 (Der Schlund / Mittenloch) ---
        if ($die === 6) {
            $state['hole6_count'] = ((int)($state['hole6_count'] ?? 0)) + 1;
            $player['sheet']['hole6_count'] = ((int)($player['sheet']['hole6_count'] ?? 0)) + 1;
            $this->repo->updatePlayerSheet($playerId, $player['sheet'], count($stones));

            // Siegprüfung: Hat der Spieler seinen letzten Stein versenkt?
            if (empty($stones)) {
                $state['winner_id'] = $playerId;
                $state['winner_name'] = $player['player_name'];
                $state['last_event'] = [
                    'type' => 'game_won',
                    'message' => "🏆 SIEG! {$player['player_name']} hat den letzten Stein in Loch 6 versenkt!",
                    'time' => time(),
                ];
                $this->repo->updateRoomState($roomId, $state, 'finished');
                return [
                    'success' => true,
                    'die' => 6,
                    'outcome' => 'hole6_win',
                    'placed_stone' => $placedStone,
                    'remaining_stones' => 0,
                    'game_over' => true,
                ];
            }

            // Wenn noch nicht gewonnen:
            if ($currentRound === 1) {
                // In Runde 1: Genau 1 Pflichtwurf -> Zug endet sofort
                $state['last_event'] = [
                    'type' => 'hole6_placed',
                    'message' => "🕳️ {$player['player_name']} würfelte eine 6! Stein verschwindet im Schlund. Pflichtwurf (1/1) beendet.",
                    'time' => time(),
                ];
                $this->advanceTurnAfterRoll($roomId, $state, $players, $playerIdx);
                return [
                    'success' => true,
                    'die' => 6,
                    'outcome' => 'hole6',
                    'placed_stone' => $placedStone,
                    'remaining_stones' => count($stones),
                    'turn_ended' => true,
                    'can_pass' => false,
                ];
            }

            if ($currentRound === 2) {
                // In Runde 2: Genau 2 Pflichtwürfe!
                if ($rollsInTurn < 2) {
                    $state['can_pass'] = false;
                    $state['last_event'] = [
                        'type' => 'hole6_placed',
                        'message' => "🕳️ {$player['player_name']} würfelte eine 6! Stein fällt in den Schlund. Pflichtwurf 1 von 2 erledigt – noch 1 Wurf!",
                        'time' => time(),
                    ];
                    $this->repo->updateRoomState($roomId, $state);
                    return [
                        'success' => true,
                        'die' => 6,
                        'outcome' => 'hole6',
                        'placed_stone' => $placedStone,
                        'remaining_stones' => count($stones),
                        'can_pass' => false,
                        'turn_ended' => false,
                    ];
                }

                // Ab dem 2. Wurf in Runde 2: Weitergabe erlaubt
                $state['can_pass'] = true;
                $state['last_event'] = [
                    'type' => 'hole6_placed',
                    'message' => "🕳️ {$player['player_name']} würfelte eine 6! Stein fällt in den Schlund. 2 Pflichtwürfe erfüllt – weiterwürfeln oder sichern?",
                    'time' => time(),
                ];
                $this->repo->updateRoomState($roomId, $state);
                return [
                    'success' => true,
                    'die' => 6,
                    'outcome' => 'hole6',
                    'placed_stone' => $placedStone,
                    'remaining_stones' => count($stones),
                    'can_pass' => true,
                    'turn_ended' => false,
                ];
            }

            // Ab Runde 3: Nur noch 1 Pflichtwurf, Weitergabe sofort erlaubt
            $state['can_pass'] = true;
            $state['last_event'] = [
                'type' => 'hole6_placed',
                'message' => "🕳️ {$player['player_name']} würfelte eine 6! Stein fällt in den Schlund. Weiterwürfeln oder sichern?",
                'time' => time(),
            ];
            $this->repo->updateRoomState($roomId, $state);

            return [
                'success' => true,
                'die' => 6,
                'outcome' => 'hole6',
                'placed_stone' => $placedStone,
                'remaining_stones' => count($stones),
                'can_pass' => true,
                'turn_ended' => false,
            ];
        }

        // --- FALL 2: WÜRFEL 1 BIS 5 ---
        $pit = $die;
        $slots = $state['board'][$pit] ?? [];
        $freeSlotIdx = null;
        if (is_array($slots)) {
            foreach ($slots as $idx => $slot) {
                if ($slot === null) {
                    $freeSlotIdx = $idx;
                    break;
                }
            }
        }

        if ($freeSlotIdx !== null) {
            // Es ist noch mindestens eine Mulde frei!
            $state['board'][$pit][$freeSlotIdx] = $placedStone;
            $this->repo->updatePlayerSheet($playerId, $player['sheet'], count($stones));

            // Siegprüfung: Letzter Stein abgelegt?
            if (empty($stones)) {
                $state['winner_id'] = $playerId;
                $state['winner_name'] = $player['player_name'];
                $state['last_event'] = [
                    'type' => 'game_won',
                    'message' => "🏆 SIEG! {$player['player_name']} hat den letzten Stein in Feld {$pit} abgelegt!",
                    'time' => time(),
                ];
                $this->repo->updateRoomState($roomId, $state, 'finished');
                return [
                    'success' => true,
                    'die' => $die,
                    'pit' => $pit,
                    'slot' => $freeSlotIdx,
                    'placed_stone' => $placedStone,
                    'outcome' => 'placed_win',
                    'remaining_stones' => 0,
                    'game_over' => true,
                ];
            }

            if ($currentRound === 1) {
                // In Runde 1: Genau 1 Pflichtwurf -> Zug endet sofort
                $state['last_event'] = [
                    'type' => 'stone_placed',
                    'message' => "💎 {$player['player_name']} legte einen Stein in Feld {$pit} ab. Pflichtwurf (1/1) beendet.",
                    'time' => time(),
                ];
                $this->advanceTurnAfterRoll($roomId, $state, $players, $playerIdx);
                return [
                    'success' => true,
                    'die' => $die,
                    'pit' => $pit,
                    'slot' => $freeSlotIdx,
                    'placed_stone' => $placedStone,
                    'outcome' => 'placed',
                    'remaining_stones' => count($stones),
                    'turn_ended' => true,
                    'can_pass' => false,
                ];
            }

            if ($currentRound === 2) {
                // In Runde 2: Genau 2 Pflichtwürfe!
                if ($rollsInTurn < 2) {
                    $state['can_pass'] = false;
                    $state['last_event'] = [
                        'type' => 'stone_placed',
                        'message' => "💎 {$player['player_name']} legte einen Stein in Feld {$pit} ab. Pflichtwurf 1 von 2 erledigt – noch 1 Wurf!",
                        'time' => time(),
                    ];
                    $this->repo->updateRoomState($roomId, $state);
                    return [
                        'success' => true,
                        'die' => $die,
                        'pit' => $pit,
                        'slot' => $freeSlotIdx,
                        'placed_stone' => $placedStone,
                        'outcome' => 'placed',
                        'remaining_stones' => count($stones),
                        'can_pass' => false,
                        'turn_ended' => false,
                    ];
                }

                // Ab dem 2. Wurf in Runde 2: Weitergabe erlaubt
                $state['can_pass'] = true;
                $state['last_event'] = [
                    'type' => 'stone_placed',
                    'message' => "💎 {$player['player_name']} legte einen Stein in Feld {$pit} ab. 2 Pflichtwürfe erfüllt – weiterwürfeln oder sichern?",
                    'time' => time(),
                ];
                $this->repo->updateRoomState($roomId, $state);
                return [
                    'success' => true,
                    'die' => $die,
                    'pit' => $pit,
                    'slot' => $freeSlotIdx,
                    'placed_stone' => $placedStone,
                    'outcome' => 'placed',
                    'remaining_stones' => count($stones),
                    'can_pass' => true,
                    'turn_ended' => false,
                ];
            }

            // Ab Runde 3: Nur noch 1 Pflichtwurf, Weitergabe sofort erlaubt
            $state['can_pass'] = true;
            $state['last_event'] = [
                'type' => 'stone_placed',
                'message' => "💎 {$player['player_name']} legte einen Stein in Feld {$pit} ab. Weiterwürfeln oder sichern?",
                'time' => time(),
            ];
            $this->repo->updateRoomState($roomId, $state);

            return [
                'success' => true,
                'die' => $die,
                'pit' => $pit,
                'slot' => $freeSlotIdx,
                'placed_stone' => $placedStone,
                'outcome' => 'placed',
                'remaining_stones' => count($stones),
                'can_pass' => true,
                'turn_ended' => false,
            ];
        }

        // --- FALL 3: ALLE MULDEN DIESER ZAHL BESETZT („Kleben geblieben“) ---
        // Der Spieler muss alle Steine, die aktuell in den Mulden 1 bis 5 auf dem gesamten Brett liegen,
        // plus den gerade entnommenen Stein in seinen Vorrat aufnehmen!
        $collectedStones = [];
        $collectedStones[] = $placedStone; // Der soeben gewürfelte Stein

        for ($pNum = 1; $pNum <= 5; $pNum++) {
            if (!empty($state['board'][$pNum]) && is_array($state['board'][$pNum])) {
                foreach ($state['board'][$pNum] as $idx => $stoneInSlot) {
                    if ($stoneInSlot !== null) {
                        $collectedStones[] = $stoneInSlot;
                        $state['board'][$pNum][$idx] = null;
                    }
                }
            }
        }

        // Steine zurück in den Vorrat des Spielers
        foreach ($collectedStones as $s) {
            $stones[] = $s;
        }
        $player['sheet']['stones'] = $stones;
        $player['sheet']['bust_count'] = ((int)($player['sheet']['bust_count'] ?? 0)) + 1;
        $this->repo->updatePlayerSheet($playerId, $player['sheet'], count($stones));

        $countPicked = count($collectedStones);
        $state['last_event'] = [
            'type' => 'bust',
            'message' => "💥 KLEBEN GEBLIEBEN! Alle Mulden von {$pit} waren belegt! {$player['player_name']} muss {$countPicked} Steine aufnehmen.",
            'time' => time(),
        ];

        // Zug endet sofort & wird weitergegeben
        $this->advanceTurnAfterRoll($roomId, $state, $players, $playerIdx);

        return [
            'success' => true,
            'die' => $die,
            'pit' => $pit,
            'outcome' => 'bust',
            'collected_count' => $countPicked,
            'remaining_stones' => count($stones),
            'turn_ended' => true,
        ];
    }

    /**
     * Aktiver Spieler sichert seinen Zug und gibt freiwillig an den nächsten Spieler weiter.
     * (Nur ab Runde 2 und nach mindestens 1 erfolgreichem Wurf erlaubt).
     */
    public function passTurn(int $roomId, int $playerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'active') {
            return ['success' => false, 'message' => 'Spiel ist nicht aktiv'];
        }

        $state = $room['state'];
        if ((int)$state['active_player_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Du bist nicht am Zug'];
        }

        if (empty($state['can_pass'])) {
            return ['success' => false, 'message' => 'Weitergeben ist jetzt nicht erlaubt'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $playerIdx = -1;
        $player = null;
        foreach ($players as $idx => $p) {
            if ((int)$p['id'] === $playerId) {
                $playerIdx = $idx;
                $player = $p;
                break;
            }
        }

        if ($playerIdx === -1 || !$player) {
            return ['success' => false, 'message' => 'Spieler nicht gefunden'];
        }

        $state['last_event'] = [
            'type' => 'pass',
            'message' => "🛑 {$player['player_name']} sichert und gibt den Würfel weiter.",
            'time' => time(),
        ];

        $this->advanceTurnAfterRoll($roomId, $state, $players, $playerIdx);

        return ['success' => true];
    }

    /**
     * Übergibt den Zug an den nächsten Spieler und inkrementiert ggf. die Rundenanzahl.
     */
    private function advanceTurnAfterRoll(int $roomId, array &$state, array &$players, int $currentPlayerIdx): void
    {
        $state['has_rolled'] = false;
        $state['can_pass'] = false;
        $state['rolls_in_turn'] = 0;

        $playerCount = count($players);
        $nextIdx = ($currentPlayerIdx + 1) % $playerCount;

        // Wenn die Runde vollendet ist (Startspieler wieder am Zug)
        if ($nextIdx === 0) {
            $state['round_number'] = ((int)($state['round_number'] ?? 1)) + 1;
        }

        $nextPlayer = $players[$nextIdx];
        $state['active_player_index'] = $nextIdx;
        $state['active_player_id'] = (int)$nextPlayer['id'];

        $this->repo->updateRoomState($roomId, $state);

        // Falls Folgespieler ein Bot ist: Züge ausführen
        $this->triggerBotMovesIfNeeded($roomId, $state, $players);
    }

    /**
     * Fügt einen Bot zum Raum hinzu.
     */
    public function addBot(int $roomId, string $botName = 'Bot'): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'lobby') {
            return ['success' => false, 'message' => 'Bots können nur in der Lobby hinzugefügt werden'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        if (count($players) >= 6) {
            return ['success' => false, 'message' => 'Maximal 6 Spieler erlaubt'];
        }

        $newCount = count($players) + 1;
        $isFixed = !empty($room['state']['starting_stones_fixed']);
        $stoneCount = $isFixed ? (int)($room['state']['starting_stones'] ?? self::DEFAULT_STONES) : self::getRecommendedStones($newCount);

        $botTokenHash = hash('sha256', bin2hex(random_bytes(16)));
        $sheet = $this->getInitialSheet($stoneCount, true);

        $player = $this->repo->joinPlayer($roomId, $botTokenHash, $botName, false, $sheet);
        $this->syncLobbyPlayerStones($roomId);
        return ['success' => true, 'bot_id' => $player['id'], 'bot_name' => $botName];
    }

    /**
     * Führt Bot-Züge automatisch mit Zeitverzögerung / Schritten aus.
     */
    public function triggerBotMovesIfNeeded(int $roomId, array &$roomState, array &$players, int $maxSteps = 15): void
    {
        if (($roomState['status'] ?? '') === 'finished' || empty($players) || $maxSteps <= 0) {
            return;
        }

        $activePlayerId = (int)($roomState['active_player_id'] ?? 0);
        $activePlayer = null;
        foreach ($players as $p) {
            if ((int)$p['id'] === $activePlayerId) {
                $activePlayer = $p;
                break;
            }
        }

        if (!$activePlayer || empty($activePlayer['sheet']['is_bot'])) {
            return;
        }

        $currentRound = (int)($roomState['round_number'] ?? 1);
        $rollsInTurn = (int)($roomState['rolls_in_turn'] ?? 0);
        $board = $roomState['board'] ?? [];

        // Anzahl der vollständig belegten Zahlen (1 bis 5) ermitteln
        $fullNumbersCount = 0;
        for ($pNum = 1; $pNum <= 5; $pNum++) {
            $slots = $board[$pNum] ?? [];
            if (is_array($slots) && !empty($slots) && !in_array(null, $slots, true)) {
                $fullNumbersCount++;
            }
        }
        $stonesLeft = count($activePlayer['sheet']['stones'] ?? []);

        $minRolls = match (true) {
            $currentRound === 1 => 1,
            $currentRound === 2 => 2,
            default => 1,
        };

        // 1. Wenn der Bot seine Pflichtwürfe in diesem Zug noch nicht erfüllt hat: Er MUSS würfeln
        if ($rollsInTurn < $minRolls) {
            $this->rollDice($roomId, $activePlayerId);
            $refreshedRoom = $this->repo->getRoomById($roomId);
            $refreshedPlayers = $this->repo->getPlayersForRoom($roomId);
            $this->triggerBotMovesIfNeeded($roomId, $refreshedRoom['state'], $refreshedPlayers, $maxSteps - 1);
            return;
        }

        // 2. Sobald Weitergabe erlaubt ist: Entscheidung Weiterspielen vs. Sichern
        if (!empty($roomState['can_pass'])) {
            $shouldRollAgain = Loch6BotService::shouldRollAgain($fullNumbersCount, $stonesLeft, $rollsInTurn);
            if ($shouldRollAgain) {
                $this->rollDice($roomId, $activePlayerId);
            } else {
                $this->passTurn($roomId, $activePlayerId);
            }

            $refreshedRoom = $this->repo->getRoomById($roomId);
            $refreshedPlayers = $this->repo->getPlayersForRoom($roomId);
            $this->triggerBotMovesIfNeeded($roomId, $refreshedRoom['state'], $refreshedPlayers, $maxSteps - 1);
        }
    }

    /**
     * Erstellt die öffentliche Ansicht für einen Client.
     */
    public function getPublicStateForPlayer(array $room, array $myPlayer, array $players): array
    {
        $state = $room['state'];
        $activePlayerId = (int)($state['active_player_id'] ?? 0);

        $publicPlayers = [];
        foreach ($players as $p) {
            $isMe = ((int)$p['id'] === (int)$myPlayer['id']);
            $sheet = $p['sheet'] ?? [];
            $stones = $sheet['stones'] ?? [];

            $publicPlayers[] = [
                'id' => (int)$p['id'],
                'name' => $p['player_name'],
                'seat_order' => (int)$p['seat_order'],
                'is_me' => $isMe,
                'is_host' => (bool)$p['is_host'],
                'is_bot' => (bool)($sheet['is_bot'] ?? false),
                'is_active' => ((int)$p['id'] === $activePlayerId),
                'stone_count' => count($stones),
                // Der aktive Spieler sieht die exakten Mineralien-Details seiner Steine; Mitspieler die Anzahl
                'stones' => $isMe ? $stones : array_map(fn($s) => ['type' => $s['type'] ?? 'amethyst', 'seed' => $s['seed'] ?? 1], $stones),
            ];
        }

        return [
            'room_code' => $room['room_code'],
            'status' => $room['status'],
            'starting_stones' => (int)($state['starting_stones'] ?? self::DEFAULT_STONES),
            'round_number' => (int)($state['round_number'] ?? 1),
            'active_player_id' => $activePlayerId,
            'rolls_in_turn' => (int)($state['rolls_in_turn'] ?? 0),
            'can_pass' => (bool)($state['can_pass'] ?? false),
            'dice' => $state['dice'] ?? [1],
            'has_rolled' => (bool)($state['has_rolled'] ?? false),
            'roll_seq' => (int)($state['roll_seq'] ?? 0),
            'board' => $state['board'] ?? [1 => [null], 2 => [null, null], 3 => [null, null, null], 4 => [null, null, null, null], 5 => [null, null, null, null, null]],
            'hole6_count' => (int)($state['hole6_count'] ?? 0),
            'winner_id' => $state['winner_id'] ?? null,
            'winner_name' => $state['winner_name'] ?? null,
            'last_event' => $state['last_event'] ?? null,
            'players' => $publicPlayers,
        ];
    }
}
