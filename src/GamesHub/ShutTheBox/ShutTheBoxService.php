<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\ShutTheBox;

use Kai\Tools\GamesHub\RoomRepository;

class ShutTheBoxService
{
    public const MAX_ROUNDS = 10;
    public const FLAPS_COUNT = 10;

    private RoomRepository $repo;

    public function __construct(?RoomRepository $repo = null)
    {
        $this->repo = $repo ?? new RoomRepository();
    }

    /**
     * Erzeugt das initiale leere Spielblatt für einen Spieler.
     */
    public function getInitialSheet(): array
    {
        return [
            'scores' => array_fill(0, self::MAX_ROUNDS, null),
            'current_flaps' => array_fill(1, self::FLAPS_COUNT, true), // 1..10 alle offen
            'is_locked_out' => false,
            'is_bot' => false,
        ];
    }

    /**
     * Erzeugt den Anfangszustand eines Shut the Box Spiels.
     */
    public function getInitialState(): array
    {
        return [
            'round_number' => 1,
            'max_rounds' => self::MAX_ROUNDS,
            'active_player_id' => null,
            'active_player_index' => 0,
            'dice' => [1, 1],
            'has_rolled' => false,
            'roll_seq' => 0,
            'abort_vote' => null, // ['initiator_id' => ..., 'initiator_name' => ..., 'votes' => [id => true/false]]
            'last_event' => [
                'type' => 'lobby_created',
                'message' => 'Lobby erstellt. Warte auf Mitspieler...',
                'time' => time(),
            ],
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
        if (count($players) < 1) {
            return ['success' => false, 'message' => 'Mindestens 1 Spieler erforderlich'];
        }

        $state = $this->getInitialState();
        $startPlayer = $players[0];
        $state['active_player_id'] = (int)$startPlayer['id'];
        $state['active_player_index'] = 0;
        $state['last_event'] = [
            'type' => 'game_started',
            'message' => "Runde 1 gestartet! {$startPlayer['player_name']} beginnt.",
            'time' => time(),
        ];

        // Alle Spieler initialisieren
        foreach ($players as $p) {
            $sheet = $this->getInitialSheet();
            if (!empty($p['sheet']['is_bot'])) {
                $sheet['is_bot'] = true;
            }
            $this->repo->updatePlayerSheet((int)$p['id'], $sheet, 0);
        }

        $this->repo->updateRoomState($roomId, $state, 'active');

        // Falls erster Spieler ein Bot ist: Zug ausführen
        $this->triggerBotMovesIfNeeded($roomId, $state, $players);

        return ['success' => true];
    }

    /**
     * Aktiver Spieler wirft die beiden Würfel.
     */
    public function rollDice(int $roomId, int $playerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'active') {
            return ['success' => false, 'message' => 'Spiel ist nicht aktiv'];
        }

        $state = $room['state'];
        if ((int)$state['active_player_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Du bist nicht am Zug'];
        }

        if (!empty($state['has_rolled'])) {
            return ['success' => false, 'message' => 'Du hast bereits gewürfelt'];
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

        // Immer mit 2 Würfeln würfeln (ohne 1-Würfelregel)
        $d1 = random_int(1, 6);
        $d2 = random_int(1, 6);
        $sum = $d1 + $d2;
        $state['dice'] = [$d1, $d2];
        $state['has_rolled'] = true;
        $state['roll_seq'] = ((int)($state['roll_seq'] ?? 0)) + 1;

        // Prüfen, welche Kombinationen noch möglich sind (Addition, Subtraktion, beide einzeln, einzelner Würfel)
        $flaps = $player['sheet']['current_flaps'] ?? [];
        $validMoves = self::getValidMovesForDice($flaps, $d1, $d2);

        if (empty($validMoves)) {
            // Keine Kombination mehr möglich -> Spieler scheidet für diese Runde aus!
            $penalty = self::calculateOpenFlapsSum($flaps);
            $roundIdx = (int)($state['round_number'] ?? 1) - 1;

            $player['sheet']['scores'][$roundIdx] = $penalty;
            $player['sheet']['is_locked_out'] = true;
            $players[$playerIdx]['sheet'] = $player['sheet'];

            $this->repo->updatePlayerSheet($playerId, $player['sheet'], self::calculateTotalScore($player['sheet']['scores']));

            $state['last_event'] = [
                'type' => 'player_locked_out',
                'message' => "❌ {$player['player_name']} würfelte {$d1} und {$d2} – keine Klappen möglich! {$penalty} Strafpunkte eingetragen.",
                'time' => time(),
            ];

            // Turn beenden & nächsten Spieler prüfen
            $this->advanceTurnAfterMove($roomId, $state, $players, $playerIdx);
            return [
                'success' => true,
                'dice' => [$d1, $d2],
                'sum' => $sum,
                'locked_out' => true,
                'penalty' => $penalty,
                'valid_combos' => [],
            ];
        }

        $state['last_event'] = [
            'type' => 'dice_rolled',
            'message' => "🎲 {$player['player_name']} würfelte {$d1} und {$d2}. Wähle Klappen.",
            'time' => time(),
        ];
        $this->repo->updateRoomState($roomId, $state);

        return [
            'success' => true,
            'dice' => [$d1, $d2],
            'sum' => $sum,
            'locked_out' => false,
            'valid_combos' => $validMoves,
        ];
    }

    /**
     * Aktiver Spieler legt gewählte Klappen um.
     *
     * @param int[] $chosenFlaps (z.B. [7] oder [3, 4] oder [1, 2, 4])
     */
    public function flipFlaps(int $roomId, int $playerId, array $chosenFlaps): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'active') {
            return ['success' => false, 'message' => 'Spiel ist nicht aktiv'];
        }

