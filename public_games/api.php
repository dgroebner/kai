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

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ungültige Aktion']);
