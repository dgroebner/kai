<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\GamesHub\CardGames\CardGameService;
use Kai\Tools\GamesHub\RoomRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$repo = new RoomRepository();
$cardService = new CardGameService($repo);
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
    // 1. RAUM ERSTELLEN (mit PIN-Schutz)
    // -------------------------------------------------------------------------
    if ($action === 'create') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $actionKey = 'create_card_room';
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
            $logger->warn('Quarxl Cards: Falscher Host-PIN eingegeben.', ['ip_hash' => $rateLimiter->getAnonymizedIpHash()]);
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Falscher Tisch-PIN. Bitte prüfe deine Eingabe.']);
            exit;
        }

        $rateLimiter->recordSuccess($actionKey);

        $playerName = trim((string)($input['player_name'] ?? 'Host'));
        if ($playerName === '') $playerName = 'Host';
        $playerName = mb_substr($playerName, 0, 30);

        // Spielmodus: uno, uno_extreme oder mau_mau
        $gameMode = trim((string)($input['game_mode'] ?? 'uno'));
        if (!in_array($gameMode, ['uno', 'uno_extreme', 'mau_mau'], true)) {
            $gameMode = 'uno';
        }

        $newPlayerToken = bin2hex(random_bytes(16));
        $newHostHash = hash('sha256', $newPlayerToken);

        $initialState = $cardService->getInitialState($gameMode);
        $room = $repo->createRoom('cards', $gameMode, $newHostHash, $initialState);

        if (!$room) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Konnte Raum nicht erstellen.']);
            exit;
        }

        $initialSheet = ['cards' => [], 'has_called_uno' => false, 'is_bot' => false];
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
    // 2. RAUM BEITRETEN (für Gäste via Link oder Code)
    // -------------------------------------------------------------------------
    if ($action === 'join') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $roomCode = strtoupper(trim((string)($input['room_code'] ?? '')));
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
        $initialSheet = ['cards' => [], 'has_called_uno' => false, 'is_bot' => false];
        $player = $repo->joinPlayer((int)$room['id'], $currentHash, $playerName, $isHost, $initialSheet);

        echo json_encode([
            'success' => true,
            'room_code' => $room['room_code'],
            'player_token' => $currentToken,
            'player_id' => $player['id'],
            'is_host' => (bool)$player['is_host'],
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // AB HIER: Erfordert gültigen Raum & identifizierten Spieler
    // -------------------------------------------------------------------------
    $roomCode = strtoupper(trim((string)($_GET['room'] ?? $input['room_code'] ?? '')));
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
    // 3. STATE ABRUFEN (Zero-Knowledge)
    // -------------------------------------------------------------------------
    if ($action === 'state') {
        $players = $repo->getPlayersForRoom($roomId);
        $publicState = $cardService->getPublicStateForPlayer($room, $player, $players);

        echo json_encode([
            'success' => true,
            'data' => $publicState,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------------------
    // 4. SPIEL STARTEN (nur Host)
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

        $res = $cardService->startGame($roomId);
        if (!$res['success']) {
            http_response_code(400);
            echo json_encode($res);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 5. BOT HINZUFÜGEN (nur Host in Lobby)
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

        $botNames = ['🤖 Robo-Max', '🤖 Cyber-Ella', '🤖 Bit-Berta', '🤖 Pixel-Paul', '🤖 Byte-Ben'];
        $players = $repo->getPlayersForRoom($roomId);
        $botIdx = count($players);
        $name = $botNames[($botIdx - 1) % count($botNames)];

        $res = $cardService->addBot($roomId, $name);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 6. KARTE SPIELEN
    // -------------------------------------------------------------------------
    if ($action === 'play_card') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $cardId = trim((string)($input['card_id'] ?? ''));
        $chosenColor = !empty($input['chosen_color']) ? trim((string)$input['chosen_color']) : null;
        $calledUno = !empty($input['called_uno']);

        $res = $cardService->playCard($roomId, (int)$player['id'], $cardId, $chosenColor, $calledUno);
        if (!$res['success']) {
            http_response_code(400);
            echo json_encode($res);
            exit;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 7. ZIEHEN ODER LAUNCHER DRÜCKEN
    // -------------------------------------------------------------------------
    if ($action === 'draw') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $res = $cardService->drawOrLaunch($roomId, (int)$player['id']);
        if (!$res['success']) {
            http_response_code(400);
            echo json_encode($res);
            exit;
        }

        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 8. "UNO!" / "MAU!" RUFEN
    // -------------------------------------------------------------------------
    if ($action === 'call_uno') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $res = $cardService->callUno($roomId, (int)$player['id']);
        echo json_encode($res);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Aktion']);
} catch (\Throwable $e) {
    $logger->error('public_games/cards/api.php: Fehler', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Interner Serverfehler']);
}
