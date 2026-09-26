<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Kassenbon\InflationAnalyzer;
use Kai\Tools\Kassenbon\ReceiptQueryRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// 1. Auth-Check — immer zuerst
Auth::requirePage('ebon_read');

$logger = new Logger();
$csrfToken = Auth::csrfToken();

// ----------------------------------------------------
// Zeitraum-Berechnung (gesamt, jahr, monat)
// ----------------------------------------------------
$type = $_GET['type'] ?? 'gesamt';
if (!in_array($type, ['gesamt', 'jahr', 'monat'], true)) {
    $type = 'gesamt';
}

$dateParam = $_GET['date'] ?? date('Y-m-d');
$dateTime = DateTime::createFromFormat('Y-m-d', $dateParam);
if (!$dateTime) {
    $dateTime = new DateTime();
}
$refDate = $dateTime->format('Y-m-d');

$startDate = null;
$endDate = null;
$periodLabel = 'Gesamter Erfassungszeitraum';
$navLabelPrev = '';
$navLabelNext = '';
$prevDate = '';
$nextDate = '';

if ($type === 'jahr') {
    $startDate = $dateTime->format('Y-01-01');
    $endDate = $dateTime->format('Y-12-31');

    $startDt = new DateTime($startDate);
    $prevDate = (clone $startDt)->modify('-1 year')->format('Y-m-d');
    $nextDate = (clone $startDt)->modify('+1 year')->format('Y-m-d');

    $periodLabel = 'Jahr ' . $startDt->format('Y');
    $navLabelPrev = 'Vorheriges Jahr';
    $navLabelNext = 'Nächstes Jahr';
} elseif ($type === 'monat') {
    $startDate = $dateTime->format('Y-m-01');
    $endDate = $dateTime->format('Y-m-t');

    $startDt = new DateTime($startDate);
    $prevDate = (clone $startDt)->modify('-1 month')->format('Y-m-d');
    $nextDate = (clone $startDt)->modify('+1 month')->format('Y-m-d');

    $germanMonths = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
        5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
    ];
    $monthNum = (int)$startDt->format('n');
    $periodLabel = $germanMonths[$monthNum] . ' ' . $startDt->format('Y');
    $navLabelPrev = 'Vorheriger Monat';
    $navLabelNext = 'Nächster Monat';
}

// ----------------------------------------------------
// Filter-Parameter
// ----------------------------------------------------
$minPurchases = isset($_GET['min_purchases']) ? (int)$_GET['min_purchases'] : 3;
if ($minPurchases < 2) {
    $minPurchases = 2;
}

$selectedStore = trim((string)($_GET['store'] ?? ''));
$selectedCategory = trim((string)($_GET['category'] ?? ''));
$excludeDeals = !empty($_GET['exclude_deals']);

