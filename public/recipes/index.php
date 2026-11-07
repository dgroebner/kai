<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Recipes\RecipeRepository;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\SystemSettingsService;

// 1. Auth-Check — immer zuerst
Auth::requirePage('recipes_read');

$canWrite = Auth::hasPermission('recipes_write');
$csrfToken = Auth::csrfToken();

$repo = new RecipeRepository();
$settings = new SystemSettingsService();

$filterCategory = trim((string)($_GET['category'] ?? 'all'));
$filterSearch = trim((string)($_GET['q'] ?? ''));
$onlyFavorites = !empty($_GET['fav']);

$recipes = $repo->getAllRecipes(
    $filterCategory !== 'all' ? $filterCategory : null,
    $filterSearch !== '' ? $filterSearch : null,
    $onlyFavorites
);
$categories = $repo->getCategories();

$stoveType = $settings->getStoveType();
$stoveLevels = $settings->getStoveLevels();
$ovenMode = $settings->getOvenDefaultMode();
$preferShallots = $settings->isRecipePreferShallotsEnabled();
$defaultAdults = $settings->getRecipeDefaultAdults();
$defaultKids = $settings->getRecipeDefaultKids();
$kidPortionPct = $settings->getRecipeKidPortionPct();
$householdPrefs = $settings->getRecipeHouseholdPreferences();

