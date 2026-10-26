<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Projects\ProjectsEngine;
use Kai\Tools\Projects\ProjectsRepository;
use Kai\Tools\Projects\Security\ProjectsAuth;

header('Content-Type: application/json; charset=utf-8');

// 1. Authentifizierung erzwingen (Projects-Session)
ProjectsAuth::requireApi();

$userId = ProjectsAuth::getUserId();
$repo = new ProjectsRepository();
$engine = new ProjectsEngine($repo);

$action = $_GET['action'] ?? '';

// Profil abrufen
$profile = $repo->getUserProfile($userId);
$userState = $profile['state_code'] ?? 'SN';

// GET-Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'day_details') {
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $data = $engine->getDayDetails($userId, $date, $userState);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    if ($action === 'month_matrix') {
        $year = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT);
        $month = filter_var($_GET['month'] ?? date('n'), FILTER_VALIDATE_INT);
        $matrix = $engine->getMonthMatrix($userId, $year, $month, $userState);
        echo json_encode(['success' => true, 'data' => $matrix]);
        exit;
    }

    if ($action === 'projects') {
        $includeArchived = !empty($_GET['all']);
        $projects = $repo->getProjects($userId, $includeArchived);
        echo json_encode(['success' => true, 'data' => $projects]);
        exit;
    }

    if ($action === 'profile') {
        echo json_encode(['success' => true, 'data' => $profile]);
        exit;
    }

    ProjectsAuth::sendJsonError(400, 'Unbekannte GET-Aktion');
}

