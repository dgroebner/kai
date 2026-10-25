<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\CardGames;

use Kai\Tools\GamesHub\CardGames\Engine\CardBotService;
use Kai\Tools\GamesHub\CardGames\Engine\DeckFactory;
use Kai\Tools\GamesHub\CardGames\Engine\ExtremeLauncherEngine;
use Kai\Tools\GamesHub\CardGames\Rules\MauMauRules;
use Kai\Tools\GamesHub\CardGames\Rules\RuleEngineInterface;
use Kai\Tools\GamesHub\CardGames\Rules\UnoExtremeRules;
use Kai\Tools\GamesHub\CardGames\Rules\UnoRules;
use Kai\Tools\GamesHub\RoomRepository;

class CardGameService
{
    private RoomRepository $repo;

    public function __construct(?RoomRepository $repo = null)
    {
        $this->repo = $repo ?? new RoomRepository();
    }

    public function getRuleEngine(string $gameMode): RuleEngineInterface
    {
        return match ($gameMode) {
            'mau_mau' => new MauMauRules(),
            'uno_extreme' => new UnoExtremeRules(),
            default => new UnoRules(),
        };
    }

    /**
     * Erstellt den initialen Zustand für einen neuen Raum in der Lobby-Phase.
     */
    public function getInitialState(string $gameMode): array
    {
        return [
            'game_mode' => $gameMode,
            'status' => 'lobby',
            'top_card' => null,
            'active_color' => null,
            'direction' => 1,
            'active_player_id' => 0,
            'active_player_index' => 0,
            'draw_pile' => [],
            'discard_pile' => [],
            'pending_draws' => 0,
            'launcher_state' => [
                'status' => 'idle', // idle, firing, dispensed
                'cards_out' => 0,
                'hits_left' => 0,
            ],
            'winner_id' => null,
            'last_event' => [
                'type' => 'room_created',
                'message' => 'Raum eröffnet. Warte auf Mitspieler...',
                'time' => time(),
            ],
        ];
    }

