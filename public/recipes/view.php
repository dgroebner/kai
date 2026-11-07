<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Recipes\RecipeRepository;
use Kai\Tools\Recipes\RecipeScaler;
use Kai\Tools\Recipes\RecipeTimelineService;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\SystemSettingsService;

// 1. Auth-Check — immer zuerst
Auth::requirePage('recipes_read');

$canWrite = Auth::hasPermission('recipes_write');
$csrfToken = Auth::csrfToken();

$recipeId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$variantId = filter_var($_GET['variant_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

if (!$recipeId) {
    header('Location: index.php');
    exit;
}

$repo = new RecipeRepository();
$scaler = new RecipeScaler();
$timelineService = new RecipeTimelineService();
$settings = new SystemSettingsService();

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

$activeVariant = $recipe['active_variant'];
$activeVariantId = (int)$activeVariant['id'];
$variants = $recipe['variants'] ?? [];

$stoveType = $settings->getStoveType();
$stoveLevels = $settings->getStoveLevels();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars((string)$recipe['title'], ENT_QUOTES, 'UTF-8') ?> - Kochrezepte</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container"
     id="recipe-detail-root"
     data-recipe-id="<?= (int)$recipe['id'] ?>"
     data-variant-id="<?= $activeVariantId ?>"
     data-recipe-title="<?= htmlspecialchars((string)$recipe['title'], ENT_QUOTES, 'UTF-8') ?>">

    <header class="page-header">
        <div>
            <span class="recipe-cat-badge"><?= htmlspecialchars((string)$recipe['category'], ENT_QUOTES, 'UTF-8') ?></span>
            <h1 class="recipe-detail-h1"><?= htmlspecialchars((string)$recipe['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
        <div class="page-header-actions">
            <a href="index.php" class="btn btn-outline">&larr; Alle Rezepte</a>
            <a href="cook.php?id=<?= (int)$recipe['id'] ?>&amp;variant_id=<?= $activeVariantId ?>&amp;adults=<?= $adults ?>&amp;kids=<?= $kids ?><?= $serveTime ? '&amp;serve_time=' . urlencode($serveTime) : '' ?>"
               id="btn-launch-cook-mode"
               class="btn btn-primary">👨‍🍳 Koch-Modus starten</a>
            <?php if ($canWrite): ?>
                <button type="button" class="btn btn-outline recipe-btn-danger" id="btn-delete-recipe" data-recipe-id="<?= (int)$recipe['id'] ?>">🗑️ Löschen</button>
            <?php endif; ?>
        </div>
    </header>

    <main>
        <!-- Kurzbeschreibung, Quelle & Nährwerte -->
        <section class="card recipe-overview-card">
            <?php if (!empty($recipe['description'])): ?>
                <p class="recipe-lead-desc"><?= htmlspecialchars((string)$recipe['description'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <div class="kpi-grid recipe-kpi-grid">
                <div class="kpi-card">
                    <span class="kpi-label">⏱️ Gesamtzeit (Synchron)</span>
                    <span class="kpi-value"><?= (int)$timeline['total_synchronized_minutes'] ?> Min.</span>
                    <span class="kpi-sub">Vorbereitung: <?= (int)$timeline['pre_prep_minutes'] ?> Min. · Herd: <?= (int)$timeline['max_cook_minutes'] ?> Min.</span>
                </div>
                <div class="kpi-card">
                    <span class="kpi-label">🔥 Herd-Konfiguration</span>
                    <span class="kpi-value"><?= htmlspecialchars($stoveType, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="kpi-sub">Konkrete Stufen 1 bis <?= (int)$stoveLevels ?></span>
                </div>
                <div class="kpi-card">
                    <span class="kpi-label">🥗 Nährwerte (pro Portion)</span>
                    <span class="kpi-value">
                        <?= !empty($recipe['calories_per_serving']) ? (int)$recipe['calories_per_serving'] . ' kcal' : 'k. A.' ?>
                    </span>
                    <span class="kpi-sub">
                        EW: <?= $recipe['protein_per_serving'] !== null ? number_format((float)$recipe['protein_per_serving'], 1, ',', '.') . ' g' : '–' ?> ·
                        KH: <?= $recipe['carbs_per_serving'] !== null ? number_format((float)$recipe['carbs_per_serving'], 1, ',', '.') . ' g' : '–' ?> ·
                        Fett: <?= $recipe['fat_per_serving'] !== null ? number_format((float)$recipe['fat_per_serving'], 1, ',', '.') . ' g' : '–' ?>
                    </span>
                </div>
                <?php if (!empty($recipe['source_url'])): ?>
                    <div class="kpi-card">
                        <span class="kpi-label">🔗 Original-Quelle</span>
                        <span class="kpi-value recipe-source-link-wrap">
                            <a href="<?= htmlspecialchars((string)$recipe['source_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn-link">Webseite öffnen &nearr;</a>
                        </span>
                        <span class="kpi-sub">Importiert &amp; aufbereitet</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Varianten-Umschalter (Rezeptabwandlungen) -->
            <div class="recipe-variants-bar">
                <div class="recipe-variants-list">
                    <span class="recipe-variants-label">🔀 Rezept-Variante:</span>
                    <?php foreach ($variants as $v): ?>
                        <?php $vid = (int)$v['id']; ?>
                        <a href="view.php?id=<?= (int)$recipe['id'] ?>&amp;variant_id=<?= $vid ?>&amp;adults=<?= $adults ?>&amp;kids=<?= $kids ?>"
                           class="btn btn-sm <?= $vid === $activeVariantId ? 'btn-primary' : 'btn-outline' ?>">
                            <?= htmlspecialchars((string)$v['variant_name'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($canWrite && count($variants) > 1): ?>
                    <button type="button" class="btn btn-sm btn-outline recipe-btn-danger" id="btn-delete-variant" data-variant-id="<?= $activeVariantId ?>">
                        Variante löschen
                    </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($activeVariant['notes'])): ?>
                <div class="recipe-variant-note-banner">
                    💡 <strong>Hinweis zu dieser Variante:</strong> <?= htmlspecialchars((string)$activeVariant['notes'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- PORTIONEN-SKALIERER & MULTI-TOPF ZEITPLANER -->
        <div class="recipe-control-grid">
            <!-- Links: Portionen & Packungsgrößen -->
            <section class="card recipe-scaler-card">
                <h2>👨‍👩‍👧‍👦 Portionen &amp; Packungsgrößen</h2>
                <p class="text-muted">Skaliert automatisch alle Zutaten, Inline-Mengen in den Kochschritten und prüft Supermarkt-Packungsgrößen gegen Verschwendung.</p>

                <div class="recipe-portion-controls">
                    <div class="recipe-portion-box">
                        <span class="recipe-portion-title">🧑 Erwachsene (100 %)</span>
                        <div class="recipe-stepper">
                            <button type="button" class="btn btn-sm btn-outline js-portion-step" data-target="adults" data-delta="-1" data-read-interactive="1">&minus;</button>
                            <input type="number" id="input-adults" class="form-control form-control-sm recipe-portion-input" value="<?= $adults ?>" min="0" max="20" data-read-interactive="1">
                            <button type="button" class="btn btn-sm btn-outline js-portion-step" data-target="adults" data-delta="1" data-read-interactive="1">+</button>
                        </div>
                    </div>

                    <div class="recipe-portion-box">
                        <span class="recipe-portion-title">🧒 Kinder / Jugendliche (80 %)</span>
                        <div class="recipe-stepper">
                            <button type="button" class="btn btn-sm btn-outline js-portion-step" data-target="kids" data-delta="-1" data-read-interactive="1">&minus;</button>
                            <input type="number" id="input-kids" class="form-control form-control-sm recipe-portion-input" value="<?= $kids ?>" min="0" max="20" data-read-interactive="1">
                            <button type="button" class="btn btn-sm btn-outline js-portion-step" data-target="kids" data-delta="1" data-read-interactive="1">+</button>
                        </div>
                    </div>
                </div>

                <div class="recipe-scaler-summary">
                    <span>Berechnete Gesamtmenge: <strong id="label-effective-servings"><?= str_replace('.', ',', (string)$recipe['scaling']['target_servings']) ?></strong> Vollportionen</span>
                    <?php if ($canWrite): ?>
                        <button type="button" class="btn btn-primary btn-sm" id="btn-open-shopping-modal">
                            🛒 Zutaten auf Einkaufsliste setzen
                        </button>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Rechts: Multi-Topf Timing-Synchronisation ("Gleichzeitig fertig") -->
            <section class="card recipe-timing-card">
                <h2>⏱️ „Gleichzeitig fertig“-Planer (Multi-Topf)</h2>
                <p class="text-muted">Wann wollt ihr essen? Kai rechnet rückwärts inkl. Wasser-Aufheizzeit (Stufe <?= (int)$stoveLevels ?>) und Schmorphasen, wann welcher Topf starten muss.</p>

                <div class="recipe-serve-time-row">
                    <label for="input-serve-time" class="recipe-serve-label">🍽️ Gewünschte Essenszeit:</label>
                    <input type="time" id="input-serve-time" class="form-control form-control-sm recipe-time-input"
                           value="<?= htmlspecialchars($serveTime ?? '13:00', ENT_QUOTES, 'UTF-8') ?>" data-read-interactive="1">
                    <button type="button" class="btn btn-sm btn-primary" id="btn-apply-serve-time" data-read-interactive="1">Zeitplan berechnen</button>
                    <?php if ($serveTime !== null): ?>
                        <button type="button" class="btn btn-sm btn-outline" id="btn-clear-serve-time" data-read-interactive="1">Relativ (T+0)</button>
                    <?php endif; ?>
                </div>

                <div class="recipe-component-sync-list" id="component-sync-summary">
                    <?php if ($timeline['recommended_start_time'] !== null): ?>
                        <div class="recipe-sync-start-banner">
                            🔔 <strong>Start Vorbereitung (Mise en Place): <?= htmlspecialchars($timeline['recommended_start_time'], ENT_QUOTES, 'UTF-8') ?> Uhr</strong>
                            · Herd-Start: <?= htmlspecialchars((string)$timeline['stove_start_time'], ENT_QUOTES, 'UTF-8') ?> Uhr
                            · Servieren: <strong><?= htmlspecialchars((string)$timeline['target_serve_time'], ENT_QUOTES, 'UTF-8') ?> Uhr</strong>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($timeline['component_summaries'] as $cs): ?>
                        <div class="recipe-comp-sync-row">
                            <div class="recipe-comp-sync-name">
                                <strong>🍲 <?= htmlspecialchars((string)$cs['component_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if (!empty($cs['pot_or_appliance'])): ?>
                                    <span class="text-muted">(<?= htmlspecialchars((string)$cs['pot_or_appliance'], ENT_QUOTES, 'UTF-8') ?>)</span>
                                <?php endif; ?>
                            </div>
                            <div class="recipe-comp-sync-timing">
                                <?php if (!empty($cs['clock_start'])): ?>
                                    <span class="recipe-clock-badge">🕒 Start: <?= htmlspecialchars((string)$cs['clock_start'], ENT_QUOTES, 'UTF-8') ?> Uhr</span>
                                <?php else: ?>
                                    <span class="recipe-clock-badge">⏳ Start: T + <?= (int)$cs['start_offset_from_begin_min'] ?> Min.</span>
                                <?php endif; ?>
                                <span class="recipe-dur-pill"><?= (int)$cs['duration_minutes'] ?> Min. Laufzeit</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <!-- ZUTATEN: FRISCHEARTIKEL vs. VORRATSSCHRANK-CHECK -->
        <div class="recipe-ingredients-grid">
            <!-- Frische Einkaufszutaten -->
            <section class="card">
                <div class="recipe-section-header">
                    <h2>🥩🥕 Frische- &amp; Hauptzutaten</h2>
                    <span class="text-muted"><?= count($recipe['fresh_ingredients']) ?> Positionen</span>
                </div>
                <p class="text-muted recipe-sub-hint">Klicke auf <strong>„🔄 Alternative“</strong>, wenn ihr heute keine Lust auf eine Zutat habt (z. B. Erbsen oder Reis) – Kai passt das Rezept an.</p>

                <ul class="recipe-ingredient-list" id="list-fresh-ingredients">
                    <?php foreach ($recipe['fresh_ingredients'] as $ing): ?>
                        <?php $pack = $ing['package_analysis'] ?? null; ?>
                        <li class="recipe-ingredient-item" data-ingredient-name="<?= htmlspecialchars((string)$ing['ingredient_name'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="recipe-ing-main">
                                <span class="recipe-ing-qty js-ing-qty"><?= htmlspecialchars((string)$ing['scaled_display'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="recipe-ing-name"><?= htmlspecialchars((string)$ing['ingredient_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if (!empty($ing['prep_note'])): ?>
                                    <span class="recipe-ing-prep">(<?= htmlspecialchars((string)$ing['prep_note'], ENT_QUOTES, 'UTF-8') ?>)</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($pack !== null): ?>
                                <div class="recipe-pack-hint <?= !empty($pack['is_exact_match']) ? 'is-exact' : 'has-leftover' ?>">
                                    📦 Handel: <strong><?= (int)$pack['packages_to_buy'] ?>&times; <?= htmlspecialchars((string)$pack['package_label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    (<?= htmlspecialchars((string)$pack['leftover_display'], ENT_QUOTES, 'UTF-8') ?>)
                                    <?php if (!$pack['is_exact_match'] && !empty($pack['leftover_tip'])): ?>
                                        <div class="recipe-leftover-tip">💡 <?= htmlspecialchars((string)$pack['leftover_tip'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($canWrite): ?>
                                <div class="recipe-ing-actions">
                                    <button type="button"
                                            class="btn btn-sm btn-outline js-open-alt-modal"
                                            data-ingredient="<?= htmlspecialchars((string)$ing['ingredient_name'], ENT_QUOTES, 'UTF-8') ?>">
                                        🔄 Alternative
                                    </button>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <!-- Vorratsschrank-Prüfung (Gewürze, Öl, Salz, Schalotten, Knoblauch) -->
            <section class="card recipe-pantry-card">
                <div class="recipe-section-header">
                    <h2>🧂🫙 Vorratsschrank-Check</h2>
                    <span class="recipe-pantry-badge">Vor dem Einkauf kurz prüfen</span>
                </div>
                <p class="text-muted recipe-sub-hint">
                    Gewürze, Öl, Salz sowie Schalotten/Knoblauch sind meistens zu Hause vorrätig und werden nicht blind auf die Einkaufsliste gesetzt.
                    Prüfe kurz, ob noch genug im Schrank steht:
                </p>

                <?php if (empty($recipe['pantry_ingredients'])): ?>
                    <p class="text-muted">Keine separaten Vorratszutaten hinterlegt.</p>
                <?php else: ?>
                    <ul class="recipe-ingredient-list" id="list-pantry-ingredients">
                        <?php foreach ($recipe['pantry_ingredients'] as $ing): ?>
                            <li class="recipe-ingredient-item recipe-ingredient-item--pantry">
                                <div class="recipe-ing-main">
                                    <span class="recipe-ing-qty js-ing-qty"><?= htmlspecialchars((string)$ing['scaled_display'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="recipe-ing-name"><?= htmlspecialchars((string)$ing['ingredient_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($ing['prep_note'])): ?>
                                        <span class="recipe-ing-prep">(<?= htmlspecialchars((string)$ing['prep_note'], ENT_QUOTES, 'UTF-8') ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="recipe-ing-actions">
                                    <span class="recipe-pantry-check-hint">🔍 Vorrat prüfen</span>
                                    <?php if ($canWrite): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline js-open-alt-modal"
                                                data-ingredient="<?= htmlspecialchars((string)$ing['ingredient_name'], ENT_QUOTES, 'UTF-8') ?>">
                                            🔄
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>

        <!-- INTELLIGENTES 2-PHASEN MISE EN PLACE (VORBEREITUNG) -->
        <section class="card recipe-mise-card">
            <h2>🔪 Intelligente Vorbereitung (Mise en Place)</h2>

            <?php if (!empty($recipe['equipment_parsed'])): ?>
                <div class="recipe-equipment-bar">
                    <strong>🍳 Benötigtes Küchenzubehör bereitstellen:</strong>
                    <div class="recipe-equipment-pills">
                        <?php foreach ($recipe['equipment_parsed'] as $eq): ?>
                            <span class="recipe-equipment-pill"><?= htmlspecialchars((string)$eq, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="recipe-mise-phases-grid">
                <!-- Phase 1: Vor dem Einschalten des Herdes -->
                <div class="recipe-mise-phase-box recipe-mise-phase--pre">
                    <h3>1️⃣ Vor dem Einschalten des Herdes erledigen</h3>
                    <p class="text-muted">Damit am heißen Herd keine Hektik entsteht, das hier zuerst fertig hinstellen:</p>
                    <?php if (empty($timeline['mise_en_place_pre'])): ?>
                        <p class="text-muted">Alle Zutaten direkt griffbereit stellen.</p>
                    <?php else: ?>
                        <ul class="recipe-mise-step-list">
                            <?php foreach ($timeline['mise_en_place_pre'] as $mStep): ?>
                                <li>
                                    <strong><?= htmlspecialchars((string)($mStep['title'] ?: 'Vorbereitung'), ENT_QUOTES, 'UTF-8') ?>:</strong>
                                    <?= htmlspecialchars((string)$mStep['instruction'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($mStep['ingredients_inline_scaled'])): ?>
                                        <div class="recipe-inline-ing-pills">
                                            <?php foreach ($mStep['ingredients_inline_scaled'] as $inItem): ?>
                                                <span class="recipe-inline-ing-pill">🥄 <?= htmlspecialchars((string)$inItem['display'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Phase 2: Entspannt während der Schmor-/Kochpausen -->
                <div class="recipe-mise-phase-box recipe-mise-phase--parallel">
                    <h3>2️⃣ Entspannt während der Kochzeit erledigen</h3>
                    <p class="text-muted">Nicht schon vorher Stress machen – für diese Aufgaben hast du während der Schmor-/Garzeit locker Zeit:</p>
                    <?php if (empty($timeline['mise_en_place_parallel'])): ?>
                        <p class="text-muted">Bei diesem schnellen Gericht gibt es keine langen passiven Schmorzeiten.</p>
                    <?php else: ?>
                        <ul class="recipe-mise-step-list">
                            <?php foreach ($timeline['mise_en_place_parallel'] as $pStep): ?>
                                <li>
                                    <span class="recipe-parallel-time-badge">
                                        <?= !empty($pStep['clock_time']) ? htmlspecialchars((string)$pStep['clock_time'], ENT_QUOTES, 'UTF-8') . ' Uhr' : htmlspecialchars((string)$pStep['relative_label'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <strong><?= htmlspecialchars((string)($pStep['title'] ?: 'Parallel-Vorbereitung'), ENT_QUOTES, 'UTF-8') ?>:</strong>
                                    <?= htmlspecialchars((string)$pStep['instruction'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($pStep['ingredients_inline_scaled'])): ?>
                                        <div class="recipe-inline-ing-pills">
                                            <?php foreach ($pStep['ingredients_inline_scaled'] as $inItem): ?>
                                                <span class="recipe-inline-ing-pill">🥄 <?= htmlspecialchars((string)$inItem['display'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- SYNCHRONISIERTER KOCHABLAUF (ALLE TÖPFE IN CHRONOLOGISCHER REIHENFOLGE) -->
        <section class="card">
            <div class="recipe-section-header">
                <div>
                    <h2>👩‍🍳 Synchronisierter Kochablauf (Schritt für Schritt)</h2>
                    <p class="text-muted">Alle Mengenangaben stehen direkt im Schritt – kein Hochscrollen nötig! Herdstufen bezogen auf euer <?= (int)$stoveLevels ?>-Stufen-Ceranfeld.</p>
                </div>
                <a href="cook.php?id=<?= (int)$recipe['id'] ?>&amp;variant_id=<?= $activeVariantId ?>&amp;adults=<?= $adults ?>&amp;kids=<?= $kids ?><?= $serveTime ? '&amp;serve_time=' . urlencode($serveTime) : '' ?>"
                   class="btn btn-primary">👨‍🍳 Großansicht (Koch-Modus)</a>
            </div>

            <div class="recipe-sync-steps-timeline" id="synchronized-steps-container">
                <?php foreach ($timeline['synchronized_steps'] as $step): ?>
                    <div class="recipe-step-card phase-<?= htmlspecialchars((string)($step['phase'] ?? 'cooking'), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="recipe-step-number-col">
                            <span class="recipe-step-num"><?= (int)$step['sync_step_index'] ?></span>
                            <?php if (!empty($step['clock_time'])): ?>
                                <span class="recipe-step-clock"><?= htmlspecialchars((string)$step['clock_time'], ENT_QUOTES, 'UTF-8') ?> Uhr</span>
                            <?php else: ?>
                                <span class="recipe-step-clock"><?= htmlspecialchars((string)$step['relative_label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="recipe-step-body">
                            <div class="recipe-step-badges">
                                <span class="recipe-step-comp-badge"><?= htmlspecialchars((string)$step['sync_badge'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if (!empty($step['stove_level'])): ?>
                                    <span class="recipe-step-stove-badge">🔥 Herd: <?= htmlspecialchars((string)$step['stove_level'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                                <?php if (!empty($step['oven_setting'])): ?>
                                    <span class="recipe-step-oven-badge">🌀 Ofen: <?= htmlspecialchars((string)$step['oven_setting'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                                <?php if ((int)($step['duration_minutes'] ?? 0) > 0): ?>
                                    <span class="recipe-step-dur-badge">⏱️ <?= (int)$step['duration_minutes'] ?> Min.</span>
                                <?php endif; ?>
                                <?php if (!empty($step['is_passive_wait'])): ?>
                                    <span class="recipe-step-passive-badge">⏳ Köchelt von allein (Zeit für Nebenarbeiten)</span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($step['title'])): ?>
                                <h3 class="recipe-step-title"><?= htmlspecialchars((string)$step['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <?php endif; ?>

                            <!-- Dynamisch skalierte Inline-Zutaten für diesen Schritt -->
                            <?php if (!empty($step['ingredients_inline_scaled'])): ?>
                                <div class="recipe-inline-ing-pills">
                                    <span class="recipe-inline-ing-label">Zutaten in diesem Schritt:</span>
                                    <?php foreach ($step['ingredients_inline_scaled'] as $inItem): ?>
                                        <span class="recipe-inline-ing-pill">🥄 <strong><?= htmlspecialchars((string)$inItem['display'], ENT_QUOTES, 'UTF-8') ?></strong></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <p class="recipe-step-instruction"><?= nl2br(htmlspecialchars((string)$step['instruction'], ENT_QUOTES, 'UTF-8')) ?></p>

                            <?php if (!empty($step['parallel_hint'])): ?>
                                <div class="recipe-step-parallel-note">
                                    💡 <strong>Parallel-Tipp:</strong> <?= htmlspecialchars((string)$step['parallel_hint'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</div>

<!-- MODAL 1: EINKAUFSLISTE MIT VORRATSSCHRANK-FILTER -->
<?php if ($canWrite): ?>
<div id="modal-recipe-shopping" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="modal-shopping-title">
    <div class="modal-content recipe-modal-lg">
        <div class="modal-header">
            <h3 id="modal-shopping-title">🛒 Zutaten auf die Einkaufsliste setzen</h3>
            <button type="button" class="modal-close js-close-shopping-modal" aria-label="Schließen">&times;</button>
        </div>
        <div class="modal-body">
            <p class="text-muted">
                Frische Zutaten sind bereits vorausgewählt (inkl. Packungsgrößen-Aufrundung).
                <strong>Vorratsartikel</strong> (Gewürze, Öl, Salz, Schalotten) sind unten abgewählt – hake sie nur an, falls dein Vorrat zu Hause leer ist!
            </p>

            <h4 class="recipe-modal-sec-title">🥩🥕 Frische Zutaten (Einkaufen)</h4>
            <div class="recipe-shopping-check-list" id="shopping-modal-fresh-list">
                <?php foreach ($recipe['fresh_ingredients'] as $idx => $ing): ?>
                    <?php
                    $pack = $ing['package_analysis'] ?? null;
                    $buyQty = $pack !== null ? (float)$pack['total_purchased'] : (float)$ing['scaled_quantity'];
                    $buyUnit = $pack !== null ? (string)$pack['package_unit'] : (string)$ing['unit'];
                    ?>
                    <label class="recipe-shop-item">
                        <input type="checkbox" class="js-shop-item-cb" checked
                               data-name="<?= htmlspecialchars((string)$ing['ingredient_name'], ENT_QUOTES, 'UTF-8') ?>"
                               data-quantity="<?= htmlspecialchars((string)$buyQty, ENT_QUOTES, 'UTF-8') ?>"
                               data-unit="<?= htmlspecialchars($buyUnit, ENT_QUOTES, 'UTF-8') ?>"
                               data-market="<?= htmlspecialchars((string)($ing['market'] ?? 'Rewe'), ENT_QUOTES, 'UTF-8') ?>"
                               data-category="<?= htmlspecialchars((string)($ing['shopping_category'] ?? 'Sonstiges'), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="recipe-shop-item-name">
                            <strong><?= htmlspecialchars((string)$ing['scaled_display'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$ing['ingredient_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if ($pack !== null): ?>
                                <small class="text-muted">(Einkauf: <?= (int)$pack['packages_to_buy'] ?>&times; <?= htmlspecialchars((string)$pack['package_label'], ENT_QUOTES, 'UTF-8') ?>)</small>
                            <?php endif; ?>
                        </span>
                        <span class="recipe-shop-market-badge"><?= htmlspecialchars((string)($ing['market'] ?? 'Rewe'), ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($recipe['pantry_ingredients'])): ?>
                <h4 class="recipe-modal-sec-title recipe-mt-md">🧂🫙 Vorratsschrank-Artikel (Nur anhaken, wenn Vorrat leer!)</h4>
                <div class="recipe-shopping-check-list" id="shopping-modal-pantry-list">
                    <?php foreach ($recipe['pantry_ingredients'] as $ing): ?>
                        <label class="recipe-shop-item recipe-shop-item--pantry">
                            <input type="checkbox" class="js-shop-item-cb"
                                   data-name="<?= htmlspecialchars((string)$ing['ingredient_name'], ENT_QUOTES, 'UTF-8') ?>"
                                   data-quantity="1"
                                   data-unit="Packung"
                                   data-market="<?= htmlspecialchars((string)($ing['market'] ?? 'Rewe'), ENT_QUOTES, 'UTF-8') ?>"
                                   data-category="<?= htmlspecialchars((string)($ing['shopping_category'] ?? 'Gewürze, Öle & Fertiggerichte'), ENT_QUOTES, 'UTF-8') ?>">
                            <span class="recipe-shop-item-name">
                                <?= htmlspecialchars((string)$ing['ingredient_name'], ENT_QUOTES, 'UTF-8') ?>
                                <small class="text-muted">(Rezept benötigt <?= htmlspecialchars((string)$ing['scaled_display'], ENT_QUOTES, 'UTF-8') ?> – im Schrank prüfen)</small>
                            </span>
                            <span class="recipe-shop-market-badge">Vorrat</span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="modal-actions recipe-modal-footer">
                <button type="button" class="btn btn-outline modal-close js-close-shopping-modal">Abbrechen</button>
                <button type="button" class="btn btn-primary" id="btn-confirm-add-shopping">🛒 Ausgewählte Artikel auf Einkaufsliste setzen</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 2: KI-ZUTATENTAUSCH ("ALTERNATIVE SUCHEN" & ALS REZEPTABWANDLUNG SPEICHERN) -->
<div id="modal-recipe-alternatives" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="modal-alt-title">
    <div class="modal-content recipe-modal-lg">
        <div class="modal-header">
            <h3 id="modal-alt-title">🔄 Zutat austauschen &amp; Rezeptabwandlung speichern</h3>
            <button type="button" class="modal-close js-close-alt-modal" aria-label="Schließen">&times;</button>
        </div>
        <div class="modal-body">
            <p class="text-muted">
                Du möchtest <strong id="alt-target-ingredient-label">diese Zutat</strong> ersetzen?
                Frag Gemini nach passenden Alternativen (inkl. Garzeit-Anpassung) oder gib direkt deinen eigenen Wunsch ein.
            </p>

            <div class="recipe-alt-search-row">
                <input type="text" id="input-alt-reason" class="form-control"
                       placeholder="Optionaler Wunsch (z. B. 'Lieber anderes Gemüse', 'Vegetarisch', 'Haben noch Reis da')...">
                <button type="button" class="btn btn-primary" id="btn-fetch-ai-alternatives">✨ Alternativen vorschlagen</button>
            </div>

            <div id="alt-loading-box" class="recipe-import-status hidden">
                <span class="recipe-spinner">⏳</span>
                <span>Gemini sucht passende Alternativen und prüft die Garzeiten...</span>
            </div>

            <div id="alt-results-container" class="recipe-alt-cards-list"></div>

            <div class="modal-actions recipe-modal-footer">
                <button type="button" class="btn btn-outline modal-close js-close-alt-modal">Schließen</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
<script src="../js/recipes.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
