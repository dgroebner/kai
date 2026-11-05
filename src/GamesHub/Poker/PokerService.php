<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Poker;

use Kai\Tools\GamesHub\RoomRepository;

/**
 * Vollständige Spiellogik für No-Limit Texas Hold'em Poker.
 */
class PokerService
{
    private RoomRepository $repo;

    public function __construct(?RoomRepository $repo = null)
    {
        $this->repo = $repo ?? new RoomRepository();
    }

    /**
     * Erzeugt den initialen Zustand für einen neuen Pokerraum.
     */
    public function getInitialState(): array
    {
        return [
            'phase' => 'lobby', // 'lobby', 'preflop', 'flop', 'turn', 'river', 'showdown', 'game_over'
            'hand_number' => 0,
            'small_blind' => 10,
            'big_blind' => 20,
            'dealer_id' => null,
            'sb_player_id' => null,
            'bb_player_id' => null,
            'current_player_id' => null,
            'pot' => 0,
            'current_highest_bet' => 0,
            'min_raise' => 20,
            'community_cards' => [],
            'deck' => [],
            'round_result' => null,
            'last_event' => null,
            'abort_votes' => [],
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
            return ['success' => false, 'message' => 'Mindestens 2 Spieler erforderlich.'];
        }

        $state = $room['state'];
        $state['hand_number'] = 0;
        $state['abort_votes'] = [];

        // Jedem Spieler 1000 Startchips zuweisen
        foreach ($players as &$p) {
            $sheet = $p['sheet'] ?? [];
            $sheet['chips'] = 1000;
            $sheet['current_bet'] = 0;
            $sheet['total_bet'] = 0;
            $sheet['is_folded'] = false;
            $sheet['is_all_in'] = false;
            $sheet['is_out'] = false;
            $sheet['has_acted'] = false;
            $sheet['hand'] = [];
            $this->repo->updatePlayerSheet((int)$p['id'], $sheet, 1000);
            $p['sheet'] = $sheet;
        }
        unset($p);

        // Ersten Geber bestimmen
        $dealerId = (int)$players[0]['id'];
        $res = $this->dealNewHand($roomId, $state, $players, $dealerId);
        if (!$res['success']) {
            return $res;
        }

        $this->repo->updateRoomState($roomId, $state, 'active');
        $this->triggerBotTurnIfNeeded($roomId);

        return ['success' => true];
    }

