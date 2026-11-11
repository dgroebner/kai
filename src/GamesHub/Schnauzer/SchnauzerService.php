<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Schnauzer;

use Kai\Tools\GamesHub\RoomRepository;

class SchnauzerService
{
    public function __construct(private RoomRepository $repo)
    {
    }

    private function isGelenauerRules(array $state): bool
    {
        return !empty($state['rules']['gelenauer_regeln'])
            || (($state['rules']['mode'] ?? '') === 'gelenau')
            || !empty($state['rules']['three_aces_33']);
    }

    /**
     * Erzeugt den initialen Raumzustand.
     *
     * @return array<string, mixed>
     */
    public function getInitialState(string|bool $rulesMode = 'standard'): array
    {
        $isGelenau = ($rulesMode === true || $rulesMode === 'gelenau');

        return [
            'phase' => 'lobby', // 'lobby', 'dealing_choice', 'playing', 'showdown', 'game_over'
            'round_number' => 0,
            'dealer_id' => null,
            'current_player_id' => null,
            'table_cards' => [],
            'dealer_reserve_cards' => [],
            'deck' => [],
            'discard_pile' => [],
            'knocked_by_player_id' => null,
            'turns_after_knock_left' => 0,
            'round_turn_count' => 0,
            'passed_players' => [],
            'rules' => [
                'mode' => $isGelenau ? 'gelenau' : 'standard',
                'gelenauer_regeln' => $isGelenau,
                'three_aces_33' => $isGelenau,
            ],
            'last_event' => [
                'time' => microtime(true),
                'type' => 'lobby_created',
                'message' => 'Schnauzer-Tisch eröffnet.',
            ],
            'round_result' => null,
        ];
    }

    /**
     * Startet das Spiel aus der Lobby.
     */
    public function startGame(int $roomId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'lobby') {
            return ['success' => false, 'message' => 'Spiel kann nur in der Lobby gestartet werden.'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        if (count($players) < 2) {
            return ['success' => false, 'message' => 'Mindestens 2 Spieler (oder Bots) erforderlich.'];
        }

        $state = $room['state'];
        $state['phase'] = 'playing';
        $state['round_number'] = 0;

        // Alle Spieler mit 3 Leben initialisieren
        foreach ($players as &$p) {
            $sheet = $p['sheet'] ?? [];
            $sheet['lives'] = 3;
            $sheet['is_swimming'] = false;
            $sheet['is_out'] = false;
            $sheet['hand'] = [];
            $this->repo->updatePlayerSheet((int)$p['id'], $sheet, 3);
            $p['sheet'] = $sheet;
        }
        unset($p);

        // Ersten Geber bestimmen (Host)
        $dealerId = (int)$players[0]['id'];
        $res = $this->dealNewRound($roomId, $state, $players, $dealerId);
        if (!$res['success']) {
            return $res;
        }

        $this->repo->updateRoomState($roomId, $state, 'active');
        return ['success' => true];
    }

    /**
     * Teilt eine neue Schnauzer-Runde aus.
     */
    private function dealNewRound(int $roomId, array &$state, array &$players, int $dealerId): array
    {
        $activePlayers = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_out'])));
        $humanSurvivors = array_values(array_filter($activePlayers, fn($p) => empty($p['sheet']['is_bot'])));
        if (count($activePlayers) < 2 || count($humanSurvivors) === 0) {
            $state['phase'] = 'game_over';
            $winner = $activePlayers[0] ?? null;
            $state['round_result'] = [
                'winner_id' => $winner ? (int)$winner['id'] : null,
                'winner_name' => $winner ? (string)$winner['player_name'] : 'Niemand',
                'is_game_over' => true,
            ];
            $this->repo->updateRoomState($roomId, $state, 'finished');
            return ['success' => true];
        }

        $state['round_number']++;
        $state['dealer_id'] = $dealerId;
        $state['knocked_by_player_id'] = null;
        $state['turns_after_knock_left'] = 0;
        $state['round_turn_count'] = 0;
        $state['passed_players'] = [];
        $state['round_result'] = null;

        // 32er Skatdeck mischen
        $deck = SchnauzerDeckFactory::createShuffledDeck();

        // Jedem aktiven Spieler 3 Handkarten austeilen
        foreach ($players as &$p) {
            if (!empty($p['sheet']['is_out'])) {
                continue;
            }
            $hand = [array_shift($deck), array_shift($deck), array_shift($deck)];
            $sheet = $p['sheet'] ?? [];
            $sheet['hand'] = $hand;
            $this->repo->updatePlayerSheet((int)$p['id'], $sheet, (int)($sheet['lives'] ?? 3));
            $p['sheet'] = $sheet;
        }
        unset($p);

        // Geber erhält 3 zusätzliche Reservekarten (Päckchen B)
        $dealerReserve = [array_shift($deck), array_shift($deck), array_shift($deck)];

        $state['deck'] = $deck;
        $state['discard_pile'] = [];
        $state['table_cards'] = [];
        $state['dealer_reserve_cards'] = $dealerReserve;

        $dealer = null;
        foreach ($players as $p) {
            if ((int)$p['id'] === $dealerId) {
                $dealer = $p;
                break;
            }
        }

        $dealerName = $dealer ? (string)$dealer['player_name'] : 'Geber';
        $state['phase'] = 'dealing_choice';
        $state['current_player_id'] = $dealerId;
        $state['last_event'] = [
            'time' => microtime(true),
            'type' => 'cards_dealt',
            'message' => "Runde {$state['round_number']}: {$dealerName} teilt aus und wählt sein Päckchen.",
            'player_id' => $dealerId,
            'player_name' => $dealerName,
        ];

        // Falls Geber ein KI-Bot ist: Sofort entscheiden
        if (!empty($dealer['sheet']['is_bot'])) {
            $isGelenau = $this->isGelenauerRules($state);
            $dealerHand = $dealer['sheet']['hand'];
            $botChoice = SchnauzerBot::decideDealerChoice($dealerHand, $dealerReserve, $isGelenau);
            $this->applyDealerChoice($state, $players, $dealerId, $botChoice);
        }

        return ['success' => true];
    }

    /**
     * Wendet die Geber-Entscheidung an ('keep' oder 'swap').
     */
    public function handleDealerChoice(int $roomId, int $playerId, string $choice): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || ($room['status'] ?? '') !== 'active') {
            return ['success' => false, 'message' => 'Spiel nicht aktiv.'];
        }