$sourceIcons = [
    'url' => '🔗 Web-Rezept',
    'photo' => '📸 Foto-Scan',
    'pdf' => '📄 PDF-Import',
    'text' => '📝 Text-Import',
    'manual' => '✍️ Manuell',
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Kochrezepte &amp; Küchenbegleiter - Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <h1>👨‍🍳 Kochrezepte &amp; Küchenbegleiter</h1>
        <div class="page-header-actions">
            <a href="../einkaufsliste/index.php" class="btn btn-outline">🛒 Einkaufsliste</a>
            <a href="../index.php" class="btn btn-outline">&larr; Übersicht</a>
            <?php if ($canWrite): ?>
                <button type="button" class="btn btn-outline" id="btn-open-profile-modal">⚙️ Küchenprofil &amp; Präferenzen</button>
                <button type="button" class="btn btn-primary" id="btn-open-import-modal">✨ Rezept importieren (KI)</button>
            <?php endif; ?>
        </div>
    </header>

    <main>
        <!-- Haushalts-Küchenprofil Banner -->
        <section class="card recipe-kitchen-profile-bar">
            <div class="recipe-profile-row">
                <div class="recipe-profile-badges">
                    <span class="recipe-profile-pill">🔥 Herd: <strong><?= htmlspecialchars($stoveType, ENT_QUOTES, 'UTF-8') ?> (Stufe 1–<?= (int)$stoveLevels ?>)</strong></span>
                    <span class="recipe-profile-pill">🌀 Ofen: <strong><?= htmlspecialchars($ovenMode, ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <span class="recipe-profile-pill">👨‍👩‍👧‍👦 Standard: <strong><?= $defaultAdults ?> Erw. + <?= $defaultKids ?> Ki. (<?= $kidPortionPct ?> %)</strong></span>
                    <?php if ($preferShallots): ?>
                        <span class="recipe-profile-pill recipe-profile-pill--highlight">🧅 Präferenz: <strong>Schalotten statt Zwiebeln</strong></span>
                    <?php endif; ?>
                    <?php if ($householdPrefs !== ''): ?>
                        <span class="recipe-profile-pill recipe-profile-pill--highlight" title="<?= htmlspecialchars($householdPrefs, ENT_QUOTES, 'UTF-8') ?>">
                            ✨ Vorlieben: <strong><?= htmlspecialchars(mb_strimwidth($householdPrefs, 0, 45, '…'), ENT_QUOTES, 'UTF-8') ?></strong>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ($canWrite): ?>
                    <button type="button" class="btn btn-sm btn-outline js-open-profile-modal">✏️ Anpassen</button>
                <?php endif; ?>
            </div>
        </section>

        <!-- Filter & Suche -->
        <section class="card recipe-filter-bar">
            <form method="GET" action="index.php" class="recipe-filter-form">
                <div class="recipe-filter-controls">
                    <select name="category" class="form-control form-control-sm" id="recipe-filter-category" data-read-interactive="1">
                        <option value="all">Alle Kategorien</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="search" name="q" class="form-control form-control-sm recipe-search-input"
                           placeholder="Rezept oder Zutat suchen..."
                           value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>"
                           id="recipe-filter-search" data-read-interactive="1">

                    <label class="recipe-fav-filter-label">
                        <input type="checkbox" name="fav" value="1" <?= $onlyFavorites ? 'checked' : '' ?> data-read-interactive="1">
                        ⭐ Nur Favoriten
                    </label>

                    <button type="submit" class="btn btn-sm btn-outline filter" data-read-interactive="1">Filtern</button>
                    <?php if ($filterCategory !== 'all' || $filterSearch !== '' || $onlyFavorites): ?>
                        <a href="index.php" class="btn btn-sm btn-text">Zurücksetzen</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- Rezept-Karten Grid -->
        <?php if (empty($recipes)): ?>
            <section class="card recipe-empty-state">
                <div class="recipe-empty-icon">🍲</div>
                <h2>Noch keine Rezepte gefunden</h2>
                <p class="text-muted">
                    Importiere ein Rezept per Foto aus dem Kochbuch, als PDF oder direkt über eine Web-URL.
                    Kai übersetzt Fachbegriffe automatisch in verständliche Schritte mit konkreten Stufen (1–<?= (int)$stoveLevels ?>) und synchronisiert alle Töpfe!
                </p>
                <?php if ($canWrite): ?>
                    <div class="recipe-empty-actions">
                        <button type="button" class="btn btn-primary" id="btn-empty-import">✨ Erstes Rezept importieren</button>
                    </div>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <div class="recipe-cards-grid">
                <?php foreach ($recipes as $r): ?>
                    <?php
                    $rid = (int)$r['id'];
                    $srcType = (string)($r['source_type'] ?? 'manual');
                    $srcLabel = $sourceIcons[$srcType] ?? '📖 Rezept';
                    $isFav = !empty($r['is_favorite']);
                    ?>
                    <article class="card recipe-card" data-recipe-id="<?= $rid ?>">
                        <div class="recipe-card-top">
                            <span class="recipe-cat-badge"><?= htmlspecialchars((string)$r['category'], ENT_QUOTES, 'UTF-8') ?></span>
                            <div class="recipe-card-top-right">
                                <span class="recipe-source-badge"><?= htmlspecialchars($srcLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($canWrite): ?>
                                    <button type="button"
                                            class="recipe-fav-btn js-toggle-fav <?= $isFav ? 'is-fav' : '' ?>"
                                            data-recipe-id="<?= $rid ?>"
                                            title="Als Favorit markieren">
                                        <?= $isFav ? '⭐' : '☆' ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <h2 class="recipe-card-title">
                            <a href="view.php?id=<?= $rid ?>"><?= htmlspecialchars((string)$r['title'], ENT_QUOTES, 'UTF-8') ?></a>
                        </h2>

                        <?php if (!empty($r['description'])): ?>
                            <p class="recipe-card-desc"><?= htmlspecialchars((string)$r['description'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>

                        <div class="recipe-card-meta">
                            <span class="recipe-meta-item" title="Gesamtzeit">⏱️ <?= (int)$r['total_time_minutes'] ?> Min.</span>
                            <span class="recipe-meta-item" title="Standard-Portionen">👨‍👩‍👧‍👦 <?= (int)$r['default_adults'] ?> Erw. + <?= (int)$r['default_kids'] ?> Ki.</span>
                            <span class="recipe-meta-item" title="Anzahl Zutaten">🥕 <?= (int)($r['ingredient_count'] ?? 0) ?> Zutaten</span>
                            <?php if (!empty($r['calories_per_serving'])): ?>
                                <span class="recipe-meta-item" title="Kalorien pro Portion">🔥 <?= (int)$r['calories_per_serving'] ?> kcal</span>
                            <?php endif; ?>
                            <?php if ((int)($r['variant_count'] ?? 1) > 1): ?>
                                <span class="recipe-meta-item recipe-meta-variants">🔀 <?= (int)$r['variant_count'] ?> Varianten</span>
                            <?php endif; ?>
                        </div>

                        <div class="recipe-card-actions">
                            <?php if ($canWrite): ?>
                                <button type="button" class="btn btn-outline btn-sm recipe-btn-danger js-delete-recipe" data-recipe-id="<?= $rid ?>" title="Rezept löschen">🗑️ Löschen</button>
                            <?php endif; ?>
                            <a href="view.php?id=<?= $rid ?>" class="btn btn-outline btn-sm">📖 Details &amp; Planung</a>
                            <a href="cook.php?id=<?= $rid ?>" class="btn btn-primary btn-sm">👨‍🍳 Koch-Modus</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php if ($canWrite): ?>
<!-- MODAL 1: KI-Rezept-Import (Foto, PDF, URL, Freitext) -->
<div id="modal-recipe-import" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="modal-import-title">
    <form id="form-recipe-import" class="modal-card modal-card--lg">
        <div class="modal-header">
            <h3 id="modal-import-title">✨ Rezept importieren &amp; intelligent aufbereiten</h3>
            <button type="button" class="modal-close js-close-import-modal" aria-label="Schließen">&times;</button>
        </div>
        <div class="modal-body">
            <p class="text-muted recipe-modal-intro">
                Kai extrahiert Zutaten, Nährwerte und Kochschritte, wendet eure <strong>Haushalts-Präferenzen</strong> an,
                übersetzt Fachbegriffe in einfache Sprache, ergänzt <strong>Herdstufen (1–<?= (int)$stoveLevels ?>)</strong> und berechnet den Synchron-Zeitplan für alle Töpfe.
            </p>

            <!-- Quell-Umschalter Tabs -->
            <div class="recipe-import-tabs">
                <button type="button" class="btn btn-sm btn-primary js-import-tab" data-import-type="photo">📸 Foto / PDF</button>
                <button type="button" class="btn btn-sm btn-outline js-import-tab" data-import-type="url">🔗 Web-URL</button>
                <button type="button" class="btn btn-sm btn-outline js-import-tab" data-import-type="text">📝 Freitext / Notiz</button>
            </div>

            <input type="hidden" id="import-source-type" value="photo">

            <!-- Tab 1: Foto oder PDF Upload -->
            <div id="import-pane-photo" class="recipe-import-pane">
                <label class="form-label" for="import-file-input">Foto einer Buchseite, Karteikarte oder PDF-Dokument auswählen:</label>
                <input type="file" id="import-file-input" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                <div id="import-file-preview" class="recipe-file-preview hidden"></div>
                <label class="form-label recipe-mt-sm" for="import-photo-hint">Optionale Zusatznotiz (z. B. „Bitte ohne Pilze“ oder „Für 4 Personen“):</label>
                <input type="text" id="import-photo-hint" class="form-control" placeholder="Optionaler Hinweis für die KI...">
            </div>

            <!-- Tab 2: URL Import -->
            <div id="import-pane-url" class="recipe-import-pane hidden">
                <label class="form-label" for="import-url-input">Link zur Rezept-Webseite (z. B. Chefkoch, EatSmarter, Foodblog):</label>
                <input type="url" id="import-url-input" class="form-control" placeholder="https://www.chefkoch.de/rezepte/...">
            </div>

            <!-- Tab 3: Freitext Import -->
            <div id="import-pane-text" class="recipe-import-pane hidden">
                <label class="form-label" for="import-text-input">Rezepttext, Zutatenliste oder eigene Kochanleitung einfügen:</label>
                <textarea id="import-text-input" class="form-control" rows="7" placeholder="Zutaten und Zubereitung hier einfügen..."></textarea>
            </div>

            <div id="import-status-box" class="recipe-import-status hidden">
                <span class="recipe-spinner">⏳</span>
                <span id="import-status-text">Gemini analysiert das Rezept, berechnet Herdstufen und synchronisiert die Töpfe (dauert ca. 10–20 Sek.)...</span>
            </div>
        </div>
        <div class="modal-footer recipe-modal-footer">
            <button type="button" class="btn btn-outline js-close-import-modal">Abbrechen</button>
            <button type="submit" class="btn btn-primary" id="btn-submit-import">🚀 Rezept analysieren &amp; speichern</button>
        </div>
    </form>
</div>

<!-- MODAL 2: Küchenprofil & Haushalts-Präferenzen -->
<div id="modal-kitchen-profile" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="modal-profile-title">
    <form id="form-kitchen-profile" class="modal-card modal-card--lg">
        <div class="modal-header">
            <h3 id="modal-profile-title">⚙️ Küchenprofil &amp; Haushalts-Präferenzen</h3>
            <button type="button" class="modal-close js-close-profile-modal" aria-label="Schließen">&times;</button>
        </div>
        <div class="modal-body">
            <p class="text-muted recipe-modal-intro">
                Diese Grundeinstellungen nutzt Kai automatisch beim Importieren neuer Rezepte, bei der Berechnung konkreter Herdstufen und bei der Standard-Skalierung.
            </p>

            <h4 class="recipe-modal-sec-title">🔥 Herd &amp; Backofen</h4>
            <div class="recipe-settings-grid">
                <div>
                    <label class="form-label" for="prof-stove-type">Herd-Typ:</label>
                    <select id="prof-stove-type" class="form-control">
                        <option value="Ceranfeld" <?= $stoveType === 'Ceranfeld' ? 'selected' : '' ?>>Ceranfeld (Elektro)</option>
                        <option value="Induktion" <?= $stoveType === 'Induktion' ? 'selected' : '' ?>>Induktionskochfeld</option>
                        <option value="Gasherd" <?= $stoveType === 'Gasherd' ? 'selected' : '' ?>>Gasherd</option>
                        <option value="Massekochfeld" <?= $stoveType === 'Massekochfeld' ? 'selected' : '' ?>>Klassisches Massekochfeld</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="prof-stove-levels">Anzahl Herdstufen (Skala):</label>
                    <input type="number" id="prof-stove-levels" class="form-control" min="3" max="18" value="<?= (int)$stoveLevels ?>" required>
                </div>
                <div>
                    <label class="form-label" for="prof-oven-mode">Standard Backofen-Beheizung:</label>
                    <select id="prof-oven-mode" class="form-control">
                        <option value="Umluft" <?= $ovenMode === 'Umluft' ? 'selected' : '' ?>>Umluft</option>
                        <option value="Ober-/Unterhitze" <?= $ovenMode === 'Ober-/Unterhitze' ? 'selected' : '' ?>>Ober-/Unterhitze</option>
                        <option value="Heißluft" <?= $ovenMode === 'Heißluft' ? 'selected' : '' ?>>Heißluft</option>
                    </select>
                </div>
            </div>

            <h4 class="recipe-modal-sec-title recipe-mt-sm">👨‍👩‍👧‍👦 Standard-Portionen im Haushalt</h4>
            <div class="recipe-settings-grid">
                <div>
                    <label class="form-label" for="prof-default-adults">Anzahl Erwachsene (100 %):</label>
                    <input type="number" id="prof-default-adults" class="form-control" min="1" max="20" value="<?= (int)$defaultAdults ?>" required>
                </div>
                <div>
                    <label class="form-label" for="prof-default-kids">Anzahl Kinder / Jugendliche:</label>
                    <input type="number" id="prof-default-kids" class="form-control" min="0" max="20" value="<?= (int)$defaultKids ?>" required>
                </div>
                <div>
                    <label class="form-label" for="prof-kid-pct">Portionsgröße Kinder (in %):</label>
                    <input type="number" id="prof-kid-pct" class="form-control" min="30" max="100" step="5" value="<?= (int)$kidPortionPct ?>" required>
                </div>
            </div>

            <h4 class="recipe-modal-sec-title recipe-mt-sm">🧅 Zutaten-Vorlieben &amp; Standard-Abwandlungen</h4>
            <label class="recipe-checkbox-card">
                <input type="checkbox" id="prof-prefer-shallots" value="1" <?= $preferShallots ? 'checked' : '' ?>>
                <div>
                    <strong>Schalotten statt regulärer Zwiebeln bevorzugen</strong>
                    <div class="text-muted">Ersetzt beim Rezept-Import automatisch Speise-/Gemüsezwiebeln durch die passende Menge Schalotten.</div>
                </div>
            </label>

            <div class="recipe-mt-sm">
                <label class="form-label" for="prof-household-prefs">Weitere Haushalts-Vorlieben, Standard-Ersetzungen oder No-Gos (Freitext für die KI):</label>
                <textarea id="prof-household-prefs" class="form-control" rows="3"
                          placeholder="z. B. Keine scharfen Chilis, kein frischer Koriander, lieber Dinkelmehl statt Weizenmehl..."><?= htmlspecialchars($householdPrefs, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>
        <div class="modal-footer recipe-modal-footer">
            <button type="button" class="btn btn-outline js-close-profile-modal">Abbrechen</button>
            <button type="submit" class="btn btn-primary" id="btn-save-kitchen-profile">💾 Einstellungen speichern</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
<script src="../js/recipes.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
