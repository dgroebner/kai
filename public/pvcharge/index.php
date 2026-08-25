<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\PVCharge\PvDashboardService;
use Kai\Tools\PVCharge\PvForecastRepository;
use Kai\Tools\PVCharge\PvTelemetryRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// Auth-Check — immer zuerst
Auth::requirePage();

$logger = new Logger();

// CSRF-Token für das Formular bereitstellen
$csrfToken = Auth::csrfToken();

$telemetryRepository = new PvTelemetryRepository();
$forecastRepository = new PvForecastRepository();
$dashboardService = new PvDashboardService($telemetryRepository);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_real_yield') {
    // CSRF-Token prüfen
    if (!Auth::isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit("Ungültiger CSRF-Token.");
    }

    $yieldData = is_array($_POST['real_yield'] ?? null) ? $_POST['real_yield'] : [];

    try {
        $forecastRepository->saveRealYields($yieldData);
        $successMessage = "Tatsächliche Erträge erfolgreich gespeichert.";
    } catch (Throwable $e) {
        $logger->error("PVCharge index.php: Fehler beim Speichern der echten Erträge.", ['error' => $e->getMessage()]);
        $errorMessage = "Fehler beim Speichern. Bitte versuche es später erneut.";
    }
}

// --- Live-Daten & Tages-Kennzahlen ---
$dashboardData = $dashboardService->getDashboardData();
$liveData = $dashboardData['live'];
$todayPeakW = $dashboardData['kpis']['todayPeakW'];
$gridImportKwh = $dashboardData['kpis']['gridImportKwh'];
$gridImportCost = $dashboardData['kpis']['gridImportCost'];
$gridExportKwh = $dashboardData['kpis']['gridExportKwh'];
$gridExportRevenue = $dashboardData['kpis']['gridExportRevenue'];
$yieldDailyKwh = $dashboardData['kpis']['yieldDailyKwh'];
$yieldRevenue = $dashboardData['kpis']['yieldRevenue'];

// --- Systemabweichung zwischen Prognose und Realertrag ---
$systemBias = $forecastRepository->getSystemBiasPercent();
$biasFactor = ($systemBias !== null) ? (1 + ($systemBias / 100)) : 1.0;

// --- Tagesprognosen (nächste 7 Tage) ---
$dailyForecasts = $forecastRepository->getDailyForecasts();

// --- Prognose-Daten für heute (Stunden/Halbstunden aufbereiten) ---
$hourlyForecasts = $forecastRepository->getTodayHourlyForecasts();

$hourlyLabels = [];
$forecastValues = [];
$correctedForecastValues = []; // Neu: Korrigierte Prognose-Werte mit Bias-Faktor
foreach ($hourlyForecasts as $row) {
    $timeKey = date('H:i', strtotime($row['forecast_time']));
    $hourlyLabels[] = $timeKey;

    $rawWatts = (float)$row['watts'];
    $forecastValues[] = $rawWatts;

    // Anwendung des Bias-Faktors (Aufschlag bei positivem Bias)
    $correctedForecastValues[] = $rawWatts * $biasFactor;
}

// --- Reale Telemetrie-Werte für heute passend zu den Prognose-Zeitpunkten holen ---
$telemetryTodayRows = $telemetryRepository->getTodayPowerCurve();

// Realwerte in eine Map nach "H:i" (auf die Minute genau oder nächste halbe Stunde) mappen
$actualMap = [];
foreach ($telemetryTodayRows as $tRow) {
    $timeKey = date('H:i', strtotime($tRow['last_update']));
    $actualMap[$timeKey] = (float)$tRow['pv_power_w'];
}

// Werte für das Chart exakt an den Labels ausrichten
$actualValues = [];
foreach ($hourlyLabels as $timeStr) {
    // Wenn es einen exakten Treffer gibt, diesen nehmen, sonst prüfen ob es nah dran ist oder null
    $actualValues[] = $actualMap[$timeStr] ?? null;
}

