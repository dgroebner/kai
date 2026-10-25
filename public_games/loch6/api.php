<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\GamesHub\RoomRepository;
use Kai\Tools\GamesHub\Loch6\Loch6Service;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$repo = new RoomRepository();
$service = new Loch6Service($repo);
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
    // 1. RAUM ERSTELLEN
    // -------------------------------------------------------------------------
    if ($action === 'create') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $actionKey = 'create_room';
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
            $logger->warn('Quarxl Loch 6: Falscher Host-PIN eingegeben.', ['ip_hash' => $rateLimiter->getAnonymizedIpHash()]);
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Falscher Tisch-PIN. Bitte prüfe deine Eingabe.']);
            exit;
        }

        $rateLimiter->recordSuccess($actionKey);

        $playerName = trim((string)($input['player_name'] ?? 'Host'));
        if ($playerName === '') $playerName = 'Host';
        $playerName = mb_substr($playerName, 0, 30);

        $stonesInput = $input['stones'] ?? 'auto';
        $isFixed = false;
        $stoneCount = Loch6Service::DEFAULT_STONES;

        if (is_numeric($stonesInput)) {
            $val = (int)$stonesInput;
            if ($val >= 4 && $val <= 20) {
                $stoneCount = $val;
                $isFixed = true;
            }
        }

        $gameMode = $isFixed ? "{$stoneCount}_stones" : "dynamic_stones";

        $newPlayerToken = bin2hex(random_bytes(16));
        $newHostHash = hash('sha256', $newPlayerToken);

        $initialState = $service->getInitialState($stoneCount);
        $initialState['starting_stones_fixed'] = $isFixed;
        $initialState['starting_stones_mode'] = $isFixed ? 'fixed' : 'auto';
        $room = $repo->createRoom('loch6', $gameMode, $newHostHash, $initialState);

        if (!$room) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Konnte Raum nicht erstellen.']);
            exit;
        }

        $initialSheet = $service->getInitialSheet($stoneCount, false);
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

        $roomCode = strtoupper(trim((string)($input['room_code'] ?? $input['room'] ?? $_GET['room'] ?? '')));
        $playerName = trim((string)($input['player_name'] ?? 'Gast'));
        if ($playerName === '') $playerName = 'Gast';
        $playerName = mb_substr($playerName, 0, 30);

        $room = $repo->getRoomByCode($roomCode);
        if (!$room) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Raum nicht gefunden.']);
            exit;
        }

        $currentToken = $playerToken !== '' ? $playerToken : bin2hex(random_bytes(16));
        $currentHash = hash('sha256', $currentToken);

        $isHost = hash_equals((string)$room['host_token_hash'], $currentHash);
        $stoneCount = (int)($room['state']['starting_stones'] ?? 6);
        $initialSheet = $service->getInitialSheet($stoneCount, false);

        $existingPlayer = $repo->getPlayerByToken((int)$room['id'], $currentHash);
        if ($existingPlayer) {
            echo json_encode([
                'success' => true,
                'room_code' => $room['room_code'],
                'player_token' => $currentToken,
                'player_id' => $existingPlayer['id'],
                'is_host' => (bool)$existingPlayer['is_host'],
            ]);
            exit;
        }

        $currentPlayers = $repo->getPlayersForRoom((int)$room['id']);
        if (count($currentPlayers) >= 6) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Raum ist voll (max. 6 Spieler).']);
            exit;
        }

        $newCount = count($currentPlayers) + 1;
        $isFixed = !empty($room['state']['starting_stones_fixed']);
        $stoneCount = $isFixed ? (int)($room['state']['starting_stones'] ?? Loch6Service::DEFAULT_STONES) : Loch6Service::getRecommendedStones($newCount);
        $initialSheet = $service->getInitialSheet($stoneCount, false);

        $player = $repo->joinPlayer((int)$room['id'], $currentHash, $playerName, $isHost, $initialSheet);
        $service->syncLobbyPlayerStones((int)$room['id']);

        echo json_encode([
            'success' => true,
            'room_code' => $room['room_code'],
            'player_token' => $currentToken,
            'player_id' => $player['id'],
            'is_host' => (bool)$player['is_host'],
        ]);
        exit;
    }

    // Authentifizierungs-Check für alle folgenden Aktionen
    $roomCode = strtoupper(trim((string)($_GET['room'] ?? $input['room_code'] ?? $input['room'] ?? '')));
    if ($roomCode === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Raum-Code fehlt']);
        exit;
    }

    $room = $repo->getRoomByCode($roomCode);
    if (!$room) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Raum nicht gefunden']);
        exit;
    }

    $roomId = (int)$room['id'];
    $player = $repo->getPlayerByToken($roomId, $playerTokenHash);
    if (!$player) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Nicht autorisiert oder nicht im Raum']);
        exit;
    }

    // -------------------------------------------------------------------------
    // 3. STATE ABRUFEN
    // -------------------------------------------------------------------------
    if ($action === 'state') {
        $players = $repo->getPlayersForRoom($roomId);
        $publicState = $service->getPublicStateForPlayer($room, $player, $players);

        echo json_encode([
            'success' => true,
            'data' => $publicState,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------------------
    // 4. SPIEL STARTEN (Host)
    // -------------------------------------------------------------------------
    if ($action === 'start') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        if (empty($player['is_host'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Nur der Host kann das Spiel starten']);
            exit;
        }

        $res = $service->startGame($roomId);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 5. BOT HINZUFÜGEN (Host)
    // -------------------------------------------------------------------------
    if ($action === 'add_bot') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        if (empty($player['is_host'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Nur der Host kann Bots hinzufügen']);
            exit;
        }

        $botNames = ['Robo-Max', 'Kristall-Klaus', 'Stein-Steffi', 'Zocker-Zoe', 'Willy-Wackelig'];
        $players = $repo->getPlayersForRoom($roomId);
        $usedCount = count(array_filter($players, fn($p) => !empty($p['sheet']['is_bot'])));
        $botName = $botNames[$usedCount % count($botNames)] ?? 'Bot-' . ($usedCount + 1);

        $res = $service->addBot($roomId, $botName);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 6. WÜRFELN (1 W6)
    // -------------------------------------------------------------------------
    if ($action === 'roll') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $stoneId = trim((string)($input['stone_id'] ?? ''));
        $res = $service->rollDice($roomId, (int)$player['id'], $stoneId ?: null);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 7. WEITERGEBEN / SICHERN (Pass)
    // -------------------------------------------------------------------------
    if ($action === 'pass') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $res = $service->passTurn($roomId, (int)$player['id']);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 8. REVANCHE (Host)
    // -------------------------------------------------------------------------
    if ($action === 'rematch') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        if (empty($player['is_host'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Nur der Host kann eine Revanche starten']);
            exit;
        }

        $res = $service->startGame($roomId);
        echo json_encode($res);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion']);
} catch (\Throwable $e) {
    $logger->error('Loch 6 api.php: Fehler.', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Interner Serverfehler']);
}
