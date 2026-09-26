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

// Cashflow-Historie (6 Monate bzw. 12 Monate für Trend-Visualisierung)
$cashflowHistory = $aggregated['cashflow_history'] ?? $aggregator->calculateCashflowHistory($periodType, $periodTarget);

// 4. Datenaufbereitung für Visualisierungen
// 4.1 Sparquoten-Barometer (Halbkreis-Tacho von -10 % bis +40 % = 50 PP)
$clampedRate = max(-10.0, min(40.0, $savingsRate));
$gaugeDeg = round(-90.0 + (($clampedRate - (-10.0)) / 50.0) * 180.0, 1);

// Prüfen, ob der aktuell ausgewählte Zeitraum der noch laufende Monat/Jahr ist
$isCurrentPeriod = ($periodType === 'month' && $periodTarget === date('Y-m'))
    || ($periodType === 'year' && $periodTarget === date('Y'));

$isProjection = !empty($cashflow['is_projection']);

if ($netBalance < 0) {
    if ($isProjection) {
        $gaugeStatus = 'Prognose: Defizit';
        $gaugeStatusClass = 'report-status-critical';
    } elseif ($isCurrentPeriod) {
        $gaugeStatus = 'Zwischenstand';
        $gaugeStatusClass = 'report-status-tight';
    } else {
        $gaugeStatus = 'Defizit';
        $gaugeStatusClass = 'report-status-critical';
    }
} elseif ($savingsRate < 10.0) {
    if ($isProjection) {
        $gaugeStatus = 'Prognose: Geringer Puffer';
        $gaugeStatusClass = 'report-status-tight';
    } else {
        $gaugeStatus = $isCurrentPeriod ? 'Zwischenstand' : 'Geringer Puffer';
        $gaugeStatusClass = 'report-status-tight';
    }
} elseif ($savingsRate < 25.0) {
    $gaugeStatus = $isProjection ? 'Prognose: Solide Sparquote' : 'Solide Sparquote';
    $gaugeStatusClass = 'report-status-healthy';
} else {
    $gaugeStatus = $isProjection ? 'Prognose: Exzellenter Sparer' : 'Exzellenter Sparer';
    $gaugeStatusClass = 'report-status-healthy';
}

// 4.2 50 / 30 / 20 Budget-Verteilung
if ($totalIncome > 0.01) {
    $pctFixed = round(($fixedExpenses / $totalIncome) * 100, 1);
    $pctVariable = round(($variableExpenses / $totalIncome) * 100, 1);
    $pctSaved = max(0.0, round(($netBalance / $totalIncome) * 100, 1));
} else {
    $pctFixed = 0.0;
    $pctVariable = 0.0;
    $pctSaved = 0.0;
}
$pctVar = $pctVariable;
$isOverBudget = ($totalExpenses > $totalIncome);
$budgetDeficit = $isOverBudget ? ($totalExpenses - $totalIncome) : 0.0;

// Segmentbreiten auf 100 % normiert für den Verteilungsbalken
$totalExpenseRatio = $pctFixed + $pctVariable;
if ($totalExpenseRatio > 100.0) {
    $barWFixed = round(($pctFixed / $totalExpenseRatio) * 100, 1);
    $barWVar = round(($pctVariable / $totalExpenseRatio) * 100, 1);
    $barWSaved = 0.0;
} else {
    $barWFixed = $pctFixed;
    $barWVar = $pctVariable;
    $barWSaved = max(0.0, round(100.0 - $barWFixed - $barWVar, 1));
}