        $state = $room['state'];
        if ($state['phase'] !== 'dealing_choice' || (int)$state['dealer_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Geber-Auswahl aktuell nicht möglich.'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $this->applyDealerChoice($state, $players, $playerId, $choice);
        $this->repo->updateRoomState($roomId, $state);

        // Falls nächster Spieler ein Bot ist: Zug ausführen
        $this->triggerBotTurnIfNeeded($roomId);

        return ['success' => true];
    }

    private function applyDealerChoice(array &$state, array &$players, int $dealerId, string $choice): void
    {
        $dealer = null;
        foreach ($players as &$p) {
            if ((int)$p['id'] === $dealerId) {
                $dealer = &$p;
                break;
            }
        }
        unset($p);

        if (!$dealer) return;

        $reserve = $state['dealer_reserve_cards'];
        $dealerHand = $dealer['sheet']['hand'];

        if ($choice === 'swap') {
            // Geber nimmt die 3 verdeckten Reservekarten, seine ursprünglichen kommen auf den Tisch
            $dealer['sheet']['hand'] = $reserve;
            $state['table_cards'] = $dealerHand;
            $this->repo->updatePlayerSheet($dealerId, $dealer['sheet'], (int)($dealer['sheet']['lives'] ?? 3));
            $choiceText = "{$dealer['player_name']} tauscht mit dem zweiten Päckchen.";
        } else {
            // Geber behält seine Hand, Reservekarten kommen auf den Tisch
            $state['table_cards'] = $reserve;
            $choiceText = "{$dealer['player_name']} behält das erste Päckchen.";
        }

        $state['dealer_reserve_cards'] = [];
        $state['phase'] = 'playing';

        // Nächster Spieler am Zug: links vom Geber (nächster aktiver Spieler)
        $activePlayers = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_out'])));
        $nextPlayerId = $this->getNextActivePlayerId($activePlayers, $dealerId);
        $state['current_player_id'] = $nextPlayerId;

        $state['last_event'] = [
            'time' => microtime(true),
            'type' => 'dealer_chosen',
            'message' => "{$choiceText} 3 Karten liegen offen in der Mitte.",
            'player_id' => $dealerId,
            'player_name' => (string)$dealer['player_name'],
        ];

        // Sofortige Prüfung: Hat jemand bereits 31 (oder 3 Asse / Schnauzer) auf der Hand?
        $isGelenau = $this->isGelenauerRules($state);
        $instantPlayerId = null;
        foreach ($activePlayers as $ap) {
            $score = SchnauzerScoreCalculator::calculateScore($ap['sheet']['hand'], $isGelenau);
            if ($score['is_feuer']) {
                $instantPlayerId = (int)$ap['id'];
                break;
            }
            if ($score['is_31'] && $instantPlayerId === null) {
                $instantPlayerId = (int)$ap['id'];
            }
        }

        if ($instantPlayerId !== null) {
            $this->executeShowdown($state, $players, true, $instantPlayerId);
            return;
        }
    }

    /**
     * Tauscht genau eine Handkarte mit einer Tischkarte.
     */
    public function swapOne(int $roomId, int $playerId, int $handIndex, int $tableIndex): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || ($room['status'] ?? '') !== 'active') {
            return ['success' => false, 'message' => 'Spiel nicht aktiv.'];
        }

