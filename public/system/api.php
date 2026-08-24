<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\ActivityLogRepository;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check (AGENTS.md)
Auth::requireApi();

// 2. HTTP-Methoden-Check (für Polling via GET oder POST – hier flexibel gehalten)
// Wenn du POST mit JSON-Body nutzt, kannst du Auth::requireMethod('POST') verwenden.
// Für einfaches Polling via Query-Parameter (?last_id=X) reicht oft auch GET oder ein angepasster POST-Empfang.
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $lastId = filter_input(INPUT_GET, 'last_id', FILTER_VALIDATE_INT) ?? 0;

        $activityRepo = new ActivityLogRepository();
        $newEntries = $activityRepo->getEntriesAfter($lastId);

        echo json_encode([
            'success' => true,
            'activities' => $newEntries
        ]);
        exit;
    }

    Auth::sendJsonError(405, 'Method not allowed');

} catch (Throwable $e) {
    new Logger()->error('system/api.php: Fehler beim Abrufen der Aktivitäten', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler');
}