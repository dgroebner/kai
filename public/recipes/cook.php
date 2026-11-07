<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Recipes\RecipeRepository;
use Kai\Tools\Recipes\RecipeScaler;
use Kai\Tools\Recipes\RecipeTimelineService;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\SystemSettingsService;

// 1. Auth-Check — immer zuerst
Auth::requirePage('recipes_read');

$csrfToken = Auth::csrfToken();

$recipeId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$variantId = filter_var($_GET['variant_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

if (!$recipeId) {
    header('Location: index.php');
    exit;
}

$settings = new SystemSettingsService();
$repo = new RecipeRepository();
$scaler = new RecipeScaler($settings);
$timelineService = new RecipeTimelineService();

if (!function_exists('formatRecipeInstruction')) {
    function formatRecipeInstruction(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $formatted = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $escaped);
        return nl2br((string)$formatted);
    }
}

$rawRecipe = $repo->getRecipeById($recipeId, $variantId);
if (!$rawRecipe) {
    header('Location: index.php');
    exit;
}

$adults = isset($_GET['adults']) ? max(0, (int)$_GET['adults']) : (int)($rawRecipe['default_adults'] ?? 2);
$kids = isset($_GET['kids']) ? max(0, (int)$_GET['kids']) : (int)($rawRecipe['default_kids'] ?? 2);
if ($adults === 0 && $kids === 0) {
    $adults = 2;
}

$serveTime = isset($_GET['serve_time']) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim((string)$_GET['serve_time']))
    ? trim((string)$_GET['serve_time'])
    : null;

$recipe = $scaler->scaleRecipe($rawRecipe, $adults, $kids);
$timeline = $timelineService->buildSynchronizedTimeline($recipe, $serveTime);
$syncSteps = $timeline['synchronized_steps'];
$totalSteps = count($syncSteps);

