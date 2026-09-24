<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Gamification\GamificationService;
use Kai\Tools\Shared\Security\Auth;

// 1. Auth-Check: Nur für Eltern (Admin-Berechtigung)
Auth::requirePage('gamification_admin');

$gamifService = new GamificationService();
$gamifService->syncDailyState();

$profileRepo = $gamifService->getProfileRepository();
$taskRepo = $gamifService->getTaskRepository();
$templateRepo = $gamifService->getTemplateRepository();
$rewardRepo = $gamifService->getRewardRepository();
$achievementService = $gamifService->getAchievementService();
$helperRepo = $gamifService->getHelperRepository();

// Freigaben laden
$pendingTasks = $taskRepo->getPendingReviewTasks();
$pendingHelpers = $helperRepo->getPendingHelpers();
$pendingRedemptions = $rewardRepo->getPendingRedemptions();
$totalPending = count($pendingTasks) + count($pendingHelpers) + count($pendingRedemptions);

// Laufende Aufgaben aller Kinder für die Übersicht
$allActiveTasksFlat = $taskRepo->getAllActiveTasksByProfile();
$allBounties = $taskRepo->getAllBounties();
// Nach Profil-ID gruppieren
$activeTasksByProfile = [];
foreach ($allActiveTasksFlat as $t) {
    $pid = (int)$t['assigned_profile_id'];
    $activeTasksByProfile[$pid][] = $t;
}

// Stammdaten laden
$templates = $templateRepo->getAllTemplates();
$rewards = $rewardRepo->getAllRewards();
$achievements = $achievementService->getAllAchievements();
$profiles = $profileRepo->getAllProfiles();
$childProfiles = $profileRepo->getChildProfiles();
$systemUsers = (new \Kai\Tools\System\GroupRepository())->getAllUsers();

// Deutsche Bezeichnungen für Typen & Metriken
$rewardTypeMap = [
    'privilege' => 'Privileg / Freiheit',
    'voucher'   => 'Gutschein',
    'allowance' => 'Taschengeld-Zuschuss',
    'event'     => 'Ausflug / Erlebnis',
    'item'      => 'Gegenstand',
];

