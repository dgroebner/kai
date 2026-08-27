<?php
// public/system/push_subscribe.php
// POST: Push-Subscription speichern oder löschen
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Push\PushSubscriptionRepository;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

Auth::requireApi();
Auth::requireMethod('POST');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    Auth::sendJsonError(400, 'Ungültige Anfrage.');
}

Auth::requireCsrfToken($input);

$action = $input['action'] ?? '';
if (!in_array($action, ['subscribe', 'unsubscribe'], true)) {
    Auth::sendJsonError(400, 'Ungültige Aktion.');
}

$endpoint = trim((string)($input['endpoint'] ?? ''));
if (empty($endpoint) || strlen($endpoint) > 2048) {
    Auth::sendJsonError(400, 'Ungültiger Endpoint.');
}

$userEmail = $_SESSION['user_email'] ?? '';
$repo = new PushSubscriptionRepository();

try {
    if ($action === 'subscribe') {
        $p256dh = trim((string)($input['p256dh'] ?? ''));
        $auth = trim((string)($input['auth'] ?? ''));

        if (empty($p256dh) || empty($auth)) {
            Auth::sendJsonError(400, 'Fehlende Schlüssel (p256dh / auth).');
        }

        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
        $repo->upsert($userEmail, $endpoint, $p256dh, $auth, $userAgent);

        echo json_encode(['success' => true, 'message' => 'Subscription gespeichert.']);
    } else {
        // unsubscribe
        $repo->deleteByEndpoint($endpoint);
        echo json_encode(['success' => true, 'message' => 'Subscription entfernt.']);
    }
} catch (Throwable $e) {
    new Logger()->error('push_subscribe.php: Fehler.', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler.');
}
