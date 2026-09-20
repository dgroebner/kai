<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Gamification\GamificationService;
use Kai\Tools\Shared\Security\Auth;

// 1. Auth-Check
Auth::requirePage('gamification_read');

$gamifService = new GamificationService();
$gamifService->syncDailyState();

$currentUserEmail = $_SESSION['user_email'] ?? '';
$isAdmin = Auth::hasPermission('gamification_admin');
$profileRepo = $gamifService->getProfileRepository();
$currentProfile = $profileRepo->getOrCreateProfile($currentUserEmail, $_SESSION['user_name'] ?? null, $isAdmin);
$profileId = (int)$currentProfile['id'];

// Level & Fortschritt berechnen
$levelProgress = $profileRepo->calculateLevelProgress((int)$currentProfile['xp']);

// Aufgaben laden
$taskRepo = $gamifService->getTaskRepository();
$myTasks = $taskRepo->getMyTasks($profileId);
$bountyTasks = $taskRepo->getBountyBoard($profileId);

// Abzeichen & Belohnungen laden
$achievements = $gamifService->getAchievementService()->getProfileAchievementsWithProgress($profileId);
$rewards = $gamifService->getRewardRepository()->getAllRewards(true);
$myRedemptions = $gamifService->getRewardRepository()->getRedemptionsForProfile($profileId, 5);

