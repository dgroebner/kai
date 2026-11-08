<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\GamesHub\RoomRepository;
use Kai\Tools\GamesHub\UnderTheHood\HoodConfig;
use Kai\Tools\GamesHub\UnderTheHood\HoodService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$repo = new RoomRepository();
$service = new HoodService($repo);
$rateLimiter = new RateLimiter();
$logger = new Logger();

$action = trim((string)($_GET['action'] ?? ''));
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$playerToken = trim((string)($_SERVER['HTTP_X_PLAYER_TOKEN'] ?? ''));
$playerTokenHash = $playerToken !== '' ? hash('sha256', $playerToken) : '';

function hoodRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function hoodRequirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        hoodRespond(405, ['success' => false, 'message' => 'Nur POST erlaubt']);
    }
}

try {
    // -------------------------------------------------------------------------
    // 1. RAUM ERSTELLEN
    // -------------------------------------------------------------------------
    if ($action === 'create') {
        hoodRequirePost();

        $actionKey = 'create_hood_room';
        if ($rateLimiter->isBlocked($actionKey)) {
            $mins = (int)ceil($rateLimiter->getRemainingLockSeconds($actionKey) / 60);
            hoodRespond(429, ['success' => false, 'message' => "Zu viele Fehlversuche. Bitte warte {$mins} Minuten."]);
        }

        $configuredPin = (string)($_ENV['GAMES_HOST_PIN'] ?? '123456');
        $inputPin = trim((string)($input['pin'] ?? ''));
        if (!hash_equals($configuredPin, $inputPin)) {
            $rateLimiter->recordFailure($actionKey, 5, 15);
            $logger->warn('Quarxl UnderTheHood: Falscher Host-PIN.', ['ip_hash' => $rateLimiter->getAnonymizedIpHash()]);
            hoodRespond(403, ['success' => false, 'message' => 'Falscher Tisch-PIN. Bitte prüfe deine Eingabe.']);
        }
        $rateLimiter->recordSuccess($actionKey);

        $playerName = mb_substr(trim((string)($input['player_name'] ?? 'Driver')), 0, 25);
        if ($playerName === '') $playerName = 'Driver';
        $vehicle = trim((string)($input['vehicle'] ?? 'sportscar'));
        $color = trim((string)($input['color'] ?? '#ef4444'));

        $newPlayerToken = bin2hex(random_bytes(16));
        $newHostHash = hash('sha256', $newPlayerToken);

        $initialState = $service->createInitialState();
        $room = $repo->createRoom('under_the_hood', 'standard', $newHostHash, $initialState);
        if (!$room) {
            hoodRespond(500, ['success' => false, 'message' => 'Konnte Raum nicht anlegen']);
        }

        $roomId = (int)$room['id'];
        $repo->joinPlayer($roomId, $newHostHash, $playerName, true);
        $addRes = $service->addPlayer($roomId, $newHostHash, $playerName, $vehicle, $color, true);

        hoodRespond(200, [
            'success'      => true,
            'room_code'    => $room['room_code'],
            'player_token' => $newPlayerToken,
            'player_id'    => $addRes['player']['id'] ?? 1,
            'is_host'      => true,
            'state'        => $addRes['state'] ?? $initialState,
        ]);
    }

    // -------------------------------------------------------------------------
    // 2. RAUM BEITRETEN
    // -------------------------------------------------------------------------
    if ($action === 'join') {
        hoodRequirePost();

        $roomCode = strtoupper(trim((string)($input['room_code'] ?? '')));
        $room = $repo->getRoomByCode($roomCode);
        if (!$room) {
            hoodRespond(404, ['success' => false, 'message' => 'Raum nicht gefunden']);
        }
        $roomId = (int)$room['id'];

        $playerName = mb_substr(trim((string)($input['player_name'] ?? 'Racer')), 0, 25);
        if ($playerName === '') $playerName = 'Racer';
        $vehicle = trim((string)($input['vehicle'] ?? 'camper'));
        $color = trim((string)($input['color'] ?? '#3b82f6'));

        $myToken = $playerToken !== '' ? $playerToken : bin2hex(random_bytes(16));
        $myHash = hash('sha256', $myToken);

        $repo->joinPlayer($roomId, $myHash, $playerName, false);
        $res = $service->addPlayer($roomId, $myHash, $playerName, $vehicle, $color, false);
        if (!$res['success']) {
            hoodRespond(400, $res);
        }

        hoodRespond(200, [
            'success'      => true,
            'room_code'    => $room['room_code'],
            'player_token' => $myToken,
            'player_id'    => $res['player']['id'],
            'is_host'      => false,
            'state'        => $res['state'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Für alle weiteren Aktionen: Raum & Spieler ermitteln
    // -------------------------------------------------------------------------
    $roomCode = strtoupper(trim((string)($input['room_code'] ?? ($_GET['room_code'] ?? ''))));
    $room = $repo->getRoomByCode($roomCode);
    if (!$room) {
        hoodRespond(404, ['success' => false, 'message' => 'Raum nicht gefunden']);
    }
    $roomId = (int)$room['id'];
    $state = $room['state'];

    $currentPlayer = null;
    foreach ($state['players'] as $p) {
        if ($p['token_hash'] === $playerTokenHash) {
            $currentPlayer = $p;
            break;
        }
    }

    // 3. STATE ABRUFEN
    if ($action === 'state') {
        hoodRespond(200, [
            'success' => true,
            'state'   => $state,
            'me'      => $currentPlayer,
            'version' => $room['state_version'],
        ]);
    }

    // 4. BOT HINZUFÜGEN (Nur Host im Lobby-Status)
    if ($action === 'add_bot') {
        hoodRequirePost();
        if (!$currentPlayer || empty($currentPlayer['is_host'])) {
            hoodRespond(403, ['success' => false, 'message' => 'Nur der Host kann Bots hinzufügen']);
        }
        $res = $service->addBot($roomId);
        hoodRespond(200, $res);
    }

    // 5. SPIEL STARTEN (Nur Host)
    if ($action === 'start') {
        hoodRequirePost();
        if (!$currentPlayer || empty($currentPlayer['is_host'])) {
            hoodRespond(403, ['success' => false, 'message' => 'Nur der Host kann das Spiel starten']);
        }
        $res = $service->startGame($roomId);
        hoodRespond(200, $res);
    }

    // Alle nachfolgenden Spielaktionen erfordern einen gültigen Spieler
    if (!$currentPlayer) {
        hoodRespond(401, ['success' => false, 'message' => 'Spieler nicht autorisiert']);
    }

    // 6. WÜRFELN
    if ($action === 'roll') {
        hoodRequirePost();
        $res = $service->rollDice($roomId, $currentPlayer['id']);
        hoodRespond(200, $res);
    }

    // 7. KAUFEN
    if ($action === 'buy') {
        hoodRequirePost();
        $res = $service->buyCurrentTile($roomId, $currentPlayer['id']);
        hoodRespond(200, $res);
    }

    // 8. KAUF ÜBERSPRINGEN
    if ($action === 'pass') {
        hoodRequirePost();
        $res = $service->passCurrentTile($roomId, $currentPlayer['id']);
        hoodRespond(200, $res);
    }

    // 9. HAUS / HOTEL BAUEN
    if ($action === 'build') {
        hoodRequirePost();
        $tileIndex = (int)($input['tile_index'] ?? -1);
        $res = $service->buildHouse($roomId, $currentPlayer['id'], $tileIndex);
        hoodRespond(200, $res);
    }

    // 10. HYPOTHEK TOGGELN
    if ($action === 'mortgage') {
        hoodRequirePost();
        $tileIndex = (int)($input['tile_index'] ?? -1);
        $res = $service->toggleMortgage($roomId, $currentPlayer['id'], $tileIndex);
        hoodRespond(200, $res);
    }

    // 11. KAUTION ZAHLEN
    if ($action === 'pay_bail') {
        hoodRequirePost();
        $res = $service->payBail($roomId, $currentPlayer['id']);
        hoodRespond(200, $res);
    }

    // 12. ZUG BEENDEN
    if ($action === 'end_turn') {
        hoodRequirePost();
        $res = $service->endTurn($roomId, $currentPlayer['id']);
        hoodRespond(200, $res);
    }

    hoodRespond(400, ['success' => false, 'message' => 'Unbekannte Aktion']);

} catch (\Throwable $e) {
    $logger->error('under_the_hood/api.php: Unerwarteter Fehler', ['error' => $e->getMessage()]);
    hoodRespond(500, ['success' => false, 'message' => 'Interner Serverfehler']);
}
