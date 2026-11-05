<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Schnauzer;

use Kai\Tools\GamesHub\RoomRepository;

class SchnauzerService
{
    public function __construct(private RoomRepository $repo)
    {
    }

    /**
     * Erzeugt den initialen Raumzustand.
     *
     * @return array<string, mixed>
     */
    public function getInitialState(bool $threeAces33 = false): array
    {
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
                'three_aces_33' => $threeAces33,
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
        if (count($activePlayers) < 2) {
            $state['phase'] = 'game_over';
            $winner = $activePlayers[0] ?? null;
            $state['round_result'] = [
                'winner_id' => $winner ? (int)$winner['id'] : null,
                'winner_name' => $winner ? (string)$winner['player_name'] : 'Niemand',
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
            $threeAces33 = !empty($state['rules']['three_aces_33']);
            $dealerHand = $dealer['sheet']['hand'];
            $botChoice = SchnauzerBot::decideDealerChoice($dealerHand, $dealerReserve, $threeAces33);
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

        // Sofortige Schnauzer-Prüfung: Hat jemand bereits 31 (oder 33) auf der Hand?
        $threeAces33 = !empty($state['rules']['three_aces_33']);
        foreach ($activePlayers as $ap) {
            $score = SchnauzerScoreCalculator::calculateScore($ap['sheet']['hand'], $threeAces33);
            if ($score['is_31'] || $score['is_feuer']) {
                $this->executeShowdown($state, $players, true, (int)$ap['id']);
                return;
            }
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

        // Prüfung auf 31 / 33 Schnauzer
        $threeAces33 = !empty($state['rules']['three_aces_33']);
        $score = SchnauzerScoreCalculator::calculateScore($hand, $threeAces33);
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

        // Prüfung auf 31 / 33 Schnauzer
        $threeAces33 = !empty($state['rules']['three_aces_33']);
        $score = SchnauzerScoreCalculator::calculateScore($player['sheet']['hand'], $threeAces33);
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
     * Schieben ("Ich passe").
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

        if ($state['knocked_by_player_id'] !== null) {
            return ['success' => false, 'message' => 'Es wurde bereits geklopft.'];
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

        // Umlaufschutz: In der ersten Runde darf nicht geklopft werden!
        if (($state['round_turn_count'] ?? 0) < count($activePlayers)) {
            return ['success' => false, 'message' => 'In der ersten Runde darf nicht geklopft werden.'];
        }

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
     * Wertet den Showdown aus, verteilt Lebensabzüge und prüft auf Spielende.
     */
    private function executeShowdown(array &$state, array &$players, bool $wasInstantSchnauzer, ?int $schnauzerPlayerId): void
    {
        $activePlayers = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_out'])));
        $scores = [];
        $minScore = 999.0;
        $threeAces33 = !empty($state['rules']['three_aces_33']);

        foreach ($activePlayers as $ap) {
            $pid = (int)$ap['id'];
            $res = SchnauzerScoreCalculator::calculateScore($ap['sheet']['hand'], $threeAces33);
            $scores[$pid] = [
                'player_id' => $pid,
                'player_name' => (string)$ap['player_name'],
                'score' => $res['score'],
                'label' => $res['label'],
                'hand' => $ap['sheet']['hand'],
                'is_31' => $res['is_31'],
                'is_feuer' => $res['is_feuer'],
            ];

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
            // Lebensabzüge durchführen
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

        $loserNames = array_map(function ($id) use ($scores) {
            return $scores[$id]['player_name'] ?? 'Spieler';
        }, $loserIds);

        $endReason = $wasInstantSchnauzer
            ? "⚡ {$scores[$schnauzerPlayerId]['player_name']} hat 31 (Schnauzer)! Sofortiges Rundenende."
            : "Runde beendet nach Klopfen.";

        $isGameOver = !$isAllTied && (count($survivors) <= 1);
        $wName = (count($survivors) === 1) ? (string)$survivors[0]['player_name'] : null;
        $wId = (count($survivors) === 1) ? (int)$survivors[0]['id'] : null;

        $state['round_result'] = [
            'scores' => $scores,
            'min_score' => $minScore,
            'is_all_tied' => $isAllTied,
            'loser_ids' => $loserIds,
            'loser_names' => implode(', ', $loserNames),
            'eliminated_names' => $eliminatedNames,
            'is_game_over' => $isGameOver,
            'winner_id' => $wId,
            'winner_name' => $wName,
            'was_instant_schnauzer' => $wasInstantSchnauzer,
            'schnauzer_player_id' => $schnauzerPlayerId,
        ];

        // Die Runde geht IMMER in den Showdown, damit alle Spieler die Hände und Punkte
        // des finalen Spiels sehen können.
        $state['phase'] = 'showdown';
        $state['last_event'] = [
            'time' => microtime(true),
            'type' => $isGameOver ? 'game_over' : 'round_ended',
            'message' => $isGameOver
                ? "🏆 Spiel vorbei! {$wName} hat als letzter Überlebender gewonnen!"
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

        if (count($activePlayers) < 2) {
            return ['success' => false, 'message' => 'Nicht genug Überlebende für eine weitere Runde.'];
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
        $threeAces33 = !empty($roomState['rules']['three_aces_33']);
        $state = $this->getInitialState($threeAces33);
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
        $canKnock = !$isKnocked && ($roundTurnCount >= count($activePlayers));

        $threeAces33 = !empty($state['rules']['three_aces_33']);
        $decision = SchnauzerBot::decideTurn($hand, $table, $isKnocked, $canKnock, $roundTurnCount, $threeAces33);

        if ($decision['action'] === 'swap_one') {
            $this->swapOne($roomId, $activePlayerId, (int)$decision['hand_index'], (int)$decision['table_index']);
        } elseif ($decision['action'] === 'swap_all') {
            $this->swapAll($roomId, $activePlayerId);
        } elseif ($decision['action'] === 'knock' && $canKnock) {
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
            $threeAces33 = !empty($state['rules']['three_aces_33']);
            if ($isMe && !empty($hand)) {
                $scoreInfo = SchnauzerScoreCalculator::calculateScore($hand, $threeAces33);
                $myScoreInfo = $scoreInfo;
            } elseif ($isShowdownOrOver && !empty($hand)) {
                $scoreInfo = SchnauzerScoreCalculator::calculateScore($hand, $threeAces33);
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

        return [
            'room_code' => $room['room_code'],
            'room_status' => $room['status'],
            'phase' => $phase,
            'round_number' => (int)($state['round_number'] ?? 0),
            'round_turn_count' => $roundTurnCount,
            'can_knock' => empty($state['knocked_by_player_id']) && ($roundTurnCount >= count($activePlayers)),
            'rules' => $state['rules'] ?? ['three_aces_33' => false],
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
