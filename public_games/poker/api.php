<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\GamesHub\RoomRepository;
use Kai\Tools\GamesHub\Poker\PokerService;
use Kai\Tools\Shared\Security\RateLimiter;
use Kai\Tools\Shared\Log\Logger;

header('Content-Type: application/json; charset=utf-8');

$repo = new RoomRepository();
$service = new PokerService($repo);
$rateLimiter = new RateLimiter();
$logger = new Logger();

$action = trim((string)($_GET['action'] ?? 'state'));
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = [];
}

$playerToken = trim((string)($_SERVER['HTTP_X_PLAYER_TOKEN'] ?? ''));
$playerTokenHash = $playerToken !== '' ? hash('sha256', $playerToken) : '';

try {
    // -------------------------------------------------------------------------
    // 1. RAUM ERSTELLEN (mit Tisch-PIN)
    // -------------------------------------------------------------------------
    if ($action === 'create') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $actionKey = 'create_poker_room';
        if ($rateLimiter->isBlocked($actionKey)) {
            $rem = $rateLimiter->getRemainingLockSeconds($actionKey);
            $mins = ceil($rem / 60);
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => "Zu viele Fehlversuche. Bitte warte {$mins} Minuten."]);
            exit;
        }

        $configuredPin = (string)($_ENV['GAMES_HOST_PIN'] ?? '123456');
        $inputPin = trim((string)($input['pin'] ?? ''));

        if (!hash_equals($configuredPin, $inputPin)) {
            $rateLimiter->recordFailure($actionKey, 5, 15);
            $logger->warn('Quarxl Poker: Falscher Host-PIN eingegeben.', ['ip_hash' => $rateLimiter->getAnonymizedIpHash()]);
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Falscher Tisch-PIN. Bitte prüfe deine Eingabe.']);
            exit;
        }

        $rateLimiter->recordSuccess($actionKey);

        $playerName = trim((string)($input['player_name'] ?? 'Host'));
        if ($playerName === '') $playerName = 'Host';
        $playerName = mb_substr($playerName, 0, 30);

        $gameMode = trim((string)($input['game_mode'] ?? 'standard'));

        $newHostToken = bin2hex(random_bytes(16));
        $newHostHash = hash('sha256', $newHostToken);

        $initialState = $service->getInitialState();
        $room = $repo->createRoom('poker', $gameMode, $newHostHash, $initialState);

        if (!$room) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Konnte Pokertisch nicht erstellen.']);
            exit;
        }

        $initialSheet = [
            'chips' => 1000,
            'current_bet' => 0,
            'total_bet' => 0,
            'is_folded' => false,
            'is_all_in' => false,
            'is_out' => false,
            'hand' => [],
            'is_bot' => false,
        ];

        $player = $repo->joinPlayer((int)$room['id'], $newHostHash, $playerName, true, $initialSheet);

        // Optionale KI-Bots direkt bei der Raumerstellung hinzufügen
        $botsCount = max(0, min(7, (int)($input['bots_count'] ?? 0)));
        if ($botsCount > 0) {
            $botNames = ['Poker-Paule', 'Bluff-Bella', 'Ace-Andy', 'River-Ralf', 'Chip-Charly', 'Fold-Fritz', 'Texas-Tina'];
            for ($i = 0; $i < $botsCount; $i++) {
                $service->addBot((int)$room['id'], $botNames[$i] ?? "Bot " . ($i + 1));
            }
        }

        echo json_encode([
            'success' => true,
            'room_code' => $room['room_code'],
            'player_token' => $newHostToken,
            'player_id' => $player['id'],
            'is_host' => true,
        ]);
        exit;
    }

    // Ab hier ist ein Raum-Code erforderlich
    $roomCode = strtoupper(trim((string)($_GET['room'] ?? $input['room_code'] ?? '')));
    if ($roomCode === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Raum-Code fehlt.']);
        exit;
    }

    $room = $repo->getRoomByCode($roomCode);
    if (!$room || $room['game_type'] !== 'poker') {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Pokertisch nicht gefunden.']);
        exit;
    }

    $roomId = (int)$room['id'];

    // -------------------------------------------------------------------------
    // 2. RAUM BEITRETEN (ODER RECONNECT)
    // -------------------------------------------------------------------------
    if ($action === 'join') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $existingPlayers = $repo->getPlayersForRoom($roomId);

        // Reconnect via Token
        if ($playerTokenHash !== '') {
            foreach ($existingPlayers as $p) {
                if ($p['player_token_hash'] === $playerTokenHash) {
                    $repo->setPlayerConnected((int)$p['id'], true);
                    $inputName = trim((string)($input['player_name'] ?? ''));
                    if ($inputName !== '' && $inputName !== $p['player_name']) {
                        $inputName = mb_substr($inputName, 0, 30);
                        $repo->updatePlayerName((int)$p['id'], $inputName);
                        $p['player_name'] = $inputName;
                    }
                    echo json_encode([
                        'success' => true,
                        'room_code' => $room['room_code'],
                        'player_token' => $playerToken,
                        'player_id' => $p['id'],
                        'player_name' => $p['player_name'],
                        'is_host' => (bool)$p['is_host'],
                        'reconnected' => true,
                    ]);
                    exit;
                }
            }
        }

        if ($room['status'] !== 'lobby') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Das Spiel läuft bereits.']);
            exit;
        }

        if (count($existingPlayers) >= 8) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Der Tisch ist mit 8 Spielern voll.']);
            exit;
        }

        $playerName = trim((string)($input['player_name'] ?? ''));
        if ($playerName === '') $playerName = 'Spieler ' . (count($existingPlayers) + 1);
        $playerName = mb_substr($playerName, 0, 30);

        $newToken = bin2hex(random_bytes(16));
        $newTokenHash = hash('sha256', $newToken);

        $initialSheet = [
            'chips' => 1000,
            'current_bet' => 0,
            'total_bet' => 0,
            'is_folded' => false,
            'is_all_in' => false,
            'is_out' => false,
            'hand' => [],
            'is_bot' => false,
        ];

        $player = $repo->joinPlayer($roomId, $newTokenHash, $playerName, false, $initialSheet);

        echo json_encode([
            'success' => true,
            'room_code' => $room['room_code'],
            'player_token' => $newToken,
            'player_id' => $player['id'],
            'player_name' => $playerName,
            'is_host' => false,
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 3. EMOJI-REAKTIONEN (auch ohne Player-Objekt lesbar)
    // -------------------------------------------------------------------------
    if ($action === 'reactions') {
        $sinceId = (int)($_GET['since'] ?? 0);
        $reactions = $repo->getReactionsSince($roomId, $sinceId);
        echo json_encode(['success' => true, 'reactions' => $reactions]);
        exit;
    }

    // Spieler-Identifikation anhand des Session-/Player-Tokens
    $myPlayer = $playerTokenHash !== '' ? $repo->getPlayerByToken($roomId, $playerTokenHash) : null;
    if (!$myPlayer) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Nicht autorisiert oder noch nicht beigetreten.']);
        exit;
    }

    $myPlayerId = (int)$myPlayer['id'];

    // -------------------------------------------------------------------------
    // 4. STATUS ABFRAGEN (POLLING)
    // -------------------------------------------------------------------------
    if ($action === 'state' || $action === 'status') {
        if ($room['status'] === 'active') {
            $service->triggerBotTurnIfNeeded($roomId);
        }

        $stateData = $service->getStateForPlayer($roomId, $myPlayerId);
        echo json_encode([
            'success' => true,
            'data' => $stateData,
            'my_player_id' => $myPlayerId,
            'is_host' => (bool)$myPlayer['is_host'],
        ]);
        exit;
    }

    if ($action === 'react') {
        $emoji = trim((string)($input['emoji'] ?? ''));
        if ($emoji === '') {
            echo json_encode(['success' => false, 'message' => 'Emoji fehlt']);
            exit;
        }
        $res = $repo->addReaction($roomId, $myPlayerId, (string)$myPlayer['player_name'], $emoji);
        echo json_encode(['success' => (bool)$res]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 5. SPIEL STARTEN (nur Host)
    // -------------------------------------------------------------------------
    if ($action === 'start') {
        if (!$myPlayer['is_host']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Nur der Host kann das Spiel starten.']);
            exit;
        }

        $res = $service->startGame($roomId);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 6. SPIELZUG AUSFÜHREN (fold, check, call, bet, raise, all_in)
    // -------------------------------------------------------------------------
    if ($action === 'play_turn') {
        $subAction = (string)($input['action_type'] ?? '');
        $amount = (int)($input['amount'] ?? 0);

        $res = $service->takeAction($roomId, $myPlayerId, $subAction, $amount);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 7. NÄCHSTE HAND STARTEN (nach Showdown)
    // -------------------------------------------------------------------------
    if ($action === 'next_hand') {
        $res = $service->startNextHand($roomId, $myPlayerId);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 8. SPIELABBRUCH BEANTRAGEN / BESTÄTIGEN
    // -------------------------------------------------------------------------
    if ($action === 'vote_abort') {
        $res = $service->voteAbort($roomId, $myPlayerId);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 9. NEUES GESAMT-SPIEL STARTEN (nach Game Over)
    // -------------------------------------------------------------------------
    if ($action === 'restart') {
        $res = $service->startGame($roomId);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 10. KI-BOT HINZUFÜGEN (in Lobby)
    // -------------------------------------------------------------------------
    if ($action === 'add_bot') {
        if ($room['status'] !== 'lobby') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Bots können nur in der Lobby hinzugefügt werden.']);
            exit;
        }

        $botNames = ['Poker-Paule', 'Bluff-Bella', 'Ace-Andy', 'River-Ralf', 'Chip-Charly', 'Fold-Fritz', 'Texas-Tina', 'Full-House-Felix'];
        $existing = $repo->getPlayersForRoom($roomId);
        $used = array_map(fn($p) => $p['player_name'], $existing);
        $available = array_values(array_diff($botNames, $used));
        $name = !empty($available) ? $available[array_rand($available)] : 'Bot ' . (count($existing) + 1);

        $res = $service->addBot($roomId, $name);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 11. KI-BOT ENTFERNEN (nur Host in Lobby)
    // -------------------------------------------------------------------------
    if ($action === 'kick_bot') {
        if (!$myPlayer['is_host']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Nur der Host kann Bots entfernen.']);
            exit;
        }

        $botId = (int)($input['bot_id'] ?? 0);
        $repo->removePlayer($roomId, $botId);
        echo json_encode(['success' => true]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 12. SPIELERNAME ÄNDERN
    // -------------------------------------------------------------------------
    if ($action === 'rename' || $action === 'update_name') {
        $newName = trim((string)($input['name'] ?? $input['player_name'] ?? ''));
        if ($newName === '') {
            echo json_encode(['success' => false, 'message' => 'Name darf nicht leer sein.']);
            exit;
        }
        $newName = mb_substr($newName, 0, 30);
        $repo->updatePlayerName($myPlayerId, $newName);
        echo json_encode(['success' => true, 'name' => $newName]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion']);
} catch (\Throwable $e) {
    $logger->error('public_games/poker/api.php Fehler', ['error' => $e->getMessage(), 'action' => $action]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Interner Serverfehler']);
}