        $state = $room['state'];
        if ($state['phase'] !== 'playing' || (int)$state['current_player_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Du bist nicht am Zug.'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $player = null;
        foreach ($players as &$p) {
            if ((int)$p['id'] === $playerId) {
                $player = &$p;
                break;
            }
        }
        unset($p);

        if (!$player) {
            return ['success' => false, 'message' => 'Spieler nicht gefunden.'];
        }

        $hand = $player['sheet']['hand'] ?? [];
        $table = $state['table_cards'] ?? [];

        if (!isset($hand[$handIndex]) || !isset($table[$tableIndex])) {
            return ['success' => false, 'message' => 'Ungültige Kartenauswahl.'];
        }

        // Tausch durchführen
        $cardFromHand = $hand[$handIndex];
        $cardFromTable = $table[$tableIndex];

        $hand[$handIndex] = $cardFromTable;
        $table[$tableIndex] = $cardFromHand;

        $player['sheet']['hand'] = $hand;
        $state['table_cards'] = $table;
        $this->repo->updatePlayerSheet($playerId, $player['sheet'], (int)($player['sheet']['lives'] ?? 3));

        $state['round_turn_count'] = ($state['round_turn_count'] ?? 0) + 1;
        $state['passed_players'] = [];
        $state['last_event'] = [
            'time' => microtime(true),
            'type' => 'card_swapped_single',
            'message' => "{$player['player_name']} hat 1 Karte getauscht.",
            'player_id' => $playerId,
            'player_name' => (string)$player['player_name'],
            'hand_card' => $cardFromTable,
            'table_card' => $cardFromHand,
            'hand_index' => $handIndex,
            'table_index' => $tableIndex,
        ];

        // Prüfung auf 31 / 3 Asse (Schnauzer)
        $isGelenau = $this->isGelenauerRules($state);
        $score = SchnauzerScoreCalculator::calculateScore($hand, $isGelenau);
        if ($score['is_31'] || $score['is_feuer']) {
            $this->executeShowdown($state, $players, true, $playerId);
            $this->repo->updateRoomState($roomId, $state);
            return ['success' => true];
        }

        // Zug beenden & an nächsten Spieler weitergeben
        $this->advanceTurn($state, $players);
        $this->repo->updateRoomState($roomId, $state);

        $this->triggerBotTurnIfNeeded($roomId);
        return ['success' => true];
    }

    /**
     * Tauscht alle drei Handkarten mit allen drei Tischkarten.
     */
    public function swapAll(int $roomId, int $playerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || ($room['status'] ?? '') !== 'active') {
            return ['success' => false, 'message' => 'Spiel nicht aktiv.'];
        }

        $state = $room['state'];
        if ($state['phase'] !== 'playing' || (int)$state['current_player_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Du bist nicht am Zug.'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $player = null;
        foreach ($players as &$p) {
            if ((int)$p['id'] === $playerId) {
                $player = &$p;
                break;
            }
        }
        unset($p);

        if (!$player) {
            return ['success' => false, 'message' => 'Spieler nicht gefunden.'];
        }

        $hand = $player['sheet']['hand'] ?? [];
        $table = $state['table_cards'] ?? [];

        // Komplett-Tausch
        $player['sheet']['hand'] = $table;
        $state['table_cards'] = $hand;
        $this->repo->updatePlayerSheet($playerId, $player['sheet'], (int)($player['sheet']['lives'] ?? 3));

        $state['round_turn_count'] = ($state['round_turn_count'] ?? 0) + 1;
        $state['passed_players'] = [];
        $state['last_event'] = [
            'time' => microtime(true),
            'type' => 'card_swapped_all',
            'message' => "{$player['player_name']} hat alle 3 Karten getauscht.",
            'player_id' => $playerId,
            'player_name' => (string)$player['player_name'],
        ];

        // Prüfung auf 31 / 3 Asse (Schnauzer)
        $isGelenau = $this->isGelenauerRules($state);
        $score = SchnauzerScoreCalculator::calculateScore($player['sheet']['hand'], $isGelenau);
        if ($score['is_31'] || $score['is_feuer']) {
            $this->executeShowdown($state, $players, true, $playerId);
            $this->repo->updateRoomState($roomId, $state);
            return ['success' => true];
        }

        $this->advanceTurn($state, $players);
        $this->repo->updateRoomState($roomId, $state);

        $this->triggerBotTurnIfNeeded($roomId);
        return ['success' => true];
    }

    /**
     * Schieben ("Ich passe") — nur in Standard-Regeln erlaubt.
     */
    public function passTurn(int $roomId, int $playerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || ($room['status'] ?? '') !== 'active') {
            return ['success' => false, 'message' => 'Spiel nicht aktiv.'];
        }

        $state = $room['state'];
        if ($state['phase'] !== 'playing' || (int)$state['current_player_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Du bist nicht am Zug.'];
        }

        if ($this->isGelenauerRules($state)) {
            if ($state['knocked_by_player_id'] !== null) {
                return $this->knock($roomId, $playerId);
            }
            return [
                'success' => false,
                'message' => 'Nach Gelenauer Regeln gibt es kein Schieben! Du musst 1 oder 3 Karten tauschen oder zumachen.',
            ];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $player = null;
        foreach ($players as $p) {
            if ((int)$p['id'] === $playerId) {
                $player = $p;
                break;
            }
        }

        if (!$player) {
            return ['success' => false, 'message' => 'Spieler nicht gefunden.'];
        }

        $activePlayers = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_out'])));

        $state['round_turn_count'] = ($state['round_turn_count'] ?? 0) + 1;
        if (!in_array($playerId, $state['passed_players'], true)) {
            $state['passed_players'][] = $playerId;
        }

        $state['last_event'] = [
            'time' => microtime(true),
            'type' => 'player_passed',
            'message' => "{$player['player_name']} schiebt.",
            'player_id' => $playerId,
            'player_name' => (string)$player['player_name'],
        ];

        // Wenn alle aktiven Spieler hintereinander geschoben haben: 3 neue Karten in die Mitte!
        if (count($state['passed_players']) >= count($activePlayers)) {
            $this->replaceTableCards($state);
            $state['passed_players'] = [];
            $state['last_event']['message'] .= ' – Alle haben geschoben: 3 neue Karten aufgedeckt!';
        }

        $this->advanceTurn($state, $players);
        $this->repo->updateRoomState($roomId, $state);

        $this->triggerBotTurnIfNeeded($roomId);
        return ['success' => true];
    }

    /**
     * Schließen / Klopfen ("Ich mache zu").
     * In den Gelenauer Regeln darf bereits ab dem 1. Umlauf zugemacht werden,
     * und in der Schlussrunde nach dem ersten Zumachen dürfen nachfolgende Spieler
     * ebenfalls "zumachen" (Karten behalten).
     */
    public function knock(int $roomId, int $playerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || ($room['status'] ?? '') !== 'active') {
            return ['success' => false, 'message' => 'Spiel nicht aktiv.'];
        }

        $state = $room['state'];
        if ($state['phase'] !== 'playing' || (int)$state['current_player_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Du bist nicht am Zug.'];
        }

        $isGelenau = $this->isGelenauerRules($state);

        $players = $this->repo->getPlayersForRoom($roomId);
        $player = null;
        foreach ($players as $p) {
            if ((int)$p['id'] === $playerId) {
                $player = $p;
                break;
            }
        }

        if (!$player) {
            return ['success' => false, 'message' => 'Spieler nicht gefunden.'];
        }

        // Fall 1: Es wurde bereits von jemandem zugemacht (Schlussrunde läuft)
        if ($state['knocked_by_player_id'] !== null) {
            if (!$isGelenau) {
                return ['success' => false, 'message' => 'Es wurde bereits geklopft.'];
            }

            // Gelenauer Regeln: Nachfolgende Spieler dürfen in der Schlussrunde ebenfalls "zumachen" (Karten behalten)
            $state['round_turn_count'] = ($state['round_turn_count'] ?? 0) + 1;
            $state['last_event'] = [
                'time' => microtime(true),
                'type' => 'player_knocked',
                'message' => "✊ {$player['player_name']} macht ebenfalls zu (behält seine Karten).",
                'player_id' => $playerId,
                'player_name' => (string)$player['player_name'],
            ];

            $this->advanceTurn($state, $players);
            $this->repo->updateRoomState($roomId, $state);

            $this->triggerBotTurnIfNeeded($roomId);
            return ['success' => true];
        }

        // Fall 2: Erster Spieler macht zu (läutet Schlussrunde ein)
        $activePlayers = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_out'])));

        // Umlaufschutz gilt nur in den Standard-Regeln (bei Gelenauer Regeln darf sofort in Runde 1 zugemacht werden)
        if (!$isGelenau && (($state['round_turn_count'] ?? 0) < count($activePlayers))) {
            return ['success' => false, 'message' => 'In der ersten Runde darf nicht geklopft werden.'];
        }

        $state['round_turn_count'] = ($state['round_turn_count'] ?? 0) + 1;
        $state['knocked_by_player_id'] = $playerId;
        $state['turns_after_knock_left'] = count($activePlayers) - 1;
        $state['passed_players'] = [];

        $state['last_event'] = [
            'time' => microtime(true),
            'type' => 'player_knocked',
            'message' => "✊ {$player['player_name']} hat zugemacht (geklopft)! Letzte Runde für alle anderen.",
            'player_id' => $playerId,
            'player_name' => (string)$player['player_name'],
        ];

        // Der Spieler, der geklopft hat, beendet damit seinen Zug.
        // Die verbleibenden Züge (turns_after_knock_left) werden erst verringert,
        // wenn die übrigen Spieler ihre nachfolgenden Züge ausführen!
        $currId = (int)$state['current_player_id'];
        $nextId = $this->getNextActivePlayerId($activePlayers, $currId);
        $state['current_player_id'] = $nextId;

        $this->repo->updateRoomState($roomId, $state);

        $this->triggerBotTurnIfNeeded($roomId);
        return ['success' => true];
    }

    /**
     * Ersetzt die 3 Tischkarten durch 3 frische Karten vom Nachziehstapel.
     */
    private function replaceTableCards(array &$state): void
    {
        $deck = $state['deck'] ?? [];
        $discard = $state['discard_pile'] ?? [];

        // Bisherige Tischkarten auf Ablagestapel
        foreach ($state['table_cards'] as $tc) {
            $discard[] = $tc;
        }

        if (count($deck) < 3) {
            // Ablagestapel neu mischen
            for ($i = count($discard) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                $tmp = $discard[$i];
                $discard[$i] = $discard[$j];
                $discard[$j] = $tmp;
            }
            $deck = array_merge($deck, $discard);
            $discard = [];
        }

        $newTable = [];
        for ($k = 0; $k < 3 && !empty($deck); $k++) {
            $newTable[] = array_shift($deck);
        }

        $state['deck'] = $deck;
        $state['discard_pile'] = $discard;
        $state['table_cards'] = $newTable;
    }

    /**
     * Gibt den Zug an den nächsten aktiven Spieler weiter bzw. leitet den Showdown ein.
     */
    private function advanceTurn(array &$state, array &$players): void
    {
        $activePlayers = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_out'])));

        // Falls geklopft wurde: verbleibende Züge herunterzählen
        if ($state['knocked_by_player_id'] !== null) {
            $state['turns_after_knock_left']--;
            if ($state['turns_after_knock_left'] <= 0) {
                // Schlussrunde beendet -> Showdown!
                $this->executeShowdown($state, $players, false, null);
                return;
            }
        }

        $currId = (int)$state['current_player_id'];
        $nextId = $this->getNextActivePlayerId($activePlayers, $currId);
        $state['current_player_id'] = $nextId;
    }

    /**
     * Wertet den Showdown aus, verteilt Lebensabzüge (sowie Bonusleben bei Gelenauer Schnauzer) und prüft auf Spielende.
     */
    private function executeShowdown(array &$state, array &$players, bool $wasInstantSchnauzer, ?int $schnauzerPlayerId): void
    {
        $activePlayers = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_out'])));
        $scores = [];
        $minScore = 999.0;
        $isGelenau = $this->isGelenauerRules($state);

        $bonusLifePlayerId = null;
        $bonusLifePlayerName = null;

        foreach ($activePlayers as $ap) {
            $pid = (int)$ap['id'];
            $res = SchnauzerScoreCalculator::calculateScore($ap['sheet']['hand'], $isGelenau);
            $scores[$pid] = [
                'player_id' => $pid,
                'player_name' => (string)$ap['player_name'],
                'score' => $res['score'],
                'label' => $res['label'],
                'hand' => $ap['sheet']['hand'],
                'is_31' => $res['is_31'],
                'is_feuer' => $res['is_feuer'],
            ];

            if ($isGelenau && !empty($res['is_feuer'])) {
                $bonusLifePlayerId = $pid;
                $bonusLifePlayerName = (string)$ap['player_name'];
            }

            if ($res['score'] < $minScore) {
                $minScore = $res['score'];
            }
        }

        // Verlierer ermitteln (alle Spieler mit dem niedrigsten Score)
        $loserIds = [];
        $eliminatedNames = [];

        foreach ($scores as $pid => $info) {
            if ($info['score'] === $minScore) {
                $loserIds[] = $pid;
            }
        }

        // Gleichstand aller Spieler ("Niemand verliert"):
        // Wenn am Ende der Runde wirklich alle aktiven Spieler exakt die gleiche Punktzahl halten,
        // verliert niemand ein Leben und es wird neu gegeben.
        $isAllTied = (count($activePlayers) >= 2 && count($loserIds) === count($activePlayers));

        if ($isAllTied) {
            $loserIds = [];
        } else {
            // Gelenauer Regeln: Bei Schnauzer (3 Asse) erhält der Inhaber +1 Leben
            if ($bonusLifePlayerId !== null) {
                foreach ($players as &$p) {
                    $pid = (int)$p['id'];
                    if ($pid !== $bonusLifePlayerId) continue;

                    $sheet = &$p['sheet'];
                    if (!empty($sheet['is_swimming'])) {
                        $sheet['is_swimming'] = false;
                        $sheet['lives'] = 1;
                    } else {
                        $sheet['lives'] = (int)($sheet['lives'] ?? 3) + 1;
                    }
                    $this->repo->updatePlayerSheet($pid, $sheet, (int)$sheet['lives']);
                    break;
                }
                unset($p);
            }

            // Lebensabzüge für die Spieler mit der niedrigsten Punktzahl durchführen
            foreach ($players as &$p) {
                $pid = (int)$p['id'];
                if (!in_array($pid, $loserIds, true)) continue;

                $sheet = &$p['sheet'];
                if ($sheet['lives'] > 1) {
                    $sheet['lives']--;
                } elseif ($sheet['lives'] === 1) {
                    $sheet['lives'] = 0;
                    $sheet['is_swimming'] = true; // Schwimmer / Schiebeleben
                } elseif ($sheet['is_swimming']) {
                    $sheet['is_swimming'] = false;
                    $sheet['is_out'] = true; // Ausgeschieden / ertrunken
                    $eliminatedNames[] = (string)$p['player_name'];
                }
                $this->repo->updatePlayerSheet($pid, $sheet, (int)($sheet['lives'] ?? 0));
            }
            unset($p);
        }

        // Prüfen, wie viele noch im Spiel sind
        $survivors = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_out'])));
        $humanSurvivors = array_values(array_filter($survivors, fn($p) => empty($p['sheet']['is_bot'])));
        $onlyBotsLeft = (count($humanSurvivors) === 0 && count($survivors) > 0);

        if ($onlyBotsLeft && count($survivors) > 1) {
            // Wenn alle menschlichen Spieler ausgeschieden sind: führenden Bot als Sieger ermitteln
            usort($survivors, function ($a, $b) use ($scores) {
                $livesA = (int)($a['sheet']['lives'] ?? 0);
                $livesB = (int)($b['sheet']['lives'] ?? 0);
                if ($livesA !== $livesB) return $livesB <=> $livesA;
                $swimA = !empty($a['sheet']['is_swimming']) ? 0 : 1;
                $swimB = !empty($b['sheet']['is_swimming']) ? 0 : 1;
                if ($swimA !== $swimB) return $swimB <=> $swimA;
                $scoreA = $scores[$a['id']]['score'] ?? 0;
                $scoreB = $scores[$b['id']]['score'] ?? 0;
                return $scoreB <=> $scoreA;
            });
        }

        $loserNames = array_map(function ($id) use ($scores) {
            return $scores[$id]['player_name'] ?? 'Spieler';
        }, $loserIds);

        if ($wasInstantSchnauzer) {
            $triggerInfo = $scores[$schnauzerPlayerId] ?? null;
            $triggerName = $triggerInfo['player_name'] ?? 'Spieler';
            if ($isGelenau) {
                if (!empty($triggerInfo['is_feuer'])) {
                    $endReason = "⚡ {$triggerName} legt einen SCHNAUZER (3 Asse) aus (+1 Leben)!";
                } else {
                    $endReason = "⚡ {$triggerName} legt 31 Punkte aus! Sofortiges Rundenende.";
                }
            } else {
                $endReason = "⚡ {$triggerName} hat 31 (Schnauzer)! Sofortiges Rundenende.";
            }
        } else {
            $endReason = $isGelenau ? "Runde beendet nach Zumachen." : "Runde beendet nach Klopfen.";
        }

        $isGameOver = !$isAllTied && (count($survivors) <= 1 || $onlyBotsLeft);
        $wName = ($isGameOver && count($survivors) >= 1) ? (string)$survivors[0]['player_name'] : null;
        $wId = ($isGameOver && count($survivors) >= 1) ? (int)$survivors[0]['id'] : null;

        $state['round_result'] = [
            'scores' => $scores,
            'min_score' => $minScore,
            'is_all_tied' => $isAllTied,
            'only_bots_left' => $onlyBotsLeft,
            'loser_ids' => $loserIds,
            'loser_names' => implode(', ', $loserNames),
            'eliminated_names' => $eliminatedNames,
            'is_game_over' => $isGameOver,
            'winner_id' => $wId,
            'winner_name' => $wName,
            'was_instant_schnauzer' => $wasInstantSchnauzer,
            'schnauzer_player_id' => $schnauzerPlayerId,
            'bonus_life_player_id' => $bonusLifePlayerId,
            'bonus_life_player_name' => $bonusLifePlayerName,
        ];

        // Die Runde geht IMMER in den Showdown, damit alle Spieler die Hände und Punkte
        // des finalen Spiels sehen können.
        $state['phase'] = 'showdown';
        $state['last_event'] = [
            'time' => microtime(true),
            'type' => $isGameOver ? 'game_over' : 'round_ended',
            'message' => $isGameOver
                ? ($onlyBotsLeft && count($survivors) > 1
                    ? "🏆 Spiel beendet! Alle menschlichen Spieler sind ausgeschieden. {$wName} (Bot) gewinnt nach Punkten/Leben!"
                    : "🏆 Spiel vorbei! {$wName} hat als letzter Überlebender gewonnen!")
                : ($isAllTied
                    ? "{$endReason} 🤝 Gleichstand aller Spieler ({$minScore} Punkte) – niemand verliert ein Leben!"
                    : "{$endReason} Verliert 1 Leben: " . implode(', ', $loserNames)),
        ];
    }

    /**
     * Startet die nächste Runde nach einem Showdown.
     */
    public function startNextRound(int $roomId, int $requestingPlayerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || ($room['status'] ?? '') !== 'active') {
            return ['success' => false, 'message' => 'Spiel nicht aktiv.'];
        }

        $state = $room['state'];
        if ($state['phase'] !== 'showdown') {
            return ['success' => false, 'message' => 'Runde kann aktuell nicht neu gestartet werden.'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $activePlayers = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_out'])));

        $humanSurvivors = array_values(array_filter($activePlayers, fn($p) => empty($p['sheet']['is_bot'])));
        if (count($activePlayers) < 2 || count($humanSurvivors) === 0) {
            return ['success' => false, 'message' => 'Spiel beendet.'];
        }

        // Nächsten Geber ermitteln: Bei Gleichstand aller Spieler gibt derselbe Geber noch einmal neu,
        // ansonsten reihum unter den Überlebenden.
        $currDealerId = (int)($state['dealer_id'] ?? $activePlayers[0]['id']);
        $isAllTied = !empty($state['round_result']['is_all_tied']);
        $nextDealerId = $isAllTied ? $currDealerId : $this->getNextActivePlayerId($activePlayers, $currDealerId);

        $res = $this->dealNewRound($roomId, $state, $players, $nextDealerId);
        if (!$res['success']) {
            return $res;
        }

        $this->repo->updateRoomState($roomId, $state);
        $this->triggerBotTurnIfNeeded($roomId);
        return ['success' => true];
    }

    /**
     * Startet das gesamte Spiel neu (z. B. nach Game Over).
     */
    public function restartGame(int $roomId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden.'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        foreach ($players as $p) {
            $sheet = $p['sheet'] ?? [];
            $sheet['lives'] = 3;
            $sheet['is_swimming'] = false;
            $sheet['is_out'] = false;
            $sheet['hand'] = [];
            $this->repo->updatePlayerSheet((int)$p['id'], $sheet, 3);
        }

        $roomState = $room['state'] ?? [];
        $isGelenau = $this->isGelenauerRules($roomState);
        $state = $this->getInitialState($isGelenau ? 'gelenau' : 'standard');
        $this->repo->updateRoomState($roomId, $state, 'lobby');
        return ['success' => true];
    }

    /**
     * Führt automatisch den Zug eines KI-Bots aus, falls dieser an der Reihe ist.
     */
    public function triggerBotTurnIfNeeded(int $roomId): bool
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || ($room['status'] ?? '') !== 'active') {
            return false;
        }

