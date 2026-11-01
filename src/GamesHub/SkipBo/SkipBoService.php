<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\SkipBo;

use Kai\Tools\GamesHub\RoomRepository;

class SkipBoService
{
    private RoomRepository $repo;

    public function __construct(?RoomRepository $repo = null)
    {
        $this->repo = $repo ?? new RoomRepository();
    }

    /**
     * Erstellt den initialen Zustand für einen neuen Skip-Bo Raum.
     */
    public function getInitialState(string $gameMode = 'standard'): array
    {
        $stockSize = match ($gameMode) {
            'fast' => 10,
            'classic' => 30,
            default => 20,
        };

        return [
            'game_mode' => $gameMode,
            'stock_size' => $stockSize,
            'status' => 'lobby',
            'active_player_id' => 0,
            'active_player_index' => 0,
            'direction' => 1,
            'building_piles' => [
                ['top_value' => 0, 'top_card' => null, 'count' => 0],
                ['top_value' => 0, 'top_card' => null, 'count' => 0],
                ['top_value' => 0, 'top_card' => null, 'count' => 0],
                ['top_value' => 0, 'top_card' => null, 'count' => 0],
            ],
            'draw_pile' => [],
            'completed_piles' => [],
            'winner_id' => null,
            'winner_name' => null,
            'last_event' => [
                'type' => 'room_created',
                'message' => 'Skip-Bo Spielraum eröffnet. Warte auf Mitspieler...',
                'time' => time(),
            ],
        ];
    }

    /**
     * Startet das Spiel:
     * - Erzeugt und mischt das 162-Karten-Deck
     * - Teilt den Zielstapel (Stockpile) an jeden Spieler aus
     * - Teilt die ersten 5 Handkarten an den Startspieler aus
     * - Setzt Status auf 'active'
     */
    public function startGame(int $roomId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        if (count($players) < 2) {
            return ['success' => false, 'message' => 'Mindestens 2 Spieler (oder Bots) erforderlich'];
        }

        $state = $room['state'];
        $stockSize = (int)($state['stock_size'] ?? 20);

        // 1. Deck mit 162 Karten erstellen
        $deckObjects = SkipBoDeckFactory::createDeck();
        $deck = array_map(fn($c) => $c->toArray(), $deckObjects);

        // 2. Zielstapel für alle Spieler austeilen
        foreach ($players as $idx => &$p) {
            $stock = [];
            for ($s = 0; $s < $stockSize; $s++) {
                if (!empty($deck)) {
                    $stock[] = array_pop($deck);
                }
            }

            $p['sheet'] = [
                'stock_pile' => $stock,
                'discard_piles' => [[], [], [], []],
                'hand' => [],
                'is_bot' => (bool)($p['sheet']['is_bot'] ?? false),
            ];
        }
        unset($p);

        // 3. Startspieler festlegen & erste 5 Handkarten ziehen
        $activeIdx = 0;
        $activePlayer = &$players[$activeIdx];
        $initialHand = [];
        for ($h = 0; $h < 5; $h++) {
            if (!empty($deck)) {
                $initialHand[] = array_pop($deck);
            }
        }
        $activePlayer['sheet']['hand'] = $initialHand;

        // DB-Sheets aktualisieren
        foreach ($players as $p) {
            $this->repo->updatePlayerSheet((int)$p['id'], $p['sheet'], 0);
        }

        // 4. Raumzustand aktivieren
        $state['status'] = 'active';
        $state['active_player_id'] = (int)$activePlayer['id'];
        $state['active_player_index'] = $activeIdx;
        $state['draw_pile'] = $deck;
        $state['completed_piles'] = [];
        $state['building_piles'] = [
            ['top_value' => 0, 'top_card' => null, 'count' => 0],
            ['top_value' => 0, 'top_card' => null, 'count' => 0],
            ['top_value' => 0, 'top_card' => null, 'count' => 0],
            ['top_value' => 0, 'top_card' => null, 'count' => 0],
        ];
        $state['last_event'] = [
            'type' => 'game_started',
            'message' => "Das Spiel beginnt! {$activePlayer['player_name']} ist am Zug.",
            'time' => time(),
        ];

        $this->repo->updateRoomState($roomId, $state, 'active');

        // Falls Startspieler ein Bot ist: Botzug anstoßen
        if (!empty($activePlayer['sheet']['is_bot'])) {
            $this->triggerBotMovesIfNeeded($roomId, $state, $players);
        }

        return ['success' => true];
    }