        $state = $room['state'];
        if ((int)$state['active_player_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Du bist nicht am Zug'];
        }

        if (empty($state['has_rolled'])) {
            return ['success' => false, 'message' => 'Du musst zuerst würfeln'];
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

        // Validierung der gewählten Klappen anhand der Spielregeln:
        // Addition, Subtraktion, beide Würfel einzeln oder ein einzelner Würfel
        $d1 = (int)($state['dice'][0] ?? 1);
        $d2 = (int)($state['dice'][1] ?? 1);
        $currentFlaps = $player['sheet']['current_flaps'] ?? [];
        $validMoves = self::getValidMovesForDice($currentFlaps, $d1, $d2);

        $sortedChosen = array_map('intval', $chosenFlaps);
        sort($sortedChosen);

        $matchedMove = null;
        foreach ($validMoves as $vm) {
            if ($vm['flaps'] === $sortedChosen) {
                $matchedMove = $vm;
                break;
            }
        }

        if (!$matchedMove) {
            return [
                'success' => false,
                'message' => 'Ungültige Klappen-Kombination. Erlaubt sind Addition, Subtraktion, beide Würfel einzeln oder ein einzelner Würfel.',
            ];
        }

        // Klappen umlegen
        foreach ($sortedChosen as $f) {
            $currentFlaps[(int)$f] = false;
        }
        $player['sheet']['current_flaps'] = $currentFlaps;

        // Hat der Spieler alle Klappen geschlossen (Shut the Box!)?
        $remainingFlapsSum = self::calculateOpenFlapsSum($currentFlaps);
        $roundIdx = (int)($state['round_number'] ?? 1) - 1;
        $shutTheBox = ($remainingFlapsSum === 0);

        if ($shutTheBox) {
            $player['sheet']['scores'][$roundIdx] = 0;
            $player['sheet']['is_locked_out'] = true;
            $state['last_event'] = [
                'type' => 'shut_the_box',
                'message' => "🏆 SHUT THE BOX! {$player['player_name']} hat alle 10 Klappen geschlossen (0 Strafpunkte)!",
                'time' => time(),
            ];
        } else {
            $state['last_event'] = [
                'type' => 'flaps_flipped',
                'message' => "{$player['player_name']} wählte {$matchedMove['label']}.",
                'time' => time(),
            ];
        }

        $players[$playerIdx]['sheet'] = $player['sheet'];
        $this->repo->updatePlayerSheet($playerId, $player['sheet'], self::calculateTotalScore($player['sheet']['scores']));

        // Turn beenden & an nächsten Spieler übergeben
        $this->advanceTurnAfterMove($roomId, $state, $players, $playerIdx);

        return [
            'success' => true,
            'flaps' => $currentFlaps,
            'shut_the_box' => $shutTheBox,
        ];
    }

