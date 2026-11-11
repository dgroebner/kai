<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\GamesHub\RoomRepository;
use Kai\Tools\GamesHub\UnderTheHood\HoodBot;
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
        $isHotSeat = !empty($input['is_hotseat']);

        $newPlayerToken = bin2hex(random_bytes(16));
        $newHostHash = hash('sha256', $newPlayerToken);

        $initialState = $service->createInitialState(['is_hotseat' => $isHotSeat]);
        $room = $repo->createRoom('under_the_hood', $isHotSeat ? 'hotseat' : 'standard', $newHostHash, $initialState);
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
        // Falls aktuell ein Bot an der Reihe ist (z.B. nach Pasch oder Reconnect), Bot-Zug automatisch vorantreiben
        $activePlayer = $state['players'][$state['current_player_index']] ?? null;
        if ($activePlayer && !empty($activePlayer['is_bot']) && empty($activePlayer['bankrupt']) && ($state['status'] ?? '') === 'active') {
            $bot = new HoodBot($service);
            $botRes = $bot->playTurn($roomId, (int)$activePlayer['id']);
            if (!empty($botRes['state'])) {
                $state = $botRes['state'];
                $updatedRoom = $repo->getRoomById($roomId);
                if ($updatedRoom) {
                    $room = $updatedRoom;
                }
            }
        }

        hoodRespond(200, [
            'success' => true,
            'state'   => $state,
            'me'      => $currentPlayer,
            'version' => $room['state_version'] ?? 1,
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

    // 4b. LOKALEN SPIELER HINZUFÜGEN (Hot-Seat-Modus)
    if ($action === 'add_local') {
        hoodRequirePost();
        if (!$currentPlayer || empty($currentPlayer['is_host'])) {
            hoodRespond(403, ['success' => false, 'message' => 'Nur der Host kann Spieler hinzufügen']);
        }
        $localName = mb_substr(trim((string)($input['player_name'] ?? ('Spieler ' . (count($state['players']) + 1)))), 0, 25);
        if ($localName === '') {
            $localName = 'Spieler ' . (count($state['players']) + 1);
        }
        $localVeh = trim((string)($input['vehicle'] ?? ''));
        $localColor = trim((string)($input['color'] ?? ''));
        $res = $service->addLocalPlayer($roomId, $localName, $localVeh, $localColor);
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

    // Im Hot-Seat-Modus agiert der Host stellvertretend für den aktuell aktiven lokalen Spieler
    $actingPlayerId = $currentPlayer['id'];
    if (!empty($state['is_hotseat']) && !empty($currentPlayer['is_host'])) {
        $activePlayer = $state['players'][$state['current_player_index']] ?? null;
        if ($activePlayer && !$activePlayer['is_bot']) {
            $actingPlayerId = $activePlayer['id'];
        }
    }

    // 6. WÜRFELN
    if ($action === 'roll') {
        hoodRequirePost();
        $res = $service->rollDice($roomId, $actingPlayerId);
        hoodRespond(200, $res);
    }

    // 7. KAUFEN
    if ($action === 'buy') {
        hoodRequirePost();
        $res = $service->buyCurrentTile($roomId, $actingPlayerId);
        hoodRespond(200, $res);
    }

    // 8. KAUF ÜBERSPRINGEN
    if ($action === 'pass') {
        hoodRequirePost();
        $res = $service->passCurrentTile($roomId, $actingPlayerId);
        hoodRespond(200, $res);
    }

    // 9. HAUS / HOTEL BAUEN
    if ($action === 'build') {
        hoodRequirePost();
        $tileIndex = (int)($input['tile_index'] ?? -1);
        $res = $service->buildHouse($roomId, $actingPlayerId, $tileIndex);
        hoodRespond(200, $res);
    }

    // 10. HYPOTHEK TOGGELN
    if ($action === 'mortgage') {
        hoodRequirePost();
        $tileIndex = (int)($input['tile_index'] ?? -1);
        $res = $service->toggleMortgage($roomId, $actingPlayerId, $tileIndex);
        hoodRespond(200, $res);
    }

    // 10b. HAUS AN DIE BANK VERKAUFEN
    if ($action === 'sell_building') {
        hoodRequirePost();
        $tileIndex = (int)($input['tile_index'] ?? -1);
        $res = $service->sellBuilding($roomId, $actingPlayerId, $tileIndex);
        hoodRespond(200, $res);
    }

    // 10c. GRUNDSTÜCK AN DIE BANK VERKAUFEN
    if ($action === 'sell_property') {
        hoodRequirePost();
        $tileIndex = (int)($input['tile_index'] ?? -1);
        $res = $service->sellProperty($roomId, $actingPlayerId, $tileIndex);
        hoodRespond(200, $res);
    }

    // 11. KAUTION ZAHLEN
    if ($action === 'pay_bail') {
        hoodRequirePost();
        $res = $service->payBail($roomId, $actingPlayerId);
        hoodRespond(200, $res);
    }

    // 12. ZUG BEENDEN
    if ($action === 'end_turn') {
        hoodRequirePost();
        $res = $service->endTurn($roomId, $actingPlayerId);
        hoodRespond(200, $res);
    }

    hoodRespond(400, ['success' => false, 'message' => 'Unbekannte Aktion']);

} catch (\Throwable $e) {
    $logger->error('under_the_hood/api.php: Unerwarteter Fehler', ['error' => $e->getMessage()]);
    hoodRespond(500, ['success' => false, 'message' => 'Interner Serverfehler']);
}
