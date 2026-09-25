<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Calendar\CalendarService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check: Mindestens Lese-Berechtigung erforderlich
Auth::requireApi('calendar_read');

// 2. HTTP-Methoden-Check
Auth::requireMethod('POST');

// 3. Input validieren & CSRF-Token prüfen
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    Auth::sendJsonError(400, 'Ungültige Anfrage');
}
Auth::requireCsrfToken($input);

$action = $input['action'] ?? '';
$currentUserEmail = $_SESSION['user_email'] ?? '';
$logger = new Logger();
$calendarService = new CalendarService();
$eventRepo = $calendarService->getEventRepository();

try {
    switch ($action) {
        case 'get_event':
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                Auth::sendJsonError(400, 'Ungültige Ereignis-ID');
            }
            $event = $eventRepo->getById($id);
            if (!$event) {
                Auth::sendJsonError(404, 'Ereignis nicht gefunden');
            }
            $details = $calendarService->calculateEventDetails($event);
            echo json_encode(['success' => true, 'event' => $event, 'details' => $details]);
            break;

        case 'save_event':
            Auth::requireApi('calendar_write');

            $title = trim((string)($input['title'] ?? ''));
            if ($title === '') {
                Auth::sendJsonError(400, 'Bitte einen Namen oder Titel für das Ereignis angeben.');
            }

            $eventType = (string)($input['event_type'] ?? 'birthday');
            $allowedTypes = ['birthday', 'anniversary', 'memorial', 'other'];
            if (!in_array($eventType, $allowedTypes, true)) {
                $eventType = 'birthday';
            }

            $day = filter_var($input['event_day'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 31]]);
            $month = filter_var($input['event_month'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
            if ($day === false || $month === false) {
                Auth::sendJsonError(400, 'Bitte einen gültigen Tag und Monat auswählen.');
            }

            $year = filter_var($input['event_year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900, 'max_range' => 2100]]);
            if ($year === false) {
                $year = null;
            }

            $category = trim((string)($input['category'] ?? 'Familie'));
            if ($category === '') {
                $category = 'Familie';
            }

            // Vorlauftage validieren
            $rawAdvance = $input['notify_days_advance'] ?? ['0', '1', '3'];
            if (is_array($rawAdvance)) {
                $advanceList = array_values(array_filter(array_map('intval', $rawAdvance), fn($v) => $v >= 0 && $v <= 90));
            } else {
                $advanceList = [0, 1, 3];
            }
            if (empty($advanceList)) {
                $advanceList = [0];
            }
            sort($advanceList);
            $notifyDaysAdvance = implode(',', $advanceList);

            // Empfänger validieren
            $recipients = $input['recipients'] ?? [];
            if (!is_array($recipients)) {
                $recipients = [];
            }
            $recipientEmails = [];
            foreach ($recipients as $em) {
                $cleaned = strtolower(trim((string)$em));
                if (filter_var($cleaned, FILTER_VALIDATE_EMAIL)) {
                    $recipientEmails[] = $cleaned;
                }
            }
            $recipientEmails = array_values(array_unique($recipientEmails));

            $notes = trim((string)($input['notes'] ?? ''));

            $eventId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

            $data = [
                'title' => $title,
                'event_type' => $eventType,
                'event_day' => $day,
                'event_month' => $month,
                'event_year' => $year,
                'category' => $category,
                'notify_days_advance' => $notifyDaysAdvance,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $currentUserEmail,
            ];

            if ($eventId) {
                $existing = $eventRepo->getById($eventId);
                if (!$existing) {
                    Auth::sendJsonError(404, 'Zu bearbeitendes Ereignis nicht gefunden.');
                }
                $eventRepo->update($eventId, $data, $recipientEmails);
                echo json_encode([
                    'success' => true,
                    'message' => 'Ereignis erfolgreich aktualisiert.',
                    'id' => $eventId,
                ]);
            } else {
                $newId = $eventRepo->create($data, $recipientEmails);
                echo json_encode([
                    'success' => true,
                    'message' => 'Ereignis erfolgreich angelegt.',
                    'id' => $newId,
                ]);
            }
            break;

        case 'delete_event':
            Auth::requireApi('calendar_write');

            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                Auth::sendJsonError(400, 'Ungültige Ereignis-ID');
            }
            $event = $eventRepo->getById($id);
            if (!$event) {
                Auth::sendJsonError(404, 'Ereignis nicht gefunden');
            }
            $eventRepo->delete($id);
            echo json_encode(['success' => true, 'message' => 'Ereignis erfolgreich gelöscht.']);
            break;

        case 'send_test_push':
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                Auth::sendJsonError(400, 'Ungültige Ereignis-ID');
            }
            $result = $calendarService->sendTestNotification($id, $currentUserEmail);
            echo json_encode($result);
            break;

        default:
            Auth::sendJsonError(400, 'Unbekannte Aktion');
    }
} catch (\Throwable $e) {
    $logger->error('public/calendar/api.php: Fehler bei API-Aktion.', ['action' => $action, 'error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Serverfehler.');
}