    /**
     * Schaltet den Zug nach einem Wurf/Klappen-Zug an den nächsten noch aktiven Spieler weiter
     * oder beendet die Runde, wenn alle Spieler ausgeschieden sind.
     */
    private function advanceTurnAfterMove(int $roomId, array &$state, array &$players, int $currentPlayerIdx): void
    {
        $state['has_rolled'] = false;
        $playerCount = count($players);

        // Prüfen, ob noch mindestens ein Spieler in dieser Runde aktiv ist
        $activePlayers = [];
        foreach ($players as $idx => $p) {
            if (empty($p['sheet']['is_locked_out'])) {
                $activePlayers[] = $idx;
            }
        }

        if (empty($activePlayers)) {
            // ALLE Spieler sind für diese Runde fertig -> Rundenabschluss!
            $this->finishCurrentRound($roomId, $state, $players);
            return;
        }

        // Nächsten aktiven Spieler im Uhrzeigersinn finden
        $nextIdx = ($currentPlayerIdx + 1) % $playerCount;
        for ($i = 0; $i < $playerCount; $i++) {
            if (empty($players[$nextIdx]['sheet']['is_locked_out'])) {
                break;
            }
            $nextIdx = ($nextIdx + 1) % $playerCount;
        }

        $nextPlayer = $players[$nextIdx];
        $state['active_player_index'] = $nextIdx;
        $state['active_player_id'] = (int)$nextPlayer['id'];

        $this->repo->updateRoomState($roomId, $state);

        // Falls Folgespieler ein Bot ist: Züge ausführen
        $this->triggerBotMovesIfNeeded($roomId, $state, $players);
    }

    /**
     * Schließt die aktuelle Runde ab und startet die nächste, oder beendet das Gesamtspiel nach 10 Runden.
     */
    private function finishCurrentRound(int $roomId, array &$state, array &$players): void
    {
        $currentRound = (int)($state['round_number'] ?? 1);

        if ($currentRound >= self::MAX_ROUNDS) {
            // Alle 10 Runden absolviert -> Gesamtspiel beenden!
            $this->finalizeGame($roomId, $state, $players);
            return;
        }

        // Nächste Spielrunde vorbereiten
        $nextRound = $currentRound + 1;

        // Runden-Zusammenfassung für den Übergang erfassen
        $roundIdx = $currentRound - 1;
        $summaryScores = [];
        foreach ($players as $p) {
            $rScore = $p['sheet']['scores'][$roundIdx] ?? 0;
            $totScore = self::calculateTotalScore($p['sheet']['scores'] ?? []);
            $summaryScores[] = [
                'player_id' => (int)$p['id'],
                'player_name' => $p['player_name'],
                'round_score' => $rScore,
                'total_score' => $totScore,
                'is_bot' => !empty($p['sheet']['is_bot']),
            ];
        }
        $state['round_summary'] = [
            'round_number' => $currentRound,
            'next_round' => $nextRound,
            'scores' => $summaryScores,
            'time' => time(),
        ];

        $state['round_number'] = $nextRound;
        $state['has_rolled'] = false;

        // Alle Klappen für alle Spieler wieder öffnen
        foreach ($players as &$p) {
            $p['sheet']['current_flaps'] = array_fill(1, self::FLAPS_COUNT, true);
            $p['sheet']['is_locked_out'] = false;
            $this->repo->updatePlayerSheet((int)$p['id'], $p['sheet'], self::calculateTotalScore($p['sheet']['scores']));
        }
        unset($p);

        // Startspieler für die neue Runde rotiert
        $startIdx = ($nextRound - 1) % count($players);
        $startPlayer = $players[$startIdx];
        $state['active_player_index'] = $startIdx;
        $state['active_player_id'] = (int)$startPlayer['id'];

        $state['last_event'] = [
            'type' => 'round_started',
            'message' => "🔔 Runde {$nextRound} von " . self::MAX_ROUNDS . " gestartet! Alle Klappen aufgestellt.",
            'time' => time(),
        ];

        $this->repo->updateRoomState($roomId, $state);

        // Bot-Züge ausführen falls nötig
        $this->triggerBotMovesIfNeeded($roomId, $state, $players);
    }

