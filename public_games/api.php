<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Kai\Tools\GamesHub\RoomRepository;

header('Content-Type: application/json; charset=utf-8');

$action = trim((string)($_GET['action'] ?? ''));
$repo = new RoomRepository();

if ($action === 'lookup') {
    $code = strtoupper(trim((string)($_GET['code'] ?? '')));
    $room = $repo->getRoomByCode($code);
    if (!$room) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Raum nicht gefunden']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'room_code' => $room['room_code'],
        'game_type' => $room['game_type'] ?? 'qwixx',
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// REAKTION SENDEN (Quarxl Reaktions-Smilies mit Spam-Schutz)
// -----------------------------------------------------------------------------
if ($action === 'react') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = [];
    }

    $roomCode = strtoupper(trim((string)($input['room_code'] ?? $_GET['room'] ?? '')));
    $room = $repo->getRoomByCode($roomCode);
    if (!$room) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Raum nicht gefunden']);
        exit;
    }

    $playerToken = trim((string)($_SERVER['HTTP_X_PLAYER_TOKEN'] ?? ''));
    if ($playerToken === '') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
        exit;
    }

    $playerHash = hash('sha256', $playerToken);
    $player = $repo->getPlayerByToken((int)$room['id'], $playerHash);
    if (!$player) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Spieler nicht im Raum']);
        exit;
    }

    $emoji = trim((string)($input['emoji'] ?? ''));
    $res = $repo->addReaction((int)$room['id'], (int)$player['id'], (string)$player['player_name'], $emoji);

    if (empty($res['success'])) {
        if (($res['error'] ?? '') === 'spam_cooldown') {
            http_response_code(429);
        } else {
            http_response_code(400);
        }
    }

    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------------
// REAKTIONEN ABRUFEN (Polling)
// -----------------------------------------------------------------------------
if ($action === 'reactions') {
    $roomCode = strtoupper(trim((string)($_GET['room'] ?? '')));
    $room = $repo->getRoomByCode($roomCode);
    if (!$room) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Raum nicht gefunden']);
        exit;
    }

    $sinceId = (int)($_GET['since'] ?? 0);
    $reactions = $repo->getRecentReactions((int)$room['id'], $sinceId);

    echo json_encode([
        'success' => true,
        'reactions' => $reactions,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ungültige Aktion']);