    /**
     * Karte auf einen der 4 Baustapel spielen.
     *
     * @param string $source 'stock' | 'hand' | 'discard'
     * @param string|int $sourceIndex Bei 'hand' die card_id oder int, bei 'discard' der Stapel-Index 0..3
     * @param int $targetPileIndex 0..3 (einer der 4 Baustapel)
     */
    public function playCard(
        int $roomId,
        int $playerId,
        string $source,
        $sourceIndex,
        int $targetPileIndex
    ): array {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'active') {
            return ['success' => false, 'message' => 'Spiel ist nicht aktiv'];
        }

        $state = $room['state'];
        if ((int)$state['active_player_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Du bist nicht am Zug'];
        }

        if ($targetPileIndex < 0 || $targetPileIndex > 3) {
            return ['success' => false, 'message' => 'Ungültiger Baustapel'];
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

        if (!$player) {
            return ['success' => false, 'message' => 'Spieler nicht gefunden'];
        }

        $sheet = $player['sheet'] ?? [];
        $stock = $sheet['stock_pile'] ?? [];
        $hand = $sheet['hand'] ?? [];
        $discardPiles = $sheet['discard_piles'] ?? [[], [], [], []];

        // 1. Karte aus der gewählten Quelle entnehmen
        $cardToPlay = null;
        $sourceDesc = '';

        if ($source === 'stock') {
            if (empty($stock)) {
                return ['success' => false, 'message' => 'Dein Zielstapel ist leer'];
            }
            $cardToPlay = end($stock);
            $sourceDesc = 'vom Zielstapel';
        } elseif ($source === 'hand') {
            $cardFoundIdx = -1;
            foreach ($hand as $hIdx => $hc) {
                if ($hc['id'] === (string)$sourceIndex || $hIdx === (int)$sourceIndex) {
                    $cardFoundIdx = $hIdx;
                    $cardToPlay = $hc;
                    break;
                }
            }
            if ($cardFoundIdx === -1 || !$cardToPlay) {
                return ['success' => false, 'message' => 'Karte nicht auf deiner Hand'];
            }
            $sourceDesc = 'von der Hand';
        } elseif ($source === 'discard') {
            $dIdx = (int)$sourceIndex;
            if ($dIdx < 0 || $dIdx > 3 || empty($discardPiles[$dIdx])) {
                return ['success' => false, 'message' => 'Dieser Hilfsstapel ist leer'];
            }
            $cardToPlay = end($discardPiles[$dIdx]);
            $sourceDesc = "vom Hilfsstapel " . ($dIdx + 1);
        } else {
            return ['success' => false, 'message' => 'Ungültige Kartenquelle'];
        }

        // 2. Regelprüfung: Passt die Karte auf den Baustapel?
        $targetPile = $state['building_piles'][$targetPileIndex] ?? ['top_value' => 0];
        $currentTop = (int)($targetPile['top_value'] ?? 0);
        $needed = $currentTop + 1;

        if ($needed > 12) {
            return ['success' => false, 'message' => 'Dieser Baustapel ist bereits voll'];
        }

        $isSkipBo = ($cardToPlay['type'] ?? '') === SkipBoCard::TYPE_SKIPBO;
        if (!$isSkipBo && (int)($cardToPlay['value'] ?? 0) !== $needed) {
            return [
                'success' => false,
                'message' => "Ungültiger Zug: Auf Baustapel " . ($targetPileIndex + 1) . " wird eine {$needed} benötigt (Karte ist {$cardToPlay['value']}).",
            ];
        }

        // 3. Karte aus der Quelle entfernen
        if ($source === 'stock') {
            array_pop($stock);
            $sheet['stock_pile'] = $stock;
        } elseif ($source === 'hand') {
            array_splice($hand, $cardFoundIdx, 1);
            $sheet['hand'] = $hand;
        } elseif ($source === 'discard') {
            array_pop($discardPiles[$dIdx]);
            $sheet['discard_piles'] = $discardPiles;
        }

        // 4. Karte auf den Baustapel legen
        $playedCardData = $cardToPlay;
        $playedCardData['played_as'] = $needed;

        $targetPile['top_value'] = $needed;
        $targetPile['top_card'] = $playedCardData;
        $targetPile['count'] = ((int)($targetPile['count'] ?? 0)) + 1;

        $clearedNotice = '';

        // 5. Hat der Baustapel 12 erreicht? -> Vollendet & Abräumen!
        if ($needed === 12) {
            $state['completed_piles'][] = [
                'cleared_at' => time(),
                'pile_index' => $targetPileIndex,
            ];
            // Stapel leeren und für eine neue 1 freigeben
            $targetPile = [
                'top_value' => 0,
                'top_card' => null,
                'count' => 0,
                'just_cleared' => true,
            ];
            $clearedNotice = " 🌟 Baustapel " . ($targetPileIndex + 1) . " (1-12) wurde vollendet und geräumt!";
        }

        $state['building_piles'][$targetPileIndex] = $targetPile;

        // 6. SIEGBEDINGUNG: Zielstapel geleert?
        if (count($stock) === 0) {
            $state['status'] = 'finished';
            $state['winner_id'] = $playerId;
            $state['winner_name'] = $player['player_name'];
            $state['last_event'] = [
                'type' => 'game_won',
                'message' => "🏆 {$player['player_name']} hat den Zielstapel komplett geleert und gewinnt Skip-Bo!",
                'time' => time(),
            ];

            $this->repo->updatePlayerSheet($playerId, $sheet, 0);
            $this->repo->updateRoomState($roomId, $state, 'finished');

            return [
                'success' => true,
                'game_finished' => true,
                'winner' => $player['player_name'],
            ];
        }

        // 7. SONDERREGEL: Handkarten komplett ausgespielt?
        // Wer während des Zuges alle 5 Handkarten ausspielt, zieht SOFORT 5 neue!
        $bonusDrawnNotice = '';
        if (count($sheet['hand']) === 0) {
            $refillCards = $this->drawCardsFromDeck($state, 5);
            $sheet['hand'] = $refillCards;
            $bonusDrawnNotice = " 🎁 Hand komplett gelegt: 5 neue Karten nachgezogen!";
        }

        // Spieler-Sheet und Raumzustand speichern
        $cardLabel = $isSkipBo ? "Skip-Bo (als {$needed})" : (string)$cardToPlay['value'];
        $state['last_event'] = [
            'type' => 'card_played',
            'message' => "{$player['player_name']} legte {$cardLabel} {$sourceDesc} auf Baustapel " . ($targetPileIndex + 1) . ".{$clearedNotice}{$bonusDrawnNotice}",
            'time' => time(),
        ];

        $players[$playerIdx]['sheet'] = $sheet;
        $this->repo->updatePlayerSheet($playerId, $sheet, 0);
        $this->repo->updateRoomState($roomId, $state);

        // Falls aktiver Spieler ein Bot ist: nächsten Bot-Schritt anstoßen
        if (!empty($sheet['is_bot'])) {
            $this->triggerBotMovesIfNeeded($roomId, $state, $players);
        }

        return ['success' => true];
    }