        $state = $room['state'];
        if ($state['phase'] !== 'playing') {
            return false;
        }

        $activePlayerId = (int)$state['current_player_id'];
        $players = $this->repo->getPlayersForRoom($roomId);
        $activePlayer = null;

        foreach ($players as $p) {
            if ((int)$p['id'] === $activePlayerId) {
                $activePlayer = $p;
                break;
            }
        }

        if (!$activePlayer || empty($activePlayer['sheet']['is_bot'])) {
            return false; // Mensch am Zug
        }

        $hand = $activePlayer['sheet']['hand'] ?? [];
        $table = $state['table_cards'] ?? [];
        $isKnocked = ($state['knocked_by_player_id'] !== null);
        $activePlayers = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_out'])));
        $roundTurnCount = (int)($state['round_turn_count'] ?? 0);
        $isGelenau = $this->isGelenauerRules($state);
        $canKnock = $isGelenau ? true : (!$isKnocked && ($roundTurnCount >= count($activePlayers)));

        $decision = SchnauzerBot::decideTurn($hand, $table, $isKnocked, $canKnock, $roundTurnCount, $isGelenau);

        if ($decision['action'] === 'swap_one') {
            $this->swapOne($roomId, $activePlayerId, (int)$decision['hand_index'], (int)$decision['table_index']);
        } elseif ($decision['action'] === 'swap_all') {
            $this->swapAll($roomId, $activePlayerId);
        } elseif ($decision['action'] === 'knock' && $canKnock) {
            $this->knock($roomId, $activePlayerId);
        } elseif ($isGelenau) {
            $this->knock($roomId, $activePlayerId);
        } else {
            $this->passTurn($roomId, $activePlayerId);
        }