// ----------------------------------------------------
// DB-Abfrage & Analyse
// ----------------------------------------------------
try {
    $receiptQueryRepo = new ReceiptQueryRepository();
    $rawItems = $receiptQueryRepo->getItemsForInflation($startDate, $endDate);

    $analyzer = new InflationAnalyzer();
    $analysis = $analyzer->analyze(
        $rawItems,
        $minPurchases,
        $selectedStore !== '' ? $selectedStore : null,
        $selectedCategory !== '' ? $selectedCategory : null,
        $excludeDeals
    );

} catch (Throwable $e) {
    $logger->error('Kassenbon inflation.php: Fehler bei der Analyse.', ['error' => $e->getMessage()]);
    http_response_code(500);
    exit('Interner Fehler bei der Preis- und Inflationsanalyse. Bitte versuche es später erneut.');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Preisentwicklung & Inflation - Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container" id="inflation-container">
    <header class="page-header">
        <h1>🏷️ Preisentwicklung & Inflation</h1>
        <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
    </header>

    <!-- Tab-Switcher (eBons / Auswertung / Inflation) -->
    <div class="period-switcher sub-nav-tabs">
        <a href="index.php" class="btn btn-outline">🧾 eBons</a>
        <a href="auswertung.php" class="btn btn-outline">📈 Auswertung</a>
        <a href="inflation.php" class="btn">🏷️ Inflation</a>
    </div>

    <!-- Schnellauswahl Zeitraum -->
    <div class="period-switcher">
        <a href="?type=gesamt&min_purchases=<?= $minPurchases ?><?= $selectedStore !== '' ? '&store=' . urlencode($selectedStore) : '' ?><?= $selectedCategory !== '' ? '&category=' . urlencode($selectedCategory) : '' ?><?= $excludeDeals ? '&exclude_deals=1' : '' ?>"
           class="btn <?= $type === 'gesamt' ? '' : 'btn-outline' ?>">Gesamt</a>
        <a href="?type=jahr&date=<?= htmlspecialchars($refDate) ?>&min_purchases=<?= $minPurchases ?><?= $selectedStore !== '' ? '&store=' . urlencode($selectedStore) : '' ?><?= $selectedCategory !== '' ? '&category=' . urlencode($selectedCategory) : '' ?><?= $excludeDeals ? '&exclude_deals=1' : '' ?>"
           class="btn <?= $type === 'jahr' ? '' : 'btn-outline' ?>">Jahr</a>
        <a href="?type=monat&date=<?= htmlspecialchars($refDate) ?>&min_purchases=<?= $minPurchases ?><?= $selectedStore !== '' ? '&store=' . urlencode($selectedStore) : '' ?><?= $selectedCategory !== '' ? '&category=' . urlencode($selectedCategory) : '' ?><?= $excludeDeals ? '&exclude_deals=1' : '' ?>"
           class="btn <?= $type === 'monat' ? '' : 'btn-outline' ?>">Monat</a>
    </div>

    <?php if ($type !== 'gesamt'): ?>
        <!-- Zeitraum Navigation -->
        <div class="period-navigation">
            <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($prevDate) ?>&min_purchases=<?= $minPurchases ?><?= $selectedStore !== '' ? '&store=' . urlencode($selectedStore) : '' ?><?= $selectedCategory !== '' ? '&category=' . urlencode($selectedCategory) : '' ?><?= $excludeDeals ? '&exclude_deals=1' : '' ?>"
               class="btn btn-outline">◀ <?= htmlspecialchars($navLabelPrev) ?></a>
            <div class="current-period-label">
                <?= htmlspecialchars($periodLabel) ?>
                <span class="period-range-sub">
                    Auswertungszeitraum: <?= date('d.m.Y', strtotime($startDate)) ?> bis <?= date('d.m.Y', strtotime($endDate)) ?>
                </span>
            </div>
            <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($nextDate) ?>&min_purchases=<?= $minPurchases ?><?= $selectedStore !== '' ? '&store=' . urlencode($selectedStore) : '' ?><?= $selectedCategory !== '' ? '&category=' . urlencode($selectedCategory) : '' ?><?= $excludeDeals ? '&exclude_deals=1' : '' ?>"
               class="btn btn-outline"><?= htmlspecialchars($navLabelNext) ?> ▶</a>
        </div>
    <?php endif; ?>

    <!-- Filterleiste (Händler, Kategorie, Mindestanzahl, Werbeangebote) -->
    <section class="card inflation-filter-card">
        <form method="GET" action="inflation.php" class="inflation-filter-form">
            <input type="hidden" name="type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="date" value="<?= htmlspecialchars($refDate, ENT_QUOTES, 'UTF-8') ?>">

            <div class="filter-form-group">
                <label for="store-select">Händler:</label>
                <select name="store" id="store-select" class="form-control form-control-sm">
                    <option value="">Alle Händler</option>
                    <?php foreach ($analysis['available_stores'] as $storeName): ?>
                        <option value="<?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $selectedStore === $storeName ? 'selected' : '' ?>>
                            <?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-form-group">
                <label for="category-select">Kategorie:</label>
                <select name="category" id="category-select" class="form-control form-control-sm">
                    <option value="">Alle Kategorien</option>
                    <?php foreach ($analysis['available_categories'] as $catName): ?>
                        <option value="<?= htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $selectedCategory === $catName ? 'selected' : '' ?>>
                            <?= htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-form-group">
                <label for="min-purchases-select">Mindestkäufe:</label>
                <select name="min_purchases" id="min-purchases-select" class="form-control form-control-sm">
                    <option value="2" <?= $minPurchases === 2 ? 'selected' : '' ?>>Mindestens 2x</option>
                    <option value="3" <?= $minPurchases === 3 ? 'selected' : '' ?>>Mindestens 3x (Standard)</option>
                    <option value="5" <?= $minPurchases === 5 ? 'selected' : '' ?>>Mindestens 5x</option>
                    <option value="10" <?= $minPurchases === 10 ? 'selected' : '' ?>>Mindestens 10x</option>
                </select>
            </div>

            <div class="filter-form-group filter-checkbox-group">
                <label for="exclude-deals-check" style="cursor: pointer; display: flex; align-items: center; gap: 0.4rem;">
                    <input type="checkbox" name="exclude_deals" id="exclude-deals-check" value="1" <?= $excludeDeals ? 'checked' : '' ?>>
                    <span>Werbeangebote ausklammern (Normalpreis-Trend)</span>
                </label>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-sm">Filter anwenden</button>
                <?php if ($selectedStore !== '' || $selectedCategory !== '' || $minPurchases !== 3 || $excludeDeals): ?>
                    <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>" class="btn btn-sm btn-outline">Zurücksetzen</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <?php if ($analysis['total_products'] === 0): ?>
        <div class="card empty-state">
            <p class="empty-state-text">
                Keine wiederkehrenden Kassenbon-Positionen für die ausgewählten Kriterien gefunden.
            </p>
            <p class="text-muted" style="margin-top: 0.5rem; font-size: 0.9rem;">
                Hinweis: Es werden nur Artikel berücksichtigt, die mindestens <?= (int)$minPurchases ?>-mal
                und an mindestens 2 verschiedenen Tagen gekauft wurden, um aussagekräftige Preisentwicklungen zu gewährleisten.
            </p>
        </div>
    <?php else: ?>
        <!-- KPI Dashboard Kacheln -->
        <div class="inflation-kpi-grid">
            <!-- Warenkorb-Inflation (Gewichtet) -->
            <div class="inflation-kpi-card <?= $analysis['weighted_inflation_pct'] > 0 ? 'kpi-danger' : ($analysis['weighted_inflation_pct'] < 0 ? 'kpi-success' : 'kpi-neutral') ?>">
                <div class="kpi-label">
                    Warenkorb-Inflation <?= $excludeDeals ? '(ohne Angebote)' : '' ?>
                </div>
                <div class="kpi-value">
                    <?= ($analysis['weighted_inflation_pct'] > 0 ? '+' : '') . number_format($analysis['weighted_inflation_pct'], 1, ',', '.') ?> %
                </div>
                <div class="kpi-subtext">
                    Ausgaben-gewichtet (Ø ungewichtete Teuerung: <?= ($analysis['avg_inflation_pct'] > 0 ? '+' : '') . number_format($analysis['avg_inflation_pct'], 1, ',', '.') ?> %)
                </div>
            </div>

            <!-- Analysierte Artikel -->
            <div class="inflation-kpi-card">
                <div class="kpi-label">Beobachtete Artikel</div>
                <div class="kpi-value"><?= (int)$analysis['total_products'] ?></div>
                <div class="kpi-subtext">
                    Gesamtausgaben: <?= number_format($analysis['total_spent_all'], 2, ',', '.') ?> €
                </div>
            </div>

            <!-- Trend-Verteilung -->
            <div class="inflation-kpi-card">
                <div class="kpi-label">Trend-Verteilung</div>
                <div class="kpi-trend-breakdown">
                    <span class="trend-pill trend-up" title="Teurer geworden">
                        🔺 <?= (int)$analysis['increased_count'] ?> teurer
                    </span>
                    <span class="trend-pill trend-down" title="Günstiger geworden">
                        🔻 <?= (int)$analysis['decreased_count'] ?> billiger
                    </span>
                    <span class="trend-pill trend-stable" title="Preisstabil">
                        ➖ <?= (int)$analysis['stable_count'] ?> stabil
                    </span>
                </div>
                <div class="kpi-subtext">Schwankungsbreite ±2% als stabil definiert</div>
            </div>

            <!-- Preissprünge & Sonderangebote -->
            <div class="inflation-kpi-card <?= $analysis['jumps_count'] > 0 ? 'kpi-warning' : '' ?>">
                <div class="kpi-label">Preissprünge & Aktionen</div>
                <div class="kpi-value" style="display: flex; gap: 0.75rem; font-size: 1.5rem;">
                    <span title="Auffällige Preissprünge ≥ 15%">⚡ <?= (int)$analysis['jumps_count'] ?></span>
                    <span title="Erkannte Sonderangebote (≥ 15% unter Normalpreis)">🎉 <?= (int)$analysis['total_deals_count'] ?></span>
                </div>
                <div class="kpi-subtext">
                    <?= (int)$analysis['products_with_deals_count'] ?> Produkte mit mind. 1 Werbeangebot
                </div>
            </div>
        </div>

        <!-- Highlight-Listen (Top Teuerungen & Top Senkungen & Sprünge) -->
        <div class="inflation-highlights-grid">
            <!-- Top Teuerung -->
            <div class="card highlight-card">
                <h3>📈 Stärkste Teuerungen</h3>
                <?php if (empty($analysis['top_increases'])): ?>
                    <p class="text-muted">Keine Preiserhöhungen im Zeitraum.</p>
                <?php else: ?>
                    <ul class="highlight-list">
                        <?php foreach ($analysis['top_increases'] as $item): ?>
                            <li class="highlight-item js-open-detail" data-key="<?= htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="highlight-item-info">
                                    <strong class="item-name"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span class="item-meta">
                                        <?= htmlspecialchars($item['store'], ENT_QUOTES, 'UTF-8') ?> • <?= (int)$item['purchase_count'] ?> Käufe
                                        <?php if (!empty($item['formatted_base_price'])): ?>
                                            • <span class="base-price-hint"><?= htmlspecialchars($item['formatted_base_price'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="highlight-item-values text-right">
                                    <span class="price-change change-up amount-bold">
                                        +<?= number_format($item['price_change_pct'], 1, ',', '.') ?> %
                                    </span>
                                    <span class="price-range">
                                        <?= number_format($item['first_price'], 2, ',', '.') ?> € &rarr; <?= number_format($item['last_price'], 2, ',', '.') ?> €
                                    </span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Top Preissenkungen -->
            <div class="card highlight-card">
                <h3>📉 Größte Preissenkungen</h3>
                <?php if (empty($analysis['top_decreases'])): ?>
                    <p class="text-muted">Keine Preissenkungen im Zeitraum.</p>
                <?php else: ?>
                    <ul class="highlight-list">
                        <?php foreach ($analysis['top_decreases'] as $item): ?>
                            <li class="highlight-item js-open-detail" data-key="<?= htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="highlight-item-info">
                                    <strong class="item-name"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span class="item-meta">
                                        <?= htmlspecialchars($item['store'], ENT_QUOTES, 'UTF-8') ?> • <?= (int)$item['purchase_count'] ?> Käufe
                                        <?php if (!empty($item['formatted_base_price'])): ?>
                                            • <span class="base-price-hint"><?= htmlspecialchars($item['formatted_base_price'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="highlight-item-values text-right">
                                    <span class="price-change change-down amount-bold">
                                        <?= number_format($item['price_change_pct'], 1, ',', '.') ?> %
                                    </span>
                                    <span class="price-range">
                                        <?= number_format($item['first_price'], 2, ',', '.') ?> € &rarr; <?= number_format($item['last_price'], 2, ',', '.') ?> €
                                    </span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Auffällige Einzelsprünge -->
            <div class="card highlight-card">
                <h3>⚡ Auffällige Preissprünge</h3>
                <?php if (empty($analysis['top_jumps'])): ?>
                    <p class="text-muted">Keine plötzlichen Preissprünge ≥ 15% gefunden.</p>
                <?php else: ?>
                    <ul class="highlight-list">
                        <?php foreach ($analysis['top_jumps'] as $item): ?>
                            <li class="highlight-item js-open-detail" data-key="<?= htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="highlight-item-info">
                                    <strong class="item-name"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span class="item-meta">
                                        <?= htmlspecialchars($item['store'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if ($item['max_jump_date']): ?>
                                            • am <?= date('d.m.Y', strtotime($item['max_jump_date'])) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="highlight-item-values text-right">
                                    <span class="price-change <?= $item['max_jump_pct'] > 0 ? 'change-up' : 'change-down' ?> amount-bold">
                                        <?= ($item['max_jump_pct'] > 0 ? '+' : '') . number_format($item['max_jump_pct'], 1, ',', '.') ?> %
                                    </span>
                                    <span class="price-range">
                                        <?= number_format((float)$item['max_jump_from'], 2, ',', '.') ?> € &rarr; <?= number_format((float)$item['max_jump_to'], 2, ',', '.') ?> €
                                    </span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <?php if (count($analysis['monthly_trend']['labels']) > 1): ?>
            <!-- Monatlicher Trend-Chart -->
            <section class="card inflation-chart-card">
                <div class="chart-header">
                    <h3>📊 Monatliche Preisentwicklung (Index-Verlauf)</h3>
                    <span class="text-muted" style="font-size: 0.85rem;">
                        Durchschnittliche prozentuale Veränderung der wiederkehrenden Produkte gegenüber der Erstbeobachtung
                    </span>
                </div>
                <div class="chart-wrapper" style="height: 260px; position: relative;">
                    <canvas id="monthlyTrendChart"></canvas>
                </div>
            </section>
        <?php endif; ?>

        <!-- Interaktive Artikelliste -->
        <section class="card">
            <div class="table-header-flex">
                <h2>Wiederkehrende Artikel (<?= (int)$analysis['total_products'] ?>)</h2>
                <div class="table-search-wrap">
                    <input type="text" id="productSearchInput" class="form-control form-control-sm"
                           placeholder="🔍 Artikel oder Händler suchen...">
                </div>
            </div>

            <!-- Schnelle Trend-Filter -->
            <div class="table-filter-pills">
                <button type="button" class="filter-pill active js-table-filter" data-filter="all">
                    Alle (<?= (int)$analysis['total_products'] ?>)
                </button>
                <button type="button" class="filter-pill js-table-filter" data-filter="increased">
                    🔺 Teurer (<?= (int)$analysis['increased_count'] ?>)
                </button>
                <button type="button" class="filter-pill js-table-filter" data-filter="jumps">
                    ⚡ Preissprünge (<?= (int)$analysis['jumps_count'] ?>)
                </button>
                <button type="button" class="filter-pill js-table-filter" data-filter="deals">
                    🎉 Mit Angeboten (<?= (int)$analysis['products_with_deals_count'] ?>)
                </button>
                <button type="button" class="filter-pill js-table-filter" data-filter="decreased">
                    🔻 Günstiger (<?= (int)$analysis['decreased_count'] ?>)
                </button>
                <button type="button" class="filter-pill js-table-filter" data-filter="stable">
                    ➖ Stabil (<?= (int)$analysis['stable_count'] ?>)
                </button>
            </div>

            <div class="table-responsive">
                <table class="stack-table inflation-table" id="inflationProductsTable">
                    <thead>
                    <tr>
                        <th>Artikel & Händler</th>
                        <th>Kategorie</th>
                        <th class="text-right">Käufe</th>
                        <th class="text-right">Erster Preis</th>
                        <th class="text-right">Letzter Preis / Grundpreis</th>
                        <th class="text-right">Min / Max</th>
                        <th class="text-right">Max. Sprung</th>
                        <th class="text-right">Veränderung</th>
                        <th class="text-right">Aktion</th>
                    </tr>
                    </thead>
                    <tbody id="inflationTableBody">
                    <!-- Wird durch JavaScript befüllt & paginiert -->
                    </tbody>
                </table>
            </div>

            <div class="pagination" id="inflationPagination">
                <!-- Dynamische Paginierung via JS -->
            </div>
        </section>
    <?php endif; ?>
</div>

<!-- Modal: Artikel-Preisverlauf & Open Food Facts -->
<div class="modal-overlay hidden" id="productDetailModal" role="dialog" aria-modal="true">
    <div class="modal-card modal-card--lg">
        <div class="modal-header">
            <div>
                <h3 id="modalProductName">Artikelname</h3>
                <span class="text-muted" id="modalProductMeta" style="font-size: 0.85rem;">Händler • Kategorie</span>
            </div>
            <button type="button" class="modal-close js-modal-close" aria-label="Schließen">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Modal KPI Row -->
            <div class="modal-kpi-row" id="modalKpiRow">
                <!-- Dynamisch befüllt -->
            </div>

            <!-- Detail Chart -->
            <div class="modal-chart-wrap" style="height: 240px; position: relative;">
                <canvas id="productHistoryChart"></canvas>
            </div>

            <!-- Open Food Facts Box -->
            <div class="modal-off-box" id="modalOffBox">
                <div class="off-header-row">
                    <div class="off-title">
                        <strong>🌐 Open Food Facts Abgleich</strong>
                        <span class="text-muted" style="font-size: 0.8rem;">EAN-Barcode, Marke & Füllmengen-Stammdaten</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline js-trigger-off" id="btnTriggerOff">
                        🔍 Daten abrufen
                    </button>
                </div>
                <div class="off-result-wrap hidden" id="offResultWrap">
                    <!-- Wird dynamisch befüllt -->
                </div>
            </div>

            <!-- Historie-Tabelle -->
            <h4 style="margin-top: 1rem; margin-bottom: 0.5rem; font-size: 0.95rem;">Einkaufshistorie</h4>
            <div class="table-responsive" style="max-height: 220px; overflow-y: auto;">
                <table class="stack-table modal-history-table">
                    <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Händler</th>
                        <th class="text-right">Menge</th>
                        <th class="text-right">Einzelpreis</th>
                        <th class="text-right">Status</th>
                        <th class="text-right">Gesamt</th>
                        <th class="text-right">Bon</th>
                    </tr>
                    </thead>
                    <tbody id="modalHistoryTableBody">
                    <!-- Dynamisch befüllt -->
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline js-modal-close">Schließen</button>
        </div>
    </div>
</div>

<!-- Daten für JavaScript bereitstellen -->
<script>
    window.INFLATION_DATA = {
        products: <?= json_encode($analysis['products'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        monthlyTrend: <?= json_encode($analysis['monthly_trend'] ?? ['labels' => [], 'values' => []], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
</script>

<script src="../js/http.js?v=<?= APP_VERSION ?>" defer></script>
<script src="../js/chart.min.js?v=<?= APP_VERSION ?>" defer></script>
<script src="../js/inflation.js?v=<?= APP_VERSION ?>" defer></script>
<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
</body>
</html>