    /**
     * Handkarte auf einen Hilfsstapel (Discard Pile) abwerfen -> beendet den eigenen Zug.
     *
     * @param int $targetDiscardIndex 0..3
     */
    public function discardCard(
        int $roomId,
        int $playerId,
        string $cardId,
        int $targetDiscardIndex
    ): array {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'active') {
            return ['success' => false, 'message' => 'Spiel ist nicht aktiv'];
        }

        $state = $room['state'];
        if ((int)$state['active_player_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Du bist nicht am Zug'];
        }

        if ($targetDiscardIndex < 0 || $targetDiscardIndex > 3) {
            return ['success' => false, 'message' => 'Ungültiger Hilfsstapel (muss 1 bis 4 sein)'];
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

        if (!$player) {
            return ['success' => false, 'message' => 'Spieler nicht gefunden'];
        }

        $sheet = $player['sheet'] ?? [];
        $hand = $sheet['hand'] ?? [];
        $discardPiles = $sheet['discard_piles'] ?? [[], [], [], []];

        // Karte auf der Hand suchen
        $cardFoundIdx = -1;
        $cardToDiscard = null;
        foreach ($hand as $hIdx => $hc) {
            if ($hc['id'] === $cardId) {
                $cardFoundIdx = $hIdx;
                $cardToDiscard = $hc;
                break;
            }
        }

        if ($cardFoundIdx === -1 || !$cardToDiscard) {
            return ['success' => false, 'message' => 'Diese Karte befindet sich nicht auf deiner Hand'];
        }

        // Karte aus Hand entfernen und auf den Hilfsstapel legen
        array_splice($hand, $cardFoundIdx, 1);
        $sheet['hand'] = $hand;
        $discardPiles[$targetDiscardIndex][] = $cardToDiscard;
        $sheet['discard_piles'] = $discardPiles;

        // Spieler-Sheet für aktuellen Spieler speichern
        $players[$playerIdx]['sheet'] = $sheet;
        $this->repo->updatePlayerSheet($playerId, $sheet, 0);

        // ---------------------------------------------------------------------
        // ZUGWECHSEL: Nächster Spieler ist an der Reihe
        // ---------------------------------------------------------------------
        $playerCount = count($players);
        $nextIdx = ($playerIdx + 1) % $playerCount;
        $nextPlayer = &$players[$nextIdx];

        $nextSheet = $nextPlayer['sheet'] ?? [];
        $nextHand = $nextSheet['hand'] ?? [];

        // Handkarten des Folgespielers auf genau 5 auffüllen
        $neededCards = max(0, 5 - count($nextHand));
        if ($neededCards > 0) {
            $drawn = $this->drawCardsFromDeck($state, $neededCards);
            $nextHand = array_merge($nextHand, $drawn);
            $nextSheet['hand'] = $nextHand;
            $nextPlayer['sheet'] = $nextSheet;
            $this->repo->updatePlayerSheet((int)$nextPlayer['id'], $nextSheet, 0);
        }

        $cardLabel = ($cardToDiscard['type'] ?? '') === SkipBoCard::TYPE_SKIPBO ? 'Skip-Bo' : (string)$cardToDiscard['value'];
        $state['active_player_id'] = (int)$nextPlayer['id'];
        $state['active_player_index'] = $nextIdx;
        $state['last_event'] = [
            'type' => 'turn_ended',
            'message' => "{$player['player_name']} warf {$cardLabel} auf Hilfsstapel " . ($targetDiscardIndex + 1) . " ab. {$nextPlayer['player_name']} ist jetzt am Zug.",
            'time' => time(),
        ];

        $this->repo->updateRoomState($roomId, $state);

        // Falls nächster Spieler ein Bot ist: Botzüge starten
        if (!empty($nextSheet['is_bot'])) {
            $this->triggerBotMovesIfNeeded($roomId, $state, $players);
        }

        return ['success' => true];
    }