    /**
     * Beendet das Gesamtspiel und ermittelt den Gewinner (geringste Gesamtpunktzahl).
     */
    public function finalizeGame(int $roomId, array &$state, array &$players): void
    {
        $bestScore = PHP_INT_MAX;
        $winner = null;

        foreach ($players as $p) {
            $total = self::calculateTotalScore($p['sheet']['scores'] ?? []);
            if ($total < $bestScore) {
                $bestScore = $total;
                $winner = $p;
            }
        }

        $winnerName = $winner ? $winner['player_name'] : 'Niemand';
        $state['winner_id'] = $winner ? (int)$winner['id'] : null;
        $state['winner_name'] = $winnerName;
        $state['winner_score'] = $bestScore;
        $state['last_event'] = [
            'type' => 'game_finished',
            'message' => "🏆 Spiel beendet! {$winnerName} gewinnt mit nur {$bestScore} Strafpunkten!",
            'time' => time(),
        ];

        $this->repo->updateRoomState($roomId, $state, 'finished');
    }

    /**
     * Beantragt einen vorzeitigen Spielabbruch mit Auswertung der bisherigen Runden.
     */
    public function requestAbort(int $roomId, int $playerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'active') {
            return ['success' => false, 'message' => 'Spiel ist nicht aktiv'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $initiator = null;
        foreach ($players as $p) {
            if ((int)$p['id'] === $playerId) {
                $initiator = $p;
                break;
            }
        }

        if (!$initiator) {
            return ['success' => false, 'message' => 'Spieler nicht gefunden'];
        }

        $state = $room['state'];
        $initiatorName = $initiator['player_name'];

        // Falls nur 1 Spieler im Raum ist: sofort abbrechen
        if (count($players) === 1) {
            $this->finalizeGame($roomId, $state, $players);
            return ['success' => true, 'aborted' => true];
        }

        $state['abort_vote'] = [
            'initiator_id' => $playerId,
            'initiator_name' => $initiatorName,
            'votes' => [
                (string)$playerId => true,
            ],
            'time' => time(),
        ];

        // Bots stimmen automatisch zu
        foreach ($players as $p) {
            if (!empty($p['sheet']['is_bot'])) {
                $state['abort_vote']['votes'][(string)$p['id']] = true;
            }
        }

        $state['last_event'] = [
            'type' => 'abort_requested',
            'message' => "⚠️ {$initiatorName} schlägt vor, das Spiel vorzeitig abzubrechen und auszuwerten.",
            'time' => time(),
        ];

        // Prüfen ob bereits alle zugestimmt haben (z.B. nur 1 Mensch + Bots)
        if ($this->checkAllVotesAgreed($players, $state['abort_vote']['votes'])) {
            $this->finalizeGame($roomId, $state, $players);
            return ['success' => true, 'aborted' => true];
        }

        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'aborted' => false];
    }

