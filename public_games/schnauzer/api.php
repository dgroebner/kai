<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\GamesHub\RoomRepository;
use Kai\Tools\GamesHub\Schnauzer\SchnauzerService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$repo = new RoomRepository();
$service = new SchnauzerService($repo);
$rateLimiter = new RateLimiter();
$logger = new Logger();

$action = trim((string)($_GET['action'] ?? ''));
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = [];
}

$playerToken = trim((string)($_SERVER['HTTP_X_PLAYER_TOKEN'] ?? ''));
$playerTokenHash = $playerToken !== '' ? hash('sha256', $playerToken) : '';

try {
    // -------------------------------------------------------------------------
    // 1. RAUM ERSTELLEN (mit Tisch-PIN-Schutz)
    // -------------------------------------------------------------------------
    if ($action === 'create') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $actionKey = 'create_schnauzer_room';
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
            $logger->warn('Quarxl Schnauzer: Falscher Host-PIN eingegeben.', ['ip_hash' => $rateLimiter->getAnonymizedIpHash()]);
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Falscher Tisch-PIN. Bitte prüfe deine Eingabe.']);
            exit;
        }

        $rateLimiter->recordSuccess($actionKey);

        $playerName = trim((string)($input['player_name'] ?? 'Host'));
        if ($playerName === '') $playerName = 'Host';
        $playerName = mb_substr($playerName, 0, 30);

        $gameMode = trim((string)($input['game_mode'] ?? 'standard'));
        $rulesMode = trim((string)($input['rules_mode'] ?? $gameMode));
        if (!in_array($rulesMode, ['standard', 'gelenau'], true)) {
            $rulesMode = (!empty($input['three_aces_33']) || !empty($input['gelenauer_regeln'])) ? 'gelenau' : 'standard';
        }

        $newPlayerToken = bin2hex(random_bytes(16));
        $newHostHash = hash('sha256', $newPlayerToken);

        $initialState = $service->getInitialState($rulesMode);
        $room = $repo->createRoom('schnauzer', $rulesMode, $newHostHash, $initialState);

        if (!$room) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Konnte Raum nicht erstellen.']);
            exit;
        }

        $initialSheet = [
            'lives' => 3,
            'is_swimming' => false,
            'is_out' => false,
            'hand' => [],
            'is_bot' => false,
        ];
        $player = $repo->joinPlayer((int)$room['id'], $newHostHash, $playerName, true, $initialSheet);

        echo json_encode([
            'success' => true,
            'room_code' => $room['room_code'],
            'player_token' => $newPlayerToken,
            'player_id' => $player['id'],
            'is_host' => true,
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 2. RAUM BEITRETEN
    // -------------------------------------------------------------------------
    if ($action === 'join') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $roomCode = strtoupper(trim((string)($input['room_code'] ?? '')));
        $room = $repo->getRoomByCode($roomCode);

        if (!$room) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Raum nicht gefunden.']);
            exit;
        }

        $roomId = (int)$room['id'];
        $existingPlayers = $repo->getPlayersForRoom($roomId);

        // Wiederverbindung über Token
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
                        'is_host' => (bool)$p['is_host'],
                        'reconnected' => true,
                    ]);
                    exit;
                }
            }
        }

        if ($room['status'] !== 'lobby') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Das Spiel läuft bereits oder ist beendet.']);
            exit;
        }

        if (count($existingPlayers) >= 8) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Der Raum ist voll (maximal 8 Spieler).']);
            exit;
        }

        $playerName = trim((string)($input['player_name'] ?? ''));
        if ($playerName === '') $playerName = 'Spieler ' . (count($existingPlayers) + 1);
        $playerName = mb_substr($playerName, 0, 30);

        $newPlayerToken = bin2hex(random_bytes(16));
        $newTokenHash = hash('sha256', $newPlayerToken);

        $initialSheet = [
            'lives' => 3,
            'is_swimming' => false,
            'is_out' => false,
            'hand' => [],
            'is_bot' => false,
        ];

        $player = $repo->joinPlayer($roomId, $newTokenHash, $playerName, false, $initialSheet);

        echo json_encode([
            'success' => true,
            'room_code' => $room['room_code'],
            'player_token' => $newPlayerToken,
            'player_id' => $player['id'],
            'is_host' => false,
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // Ab hier: Raum und Spieler validieren
    // -------------------------------------------------------------------------
    $roomCode = strtoupper(trim((string)($input['room_code'] ?? $_GET['room'] ?? '')));
    $room = $repo->getRoomByCode($roomCode);
    if (!$room) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Raum existiert nicht.']);
        exit;
    }

    $roomId = (int)$room['id'];

    // -------------------------------------------------------------------------
    // REAKTIONEN ABRUFEN (Polling via reactions.js)
    // -------------------------------------------------------------------------
    if ($action === 'reactions') {
        $sinceId = (int)($_GET['since'] ?? 0);
        $reactions = $repo->getRecentReactions($roomId, $sinceId);

        echo json_encode([
            'success' => true,
            'reactions' => $reactions,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $myPlayer = $playerTokenHash !== '' ? $repo->getPlayerByToken($roomId, $playerTokenHash) : null;
    if (!$myPlayer) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Nicht autorisiert oder nicht im Raum']);
        exit;
    }
    $myPlayerId = (int)$myPlayer['id'];

    // -------------------------------------------------------------------------
    // REAKTION SENDEN (Quarxl Reaktions-Smilies)
    // -------------------------------------------------------------------------
    if ($action === 'react') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $emoji = trim((string)($input['emoji'] ?? ''));
        $res = $repo->addReaction($roomId, $myPlayerId, (string)$myPlayer['player_name'], $emoji);
        if (empty($res['success'])) {
            http_response_code(($res['error'] ?? '') === 'spam_cooldown' ? 429 : 400);
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------------------
    // 3. STATUS (POLLING & BOT-TRIGGER)
    // -------------------------------------------------------------------------
    if ($action === 'status') {
        if ($room['status'] === 'active') {
            $service->triggerBotTurnIfNeeded($roomId);
        }

        $gameState = $service->getPublicGameState($roomId, $myPlayerId);
        if ($gameState) {
            $gameState['recent_reactions'] = $repo->getRecentReactions($roomId);
        }

        echo json_encode([
            'success' => true,
            'data' => $gameState,
            'my_player_id' => $myPlayerId,
            'is_host' => (bool)$myPlayer['is_host'],
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 4. SPIEL STARTEN (nur Host)
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
    // 5. GEBER-ENTSCHEIDUNG (keep oder swap)
    // -------------------------------------------------------------------------
    if ($action === 'dealer_choice') {
        $choice = trim((string)($input['choice'] ?? 'keep'));
        $res = $service->handleDealerChoice($roomId, $myPlayerId, $choice);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 6. EINE KARTE TAUSCHEN (hand_index, table_index)
    // -------------------------------------------------------------------------
    if ($action === 'swap_one') {
        $handIndex = (int)($input['hand_index'] ?? -1);
        $tableIndex = (int)($input['table_index'] ?? -1);

        $res = $service->swapOne($roomId, $myPlayerId, $handIndex, $tableIndex);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 7. ALLE DREI KARTEN TAUSCHEN
    // -------------------------------------------------------------------------
    if ($action === 'swap_all') {
        $res = $service->swapAll($roomId, $myPlayerId);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 8. SCHIEBEN (Passen)
    // -------------------------------------------------------------------------
    if ($action === 'pass') {
        $res = $service->passTurn($roomId, $myPlayerId);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 9. ZUMACHEN / KLOPFEN
    // -------------------------------------------------------------------------
    if ($action === 'knock') {
        $res = $service->knock($roomId, $myPlayerId);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 10. NÄCHSTE RUNDE STARTEN (nach Showdown)
    // -------------------------------------------------------------------------
    if ($action === 'next_round') {
        $res = $service->startNextRound($roomId, $myPlayerId);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 11. SPIEL NEUSTARTEN (nach Game Over / Showdown)
    // -------------------------------------------------------------------------
    if ($action === 'restart') {
        $res = $service->restartGame($roomId);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 12. KI-BOT HINZUFÜGEN / ENTFERNEN (nur Host)
    // -------------------------------------------------------------------------
    if ($action === 'add_bot') {
        if (!$myPlayer['is_host']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Nur der Host kann Bots hinzufügen.']);
            exit;
        }

        $botNames = ['Robo-Opa', 'Knack-Bot', 'Karten-Fritz', 'Skat-Susi', 'Blatt-Benno', 'Schnauzer-Sam'];
        $existing = $repo->getPlayersForRoom($roomId);
        $used = array_map(fn($p) => $p['player_name'], $existing);
        $available = array_values(array_diff($botNames, $used));
        $name = !empty($available) ? $available[array_rand($available)] : 'Bot ' . (count($existing) + 1);

        $res = $service->addBot($roomId, $name);
        echo json_encode($res);
        exit;
    }

    if ($action === 'kick_bot') {
        if (!$myPlayer['is_host']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Nur der Host kann Bots entfernen.']);
            exit;
        }

        $botId = (int)($input['bot_id'] ?? 0);
        $res = $service->kickBot($roomId, $botId);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 13. SPIELERNAME ÄNDERN
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

    // -------------------------------------------------------------------------
    // 14. RAUM VERLASSEN
    // -------------------------------------------------------------------------
    if ($action === 'leave') {
        $repo->leavePlayer($roomId, $myPlayerId);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion.']);
} catch (\Throwable $e) {
    $logger->error('public_games/schnauzer/api.php Fehler', [
        'error' => $e->getMessage(),
        'action' => $action,
    ]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Interner Serverfehler']);
}