    /**
     * Zieht Karten vom Nachziehstapel (mit automatischem Nachmischen abgeräumter 12er-Stapel).
     */
    private function drawCardsFromDeck(array &$roomState, int $count): array
    {
        $deck = $roomState['draw_pile'] ?? [];
        $drawn = [];

        for ($i = 0; $i < $count; $i++) {
            if (empty($deck)) {
                // Abgeräumte 12er-Stapel neu mischen
                $completed = $roomState['completed_piles'] ?? [];
                if (!empty($completed)) {
                    // Erzeuge frischen Kartennachschub aus den abgeschlossenen Stapeln
                    $newDeckCards = [];
                    foreach ($completed as $c) {
                        for ($num = 1; $num <= 12; $num++) {
                            $color = $num <= 4 ? 'blue' : ($num <= 8 ? 'green' : 'orange');
                            $id = sprintf('sb_reshuffle_%02d_%s', $num, bin2hex(random_bytes(2)));
                            $newDeckCards[] = (new SkipBoCard($id, SkipBoCard::TYPE_NUMBER, $num, $color))->toArray();
                        }
                    }
                    SkipBoDeckFactory::shuffleDeck($newDeckCards);
                    $deck = $newDeckCards;
                    $roomState['completed_piles'] = [];
                }
            }

            if (!empty($deck)) {
                $drawn[] = array_pop($deck);
            }
        }

        $roomState['draw_pile'] = $deck;
        return $drawn;
    }