    /**
     * Spieler stimmt über vorzeitigen Abbruch ab.
     */
    public function voteAbort(int $roomId, int $playerId, bool $agrees): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'active') {
            return ['success' => false, 'message' => 'Spiel ist nicht aktiv'];
        }

        $state = $room['state'];
        if (empty($state['abort_vote'])) {
            return ['success' => false, 'message' => 'Keine aktive Abbruchabstimmung'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $voter = null;
        foreach ($players as $p) {
            if ((int)$p['id'] === $playerId) {
                $voter = $p;
                break;
            }
        }

        if (!$voter) {
            return ['success' => false, 'message' => 'Spieler nicht gefunden'];
        }

        if (!$agrees) {
            // Ein Spieler lehnt ab -> Abbruch gescheitert!
            $state['abort_vote'] = null;
            $state['last_event'] = [
                'type' => 'abort_rejected',
                'message' => "ℹ️ {$voter['player_name']} hat den vorzeitigen Abbruch abgelehnt. Spiel geht weiter!",
                'time' => time(),
            ];
            $this->repo->updateRoomState($roomId, $state);
            return ['success' => true, 'aborted' => false, 'rejected' => true];
        }

        // Stimme hinzufügen
        $state['abort_vote']['votes'][(string)$playerId] = true;

        if ($this->checkAllVotesAgreed($players, $state['abort_vote']['votes'])) {
            // Alle haben zugestimmt!
            $state['abort_vote'] = null;
            $this->finalizeGame($roomId, $state, $players);
            return ['success' => true, 'aborted' => true];
        }

        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'aborted' => false];
    }

    private function checkAllVotesAgreed(array $players, array $votes): bool
    {
        foreach ($players as $p) {
            $pid = (string)$p['id'];
            if (empty($votes[$pid])) {
                return false;
            }
        }
        return true;
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
        if (count($players) >= 4) {
            return ['success' => false, 'message' => 'Maximal 4 Spieler am Brett erlaubt'];
        }

        $botTokenHash = hash('sha256', bin2hex(random_bytes(16)));
        $initialSheet = $this->getInitialSheet();
        $initialSheet['is_bot'] = true;

        $player = $this->repo->joinPlayer($roomId, $botTokenHash, $botName, false, $initialSheet);
        return ['success' => true, 'bot_id' => $player['id'], 'bot_name' => $botName];
    }

    /**
     * Führt Bot-Züge automatisch aus.
     */
    public function triggerBotMovesIfNeeded(int $roomId, array &$roomState, array &$players, int $maxSteps = 20): void
    {
        if (($roomState['status'] ?? '') === 'finished' || empty($players)) {
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

        if ($maxSteps <= 0) {
            return;
        }

        // Bot-Zug ausführen:
        if (empty($roomState['has_rolled'])) {
            // 1. Bot würfelt
            $rollRes = $this->rollDice($roomId, $activePlayerId);
            if (!empty($rollRes['locked_out'])) {
                // Ausgeschieden -> weitergeben
                $refreshedRoom = $this->repo->getRoomById($roomId);
                $refreshedPlayers = $this->repo->getPlayersForRoom($roomId);
                $this->triggerBotMovesIfNeeded($roomId, $refreshedRoom['state'], $refreshedPlayers, $maxSteps - 1);
                return;
            }

            // 2. Taktische Klappenauswahl durch BotService (Addition, Subtraktion, beide einzeln oder einzelner Würfel)
            $flaps = $activePlayer['sheet']['current_flaps'] ?? [];
            $d1 = (int)($rollRes['dice'][0] ?? 1);
            $d2 = (int)($rollRes['dice'][1] ?? 1);
            $chosenCombo = ShutTheBoxBotService::pickBestMove($flaps, $d1, $d2);

            if (!empty($chosenCombo)) {
                $this->flipFlaps($roomId, $activePlayerId, $chosenCombo);
            }

            // Rekursiv für nächsten Bot-Zug
            $refreshedRoom = $this->repo->getRoomById($roomId);
            $refreshedPlayers = $this->repo->getPlayersForRoom($roomId);
            $this->triggerBotMovesIfNeeded($roomId, $refreshedRoom['state'], $refreshedPlayers, $maxSteps - 1);
        }
    }

    /**
     * Erstellt die öffentliche Spielansicht für einen Spieler.
     */
    public function getPublicStateForPlayer(array $room, array $myPlayer, array $players): array
    {
        $state = $room['state'];
        $activePlayerId = (int)($state['active_player_id'] ?? 0);

        $publicPlayers = [];
        foreach ($players as $p) {
            $isMe = ((int)$p['id'] === (int)$myPlayer['id']);
            $sheet = $p['sheet'] ?? [];
            $totalScore = self::calculateTotalScore($sheet['scores'] ?? []);

            $publicPlayers[] = [
                'id' => (int)$p['id'],
                'name' => $p['player_name'],
                'seat_order' => (int)$p['seat_order'],
                'is_me' => $isMe,
                'is_host' => (bool)$p['is_host'],
                'is_bot' => (bool)($sheet['is_bot'] ?? false),
                'is_active' => ((int)$p['id'] === $activePlayerId),
                'is_locked_out' => (bool)($sheet['is_locked_out'] ?? false),
                'flaps' => $sheet['current_flaps'] ?? array_fill(1, self::FLAPS_COUNT, true),
                'scores' => $sheet['scores'] ?? array_fill(0, self::MAX_ROUNDS, null),
                'total_score' => $totalScore,
            ];
        }

        // Mögliche Kombinationen für den aktiven Spieler berechnen (falls gewürfelt)
        // Erlaubt: Addition, Subtraktion, beide Würfel einzeln oder ein einzelner Würfel
        $validCombos = [];
        if (!empty($state['has_rolled']) && (int)$myPlayer['id'] === $activePlayerId) {
            $myFlaps = $myPlayer['sheet']['current_flaps'] ?? [];
            $d1 = (int)($state['dice'][0] ?? 1);
            $d2 = (int)($state['dice'][1] ?? 1);
            $validCombos = self::getValidMovesForDice($myFlaps, $d1, $d2);
        }

        return [
            'room_code' => $room['room_code'],
            'status' => $room['status'],
            'round_number' => (int)($state['round_number'] ?? 1),
            'max_rounds' => self::MAX_ROUNDS,
            'active_player_id' => $activePlayerId,
            'dice' => $state['dice'] ?? [1, 1],
            'has_rolled' => (bool)($state['has_rolled'] ?? false),
            'roll_seq' => (int)($state['roll_seq'] ?? 0),
            'abort_vote' => $state['abort_vote'] ?? null,
            'winner_id' => $state['winner_id'] ?? null,
            'winner_name' => $state['winner_name'] ?? null,
            'winner_score' => $state['winner_score'] ?? null,
            'last_event' => $state['last_event'] ?? null,
            'round_summary' => $state['round_summary'] ?? null,
            'players' => $publicPlayers,
            'valid_combos' => $validCombos,
        ];
    }

    /**
     * Ermittelt alle gültigen Klappen-Kombinationen für zwei gewürfelte Augen:
     * - Addition beider Würfel: [d1 + d2]
     * - Subtraktion beider Würfel: [|d1 - d2|]
     * - Beide Würfel einzeln: [d1, d2]
     * - Einzelner Würfel: [d1] oder [d2]
     *
     * @param array<int, bool> $flaps Offene/geschlossene Klappen (1..10)
     * @param int $d1 Erster Würfel (1..6)
     * @param int $d2 Zweiter Würfel (1..6)
     * @return array<array{type: string, label: string, flaps: int[]}>
     */
    public static function getValidMovesForDice(array $flaps, int $d1, int $d2): array
    {
        $moves = [];
        $seenFlapKeys = [];

        $addMove = function (string $type, string $label, array $chosen) use (&$moves, &$seenFlapKeys) {
            $chosen = array_map('intval', $chosen);
            sort($chosen);
            $key = implode('-', $chosen);
            if (!isset($seenFlapKeys[$key])) {
                $seenFlapKeys[$key] = true;
                $moves[] = [
                    'type' => $type,
                    'label' => $label,
                    'flaps' => $chosen,
                ];
            }
        };

        // 1. Addition beider Würfel: [d1 + d2]
        $addSum = $d1 + $d2;
        if ($addSum <= self::FLAPS_COUNT && !empty($flaps[$addSum])) {
            $addMove('addition', "{$addSum} ({$d1}+{$d2})", [$addSum]);
        }

        // 2. Subtraktion beider Würfel: [|d1 - d2|]
        $subDiff = abs($d1 - $d2);
        if ($subDiff >= 1 && $subDiff <= self::FLAPS_COUNT && !empty($flaps[$subDiff])) {
            $high = max($d1, $d2);
            $low = min($d1, $d2);
            $addMove('subtraction', "{$subDiff} ({$high}-{$low})", [$subDiff]);
        }

        // 3. Beide Würfel einzeln: [d1, d2]
        if ($d1 !== $d2) {
            $f1 = min($d1, $d2);
            $f2 = max($d1, $d2);
            if (!empty($flaps[$f1]) && !empty($flaps[$f2])) {
                $addMove('both', "{$f1} & {$f2}", [$f1, $f2]);
            }
        }

        // 4. Einzelner Würfel d1: [d1]
        if (!empty($flaps[$d1])) {
            $addMove('single', "Nur {$d1}", [$d1]);
        }

        // 5. Einzelner Würfel d2: [d2]
        if ($d2 !== $d1 && !empty($flaps[$d2])) {
            $addMove('single', "Nur {$d2}", [$d2]);
        }

        return $moves;
    }

    /**
     * Findet alle gültigen Teilmengen offener Klappen, deren Summe exakt $targetSum ist.
     *
     * @param array<int, bool> $flaps
     * @param int $targetSum
     * @return array<int[]> Liste gültiger Kombinationen (z.B. [[7], [3, 4], [1, 2, 4]])
     */
    public static function findCombinationsForSum(array $flaps, int $targetSum): array
    {
        $openNumbers = [];
        for ($i = 1; $i <= self::FLAPS_COUNT; $i++) {
            if (!empty($flaps[$i])) {
                $openNumbers[] = $i;
            }
        }

        $results = [];
        self::backtrackSubsetSum($openNumbers, $targetSum, 0, [], $results);
        return $results;
    }

    private static function backtrackSubsetSum(array $numbers, int $target, int $startIndex, array $current, array &$results): void
    {
        if ($target === 0) {
            $results[] = $current;
            return;
        }
        if ($target < 0) {
            return;
        }

        for ($i = $startIndex; $i < count($numbers); $i++) {
            $num = $numbers[$i];
            if ($num > $target) {
                continue;
            }
            $current[] = $num;
            self::backtrackSubsetSum($numbers, $target - $num, $i + 1, $current, $results);
            array_pop($current);
        }
    }

    /**
     * Berechnet die Summe aller noch offenen Klappen.
     */
    public static function calculateOpenFlapsSum(array $flaps): int
    {
        $sum = 0;
        for ($i = 1; $i <= self::FLAPS_COUNT; $i++) {
            if (!empty($flaps[$i])) {
                $sum += $i;
            }
        }
        return $sum;
    }

    /**
     * Berechnet die Gesamtzahl an Strafpunkten über alle bisherigen Runden.
     */
    public static function calculateTotalScore(array $scores): int
    {
        $total = 0;
        foreach ($scores as $s) {
            if ($s !== null && $s !== '') {
                $total += (int)$s;
            }
        }
        return $total;
    }
}