$activeVariant = $recipe['active_variant'];
$activeVariantId = (int)$activeVariant['id'];
$stoveLevels = $settings->getStoveLevels();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>👨‍🍳 Koch-Modus: <?= htmlspecialchars((string)$recipe['title'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container recipe-cook-container"
     id="recipe-cook-root"
     data-total-steps="<?= $totalSteps ?>">

    <header class="page-header recipe-cook-header">
        <div>
            <span class="recipe-cook-mode-tag">👨‍🍳 Interaktiver Küchenbegleiter · <?= $adults ?> Erw. + <?= $kids ?> Ki.</span>
            <h1 class="recipe-detail-h1"><?= htmlspecialchars((string)$recipe['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
        <div class="page-header-actions">
            <span class="recipe-wakelock-pill" id="wakelock-status-badge" title="Verhindert das automatische Abschalten des Displays beim Kochen">
                💡 Display bleibt aktiv
            </span>
            <a href="view.php?id=<?= (int)$recipe['id'] ?>&amp;variant_id=<?= $activeVariantId ?>&amp;adults=<?= $adults ?>&amp;kids=<?= $kids ?><?= $serveTime ? '&amp;serve_time=' . urlencode($serveTime) : '' ?>"
               class="btn btn-outline">&larr; Zurück zur Rezeptansicht</a>
        </div>
    </header>

    <main>
        <!-- AKTIVE PARALLEL-TIMER DOCK (für mehrere Töpfe gleichzeitig) -->
        <section id="cook-timers-dock" class="card recipe-timers-dock hidden" aria-live="polite">
            <div class="recipe-timers-dock-header">
                <strong>⏱️ Laufende Küchen-Timer</strong>
                <button type="button" class="btn btn-sm btn-outline js-add-custom-timer" data-read-interactive="1">+ Eigener Timer</button>
            </div>
            <div id="cook-timers-list" class="recipe-timers-grid"></div>
        </section>

        <!-- ANSICHTS-UMSCHALTER: FOKUS-KARTE vs. CHECKLISTE ALLER SCHRITTE -->
        <div class="recipe-cook-toolbar">
            <div class="recipe-cook-view-tabs">
                <button type="button" class="btn btn-primary js-cook-view-btn" data-cook-view="focus" data-read-interactive="1">
                    🎯 Schritt-für-Schritt (Großansicht)
                </button>
                <button type="button" class="btn btn-outline js-cook-view-btn" data-cook-view="all" data-read-interactive="1">
                    📋 Alle <?= $totalSteps ?> Schritte &amp; Töpfe
                </button>
            </div>

            <div class="recipe-cook-progress-wrap">
                <span id="cook-step-counter-label" class="recipe-cook-progress-text">Schritt 1 von <?= max(1, $totalSteps) ?></span>
            </div>
        </div>

        <?php if (empty($syncSteps)): ?>
            <section class="card">
                <p class="text-muted">Für dieses Rezept sind noch keine Kochschritte hinterlegt.</p>
            </section>
        <?php else: ?>
            <!-- ANSICHT 1: FOKUS-MODUS (GROSSE KARTEN FÜR DIE KÜCHE) -->
            <section id="cook-view-focus" class="recipe-cook-focus-stage">
                <?php foreach ($syncSteps as $idx => $step): ?>
                    <?php
                    $stepNum = (int)$step['sync_step_index'];
                    $durMin = (int)($step['duration_minutes'] ?? 0);
                    $timerTitle = ($step['title'] ?: $step['sync_badge']) . ' (Schritt ' . $stepNum . ')';
                    ?>
                    <article class="card recipe-focus-card <?= $idx === 0 ? '' : 'hidden' ?>"
                             data-step-index="<?= $idx ?>"
                             data-step-number="<?= $stepNum ?>">

                        <div class="recipe-focus-card-top">
                            <span class="recipe-focus-step-pill">Schritt <?= $stepNum ?> von <?= $totalSteps ?></span>
                            <span class="recipe-step-comp-badge"><?= htmlspecialchars((string)$step['sync_badge'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="recipe-focus-time-pill">
                                <?= !empty($step['clock_time']) ? '🕒 ' . htmlspecialchars((string)$step['clock_time'], ENT_QUOTES, 'UTF-8') . ' Uhr' : '⏳ ' . htmlspecialchars((string)$step['relative_label'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>

                        <?php if (!empty($step['title'])): ?>
                            <h2 class="recipe-focus-title"><?= htmlspecialchars((string)$step['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <?php endif; ?>

                        <!-- Herd-Stufe (1-12) & Ofen-Einstellung prominent hervorgehoben -->
                        <?php if (!empty($step['stove_level']) || !empty($step['oven_setting'])): ?>
                            <div class="recipe-focus-appliance-bar">
                                <?php if (!empty($step['stove_level'])): ?>
                                    <div class="recipe-focus-stove-box">
                                        <span class="recipe-focus-appliance-icon">🔥</span>
                                        <div>
                                            <span class="recipe-focus-appliance-label">Herd-Einstellung (Skala 1–<?= (int)$stoveLevels ?>)</span>
                                            <strong class="recipe-focus-appliance-val"><?= htmlspecialchars((string)$step['stove_level'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($step['oven_setting'])): ?>
                                    <div class="recipe-focus-stove-box">
                                        <span class="recipe-focus-appliance-icon">🌀</span>
                                        <div>
                                            <span class="recipe-focus-appliance-label">Backofen</span>
                                            <strong class="recipe-focus-appliance-val"><?= htmlspecialchars((string)$step['oven_setting'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Inline-Zutaten für diesen Schritt (groß & sofort ablesbar) -->
                        <?php if (!empty($step['ingredients_inline_scaled'])): ?>
                            <div class="recipe-focus-ingredients-box">
                                <span class="recipe-focus-ing-heading">🥄 Benötigte Zutaten in diesem Schritt:</span>
                                <div class="recipe-focus-ing-pills">
                                    <?php foreach ($step['ingredients_inline_scaled'] as $inItem): ?>
                                        <span class="recipe-focus-ing-pill"><?= htmlspecialchars((string)$inItem['display'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Haupt-Anweisung in großer Küchentypografie -->
                        <div class="recipe-focus-instruction">
                            <?= formatRecipeInstruction((string)$step['instruction']) ?>
                        </div>

                        <?php if (!empty($step['parallel_hint'])): ?>
                            <div class="recipe-step-parallel-note recipe-mt-md">
                                💡 <strong>Währenddessen:</strong> <?= htmlspecialchars((string)$step['parallel_hint'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>

                        <!-- One-Click Timer-Button für diesen Schritt -->
                        <?php if ($durMin > 0): ?>
                            <div class="recipe-focus-timer-cta">
                                <button type="button"
                                        class="btn btn-primary btn-lg js-start-step-timer"
                                        data-minutes="<?= $durMin ?>"
                                        data-label="<?= htmlspecialchars((string)$timerTitle, ENT_QUOTES, 'UTF-8') ?>"
                                        data-read-interactive="1">
                                    ⏱️ Timer starten (<?= $durMin ?> Min.)
                                </button>
                            </div>
                        <?php endif; ?>

                        <!-- Navigation: Zurück / Schritt erledigt & Weiter -->
                        <div class="recipe-focus-nav-bar">
                            <button type="button"
                                    class="btn btn-outline btn-lg js-cook-prev-step"
                                    <?= $idx === 0 ? 'disabled' : '' ?>
                                    data-read-interactive="1">
                                &larr; Vorheriger Schritt
                            </button>

                            <?php if ($idx < $totalSteps - 1): ?>
                                <button type="button"
                                        class="btn btn-primary btn-lg js-cook-next-step"
                                        data-read-interactive="1">
                                    Schritt erledigt &amp; Weiter &rarr;
                                </button>
                            <?php else: ?>
                                <a href="view.php?id=<?= (int)$recipe['id'] ?>&amp;variant_id=<?= $activeVariantId ?>"
                                   class="btn btn-primary btn-lg">
                                    🎉 Fertig gekocht! Guten Appetit!
                                </a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <!-- ANSICHT 2: ALLE SCHRITTE & TÖPFE ALS ABHAK-LISTE -->
            <section id="cook-view-all" class="card hidden">
                <h2>📋 Alle synchronisierten Schritte zum Abhaken</h2>
                <div class="recipe-sync-steps-timeline">
                    <?php foreach ($syncSteps as $idx => $step): ?>
                        <?php
                        $stepNum = (int)$step['sync_step_index'];
                        $durMin = (int)($step['duration_minutes'] ?? 0);
                        $timerTitle = ($step['title'] ?: $step['sync_badge']) . ' (Schritt ' . $stepNum . ')';
                        ?>
                        <div class="recipe-step-card js-cook-checklist-row" data-step-index="<?= $idx ?>">
                            <div class="recipe-step-number-col">
                                <input type="checkbox" class="recipe-step-done-cb js-step-done-cb" data-step-index="<?= $idx ?>" data-read-interactive="1">
                                <span class="recipe-step-num"><?= $stepNum ?></span>
                            </div>
                            <div class="recipe-step-body">
                                <div class="recipe-step-badges">
                                    <span class="recipe-step-comp-badge"><?= htmlspecialchars((string)$step['sync_badge'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($step['stove_level'])): ?>
                                        <span class="recipe-step-stove-badge">🔥 <?= htmlspecialchars((string)$step['stove_level'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <?php if ($durMin > 0): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline js-start-step-timer"
                                                data-minutes="<?= $durMin ?>"
                                                data-label="<?= htmlspecialchars((string)$timerTitle, ENT_QUOTES, 'UTF-8') ?>"
                                                data-read-interactive="1">
                                            ⏱️ Timer <?= $durMin ?> Min. starten
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline js-jump-to-step" data-step-index="<?= $idx ?>" data-read-interactive="1">
                                        🎯 Fokus
                                    </button>
                                </div>

                                <?php if (!empty($step['title'])): ?>
                                    <h3 class="recipe-step-title"><?= htmlspecialchars((string)$step['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <?php endif; ?>

                                <?php if (!empty($step['ingredients_inline_scaled'])): ?>
                                    <div class="recipe-inline-ing-pills">
                                        <?php foreach ($step['ingredients_inline_scaled'] as $inItem): ?>
                                            <span class="recipe-inline-ing-pill">🥄 <strong><?= htmlspecialchars((string)$inItem['display'], ENT_QUOTES, 'UTF-8') ?></strong></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <p class="recipe-step-instruction"><?= formatRecipeInstruction((string)$step['instruction']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>

<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
<script src="../js/recipes.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