    /**
     * Führt Bot-Züge autonom und sicher aus.
     */
    private function triggerBotMovesIfNeeded(int $roomId, array &$roomState, array &$players, int $depth = 0): void
    {
        if ($depth > 25) {
            return; // Schutz vor Endlosschleifen
        }

        if (($roomState['status'] ?? '') !== 'active') {
            return;
        }

        $activeId = (int)($roomState['active_player_id'] ?? 0);
        $activePlayer = null;
        $activeIdx = -1;

        foreach ($players as $idx => $p) {
            if ((int)$p['id'] === $activeId) {
                $activePlayer = $p;
                $activeIdx = $idx;
                break;
            }
        }

        if (!$activePlayer || empty($activePlayer['sheet']['is_bot'])) {
            return; // Menschlicher Spieler am Zug
        }

        $sheet = $activePlayer['sheet'];
        $hand = $sheet['hand'] ?? [];
        $stock = $sheet['stock_pile'] ?? [];
        $stockTop = !empty($stock) ? end($stock) : null;
        $discardPiles = $sheet['discard_piles'] ?? [[], [], [], []];
        $buildingPiles = $roomState['building_piles'] ?? [];

        // Bot Entscheidung anfordern
        $decision = SkipBoBot::decideNextMove($hand, $stockTop, $discardPiles, $buildingPiles);

        if ($decision['action'] === 'play') {
            $src = $decision['source'];
            $srcIdx = $src === 'hand'
                ? ($hand[$decision['source_index']]['id'] ?? $decision['source_index'])
                : $decision['source_index'];

            $res = $this->playCard($roomId, $activeId, $src, $srcIdx, (int)$decision['target_pile']);

            if (!empty($res['success']) && empty($res['game_finished'])) {
                // Frisch laden für nächsten Schritt
                $room = $this->repo->getRoomById($roomId);
                if ($room) {
                    $roomState = $room['state'];
                    $players = $this->repo->getPlayersForRoom($roomId);
                    $this->triggerBotMovesIfNeeded($roomId, $roomState, $players, $depth + 1);
                }
            }
        } elseif ($decision['action'] === 'discard') {
            $this->discardCard($roomId, $activeId, (string)$decision['card_id'], (int)$decision['target_discard_index']);
        }
    }