// Neu verdiente Abzeichen abfragen
$newBadges = $gamifService->getAchievementService()->checkAndAwardAchievements($profileId);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= Auth::csrfToken() ?>">
    <title>Familien-Quests 🏆 — kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <div>
            <h1>Familien-Quests 🏆</h1>
            <p class="text-muted">Deine Missionen, Abzeichen und Belohnungen</p>
        </div>
        <div class="page-header-actions">
            <button type="button" class="btn btn-primary js-open-pitch-modal">✨ Ich hab geholfen!</button>
            <?php if ($isAdmin): ?>
                <a href="admin.php" class="btn btn-outline">👑 Eltern-Bereich</a>
            <?php endif; ?>
            <a href="../index.php" class="btn btn-outline">← Zurück</a>
        </div>
    </header>

    <!-- Spieler-HUD (Status, Level, XP, Münzen, Streak) -->
    <section class="gamif-hud">
        <div class="gamif-hud-header">
            <div class="gamif-hud-profile">
                <div class="gamif-avatar gamif-avatar--clickable js-open-avatar-picker" title="Tippe hier, um dein Spieler-Symbol zu ändern!"><?= htmlspecialchars($currentProfile['avatar_icon'] ?? '⭐', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="gamif-profile-info">
                    <h2><?= htmlspecialchars($currentProfile['display_name'] ?? 'Held', ENT_QUOTES, 'UTF-8') ?></h2>
                    <span class="gamif-rank-title">Level <?= $levelProgress['level'] ?>: <?= htmlspecialchars($levelProgress['rank_title'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
            <div class="gamif-hud-stats">
                <div class="gamif-stat-chip gamif-stat-chip--coins" title="Verfügbare Belohnungsmünzen">
                    🪙 <span><?= (int)$currentProfile['coins'] ?></span> Münzen
                </div>
                <div class="gamif-stat-chip gamif-stat-chip--streak" title="Tage in Folge zuverlässig erledigt">
                    🔥 <span><?= (int)$currentProfile['streak_days'] ?></span> <?= (int)$currentProfile['streak_days'] === 1 ? 'Tag' : 'Tage' ?> Serie
                </div>
                <div class="gamif-stat-chip gamif-stat-chip--xp" title="Gesamte Erfahrungspunkte">
                    ⭐ <span><?= (int)$currentProfile['xp'] ?></span> XP
                </div>
            </div>
        </div>

        <div class="gamif-xp-container">
            <div class="gamif-xp-labels">
                <span>Level <?= $levelProgress['level'] ?></span>
                <span>Noch <?= $levelProgress['xp_remaining'] ?> XP bis Level <?= $levelProgress['level'] + 1 ?> (<?= $levelProgress['percent'] ?>%)</span>
            </div>
            <div class="gamif-xp-track">
                <div class="gamif-xp-fill" style="width: <?= $levelProgress['percent'] ?>%;"></div>
            </div>
        </div>
    </section>

    <!-- Navigation Tabs -->
    <nav class="gamif-tabs">
        <button type="button" class="gamif-tab-btn active" data-tab="tab-missions">🎯 Meine Missionen (<?= count($myTasks) ?>)</button>
        <button type="button" class="gamif-tab-btn" data-tab="tab-board">🔥 Schwarzes Brett (<?= count($bountyTasks) ?>)</button>
        <button type="button" class="gamif-tab-btn" data-tab="tab-rewards">🎁 Belohnungen</button>
        <button type="button" class="gamif-tab-btn" data-tab="tab-trophies">🏆 Trophäen (<?= count(array_filter($achievements, fn($a) => $a['is_unlocked'])) ?>/<?= count($achievements) ?>)</button>
    </nav>

    <!-- Tab 1: Meine Missionen -->
    <section id="tab-missions" class="gamif-tab-content">
        <?php if (empty($myTasks)): ?>
            <div class="gamif-empty-box">
                <span class="gamif-empty-icon">🎉</span>
                <h3>Alles erledigt für heute!</h3>
                <p>Du hast aktuell keine offenen Aufgaben. Schau auf dem Schwarzen Brett vorbei oder trage eine spontane Hilfe ein!</p>
            </div>
        <?php else: ?>
            <div class="gamif-grid">
                <?php foreach ($myTasks as $task): 
                    $isDone = $task['status'] === 'completed';
                    $inReview = in_array($task['status'], ['submitted', 'in_review'], true);
                    $cardClass = $isDone ? 'gamif-card--completed' : ($inReview ? 'gamif-card--in-review' : '');
                ?>
                    <article class="gamif-card <?= $cardClass ?>" data-task-id="<?= (int)$task['id'] ?>">
                        <div>
                            <div class="gamif-card-header">
                                <h3 class="gamif-card-title"><?= htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <div class="gamif-card-reward">
                                    <span class="gamif-stat-chip gamif-stat-chip--coins">🪙 +<?= (int)$task['base_coins'] ?></span>
                                    <span class="gamif-stat-chip gamif-stat-chip--xp">⭐ +<?= (int)$task['base_xp'] ?></span>
                                </div>
                            </div>

                            <div class="gamif-card-meta">
                                <span class="gamif-tag"><?= htmlspecialchars(ucfirst($task['category']), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if (!empty($task['due_time'])): ?>
                                    <span class="gamif-tag gamif-tag--time">⏰ Bis <?= htmlspecialchars(substr($task['due_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?> Uhr</span>
                                <?php endif; ?>
                                <?php if (!empty($task['is_cooking_day'])): ?>
                                    <span class="gamif-tag" style="background: rgba(236, 72, 153, 0.2); color: #f472b6;">🍳 Koch-Tag</span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($task['description'])): ?>
                                <p class="gamif-card-desc"><?= htmlspecialchars($task['description'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>

                            <?php if (!empty($task['is_cooking_day'])): ?>
                                <div style="margin-top: 0.5rem; padding: 0.5rem; background: var(--bg-main); border-radius: var(--border-radius); font-size: 0.85rem;">
                                    <?php if (empty($task['recipe_title'])): ?>
                                        <p class="text-warning">⚠️ Noch kein Gericht vorgeschlagen!</p>
                                        <button type="button" class="btn btn-outline btn-sm js-open-recipe-modal" data-task-id="<?= (int)$task['id'] ?>" data-task-title="<?= htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8') ?>">Gericht vorschlagen (+10 Bonus-Münzen bei 24h Vorlauf)</button>
                                    <?php else: ?>
                                        <strong>Gericht:</strong> <?= htmlspecialchars($task['recipe_title'], ENT_QUOTES, 'UTF-8') ?>
                                        <span class="gamif-tag" style="margin-left: 0.3rem;">Status: <?= htmlspecialchars($task['recipe_status'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($task['parent_feedback'])): ?>
                                            <p class="text-muted" style="margin-top: 0.25rem;">Rückmeldung Eltern: <?= htmlspecialchars($task['parent_feedback'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="gamif-card-footer">
                            <?php if ($isDone): ?>
                                <span class="text-success">✅ Erfolgreich erledigt (+<?= (int)$task['final_coins'] ?> Münzen)</span>
                            <?php elseif ($inReview): ?>
                                <span class="text-warning">⏳ Wartet auf Freigabe der Eltern</span>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline btn-sm js-coop-btn" data-task-id="<?= (int)$task['id'] ?>" data-title="<?= htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8') ?>">Geschwisterhilfe anfragen</button>
                                <button type="button" class="btn btn-primary btn-sm js-submit-task-btn" data-task-id="<?= (int)$task['id'] ?>" data-title="<?= htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8') ?>">Erledigt melden ✓</button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Tab 2: Schwarzes Brett (Bounty Board) -->
    <section id="tab-board" class="gamif-tab-content hidden">
        <?php if (empty($bountyTasks)): ?>
            <div class="gamif-empty-box">
                <span class="gamif-empty-icon">🛡️</span>
                <h3>Das Schwarze Brett ist leer</h3>
                <p>Aktuell gibt es keine offenen Bounties oder überfälligen Rettungs-Missionen. Gute Teamarbeit!</p>
            </div>
        <?php else: ?>
            <div class="gamif-grid">
                <?php foreach ($bountyTasks as $task): 
                    $isRescue = $task['status'] === 'escalated' || !empty($task['bounty_bonus_coins']);
                    $isClaimedByMe = (int)($task['claimed_by_profile_id'] ?? 0) === $profileId;
                    $totalCoins = (int)$task['base_coins'] + (int)$task['bounty_bonus_coins'];
                    $totalXp = (int)$task['base_xp'] + (int)$task['bounty_bonus_xp'];
                ?>
                    <article class="gamif-card <?= $isRescue ? 'gamif-card--rescue' : '' ?>" data-task-id="<?= (int)$task['id'] ?>">
                        <div>
                            <div class="gamif-card-header">
                                <div>
                                    <h3 class="gamif-card-title"><?= htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                    <?php if ($isRescue): ?>
                                        <span class="gamif-tag gamif-tag--rescue">🔥 RETTUNGS-QUEST (+50% Bonus-Münzen!)</span>
                                    <?php else: ?>
                                        <span class="gamif-tag gamif-tag--bounty">Offene Gemeinschaftsaufgabe</span>
                                    <?php endif; ?>
                                </div>
                                <div class="gamif-card-reward">
                                    <span class="gamif-stat-chip gamif-stat-chip--coins">🪙 +<?= $totalCoins ?></span>
                                    <span class="gamif-stat-chip gamif-stat-chip--xp">⭐ +<?= $totalXp ?></span>
                                </div>
                            </div>

                            <div class="gamif-card-meta">
                                <span class="gamif-tag"><?= htmlspecialchars(ucfirst($task['category']), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if (!empty($task['origin_name'])): ?>
                                    <span class="gamif-tag">Ursprünglich von <?= htmlspecialchars($task['origin_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($task['description'])): ?>
                                <p class="gamif-card-desc"><?= htmlspecialchars($task['description'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="gamif-card-footer">
                            <?php if ($isClaimedByMe): ?>
                                <span class="text-warning">🔒 Von dir reserviert</span>
                                <div style="display:flex; gap:0.5rem;">
                                    <button type="button" class="btn btn-outline btn-sm js-release-claim-btn" data-task-id="<?= (int)$task['id'] ?>" data-title="<?= htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8') ?>">Freigeben</button>
                                    <button type="button" class="btn btn-primary btn-sm js-submit-task-btn" data-task-id="<?= (int)$task['id'] ?>" data-title="<?= htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8') ?>">Erledigt ✓</button>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">Verfügbar für alle</span>
                                <button type="button" class="btn btn-primary btn-sm js-claim-task-btn" data-task-id="<?= (int)$task['id'] ?>" data-title="<?= htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8') ?>" data-coins="<?= $totalCoins ?>" data-xp="<?= $totalXp ?>">Quest schnappen ⚡</button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Tab 3: Belohnungen -->
    <section id="tab-rewards" class="gamif-tab-content hidden">
        <?php if (!empty($myRedemptions)): ?>
            <div class="gamif-hud" style="margin-bottom: 1.5rem;">
                <h3>Deine aktuellen Anträge</h3>
                <div style="display:flex; flex-direction:column; gap:0.5rem; margin-top:0.5rem;">
                    <?php foreach ($myRedemptions as $red): ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; padding:0.5rem; background:var(--bg-main); border-radius:var(--border-radius);">
                            <div>
                                <span><?= htmlspecialchars($red['reward_icon'] ?? '🎁', ENT_QUOTES, 'UTF-8') ?></span>
                                <strong><?= htmlspecialchars($red['reward_title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="text-muted">(<?= (int)$red['coin_cost'] ?> Münzen)</span>
                            </div>
                            <div>
                                <?php if ($red['status'] === 'requested'): ?>
                                    <span class="gamif-tag" style="background:rgba(245,158,11,0.2); color:#f59e0b;">⏳ In Prüfung</span>
                                <?php elseif ($red['status'] === 'approved'): ?>
                                    <span class="gamif-tag" style="background:rgba(16,185,129,0.2); color:#10b981;">✅ Genehmigt</span>
                                <?php elseif ($red['status'] === 'rejected'): ?>
                                    <span class="gamif-tag" style="background:rgba(239,68,68,0.2); color:#ef4444;">❌ Abgelehnt</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <h3>Belohnungskatalog</h3>
        <p class="text-muted">Tausche deine hart verdienten Münzen gegen tolle Belohnungen und Privilegien ein!</p>
        <div class="gamif-grid" style="margin-top: 1rem;">
            <?php 
            $rewardTypeMap = [
                'privilege' => 'Privileg / Freiheit',
                'voucher'   => 'Gutschein',
                'allowance' => 'Taschengeld-Zuschuss',
                'event'     => 'Ausflug / Erlebnis',
                'item'      => 'Gegenstand',
            ];
            foreach ($rewards as $reward): 
                $canAfford = (int)$currentProfile['coins'] >= (int)$reward['coin_cost'];
            ?>
                <div class="gamif-reward-card">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                            <div class="gamif-reward-icon"><?= htmlspecialchars($reward['icon'], ENT_QUOTES, 'UTF-8') ?></div>
                            <span class="gamif-tag"><?= htmlspecialchars($rewardTypeMap[$reward['type']] ?? ucfirst($reward['type']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <h4 style="margin: 0.5rem 0 0.25rem 0;"><?= htmlspecialchars($reward['title'], ENT_QUOTES, 'UTF-8') ?></h4>
                        <?php if (!empty($reward['description'])): ?>
                            <p class="text-muted" style="font-size: 0.85rem;"><?= htmlspecialchars($reward['description'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.5rem; padding-top:0.5rem; border-top:1px solid rgba(255,255,255,0.05);">
                        <span class="gamif-reward-cost">🪙 <?= (int)$reward['coin_cost'] ?></span>
                        <button type="button" class="btn btn-sm <?= $canAfford ? 'btn-primary' : 'btn-outline' ?> js-redeem-btn" 
                                data-reward-id="<?= (int)$reward['id'] ?>" 
                                data-title="<?= htmlspecialchars($reward['title'], ENT_QUOTES, 'UTF-8') ?>"
                                data-cost="<?= (int)$reward['coin_cost'] ?>"
                                <?= !$canAfford ? 'disabled' : '' ?>>
                            <?= $canAfford ? 'Einlösen' : 'Zu wenig Münzen' ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Tab 4: Trophäen & Abzeichen -->
    <section id="tab-trophies" class="gamif-tab-content hidden">
        <h3>Deine Trophäen-Wand</h3>
        <p class="text-muted">Meistere Herausforderungen, hilf deinen Geschwistern und schalte legendäre Abzeichen frei!</p>
        <div class="gamif-badge-grid" style="margin-top: 1rem;">
            <?php foreach ($achievements as $ach): ?>
                <div class="gamif-badge-card <?= $ach['is_unlocked'] ? 'gamif-badge-card--unlocked' : 'gamif-badge-card--locked' ?>">
                    <div class="gamif-badge-icon"><?= htmlspecialchars($ach['icon'], ENT_QUOTES, 'UTF-8') ?></div>
                    <h4 class="gamif-badge-title"><?= htmlspecialchars($ach['title'], ENT_QUOTES, 'UTF-8') ?></h4>
                    <p class="gamif-badge-desc"><?= htmlspecialchars($ach['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    <div class="gamif-badge-progress">
                        <div class="gamif-xp-track" style="height: 6px;">
                            <div class="gamif-xp-fill" style="width: <?= $ach['percent'] ?>%;"></div>
                        </div>
                        <span class="text-muted" style="font-size: 0.7rem;"><?= $ach['current_value'] ?> / <?= $ach['target_value'] ?></span>
                    </div>
                    <?php if ($ach['is_unlocked']): ?>
                        <span class="gamif-tag" style="margin-top:0.25rem; background:rgba(245,158,11,0.2); color:#f59e0b; font-size:0.7rem;">⭐ Freigeschaltet!</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Modal: Spontan-Hilfe einreichen ("Ich hab geholfen!") -->
    <div id="modal-pitch" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3>✨ Ich habe geholfen!</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <p class="text-muted">Hast du spontan im Haushalt mitgeholfen oder eine Aufgabe selbstständig erledigt? Trag es hier ein!</p>
                <form id="form-pitch">
                    <div class="form-group">
                        <label for="pitch-title">Was hast du gemacht?</label>
                        <input type="text" id="pitch-title" name="title" class="form-control" placeholder="z. B. Spülmaschine ausgeräumt, Hund gebürstet..." required>
                    </div>
                    <div class="form-group">
                        <label for="pitch-category">Bereich</label>
                        <select id="pitch-category" name="category" class="form-control">
                            <option value="haushalt">Haushalt & Küche</option>
                            <option value="tiere">Tiere & Fütterung</option>
                            <option value="zimmer">Zimmer & Ordnung</option>
                            <option value="garten">Garten & Außenbereich</option>
                            <option value="projekt">Eigenes Projekt</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pitch-notes">Zusatz-Info (optional)</label>
                        <textarea id="pitch-notes" name="notes" class="form-control" rows="2" placeholder="z. B. Zusammen mit Mama gemacht oder ganz alleine"></textarea>
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Einreichen 🚀</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Aufgabe als erledigt melden -->
    <div id="modal-submit-task" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modal-submit-title">Aufgabe abschließen</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-submit-task">
                    <input type="hidden" id="submit-task-id" name="task_id">
                    <p class="text-muted">Möchtest du diese Aufgabe zur Prüfung bei deinen Eltern einreichen?</p>
                    <div class="form-group">
                        <label for="submit-notes">Kurze Notiz (optional):</label>
                        <input type="text" id="submit-notes" name="notes" class="form-control" placeholder="z. B. Alles fertig geputzt!">
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Jetzt einreichen ✓</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Rezept vorschlagen -->
    <div id="modal-recipe" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3>🍳 Gericht vorschlagen</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-recipe">
                    <input type="hidden" id="recipe-task-id" name="task_id">
                    <div class="form-group">
                        <label for="recipe-title">Gerichtsname:</label>
                        <input type="text" id="recipe-title" name="recipe_title" class="form-control" placeholder="z. B. Selbstgemachte Pizza, Pfannkuchen..." required>
                    </div>
                    <div class="form-group">
                        <label for="recipe-details">Zutaten / Einkaufs-Wünsche:</label>
                        <textarea id="recipe-details" name="recipe_details" class="form-control" rows="3" placeholder="Welche Zutaten brauchen wir noch?"></textarea>
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Vorschlag abschicken</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Geschwisterhilfe melden (Co-Op) -->
    <div id="modal-coop" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3>🤝 Mithilfe melden</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-coop">
                    <input type="hidden" id="coop-task-id" name="task_id">
                    <p class="text-muted">Hast du bei dieser Aufgabe freiwillig mitgeholfen? Trage es ein und sichere dir deinen Helfer-Bonus!</p>
                    <div class="form-group">
                        <label for="coop-note">Was hast du übernommen?</label>
                        <input type="text" id="coop-note" name="note" class="form-control" placeholder="z. B. Gemüse geschnitten, Tisch abgeräumt..." required>
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Mithilfe anmelden</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Prämie einlösen -->
    <div id="modal-redeem" class="modal-overlay hidden">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="redeem-modal-title">Belohnung einlösen</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <form id="form-redeem">
                    <input type="hidden" id="redeem-reward-id" name="reward_id">
                    <p id="redeem-modal-desc" class="text-muted"></p>
                    <div class="form-group">
                        <label for="redeem-note">Möchtest du deinen Eltern etwas dazu sagen?</label>
                        <input type="text" id="redeem-note" name="note" class="form-control" placeholder="z. B. Gerne am Samstag einlösen">
                    </div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                        <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Antrag stellen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Quest schnappen (Claim) -->
    <div id="modal-claim-task" class="modal-overlay hidden">
        <div class="modal-card modal-card--sm">
            <div class="modal-header">
                <h3>⚡ Quest annehmen</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="claim-task-id" value="">
                <p id="claim-task-msg">Möchtest du dir diese Quest schnappen und für 12 Stunden für dich reservieren?</p>
                <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                    <button type="button" class="btn btn-outline modal-close">Abbrechen</button>
                    <button type="button" id="btn-confirm-claim" class="btn btn-primary">⚡ Quest jetzt starten!</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Quest freigeben (Release) -->
    <div id="modal-release-task" class="modal-overlay hidden">
        <div class="modal-card modal-card--sm">
            <div class="modal-header">
                <h3>↩️ Quest zurückgeben</h3>
                <button type="button" class="btn btn-outline btn-sm modal-close">✕</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="release-task-id" value="">
                <p id="release-task-msg">Möchtest du diese Quest wirklich wieder auf das Schwarze Brett legen, damit ein anderes Geschwisterkind sie übernehmen kann?</p>
                <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                    <button type="button" class="btn btn-outline modal-close">Behalten</button>
                    <button type="button" id="btn-confirm-release" class="btn btn-outline">↩️ Wieder freigeben</button>
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

    <footer class="app-footer">
        <div>kai v<?= APP_VERSION ?> · Familien-Quests 🏆</div>
    </footer>
</div>

<script src="../js/http.js?v=<?= APP_VERSION ?>"></script>
<script src="../js/gamification-emoji-picker.js?v=<?= APP_VERSION ?>"></script>
<script src="../js/gamification.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
