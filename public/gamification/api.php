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

        case 'task_pitch':
            Auth::requireApi('gamification_write');
            $title = trim((string)($input['title'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            $category = trim((string)($input['category'] ?? 'haushalt'));
            $notes = trim((string)($input['notes'] ?? ''));
            if ($title === '') {
                Auth::sendJsonError(400, 'Bitte gib einen Titel für deine Hilfe ein.');
            }
            $newId = $gamifService->getTaskRepository()->createPitch(
                (int)$currentProfile['id'],
                $title,
                $description ?: null,
                $category,
                $notes ?: null
            );
            echo json_encode(['success' => true, 'task_id' => $newId, 'message' => 'Klasse Initiative! Deine Hilfe wurde eingereicht.']);
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

        case 'cooking_pitch':
            Auth::requireApi('gamification_write');
            $taskId = filter_var($input['task_id'] ?? null, FILTER_VALIDATE_INT);
            $recipeTitle = trim((string)($input['recipe_title'] ?? ''));
            $recipeDetails = trim((string)($input['recipe_details'] ?? ''));
            if (!$taskId || $recipeTitle === '') {
                Auth::sendJsonError(400, 'Bitte gib einen Gerichtsnamen an.');
            }
            $success = $gamifService->getTaskRepository()->updateCookingPitch(
                $taskId,
                (int)$currentProfile['id'],
                $recipeTitle,
                $recipeDetails ?: null
            );
            echo json_encode(['success' => $success, 'message' => 'Rezept-Vorschlag eingereicht!']);
            break;

        case 'rate_cooking':
            Auth::requireApi('gamification_write');
            $taskId = filter_var($input['task_id'] ?? null, FILTER_VALIDATE_INT);
            $stars = filter_var($input['stars'] ?? null, FILTER_VALIDATE_INT);
            $comment = isset($input['comment']) ? trim((string)$input['comment']) : null;
            if (!$taskId || !$stars || $stars < 1 || $stars > 5) {
                Auth::sendJsonError(400, 'Bitte wähle zwischen 1 und 5 Sternen.');
            }
            $success = $gamifService->getRatingRepository()->submitRating(
                $taskId,
                (int)$currentProfile['id'],
                $stars,
                $comment
            );
            echo json_encode(['success' => $success, 'message' => 'Danke für dein Feedback! Du hast +5 XP und +2 Münzen Kritiker-Bonus erhalten.']);
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
            if (!empty($input['display_name'])) {
                $data['display_name'] = trim((string)$input['display_name']);
            }

            $targetId = (int)$currentProfile['id'];
            if (!empty($input['profile_id']) && Auth::hasPermission('gamification_admin')) {
                $targetId = (int)$input['profile_id'];
            }

            $ok = $profileRepo->updateProfile($targetId, $data);
            echo json_encode(['success' => $ok, 'message' => 'Profil aktualisiert.']);
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

        case 'triage_review_cooking_pitch':
            Auth::requireApi('gamification_admin');
            $taskId = filter_var($input['task_id'] ?? null, FILTER_VALIDATE_INT);
            $approved = !empty($input['approved']);
            $feedback = isset($input['feedback']) ? trim((string)$input['feedback']) : null;

            if (!$taskId) {
                Auth::sendJsonError(400, 'Ungültige Aufgaben-ID');
            }

            $ok = $gamifService->getTaskRepository()->reviewCookingPitch($taskId, $approved, $feedback);
            echo json_encode(['success' => $ok, 'message' => $approved ? 'Gericht freigegeben!' : 'Rückmeldung an das Kind übermittelt.']);
            break;

        case 'template_save':
            Auth::requireApi('gamification_admin');
            $tmplId = $gamifService->getTemplateRepository()->saveTemplate($input);
            echo json_encode(['success' => true, 'template_id' => $tmplId, 'message' => 'Aufgaben-Vorlage gespeichert.']);
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
