<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Projects\Mail\ProjectsMailboxCleaner;
use Kai\Tools\Projects\Mail\ProjectsMailer;
use Kai\Tools\Projects\ProjectsAdminRepository;
use Kai\Tools\Projects\Security\ProjectsAuth;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// Nur für Kai-Super-Admin (Google OAuth Session)
ProjectsAuth::requireSuperAdminApi();
Auth::requireMethod('POST');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    ProjectsAuth::sendJsonError(400, 'Ungültige Nutzdaten');
}
Auth::requireCsrfToken($input);

$adminRepo = new ProjectsAdminRepository();
$action = $input['action'] ?? '';

if ($action === 'invite_user') {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $name = trim((string)($input['name'] ?? ''));
    $stateCode = $input['state_code'] ?? 'SN';
    $sendMail = !empty($input['send_mail']);

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ProjectsAuth::sendJsonError(400, 'Gültige E-Mail-Adresse erforderlich.');
    }

    $userId = $adminRepo->inviteUser($email, $name, $stateCode);

    if ($sendMail) {
        try {
            $mailer = new ProjectsMailer();
            $appUrl = ProjectsAuth::getAppUrl() . '/login.php';
            $mailer->sendInvitation($email, $appUrl, $name);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => true,
                'message' => "Nutzer gespeichert, aber E-Mail-Versand fehlgeschlagen: " . $e->getMessage(),
            ]);
            exit;
        }
    }

    echo json_encode(['success' => true, 'message' => "Nutzer {$email} erfolgreich eingeladen."]);
    exit;
}

if ($action === 'update_status') {
    $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
    $status = $input['status'] ?? '';

    if (!$userId || !in_array($status, ['invited', 'active', 'disabled'], true)) {
        ProjectsAuth::sendJsonError(400, 'Ungültige Parameter');
    }

    $adminRepo->updateStatus($userId, $status);
    echo json_encode(['success' => true, 'message' => 'Status aktualisiert.']);
    exit;
}

if ($action === 'delete_user') {
    $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId) {
        ProjectsAuth::sendJsonError(400, 'Ungültige User-ID');
    }

    $adminRepo->deleteUser($userId);
    echo json_encode(['success' => true, 'message' => 'Nutzer gelöscht.']);
    exit;
}

if ($action === 'purge_mailbox') {
    $cleaner = new ProjectsMailboxCleaner();
    $result = $cleaner->purgeMailbox();
    echo json_encode($result);
    exit;
}

ProjectsAuth::sendJsonError(400, 'Unbekannte Aktion');