        return true;
    }

    /**
     * Fügt dem Tisch einen KI-Bot hinzu.
     */
    public function addBot(int $roomId, string $botName): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'lobby') {
            return ['success' => false, 'message' => 'Bots können nur in der Lobby hinzugefügt werden.'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        if (count($players) >= 8) {
            return ['success' => false, 'message' => 'Maximale Spielerzahl erreicht (8).'];
        }

        $botTokenHash = hash('sha256', 'bot_' . bin2hex(random_bytes(16)));
        $botSheet = [
            'lives' => 3,
            'is_swimming' => false,
            'is_out' => false,
            'hand' => [],
            'is_bot' => true,
        ];

        $player = $this->repo->joinPlayer($roomId, $botTokenHash, $botName, false, $botSheet);
        return [
            'success' => true,
            'bot' => [
                'id' => $player['id'],
                'player_name' => $botName,
                'is_bot' => true,
            ],
        ];
    }

    /**
     * Entfernt einen Bot.
     */
    public function kickBot(int $roomId, int $botId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'lobby') {
            return ['success' => false, 'message' => 'Nur in der Lobby möglich.'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        foreach ($players as $p) {
            if ((int)$p['id'] === $botId && !empty($p['sheet']['is_bot'])) {
                $this->repo->leavePlayer($roomId, $botId);
                return ['success' => true];
            }
        }

        return ['success' => false, 'message' => 'Bot nicht gefunden.'];
    }

    /**
     * Liefert den für den anfragenden Spieler gefilterten Spielzustand (Verdeckte Karten).
     */
    public function getPublicGameState(int $roomId, ?int $forPlayerId): ?array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) return null;

        $state = $room['state'] ?? [];
        $players = $this->repo->getPlayersForRoom($roomId);
        $phase = $state['phase'] ?? 'lobby';

        $isShowdownOrOver = in_array($phase, ['showdown', 'game_over'], true);
        $isGelenau = $this->isGelenauerRules($state);

        $publicPlayers = [];
        $myScoreInfo = null;

        foreach ($players as $p) {
            $pid = (int)$p['id'];
            $isMe = ($forPlayerId !== null && $pid === $forPlayerId);
            $sheet = $p['sheet'] ?? [];
            $hand = $sheet['hand'] ?? [];

            // Eigene Handkarten sind immer sichtbar; fremde nur im Showdown / Game Over
            $visibleHand = ($isMe || $isShowdownOrOver) ? $hand : array_fill(0, count($hand), ['is_hidden' => true]);

            $scoreInfo = null;
            if ($isMe && !empty($hand)) {
                $scoreInfo = SchnauzerScoreCalculator::calculateScore($hand, $isGelenau);
                $myScoreInfo = $scoreInfo;
            } elseif ($isShowdownOrOver && !empty($hand)) {
                $scoreInfo = SchnauzerScoreCalculator::calculateScore($hand, $isGelenau);
            }

            $publicPlayers[] = [
                'id' => $pid,
                'name' => (string)$p['player_name'],
                'is_host' => (bool)$p['is_host'],
                'is_bot' => (bool)($sheet['is_bot'] ?? false),
                'is_active' => ($pid === (int)($state['current_player_id'] ?? 0)),
                'is_dealer' => ($pid === (int)($state['dealer_id'] ?? 0)),
                'is_me' => $isMe,
                'lives' => (int)($sheet['lives'] ?? 3),
                'is_swimming' => (bool)($sheet['is_swimming'] ?? false),
                'is_out' => (bool)($sheet['is_out'] ?? false),
                'card_count' => count($hand),
                'hand' => $visibleHand,
                'score_info' => $scoreInfo,
            ];
        }

        $activePlayers = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_out'])));
        $roundTurnCount = (int)($state['round_turn_count'] ?? 0);

        $canKnock = $isGelenau
            ? true
            : (empty($state['knocked_by_player_id']) && ($roundTurnCount >= count($activePlayers)));

        return [
            'room_code' => $room['room_code'],
            'room_status' => $room['status'],
            'phase' => $phase,
            'round_number' => (int)($state['round_number'] ?? 0),
            'round_turn_count' => $roundTurnCount,
            'can_knock' => $canKnock,
            'rules' => [
                'mode' => $isGelenau ? 'gelenau' : 'standard',
                'gelenauer_regeln' => $isGelenau,
                'three_aces_33' => $isGelenau,
            ],
            'current_player_id' => $state['current_player_id'] ?? null,
            'dealer_id' => $state['dealer_id'] ?? null,
            'knocked_by_player_id' => $state['knocked_by_player_id'] ?? null,
            'turns_after_knock_left' => $state['turns_after_knock_left'] ?? 0,
            'table_cards' => $state['table_cards'] ?? [],
            'dealer_reserve_cards' => ($forPlayerId === (int)($state['dealer_id'] ?? 0)) ? ($state['dealer_reserve_cards'] ?? []) : [],
            'last_event' => $state['last_event'] ?? null,
            'round_result' => $state['round_result'] ?? null,
            'players' => $publicPlayers,
            'my_score' => $myScoreInfo,
            'state_version' => (int)$room['state_version'],
        ];
    }

    private function getNextActivePlayerId(array $activePlayers, int $currentId): int
    {
        $count = count($activePlayers);
        if ($count === 0) return 0;

        $currentIndex = 0;
        foreach ($activePlayers as $idx => $p) {
            if ((int)$p['id'] === $currentId) {
                $currentIndex = $idx;
                break;
            }
        }

        $nextIndex = ($currentIndex + 1) % $count;
        return (int)$activePlayers[$nextIndex]['id'];
    }
}
