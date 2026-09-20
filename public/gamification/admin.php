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

// Stammdaten laden
$templates = $templateRepo->getAllTemplates();
$rewards = $rewardRepo->getAllRewards();
$achievements = $achievementService->getAllAchievements();
$profiles = $profileRepo->getAllProfiles();
$childProfiles = $profileRepo->getChildProfiles();
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
        <?php if ($totalPending === 0): ?>
            <div class="gamif-empty-box">
                <span class="gamif-empty-icon">☕</span>
                <h3>Alles erledigt!</h3>
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
                                <button type="button" class="btn btn-outline btn-sm js-triage-reject-btn" data-task-id="<?= (int)$t['id'] ?>">Ablehnen / Nachbessern</button>
                                <button type="button" class="btn btn-primary btn-sm js-triage-approve-btn" data-task-id="<?= (int)$t['id'] ?>" data-coins="<?= (int)$t['base_coins'] + (int)$t['bounty_bonus_coins'] + (int)$t['initiative_bonus_coins'] ?>" data-xp="<?= (int)$t['base_xp'] + (int)$t['bounty_bonus_xp'] ?>">Bestätigen & Punkte gutschreiben ✓</button>
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
                                <button type="button" class="btn btn-outline btn-sm js-review-helper-btn" data-helper-id="<?= (int)$h['id'] ?>" data-approved="0">Ablehnen</button>
                                <button type="button" class="btn btn-primary btn-sm js-review-helper-btn" data-helper-id="<?= (int)$h['id'] ?>" data-approved="1">Mithilfe anerkennen (+<?= (int)$h['bonus_coins'] ?> Münzen) ✓</button>
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
                                <button type="button" class="btn btn-outline btn-sm js-review-redemption-btn" data-redemption-id="<?= (int)$r['id'] ?>" data-action="reject">Ablehnen (Münzen erstatten)</button>
                                <button type="button" class="btn btn-primary btn-sm js-review-redemption-btn" data-redemption-id="<?= (int)$r['id'] ?>" data-action="approve">Genehmigen ✓</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Reiter 2: Aufgaben-Vorlagen verwalten -->
    <section id="tab-templates" class="gamif-tab-content hidden">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3>Regelmäßige Aufgaben & Vorlagen</h3>
            <button type="button" class="btn btn-primary btn-sm js-open-template-modal">+ Neue Vorlage anlegen</button>
        </div>

        <div class="table-responsive">
            <table class="table">
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
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($tmpl['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if (!empty($tmpl['is_cooking_day'])): ?>
                                        <span class="gamif-tag" style="background:rgba(236,72,153,0.2); color:#f472b6;">🍳 Koch-Tag</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(ucfirst($tmpl['category']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php 
                                    $recMap = ['none' => 'Einmalig', 'daily' => 'Täglich', 'weekly' => 'Wöchentlich', 'interval' => 'Intervall'];
                                    echo htmlspecialchars($recMap[$tmpl['recurrence']] ?? $tmpl['recurrence'], ENT_QUOTES, 'UTF-8');
                                    ?>
                                </td>
                                <td><?= !empty($tmpl['due_time']) ? htmlspecialchars(substr($tmpl['due_time'], 0, 5), ENT_QUOTES, 'UTF-8') . ' Uhr' : 'Keine' ?></td>
                                <td><?= !empty($tmpl['assigned_name']) ? htmlspecialchars($tmpl['assigned_name'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">Schwarzes Brett</span>' ?></td>
                                <td>🪙 +<?= (int)$tmpl['base_coins'] ?> | ⭐ +<?= (int)$tmpl['base_xp'] ?></td>
                                <td><?= (int)$tmpl['is_active'] === 1 ? '<span class="text-success">Aktiv</span>' : '<span class="text-muted">Inaktiv</span>' ?></td>
                                <td>
                                    <button type="button" class="btn btn-outline btn-sm js-edit-template-btn" data-template='<?= htmlspecialchars(json_encode($tmpl), ENT_QUOTES, 'UTF-8') ?>'>Bearbeiten</button>
                                    <button type="button" class="btn btn-outline btn-sm js-delete-template-btn" data-template-id="<?= (int)$tmpl['id'] ?>">Löschen</button>
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
            <button type="button" class="btn btn-primary btn-sm js-open-reward-modal">+ Neue Prämie anlegen</button>
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
                                <td><?= htmlspecialchars($rew['type'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int)$rew['cooldown_days'] > 0 ? (int)$rew['cooldown_days'] . ' Tage' : 'Keine' ?></td>
                                <td><?= (int)$rew['is_active'] === 1 ? '<span class="text-success">Aktiv</span>' : '<span class="text-muted">Pausiert</span>' ?></td>
                                <td>
                                    <button type="button" class="btn btn-outline btn-sm js-edit-reward-btn" data-reward='<?= htmlspecialchars(json_encode($rew), ENT_QUOTES, 'UTF-8') ?>'>Bearbeiten</button>
                                    <button type="button" class="btn btn-outline btn-sm js-delete-reward-btn" data-reward-id="<?= (int)$rew['id'] ?>">Löschen</button>
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
            <button type="button" class="btn btn-primary btn-sm js-open-badge-modal">+ Neues Abzeichen anlegen</button>
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
                        <?php foreach ($achievements as $ach): ?>
                            <tr>
                                <td style="font-size:1.5rem;"><?= htmlspecialchars($ach['icon'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($ach['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="text-muted" style="font-size:0.85rem;"><?= htmlspecialchars($ach['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td>
                                    <?= htmlspecialchars($ach['metric_type'], ENT_QUOTES, 'UTF-8') ?>:
                                    <strong><?= (int)$ach['metric_target'] ?></strong>
                                </td>
                                <td>🪙 +<?= (int)$ach['reward_coins'] ?> | ⭐ +<?= (int)$ach['reward_xp'] ?></td>
                                <td><?= (int)$ach['is_active'] === 1 ? '<span class="text-success">Aktiv</span>' : '<span class="text-muted">Inaktiv</span>' ?></td>
                                <td>
                                    <button type="button" class="btn btn-outline btn-sm js-edit-badge-btn" data-badge='<?= htmlspecialchars(json_encode($ach), ENT_QUOTES, 'UTF-8') ?>'>Bearbeiten</button>
                                    <button type="button" class="btn btn-outline btn-sm js-delete-badge-btn" data-badge-id="<?= (int)$ach['id'] ?>">Löschen</button>
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
        <h3>Punktekonten der Familienmitglieder</h3>
        <p class="text-muted">Hier kannst du Punktestände und Münzguthaben einsehen oder bei Bedarf korrigieren.</p>

        <div class="gamif-grid" style="margin-top:1rem;">
            <?php foreach ($profiles as $p): 
                $lvlProg = $profileRepo->calculateLevelProgress((int)$p['xp']);
            ?>
                <div class="gamif-card">
                    <div style="display:flex; align-items:center; gap:0.75rem;">
                        <span style="font-size:2rem;"><?= htmlspecialchars($p['avatar_icon'] ?? '⭐', ENT_QUOTES, 'UTF-8') ?></span>
                        <div>
                            <h4 style="margin:0;"><?= htmlspecialchars($p['display_name'], ENT_QUOTES, 'UTF-8') ?></h4>
                            <span class="text-muted" style="font-size:0.85rem;">Level <?= $lvlProg['level'] ?> · <?= htmlspecialchars($lvlProg['rank_title'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-top:0.5rem;">
                        <span class="gamif-stat-chip gamif-stat-chip--coins">🪙 <?= (int)$p['coins'] ?> Münzen</span>
                        <span class="gamif-stat-chip gamif-stat-chip--streak">🔥 <?= (int)$p['streak_days'] ?> Tage</span>
                        <span class="gamif-stat-chip gamif-stat-chip--xp">⭐ <?= (int)$p['xp'] ?> XP</span>
                    </div>
                    <div style="margin-top:0.75rem; padding-top:0.75rem; border-top:1px solid rgba(255,255,255,0.05); display:flex; justify-content:flex-end;">
                        <button type="button" class="btn btn-outline btn-sm js-adjust-points-btn" data-profile-id="<?= (int)$p['id'] ?>" data-name="<?= htmlspecialchars($p['display_name'], ENT_QUOTES, 'UTF-8') ?>">Punkte anpassen</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

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
                                <option value="kochen">Kochen & Mahlzeiten</option>
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
                        <label>Wochentage (Mo=1 bis So=7 kommagetrennt, z. B. 1,3,5):</label>
                        <input type="text" id="tmpl-recurrence-days" name="recurrence_days" class="form-control" placeholder="1,2,3,4,5">
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
                    <div class="form-group" style="display:flex; align-items:center; gap:0.5rem; margin-top:0.5rem;">
                        <input type="checkbox" id="tmpl-cooking" name="is_cooking_day" value="1">
                        <label for="tmpl-cooking" style="margin:0;">Koch-Tag (aktiviert Rezept-Pitch & Bewertung)</label>
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Vorlage speichern</button>
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
                            <input type="text" id="reward-icon" name="icon" class="form-control" value="🎁">
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

    <footer class="app-footer">
        <div>kai v<?= APP_VERSION ?> · Eltern-Verwaltung 👑</div>
    </footer>
</div>

<script src="../js/http.js?v=<?= APP_VERSION ?>"></script>
<script src="../js/gamification-admin.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