    /**
     * Startet das Spiel: Mischen, Karten an Spieler verteilen, Top-Karte aufdecken.
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

        $gameMode = $room['game_mode'] ?? 'uno';
        $ruleEngine = $this->getRuleEngine($gameMode);
        $handSize = $ruleEngine->getInitialHandSize();

        // 1. Neues Deck erzeugen und mischen
        $deck = DeckFactory::createDeck($gameMode);
        $deckArray = array_map(fn($c) => $c->toArray(), $deck);

        // 2. Handkarten an jeden Spieler austeilen
        foreach ($players as &$p) {
            $hand = [];
            for ($h = 0; $h < $handSize; $h++) {
                if (!empty($deckArray)) {
                    $hand[] = array_pop($deckArray);
                }
            }
            $p['sheet'] = [
                'cards' => $hand,
                'has_called_uno' => false,
                'is_bot' => (bool)($p['sheet']['is_bot'] ?? false),
            ];
            $this->repo->updatePlayerSheet((int)$p['id'], $p['sheet'], 0);
        }

        // 3. Erste Karte für den Ablagestapel aufdecken (keine Wild/Sonderkarten als Startkarte)
        $topCard = null;
        $discardPile = [];

        while (!empty($deckArray)) {
            $candidate = array_pop($deckArray);
            if ($candidate['color'] !== 'wild' && $candidate['type'] === 'number') {
                $topCard = $candidate;
                $discardPile[] = $topCard;
                break;
            }
            // Sonderkarte hinten wieder einstecken
            array_unshift($deckArray, $candidate);
        }

        if (!$topCard && !empty($deckArray)) {
            $topCard = array_pop($deckArray);
            $discardPile[] = $topCard;
        }

        $startPlayer = $players[0];

        $state = [
            'game_mode' => $gameMode,
            'top_card' => $topCard,
            'active_color' => null,
            'direction' => 1,
            'active_player_id' => (int)$startPlayer['id'],
            'active_player_index' => 0,
            'draw_pile' => $deckArray,
            'discard_pile' => $discardPile,
            'pending_draws' => 0,
            'launcher_state' => [
                'status' => 'idle',
                'cards_out' => 0,
                'hits_left' => 0,
            ],
            'winner_id' => null,
            'last_event' => [
                'type' => 'game_started',
                'message' => "Spiel gestartet! {$startPlayer['player_name']} beginnt.",
                'time' => time(),
            ],
        ];

        $this->repo->updateRoomState($roomId, $state, 'active');

        // Falls Startspieler ein Bot ist: Bot-Zug ausführen
        $this->triggerBotMovesIfNeeded($roomId, $state, $players);

        return ['success' => true];
    }

    /**
     * Spieler legt eine Karte ab.
     */
    public function playCard(
        int $roomId,
        int $playerId,
        string $cardId,
        ?string $chosenColor = null,
        bool $calledUno = false
    ): array {
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

        $hand = $player['sheet']['cards'] ?? [];
        $cardIdx = -1;
        $playedCard = null;

        foreach ($hand as $i => $c) {
            if ($c['id'] === $cardId) {
                $cardIdx = $i;
                $playedCard = $c;
                break;
            }
        }

        if ($cardIdx === -1 || !$playedCard) {
            return ['success' => false, 'message' => 'Karte nicht auf der Hand'];
        }

        $ruleEngine = $this->getRuleEngine($room['game_mode'] ?? 'uno');
        $topCard = $state['top_card'];
        $activeColor = $state['active_color'];
        $pendingDraws = (int)($state['pending_draws'] ?? 0);

        if (!$ruleEngine->canPlay($playedCard, $topCard, $activeColor, $pendingDraws, $hand)) {
            return ['success' => false, 'message' => 'Diese Karte darf nicht gelegt werden'];
        }

        // Karte aus der Hand entfernen
        array_splice($hand, $cardIdx, 1);
        $player['sheet']['cards'] = $hand;
        $players[$playerIdx]['sheet']['cards'] = $hand;

        // Uno-Ansage vermerken
        if (count($hand) === 1 && $calledUno) {
            $player['sheet']['has_called_uno'] = true;
            $players[$playerIdx]['sheet']['has_called_uno'] = true;
        } else {
            $player['sheet']['has_called_uno'] = false;
            $players[$playerIdx]['sheet']['has_called_uno'] = false;
        }

        // Karte auf den Ablagestapel legen
        $state['discard_pile'][] = $playedCard;
        $state['top_card'] = $playedCard;

        // Hat der Spieler gewonnen?
        if (count($hand) === 0) {
            $state['winner_id'] = $playerId;
            $state['last_event'] = [
                'type' => 'game_won',
                'message' => "🎉 {$player['player_name']} hat alle Karten abgelegt und gewinnt das Spiel!",
                'time' => time(),
            ];
            $this->repo->updatePlayerSheet($playerId, $player['sheet'], 0);
            $this->repo->updateRoomState($roomId, $state, 'finished');
            return ['success' => true, 'game_finished' => true];
        }

        // Karteneffekte anwenden
        $result = $ruleEngine->applyCard($playedCard, $state, $players, $playerIdx, $chosenColor);

        // Nächsten Spieler setzen
        $nextPlayerIdx = $result['next_player_index'];
        $nextPlayer = $players[$nextPlayerIdx];
        $state['active_player_index'] = $nextPlayerIdx;
        $state['active_player_id'] = (int)$nextPlayer['id'];

        $unoNotice = (count($hand) === 1 && $calledUno) ? ' und ruft UNO!' : '';
        $state['last_event'] = [
            'type' => 'card_played',
            'message' => "{$player['player_name']} legte {$playedCard['label']}{$unoNotice}. {$result['message']}",
            'time' => time(),
        ];

        // Hand aktualisieren
        $this->repo->updatePlayerSheet($playerId, $player['sheet'], 0);
        $this->repo->updateRoomState($roomId, $state);

        // Falls Folgespieler ein Bot ist: Bot-Züge ausführen
        $this->triggerBotMovesIfNeeded($roomId, $state, $players);

        return ['success' => true];
    }

    /**
     * Spieler zieht Karte(n) oder betätigt den Uno Extreme Launcher.
     */
    public function drawOrLaunch(int $roomId, int $playerId): array
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

        $gameMode = $room['game_mode'] ?? 'uno';
        $drawPile = &$state['draw_pile'];
        $discardPile = &$state['discard_pile'];
        $pendingDraws = (int)($state['pending_draws'] ?? 0);
        $hand = $player['sheet']['cards'] ?? [];

        $drawnCards = [];
        $message = '';

        if ($gameMode === 'uno_extreme') {
            // Uno Extreme Launcher betätigen!
            $hitCount = max(1, $pendingDraws);
            $dispenseCount = ExtremeLauncherEngine::rollDispenseCount($hitCount);
            $state['pending_draws'] = 0;

            if ($dispenseCount > 0) {
                $drawnCards = ExtremeLauncherEngine::drawCards($dispenseCount, $drawPile, $discardPile);
                $hand = array_merge($hand, $drawnCards);
                $message = "💥 Der Launcher hat {$dispenseCount} Karten für {$player['player_name']} ausgespuckt!";
            } else {
                $message = "✨ Glück gehabt! Der Launcher blieb stumm. Keine Karten für {$player['player_name']}.";
            }

            $state['launcher_state'] = [
                'status' => 'dispensed',
                'cards_out' => $dispenseCount,
                'hits_left' => 0,
            ];
        } else {
            // Klassisches Ziehen (Uno oder Mau-Mau)
            $drawCount = max(1, $pendingDraws);
            $state['pending_draws'] = 0;

            $drawnCards = ExtremeLauncherEngine::drawCards($drawCount, $drawPile, $discardPile);
            $hand = array_merge($hand, $drawnCards);
            $message = "{$player['player_name']} zog " . ($drawCount === 1 ? "1 Karte." : "{$drawCount} Strafkarten.");
        }