    /**
     * Teilt eine neue Pokerhand aus.
     */
    private function dealNewHand(int $roomId, array &$state, array &$players, int $dealerId): array
    {
        // Aktive Spieler mit Chips > 0
        $activePlayers = array_values(array_filter($players, fn($p) => (int)($p['sheet']['chips'] ?? 0) > 0));

        // Prüfen, ob menschliche Spieler noch Chips haben
        $humanActive = array_values(array_filter($activePlayers, fn($p) => empty($p['sheet']['is_bot'])));

        if (count($activePlayers) < 2 || count($humanActive) === 0) {
            $state['phase'] = 'game_over';
            $winner = $activePlayers[0] ?? null;
            $state['round_result'] = [
                'winner_id' => $winner ? (int)$winner['id'] : null,
                'winner_name' => $winner ? (string)$winner['player_name'] : 'Niemand',
                'is_game_over' => true,
                'payouts' => [],
                'pot_resolutions' => [],
            ];
            $this->repo->updateRoomState($roomId, $state, 'finished');
            return ['success' => true];
        }

        $state['hand_number']++;
        $state['community_cards'] = [];
        $state['round_result'] = null;
        $state['abort_votes'] = [];

        // Positionen ermitteln: Dealer, Small Blind, Big Blind
        $state['dealer_id'] = $dealerId;
        $nActive = count($activePlayers);

        $sbId = null;
        $bbId = null;

        if ($nActive === 2) {
            // Heads-Up: Dealer ist SB und zahlt SB, der andere ist BB
            $sbId = $dealerId;
            $bbId = $this->getNextActivePlayerId($activePlayers, $dealerId);
        } else {
            $sbId = $this->getNextActivePlayerId($activePlayers, $dealerId);
            $bbId = $this->getNextActivePlayerId($activePlayers, $sbId);
        }

        $state['sb_player_id'] = $sbId;
        $state['bb_player_id'] = $bbId;

        // Blinds einziehen
        $sbAmount = (int)$state['small_blind'];
        $bbAmount = (int)$state['big_blind'];
        $pot = 0;

        foreach ($players as &$p) {
            $pid = (int)$p['id'];
            $sheet = $p['sheet'] ?? [];
            $chips = (int)($sheet['chips'] ?? 0);

            if ($chips <= 0) {
                $sheet['is_out'] = true;
                $sheet['is_folded'] = true;
                $sheet['current_bet'] = 0;
                $sheet['total_bet'] = 0;
                $sheet['is_all_in'] = false;
                $sheet['hand'] = [];
                $sheet['has_acted'] = false;
                $this->repo->updatePlayerSheet($pid, $sheet, 0);
                $p['sheet'] = $sheet;
                continue;
            }

            $sheet['is_out'] = false;
            $sheet['is_folded'] = false;
            $sheet['is_all_in'] = false;
            $sheet['has_acted'] = false;
            $sheet['current_bet'] = 0;
            $sheet['total_bet'] = 0;

            if ($pid === $sbId) {
                $actualSb = min($chips, $sbAmount);
                $chips -= $actualSb;
                $sheet['chips'] = $chips;
                $sheet['current_bet'] = $actualSb;
                $sheet['total_bet'] = $actualSb;
                $sheet['is_all_in'] = ($chips === 0);
                $pot += $actualSb;
            } elseif ($pid === $bbId) {
                $actualBb = min($chips, $bbAmount);
                $chips -= $actualBb;
                $sheet['chips'] = $chips;
                $sheet['current_bet'] = $actualBb;
                $sheet['total_bet'] = $actualBb;
                $sheet['is_all_in'] = ($chips === 0);
                $pot += $actualBb;
            }

            $this->repo->updatePlayerSheet($pid, $sheet, $chips);
            $p['sheet'] = $sheet;
        }
        unset($p);

        // Deck mischen und Karten austeilen
        $deck = PokerDeckFactory::createShuffledDeck();

        foreach ($players as &$p) {
            if (empty($p['sheet']['is_out'])) {
                $hand = [array_shift($deck), array_shift($deck)];
                $sheet = $p['sheet'];
                $sheet['hand'] = $hand;
                $this->repo->updatePlayerSheet((int)$p['id'], $sheet, (int)$sheet['chips']);
                $p['sheet'] = $sheet;
            }
        }
        unset($p);

        $state['deck'] = $deck;
        $state['pot'] = $pot;
        $state['current_highest_bet'] = $bbAmount;
        $state['min_raise'] = $bbAmount;
        $state['phase'] = 'preflop';

        // Pre-Flop startet links vom Big Blind (Under the Gun), bei Heads-up ist es der SB (Dealer)
        $firstToAct = ($nActive === 2) ? $sbId : $this->getNextActivePlayerId($activePlayers, $bbId);
        $state['current_player_id'] = $firstToAct;

        $sbName = $this->getPlayerName($players, $sbId);
        $bbName = $this->getPlayerName($players, $bbId);

        $state['last_event'] = [
            'time' => microtime(true),
            'type' => 'hand_dealt',
            'message' => "Hand #{$state['hand_number']}: Blinds gesetzt ({$sbName} {$sbAmount}, {$bbName} {$bbAmount}).",
            'player_id' => $firstToAct,
        ];

        return ['success' => true];
    }