// 4.3 6-Monats-Trend Maximum für Skalierung
$maxTrendVal = 1000.0;
foreach ($cashflowHistory as $histItem) {
    $maxTrendVal = max($maxTrendVal, (float)$histItem['total_income'], (float)$histItem['total_expenses']);
}
$maxTrendVal = ceil($maxTrendVal / 500) * 500;

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

    <!-- Hinweisbanner bei laufendem Zeitraum -->
    <?php if ($isCurrentPeriod): ?>
        <div class="report-ongoing-banner">
            <div class="report-ongoing-icon">ℹ️</div>
            <div class="report-ongoing-body">
                <strong>Laufender Monat (<?= $isProjection ? 'Prognose zum Monatsende' : 'Zwischenstand' ?>):</strong>
                <p>
                    <?php if ($isProjection): ?>
                        Dieser Monat ist aktuell noch in Bewegung. Um eine verzerrte Momentaufnahme zu vermeiden, arbeiten die Kennzahlen und Barometer oben mit einer <strong>Prognose zum Monatsende</strong>:
                        Bereits verbuchte Umsätze werden mit allen noch ausstehenden, fest eingeplanten Vertragsbuchungen zusammengeführt (noch ausstehend:
                        <span class="text-green">+<?= number_format((float)($cashflow['pending_income'] ?? 0), 2, ',', '.') ?> €</span> Einnahmen,
                        <span class="text-red">-<?= number_format((float)($cashflow['pending_expenses'] ?? 0), 2, ',', '.') ?> €</span> Fixkosten).
                    <?php else: ?>
                        Dieser Monat ist aktuell noch in Bewegung. Fixkosten, Leasingraten und laufende Verträge werden typischerweise direkt am Monatsanfang abgebucht, während das Gehalt und ausgleichende Einnahmen meist erst gegen Monatsende eingehen. Ein temporäres rechnerisches Minus oder eine geringere Sparquote zur Monatsmitte ist daher völlig normal und gleicht sich zum Monatsabschluss meist wieder aus.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- KPI-Dashboard -->
    <section class="kpi-grid report-kpi-grid">
        <div class="kpi-card report-kpi-income">
            <div class="kpi-label">
                📈 Gesamteinnahmen
                <?php if ($isProjection): ?>
                    <span class="report-prognose-pill">Prognose</span>
                <?php endif; ?>
            </div>
            <div class="kpi-value-sm text-green">
                +<?= number_format($totalIncome, 2, ',', '.') ?> €
            </div>
            <?php if ($isProjection && isset($cashflow['actual_income'])): ?>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Ist-Stand: +<?= number_format((float)$cashflow['actual_income'], 2, ',', '.') ?> €
                </div>
            <?php endif; ?>
        </div>
        <div class="kpi-card report-kpi-expenses">
            <div class="kpi-label">
                📉 Gesamtausgaben
                <?php if ($isProjection): ?>
                    <span class="report-prognose-pill">Prognose</span>
                <?php endif; ?>
            </div>
            <div class="kpi-value-sm text-red">
                -<?= number_format($totalExpenses, 2, ',', '.') ?> €
            </div>
            <?php if ($isProjection && isset($cashflow['actual_expenses'])): ?>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Ist-Stand: -<?= number_format((float)$cashflow['actual_expenses'], 2, ',', '.') ?> €
                </div>
            <?php endif; ?>
        </div>
        <div class="kpi-card report-kpi-balance">
            <div class="kpi-label">
                💰 Netto-Saldo
                <?php if ($isProjection): ?>
                    <span class="report-prognose-pill">Prognose</span>
                <?php endif; ?>
            </div>
            <div class="kpi-value-sm <?= $netBalance >= 0 ? 'text-green' : 'text-red' ?>">
                <?= $netBalance >= 0 ? '+' : '' ?><?= number_format($netBalance, 2, ',', '.') ?> €
            </div>
            <?php if ($isProjection && isset($cashflow['actual_net_balance'])): ?>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Ist-Stand: <?= $cashflow['actual_net_balance'] >= 0 ? '+' : '' ?><?= number_format((float)$cashflow['actual_net_balance'], 2, ',', '.') ?> €
                </div>
            <?php endif; ?>
        </div>
        <div class="kpi-card report-kpi-savings">
            <div class="kpi-label">
                🎯 Sparquote
                <?php if ($isProjection): ?>
                    <span class="report-prognose-pill">Prognose</span>
                <?php endif; ?>
            </div>
            <div class="kpi-value-sm <?= $savingsRate >= 0 ? ($savingsRate >= 20.0 ? 'text-green' : '') : 'text-red' ?>">
                <?= $savingsRate > 0 ? '+' : '' ?><?= number_format($savingsRate, 1, ',', '.') ?> %
            </div>
            <?php if ($isProjection && isset($cashflow['actual_savings_rate_percent'])): ?>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Ist-Stand: <?= number_format((float)$cashflow['actual_savings_rate_percent'], 1, ',', '.') ?> %
                </div>
            <?php endif; ?>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">
                📑 Gebundene Fixkosten
                <?php if ($isProjection): ?>
                    <span class="report-prognose-pill">Prognose</span>
                <?php endif; ?>
            </div>
            <div class="kpi-value-sm">
                <?= number_format($fixedExpenses, 2, ',', '.') ?> €
            </div>
            <?php if ($isProjection && isset($cashflow['actual_fixed_booked'])): ?>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Bereits gebucht: <?= number_format((float)$cashflow['actual_fixed_booked'], 2, ',', '.') ?> €
                </div>
            <?php endif; ?>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">
                🛒 Variabler Konsum
            </div>
            <div class="kpi-value-sm">
                <?= number_format($variableExpenses, 2, ',', '.') ?> €
            </div>
            <?php if ($isProjection): ?>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Bisher angefallen
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- NEUES FINANZ-COCKPIT (Barometer & 50/30/20-Verteilung) -->
    <div class="report-cockpit-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
        <!-- 1. Sparquoten-Barometer (Halbkreis-Tacho) -->
        <div class="card report-cockpit-card">
            <div class="report-cockpit-header">
                <h3>
                    🧭 Sparquoten-Barometer
                    <?php if ($isProjection): ?>
                        <span class="report-prognose-pill">Prognose</span>
                    <?php endif; ?>
                </h3>
                <span class="report-status-badge <?= htmlspecialchars($gaugeStatusClass, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($gaugeStatus, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <div class="report-gauge-container">
                <svg viewBox="0 0 320 185" class="report-gauge-svg" style="max-width: 270px; width: 100%; height: auto; display: block; margin: 0 auto;">
                    <!-- 4 Farbzonen (Halbkreisbogen r=85, cx=160, cy=130) -->
                    <!-- Defizit (< 0%) -->
                    <path d="M 75 130 A 85 85 0 0 1 91.2 80" fill="none" stroke="#ef4444" stroke-width="16" stroke-linecap="round" />
                    <!-- Knapp (0% - 10%) -->
                    <path d="M 91.2 80 A 85 85 0 0 1 133.7 49.2" fill="none" stroke="#f59e0b" stroke-width="16" />
                    <!-- Solide (10% - 25%) -->
                    <path d="M 133.7 49.2 A 85 85 0 0 1 210.0 61.2" fill="none" stroke="#10b981" stroke-width="16" />
                    <!-- Top (> 25%) -->
                    <path d="M 210.0 61.2 A 85 85 0 0 1 245 130" fill="none" stroke="#059669" stroke-width="16" stroke-linecap="round" />

                    <!-- Skalen-Beschriftung direkt am Bogen -->
                    <text x="48" y="134" text-anchor="end" fill="#ef4444" font-size="11" font-weight="700">&lt; 0%</text>
                    <text x="78" y="74" text-anchor="end" fill="#f59e0b" font-size="11" font-weight="700">0%</text>
                    <text x="124" y="38" text-anchor="end" fill="#cbd5e1" font-size="11" font-weight="700">10%</text>
                    <text x="216" y="48" text-anchor="start" fill="#10b981" font-size="11" font-weight="700">25%</text>
                    <text x="272" y="134" text-anchor="start" fill="#059669" font-size="11" font-weight="700">&gt; 40%</text>

                    <!-- Zonen-Namen unter den Segmenten -->
                    <text x="75" y="152" text-anchor="middle" fill="#ef4444" font-size="9" font-weight="600">Defizit</text>
                    <text x="112" y="152" text-anchor="middle" fill="#f59e0b" font-size="9" font-weight="600">Puffer</text>
                    <text x="175" y="152" text-anchor="middle" fill="#10b981" font-size="9" font-weight="600">Solide</text>
                    <text x="235" y="152" text-anchor="middle" fill="#059669" font-size="9" font-weight="600">Top</text>

                    <!-- Zeigernadel -->
                    <polygon points="157,130 160,54 163,130" fill="#ffffff" class="report-gauge-needle"
                             style="transform-origin: 160px 130px; transform: rotate(<?= $gaugeDeg ?>deg);" />
                    <circle cx="160" cy="130" r="8" fill="#3b82f6" stroke="#0f172a" stroke-width="2" />
                    <circle cx="160" cy="130" r="3" fill="#ffffff" />

                    <!-- Großer Prozentwert im Zentrum -->
                    <text x="160" y="112" text-anchor="middle" fill="<?= $savingsRate >= 0 ? '#10b981' : '#ef4444' ?>" font-size="22" font-weight="800">
                        <?= $savingsRate > 0 ? '+' : '' ?><?= number_format($savingsRate, 1, ',', '.') ?> %
                    </text>

                    <!-- Status-Badge im SVG -->
                    <text x="160" y="174" text-anchor="middle" fill="<?= $netBalance >= 0 ? '#60a5fa' : '#ef4444' ?>" font-size="11" font-weight="700" letter-spacing="1">
                        <?= htmlspecialchars(strtoupper($gaugeStatus), ENT_QUOTES, 'UTF-8') ?>
                    </text>
                </svg>
            </div>
        </div>

        <!-- 2. 50 / 30 / 20 Budget-Verteilung -->
        <div class="card report-cockpit-card">
            <div class="report-cockpit-header">
                <h3>
                    ⚖️ 50 / 30 / 20 Budget-Verteilung
                    <?php if ($isProjection): ?>
                        <span class="report-prognose-pill">Prognose</span>
                    <?php endif; ?>
                </h3>
                <span class="report-status-badge <?= $isOverBudget ? ($isProjection ? 'report-status-tight' : ($isCurrentPeriod ? 'report-status-tight' : 'report-status-critical')) : 'report-status-healthy' ?>">
                    <?= $isOverBudget ? ($isProjection ? 'PROGNOSE: ÜBERSCHREITUNG' : ($isCurrentPeriod ? 'ZWISCHENSTAND' : 'ÜBERSCHREITUNG')) : ($isProjection ? 'PROGNOSE: IN BALANCE' : 'IN BALANCE') ?>
                </span>
            </div>
            <div class="report-budget-container">
                <div class="report-budget-bar-wrapper">
                    <div class="report-budget-bar">
                        <?php if ($barWFixed > 0): ?>
                            <div class="report-budget-seg report-budget-seg-fixed" style="width: <?= $barWFixed ?>%;" title="Fixkosten: <?= $pctFixed ?>%">
                                <?= $barWFixed >= 14 ? 'Fix ' . $pctFixed . '%' : ($barWFixed >= 8 ? $pctFixed . '%' : '') ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($barWVar > 0): ?>
                            <div class="report-budget-seg report-budget-seg-var" style="width: <?= $barWVar ?>%;" title="Konsum: <?= $pctVar ?>%">
                                <?= $barWVar >= 14 ? 'Konsum ' . $pctVar . '%' : ($barWVar >= 8 ? $pctVar . '%' : '') ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($barWSaved > 0): ?>
                            <div class="report-budget-seg report-budget-seg-saved" style="width: <?= $barWSaved ?>%;" title="Sparen: <?= $pctSaved ?>%">
                                <?= $barWSaved >= 14 ? 'Sparen ' . $pctSaved . '%' : ($barWSaved >= 8 ? $pctSaved . '%' : '') ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="report-budget-markers">
                        <div class="report-budget-marker" style="left: 50%;">50% Soll (Fix)</div>
                        <div class="report-budget-marker" style="left: 80%;">80% Soll (Konsum)</div>
                    </div>
                </div>

                <?php if ($isOverBudget): ?>
                    <div class="report-budget-deficit-box <?= $isCurrentPeriod ? 'report-budget-ongoing-box' : '' ?>">
                        <?php if ($isProjection): ?>
                            ℹ️ <strong>Prognose zum Monatsende:</strong> Die Gesamtausgaben übersteigen nach Abzug aller anstehenden Buchungen die Einnahmen voraussichtlich um <strong><?= number_format($budgetDeficit, 2, ',', '.') ?> €</strong>.
                        <?php elseif ($isCurrentPeriod): ?>
                            ℹ️ <strong>Laufender Monat:</strong> Ausgaben liegen aktuell um <?= number_format($budgetDeficit, 2, ',', '.') ?> € über den bisherigen Eingängen. Der finale Ausgleich erfolgt in der Regel mit dem Gehaltseingang zum Monatsende.
                        <?php else: ?>
                            ⚠️ Ausgaben übersteigen Einnahmen um <strong><?= number_format($budgetDeficit, 2, ',', '.') ?> €</strong>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="report-budget-stats">
                    <div class="report-budget-stat-item">
                        <div class="report-budget-stat-header">
                            <span>Fixkosten</span>
                            <span><?= $pctFixed <= 50.0 ? '✅' : '⚠️' ?></span>
                        </div>
                        <div class="report-budget-stat-val text-blue">
                            <?= $pctFixed ?> %
                        </div>
                        <div class="report-budget-stat-sub">
                            <?= number_format($fixedExpenses, 2, ',', '.') ?> € (Soll: &le; 50%)
                        </div>
                    </div>
                    <div class="report-budget-stat-item">
                        <div class="report-budget-stat-header">
                            <span>Konsum</span>
                            <span><?= $pctVariable <= 35.0 ? '✅' : '⚡' ?></span>
                        </div>
                        <div class="report-budget-stat-val text-orange">
                            <?= $pctVariable ?> %
                        </div>
                        <div class="report-budget-stat-sub">
                            <?= number_format($variableExpenses, 2, ',', '.') ?> € (Soll: ~30%)
                        </div>
                    </div>
                    <div class="report-budget-stat-item">
                        <div class="report-budget-stat-header">
                            <span>Sparen</span>
                            <span><?= $savingsRate >= 20.0 ? '✅' : ($netBalance >= 0 ? '🟡' : '🔴') ?></span>
                        </div>
                        <div class="report-budget-stat-val <?= $netBalance >= 0 ? 'text-green' : 'text-red' ?>">
                            <?= $savingsRate > 0 ? '+' : '' ?><?= number_format($savingsRate, 1, ',', '.') ?> %
                        </div>
                        <div class="report-budget-stat-sub">
                            <?= ($netBalance >= 0 ? '+' : '') . number_format($netBalance, 2, ',', '.') ?> € (Soll: &ge; 20%)
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 5. CASHFLOW-TREND -->
    <section class="card report-trend-card">
        <div class="report-trend-card-header">
            <h2>📈 Cashflow-Trend (<?= $periodType === 'year' ? '12 Monate' : 'Letzte 6 Monate' ?>)</h2>
            <span class="text-muted" style="font-size: 0.85rem;">
                Einnahmen (grün) vs. Ausgaben (rot) &amp; Netto-Saldo
            </span>
        </div>
        <p class="subtitle" style="margin-bottom: 0.75rem;">
            Monatlicher Verlauf zur schnellen Erkennung von Kontobewegungen und Trends.
        </p>

        <?php if (empty($cashflowHistory)): ?>
            <p class="text-muted">Keine historischen Buchungsdaten vorhanden.</p>
        <?php else:
            $cnt = count($cashflowHistory);
            $svgW = 800;
            $svgH = 240;
            $padL = 70;
            $padR = 25;
            $padT = 25;
            $padB = 48;
            $chartW = $svgW - $padL - $padR;
            $chartH = $svgH - $padT - $padB;
            $slotW = $chartW / max(1, $cnt);
            $barW = max(12, min(24, round($slotW * 0.28)));
            $netPoints = [];
            $trendNodes = [];
        ?>
            <div class="report-trend-chart-wrapper" style="width: 100%; overflow-x: auto;">
                <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" class="report-trend-svg" style="min-width: 580px; max-width: 100%; height: auto; max-height: 240px; display: block; margin: 0 auto;">
                    <!-- Grid Lines & Y-Achsen-Beschriftung -->
                    <line x1="<?= $padL ?>" y1="<?= $padT ?>" x2="<?= $svgW - $padR ?>" y2="<?= $padT ?>"
                          stroke="#334155" stroke-dasharray="3 3" />
                    <text x="<?= $padL - 10 ?>" y="<?= $padT + 4 ?>" text-anchor="end" fill="#94a3b8" font-size="11" font-weight="600">
                        <?= number_format($maxTrendVal, 0, ',', '.') ?> €
                    </text>

                    <line x1="<?= $padL ?>" y1="<?= round($padT + $chartH / 2, 1) ?>" x2="<?= $svgW - $padR ?>" y2="<?= round($padT + $chartH / 2, 1) ?>"
                          stroke="#334155" stroke-dasharray="3 3" />
                    <text x="<?= $padL - 10 ?>" y="<?= round($padT + $chartH / 2 + 4, 1) ?>" text-anchor="end" fill="#94a3b8" font-size="11" font-weight="600">
                        <?= number_format($maxTrendVal / 2, 0, ',', '.') ?> €
                    </text>

                    <line x1="<?= $padL ?>" y1="<?= $padT + $chartH ?>" x2="<?= $svgW - $padR ?>" y2="<?= $padT + $chartH ?>"
                          stroke="#475569" stroke-width="1.5" />
                    <text x="<?= $padL - 10 ?>" y="<?= $padT + $chartH + 4 ?>" text-anchor="end" fill="#94a3b8" font-size="11" font-weight="600">
                        0 €
                    </text>

                    <!-- Säulen & Highlight -->
                    <?php foreach ($cashflowHistory as $idx => $m):
                        $xCenter = round($padL + ($idx + 0.5) * $slotW, 1);
                        $hInc = max(3, round(((float)$m['total_income'] / $maxTrendVal) * $chartH, 1));
                        $yInc = round($padT + $chartH - $hInc, 1);
                        $xInc = round($xCenter - $barW - 2, 1);

                        $hExp = max(3, round(((float)$m['total_expenses'] / $maxTrendVal) * $chartH, 1));
                        $yExp = round($padT + $chartH - $hExp, 1);
                        $xExp = round($xCenter + 2, 1);

                        $netRatio = (float)$m['net_balance'] / $maxTrendVal;
                        $yNet = round($padT + $chartH - ($netRatio * $chartH), 1);
                        $yNet = max($padT, min($padT + $chartH + 10, $yNet));
                        $netPoints[] = "$xCenter,$yNet";

                        $ttText = "<strong>" . htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8') . " (" . htmlspecialchars($m['month_key'], ENT_QUOTES, 'UTF-8') . ")</strong><br>"
                                . "📈 Einnahmen: +" . number_format($m['total_income'], 2, ',', '.') . " €<br>"
                                . "📉 Ausgaben: -" . number_format($m['total_expenses'], 2, ',', '.') . " €<br>"
                                . "💰 Netto-Saldo: " . ($m['net_balance'] >= 0 ? '+' : '') . number_format($m['net_balance'], 2, ',', '.') . " €<br>"
                                . "🎯 Sparquote: " . ($m['savings_rate_percent'] > 0 ? '+' : '') . number_format($m['savings_rate_percent'], 1, ',', '.') . " %";

                        $trendNodes[] = [
                            'x' => $xCenter,
                            'y' => $yNet,
                            'net' => $m['net_balance'],
                            'tt' => $ttText,
                            'is_current' => $m['is_current'],
                            'label' => $m['label']
                        ];
                    ?>
                        <?php if ($m['is_current']): ?>
                            <!-- Highlight-Rahmen für aktuellen Zeitraum -->
                            <rect x="<?= round($xCenter - $slotW / 2 + 2, 1) ?>" y="<?= $padT ?>"
                                  width="<?= round($slotW - 4, 1) ?>" height="<?= $chartH ?>"
                                  fill="rgba(59, 130, 246, 0.08)" stroke="#3b82f6" stroke-width="1.5" stroke-dasharray="3 3" rx="4" />
                        <?php endif; ?>

                        <!-- Einnahmen-Säule (Grün) -->
                        <rect class="report-trend-bar chart-bar"
                              x="<?= $xInc ?>" y="<?= $yInc ?>" width="<?= $barW ?>" height="<?= $hInc ?>"
                              fill="#10b981" rx="3" data-tooltip="<?= $ttText ?>" />

                        <!-- Ausgaben-Säule (Rot) -->
                        <rect class="report-trend-bar chart-bar"
                              x="<?= $xExp ?>" y="<?= $yExp ?>" width="<?= $barW ?>" height="<?= $hExp ?>"
                              fill="#ef4444" rx="3" data-tooltip="<?= $ttText ?>" />

                        <?php if ($cnt <= 6): ?>
                            <!-- Beträge über den Säulen bei bis zu 6 Monaten -->
                            <text x="<?= round($xInc + $barW / 2, 1) ?>" y="<?= max($padT - 4, $yInc - 4) ?>"
                                  text-anchor="middle" fill="#10b981" font-size="9" font-weight="600">
                                <?= round($m['total_income'] / 1000, 1) ?>k
                            </text>
                            <text x="<?= round($xExp + $barW / 2, 1) ?>" y="<?= max($padT - 4, $yExp - 4) ?>"
                                  text-anchor="middle" fill="#f87171" font-size="9" font-weight="600">
                                <?= round($m['total_expenses'] / 1000, 1) ?>k
                            </text>
                        <?php endif; ?>

                        <!-- Monats-Beschriftung (X-Achse) -->
                        <text x="<?= $xCenter ?>" y="<?= $padT + $chartH + 18 ?>" text-anchor="middle"
                              fill="<?= $m['is_current'] ? '#60a5fa' : '#e2e8f0' ?>"
                              font-size="12" font-weight="<?= $m['is_current'] ? '800' : '600' ?>">
                            <?= htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8') ?>
                        </text>

                        <!-- Netto-Saldo Wert direkt unter dem Monat -->
                        <text x="<?= $xCenter ?>" y="<?= $padT + $chartH + 34 ?>" text-anchor="middle"
                              fill="<?= $m['net_balance'] >= 0 ? '#10b981' : '#f87171' ?>"
                              font-size="11" font-weight="700">
                            <?= $m['net_balance'] >= 0 ? '+' : '' ?><?= number_format($m['net_balance'], 0, ',', '.') ?> €
                        </text>
                    <?php endforeach; ?>

                    <!-- Netto-Saldo Trendlinie -->
                    <?php if (count($netPoints) > 1): ?>
                        <polyline points="<?= implode(' ', $netPoints) ?>" fill="none" stroke="#60a5fa" stroke-width="2.2" stroke-dasharray="3 3" />
                    <?php endif; ?>

                    <!-- Saldo Punkte -->
                    <?php foreach ($trendNodes as $node): ?>
                        <circle class="report-trend-point chart-point"
                                cx="<?= $node['x'] ?>" cy="<?= $node['y'] ?>" r="4.5"
                                fill="<?= $node['net'] >= 0 ? '#10b981' : '#ef4444' ?>"
                                stroke="#0f172a" stroke-width="1.5"
                                data-tooltip="<?= $node['tt'] ?>" />
                    <?php endforeach; ?>
                </svg>

                <div class="report-trend-legend">
                    <div class="report-trend-legend-item">
                        <span class="report-trend-legend-color" style="background: #10b981;"></span>
                        <span>Einnahmen (k = Tausend €)</span>
                    </div>
                    <div class="report-trend-legend-item">
                        <span class="report-trend-legend-color" style="background: #ef4444;"></span>
                        <span>Ausgaben</span>
                    </div>
                    <div class="report-trend-legend-item">
                        <span class="report-trend-legend-line"></span>
                        <span>Netto-Saldo Trend</span>
                    </div>
                    <div class="report-trend-legend-item">
                        <span style="color: #60a5fa; font-weight: 700;">[---]</span>
                        <span>Aktueller Monat</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
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
                                    <?php if (!empty($dev['due_day'])): ?>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">
                                            Fälligkeit: <?= (int)$dev['due_day'] ?>. des Monats
                                            <?php if (!empty($dev['expected_date'])): ?>
                                                (<?= date('d.m.Y', strtotime($dev['expected_date'])) ?>)
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Art">
                                    <span class="report-dev-badge report-dev-<?= htmlspecialchars($dev['type'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= $dev['type'] === 'missing_payment' ? 'Fehlend' : ($dev['type'] === 'pending_payment' ? 'Ausstehend' : 'Betrag') ?>
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