        $player['sheet']['cards'] = $hand;
        $player['sheet']['has_called_uno'] = false;
        $players[$playerIdx]['sheet']['cards'] = $hand;

        // Turn wechselt zum nächsten Spieler
        $playerCount = count($players);
        $direction = (int)($state['direction'] ?? 1);
        $nextPlayerIdx = ($playerIdx + $direction) % $playerCount;
        if ($nextPlayerIdx < 0) {
            $nextPlayerIdx += $playerCount;
        }

        $nextPlayer = $players[$nextPlayerIdx];
        $state['active_player_index'] = $nextPlayerIdx;
        $state['active_player_id'] = (int)$nextPlayer['id'];

        $state['last_event'] = [
            'type' => 'cards_drawn',
            'message' => $message,
            'time' => time(),
        ];

        $this->repo->updatePlayerSheet($playerId, $player['sheet'], 0);
        $this->repo->updateRoomState($roomId, $state);

        // Bot-Züge prüfen
        $this->triggerBotMovesIfNeeded($roomId, $state, $players);

        return ['success' => true, 'drawn_count' => count($drawnCards)];
    }

    /**
     * Manueller "UNO!" / "MAU!" Button-Druck eines Spielers.
     */
    public function callUno(int $roomId, int $playerId): array
    {
        $players = $this->repo->getPlayersForRoom($roomId);
        foreach ($players as $p) {
            if ((int)$p['id'] === $playerId) {
                $sheet = $p['sheet'];
                $sheet['has_called_uno'] = true;
                $this->repo->updatePlayerSheet($playerId, $sheet, 0);

                $room = $this->repo->getRoomById($roomId);
                if ($room) {
                    $state = $room['state'];
                    $callWord = ($room['game_mode'] === 'mau_mau') ? 'MAU!' : 'UNO!';
                    $state['last_event'] = [
                        'type' => 'uno_called',
                        'message' => "📢 {$p['player_name']} ruft laut {$callWord}",
                        'time' => time(),
                    ];
                    $this->repo->updateRoomState($roomId, $state);
                }
                return ['success' => true];
            }
        }
        return ['success' => false, 'message' => 'Spieler nicht gefunden'];
    }

    /**
     * Ein Spieler erwischt einen Mitspieler, der nur noch 1 Karte hat und nicht MAU/UNO gesagt hat.
     */
    public function catchUnoMiss(int $roomId, int $callerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'active') {
            return ['success' => false, 'message' => 'Spiel ist nicht aktiv'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        $caller = null;
        foreach ($players as $p) {
            if ((int)$p['id'] === $callerId) {
                $caller = $p;
                break;
            }
        }

        $state = $room['state'];
        $drawPile = &$state['draw_pile'];
        $discardPile = &$state['discard_pile'];
        $callWord = ($room['game_mode'] === 'mau_mau') ? 'MAU' : 'UNO';

        // Suchen nach einem Mitspieler mit 1 Karte, der nicht gerufen hat
        foreach ($players as $target) {
            if ((int)$target['id'] === $callerId) {
                continue;
            }

            $hand = $target['sheet']['cards'] ?? [];
            $hasCalled = !empty($target['sheet']['has_called_uno']);

            if (count($hand) === 1 && !$hasCalled) {
                $penaltyCards = ExtremeLauncherEngine::drawCards(2, $drawPile, $discardPile);
                $target['sheet']['cards'] = array_merge($hand, $penaltyCards);
                $target['sheet']['has_called_uno'] = true;

                $this->repo->updatePlayerSheet((int)$target['id'], $target['sheet'], 0);

                $callerName = $caller ? $caller['player_name'] : 'Ein Mitspieler';
                $state['last_event'] = [
                    'type' => 'uno_caught',
                    'message' => "🚨 Erwischt! {$callerName} hat bemerkt, dass {$target['player_name']} kein {$callWord} gerufen hat! (+2 Strafkarten)",
                    'time' => time(),
                ];
                $this->repo->updateRoomState($roomId, $state);

                return [
                    'success' => true,
                    'caught' => true,
                    'target_name' => $target['player_name'],
                ];
            }
        }

        return ['success' => false, 'message' => "Niemand hat vergessen {$callWord} zu rufen."];
    }

    /**
     * Fügt einen KI-Bot zum Raum hinzu.
     */
    public function addBot(int $roomId, string $botName = 'Bot'): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room || $room['status'] !== 'lobby') {
            return ['success' => false, 'message' => 'Bots können nur in der Lobby hinzugefügt werden'];
        }

        $players = $this->repo->getPlayersForRoom($roomId);
        if (count($players) >= 8) {
            return ['success' => false, 'message' => 'Maximal 8 Spieler/Bots erlaubt'];
        }

        $botTokenHash = hash('sha256', bin2hex(random_bytes(16)));
        $initialSheet = [
            'cards' => [],
            'has_called_uno' => false,
            'is_bot' => true,
        ];

        $player = $this->repo->joinPlayer($roomId, $botTokenHash, $botName, false, $initialSheet);
        return ['success' => true, 'bot_id' => $player['id'], 'bot_name' => $botName];
    }

    /**
     * Prüft, ob der aktive Spieler ein Bot ist, und führt dessen Züge automatisch aus.
     */
    public function triggerBotMovesIfNeeded(int $roomId, array &$roomState, array &$players, int $maxSteps = 5): void
    {
        if ($roomState['status'] ?? '' === 'finished' || empty($players)) {
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
            return; // Menschlicher Spieler am Zug
        }

        if ($maxSteps <= 0) {
            return; // Schutz vor Endlosschleifen
        }

        $gameMode = $roomState['game_mode'] ?? 'uno';
        $ruleEngine = $this->getRuleEngine($gameMode);
        $botHand = $activePlayer['sheet']['cards'] ?? [];

        $decision = CardBotService::decideMove(
            $botHand,
            $roomState['top_card'],
            $roomState['active_color'],
            (int)($roomState['pending_draws'] ?? 0),
            $ruleEngine
        );

        if ($decision['action'] === 'play') {
            $callUno = (count($botHand) === 2); // Wenn er gleich nur noch 1 Karte hat
            $this->playCard($roomId, $activePlayerId, $decision['card_id'], $decision['chosen_color'], $callUno);
        } else {
            $this->drawOrLaunch($roomId, $activePlayerId);
        }
    }

    /**
     * ZERO-KNOWLEDGE PROJEKTION:
     * Erstellt die sichere Sicht auf den Spielzustand für einen anfragenden Spieler.
     */
    public function getPublicStateForPlayer(array $room, array $myPlayer, array $players): array
    {
        $roomState = $room['state'];
        $gameMode = $room['game_mode'] ?? 'uno';
        $ruleEngine = $this->getRuleEngine($gameMode);

        $activePlayerId = (int)($roomState['active_player_id'] ?? 0);
        $topCard = $roomState['top_card'];
        $activeColor = $roomState['active_color'];
        $pendingDraws = (int)($roomState['pending_draws'] ?? 0);

        $publicPlayers = [];

        foreach ($players as $p) {
            $isMe = ((int)$p['id'] === (int)$myPlayer['id']);
            $hand = $p['sheet']['cards'] ?? [];

            // Nur für mich selbst annotieren wir die Karten mit "can_play"
            $annotatedCards = null;
            if ($isMe) {
                $annotatedCards = [];
                $isMyTurn = ((int)$p['id'] === $activePlayerId);

                foreach ($hand as $card) {
                    $canPlay = $isMyTurn && $topCard && $ruleEngine->canPlay($card, $topCard, $activeColor, $pendingDraws, $hand);
                    $annotatedCards[] = array_merge($card, ['can_play' => $canPlay]);
                }
            }

            $publicPlayers[] = [
                'id' => (int)$p['id'],
                'name' => $p['player_name'],
                'seat_order' => (int)$p['seat_order'],
                'is_me' => $isMe,
                'is_host' => (bool)$p['is_host'],
                'is_bot' => (bool)($p['sheet']['is_bot'] ?? false),
                'is_active' => ((int)$p['id'] === $activePlayerId),
                'card_count' => count($hand),
                'has_called_uno' => (bool)($p['sheet']['has_called_uno'] ?? false),
                'cards' => $annotatedCards, // null für Mitspieler!
            ];
        }

        // Verdecktes Deck wird niemals übertragen, nur der Zähler!
        return [
            'room_code' => $room['room_code'],
            'game_mode' => $gameMode,
            'status' => $room['status'],
            'state_version' => (int)$room['state_version'],
            'top_card' => $topCard,
            'active_color' => $activeColor,
            'direction' => (int)($roomState['direction'] ?? 1),
            'active_player_id' => $activePlayerId,
            'pending_draws' => $pendingDraws,
            'draw_pile_count' => count($roomState['draw_pile'] ?? []),
            'discard_pile_count' => count($roomState['discard_pile'] ?? []),
            'launcher_state' => $roomState['launcher_state'] ?? null,
            'winner_id' => $roomState['winner_id'] ?? null,
            'last_event' => $roomState['last_event'] ?? null,
            'players' => $publicPlayers,
            'valid_colors' => $ruleEngine->getValidColors(),
        ];
    }
}