    /**
     * Führt eine Poker-Aktion eines Spielers aus (fold, check, call, bet, raise, all_in).
     */
    public function takeAction(int $roomId, int $playerId, string $action, int $amount = 0): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || ($room['status'] ?? '') !== 'active') {
            return ['success' => false, 'message' => 'Spiel ist nicht aktiv.'];
        }

        $state = $room['state'];
        if (in_array($state['phase'], ['lobby', 'showdown', 'game_over'], true)) {
            return ['success' => false, 'message' => 'Aktuell kann kein Spielzug ausgeführt werden.'];
        }

        if ((int)$state['current_player_id'] !== $playerId) {
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

        $sheet = &$player['sheet'];
        $myChips = (int)($sheet['chips'] ?? 0);
        $myCurrentBet = (int)($sheet['current_bet'] ?? 0);
        $highestBet = (int)$state['current_highest_bet'];
        $toCall = max(0, $highestBet - $myCurrentBet);

        $actionEventMsg = '';

        switch ($action) {
            case 'fold':
                $sheet['is_folded'] = true;
                $sheet['has_acted'] = true;
                $actionEventMsg = "{$player['player_name']} passt (Fold).";
                break;

            case 'check':
                if ($toCall > 0) {
                    return ['success' => false, 'message' => "Checken nicht möglich. Es muss {$toCall} gecallt werden."];
                }
                $sheet['has_acted'] = true;
                $actionEventMsg = "{$player['player_name']} checkt.";
                break;

            case 'call':
                if ($toCall === 0) {
                    $sheet['has_acted'] = true;
                    $actionEventMsg = "{$player['player_name']} checkt.";
                    break;
                }
                $pay = min($myChips, $toCall);
                $myChips -= $pay;
                $sheet['chips'] = $myChips;
                $sheet['current_bet'] += $pay;
                $sheet['total_bet'] = (int)($sheet['total_bet'] ?? 0) + $pay;
                $state['pot'] += $pay;
                $sheet['has_acted'] = true;
                if ($myChips === 0) {
                    $sheet['is_all_in'] = true;
                    $actionEventMsg = "{$player['player_name']} callt All-In ({$pay} Chips).";
                } else {
                    $actionEventMsg = "{$player['player_name']} geht mit (Call {$pay}).";
                }
                break;

            case 'bet':
            case 'raise':
                // Bet oder Raise: Der Ziel-Einsatz in der aktuellen Wettrunde
                $desiredTotalBet = $amount;
                if ($desiredTotalBet <= $highestBet) {
                    return ['success' => false, 'message' => 'Erhöhung muss über dem aktuellen Höchsteinsatz liegen.'];
                }

                $neededChips = $desiredTotalBet - $myCurrentBet;
                if ($neededChips > $myChips) {
                    // Spieler geht All-In
                    $neededChips = $myChips;
                    $desiredTotalBet = $myCurrentBet + $neededChips;
                }

                $raiseDifference = $desiredTotalBet - $highestBet;
                if ($neededChips < $myChips && $raiseDifference < $state['min_raise']) {
                    return ['success' => false, 'message' => "Minimale Erhöhung beträgt {$state['min_raise']} Chips."];
                }

                $myChips -= $neededChips;
                $sheet['chips'] = $myChips;
                $sheet['current_bet'] = $desiredTotalBet;
                $sheet['total_bet'] = (int)($sheet['total_bet'] ?? 0) + $neededChips;
                $state['pot'] += $neededChips;
                $sheet['has_acted'] = true;

                if ($myChips === 0) {
                    $sheet['is_all_in'] = true;
                }

                // Bei echtem Raise: Andere Spieler müssen erneut agieren!
                if ($desiredTotalBet > $highestBet) {
                    $state['min_raise'] = max((int)$state['big_blind'], $raiseDifference);
                    $state['current_highest_bet'] = $desiredTotalBet;
                    // Alle anderen noch aktiven Spieler zurücksetzen
                    foreach ($players as &$otherP) {
                        if ((int)$otherP['id'] !== $playerId && empty($otherP['sheet']['is_folded']) && empty($otherP['sheet']['is_all_in'])) {
                            $otherP['sheet']['has_acted'] = false;
                            $this->repo->updatePlayerSheet((int)$otherP['id'], $otherP['sheet'], (int)$otherP['sheet']['chips']);
                        }
                    }
                    unset($otherP);
                }

                $actionEventMsg = ($myChips === 0)
                    ? "{$player['player_name']} geht ALL-IN mit {$desiredTotalBet} Chips!"
                    : "{$player['player_name']} erhöht auf {$desiredTotalBet} Chips.";
                break;

            case 'all_in':
                if ($myChips <= 0) {
                    return ['success' => false, 'message' => 'Keine Chips mehr vorhanden.'];
                }
                $pay = $myChips;
                $desiredTotalBet = $myCurrentBet + $pay;
                $myChips = 0;
                $sheet['chips'] = 0;
                $sheet['current_bet'] = $desiredTotalBet;
                $sheet['total_bet'] = (int)($sheet['total_bet'] ?? 0) + $pay;
                $state['pot'] += $pay;
                $sheet['has_acted'] = true;
                $sheet['is_all_in'] = true;

                if ($desiredTotalBet > $highestBet) {
                    $raiseDiff = $desiredTotalBet - $highestBet;
                    $state['min_raise'] = max((int)$state['big_blind'], $raiseDiff);
                    $state['current_highest_bet'] = $desiredTotalBet;

                    foreach ($players as &$otherP) {
                        if ((int)$otherP['id'] !== $playerId && empty($otherP['sheet']['is_folded']) && empty($otherP['sheet']['is_all_in'])) {
                            $otherP['sheet']['has_acted'] = false;
                            $this->repo->updatePlayerSheet((int)$otherP['id'], $otherP['sheet'], (int)$otherP['sheet']['chips']);
                        }
                    }
                    unset($otherP);
                }

                $actionEventMsg = "{$player['player_name']} geht ALL-IN ({$desiredTotalBet} Chips)!";
                break;

            default:
                return ['success' => false, 'message' => 'Unbekannte Aktion.'];
        }

        $this->repo->updatePlayerSheet($playerId, $sheet, (int)$sheet['chips']);

        $state['last_event'] = [
            'time' => microtime(true),
            'type' => 'player_action',
            'action' => $action,
            'message' => $actionEventMsg,
            'player_id' => $playerId,
        ];

        // Prüfen, ob die Wettrunde oder Hand beendet ist
        $this->progressGameAfterAction($roomId, $state, $players);

        $this->repo->updateRoomState($roomId, $state);
        $this->triggerBotTurnIfNeeded($roomId);

        return ['success' => true];
    }

    /**
     * Prüft nach einer Aktion, ob die Runde fortschreitet (nächster Spieler, Flop, Turn, River, Showdown).
     */
    private function progressGameAfterAction(int $roomId, array &$state, array &$players): void
    {
        // 1. Haben alle bis auf einen gefoldet?
        $unfolded = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_folded']) && empty($p['sheet']['is_out'])));
        if (count($unfolded) === 1) {
            $this->concludeHandByFold($state, $players, $unfolded[0]);
            return;
        }

        // 2. Prüfen, ob die aktuelle Setzrunde beendet ist
        // Eine Setzrunde ist beendet, wenn jeder noch handlungsfähige Spieler agiert hat
        // und sein aktueller Einsatz dem Höchsteinsatz entspricht.
        $highestBet = (int)$state['current_highest_bet'];
        $canActPlayers = array_values(array_filter($unfolded, fn($p) => empty($p['sheet']['is_all_in'])));

        $allMatchedAndActed = true;
        foreach ($canActPlayers as $p) {
            if (empty($p['sheet']['has_acted']) || (int)($p['sheet']['current_bet'] ?? 0) !== $highestBet) {
                $allMatchedAndActed = false;
                break;
            }
        }

        // Wenn höchstens 1 Spieler noch Chips hat und alle anderen All-In sind
        if (count($canActPlayers) <= 1 && $allMatchedAndActed) {
            $this->advanceToNextStreetOrShowdown($roomId, $state, $players, true);
            return;
        }

        if ($allMatchedAndActed) {
            // Reguläres Ende der Setzrunde -> Nächste Street
            $this->advanceToNextStreetOrShowdown($roomId, $state, $players, false);
            return;
        }

        // Ansonsten: Nächsten aktiven Spieler bestimmen
        $currId = (int)$state['current_player_id'];
        $nextId = $this->getNextEligiblePlayerId($canActPlayers, $currId);
        $state['current_player_id'] = $nextId;
    }

    /**
     * Bringt das Spiel zur nächsten Street (Flop, Turn, River) oder zum Showdown.
     */
    private function advanceToNextStreetOrShowdown(int $roomId, array &$state, array &$players, bool $fastForwardAllIn = false): void
    {
        // Alle aktuellen Einsätze auf 0 zurücksetzen
        foreach ($players as &$p) {
            $p['sheet']['current_bet'] = 0;
            $p['sheet']['has_acted'] = false;
            $this->repo->updatePlayerSheet((int)$p['id'], $p['sheet'], (int)($p['sheet']['chips'] ?? 0));
        }
        unset($p);

        $state['current_highest_bet'] = 0;
        $state['min_raise'] = (int)$state['big_blind'];

        $deck = &$state['deck'];
        $comm = &$state['community_cards'];
        $phase = $state['phase'];

        if ($phase === 'preflop') {
            // Flop austeilen: 1 Burncard, 3 Community Cards
            array_shift($deck);
            $comm[] = array_shift($deck);
            $comm[] = array_shift($deck);
            $comm[] = array_shift($deck);
            $state['phase'] = 'flop';
            $state['last_event'] = [
                'time' => microtime(true),
                'type' => 'street_dealt',
                'message' => 'Flop aufgedeckt: 3 Karten auf dem Tisch.',
            ];
        } elseif ($phase === 'flop') {
            // Turn austeilen: 1 Burncard, 1 Community Card
            array_shift($deck);
            $comm[] = array_shift($deck);
            $state['phase'] = 'turn';
            $state['last_event'] = [
                'time' => microtime(true),
                'type' => 'street_dealt',
                'message' => 'Turn aufgedeckt: 4. Karte liegt.',
            ];
        } elseif ($phase === 'turn') {
            // River austeilen: 1 Burncard, 1 Community Card
            array_shift($deck);
            $comm[] = array_shift($deck);
            $state['phase'] = 'river';
            $state['last_event'] = [
                'time' => microtime(true),
                'type' => 'street_dealt',
                'message' => 'River aufgedeckt: Alle 5 Board-Karten liegen!',
            ];
        } else {
            // Nach dem River: Showdown!
            $this->concludeHandByShowdown($state, $players);
            return;
        }

        // Falls alle Spieler All-In sind: Direkt weiterschalten bis zum Showdown!
        if ($fastForwardAllIn) {
            $this->advanceToNextStreetOrShowdown($roomId, $state, $players, true);
            return;
        }

        // Nächsten Spieler am Zug bestimmen (links vom Dealer)
        $unfolded = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_folded']) && empty($p['sheet']['is_out'])));
        $canAct = array_values(array_filter($unfolded, fn($p) => empty($p['sheet']['is_all_in'])));
        $dealerId = (int)$state['dealer_id'];
        $nextId = $this->getNextEligiblePlayerId($canAct, $dealerId);
        $state['current_player_id'] = $nextId;
    }

    /**
     * Hand-Ende durch Showdown (Auswertung der besten 5 aus 7 Karten).
     */
    private function concludeHandByShowdown(array &$state, array &$players): void
    {
        $communityCards = $state['community_cards'];
        $unfolded = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_folded']) && empty($p['sheet']['is_out'])));

        $evaluations = [];
        foreach ($unfolded as $p) {
            $pid = (int)$p['id'];
            $all7 = array_merge($p['sheet']['hand'] ?? [], $communityCards);
            $evaluations[$pid] = PokerHandEvaluator::evaluate7Cards($all7);
        }

        $res = PokerPotManager::resolveShowdown($players, $evaluations);

        // Chips an Spieler gutschreiben
        foreach ($players as &$p) {
            $pid = (int)$p['id'];
            $won = $res['payouts'][$pid] ?? 0;
            if ($won > 0) {
                $p['sheet']['chips'] = (int)($p['sheet']['chips'] ?? 0) + $won;
            }
            $this->repo->updatePlayerSheet($pid, $p['sheet'], (int)$p['sheet']['chips']);
        }
        unset($p);

        $state['phase'] = 'showdown';
        $state['round_result'] = [
            'is_game_over' => false,
            'evaluations' => $evaluations,
            'payouts' => $res['payouts'],
            'pot_resolutions' => $res['pot_resolutions'],
        ];

        // Prüfen, ob nach dieser Hand nur noch 1 Spieler Chips hat (Gesamtsieger)
        $survivors = array_values(array_filter($players, fn($p) => (int)($p['sheet']['chips'] ?? 0) > 0));
        $humanSurvivors = array_values(array_filter($survivors, fn($p) => empty($p['sheet']['is_bot'])));

        if (count($survivors) <= 1 || count($humanSurvivors) === 0) {
            $state['round_result']['is_game_over'] = true;
            $winner = $survivors[0] ?? null;
            $state['round_result']['winner_id'] = $winner ? (int)$winner['id'] : null;
            $state['round_result']['winner_name'] = $winner ? (string)$winner['player_name'] : 'Niemand';
        }

        $firstRes = $res['pot_resolutions'][0] ?? null;
        $winnerDesc = $firstRes ? (implode(', ', $firstRes['winner_names']) . " gewinnt mit {$firstRes['hand_desc']}") : 'Showdown beendet';

        $state['last_event'] = [
            'time' => microtime(true),
            'type' => 'showdown',
            'message' => "Showdown! {$winnerDesc}.",
        ];
    }

    /**
     * Hand-Ende, weil alle bis auf einen Spieler gefoldet haben.
     */
    private function concludeHandByFold(array &$state, array &$players, array $soleWinner): void
    {
        $evaluations = [];
        $res = PokerPotManager::resolveShowdown($players, $evaluations);

        $wId = (int)$soleWinner['id'];
        $won = $res['payouts'][$wId] ?? $state['pot'];

        foreach ($players as &$p) {
            $pid = (int)$p['id'];
            if ($pid === $wId) {
                $p['sheet']['chips'] = (int)($p['sheet']['chips'] ?? 0) + $won;
            }
            $this->repo->updatePlayerSheet($pid, $p['sheet'], (int)$p['sheet']['chips']);
        }
        unset($p);

        $state['phase'] = 'showdown';
        $state['round_result'] = [
            'is_game_over' => false,
            'evaluations' => [],
            'payouts' => $res['payouts'],
            'pot_resolutions' => $res['pot_resolutions'],
            'winner_id' => $wId,
            'winner_name' => $soleWinner['player_name'],
            'won_by_fold' => true,
        ];

        $survivors = array_values(array_filter($players, fn($p) => (int)($p['sheet']['chips'] ?? 0) > 0));
        $humanSurvivors = array_values(array_filter($survivors, fn($p) => empty($p['sheet']['is_bot'])));

        if (count($survivors) <= 1 || count($humanSurvivors) === 0) {
            $state['round_result']['is_game_over'] = true;
            $winner = $survivors[0] ?? null;
            $state['round_result']['winner_id'] = $winner ? (int)$winner['id'] : null;
            $state['round_result']['winner_name'] = $winner ? (string)$winner['player_name'] : 'Niemand';
        }

        $state['last_event'] = [
            'time' => microtime(true),
            'type' => 'won_by_fold',
            'message' => "{$soleWinner['player_name']} gewinnt den Pot ({$won} Chips), da alle anderen gepasst haben.",
            'player_id' => $wId,
        ];
    }

    /**
     * Startet die nächste Hand nach einem Showdown.
     */
    public function startNextHand(int $roomId, int $playerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || ($room['status'] ?? '') !== 'active') {
            return ['success' => false, 'message' => 'Spiel nicht aktiv.'];
        }

        $state = $room['state'];
        if ($state['phase'] !== 'showdown') {
            return ['success' => false, 'message' => 'Hand kann aktuell nicht neu gestartet werden.'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $activePlayers = array_values(array_filter($players, fn($p) => (int)($p['sheet']['chips'] ?? 0) > 0));

        if (count($activePlayers) < 2) {
            return ['success' => false, 'message' => 'Nicht genug aktive Spieler mit Chips.'];
        }

        // Dealer wandert reihum
        $currDealerId = (int)($state['dealer_id'] ?? $activePlayers[0]['id']);
        $nextDealerId = $this->getNextActivePlayerId($activePlayers, $currDealerId);

        $res = $this->dealNewHand($roomId, $state, $players, $nextDealerId);
        if (!$res['success']) {
            return $res;
        }

        $this->repo->updateRoomState($roomId, $state);
        $this->triggerBotTurnIfNeeded($roomId);

        return ['success' => true];
    }

    /**
     * Spielabbruch beantragen bzw. bestätigen.
     * Wenn alle aktiven menschlichen Spieler zustimmen, wird das Spiel abgebrochen und in die Lobby zurückgesetzt.
     */
    public function voteAbort(int $roomId, int $playerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || ($room['status'] ?? '') !== 'active') {
            return ['success' => false, 'message' => 'Spiel nicht aktiv.'];
        }

        $state = $room['state'];
        $votes = $state['abort_votes'] ?? [];

        if (!in_array($playerId, $votes, true)) {
            $votes[] = $playerId;
        }
        $state['abort_votes'] = $votes;

        $players = $this->repo->getPlayersForRoom($roomId);
        $humanPlayers = array_values(array_filter($players, fn($p) => empty($p['sheet']['is_bot']) && (bool)$p['is_connected']));

        $allAgreed = true;
        foreach ($humanPlayers as $hp) {
            if (!in_array((int)$hp['id'], $votes, true)) {
                $allAgreed = false;
                break;
            }
        }

        if ($allAgreed && count($humanPlayers) > 0) {
            // Alle haben zugestimmt -> Spiel abbrechen & zurück in die Lobby!
            foreach ($players as &$p) {
                $sheet = $p['sheet'] ?? [];
                $sheet['chips'] = 1000;
                $sheet['hand'] = [];
                $sheet['is_folded'] = false;
                $sheet['is_all_in'] = false;
                $sheet['is_out'] = false;
                $this->repo->updatePlayerSheet((int)$p['id'], $sheet, 1000);
            }
            unset($p);

            $state = $this->getInitialState();
            $state['last_event'] = [
                'time' => microtime(true),
                'type' => 'game_aborted',
                'message' => 'Spiel wurde von allen Spielern einvernehmlich abgebrochen.',
            ];
            $this->repo->updateRoomState($roomId, $state, 'lobby');

            return ['success' => true, 'aborted' => true];
        }

        $state['last_event'] = [
            'time' => microtime(true),
            'type' => 'abort_vote',
            'message' => count($votes) . ' von ' . count($humanPlayers) . ' Spielern haben dem Spielabbruch zugestimmt.',
        ];
        $this->repo->updateRoomState($roomId, $state);

        return ['success' => true, 'aborted' => false, 'votes_count' => count($votes), 'needed_count' => count($humanPlayers)];
    }

    /**
     * Fügt einen KI-Bot hinzu.
     */
    public function addBot(int $roomId, string $botName): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'lobby') {
            return ['success' => false, 'message' => 'Bots können nur in der Lobby hinzugefügt werden.'];
        }

        $existing = $this->repo->getPlayersForRoom($roomId);
        if (count($existing) >= 8) {
            return ['success' => false, 'message' => 'Der Tisch ist bereits mit 8 Spielern voll.'];
        }

        $botTokenHash = hash('sha256', 'poker_bot_' . bin2hex(random_bytes(16)));
        $initialSheet = [
            'chips' => 1000,
            'current_bet' => 0,
            'total_bet' => 0,
            'hand' => [],
            'is_bot' => true,
            'is_folded' => false,
            'is_all_in' => false,
            'is_out' => false,
            'has_acted' => false,
        ];

        $player = $this->repo->joinPlayer($roomId, $botTokenHash, $botName, false, $initialSheet);
        return ['success' => true, 'player' => $player];
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
        if (in_array($state['phase'], ['lobby', 'showdown', 'game_over'], true)) {
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
            return false;
        }

        $sheet = $activePlayer['sheet'];
        $holeCards = $sheet['hand'] ?? [];
        $communityCards = $state['community_cards'] ?? [];
        $highestBet = (int)$state['current_highest_bet'];
        $myCurrentBet = (int)($sheet['current_bet'] ?? 0);
        $toCall = max(0, $highestBet - $myCurrentBet);
        $pot = (int)$state['pot'];
        $myChips = (int)($sheet['chips'] ?? 0);
        $minRaise = (int)$state['min_raise'];
        $phase = (string)$state['phase'];

        $decision = PokerBot::decideAction(
            $holeCards,
            $communityCards,
            $toCall,
            $pot,
            $myChips,
            $minRaise,
            $phase
        );

        $action = $decision['action'];
        $amount = (int)($decision['amount'] ?? 0);

        if ($action === 'bet' || $action === 'raise') {
            $targetBet = $highestBet + max($minRaise, $amount);
            $maxPossibleBet = $myCurrentBet + $myChips;

            if ($targetBet > $maxPossibleBet) {
                $action = 'all_in';
                $amount = $myChips;
            } else {
                $amount = $targetBet;
            }
        } elseif ($action === 'call') {
            $amount = min($myChips, $toCall);
        } elseif ($action === 'all_in') {
            $amount = $myChips;
        } else {
            $amount = 0;
        }

        $res = $this->takeAction($roomId, $activePlayerId, $action, $amount);
        if (empty($res['success'])) {
            // Ausweichaktion, falls Bet/Raise ungültig war
            if ($toCall === 0) {
                $this->takeAction($roomId, $activePlayerId, 'check', 0);
            } else {
                $this->takeAction($roomId, $activePlayerId, 'fold', 0);
            }
        }
        return true;
    }

    /**
     * Bereitet den Spielstatus für die Client-Ausgabe auf (verdeckte Handkarten).
     */
    public function getStateForPlayer(int $roomId, int $forPlayerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return [];
        }

        $state = $room['state'];
        $phase = $state['phase'] ?? 'lobby';
        $isShowdown = ($phase === 'showdown' || $phase === 'game_over');

        $players = $this->repo->getPlayersForRoom($roomId);
        $publicPlayers = [];

        foreach ($players as $p) {
            $pid = (int)$p['id'];
            $isMe = ($pid === $forPlayerId);
            $sheet = $p['sheet'] ?? [];
            $hand = $sheet['hand'] ?? [];

            // Handkarten: eigene Karten immer sichtbar; fremde Karten nur im Showdown (falls nicht gefoldet)
            $visibleHand = [];
            if ($isMe) {
                $visibleHand = $hand;
            } elseif ($isShowdown && empty($sheet['is_folded']) && !empty($hand)) {
                $visibleHand = $hand;
            } else {
                $visibleHand = array_fill(0, count($hand), ['is_hidden' => true]);
            }

            $publicPlayers[] = [
                'id' => $pid,
                'name' => (string)$p['player_name'],
                'is_host' => (bool)$p['is_host'],
                'is_bot' => !empty($sheet['is_bot']),
                'is_connected' => (bool)$p['is_connected'],
                'chips' => (int)($sheet['chips'] ?? 0),
                'current_bet' => (int)($sheet['current_bet'] ?? 0),
                'is_folded' => !empty($sheet['is_folded']),
                'is_all_in' => !empty($sheet['is_all_in']),
                'is_out' => !empty($sheet['is_out']),
                'hand' => $visibleHand,
            ];
        }

        // Eigene Hand-Stärke ausrechnen (falls im Spiel)
        $myScoreInfo = null;
        $myPlayer = null;
        foreach ($players as $p) {
            if ((int)$p['id'] === $forPlayerId) {
                $myPlayer = $p;
                break;
            }
        }

        if ($myPlayer && !empty($myPlayer['sheet']['hand']) && empty($myPlayer['sheet']['is_folded'])) {
            $commCards = $state['community_cards'] ?? [];
            $allCards = array_merge($myPlayer['sheet']['hand'], $commCards);
            if (count($allCards) >= 5) {
                $myScoreInfo = PokerHandEvaluator::evaluate7Cards($allCards);
            }
        }

        // Aktuelle Wettoptionen für den Spieler am Zug
        $mySheet = $myPlayer['sheet'] ?? [];
        $myChips = (int)($mySheet['chips'] ?? 0);
        $myCurrentBet = (int)($mySheet['current_bet'] ?? 0);
        $highestBet = (int)($state['current_highest_bet'] ?? 0);
        $toCall = max(0, $highestBet - $myCurrentBet);
        $minRaise = (int)($state['min_raise'] ?? 20);

        return [
            'room_code' => $room['room_code'],
            'room_status' => $room['status'],
            'phase' => $phase,
            'hand_number' => (int)($state['hand_number'] ?? 0),
            'small_blind' => (int)($state['small_blind'] ?? 10),
            'big_blind' => (int)($state['big_blind'] ?? 20),
            'dealer_id' => $state['dealer_id'] ?? null,
            'sb_player_id' => $state['sb_player_id'] ?? null,
            'bb_player_id' => $state['bb_player_id'] ?? null,
            'current_player_id' => $state['current_player_id'] ?? null,
            'pot' => (int)($state['pot'] ?? 0),
            'current_highest_bet' => $highestBet,
            'community_cards' => $state['community_cards'] ?? [],
            'last_event' => $state['last_event'] ?? null,
            'round_result' => $state['round_result'] ?? null,
            'abort_votes' => $state['abort_votes'] ?? [],
            'players' => $publicPlayers,
            'my_score' => $myScoreInfo,
            'can_check' => ($toCall === 0),
            'call_amount' => min($myChips, $toCall),
            'min_bet_amount' => min($myChips + $myCurrentBet, $highestBet + $minRaise),
            'max_bet_amount' => $myChips + $myCurrentBet,
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

    private function getNextEligiblePlayerId(array $eligiblePlayers, int $currentId): int
    {
        $count = count($eligiblePlayers);
        if ($count === 0) return 0;

        $currentIndex = -1;
        foreach ($eligiblePlayers as $idx => $p) {
            if ((int)$p['id'] === $currentId) {
                $currentIndex = $idx;
                break;
            }
        }

        if ($currentIndex === -1) {
            return (int)$eligiblePlayers[0]['id'];
        }

        $nextIndex = ($currentIndex + 1) % $count;
        return (int)$eligiblePlayers[$nextIndex]['id'];
    }

    private function getPlayerName(array $players, ?int $pid): string
    {
        if ($pid === null) return 'Spieler';
        foreach ($players as $p) {
            if ((int)$p['id'] === $pid) {
                return (string)$p['player_name'];
            }
        }
        return 'Spieler';
    }
}
