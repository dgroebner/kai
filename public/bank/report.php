<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Bank\FinancialReportAggregator;
use Kai\Tools\Bank\FinancialReportRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// 1. Auth-Check (AGENTS.md)
Auth::requirePage('finance_read');

$logger = new Logger(14);
$reportRepo = new FinancialReportRepository();
$aggregator = new FinancialReportAggregator();

// 2. Zeitraum-Parameter auflösen (Typ: 'monat'|'jahr')
$viewType = $_GET['type'] ?? 'monat';
if (!in_array($viewType, ['monat', 'jahr'], true)) {
    $viewType = 'monat';
}
$periodType = $viewType === 'jahr' ? 'year' : 'month';

$periodParam = $_GET['period'] ?? null;
if ($periodType === 'year') {
    $currentYear = (int)date('Y');
    $targetYear = $periodParam && preg_match('/^\d{4}$/', $periodParam) ? (int)$periodParam : $currentYear;
    $periodTarget = (string)$targetYear;

    $prevPeriod = (string)($targetYear - 1);
    $nextPeriod = (string)($targetYear + 1);
    $periodLabel = "Jahr $targetYear";
    $navPrevLabel = (string)($targetYear - 1);
    $navNextLabel = (string)($targetYear + 1);
} else {
    // Monat (YYYY-MM)
    if (!$periodParam || !preg_match('/^\d{4}-\d{2}$/', $periodParam)) {
        $periodParam = date('Y-m');
    }
    $periodTarget = $periodParam;
    $dt = DateTimeImmutable::createFromFormat('!Y-m', $periodTarget) ?: new DateTimeImmutable('first day of this month');

    $prevDt = $dt->modify('-1 month');
    $nextDt = $dt->modify('+1 month');

    $prevPeriod = $prevDt->format('Y-m');
    $nextPeriod = $nextDt->format('Y-m');
    $periodLabel = $dt->format('F Y');
    // Deutsche Monatsnamen
    $monthNames = [
            'January' => 'Januar', 'February' => 'Februar', 'March' => 'März', 'April' => 'April',
            'May' => 'Mai', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'August',
            'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Dezember'
    ];
    $periodLabel = strtr($periodLabel, $monthNames);
    $navPrevLabel = strtr($prevDt->format('M Y'), [
            'Jan' => 'Jan', 'Feb' => 'Feb', 'Mar' => 'Mär', 'Apr' => 'Apr', 'May' => 'Mai', 'Jun' => 'Jun',
            'Jul' => 'Jul', 'Aug' => 'Aug', 'Sep' => 'Sep', 'Oct' => 'Okt', 'Nov' => 'Nov', 'Dec' => 'Dez'
    ]);
    $navNextLabel = strtr($nextDt->format('M Y'), [
            'Jan' => 'Jan', 'Feb' => 'Feb', 'Mar' => 'Mär', 'Apr' => 'Apr', 'May' => 'Mai', 'Jun' => 'Jun',
            'Jul' => 'Jul', 'Aug' => 'Aug', 'Sep' => 'Sep', 'Oct' => 'Okt', 'Nov' => 'Nov', 'Dec' => 'Dez'
    ]);
}

// 3. Bericht aus Cache/DB laden oder voraggregieren
$savedReport = $reportRepo->getReport($periodType, $periodTarget);

if ($savedReport !== null) {
    $aggregated = $savedReport['aggregated_data'];
    $aiAnalysis = $savedReport['ai_analysis'];
    $lastUpdated = $savedReport['updated_at'] ?? $savedReport['created_at'];
    $isAnalyzed = true;
} else {
    // Deterministische Live-Aggregation für sofortige Zahlen
    $aggregated = $aggregator->aggregate($periodType, $periodTarget);
    $aiAnalysis = null;
    $lastUpdated = null;
    $isAnalyzed = false;
}

$cashflow = $aggregated['cashflow_totals'] ?? [];
$tags = $aggregated['tag_breakdown'] ?? [];
$deviations = $aggregated['contract_deviations'] ?? [];
$receiptInsights = $aggregated['receipt_insights'] ?? [];

$totalIncome = (float)($cashflow['total_income'] ?? 0);
$totalExpenses = (float)($cashflow['total_expenses'] ?? 0);
$netBalance = (float)($cashflow['net_balance'] ?? 0);
$savingsRate = (float)($cashflow['savings_rate_percent'] ?? 0);
$fixedExpenses = (float)($cashflow['fixed_expenses_total'] ?? 0);
$variableExpenses = (float)($cashflow['variable_expenses_total'] ?? 0);

