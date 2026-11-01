<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\GamesHub\RoomRepository;
use Kai\Tools\GamesHub\SkipBo\SkipBoService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$repo = new RoomRepository();
$skipBoService = new SkipBoService($repo);
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

        $actionKey = 'create_skipbo_room';
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
            $logger->warn('Quarxl Skip-Bo: Falscher Host-PIN eingegeben.', ['ip_hash' => $rateLimiter->getAnonymizedIpHash()]);
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Falscher Tisch-PIN. Bitte prüfe deine Eingabe.']);
            exit;
        }

        $rateLimiter->recordSuccess($actionKey);

        $playerName = trim((string)($input['player_name'] ?? 'Host'));
        if ($playerName === '') $playerName = 'Host';
        $playerName = mb_substr($playerName, 0, 30);

        // Spielmodus: 'fast' (10 Karten), 'standard' (20 Karten), 'classic' (30 Karten)
        $gameMode = trim((string)($input['game_mode'] ?? 'standard'));
        if (!in_array($gameMode, ['fast', 'standard', 'classic'], true)) {
            $gameMode = 'standard';
        }

        $newPlayerToken = bin2hex(random_bytes(16));
        $newHostHash = hash('sha256', $newPlayerToken);

        $initialState = $skipBoService->getInitialState($gameMode);
        $room = $repo->createRoom('skipbo', $gameMode, $newHostHash, $initialState);

        if (!$room) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Konnte Raum nicht erstellen.']);
            exit;
        }

        $initialSheet = [
            'stock_pile' => [],
            'discard_piles' => [[], [], [], []],
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
            echo json_encode(['success' => false, 'message' => 'Raum nicht gefunden']);
            exit;
        }

        $roomId = (int)$room['id'];
        $playerName = trim((string)($input['player_name'] ?? 'Gast'));
        if ($playerName === '') $playerName = 'Gast';
        $playerName = mb_substr($playerName, 0, 30);

        // Prüfen, ob der Spieler bereits im Raum ist
        if ($playerTokenHash !== '') {
            $existing = $repo->getPlayerByToken($roomId, $playerTokenHash);
            if ($existing) {
                echo json_encode([
                    'success' => true,
                    'room_code' => $room['room_code'],
                    'player_token' => $playerToken,
                    'player_id' => $existing['id'],
                    'is_host' => (bool)$existing['is_host'],
                ]);
                exit;
            }
        }

        // Falls das Spiel schon läuft, kann kein neuer Spieler mehr beitreten
        if ($room['status'] !== 'lobby') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Das Spiel läuft bereits']);
            exit;
        }

        $players = $repo->getPlayersForRoom($roomId);
        if (count($players) >= 6) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Tisch ist voll (maximal 6 Spieler)']);
            exit;
        }

        $newToken = bin2hex(random_bytes(16));
        $newHash = hash('sha256', $newToken);

        $initialSheet = [
            'stock_pile' => [],
            'discard_piles' => [[], [], [], []],
            'hand' => [],
            'is_bot' => false,
        ];
        $player = $repo->joinPlayer($roomId, $newHash, $playerName, false, $initialSheet);

        echo json_encode([
            'success' => true,
            'room_code' => $room['room_code'],
            'player_token' => $newToken,
            'player_id' => $player['id'],
            'is_host' => false,
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // VORBEDINGUNG FÜR WEITERE AKTIONEN: Token & Raum validieren
    // -------------------------------------------------------------------------
    $roomCode = strtoupper(trim((string)($input['room_code'] ?? $_GET['room'] ?? '')));
    $room = $repo->getRoomByCode($roomCode);
    if (!$room) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Raum nicht gefunden']);
        exit;
    }

    $roomId = (int)$room['id'];
    $player = $playerTokenHash !== '' ? $repo->getPlayerByToken($roomId, $playerTokenHash) : null;
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
        $publicState = $skipBoService->getPublicStateForPlayer($room, $player, $players);
        $publicState['recent_reactions'] = $repo->getRecentReactions($roomId);

        echo json_encode([
            'success' => true,
            'data' => $publicState,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

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
        $res = $repo->addReaction($roomId, (int)$player['id'], (string)$player['player_name'], $emoji);
        if (empty($res['success'])) {
            http_response_code(($res['error'] ?? '') === 'spam_cooldown' ? 429 : 400);
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
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

        $res = $skipBoService->startGame($roomId);
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

        $res = $skipBoService->addBot($roomId, $name);
        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 6. KARTE AUF BAUSTAPEL SPIELEN
    // -------------------------------------------------------------------------
    if ($action === 'play_card') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $source = trim((string)($input['source'] ?? ''));
        $sourceIndex = $input['source_index'] ?? null;
        $targetPile = (int)($input['target_pile'] ?? 0);

        $res = $skipBoService->playCard($roomId, (int)$player['id'], $source, $sourceIndex, $targetPile);
        if (!$res['success']) {
            http_response_code(400);
            echo json_encode($res);
            exit;
        }

        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 7. KARTE AUF HILFSSTAPEL ABWERFEN (ZUG BEENDEN)
    // -------------------------------------------------------------------------
    if ($action === 'discard') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $cardId = trim((string)($input['card_id'] ?? ''));
        $targetDiscard = (int)($input['target_discard_index'] ?? 0);

        $res = $skipBoService->discardCard($roomId, (int)$player['id'], $cardId, $targetDiscard);
        if (!$res['success']) {
            http_response_code(400);
            echo json_encode($res);
            exit;
        }

        echo json_encode($res);
        exit;
    }

    // -------------------------------------------------------------------------
    // 8. SPIELERNAME ÄNDERN
    // -------------------------------------------------------------------------
    if ($action === 'update_name') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $newName = trim((string)($input['player_name'] ?? ''));
        if ($newName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Name darf nicht leer sein']);
            exit;
        }

        $newName = mb_substr($newName, 0, 30);
        $repo->updatePlayerName((int)$player['id'], $newName);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion']);
} catch (\Throwable $e) {
    $logger->error('public_games/skipbo/api.php: Fehler bei Anfrage.', [
        'error' => $e->getMessage(),
        'action' => $action,
    ]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Interner Serverfehler']);
}