    /**
     * Fügt dem Raum in der Lobby einen KI-Bot hinzu.
     */
    public function addBot(int $roomId, string $botName): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'lobby') {
            return ['success' => false, 'message' => 'Bots können nur in der Lobby hinzugefügt werden'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        if (count($players) >= 6) {
            return ['success' => false, 'message' => 'Tisch ist voll (maximal 6 Spieler)'];
        }

        $botTokenHash = hash('sha256', 'bot_' . bin2hex(random_bytes(16)));
        $botSheet = [
            'stock_pile' => [],
            'discard_piles' => [[], [], [], []],
            'hand' => [],
            'is_bot' => true,
        ];

        $player = $this->repo->joinPlayer($roomId, $botTokenHash, $botName, false, $botSheet);

        return [
            'success' => true,
            'player' => [
                'id' => $player['id'],
                'player_name' => $botName,
                'is_bot' => true,
            ],
        ];
    }

    /**
     * ZERO-KNOWLEDGE PROJEKTION:
     * Bereitet den Spielzustand sicher für den anfragenden Client auf.
     * Gesichtete gegnerische Handkarten und verdeckte Zielstapelkarten bleiben verborgen!
     */
    public function getPublicStateForPlayer(array $room, array $myPlayer, array $players): array
    {
        $roomState = $room['state'] ?? [];
        $activePlayerId = (int)($roomState['active_player_id'] ?? 0);
        $buildingPiles = $roomState['building_piles'] ?? [];

        // Prüfen, welche Kartenwerte auf welche Baustapel passen
        $neededValues = [];
        foreach ($buildingPiles as $pIdx => $bp) {
            $topVal = (int)($bp['top_value'] ?? 0);
            $neededValues[$pIdx] = $topVal + 1; // 1 bis 12
        }

        $publicPlayers = [];

        foreach ($players as $p) {
            $isMe = ((int)$p['id'] === (int)$myPlayer['id']);
            $sheet = $p['sheet'] ?? [];
            $stock = $sheet['stock_pile'] ?? [];
            $hand = $sheet['hand'] ?? [];
            $discardPiles = $sheet['discard_piles'] ?? [[], [], [], []];

            $stockTopCard = null;
            if (!empty($stock)) {
                $rawTop = end($stock);
                $stockTopCard = [
                    'id' => $rawTop['id'],
                    'type' => $rawTop['type'],
                    'value' => $rawTop['value'],
                    'color' => $rawTop['color'],
                    'label' => $rawTop['label'],
                ];

                if ($isMe) {
                    $stockTopCard['can_play_to'] = $this->computePlayablePiles($rawTop, $neededValues);
                }
            }

            // Hilfsstapel (oberste Karten sind für alle sichtbar)
            $publicDiscard = [];
            for ($d = 0; $d < 4; $d++) {
                $pile = $discardPiles[$d] ?? [];
                $topD = !empty($pile) ? end($pile) : null;
                $topFormatted = null;

                if ($topD) {
                    $topFormatted = [
                        'id' => $topD['id'],
                        'type' => $topD['type'],
                        'value' => $topD['value'],
                        'color' => $topD['color'],
                        'label' => $topD['label'],
                    ];
                    if ($isMe) {
                        $topFormatted['can_play_to'] = $this->computePlayablePiles($topD, $neededValues);
                    }
                }

                $publicDiscard[] = [
                    'count' => count($pile),
                    'top_card' => $topFormatted,
                ];
            }

            // Handkarten: NUR für mich selbst detailliert auflisten!
            $myHandCards = [];
            if ($isMe) {
                foreach ($hand as $hc) {
                    $cFormatted = [
                        'id' => $hc['id'],
                        'type' => $hc['type'],
                        'value' => $hc['value'],
                        'color' => $hc['color'],
                        'label' => $hc['label'],
                        'can_play_to' => $this->computePlayablePiles($hc, $neededValues),
                    ];
                    $myHandCards[] = $cFormatted;
                }
            }

            $publicPlayers[] = [
                'id' => (int)$p['id'],
                'player_name' => $p['player_name'],
                'is_host' => (bool)$p['is_host'],
                'is_bot' => (bool)($sheet['is_bot'] ?? false),
                'is_active' => ((int)$p['id'] === $activePlayerId),
                'is_me' => $isMe,
                'stock_count' => count($stock),
                'stock_top_card' => $stockTopCard,
                'discard_piles' => $publicDiscard,
                'hand_count' => count($hand),
                'hand' => $isMe ? $myHandCards : null,
            ];
        }

        // Öffentliche Baustapel
        $publicBuilding = [];
        foreach ($buildingPiles as $idx => $bp) {
            $publicBuilding[] = [
                'index' => $idx,
                'top_value' => (int)($bp['top_value'] ?? 0),
                'needed_value' => (int)($bp['top_value'] ?? 0) + 1,
                'top_card' => $bp['top_card'] ?? null,
                'count' => (int)($bp['count'] ?? 0),
            ];
        }

        return [
            'room_code' => $room['room_code'],
            'status' => $roomState['status'] ?? 'lobby',
            'game_mode' => $roomState['game_mode'] ?? 'standard',
            'stock_size' => (int)($roomState['stock_size'] ?? 20),
            'active_player_id' => $activePlayerId,
            'is_my_turn' => ($activePlayerId === (int)$myPlayer['id']),
            'building_piles' => $publicBuilding,
            'draw_pile_count' => count($roomState['draw_pile'] ?? []),
            'players' => $publicPlayers,
            'winner_id' => $roomState['winner_id'] ?? null,
            'winner_name' => $roomState['winner_name'] ?? null,
            'last_event' => $roomState['last_event'] ?? null,
        ];
    }

    /**
     * Ermittelt, auf welche Baustapel (0..3) eine gegebene Karte legal gelegt werden kann.
     */
    private function computePlayablePiles(array $card, array $neededValues): array
    {
        $playable = [];
        $isSkipBo = ($card['type'] ?? '') === SkipBoCard::TYPE_SKIPBO;
        $val = (int)($card['value'] ?? 0);

        foreach ($neededValues as $pileIdx => $needed) {
            if ($needed > 12) continue;
            if ($isSkipBo || $val === $needed) {
                $playable[] = $pileIdx;
            }
        }

        return $playable;
    }
}