// POST-Aktionen (Erfordern CSRF-Token)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        ProjectsAuth::sendJsonError(400, 'Ungültige Nutzdaten');
    }
    ProjectsAuth::requireCsrfToken($input);

    $postAction = $input['action'] ?? '';

    // 1. Schnelleingabe / Ausnahme hinzufügen
    if ($postAction === 'add_exception') {
        $date = $input['date'] ?? date('Y-m-d');
        $shortCode = strtoupper(trim((string)($input['short_code'] ?? '')));
        $hours = (float)($input['hours'] ?? 0.0);
        $comment = trim((string)($input['comment'] ?? ''));

        if ($hours <= 0.0 || empty($shortCode)) {
            ProjectsAuth::sendJsonError(400, 'Kürzel und positive Stundenangabe erforderlich.');
        }

        $project = $repo->findProjectByShortCode($userId, $shortCode);
        if (!$project) {
            ProjectsAuth::sendJsonError(404, "Projekt mit Kürzel '{$shortCode}' nicht gefunden.");
        }

        // Tagessituation prüfen: Darf inkl. Abwesenheit und bestehenden Ausnahmen nicht über 8,0h steigen
        $dayDetails = $engine->getDayDetails($userId, $date, $userState);
        $alreadyUsed = $dayDetails['absence_hours'] + $dayDetails['exceptions_hours'];
        if (($alreadyUsed + $hours) > ProjectsEngine::DAILY_TARGET_HOURS + 0.001) {
            $maxAllowed = max(0.0, ProjectsEngine::DAILY_TARGET_HOURS - $alreadyUsed);
            ProjectsAuth::sendJsonError(400, "Tageslimit von 8,0h überschritten. Maximal noch {$maxAllowed}h verfügbar.");
        }

        $excId = $repo->addException($userId, (int)$project['id'], $date, $hours, $comment);
        $updatedDay = $engine->getDayDetails($userId, $date, $userState);

        echo json_encode(['success' => true, 'data' => $updatedDay, 'message' => "{$hours}h auf {$project['short_code']} gebucht."]);
        exit;
    }

    // 2. Ausnahme löschen
    if ($postAction === 'delete_exception') {
        $exceptionId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        $date = $input['date'] ?? date('Y-m-d');

        if (!$exceptionId) {
            ProjectsAuth::sendJsonError(400, 'Ungültige ID');
        }

        $repo->deleteException($userId, $exceptionId);
        $updatedDay = $engine->getDayDetails($userId, $date, $userState);

        echo json_encode(['success' => true, 'data' => $updatedDay, 'message' => 'Ausnahme entfernt.']);
        exit;
    }

    // 3. Hauptprojekt wechseln
    if ($postAction === 'set_default_project') {
        $projectId = filter_var($input['project_id'] ?? null, FILTER_VALIDATE_INT);
        $mode = $input['mode'] ?? 'permanent'; // 'permanent', 'today', 'until'
        $validFrom = $input['valid_from'] ?? date('Y-m-d');
        $validTo = $input['valid_to'] ?? null;

        if (!$projectId || !in_array($mode, ['permanent', 'today', 'until'], true)) {
            ProjectsAuth::sendJsonError(400, 'Ungültige Parameter für Hauptprojekt');
        }

        $repo->setDefaultProject($userId, $projectId, $mode, $validFrom, $validTo);
        $updatedDay = $engine->getDayDetails($userId, $validFrom, $userState);

        echo json_encode(['success' => true, 'data' => $updatedDay, 'message' => 'Hauptprojekt aktualisiert.']);
        exit;
    }

    // 4. Multitages-Abwesenheit buchen (Urlaub / Krankheit)
    if ($postAction === 'save_absence_range') {
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? $startDate;
        $type = in_array($input['type'] ?? '', ['vacation', 'sick', 'special_leave'], true) ? $input['type'] : 'vacation';
        $hours = (float)($input['hours'] ?? 8.0);
        $comment = trim((string)($input['comment'] ?? ''));

        if (!$startDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            ProjectsAuth::sendJsonError(400, 'Ungültiges Startdatum');
        }
        if (!$endDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $endDate = $startDate;
        }

        $startTs = strtotime($startDate);
        $endTs = strtotime($endDate);
        if ($startTs > $endTs) {
            [$startTs, $endTs] = [$endTs, $startTs];
        }

        $bookedDays = 0;
        $holidaySvc = new \Kai\Tools\Shared\Utils\HolidayService();

        for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
            $dStr = date('Y-m-d', $ts);
            $dow = (int)date('N', $ts);

            // Wochenenden und Feiertage automatisch auslassen
            if ($dow >= 6 || $holidaySvc->getPublicHoliday($dStr, $userState) !== null) {
                continue;
            }

            $repo->saveAbsence($userId, $dStr, $type, $hours, $comment);
            $bookedDays++;
        }

        echo json_encode([
            'success' => true,
            'booked_days' => $bookedDays,
            'message' => "{$bookedDays} Arbeitstag(e) als {$type} eingetragen."
        ]);
        exit;
    }

    // 5. Abwesenheit für einzelnen Tag löschen
    if ($postAction === 'delete_absence') {
        $date = $input['date'] ?? null;
        if (!$date) {
            ProjectsAuth::sendJsonError(400, 'Datum erforderlich');
        }
        $repo->deleteAbsence($userId, $date);
        $updatedDay = $engine->getDayDetails($userId, $date, $userState);

        echo json_encode(['success' => true, 'data' => $updatedDay, 'message' => 'Abwesenheit gelöscht.']);
        exit;
    }

    // 6. Projekt anlegen / bearbeiten
    if ($postAction === 'save_project') {
        $projectId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $name = trim((string)($input['name'] ?? ''));
        $shortCode = strtoupper(trim((string)($input['short_code'] ?? '')));
        $planiswareCode = trim((string)($input['planisware_code'] ?? ''));
        $color = $input['color'] ?? '#0284c7';

        if (empty($name) || empty($shortCode)) {
            ProjectsAuth::sendJsonError(400, 'Name und Kürzel sind Pflichtfelder.');
        }

        // Kürzel-Kollision mit anderem Projekt prüfen
        $existing = $repo->findProjectByShortCode($userId, $shortCode);
        if ($existing && ($projectId === null || (int)$existing['id'] !== $projectId)) {
            ProjectsAuth::sendJsonError(400, "Das Kürzel '{$shortCode}' wird bereits verwendet.");
        }

        $savedId = $repo->saveProject($userId, $name, $shortCode, $planiswareCode, $color, $projectId);
        $all = $repo->getProjects($userId, true);

        echo json_encode(['success' => true, 'data' => $all, 'saved_id' => $savedId, 'message' => 'Projekt gespeichert.']);
        exit;
    }

    // 7. Profil aktualisieren (Bundesland SN/HH, Name, Reminder)
    if ($postAction === 'save_profile') {
        $name = trim((string)($input['name'] ?? ''));
        $stateCode = in_array(strtoupper($input['state_code'] ?? ''), ['SN', 'HH'], true) ? strtoupper($input['state_code']) : 'SN';
        $reminderEnabled = !empty($input['reminder_enabled']);
        $reminderTime = $input['reminder_time'] ?? '16:30';

        $repo->updateProfile($userId, $name, $stateCode, $reminderEnabled, $reminderTime);
        $_SESSION['proj_user_name'] = $name;
        $_SESSION['proj_user_state'] = $stateCode;

        echo json_encode(['success' => true, 'message' => 'Profil aktualisiert.']);
        exit;
    }

    // 8. Logout
    if ($postAction === 'logout') {
        ProjectsAuth::logout();
        echo json_encode(['success' => true, 'redirect' => 'login.php']);
        exit;
    }

    // 9. Push Subscription speichern
    if ($postAction === 'save_push_subscription') {
        $endpoint = $input['endpoint'] ?? '';
        $p256dh = $input['keys']['p256dh'] ?? '';
        $auth = $input['keys']['auth'] ?? '';

        if (!empty($endpoint) && !empty($p256dh) && !empty($auth)) {
            $pushSvc = new \Kai\Tools\Projects\ProjectsPushService();
            $pushSvc->saveSubscription($userId, $endpoint, $p256dh, $auth, $_SERVER['HTTP_USER_AGENT'] ?? null);
            echo json_encode(['success' => true, 'message' => 'Push-Abo gespeichert.']);
            exit;
        }
        ProjectsAuth::sendJsonError(400, 'Ungültige Subscription-Daten');
    }

    ProjectsAuth::sendJsonError(400, 'Unbekannte POST-Aktion');
}