// --- Historische Telemetrie-Daten mit Filter & Paginierung ---
$telemetryFilter = PvTelemetryRepository::normalizeFilter($_GET['tel_filter'] ?? null);

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$totalTelemetryRecords = $telemetryRepository->countRecords($telemetryFilter);
$totalPages = max(1, (int)ceil($totalTelemetryRecords / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$telemetryRecords = $telemetryRepository->getRecords($telemetryFilter, $perPage, $offset);

// Datensätze für das Chart chronologisch aufbereiten
$chartRows = $telemetryRepository->getChartRows($telemetryFilter);

$chartLabels = [];
$chartPv = [];
$chartHouse = [];
$chartGridImport = [];
$chartGridExport = [];
$chartSoc = [];
$chartBatCharge = [];
$chartBatDischarge = [];

foreach ($chartRows as $r) {
    $chartLabels[] = $r['last_update'];
    $chartPv[] = (float)$r['pv_power_w'];
    $chartHouse[] = (float)$r['house_load_w'];

    $grid = (float)$r['grid_total_w'];
    $chartGridImport[] = $grid > 0 ? $grid : 0;
    $chartGridExport[] = $grid < 0 ? abs($grid) : 0;

    $chartSoc[] = (int)$r['battery_soc_pct'];

    $bat = (float)$r['battery_power_w'];
    $chartBatCharge[] = $bat < 0 ? abs($bat) : 0;
    $chartBatDischarge[] = $bat > 0 ? abs($bat) : 0;
}

// --- Metadaten ---
$lastUpdate = $forecastRepository->getLastForecastUpdate();

$todayWh = 0;
foreach ($dailyForecasts as $day) {
    if ($day['forecast_date'] === date('Y-m-d')) {
        $todayWh = (int)$day['watt_hours_day'];
        break;
    }
}
$todayKwh = round($todayWh / 1000, 2);
$peakWatts = empty($hourlyForecasts) ? 0 : max(array_column($hourlyForecasts, 'watts'));

function getWeatherLabel(float $kwh): array
{
    if ($kwh > 16) return ['icon' => '☀️', 'label' => 'Sehr sonnig'];
    if ($kwh > 10) return ['icon' => '🌤️', 'label' => 'Heiter'];
    if ($kwh > 5) return ['icon' => '⛅', 'label' => 'Bewölkt'];
    return ['icon' => '🌧️', 'label' => 'Stark bewölkt'];
}

function getBatteryColorClass(int $soc): string
{
    if ($soc < 20) return 'text-danger';
    if ($soc <= 50) return 'text-warning';
    return 'text-success';
}

// --- AJAX-Request Handler für Telemetrie-Updates ---
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');

    echo json_encode([
            'records' => $telemetryRecords,
            'chart' => $chartRows,
            'kpis' => $dashboardData['kpis']
    ]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Energie-Dashboard – Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
    <!-- Lokale Chart.js Einbindung konform zur 'self'-CSP -->
    <script src="../js/chart.min.js?v=<?= APP_VERSION ?>"></script>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">

    <header>
        <div class="page-header">
            <h1>⚡ Energie-Dashboard</h1>
            <div class="page-header-actions">
                <?php if (!empty($liveData['last_update'])): ?>
                    <span class="last-update">Live-Daten: <?= date('d.m.Y H:i:s', strtotime($liveData['last_update'])) ?></span>
                <?php endif; ?>
                <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
            </div>
        </div>
        <div class="subtitle">
            Live-Telemetrie & Ertragsprognose für die 4,2-kWp-Anlage am Standort 51.30°N / 12.45°O
        </div>
    </header>

    <main>

        <!-- Sektion 1: Live-Energiefluss-Diagramm -->
        <div class="section-title">Live-Energiefluss</div>
        <div class="energy-flow-card">
            <div class="energy-flow-container">
                <svg class="flow-svg" viewBox="0 0 650 260">
                    <path id="line-pv-house" class="flow-line" d="M 325 65 L 325 195" stroke="var(--bg-surface-hover)"/>
                    <path id="line-bat-house" class="flow-line" d="M 108 195 L 325 195"
                          stroke="var(--bg-surface-hover)"/>
                    <path id="line-grid-house" class="flow-line" d="M 542 195 L 325 195"
                          stroke="var(--bg-surface-hover)"/>
                </svg>

                <?php
                // Stündliche Prognose-Map für das Frontend aufbereiten (z. B. ["12:00" => 2500, "13:00" => 3100, ...])
                $frontendForecastMap = [];
                foreach ($hourlyForecasts as $row) {
                    $timeKey = date('H', strtotime($row['forecast_time'])); // Auf volle Stunde runden oder H:i nutzen
                    $frontendForecastMap[$timeKey] = (float)$row['watts'] * $biasFactor;
                }
                ?>
                <div class="flow-node node-pv state-gray" id="node-pv"
                     data-forecast="<?= htmlspecialchars(json_encode($frontendForecastMap), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="flow-node-icon" id="pv-icon">☀️</div>
                    <div class="flow-node-title">Solar (PV)</div>
                    <div class="flow-node-value val-gray" data-flow="pv_power">0 W</div>
                    <div class="flow-node-subtext" id="pv-subtext"></div>
                </div>

                <div class="flow-node node-battery state-gray" id="node-battery">
                    <div class="flow-node-icon">
                        <!-- Dynamisches SVG-Batterie-Icon -->
                        <svg class="battery-icon" viewBox="0 0 24 24" width="28" height="28">
                            <!-- Äußerer Rahmen der Batterie -->
                            <rect x="2" y="5" width="16" height="14" rx="2" fill="none" stroke="currentColor"
                                  stroke-width="2"/>
                            <!-- Batterie-Pol -->
                            <path d="M 18 9 L 21 9 L 21 15 L 18 15 Z" fill="currentColor"/>
                            <!-- Füllstand -->
                            <rect class="battery-level-fill" x="4" y="7" width="12" height="10" rx="1"
                                  fill="currentColor"/>
                        </svg>
                    </div>
                    <div class="flow-node-title">Batterie (<span data-live="battery_soc">0</span>%)</div>
                    <div class="flow-node-value val-gray" data-flow="battery_power">0 W</div>
                    <div class="flow-node-subtext" id="bat-subtext"></div>
                </div>

                <div class="flow-node node-house">
                    <div class="flow-node-icon">🏠</div>
                    <div class="flow-node-title">Hauslast</div>
                    <div class="flow-node-value text-info" data-flow="house_load">
                        <?= isset($liveData['house_load_w']) ? number_format($liveData['house_load_w'], 0, ',', '.') : '0' ?>
                        W
                    </div>
                </div>

                <div class="flow-node node-grid state-gray" id="node-grid">
                    <div class="flow-node-icon">⚡</div>
                    <div class="flow-node-title">Öff. Netz</div>
                    <div class="flow-node-value val-gray" data-flow="grid_total_w">0 W</div>
                    <div class="flow-node-subtext" id="grid-subtext"></div>
                </div>
            </div>
        </div>

        <!-- Zusätzliche Live-KPIs unter dem Energiefluss -->
        <!-- Zusätzliche Live-KPIs unter dem Energiefluss -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">Ertrag Heute</div>
                <div class="kpi-value kpi-value-sm text-warning">
                    <span id="kpi-yield-kwh"><?= number_format($yieldDailyKwh, 2, ',', '.') ?></span>
                    <span class="kpi-unit">kWh</span>
                </div>
                <div class="kpi-note kpi-note-muted">
                    (~<span id="kpi-yield-rev"><?= number_format($yieldRevenue, 2, ',', '.') ?></span> €)
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Peak-Leistung Heute</div>
                <div class="kpi-value kpi-value-sm">
                    <span id="kpi-peak-w"><?= number_format($todayPeakW, 0, ',', '.') ?></span>
                    <span class="kpi-unit">W</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Netzbezug (Heute)</div>
                <div class="kpi-value kpi-value-sm text-danger">
                    <span id="kpi-import-kwh"><?= number_format($gridImportKwh, 2, ',', '.') ?></span> <span
                            class="kpi-unit">kWh</span>
                </div>
                <div class="kpi-note kpi-note-muted">
                    (~<span id="kpi-import-cost"><?= number_format($gridImportCost, 2, ',', '.') ?></span> €)
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Netzeinspeisung (Heute)</div>
                <div class="kpi-value kpi-value-sm text-success">
                    <span id="kpi-export-kwh"><?= number_format($gridExportKwh, 2, ',', '.') ?></span> <span
                            class="kpi-unit">kWh</span>
                </div>
                <div class="kpi-note kpi-note-muted">
                    (~<span id="kpi-export-rev"><?= number_format($gridExportRevenue, 2, ',', '.') ?></span> €)
                </div>
            </div>
        </div>

        <!-- Sektion 2: Prognose & Abweichung KPI-Karten -->
        <div class="section-title">Prognose & KPI</div>
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">Prognose Heute</div>
                <div class="kpi-value kpi-value-sm">
                    <?php
                     $correctedTodayKwh = $todayKwh * $biasFactor;
                     echo $correctedTodayKwh > 0 ? number_format($correctedTodayKwh, 1, ',', '.') : '–';
                     ?>
                    <span class="kpi-unit">kWh</span>
                </div>
                <?php if ($systemBias !== null): ?>
                    <div class="kpi-note kpi-note-muted">
                        (<?= number_format($todayKwh, 1, ',', '.') ?> kWh)
                    </div>
                <?php endif; ?>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Peak-Leistung Heute</div>
                <div class="kpi-value kpi-value-sm">
                    <?= $peakWatts > 0 ? number_format($peakWatts, 0, ',', '.') : '–' ?>
                    <span class="kpi-unit">W</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Wetter Heute</div>
                <?php $wx = getWeatherLabel($todayKwh); ?>
                <div class="kpi-value kpi-value-sm">
                    <?= $wx['icon'] ?> <span class="kpi-unit"><?= $wx['label'] ?></span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Systemabweichung</div>
                <div class="kpi-value kpi-value-sm kpi-value-colored"
                     style="--value-color: <?= $systemBias >= 0 ? 'var(--pv-green)' : 'var(--color-red)' ?>;">
                    <?= $systemBias !== null ? ($systemBias > 0 ? '+' : '') . number_format($systemBias, 1, ',', '.') : '–' ?>
                    <span class="kpi-unit">%</span>
                </div>
            </div>
        </div>

        <!-- Sektion 3: Leistungsverlauf heute (Prognose vs. Real als Liniendiagramm) -->
        <div class="chart-section">
            <div class="section-title">Leistungsverlauf – Heute (Prognose vs. Real)</div>

            <?php if (!empty($hourlyForecasts)): ?>
                <div class="card u-mb-lg" style="padding: 1rem 1.5rem;">
                    <div style="position: relative; height:280px; width:100%;">
                        <canvas id="todayComparisonChart"
                                data-labels="<?= htmlspecialchars(json_encode($hourlyLabels), ENT_QUOTES, 'UTF-8') ?>"
                                data-forecast="<?= htmlspecialchars(json_encode($forecastValues), ENT_QUOTES, 'UTF-8') ?>"
                                data-corrected-forecast="<?= htmlspecialchars(json_encode($correctedForecastValues), ENT_QUOTES, 'UTF-8') ?>"
                                data-actual="<?= htmlspecialchars(json_encode($actualValues), ENT_QUOTES, 'UTF-8') ?>">
                        </canvas>
                    </div>
                </div>
            <?php else: ?>
                <div class="no-data">
                    Noch keine stündlichen Prognosedaten für heute vorhanden.<br>
                    <small>Der Cronjob befüllt diese Ansicht automatisch.</small>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sektion 4: Mehrtages-Prognose & Erfassung -->
        <div class="chart-section">
            <div class="section-title">Mehrtages-Prognose & Erfassung</div>

            <?php if (isset($successMessage)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if (isset($errorMessage)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if (!empty($dailyForecasts)):
                         $maxDayWh = max(array_column($dailyForecasts, 'watt_hours_day'));
                         if ($maxDayWh < 1) $maxDayWh = 1;
                         ?>
                <form action="" method="POST">
                    <input type="hidden" name="action" value="save_real_yield">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="table-responsive">
                        <table class="forecast-table stack-table">
                            <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Wetter</th>
                                <th class="text-right">Prognose</th>
                                <th class="text-right text-warning">Tatsächlich</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($dailyForecasts as $day):
                         $kwh = round($day['watt_hours_day'] / 1000, 2);
                         $isToday = ($day['forecast_date'] === date('Y-m-d'));
                         $barPct = round($day['watt_hours_day'] / $maxDayWh * 100);
                         $wx = getWeatherLabel($kwh);
                         $dateObj = DateTime::createFromFormat('Y-m-d', $day['forecast_date']);
                         $weekday = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)$dateObj->format('w')];

                         $realKwh = '';
                         if (isset($day['real_watt_hours_day']) && $day['real_watt_hours_day'] !== null) {
                             $realKwh = number_format($day['real_watt_hours_day'] / 1000, 2, ',', '');
                         }
                         ?>
                                <tr class="<?= $isToday ? 'is-today-row' : '' ?>">
                                    <td data-label="Datum">
                                        <strong><?= $weekday ?>, <?= $dateObj->format('d.m.') ?></strong>
                                    </td>
                                    <td data-label="Wetter"><?= $wx['icon'] ?> <?= $wx['label'] ?></td>
                                    <td data-label="Prognose" class="yield-value text-right u-nowrap">
                                        <?php $correctedKwh = $kwh * $biasFactor; ?>
                                        <strong><?= number_format($correctedKwh, 2, ',', '.') ?> kWh</strong>
                                        <?php if ($systemBias !== null): ?>
                                            <span class="kpi-note kpi-note-muted">(<?= number_format($kwh, 2, ',', '.') ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Tatsächlich" class="text-right">
                                        <input type="text"
                                               class="yield-input"
                                               name="real_yield[<?= htmlspecialchars($day['forecast_date'], ENT_QUOTES, 'UTF-8') ?>]"
                                               value="<?= htmlspecialchars($realKwh, ENT_QUOTES, 'UTF-8') ?>"
                                               placeholder="-,--"
                                                <?= ($day['forecast_date'] > date('Y-m-d')) ? 'disabled' : '' ?>
                                        > <small class="input-unit">kWh</small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-save">💾 Erträge speichern</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="no-data">
                    Noch keine Prognosedaten in der Datenbank.<br>
                    <small>Bitte den Forecast-Cronjob einmalig manuell ausführen.</small>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sektion 5: Historische Telemetrie-Daten & Grafische Auswertung -->
        <div class="chart-section u-mt-lg" id="telemetry-section">
            <div class="section-title">Historische Telemetrie-Daten & Verlauf</div>

            <!-- Zeit-Filterbuttons -->
            <div class="period-switcher">
                <a href="?tel_filter=tag&page=1" class="btn <?= $telemetryFilter === 'tag' ? '' : 'btn-outline' ?>">Heute</a>
                <a href="?tel_filter=woche&page=1" class="btn <?= $telemetryFilter === 'woche' ? '' : 'btn-outline' ?>">Letzte
                    7 Tage</a>
                <a href="?tel_filter=monat&page=1" class="btn <?= $telemetryFilter === 'monat' ? '' : 'btn-outline' ?>">Letzte
                    30 Tage</a>
            </div>

            <!-- Grafische Chart-Auswertung -->
            <?php if (!empty($chartRows)): ?>
                <div class="card u-mb-lg" style="padding: 1rem 1.5rem;">
                    <div style="position: relative; height:320px; width:100%;">
                        <canvas id="telemetryChart"
                                data-labels="<?= htmlspecialchars(json_encode($chartLabels), ENT_QUOTES, 'UTF-8') ?>"
                                data-pv="<?= htmlspecialchars(json_encode($chartPv), ENT_QUOTES, 'UTF-8') ?>"
                                data-house="<?= htmlspecialchars(json_encode($chartHouse), ENT_QUOTES, 'UTF-8') ?>"
                                data-grid-import="<?= htmlspecialchars(json_encode($chartGridImport), ENT_QUOTES, 'UTF-8') ?>"
                                data-grid-export="<?= htmlspecialchars(json_encode($chartGridExport), ENT_QUOTES, 'UTF-8') ?>"
                                data-bat-charge="<?= htmlspecialchars(json_encode($chartBatCharge), ENT_QUOTES, 'UTF-8') ?>"
                                data-bat-discharge="<?= htmlspecialchars(json_encode($chartBatDischarge), ENT_QUOTES, 'UTF-8') ?>"
                                data-soc="<?= htmlspecialchars(json_encode($chartSoc), ENT_QUOTES, 'UTF-8') ?>">
                        </canvas>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($telemetryRecords)): ?>
                <div class="table-responsive">
                    <table class="data-table stack-table">
                        <thead>
                        <tr>
                            <th>Zeitpunkt</th>
                            <th class="text-right">PV (W)</th>
                            <th class="text-right">Haus (W)</th>
                            <th class="text-right">Netz (W)</th>
                            <th class="text-right">SoC (%)</th>
                            <th class="text-right">Bat (W)</th>
                            <th class="text-right">Tagesertrag</th>
                        </tr>
                        </thead>
                        <tbody id="telemetry-table-body">
                        <?php foreach ($telemetryRecords as $row):
                         $soc = (int)($row['battery_soc_pct'] ?? 0);
                         $socColorClass = getBatteryColorClass($soc);
                         ?>
                            <tr>
                                <td data-label="Zeitpunkt"><?= htmlspecialchars($row['last_update'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td data-label="PV (W)"
                                    class="text-right"><?= number_format((float)$row['pv_power_w'], 0, ',', '.') ?> W
                                </td>
                                <td data-label="Haus (W)"
                                    class="text-right"><?= number_format((float)$row['house_load_w'], 0, ',', '.') ?> W
                                </td>
                                <td data-label="Netz (W)"
                                    class="text-right"><?= number_format((float)$row['grid_total_w'], 0, ',', '.') ?> W
                                </td>
                                <td data-label="SoC (%)"
                                    class="text-right <?= $socColorClass ?> amount-bold"><?= $soc ?> %
                                </td>
                                <td data-label="Bat (W)"
                                    class="text-right"><?= number_format((float)$row['battery_power_w'], 0, ',', '.') ?>
                                    W
                                </td>
                                <td data-label="Tagesertrag"
                                    class="text-right"><?= number_format((float)$row['yield_daily_kwh'], 2, ',', '.') ?>
                                    kWh
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Paginierung -->
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?tel_filter=<?= $telemetryFilter ?>&page=<?= $page - 1 ?>#telemetry-section"
                           class="btn btn-outline">&larr;
                            Zurück</a>
                    <?php else: ?>
                        <span class="btn btn-outline disabled">&larr; Zurück</span>
                    <?php endif; ?>

                    <span class="page-info">Seite <?= $page ?> von <?= $totalPages ?></span>

                    <?php if ($page < $totalPages): ?>
                        <a href="?tel_filter=<?= $telemetryFilter ?>&page=<?= $page + 1 ?>#telemetry-section"
                           class="btn btn-outline">Weiter
                            &rarr;</a>
                    <?php else: ?>
                        <span class="btn btn-outline disabled">Weiter &rarr;</span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="no-data">
                    Keine historischen Telemetriedaten für den ausgewählten Zeitraum gefunden.
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<script src="../js/pv-dashboard.js?v=<?= APP_VERSION ?>" defer></script>
<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
</body>
</html>