$metricTypeMap = [
    'rescue_count'       => 'Rettungen überfälliger Aufgaben',
    'task_count'         => 'Erledigte Aufgaben gesamt',
    'category_count'     => 'Aufgaben einer Kategorie',
    'streak_days'        => 'Zuverlässigkeits-Serie (Tage)',
    'initiative_count'   => 'Spontane Hilfen / Initiativen',
    'xp_total'           => 'Gesamte Erfahrungspunkte (XP)',
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= Auth::csrfToken() ?>">
    <title>Familien-Aufgaben: Eltern-Bereich 👑 — kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <div>
            <h1>Eltern-Übersicht 👑</h1>
            <p class="text-muted">Aufgaben freigeben, Vorlagen verwalten und Belohnungen pflegen</p>
        </div>
        <div class="page-header-actions">
            <button type="button" class="btn btn-outline js-sync-escalation-btn" title="Überfällige Aufgaben sofort prüfen und Tagesaufgaben vorbereiten">Fristen jetzt prüfen ⏱️</button>
            <a href="index.php" class="btn btn-outline">← Zur Kinder-Ansicht</a>
            <a href="../index.php" class="btn btn-outline">Hauptmenü</a>
        </div>
    </header>

    <!-- Reiter-Navigation -->
    <nav class="gamif-tabs">
        <button type="button" class="gamif-tab-btn active" data-tab="tab-approvals">
            Freigaben & Prüfung <?= $totalPending > 0 ? "({$totalPending})" : '' ?>
        </button>
        <button type="button" class="gamif-tab-btn" data-tab="tab-templates">
            Aufgaben-Vorlagen (<?= count($templates) ?>)
        </button>
        <button type="button" class="gamif-tab-btn" data-tab="tab-rewards">
            Prämien-Katalog (<?= count($rewards) ?>)
        </button>
        <button type="button" class="gamif-tab-btn" data-tab="tab-badges">
            Abzeichen & Meilensteine (<?= count($achievements) ?>)
        </button>
        <button type="button" class="gamif-tab-btn" data-tab="tab-accounts">
            Familien-Punktekonten
        </button>
    </nav>

    <!-- Reiter 1: Freigaben & Prüfung (Triage) -->
    <section id="tab-approvals" class="gamif-tab-content">

        <!-- Übersicht: Laufende Aufgaben aller Kinder -->
        <?php if (!empty($activeTasksByProfile)): ?>
            <div class="gamif-active-overview">
                <h3 class="gamif-section-title">📋 Laufende Aufgaben der Kinder</h3>
                <div class="gamif-active-grid">
                    <?php foreach ($childProfiles as $cp):
                        $pid = (int)$cp['id'];
                        $tasks = $activeTasksByProfile[$pid] ?? [];
                        $lvl = $profileRepo->calculateLevelProgress((int)$cp['xp']);
                    ?>
                        <div class="gamif-active-child-card">
                            <div class="gamif-active-child-header">
                                <span class="gamif-avatar" style="font-size:1.4rem; width:2rem; height:2rem;"><?= htmlspecialchars($cp['avatar_icon'] ?? '⭐', ENT_QUOTES, 'UTF-8') ?></span>
                                <div>
                                    <strong><?= htmlspecialchars($cp['display_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span class="text-muted" style="font-size:0.8rem; display:block;">Level <?= $lvl['level'] ?> · 🔥 <?= (int)$cp['streak_days'] ?> Tage · 🪙 <?= (int)$cp['coins'] ?></span>
                                </div>
                                <span class="gamif-active-count"><?= count($tasks) ?></span>
                            </div>
                            <?php if (empty($tasks)): ?>
                                <p class="gamif-active-empty">Alle Aufgaben erledigt 🎉</p>
                            <?php else: ?>
                                <ul class="gamif-active-task-list">
                                    <?php foreach ($tasks as $at):
                                        $isInProgress = $at['status'] === 'in_progress';
                                        $isOverdue = !empty($at['due_date']) && $at['due_date'] < date('Y-m-d');
                                    ?>
                                        <li class="gamif-active-task-item <?= $isInProgress ? 'gamif-active-task-item--active' : '' ?> <?= $isOverdue ? 'gamif-active-task-item--overdue' : '' ?>">
                                            <span class="gamif-active-task-name"><?= htmlspecialchars($at['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="gamif-active-task-meta">
                                                <?php if ($isInProgress): ?><span class="gamif-tag" style="font-size:0.7rem; padding:0.1rem 0.4rem;">▶ läuft</span><?php endif; ?>
                                                <?php if ($isOverdue): ?><span class="gamif-tag gamif-tag--rescue" style="font-size:0.7rem; padding:0.1rem 0.4rem;">⚠ überfällig</span><?php endif; ?>
                                                <?php if (!empty($at['due_time'])): ?><span class="text-muted" style="font-size:0.75rem;">bis <?= htmlspecialchars(substr($at['due_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?> Uhr</span><?php endif; ?>
                                                <button type="button" class="btn btn-outline btn-sm js-spawn-for-child-btn" style="padding:0.1rem 0.5rem; font-size:0.75rem;" data-profile-id="<?= $pid ?>" title="Weitere Aufgabe für <?= htmlspecialchars($cp['display_name'], ENT_QUOTES, 'UTF-8') ?> anlegen">+</button>
                                                <button type="button" class="btn btn-outline btn-sm js-delete-task-btn" style="padding:0.1rem 0.5rem; font-size:0.75rem; color:#ef4444;" data-task-id="<?= (int)$at['id'] ?>" data-title="<?= htmlspecialchars($at['title'], ENT_QUOTES, 'UTF-8') ?>" title="Aufgabe löschen">🗑️</button>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <div class="gamif-active-child-footer">
                                <button type="button" class="btn btn-outline btn-sm js-spawn-for-child-btn" data-profile-id="<?= $pid ?>" data-profile-name="<?= htmlspecialchars($cp['display_name'], ENT_QUOTES, 'UTF-8') ?>">▶️ Aufgabe anlegen</button>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($allBounties)): ?>
                        <div class="gamif-active-child-card" style="border: 2px solid var(--color-orange);">
                            <div class="gamif-active-child-header" style="background: rgba(249, 115, 22, 0.1);">
                                <span class="gamif-avatar" style="font-size:1.4rem; width:2rem; height:2rem;">🛡️</span>
                                <div>
                                    <strong>Schwarzes Brett</strong>
                                    <span class="text-muted" style="font-size:0.8rem; display:block;">Offene Bounties & gerettete Aufgaben</span>
                                </div>
                                <span class="gamif-active-count"><?= count($allBounties) ?></span>
                            </div>
                            <ul class="gamif-active-task-list">
                                <?php foreach ($allBounties as $at): 
                                    $isInProgress = $at['status'] === 'in_progress';
                                ?>
                                    <li class="gamif-active-task-item <?= $isInProgress ? 'gamif-active-task-item--active' : '' ?>">
                                        <span class="gamif-active-task-name"><?= htmlspecialchars($at['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="gamif-active-task-meta">
                                            <?php if ($isInProgress): ?><span class="gamif-tag" style="font-size:0.7rem; padding:0.1rem 0.4rem;">▶ reserviert von <?= htmlspecialchars($at['claimed_name'] ?? '?', ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                            <span class="gamif-tag gamif-tag--rescue" style="font-size:0.7rem; padding:0.1rem 0.4rem;">Von: <?= htmlspecialchars($at['origin_name'] ?? 'System', ENT_QUOTES, 'UTF-8') ?></span>
                                            <button type="button" class="btn btn-outline btn-sm js-delete-task-btn" style="padding:0.1rem 0.5rem; font-size:0.75rem; color:#ef4444;" data-task-id="<?= (int)$at['id'] ?>" data-title="<?= htmlspecialchars($at['title'], ENT_QUOTES, 'UTF-8') ?>" title="Aufgabe löschen">🗑️</button>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        <?php endif; ?>


        <?php if ($totalPending === 0): ?>
            <div class="gamif-empty-box" style="margin-top: 1rem;">
                <span class="gamif-empty-icon">☕</span>
                <h3>Keine offenen Freigaben</h3>
                <p>Aktuell warten keine Aufgaben, Mithilfen oder Prämien-Anträge auf deine Bestätigung.</p>
            </div>
        <?php else: ?>
            <!-- Eingereichte Aufgaben -->
            <?php if (!empty($pendingTasks)): ?>
                <h3>Eingereichte Aufgaben (<?= count($pendingTasks) ?>)</h3>
                <div style="margin-bottom: 2rem;">
                    <?php foreach ($pendingTasks as $t): 
                        $recipientName = $t['claimed_name'] ?: $t['assigned_name'] ?: $t['origin_name'] ?: 'Kind';
                        $isBounty = !empty($t['bounty_bonus_coins']);
                        $isPitch = !empty($t['initiative_bonus_coins']);
                    ?>
                        <div class="gamif-triage-card">
                            <div class="gamif-triage-header">
                                <div>
                                    <h4 style="margin:0; font-size:1.1rem;"><?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?></h4>
                                    <span class="text-muted" style="font-size:0.85rem;">
                                        Eingereicht von <strong><?= htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') ?></strong>
                                        <?php if (!empty($t['submitted_at'])): ?>
                                            am <?= date('d.m. H:i', strtotime($t['submitted_at'])) ?> Uhr
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div>
                                    <span class="gamif-stat-chip gamif-stat-chip--coins">🪙 <?= (int)$t['base_coins'] + (int)$t['bounty_bonus_coins'] + (int)$t['initiative_bonus_coins'] ?></span>
                                    <span class="gamif-stat-chip gamif-stat-chip--xp">⭐ <?= (int)$t['base_xp'] + (int)$t['bounty_bonus_xp'] ?></span>
                                </div>
                            </div>

                            <div class="gamif-card-meta">
                                <span class="gamif-tag"><?= htmlspecialchars(ucfirst($t['category']), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($isBounty): ?>
                                    <span class="gamif-tag gamif-tag--rescue">🔥 Gerettete Aufgabe (+<?= (int)$t['bounty_bonus_coins'] ?> Retter-Münzen)</span>
                                <?php endif; ?>
                                <?php if ($isPitch): ?>
                                    <span class="gamif-tag" style="background:rgba(59,130,246,0.2); color:#60a5fa;">💡 Spontane Mithilfe (+15 Initiative-Münzen)</span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($t['submission_notes'])): ?>
                                <p style="margin-top:0.5rem; background:var(--bg-main); padding:0.5rem; border-radius:var(--border-radius); font-size:0.9rem;">
                                    <strong>Notiz des Kinds:</strong> <?= htmlspecialchars($t['submission_notes'], ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            <?php endif; ?>

                            <div class="gamif-triage-actions">
                                <button type="button" class="btn btn-outline btn-sm js-triage-reject-btn" data-task-id="<?= (int)$t['id'] ?>" data-title="<?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?>">❌ Ablehnen / Nachbessern</button>
                                <button type="button" class="btn btn-primary btn-sm js-triage-approve-btn" data-task-id="<?= (int)$t['id'] ?>" data-title="<?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?>" data-recipient="<?= htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') ?>" data-coins="<?= (int)$t['base_coins'] + (int)$t['bounty_bonus_coins'] + (int)$t['initiative_bonus_coins'] ?>" data-xp="<?= (int)$t['base_xp'] + (int)$t['bounty_bonus_xp'] ?>">✅ Bestätigen & Punkte gutschreiben</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Gemeldete Geschwister-Mithilfe -->
            <?php if (!empty($pendingHelpers)): ?>
                <h3>Bestätigung für Geschwister-Mithilfe (<?= count($pendingHelpers) ?>)</h3>
                <div style="margin-bottom: 2rem;">
                    <?php foreach ($pendingHelpers as $h): ?>
                        <div class="gamif-triage-card">
                            <div class="gamif-triage-header">
                                <div>
                                    <strong><?= htmlspecialchars($h['helper_name'], ENT_QUOTES, 'UTF-8') ?></strong> hat mitgeholfen bei:
                                    <span class="text-blue">„<?= htmlspecialchars($h['task_title'], ENT_QUOTES, 'UTF-8') ?>“</span>
                                </div>
                                <div>
                                    <span class="gamif-stat-chip gamif-stat-chip--coins">🪙 +<?= (int)$h['bonus_coins'] ?></span>
                                </div>
                            </div>
                            <?php if (!empty($h['note'])): ?>
                                <p class="text-muted" style="font-size:0.9rem;">Mithilfe-Notiz: <?= htmlspecialchars($h['note'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                            <div class="gamif-triage-actions">
                                <button type="button" class="btn btn-outline btn-sm js-review-helper-btn" data-helper-id="<?= (int)$h['id'] ?>" data-helper-name="<?= htmlspecialchars($h['helper_name'], ENT_QUOTES, 'UTF-8') ?>" data-task-title="<?= htmlspecialchars($h['task_title'], ENT_QUOTES, 'UTF-8') ?>" data-coins="<?= (int)$h['bonus_coins'] ?>" data-action="reject">❌ Ablehnen</button>
                                <button type="button" class="btn btn-primary btn-sm js-review-helper-btn" data-helper-id="<?= (int)$h['id'] ?>" data-helper-name="<?= htmlspecialchars($h['helper_name'], ENT_QUOTES, 'UTF-8') ?>" data-task-title="<?= htmlspecialchars($h['task_title'], ENT_QUOTES, 'UTF-8') ?>" data-coins="<?= (int)$h['bonus_coins'] ?>" data-action="approve">✅ Mithilfe anerkennen (+<?= (int)$h['bonus_coins'] ?> Münzen)</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Anträge auf Belohnungen -->
            <?php if (!empty($pendingRedemptions)): ?>
                <h3>Prämien-Anträge (<?= count($pendingRedemptions) ?>)</h3>
                <div style="margin-bottom: 2rem;">
                    <?php foreach ($pendingRedemptions as $r): ?>
                        <div class="gamif-triage-card">
                            <div class="gamif-triage-header">
                                <div>
                                    <span style="font-size:1.5rem; margin-right:0.5rem;"><?= htmlspecialchars($r['reward_icon'] ?? '🎁', ENT_QUOTES, 'UTF-8') ?></span>
                                    <strong><?= htmlspecialchars($r['profile_name'], ENT_QUOTES, 'UTF-8') ?></strong> möchte einlösen:
                                    <strong class="text-blue">„<?= htmlspecialchars($r['reward_title'], ENT_QUOTES, 'UTF-8') ?>“</strong>
                                </div>
                                <div>
                                    <span class="gamif-stat-chip gamif-stat-chip--coins">🪙 <?= (int)$r['coin_cost'] ?> Münzen</span>
                                </div>
                            </div>
                            <?php if (!empty($r['request_note'])): ?>
                                <p class="text-muted" style="font-size:0.9rem;">Wunsch-Notiz: <?= htmlspecialchars($r['request_note'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                            <div class="gamif-triage-actions">
                                <button type="button" class="btn btn-outline btn-sm js-review-redemption-btn" data-redemption-id="<?= (int)$r['id'] ?>" data-profile-name="<?= htmlspecialchars($r['profile_name'], ENT_QUOTES, 'UTF-8') ?>" data-reward-title="<?= htmlspecialchars($r['reward_title'], ENT_QUOTES, 'UTF-8') ?>" data-reward-icon="<?= htmlspecialchars($r['reward_icon'] ?? '🎁', ENT_QUOTES, 'UTF-8') ?>" data-cost="<?= (int)$r['coin_cost'] ?>" data-note="<?= htmlspecialchars($r['request_note'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-action="reject">❌ Ablehnen (Münzen erstatten)</button>
                                <button type="button" class="btn btn-primary btn-sm js-review-redemption-btn" data-redemption-id="<?= (int)$r['id'] ?>" data-profile-name="<?= htmlspecialchars($r['profile_name'], ENT_QUOTES, 'UTF-8') ?>" data-reward-title="<?= htmlspecialchars($r['reward_title'], ENT_QUOTES, 'UTF-8') ?>" data-reward-icon="<?= htmlspecialchars($r['reward_icon'] ?? '🎁', ENT_QUOTES, 'UTF-8') ?>" data-cost="<?= (int)$r['coin_cost'] ?>" data-note="<?= htmlspecialchars($r['request_note'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-action="approve">✅ Genehmigen</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Reiter 2: Aufgaben-Vorlagen verwalten -->
    <section id="tab-templates" class="gamif-tab-content hidden">
        <div class="gamif-filter-bar">
            <div class="gamif-filter-group">
                <select id="filter-tmpl-category" class="form-control form-control--sm" title="Nach Kategorie filtern">
                    <option value="">Alle Kategorien</option>
                    <option value="haushalt">Haushalt & Küche</option>
                    <option value="tiere">Tiere & Fütterung</option>
                    <option value="zimmer">Zimmer & Ordnung</option>
                    <option value="garten">Garten</option>
                </select>
                <select id="filter-tmpl-assigned" class="form-control form-control--sm" title="Nach Zuweisung filtern">
                    <option value="">Alle Zuweisungen</option>
                    <option value="0">Schwarzes Brett</option>
                    <?php foreach ($childProfiles as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['display_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filter-tmpl-recurrence" class="form-control form-control--sm" title="Nach Wiederholung filtern">
                    <option value="">Alle Typen</option>
                    <option value="none">Nur manuell</option>
                    <option value="daily">Täglich</option>
                    <option value="weekly">Wöchentlich</option>
                </select>
                <button type="button" id="btn-filter-tmpl-reset" class="btn btn-outline btn-sm">✕ Zurücksetzen</button>
            </div>
            <div class="gamif-filter-actions">
                <button type="button" class="btn btn-outline btn-sm js-open-spawn-quick-btn" title="Vorlage sofort als Aufgabe aktivieren">▶️ Aufgabe starten</button>
                <button type="button" class="btn btn-primary btn-sm js-open-template-modal">+ Neue Vorlage</button>
            </div>
        </div>
        <p id="filter-tmpl-empty" class="text-muted text-center" style="display:none; margin-top:1rem;">Keine Vorlagen entsprechen dem Filter.</p>

        <div class="table-responsive">
            <table class="table" id="tbl-templates">
                <thead>
                    <tr>
                        <th>Titel</th>
                        <th>Kategorie</th>
                        <th>Wiederholung</th>
                        <th>Frist</th>
                        <th>Zuweisung</th>
                        <th>Belohnung</th>
                        <th>Status</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($templates)): ?>
                        <tr><td colspan="8" class="text-center text-muted">Noch keine Vorlagen angelegt.</td></tr>
                    <?php else: ?>
                        <?php foreach ($templates as $tmpl): ?>
                            <tr data-tmpl-category="<?= htmlspecialchars($tmpl['category'], ENT_QUOTES, 'UTF-8') ?>"
                                data-tmpl-assigned="<?= (int)($tmpl['assigned_profile_id'] ?? 0) ?>"
                                data-tmpl-recurrence="<?= htmlspecialchars($tmpl['recurrence'], ENT_QUOTES, 'UTF-8') ?>">
                                <td>
                                    <div class="gamif-title-cell">
                                        <strong><?= htmlspecialchars($tmpl['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <?php if (isset($tmpl['can_escalate']) && (int)$tmpl['can_escalate'] === 0): ?>
                                            <div class="gamif-tag-row">
                                                <span class="gamif-tag gamif-tag--no-rescue" data-gamif-title="📌 Keine Rettung" data-gamif-tooltip="Feste Routine: Bleibt fest beim Kind und wandert bei Fristversäumnis nicht auf das Schwarze Brett. Geschwister können sie nicht als Belohnung übernehmen.">📌 Keine Rettung</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars(ucfirst($tmpl['category']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php 
                                    $recMap = ['none' => 'Einmalig', 'daily' => 'Täglich', 'weekly' => 'Wöchentlich', 'interval' => 'Intervall'];
                                    $recText = $recMap[$tmpl['recurrence']] ?? $tmpl['recurrence'];
                                    if ($tmpl['recurrence'] === 'weekly' && !empty($tmpl['recurrence_days'])) {
                                        $dayNames = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
                                        $activeDays = array_map(fn($d) => $dayNames[(int)$d] ?? $d, array_filter(array_map('trim', explode(',', $tmpl['recurrence_days']))));
                                        if (!empty($activeDays)) {
                                            $recText .= ' (' . implode(', ', $activeDays) . ')';
                                        }
                                    }
                                    echo htmlspecialchars($recText, ENT_QUOTES, 'UTF-8');
                                    ?>
                                </td>
                                <td><?= !empty($tmpl['due_time']) ? htmlspecialchars(substr($tmpl['due_time'], 0, 5), ENT_QUOTES, 'UTF-8') . ' Uhr' : 'Keine' ?></td>
                                <td><?= !empty($tmpl['assigned_name']) ? htmlspecialchars($tmpl['assigned_name'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">Schwarzes Brett</span>' ?></td>
                                <td>
                                    <div class="gamif-reward-cell">
                                        <span class="gamif-reward-row text-warning"><strong>🪙 +<?= (int)$tmpl['base_coins'] ?></strong> <span class="text-muted">Münzen</span></span>
                                        <span class="gamif-reward-row text-info"><strong>⭐ +<?= (int)$tmpl['base_xp'] ?></strong> <span class="text-muted">XP</span></span>
                                    </div>
                                </td>
                                <td><?= (int)$tmpl['is_active'] === 1 ? '<span class="text-success">Aktiv</span>' : '<span class="text-muted">Inaktiv</span>' ?></td>
                                <td>
                                    <button type="button" class="btn btn-outline btn-sm js-spawn-template-btn" data-template-id="<?= (int)$tmpl['id'] ?>" data-title="<?= htmlspecialchars($tmpl['title'], ENT_QUOTES, 'UTF-8') ?>" data-assigned-id="<?= (int)($tmpl['assigned_profile_id'] ?? 0) ?>" title="Jetzt für heute als Aufgabe anlegen">▶️</button>
                                    <button type="button" class="btn btn-outline btn-sm js-edit-template-btn" data-template='<?= htmlspecialchars(json_encode($tmpl), ENT_QUOTES, 'UTF-8') ?>' title="Bearbeiten">✏️</button>
                                    <button type="button" class="btn btn-outline btn-sm js-delete-template-btn" data-template-id="<?= (int)$tmpl['id'] ?>" data-title="<?= htmlspecialchars($tmpl['title'], ENT_QUOTES, 'UTF-8') ?>" title="Löschen">🗑️</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Reiter 3: Prämien-Katalog -->
    <section id="tab-rewards" class="gamif-tab-content hidden">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3>Prämien & Belohnungen</h3>
            <button type="button" class="btn btn-primary btn-sm js-open-reward-modal">➕ Neue Prämie anlegen</button>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Icon</th>
                        <th>Titel & Beschreibung</th>
                        <th>Kosten</th>
                        <th>Typ</th>
                        <th>Wartezeit (Tage)</th>
                        <th>Status</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rewards)): ?>
                        <tr><td colspan="7" class="text-center text-muted">Noch keine Prämien definiert.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rewards as $rew): ?>
                            <tr>
                                <td style="font-size:1.5rem;"><?= htmlspecialchars($rew['icon'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($rew['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if (!empty($rew['description'])): ?>
                                        <div class="text-muted" style="font-size:0.85rem;"><?= htmlspecialchars($rew['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><strong class="text-warning">🪙 <?= (int)$rew['coin_cost'] ?></strong></td>
                                <td><span class="gamif-tag"><?= htmlspecialchars($rewardTypeMap[$rew['type']] ?? ucfirst($rew['type']), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= (int)$rew['cooldown_days'] > 0 ? (int)$rew['cooldown_days'] . ' Tage' : 'Keine' ?></td>
                                <td><?= (int)$rew['is_active'] === 1 ? '<span class="text-success">Aktiv</span>' : '<span class="text-muted">Pausiert</span>' ?></td>
                                <td>
                                    <button type="button" class="btn btn-outline btn-sm js-edit-reward-btn" data-reward='<?= htmlspecialchars(json_encode($rew), ENT_QUOTES, 'UTF-8') ?>' title="Bearbeiten">✏️</button>
                                    <button type="button" class="btn btn-outline btn-sm js-delete-reward-btn" data-reward-id="<?= (int)$rew['id'] ?>" data-title="<?= htmlspecialchars($rew['title'], ENT_QUOTES, 'UTF-8') ?>" title="Löschen">🗑️</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Reiter 4: Abzeichen & Meilensteine -->
    <section id="tab-badges" class="gamif-tab-content hidden">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3>Erfolgs-Regeln & Abzeichen</h3>
            <button type="button" class="btn btn-primary btn-sm js-open-badge-modal">➕ Neues Abzeichen anlegen</button>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Icon</th>
                        <th>Titel & Beschreibung</th>
                        <th>Bedingung</th>
                        <th>Belohnung</th>
                        <th>Status</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($achievements)): ?>
                        <tr><td colspan="6" class="text-center text-muted">Noch keine Abzeichen definiert.</td></tr>
                    <?php else: ?>
                        <?php foreach ($achievements as $ach): 
                            $conditionText = '';
                            $target = (int)$ach['metric_target'];
                            switch ($ach['metric_type']) {
                                case 'rescue_count':
                                    $conditionText = "Mind. {$target}× überfällige Aufgabe retten";
                                    break;
                                case 'task_count':
                                    $conditionText = "Mind. {$target} Aufgaben erledigen";
                                    break;
                                case 'category_count':
                                    $cat = ucfirst($ach['metric_parameter'] ?? 'Haushalt');
                                    $conditionText = "Mind. {$target} Aufgaben in „{$cat}“";
                                    break;
                                case 'streak_days':
                                    $conditionText = "Mind. {$target} Tage Serie ohne Fristversäumnis";
                                    break;
                                case 'initiative_count':
                                    $conditionText = "Mind. {$target}× eigene Spontan-Hilfe einreichen";
                                    break;
                                case 'xp_total':
                                    $conditionText = "Mind. {$target} Erfahrungspunkte (XP) erreichen";
                                    break;
                                default:
                                    $conditionText = ($metricTypeMap[$ach['metric_type']] ?? $ach['metric_type']) . ": {$target}";
                            }
                        ?>
                            <tr>
                                <td style="font-size:1.5rem;"><?= htmlspecialchars($ach['icon'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($ach['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="text-muted" style="font-size:0.85rem;"><?= htmlspecialchars($ach['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($conditionText, ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="text-muted" style="font-size:0.8rem;"><?= htmlspecialchars($metricTypeMap[$ach['metric_type']] ?? $ach['metric_type'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td>
                                    <div class="gamif-reward-cell">
                                        <span class="gamif-reward-row text-warning"><strong>🪙 +<?= (int)$ach['reward_coins'] ?></strong> <span class="text-muted">Münzen</span></span>
                                        <span class="gamif-reward-row text-info"><strong>⭐ +<?= (int)$ach['reward_xp'] ?></strong> <span class="text-muted">XP</span></span>
                                    </div>
                                </td>
                                <td><?= (int)$ach['is_active'] === 1 ? '<span class="text-success">Aktiv</span>' : '<span class="text-muted">Inaktiv</span>' ?></td>
                                <td>
                                    <button type="button" class="btn btn-outline btn-sm js-edit-badge-btn" data-badge='<?= htmlspecialchars(json_encode($ach), ENT_QUOTES, 'UTF-8') ?>' title="Bearbeiten">✏️</button>
                                    <button type="button" class="btn btn-outline btn-sm js-delete-badge-btn" data-badge-id="<?= (int)$ach['id'] ?>" data-title="<?= htmlspecialchars($ach['title'], ENT_QUOTES, 'UTF-8') ?>" title="Löschen">🗑️</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Reiter 5: Punktekonten & Familie -->
    <section id="tab-accounts" class="gamif-tab-content hidden">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
            <div>
                <h3 style="margin:0;">Punktekonten der Familienmitglieder</h3>
                <p class="text-muted" style="margin:0;">Hier kannst du Punktestände und Münzguthaben einsehen oder bei Bedarf korrigieren.</p>
            </div>
            <button type="button" class="btn btn-primary btn-sm js-open-create-profile-modal">➕ Mitspieler anlegen</button>
        </div>

        <div class="gamif-grid" style="margin-top:1rem;">
            <?php foreach ($profiles as $p): 
                $lvlProg = $profileRepo->calculateLevelProgress((int)$p['xp']);
            ?>
                <div class="gamif-card">
                    <div style="display:flex; align-items:center; justify-content:space-between;">
                        <div style="display:flex; align-items:center; gap:0.75rem;">
                            <span class="gamif-avatar gamif-avatar--clickable js-change-profile-avatar" data-profile-id="<?= (int)$p['id'] ?>" data-name="<?= htmlspecialchars($p['display_name'], ENT_QUOTES, 'UTF-8') ?>" title="Symbol ändern" style="font-size:2rem; width:2.8rem; height:2.8rem; display:inline-flex; align-items:center; justify-content:center;"><?= htmlspecialchars($p['avatar_icon'] ?? '⭐', ENT_QUOTES, 'UTF-8') ?></span>
                            <div>
                                <h4 style="margin:0;"><?= htmlspecialchars($p['display_name'], ENT_QUOTES, 'UTF-8') ?></h4>
                                <span class="text-muted" style="font-size:0.85rem;">Level <?= $lvlProg['level'] ?> · <?= htmlspecialchars($lvlProg['rank_title'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                        <span class="gamif-tag <?= $p['role'] === 'parent' ? 'gamif-tag--rescue' : '' ?>"><?= $p['role'] === 'parent' ? '👑 Eltern' : 'Kind' ?></span>
                    </div>
                    <div style="display:flex; flex-wrap:wrap; gap:0.4rem; margin-top:0.5rem;">
                        <span class="gamif-stat-chip gamif-stat-chip--coins">🪙 <?= (int)$p['coins'] ?> Münzen</span>
                        <span class="gamif-stat-chip gamif-stat-chip--streak">🔥 <?= (int)$p['streak_days'] ?> Tage</span>
                        <span class="gamif-stat-chip gamif-stat-chip--xp">⭐ <?= (int)$p['xp'] ?> XP</span>
                        <?php if ((int)($p['streak_shields'] ?? 0) > 0): ?>
                            <span class="gamif-stat-chip gamif-stat-chip--shield" data-gamif-title="🛡️ Streak-Schilde" data-gamif-tooltip="Schützt die Serie automatisch vor Säumnis (noch <?= (int)$p['streak_shields'] ?> verfügbar).">🛡️ <?= (int)$p['streak_shields'] ?></span>
                        <?php endif; ?>
                        <?php if (!empty($p['streak_freeze_until']) && $p['streak_freeze_until'] >= date('Y-m-d')): ?>
                            <span class="gamif-tag" style="background:rgba(59,130,246,0.2); color:#60a5fa;" data-gamif-title="🏖️ Urlaubs-Pausenschutz" data-gamif-tooltip="Serie ist pausiert und vor Säumnis geschützt bis <?= htmlspecialchars(date('d.m.Y', strtotime($p['streak_freeze_until'])), ENT_QUOTES, 'UTF-8') ?><?= !empty($p['streak_freeze_reason']) ? ' (' . htmlspecialchars($p['streak_freeze_reason'], ENT_QUOTES, 'UTF-8') . ')' : '' ?>.">🏖️ Pause bis <?= htmlspecialchars(date('d.m.', strtotime($p['streak_freeze_until'])), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="margin-top:0.75rem; padding-top:0.75rem; border-top:1px solid rgba(255,255,255,0.05); display:flex; justify-content:flex-end; gap:0.5rem;">
                        <button type="button" class="btn btn-outline btn-sm js-edit-profile-btn" data-profile="<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>">✏️ Bearbeiten</button>
                        <button type="button" class="btn btn-outline btn-sm js-adjust-points-btn" data-profile-id="<?= (int)$p['id'] ?>" data-name="<?= htmlspecialchars($p['display_name'], ENT_QUOTES, 'UTF-8') ?>">🪙 Punkte</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Modal: Aufgabe löschen (Admin / Test-Modus) -->
    <div id="modal-delete-task" class="modal-overlay hidden">
        <div class="modal-card modal-card--sm">
            <div class="modal-header">
                <h3>🗑️ Aufgabe löschen</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <p>Soll die Aufgabe <strong id="delete-task-title"></strong> wirklich unwiderruflich gelöscht werden?</p>
                <input type="hidden" id="delete-task-id">
                <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                    <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                    <button type="button" id="btn-confirm-delete-task" class="btn btn-primary" style="background:#ef4444; border-color:#ef4444;">🗑️ Löschen</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Vorlage erstellen / bearbeiten -->
    <div id="modal-template" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modal-template-heading">Aufgaben-Vorlage</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-template">
                    <input type="hidden" id="template-id" name="id">
                    <div class="form-group">
                        <label for="tmpl-title">Titel der Aufgabe:</label>
                        <input type="text" id="tmpl-title" name="title" class="form-control" required placeholder="z. B. Spülmaschine ausräumen">
                    </div>
                    <div class="form-group">
                        <label for="tmpl-desc">Beschreibung (optional):</label>
                        <textarea id="tmpl-desc" name="description" class="form-control" rows="2" placeholder="Was ist genau zu tun?"></textarea>
                    </div>
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="tmpl-category">Kategorie:</label>
                            <select id="tmpl-category" name="category" class="form-control">
                                <option value="haushalt">Haushalt & Küche</option>
                                <option value="tiere">Tiere & Fütterung</option>
                                <option value="zimmer">Zimmer & Ordnung</option>
                                <option value="garten">Garten</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="tmpl-assigned">Feste Zuweisung:</label>
                            <select id="tmpl-assigned" name="assigned_profile_id" class="form-control">
                                <option value="">Keine (Offenes Schwarzes Brett)</option>
                                <?php foreach ($childProfiles as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['display_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="tmpl-recurrence">Wiederholung:</label>
                            <select id="tmpl-recurrence" name="recurrence" class="form-control">
                                <option value="none">Nur manuell</option>
                                <option value="daily">Täglich</option>
                                <option value="weekly">Bestimmte Wochentage</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="tmpl-duetime">Frist / Uhrzeit:</label>
                            <input type="time" id="tmpl-duetime" name="due_time" class="form-control" value="18:00">
                        </div>
                    </div>
                    <div class="form-group" id="group-recurrence-days" style="display:none;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Wiederholen an Wochentagen:</label>
                        <div class="gamif-weekday-picker">
                            <label class="gamif-weekday-pill">
                                <input type="checkbox" name="recurrence_day_check" value="1">
                                <span>Mo</span>
                            </label>
                            <label class="gamif-weekday-pill">
                                <input type="checkbox" name="recurrence_day_check" value="2">
                                <span>Di</span>
                            </label>
                            <label class="gamif-weekday-pill">
                                <input type="checkbox" name="recurrence_day_check" value="3">
                                <span>Mi</span>
                            </label>
                            <label class="gamif-weekday-pill">
                                <input type="checkbox" name="recurrence_day_check" value="4">
                                <span>Do</span>
                            </label>
                            <label class="gamif-weekday-pill">
                                <input type="checkbox" name="recurrence_day_check" value="5">
                                <span>Fr</span>
                            </label>
                            <label class="gamif-weekday-pill">
                                <input type="checkbox" name="recurrence_day_check" value="6">
                                <span>Sa</span>
                            </label>
                            <label class="gamif-weekday-pill">
                                <input type="checkbox" name="recurrence_day_check" value="7">
                                <span>So</span>
                            </label>
                        </div>
                    </div>
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="tmpl-coins">Belohnungs-Münzen:</label>
                            <input type="number" id="tmpl-coins" name="base_coins" class="form-control" value="20" min="1">
                        </div>
                        <div class="form-group">
                            <label for="tmpl-xp">Erfahrungspunkte (XP):</label>
                            <input type="number" id="tmpl-xp" name="base_xp" class="form-control" value="50" min="1">
                        </div>
                    </div>
                    <div class="gamif-checkbox-group">
                        <label class="gamif-checkbox-row" for="tmpl-escalate">
                            <input type="checkbox" id="tmpl-escalate" name="can_escalate" value="1" checked>
                            <span>Verschieben auf Schwarzes Brett bei Fristversäumnis</span>
                        </label>
                        <label class="gamif-checkbox-row" for="tmpl-spawn-now">
                            <input type="checkbox" id="tmpl-spawn-now" name="spawn_immediately" value="1">
                            <span>🚀 Sofort für heute als aktive Aufgabe starten</span>
                        </label>
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Vorlage speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Vorlage als Aufgabe starten -->
    <div id="modal-spawn-template" class="modal-overlay hidden">
        <div class="modal-card modal-card--sm">
            <div class="modal-header">
                <h3>Aufgabe aktivieren ▶️</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-spawn-template">
                    <input type="hidden" id="spawn-template-id" name="template_id">
                    <p id="spawn-template-desc" style="font-size:0.95rem; margin-bottom:1rem;"></p>
                    <!-- Vorlagen-Auswahl: nur im Schnell-Spawn-Modus sichtbar -->
                    <div class="form-group" id="group-spawn-template-select" style="display:none;">
                        <label for="spawn-template-select">Vorlage auswählen:</label>
                        <select id="spawn-template-select" class="form-control">
                            <option value="">— Bitte wählen —</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"
                                    data-assigned="<?= (int)($t['assigned_profile_id'] ?? 0) ?>">
                                    <?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?>
                                    (<?= htmlspecialchars(ucfirst($t['category']), ENT_QUOTES, 'UTF-8') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="spawn-assigned">Zuweisen an:</label>
                        <select id="spawn-assigned" name="assigned_profile_id" class="form-control">
                            <option value="">Schwarzes Brett (Offen für alle)</option>
                            <?php foreach ($childProfiles as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['display_name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="spawn-date">Fälligkeitsdatum:</label>
                        <input type="date" id="spawn-date" name="due_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">🚀 Aufgabe jetzt starten</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Prämie erstellen / bearbeiten -->
    <div id="modal-reward" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modal-reward-heading">Prämie verwalten</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-reward">
                    <input type="hidden" id="reward-id" name="id">
                    <div class="form-group">
                        <label for="reward-title">Titel der Belohnung:</label>
                        <input type="text" id="reward-title" name="title" class="form-control" required placeholder="z. B. 30 Min. Gaming">
                    </div>
                    <div class="form-group">
                        <label for="reward-desc">Beschreibung:</label>
                        <textarea id="reward-desc" name="description" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="reward-cost">Münzkosten:</label>
                            <input type="number" id="reward-cost" name="coin_cost" class="form-control" value="100" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="reward-icon">Symbol / Emoji:</label>
                            <div class="gamif-emoji-picker-group">
                                <button type="button" class="gamif-emoji-preview js-open-emoji-picker" id="reward-icon-preview" data-target-input="#reward-icon" data-target-preview="#reward-icon-preview" title="Klicken, um Symbol zu wählen">🎁</button>
                                <input type="hidden" id="reward-icon" name="icon" value="🎁">
                                <button type="button" class="btn btn-outline btn-sm js-open-emoji-picker" data-target-input="#reward-icon" data-target-preview="#reward-icon-preview">🎨 Symbol wählen</button>
                            </div>
                        </div>
                    </div>
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="reward-type">Typ:</label>
                            <select id="reward-type" name="type" class="form-control">
                                <option value="privilege">Privileg / Freiheit</option>
                                <option value="voucher">Gutschein</option>
                                <option value="allowance">Taschengeld-Zuschuss</option>
                                <option value="event">Ausflug / Event</option>
                                <option value="item">Gegenstand</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="reward-cooldown">Wartezeit bis zur nächsten Einlösung (Tage):</label>
                            <input type="number" id="reward-cooldown" name="cooldown_days" class="form-control" value="0" min="0">
                        </div>
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Prämie speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Abzeichen erstellen / bearbeiten -->
    <div id="modal-badge" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modal-badge-heading">Abzeichen verwalten</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-badge">
                    <input type="hidden" id="badge-id" name="id">
                    <div class="form-group">
                        <label for="badge-title">Titel des Abzeichens:</label>
                        <input type="text" id="badge-title" name="title" class="form-control" required placeholder="z. B. Die Feuerwehr">
                    </div>
                    <div class="form-group">
                        <label for="badge-desc">Beschreibung:</label>
                        <textarea id="badge-desc" name="description" class="form-control" rows="2" placeholder="Was muss das Kind dafür erreichen?"></textarea>
                    </div>
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="badge-icon">Symbol / Emoji:</label>
                            <div class="gamif-emoji-picker-group">
                                <button type="button" class="gamif-emoji-preview js-open-emoji-picker" id="badge-icon-preview" data-target-input="#badge-icon" data-target-preview="#badge-icon-preview" title="Klicken, um Symbol zu wählen">🏆</button>
                                <input type="hidden" id="badge-icon" name="icon" value="🏆">
                                <button type="button" class="btn btn-outline btn-sm js-open-emoji-picker" data-target-input="#badge-icon" data-target-preview="#badge-icon-preview">🎨 Symbol wählen</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="badge-metric-type">Art der Bedingung:</label>
                            <select id="badge-metric-type" name="metric_type" class="form-control">
                                <option value="rescue_count">Rettungen überfälliger Aufgaben</option>
                                <option value="task_count">Erledigte Aufgaben gesamt</option>
                                <option value="category_count">Aufgaben einer bestimmten Kategorie</option>
                                <option value="streak_days">Zuverlässigkeits-Serie (Tage)</option>
                                <option value="initiative_count">Spontane Hilfen / Initiativen</option>
                                <option value="xp_total">Gesamte Erfahrungspunkte (XP)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="badge-metric-target">Zielwert (Anzahl / Tage / Sterne):</label>
                            <input type="number" id="badge-metric-target" name="metric_target" class="form-control" value="1" min="1" required>
                        </div>
                        <div class="form-group" id="group-badge-param" style="display:none;">
                            <label for="badge-param">Kategorie (z. B. haushalt, schule):</label>
                            <input type="text" id="badge-param" name="metric_parameter" class="form-control" placeholder="haushalt">
                        </div>
                    </div>
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="badge-coins">Belohnungs-Münzen:</label>
                            <input type="number" id="badge-coins" name="reward_coins" class="form-control" value="50" min="0">
                        </div>
                        <div class="form-group">
                            <label for="badge-xp">Erfahrungspunkte (XP):</label>
                            <input type="number" id="badge-xp" name="reward_xp" class="form-control" value="100" min="0">
                        </div>
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Abzeichen speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Punkte manuell anpassen -->
    <div id="modal-points" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modal-points-heading">Punktekonto anpassen</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-points">
                    <input type="hidden" id="points-profile-id" name="profile_id">
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="points-coins">Münzen ändern (+/-):</label>
                            <input type="number" id="points-coins" name="coins_delta" class="form-control" value="0">
                        </div>
                        <div class="form-group">
                            <label for="points-xp">XP hinzufügen (+):</label>
                            <input type="number" id="points-xp" name="xp_delta" class="form-control" value="0" min="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="points-reason">Begründung:</label>
                        <input type="text" id="points-reason" name="reason" class="form-control" placeholder="z. B. Sonder-Bonus für tolles Zeugnis" required>
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Buchen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Aufgabe prüfen / Nachbessern -->
    <div id="modal-review" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modal-review-heading">Aufgabe prüfen</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-review">
                    <input type="hidden" id="review-task-id" name="task_id">
                    <input type="hidden" id="review-sub-action" name="sub_action" value="reject">
                    <p id="review-task-title-display" class="text-muted"></p>
                    <div class="form-group">
                        <label for="review-feedback">Rückmeldung an das Kind:</label>
                        <textarea id="review-feedback" name="feedback" class="form-control" rows="3" placeholder="Bitte räume noch den Tisch ab..." required></textarea>
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-danger">Zurückweisen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Aufgabe genehmigen & Belohnung anpassen -->
    <div id="modal-approve-task" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Aufgabe genehmigen & belohnen ✅</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-approve-task">
                    <input type="hidden" id="approve-task-id" name="task_id">
                    <p id="approve-task-desc" class="text-muted"></p>
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="approve-coins">Münzen gutschreiben:</label>
                            <input type="number" id="approve-coins" name="custom_coins" class="form-control" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="approve-xp">XP gutschreiben:</label>
                            <input type="number" id="approve-xp" name="custom_xp" class="form-control" min="0" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="approve-feedback">Lob / Notiz an das Kind (optional):</label>
                        <input type="text" id="approve-feedback" name="feedback" class="form-control" placeholder="Super gemacht! Weiter so!">
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">✅ Bestätigen & Gutschreiben</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Geschwister-Mithilfe bewerten -->
    <div id="modal-review-helper" class="modal-overlay hidden">
        <div class="modal-card modal-card--sm">
            <div class="modal-header">
                <h3 id="review-helper-heading">Mithilfe bewerten 🤝</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-review-helper">
                    <input type="hidden" id="helper-id" name="helper_id">
                    <input type="hidden" id="helper-approved" name="approved" value="1">
                    <p id="helper-desc" class="text-muted"></p>
                    <div class="form-group" id="group-helper-coins">
                        <label for="helper-coins">Helfer-Münzen:</label>
                        <input type="number" id="helper-coins" name="custom_coins" class="form-control" min="0" value="10">
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" id="helper-submit-btn" class="btn btn-primary">Bestätigen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Prämien-Antrag prüfen -->
    <div id="modal-review-redemption" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="redemption-heading">Prämien-Wunsch prüfen 🎁</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-review-redemption">
                    <input type="hidden" id="redemption-id" name="redemption_id">
                    <input type="hidden" id="redemption-action" name="sub_action" value="approve">
                    <p id="redemption-desc" class="text-muted"></p>
                    <div class="form-group">
                        <label for="redemption-parent-note">Notiz / Vereinbarung (optional):</label>
                        <input type="text" id="redemption-parent-note" name="parent_note" class="form-control" placeholder="z. B. Vereinbart für Samstag Nachmittag">
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" id="redemption-submit-btn" class="btn btn-primary">Genehmigen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Löschen bestätigen -->
    <div id="modal-confirm-delete" class="modal-overlay hidden">
        <div class="modal-card modal-card--sm">
            <div class="modal-header">
                <h3 id="confirm-delete-heading">Eintrag löschen 🗑️</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <p id="confirm-delete-msg">Möchtest du dieses Element wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.</p>
                <input type="hidden" id="delete-type" value="">
                <input type="hidden" id="delete-id" value="">
                <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                    <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                    <button type="button" id="btn-confirm-delete-execute" class="btn btn-danger">🗑️ Unwiderruflich löschen</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Fristen & Aufgaben manuell synchronisieren -->
    <div id="modal-sync-escalation" class="modal-overlay hidden">
        <div class="modal-card modal-card--sm">
            <div class="modal-header">
                <h3>Fristen & Aufgaben abgleichen ⏱️</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <p>Möchtest du die Fristen jetzt sofort prüfen? Überfällige Aufgaben werden auf das Schwarze Brett eskaliert (+50% Retter-Bonus) und fällige Tagesaufgaben vorbereitet.</p>
                <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                    <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                    <button type="button" id="btn-execute-sync-escalation" class="btn btn-primary">⏱️ Jetzt prüfen</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Rückmeldung / Feedback -->
    <div id="modal-feedback" class="modal-overlay hidden">
        <div class="modal-card modal-card--sm">
            <div class="modal-header">
                <h3 id="feedback-heading">Hinweis</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <p id="feedback-msg"></p>
                <div class="modal-actions" style="display:flex; justify-content:flex-end; margin-top:1rem;">
                    <button type="button" id="btn-feedback-ok" class="btn btn-primary modal-close">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Mitspieler-Profil bearbeiten -->
    <div id="modal-edit-profile" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Mitspieler bearbeiten ✏️</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-edit-profile">
                    <input type="hidden" id="edit-profile-id" name="profile_id">
                    <div class="form-group">
                        <label for="edit-profile-name">Name des Mitspielers:</label>
                        <input type="text" id="edit-profile-name" name="display_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="edit-profile-email">Google-Konto / E-Mail:</label>
                        <input type="text" id="edit-profile-email" class="form-control" readonly disabled>
                    </div>
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="edit-profile-role">Rolle:</label>
                            <select id="edit-profile-role" name="role" class="form-control">
                                <option value="child">Kind (Mitspieler)</option>
                                <option value="parent">Elternteil (Admin)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit-profile-avatar">Symbol / Emoji:</label>
                            <div class="gamif-emoji-picker-group">
                                <button type="button" class="gamif-emoji-preview js-open-emoji-picker" id="edit-profile-avatar-preview" data-target-input="#edit-profile-avatar" data-target-preview="#edit-profile-avatar-preview" title="Klicken, um Symbol zu wählen">⭐</button>
                                <input type="hidden" id="edit-profile-avatar" name="avatar_icon" value="⭐">
                                <button type="button" class="btn btn-outline btn-sm js-open-emoji-picker" data-target-input="#edit-profile-avatar" data-target-preview="#edit-profile-avatar-preview">🎨 Symbol wählen</button>
                            </div>
                        </div>
                    </div>
                    <hr style="border:0; border-top:1px solid rgba(255,255,255,0.1); margin:1rem 0;">
                    <h4 style="margin:0 0 0.5rem 0; font-size:0.95rem; color:var(--text-light, #fff);">🛡️ Serien-Schutz &amp; Urlaub</h4>
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="edit-profile-shields">Aktive Streak-Schilde:</label>
                            <input type="number" id="edit-profile-shields" name="streak_shields" class="form-control" min="0" max="99" value="0">
                            <span class="text-muted small">Rettet die Serie automatisch bei Säumnis.</span>
                        </div>
                        <div class="form-group">
                            <label for="edit-profile-freeze-until">Urlaub / Pause bis einschl.:</label>
                            <input type="date" id="edit-profile-freeze-until" name="streak_freeze_until" class="form-control">
                            <span class="text-muted small">Kein Fristverfall während dieses Zeitraums.</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="edit-profile-freeze-reason">Grund für Pause / Urlaub (optional):</label>
                        <input type="text" id="edit-profile-freeze-reason" name="streak_freeze_reason" class="form-control" placeholder="z. B. Klassenfahrt, Sommerurlaub, Krank">
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Änderungen speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Neuen Mitspieler anlegen -->
    <div id="modal-create-profile" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Neuen Mitspieler anlegen 👤</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-create-profile">
                    <div class="form-group">
                        <label for="new-profile-name">Name des Kindes / Mitspielers:</label>
                        <input type="text" id="new-profile-name" name="display_name" class="form-control" required placeholder="z. B. Zoé oder Enya">
                    </div>
                    <div class="form-group">
                        <label for="new-profile-email">Google-Konto (E-Mail):</label>
                        <input type="email" id="new-profile-email" name="user_email" class="form-control" list="known-users-list" required placeholder="z. B. kind@gmail.com">
                        <datalist id="known-users-list">
                            <?php foreach ($systemUsers as $u): ?>
                                <option value="<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($u['name'] ?: $u['email'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </datalist>
                        <small class="text-muted">Mit dieser E-Mail meldet sich das Kind über Google an.</small>
                    </div>
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="new-profile-role">Rolle:</label>
                            <select id="new-profile-role" name="role" class="form-control">
                                <option value="child" selected>Kind (Mitspieler)</option>
                                <option value="parent">Elternteil (Admin)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="new-profile-avatar">Start-Symbol / Emoji:</label>
                            <div class="gamif-emoji-picker-group">
                                <button type="button" class="gamif-emoji-preview js-open-emoji-picker" id="new-profile-avatar-preview" data-target-input="#new-profile-avatar" data-target-preview="#new-profile-avatar-preview" title="Klicken, um Symbol zu wählen">⭐</button>
                                <input type="hidden" id="new-profile-avatar" name="avatar_icon" value="⭐">
                                <button type="button" class="btn btn-outline btn-sm js-open-emoji-picker" data-target-input="#new-profile-avatar" data-target-preview="#new-profile-avatar-preview">🎨 Symbol wählen</button>
                            </div>
                        </div>
                    </div>
                    <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div class="form-group">
                            <label for="new-profile-coins">Start-Münzen:</label>
                            <input type="number" id="new-profile-coins" name="initial_coins" class="form-control" value="0" min="0">
                        </div>
                        <div class="form-group">
                            <label for="new-profile-xp">Start-XP:</label>
                            <input type="number" id="new-profile-xp" name="initial_xp" class="form-control" value="0" min="0">
                        </div>
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Mitspieler speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <footer class="app-footer">
        <div>kai v<?= APP_VERSION ?> · Eltern-Verwaltung 👑</div>
    </footer>
</div>

<script src="../js/http.js?v=<?= APP_VERSION ?>"></script>
<script src="../js/gamification-tooltip.js?v=<?= APP_VERSION ?>"></script>
<script src="../js/gamification-emoji-picker.js?v=<?= APP_VERSION ?>"></script>
<script src="../js/gamification-admin.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
