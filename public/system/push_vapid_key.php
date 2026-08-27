<?php
// public/system/push_vapid_key.php
// Liefert den VAPID Public Key für den Browser (kein Secret – Public Key ist öffentlich)
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

Auth::requireApi();
Auth::requireMethod('GET');

$publicKey = $_ENV['VAPID_PUBLIC_KEY'] ?? '';

if (empty($publicKey)) {
    Auth::sendJsonError(503, 'Web Push ist nicht konfiguriert.');
}

echo json_encode(['publicKey' => $publicKey]);
