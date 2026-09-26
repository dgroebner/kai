<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\ActivityLogRepository;
use Kai\Tools\System\BriefingService;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check: Mindestens angemeldeter Benutzer erforderlich
Auth::requireApi();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = filter_input(INPUT_GET, 'action', FILTER_DEFAULT)
    ?? filter_input(INPUT_POST, 'action', FILTER_DEFAULT);

try {
    // -------------------------------------------------------------------------
    // ACTION: Briefing-Payload abrufen
    // -------------------------------------------------------------------------
    if ($action === 'briefing') {
        $currentUserEmail = $_SESSION['user_email'] ?? '';
        if ($currentUserEmail === '') {
            Auth::sendJsonError(401, 'Nicht angemeldet');
        }

        $briefingService = new BriefingService();
        $payload = $briefingService->getBriefingForUser($currentUserEmail);

        echo json_encode([
            'success' => true,
            'data' => $payload,
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // ACTION: Briefing-Präferenzen speichern
    // -------------------------------------------------------------------------
    if ($action === 'save_briefing_preferences') {
        Auth::requireMethod('POST');
        $currentUserEmail = $_SESSION['user_email'] ?? '';
        if ($currentUserEmail === '') {
            Auth::sendJsonError(401, 'Nicht angemeldet');
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        Auth::requireCsrfToken($input);

        $preferences = $input['preferences'] ?? [];
        if (!is_array($preferences)) {
            Auth::sendJsonError(400, 'Ungültige Präferenzdaten');
        }

        $briefingService = new BriefingService();
        $briefingService->savePreferencesForUser($currentUserEmail, $preferences);

        echo json_encode(['success' => true]);
        exit;
    }

    // -------------------------------------------------------------------------
    // Standard-Aktivitäten-Log (erfordert weiterhin system_write)
    // -------------------------------------------------------------------------
    Auth::requireApi('system_write');

    if ($method === 'GET') {
        $lastId = filter_input(INPUT_GET, 'last_id', FILTER_VALIDATE_INT) ?? 0;

        $activityRepo = new ActivityLogRepository();
        $newEntries = $activityRepo->getEntriesAfter($lastId);

        echo json_encode([
            'success' => true,
            'activities' => $newEntries,
        ]);
        exit;
    }

    Auth::sendJsonError(405, 'Method not allowed');

} catch (Throwable $e) {
    (new Logger())->error('system/api.php: Interner Fehler', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler');
}