$canEdit = Auth::hasPermission('finance_write');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finanzreport <?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?> – Kai</title>
    <meta name="csrf-token" content="<?= Auth::csrfToken() ?>">
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
    <script src="../js/http.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="../js/bank-report.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="../js/pwa-register.js?v=<?= APP_VERSION ?>" defer></script>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container" id="report-container"
     data-period-type="<?= htmlspecialchars($periodType, ENT_QUOTES, 'UTF-8') ?>"
     data-period-target="<?= htmlspecialchars($periodTarget, ENT_QUOTES, 'UTF-8') ?>">

    <header class="page-header">
        <h1>📊 KI-Finanzreport</h1>
        <div class="page-header-actions">
            <?php if ($lastUpdated): ?>
                <span class="last-update">Zuletzt analysiert: <?= htmlspecialchars(date('d.m.Y H:i', strtotime($lastUpdated)), ENT_QUOTES, 'UTF-8') ?> Uhr</span>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <button type="button" id="btn-generate-report" class="btn btn-blue">
                    <?= $isAnalyzed ? '🔄 Neu analysieren' : '🤖 KI-Analyse erstellen' ?>
                </button>
            <?php endif; ?>

            <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
        </div>
    </header>

    <!-- Tab-Switcher (Girokonto / Kreditkarte / Verträge / Finanzreport) -->
    <div class="period-switcher report-nav-tabs">
        <a href="index.php" class="btn btn-outline">🏦 Girokonto</a>
        <a href="creditcard.php" class="btn btn-outline">💳 Kreditkarte</a>
        <a href="contracts.php" class="btn btn-outline">📑 Verträge</a>
        <a href="report.php" class="btn">📊 Finanzreport</a>
    </div>

    <!-- Schnelleinstellungen (Typ: Monat / Jahr) -->
    <div class="period-switcher">
        <a href="?type=monat&period=<?= htmlspecialchars($periodType === 'year' ? date('Y-m') : $periodTarget, ENT_QUOTES, 'UTF-8') ?>"
           class="btn <?= $viewType === 'monat' ? '' : 'btn-outline' ?>">Monat</a>
        <a href="?type=jahr&period=<?= htmlspecialchars($periodType === 'year' ? $periodTarget : substr($periodTarget, 0, 4), ENT_QUOTES, 'UTF-8') ?>"
           class="btn <?= $viewType === 'jahr' ? '' : 'btn-outline' ?>">Jahr</a>
    </div>

    <!-- Zeitraum Navigation -->
    <div class="period-navigation">
        <a href="?type=<?= htmlspecialchars($viewType, ENT_QUOTES, 'UTF-8') ?>&period=<?= htmlspecialchars($prevPeriod, ENT_QUOTES, 'UTF-8') ?>"
           class="btn btn-outline">◀ <?= htmlspecialchars($navPrevLabel, ENT_QUOTES, 'UTF-8') ?></a>
        <div class="current-period-label">
            <?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?>
            <span class="period-range-sub">
                Referenz: <?= htmlspecialchars($aggregated['metadata']['period_reference'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                (<?= date('d.m.Y', strtotime($aggregated['metadata']['target_start'])) ?> bis <?= date('d.m.Y', strtotime($aggregated['metadata']['target_end'])) ?>)
            </span>
        </div>
        <a href="?type=<?= htmlspecialchars($viewType, ENT_QUOTES, 'UTF-8') ?>&period=<?= htmlspecialchars($nextPeriod, ENT_QUOTES, 'UTF-8') ?>"
           class="btn btn-outline"><?= htmlspecialchars($navNextLabel, ENT_QUOTES, 'UTF-8') ?> ▶</a>
    </div>

    <!-- Status / Lade-Meldung -->
    <div id="report-feedback-banner" class="hidden report-feedback-banner"></div>

    <!-- KPI-Dashboard -->
    <section class="kpi-grid report-kpi-grid">
        <div class="kpi-card report-kpi-income">
            <div class="kpi-label">📈 Gesamteinnahmen</div>
            <div class="kpi-value-sm text-green">
                +<?= number_format($totalIncome, 2, ',', '.') ?> €
            </div>
        </div>
        <div class="kpi-card report-kpi-expenses">
            <div class="kpi-label">📉 Gesamtausgaben</div>
            <div class="kpi-value-sm text-red">
                -<?= number_format($totalExpenses, 2, ',', '.') ?> €
            </div>
        </div>
        <div class="kpi-card report-kpi-balance">
            <div class="kpi-label">💰 Netto-Saldo</div>
            <div class="kpi-value-sm <?= $netBalance >= 0 ? 'text-green' : 'text-red' ?>">
                <?= $netBalance >= 0 ? '+' : '' ?><?= number_format($netBalance, 2, ',', '.') ?> €
            </div>
        </div>
        <div class="kpi-card report-kpi-savings">
            <div class="kpi-label">🎯 Sparquote</div>
            <div class="kpi-value-sm">
                <?= number_format($savingsRate, 1, ',', '.') ?> %
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">📑 Gebundene Fixkosten</div>
            <div class="kpi-value-sm">
                <?= number_format($fixedExpenses, 2, ',', '.') ?> €
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">🛒 Variabler Konsum</div>
            <div class="kpi-value-sm">
                <?= number_format($variableExpenses, 2, ',', '.') ?> €
            </div>
        </div>
    </section>

    <!-- KI-Analyse Hero-Karte -->
    <?php if ($isAnalyzed && $aiAnalysis): ?>
        <section class="card report-hero-card">
            <div class="report-hero-header">
                <h2>🤖 KI-Finanzanalyse & Fazit</h2>
                <span class="report-status-badge report-status-<?= htmlspecialchars($aiAnalysis['fixed_vs_variable']['status'] ?? 'healthy', ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars(strtoupper($aiAnalysis['fixed_vs_variable']['status'] ?? 'STATUS'), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>

            <p class="report-summary-text">
                <?= nl2br(htmlspecialchars($aiAnalysis['summary'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
            </p>

            <div class="report-analysis-grid">
                <div class="report-analysis-col">
                    <h3>⚖️ Fixkosten vs. Konsum</h3>
                    <p class="report-col-text">
                        <?= htmlspecialchars($aiAnalysis['fixed_vs_variable']['analysis'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>
                <div class="report-analysis-col">
                    <h3>🔮 Prognose Folgeperiode</h3>
                    <p class="report-col-text">
                        <?= htmlspecialchars($aiAnalysis['forecast'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>
            </div>

            <?php if (!empty($aiAnalysis['action_items'])): ?>
                <div class="report-actions-wrapper">
                    <h3>✅ Konkrete Handlungsempfehlungen</h3>
                    <ul class="report-action-items">
                        <?php foreach ($aiAnalysis['action_items'] as $item): ?>
                            <li><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <div class="card report-empty-prompt">
            <h3>🤖 Noch keine KI-Auswertung für diesen Zeitraum vorhanden</h3>
            <p>
                Deine Einnahmen, Ausgaben und Salden sind oben bereits fertig berechnet. Starte die KI-Analyse, um eine verständliche Zusammenfassung, Ausreißer und Alltagstipps zu erhalten.
            </p>
            <?php if ($canEdit): ?>
                <button type="button" class="btn btn-blue" id="btn-generate-report-inline">
                    🚀 KI-Finanzreport jetzt erstellen
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- DETAIL-BEREICHE -->
    <div class="report-section-grid">

        <!-- 1. Ausgaben nach Kategorien -->
        <section class="card">
            <div class="card-header">
                <h2>🏷️ Ausgaben nach Kategorien</h2>
            </div>
            <p class="subtitle">
                Überblick über deine getaggten Ausgaben im Vergleich zum Vormonat mit Co-Tags.
            </p>

            <?php if (!empty($aiAnalysis['tag_anomalies'])): ?>
                <div class="report-findings-box">
                    <strong>Ausreißer & Anomalien:</strong>
                    <ul class="report-findings-list">
                        <?php foreach ($aiAnalysis['tag_anomalies'] as $anomaly): ?>
                            <li>
                                <span class="report-tag-badge"><?= htmlspecialchars($anomaly['tag_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>:
                                <?= htmlspecialchars($anomaly['observation'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($anomaly['overlap_context'])): ?>
                                    <span class="report-overlap-hint">(<?= htmlspecialchars($anomaly['overlap_context'], ENT_QUOTES, 'UTF-8') ?>)</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($tags)): ?>
                <p class="text-muted">Keine getaggten Ausgaben im Zeitraum vorhanden.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="receipts-table stack-table">
                        <thead>
                        <tr>
                            <th>Kategorie / Tag</th>
                            <th class="text-right">Ziel-Summe</th>
                            <th class="text-right">Referenz</th>
                            <th class="text-right">Delta</th>
                            <th>Häufigste Co-Tags</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tags as $tagRow): ?>
                            <tr>
                                <td data-label="Tag">
                                    <strong><?= htmlspecialchars($tagRow['tag_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>
                                <td data-label="Ziel-Summe" class="text-right">
                                    <?= number_format($tagRow['target_sum'], 2, ',', '.') ?> €
                                </td>
                                <td data-label="Referenz" class="text-right">
                                    <?= number_format($tagRow['reference_sum'], 2, ',', '.') ?> €
                                </td>
                                <td data-label="Delta"
                                    class="text-right <?= $tagRow['delta_absolute'] > 0 ? 'text-red' : ($tagRow['delta_absolute'] < 0 ? 'text-green' : '') ?>">
                                    <?= $tagRow['delta_absolute'] > 0 ? '+' : '' ?><?= number_format($tagRow['delta_absolute'], 2, ',', '.') ?>
                                    €
                                    <?php if ($tagRow['delta_percent'] !== null): ?>
                                        <small>(<?= $tagRow['delta_percent'] > 0 ? '+' : '' ?><?= number_format($tagRow['delta_percent'], 1, ',', '.') ?>
                                            %)</small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Co-Tags">
                                    <?php if (!empty($tagRow['overlap_tags'])): ?>
                                        <div class="co-tag-chips">
                                            <?php foreach ($tagRow['overlap_tags'] as $co): ?>
                                                <span class="co-tag-chip"><?= htmlspecialchars($co, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">–</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- 2. Feste Verträge & Abos -->
        <section class="card">
            <div class="card-header">
                <h2>📑 Feste Verträge & Abos</h2>
            </div>
            <p class="subtitle">
                Prüfung deiner regelmäßigen Abbuchungen und Verträge auf Veränderungen oder fehlende Buchungen.
            </p>

            <?php if (!empty($aiAnalysis['contract_findings'])): ?>
                <div class="report-findings-box">
                    <strong>Wichtige Hinweise:</strong>
                    <ul class="report-findings-list">
                        <?php foreach ($aiAnalysis['contract_findings'] as $finding): ?>
                            <li>
                                <strong><?= htmlspecialchars($finding['contract_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>:</strong>
                                <?= htmlspecialchars($finding['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($deviations)): ?>
                <p class="text-green">✅ Alle festen Verträge und Abos wurden in diesem Zeitraum wie erwartet abgebucht.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="receipts-table stack-table">
                        <thead>
                        <tr>
                            <th>Vertrag</th>
                            <th>Art</th>
                            <th class="text-right">Soll</th>
                            <th class="text-right">Ist</th>
                            <th class="text-right">Differenz</th>
                            <th>Hinweis</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($deviations as $dev): ?>
                            <tr>
                                <td data-label="Vertrag">
                                    <strong><?= htmlspecialchars($dev['contract_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>
                                <td data-label="Art">
                                    <span class="report-dev-badge report-dev-<?= htmlspecialchars($dev['type'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= $dev['type'] === 'missing_payment' ? 'Fehlend' : 'Betrag' ?>
                                    </span>
                                </td>
                                <td data-label="Soll" class="text-right">
                                    <?= number_format($dev['expected_amount'], 2, ',', '.') ?> €
                                </td>
                                <td data-label="Ist" class="text-right">
                                    <?= number_format($dev['actual_amount'], 2, ',', '.') ?> €
                                </td>
                                <td data-label="Differenz"
                                    class="text-right <?= $dev['difference'] > 0 ? 'text-red' : ($dev['difference'] < 0 ? 'text-orange' : '') ?>">
                                    <?= $dev['difference'] > 0 ? '+' : '' ?><?= number_format($dev['difference'], 2, ',', '.') ?> €
                                </td>
                                <td data-label="Hinweis">
                                    <?= htmlspecialchars($dev['details'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- 3. Einkäufe & Kassenbons -->
        <section class="card">
            <div class="card-header">
                <h2>🧾 Einkäufe & Kassenbons</h2>
            </div>
            <p class="subtitle">
                Supermärkte, Preisanstiege bei Artikeln und kleinere Beträge im Alltag.
            </p>

            <?php if (!empty($aiAnalysis['receipt_insights_analysis'])): ?>
                <div class="report-findings-box">
                    <strong>KI-Erkenntnisse:</strong>
                    <ul class="report-findings-list">
                        <?php if (!empty($aiAnalysis['receipt_insights_analysis']['inflation_notes'])): ?>
                            <li><strong>Inflation /
                                    Einzelpreise:</strong> <?= htmlspecialchars($aiAnalysis['receipt_insights_analysis']['inflation_notes'], ENT_QUOTES, 'UTF-8') ?>
                            </li>
                        <?php endif; ?>
                        <?php if (!empty($aiAnalysis['receipt_insights_analysis']['merchant_notes'])): ?>
                            <li><strong>Händler &
                                    Kleinbeträge:</strong> <?= htmlspecialchars($aiAnalysis['receipt_insights_analysis']['merchant_notes'], ENT_QUOTES, 'UTF-8') ?>
                            </li>
                        <?php endif; ?>
                        <?php if (!empty($aiAnalysis['receipt_insights_analysis']['basket_split_notes'])): ?>
                            <li>
                                <strong>Warenkörbe:</strong> <?= htmlspecialchars($aiAnalysis['receipt_insights_analysis']['basket_split_notes'], ENT_QUOTES, 'UTF-8') ?>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="receipt-insights-subgrid">
                <!-- Top Händler -->
                <div class="insights-subcol">
                    <h3>🏪 Top Händler</h3>
                    <?php if (empty($receiptInsights['top_merchants'])): ?>
                        <p class="text-muted">Keine Händlerdaten erfasst.</p>
                    <?php else: ?>
                        <ul class="insights-list">
                            <?php foreach ($receiptInsights['top_merchants'] as $m): ?>
                                <li>
                                    <span><?= htmlspecialchars($m['merchant'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)$m['count'] ?>x)</span>
                                    <strong><?= number_format((float)$m['total'], 2, ',', '.') ?> €</strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Kleinbuchungen & Preissteigerungen -->
                <div class="insights-subcol">
                    <h3>⚡ Kleinbuchungen (&lt; 10 €)</h3>
                    <p>
                        <strong><?= (int)($receiptInsights['micro_transactions']['count'] ?? 0) ?> Buchungen</strong>
                        mit insgesamt
                        <strong><?= number_format((float)($receiptInsights['micro_transactions']['total_amount'] ?? 0), 2, ',', '.') ?>
                            €</strong>
                    </p>

                    <?php if (!empty($receiptInsights['top_price_increases'])): ?>
                        <h3 class="insights-subheading">📈 Preissteigerungen bei Artikeln</h3>
                        <ul class="insights-list">
                            <?php foreach ($receiptInsights['top_price_increases'] as $p): ?>
                                <li>
                                    <span><?= htmlspecialchars($p['item_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="text-red">+<?= number_format((float)$p['increase_percent'], 1, ',', '.') ?>% (<?= number_format((float)$p['old_price'], 2, ',', '.') ?> € &rarr; <?= number_format((float)$p['new_price'], 2, ',', '.') ?> €)</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Warenkorb-Splits -->
            <?php if (!empty($receiptInsights['basket_splits'])): ?>
                <h3 class="insights-subheading">🧺 Warenkorb-Splits (Mehrere Warengruppen auf einem Bon)</h3>
                <div class="table-responsive">
                    <table class="receipts-table stack-table">
                        <thead>
                        <tr>
                            <th>Händler</th>
                            <th>Datum</th>
                            <th class="text-right">Gesamt</th>
                            <th>Enthaltene Kategorien</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($receiptInsights['basket_splits'] as $split): ?>
                            <tr>
                                <td data-label="Händler">
                                    <strong><?= htmlspecialchars($split['store'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td data-label="Datum"><?= date('d.m.Y', strtotime($split['purchase_date'])) ?></td>
                                <td data-label="Gesamt"
                                    class="text-right"><?= number_format($split['total'], 2, ',', '.') ?> €
                                </td>
                                <td data-label="Kategorien">
                                    <div class="co-tag-chips">
                                        <?php foreach ($split['categories'] as $cat): ?>
                                            <span class="co-tag-chip"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

    </div>

</div>
</html>
