<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Gamification\GamificationService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check
Auth::requireApi('gamification_read');

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
$gamifService = new GamificationService();
$profileRepo = $gamifService->getProfileRepository();
$isAdmin = Auth::hasPermission('gamification_admin');
$currentProfile = $profileRepo->getOrCreateProfile($currentUserEmail, $_SESSION['user_name'] ?? null, $isAdmin);

try {
    switch ($action) {
        // -------------------------------------------------------------
        // KINDER- & MITMACH-AKTIONEN
        // -------------------------------------------------------------
        case 'task_claim':
            Auth::requireApi('gamification_write');
            $taskId = filter_var($input['task_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$taskId) {
                Auth::sendJsonError(400, 'Ungültige Aufgaben-ID');
            }
            $success = $gamifService->getTaskRepository()->claimTask($taskId, (int)$currentProfile['id']);
            if (!$success) {
                Auth::sendJsonError(400, 'Diese Quest konnte nicht beansprucht werden (evtl. bereits vergeben oder abgelaufen).');
            }
            echo json_encode(['success' => true, 'message' => 'Quest erfolgreich gesichert! Du hast 12 Stunden Zeit.']);
            break;

        case 'task_release':
            Auth::requireApi('gamification_write');
            $taskId = filter_var($input['task_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$taskId) {
                Auth::sendJsonError(400, 'Ungültige Aufgaben-ID');
            }
            $success = $gamifService->getTaskRepository()->releaseClaim($taskId, (int)$currentProfile['id']);
            echo json_encode(['success' => $success, 'message' => 'Quest wieder für deine Geschwister freigegeben.']);
            break;

        case 'task_submit':
            Auth::requireApi('gamification_write');
            $taskId = filter_var($input['task_id'] ?? null, FILTER_VALIDATE_INT);
            $notes = isset($input['notes']) ? trim((string)$input['notes']) : null;
            if (!$taskId) {
                Auth::sendJsonError(400, 'Ungültige Aufgaben-ID');
            }
            $success = $gamifService->getTaskRepository()->submitTask($taskId, (int)$currentProfile['id'], $notes);
            if (!$success) {
                Auth::sendJsonError(400, 'Aufgabe konnte nicht eingereicht werden.');
            }
            echo json_encode(['success' => true, 'message' => 'Super gemacht! Die Aufgabe wurde zur Prüfung eingereicht.']);
            break;

        case 'helper_claim':
            Auth::requireApi('gamification_write');
            $taskId = filter_var($input['task_id'] ?? null, FILTER_VALIDATE_INT);
            $note = isset($input['note']) ? trim((string)$input['note']) : null;
            if (!$taskId) {
                Auth::sendJsonError(400, 'Ungültige Aufgaben-ID');
            }
            $claimId = $gamifService->getHelperRepository()->registerHelp($taskId, (int)$currentProfile['id'], $note);
            echo json_encode(['success' => true, 'helper_claim_id' => $claimId, 'message' => 'Mithilfe gemeldet! Deine Eltern bestätigen deinen Helfer-Bonus.']);
            break;

        case 'task_start_ondemand':
            Auth::requireApi('gamification_write');
            $tmplId = filter_var($input['template_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$tmplId) {
                Auth::sendJsonError(400, 'Ungültige Vorlagen-ID');
            }
            $notes = isset($input['notes']) ? trim((string)$input['notes']) : null;
            $submitImmediately = !empty($input['submit_immediately']);
            $status = $submitImmediately ? 'submitted' : 'in_progress';

            $taskId = $gamifService->getTemplateRepository()->instantiateTaskFromTemplate(
                $tmplId,
                date('Y-m-d'),
                (int)$currentProfile['id'],
                $status
            );

            if ($submitImmediately) {
                $gamifService->getTaskRepository()->submitTask($taskId, (int)$currentProfile['id'], $notes);
            }

            echo json_encode([
                'success' => true,
                'task_id' => $taskId,
                'message' => $submitImmediately
                    ? 'Super gemacht! Die Aufgabe wurde direkt zur Prüfung eingereicht.'
                    : 'Aufgabe gestartet! Du findest sie unter deinen aktiven Missionen.'
            ]);
            break;

        case 'reward_redeem':
            Auth::requireApi('gamification_write');
            $rewardId = filter_var($input['reward_id'] ?? null, FILTER_VALIDATE_INT);
            $note = isset($input['note']) ? trim((string)$input['note']) : null;
            if (!$rewardId) {
                Auth::sendJsonError(400, 'Ungültige Belohnungs-ID');
            }
            $res = $gamifService->getRewardRepository()->requestRedemption(
                $rewardId,
                (int)$currentProfile['id'],
                $note
            );
            if (!$res['success']) {
                Auth::sendJsonError(400, $res['message']);
            }
            echo json_encode($res);
            break;

        case 'profile_update':
            Auth::requireApi('gamification_write');
            $data = [];
            if (!empty($input['avatar_icon'])) {
                $data['avatar_icon'] = trim((string)$input['avatar_icon']);
            }
            if (!empty($input['color'])) {
                $data['color'] = trim((string)$input['color']);
            }
            if (isset($input['display_name'])) {
                $trimmedName = trim((string)$input['display_name']);
                if ($trimmedName !== '') {
                    $data['display_name'] = $trimmedName;
                }
            }
            if (isset($input['role']) && Auth::hasPermission('gamification_admin') && in_array($input['role'], ['parent', 'child'], true)) {
                $data['role'] = $input['role'];
            }
            if (Auth::hasPermission('gamification_admin')) {
                if (isset($input['streak_shields'])) {
                    $data['streak_shields'] = max(0, (int)$input['streak_shields']);
                }
                if (array_key_exists('streak_freeze_until', $input)) {
                    $data['streak_freeze_until'] = !empty($input['streak_freeze_until']) ? trim((string)$input['streak_freeze_until']) : null;
                }
                if (array_key_exists('streak_freeze_reason', $input)) {
                    $data['streak_freeze_reason'] = !empty($input['streak_freeze_reason']) ? trim((string)$input['streak_freeze_reason']) : null;
                }
            }

            $targetId = (int)$currentProfile['id'];
            if (!empty($input['profile_id']) && Auth::hasPermission('gamification_admin')) {
                $targetId = (int)$input['profile_id'];
            }

            $ok = $profileRepo->updateProfile($targetId, $data);
            echo json_encode(['success' => $ok, 'message' => 'Profil aktualisiert.']);
            break;

        case 'set_streak_freeze':
            Auth::requireApi('gamification_admin');
            $targetId = (int)($input['profile_id'] ?? 0);
            $until = !empty($input['until_date']) ? trim((string)$input['until_date']) : null;
            $reason = !empty($input['reason']) ? trim((string)$input['reason']) : null;
            $ok = $profileRepo->updateProfile($targetId, [
                'streak_freeze_until' => $until,
                'streak_freeze_reason' => $reason,
            ]);
            echo json_encode(['success' => $ok, 'message' => $until ? 'Pausenschutz aktiviert.' : 'Pausenschutz aufgehoben.']);
            break;

        case 'adjust_streak_shields':
            Auth::requireApi('gamification_admin');
            $targetId = (int)($input['profile_id'] ?? 0);
            $delta = (int)($input['delta'] ?? 0);
            $profileRepo->updateStreakShields($targetId, $delta);
            echo json_encode(['success' => true, 'message' => 'Streak-Schilde angepasst.']);
            break;

        // -------------------------------------------------------------
        // ELTERN-AKTIONEN (FREIGABEN, VORLAGEN, PRÄMIEN, ADMIN)
        // -------------------------------------------------------------
        case 'triage_review_task':
            Auth::requireApi('gamification_admin');
            $taskId = filter_var($input['task_id'] ?? null, FILTER_VALIDATE_INT);
            $subAction = in_array($input['sub_action'] ?? '', ['approve', 'reject'], true) ? $input['sub_action'] : 'approve';
            $feedback = isset($input['feedback']) ? trim((string)$input['feedback']) : null;
            $customXp = isset($input['custom_xp']) ? (int)$input['custom_xp'] : null;
            $customCoins = isset($input['custom_coins']) ? (int)$input['custom_coins'] : null;

            if (!$taskId) {
                Auth::sendJsonError(400, 'Ungültige Aufgaben-ID');
            }

            $task = $gamifService->getTaskRepository()->getTaskById($taskId);
            $recipientId = $task ? ((int)($task['claimed_by_profile_id'] ?: $task['assigned_profile_id'])) : null;

            $ok = $gamifService->getTaskRepository()->reviewTask($taskId, $subAction, $feedback, $customXp, $customCoins);
            if (!$ok) {
                Auth::sendJsonError(400, 'Aufgabe konnte nicht bearbeitet werden.');
            }

            // Neu freigeschaltete Abzeichen prüfen
            $newBadges = [];
            if ($subAction === 'approve' && $recipientId) {
                $newBadges = $gamifService->getAchievementService()->checkAndAwardAchievements($recipientId);
            }

            echo json_encode([
                'success' => true,
                'message' => $subAction === 'approve' ? 'Aufgabe genehmigt und Punkte gutgeschrieben!' : 'Aufgabe abgelehnt.',
                'new_achievements' => $newBadges,
            ]);
            break;

        case 'triage_review_helper':
            Auth::requireApi('gamification_admin');
            $helperId = filter_var($input['helper_id'] ?? null, FILTER_VALIDATE_INT);
            $approved = !empty($input['approved']);
            $coins = isset($input['custom_coins']) ? (int)$input['custom_coins'] : null;
            $xp = isset($input['custom_xp']) ? (int)$input['custom_xp'] : null;

            if (!$helperId) {
                Auth::sendJsonError(400, 'Ungültige Helfer-ID');
            }

            $ok = $gamifService->getHelperRepository()->reviewHelper($helperId, $approved, $coins, $xp);
            echo json_encode(['success' => $ok, 'message' => $approved ? 'Mithilfe bestätigt und Bonus ausgezahlt.' : 'Mithilfe abgelehnt.']);
            break;

        case 'triage_review_redemption':
            Auth::requireApi('gamification_admin');
            $redemptionId = filter_var($input['redemption_id'] ?? null, FILTER_VALIDATE_INT);
            $subAction = in_array($input['sub_action'] ?? '', ['approve', 'reject'], true) ? $input['sub_action'] : 'approve';
            $parentNote = isset($input['parent_note']) ? trim((string)$input['parent_note']) : null;

            if (!$redemptionId) {
                Auth::sendJsonError(400, 'Ungültige Antrags-ID');
            }

            $ok = $gamifService->getRewardRepository()->reviewRedemption($redemptionId, $subAction, $currentUserEmail, $parentNote);
            echo json_encode(['success' => $ok, 'message' => $subAction === 'approve' ? 'Prämie genehmigt!' : 'Antrag abgelehnt und Münzen erstattet.']);
            break;

        case 'template_save':
            Auth::requireApi('gamification_admin');
            $tmplId = $gamifService->getTemplateRepository()->saveTemplate($input);
            $spawnedTaskId = null;
            if (!empty($input['spawn_immediately'])) {
                $assignedId = isset($input['assigned_profile_id']) && $input['assigned_profile_id'] !== ''
                    ? (int)$input['assigned_profile_id']
                    : null;
                $spawnedTaskId = $gamifService->getTemplateRepository()->instantiateTaskFromTemplate(
                    $tmplId,
                    date('Y-m-d'),
                    $assignedId
                );
            }
            echo json_encode([
                'success' => true,
                'template_id' => $tmplId,
                'task_id' => $spawnedTaskId,
                'message' => $spawnedTaskId ? 'Vorlage gespeichert und Aufgabe sofort für heute aktiviert!' : 'Aufgaben-Vorlage gespeichert.'
            ]);
            break;

        case 'template_spawn_task':
            Auth::requireApi('gamification_admin');
            $tmplId = filter_var($input['template_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$tmplId) {
                Auth::sendJsonError(400, 'Ungültige Vorlagen-ID');
            }
            $targetDate = !empty($input['due_date']) ? trim((string)$input['due_date']) : date('Y-m-d');
            $assignedId = array_key_exists('assigned_profile_id', $input) && $input['assigned_profile_id'] !== ''
                ? (int)$input['assigned_profile_id']
                : null;
            $taskId = $gamifService->getTemplateRepository()->instantiateTaskFromTemplate($tmplId, $targetDate, $assignedId);
            echo json_encode([
                'success' => true,
                'task_id' => $taskId,
                'message' => 'Aufgabe wurde für heute erfolgreich aktiviert und ins Spiel gebracht!'
            ]);
            break;

        case 'template_delete':
            Auth::requireApi('gamification_admin');
            $tmplId = filter_var($input['template_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$tmplId) {
                Auth::sendJsonError(400, 'Ungültige Vorlagen-ID');
            }
            $ok = $gamifService->getTemplateRepository()->deleteTemplate($tmplId);
            echo json_encode(['success' => $ok, 'message' => 'Vorlage gelöscht.']);
            break;

        case 'task_delete':
            Auth::requireApi('gamification_admin');
            $taskId = filter_var($input['task_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$taskId) {
                Auth::sendJsonError(400, 'Ungültige Aufgaben-ID');
            }
            try {
                $ok = $gamifService->getTaskRepository()->deleteTask($taskId);
                echo json_encode(['success' => $ok, 'message' => $ok ? 'Aufgabe gelöscht.' : 'Aufgabe nicht gefunden.']);
            } catch (\Throwable $e) {
                (new Logger())->error('task_delete: Fehler', ['error' => $e->getMessage()]);
                Auth::sendJsonError(500, 'Interner Fehler');
            }
            break;

        case 'reward_save':
            Auth::requireApi('gamification_admin');
            $rewardId = $gamifService->getRewardRepository()->saveReward($input);
            echo json_encode(['success' => true, 'reward_id' => $rewardId, 'message' => 'Prämie gespeichert.']);
            break;

        case 'reward_delete':
            Auth::requireApi('gamification_admin');
            $rewardId = filter_var($input['reward_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$rewardId) {
                Auth::sendJsonError(400, 'Ungültige Prämien-ID');
            }
            $ok = $gamifService->getRewardRepository()->deleteReward($rewardId);
            echo json_encode(['success' => $ok, 'message' => 'Prämie entfernt.']);
            break;

        case 'achievement_save':
            Auth::requireApi('gamification_admin');
            $achId = $gamifService->getAchievementService()->saveAchievement($input);
            echo json_encode(['success' => true, 'achievement_id' => $achId, 'message' => 'Abzeichen-Regel gespeichert.']);
            break;

        case 'achievement_delete':
            Auth::requireApi('gamification_admin');
            $achId = filter_var($input['achievement_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$achId) {
                Auth::sendJsonError(400, 'Ungültige Abzeichen-ID');
            }
            $ok = $gamifService->getAchievementService()->deleteAchievement($achId);
            echo json_encode(['success' => $ok, 'message' => 'Abzeichen gelöscht.']);
            break;

        case 'profile_create':
            Auth::requireApi('gamification_admin');
            $name = trim((string)($input['display_name'] ?? ''));
            $email = trim((string)($input['user_email'] ?? ''));
            $role = in_array($input['role'] ?? '', ['child', 'parent'], true) ? $input['role'] : 'child';
            $avatar = trim((string)($input['avatar_icon'] ?? '⭐')) ?: '⭐';
            $color = trim((string)($input['color'] ?? '#3b82f6')) ?: '#3b82f6';
            $coins = max(0, (int)($input['initial_coins'] ?? 0));
            $xp = max(0, (int)($input['initial_xp'] ?? 0));

            if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Auth::sendJsonError(400, 'Bitte gib einen Namen und eine gültige E-Mail-Adresse an.');
            }

            $profileId = $profileRepo->createProfile($email, $name, $role, $avatar, $color, $coins, $xp);
            echo json_encode(['success' => true, 'profile_id' => $profileId, 'message' => "Mitspieler „{$name}“ erfolgreich angelegt!"]);
            break;

        case 'profile_adjust':
            Auth::requireApi('gamification_admin');
            $profileId = filter_var($input['profile_id'] ?? null, FILTER_VALIDATE_INT);
            $xpDelta = (int)($input['xp_delta'] ?? 0);
            $coinsDelta = (int)($input['coins_delta'] ?? 0);
            $reason = trim((string)($input['reason'] ?? 'Manuelle Korrektur durch Eltern'));

            if (!$profileId) {
                Auth::sendJsonError(400, 'Ungültige Profil-ID');
            }

            if ($coinsDelta < 0) {
                $profileRepo->deductCoins($profileId, abs($coinsDelta), $reason, 'manual_adjustment', null);
            }
            if ($xpDelta > 0 || $coinsDelta > 0) {
                $profileRepo->addXpAndCoins($profileId, max(0, $xpDelta), max(0, $coinsDelta), $reason, 'manual_adjustment', null);
            }
            echo json_encode(['success' => true, 'message' => 'Guthaben angepasst.']);
            break;

        case 'run_escalation':
            Auth::requireApi('gamification_admin');
            $gamifService->syncDailyState();
            echo json_encode(['success' => true, 'message' => 'Fristen abgeglichen und Tagesaufgaben erzeugt.']);
            break;

        default:
            Auth::sendJsonError(400, 'Unbekannte Aktion');
    }
} catch (\Throwable $e) {
    $logger->error('gamification/api.php: Fehler.', ['error' => $e->getMessage(), 'action' => $action]);
    Auth::sendJsonError(500, 'Interner Fehler bei der Verarbeitung');
}
