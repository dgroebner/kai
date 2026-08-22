<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// Auth-Check — immer zuerst
Auth::requirePage();

$logger = new Logger();

// CSRF-Token für das Formular bereitstellen
$csrfToken = Auth::csrfToken();

$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_real_yield') {
    // CSRF-Token prüfen
    if (!Auth::isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit("Ungültiger CSRF-Token.");
    }

    $yieldData = is_array($_POST['real_yield'] ?? null) ? $_POST['real_yield'] : [];

    $db->beginTransaction();
    try {
        $updateStmt = $db->prepare("
            UPDATE pv_forecast_daily 
            SET real_watt_hours_day = :real_wh 
            WHERE forecast_date = :date
        ");

        foreach ($yieldData as $date => $kwhValue) {
            // Datum strikt validieren, bevor es in die Abfrage gelangt
            $dateObj = DateTime::createFromFormat('Y-m-d', (string)$date);
            if (!$dateObj || $dateObj->format('Y-m-d') !== (string)$date) {
                continue;
            }

            // Wenn das Feld leer gelassen wurde, tragen wir NULL ein
            if (!is_scalar($kwhValue) || trim((string)$kwhValue) === '') {
                $updateStmt->execute([':real_wh' => null, ':date' => $date]);
                continue;
            }

            // Komma durch Punkt ersetzen und in Wattstunden umrechnen
            $kwh = (float)str_replace(',', '.', (string)$kwhValue);
            $wh = (int)round(max(0.0, $kwh) * 1000);

            $updateStmt->execute([
                    ':real_wh' => $wh,
                    ':date' => $date
            ]);
        }
        $db->commit();
        $successMessage = "Tatsächliche Erträge erfolgreich gespeichert.";
    } catch (Throwable $e) {
        $db->rollBack();
        $logger->error("PVCharge index.php: Fehler beim Speichern der echten Erträge.", ['error' => $e->getMessage()]);
        $errorMessage = "Fehler beim Speichern. Bitte versuche es später erneut.";
    }
}

// --- Live-Daten aus pv_live abrufen ---
$liveStmt = $db->query("SELECT * FROM pv_live ORDER BY id DESC LIMIT 1");
$liveData = $liveStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// --- Tagesprognosen (nächste 7 Tage) ---
$dailyStmt = $db->prepare("
    SELECT forecast_date, watt_hours_day, real_watt_hours_day
    FROM pv_forecast_daily
    WHERE forecast_date >= CURDATE() - INTERVAL 3 DAY
    ORDER BY forecast_date
    LIMIT 10
");
$dailyStmt->execute();
$dailyForecasts = $dailyStmt->fetchAll();

// --- Stündliche Prognosen für heute ---
$hourlyStmt = $db->prepare("
    SELECT forecast_time, watts, watt_hours
    FROM pv_forecast_hourly
    WHERE DATE(forecast_time) = CURDATE()
    ORDER BY forecast_time
");
$hourlyStmt->execute();
$hourlyForecasts = $hourlyStmt->fetchAll();

// --- Historische Telemetrie-Daten mit Filter & Paginierung ---
$telemetryFilter = $_GET['tel_filter'] ?? 'tag';
if (!in_array($telemetryFilter, ['tag', 'woche', 'monat'])) {
    $telemetryFilter = 'tag';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Bedingung für den Zeitfilter
$whereClause = "1=1";
if ($telemetryFilter === 'tag') {
    $whereClause = "last_update >= CURDATE()";
} elseif ($telemetryFilter === 'woche') {
    $whereClause = "last_update >= NOW() - INTERVAL 7 DAY";
} elseif ($telemetryFilter === 'monat') {
    $whereClause = "last_update >= NOW() - INTERVAL 30 DAY";
}

// Gesamtanzahl für Paginierung ermitteln
$countStmt = $db->query("SELECT COUNT(*) FROM pv_telemetry WHERE $whereClause");
$totalTelemetryRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalTelemetryRecords / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// Telemetriedaten für die aktuelle Seite abrufen
$telemetryStmt = $db->prepare("
    SELECT * FROM pv_telemetry 
    WHERE $whereClause 
    ORDER BY last_update DESC 
    LIMIT :limit OFFSET :offset
");
$telemetryStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$telemetryStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$telemetryStmt->execute();
$telemetryRecords = $telemetryStmt->fetchAll(PDO::FETCH_ASSOC);

// --- Metadaten ---
$lastUpdateStmt = $db->query("SELECT MAX(updated_at) FROM pv_forecast_daily");
$lastUpdate = $lastUpdateStmt->fetchColumn();

// Tagesgesamtertrag heute
$todayWh = 0;
foreach ($dailyForecasts as $day) {
    if ($day['forecast_date'] === date('Y-m-d')) {
        $todayWh = (int)$day['watt_hours_day'];
        break;
    }
}
$todayKwh = round($todayWh / 1000, 2);

// Peak-Leistung heute
$peakWatts = empty($hourlyForecasts) ? 0 : max(array_column($hourlyForecasts, 'watts'));

// Wetterzustand aus Ertrag ableiten
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

// Max watts für Skalierung des Balkendiagramms
$maxWatts = empty($hourlyForecasts) ? 1 : max(array_column($hourlyForecasts, 'watts'));
if ($maxWatts < 1) $maxWatts = 1;

// --- Systemabweichung (Bias) ermitteln ---
$biasStmt = $db->query("
    SELECT (SUM(real_watt_hours_day) / SUM(watt_hours_day) - 1) * 100 
    FROM pv_forecast_daily 
    WHERE real_watt_hours_day IS NOT NULL
");
$systemBias = $biasStmt->fetchColumn();
$biasFactor = ($systemBias !== null) ? (1 + ($systemBias / 100)) : 1.0;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Energie-Dashboard – Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
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
                <!-- SVG Linien (Exakt Zentrum zu Zentrum der Grid-Spalten/Reihen) -->
                <svg class="flow-svg" viewBox="0 0 650 260">
                    <path id="line-pv-house" class="flow-line" d="M 325 65 L 325 195" stroke="var(--bg-surface-hover)"/>
                    <path id="line-bat-house" class="flow-line" d="M 108 195 L 325 195"
                          stroke="var(--bg-surface-hover)"/>
                    <path id="line-grid-house" class="flow-line" d="M 542 195 L 325 195"
                          stroke="var(--bg-surface-hover)"/>
                </svg>

                <!-- PV Knoten -->
                <div class="flow-node node-pv state-gray" id="node-pv">
                    <div class="flow-node-icon">☀️</div>
                    <div class="flow-node-title">Solar (PV)</div>
                    <div class="flow-node-value val-gray" data-flow="pv_power">0 W</div>
                    <div class="flow-node-subtext"></div>
                </div>

                <!-- Batterie Knoten -->
                <div class="flow-node node-battery state-gray" id="node-battery">
                    <div class="flow-node-icon">🔋</div>
                    <div class="flow-node-title">Batterie (<span data-live="battery_soc">0</span>%)</div>
                    <div class="flow-node-value val-gray" data-flow="battery_power">0 W</div>
                    <div class="flow-node-subtext" id="bat-subtext"></div>
                </div>

                <!-- Haus Knoten (Zentrum, bleibt konstant blau) -->
                <div class="flow-node node-house">
                    <div class="flow-node-icon">🏠</div>
                    <div class="flow-node-title">Hauslast</div>
                    <div class="flow-node-value text-info" data-flow="house_load">
                        <?= isset($liveData['house_load_w']) ? number_format($liveData['house_load_w'], 0, ',', '.') : '0' ?>
                        W
                    </div>
                </div>

                <!-- Netz Knoten -->
                <div class="flow-node node-grid state-gray" id="node-grid">
                    <div class="flow-node-icon">⚡</div>
                    <div class="flow-node-title">Öff. Netz</div>
                    <div class="flow-node-value val-gray" data-flow="grid_total_w">0 W</div>
                    <div class="flow-node-subtext" id="grid-subtext"></div>
                </div>
            </div>
        </div>

        <!-- Sektion 2: Prognose & Abweichung KPI-Karten -->
        <div class="section-title">Prognose & KPI</div>
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">Prognose Heute</div>
                <div class="kpi-value">
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
                <div class="kpi-value">
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
                <div class="kpi-value kpi-value-colored"
                     style="--value-color: <?= $systemBias >= 0 ? 'var(--pv-green)' : 'var(--color-red)' ?>;">
                    <?= $systemBias !== null ? ($systemBias > 0 ? '+' : '') . number_format($systemBias, 1, ',', '.') : '–' ?>
                    <span class="kpi-unit">%</span>
                </div>
            </div>
        </div>

        <!-- Sektion 3: Tages-Leistungsverlauf (Stundenwerte) -->
        <div class="chart-section">
            <div class="section-title">Stündlicher Leistungsverlauf – Heute</div>

            <?php if (!empty($hourlyForecasts)): ?>
                <div class="bar-chart">
                    <?php foreach ($hourlyForecasts as $row):
                        $heightPct = ($maxWatts > 0) ? round($row['watts'] / $maxWatts * 100) : 0;
                        $hour = date('H', strtotime($row['forecast_time']));
                        ?>
                        <div class="bar-col">
                            <div class="bar-fill"
                                 style="height: <?= max($heightPct, 1) ?>%"
                                 data-watts="<?= number_format($row['watts'], 0, ',', '.') ?>">
                            </div>
                            <div class="bar-label"><?= $hour ?></div>
                        </div>
                    <?php endforeach; ?>
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
                                        <?php
                                        $correctedKwh = $kwh * $biasFactor;
                                        ?>
                                        <strong><?= number_format($correctedKwh, 2, ',', '.') ?> kWh</strong>
                                        <?php if ($systemBias !== null): ?>
                                            <span class="kpi-note kpi-note-muted">
												(<?= number_format($kwh, 2, ',', '.') ?>)
											</span>
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
                        <button type="submit" class="btn btn-save">
                            💾 Erträge speichern
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="no-data">
                    Noch keine Prognosedaten in der Datenbank.<br>
                    <small>Bitte den Forecast-Cronjob einmalig manuell ausführen.</small>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sektion 5: Historische Telemetrie-Daten -->
        <div class="chart-section u-mt-lg">
            <div class="section-title">Historische Telemetrie-Daten</div>

            <!-- Zeit-Filterbuttons analog zur Kassenbon-Auswertung -->
            <div class="period-switcher">
                <a href="?tel_filter=tag&page=1" class="btn <?= $telemetryFilter === 'tag' ? '' : 'btn-outline' ?>">Heute</a>
                <a href="?tel_filter=woche&page=1" class="btn <?= $telemetryFilter === 'woche' ? '' : 'btn-outline' ?>">Letzte
                    7 Tage</a>
                <a href="?tel_filter=monat&page=1" class="btn <?= $telemetryFilter === 'monat' ? '' : 'btn-outline' ?>">Letzte
                    30 Tage</a>
            </div>

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
                        <tbody>
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
                        <a href="?tel_filter=<?= $telemetryFilter ?>&page=<?= $page - 1 ?>" class="btn btn-outline">&larr;
                            Zurück</a>
                    <?php else: ?>
                        <span class="btn btn-outline disabled">&larr; Zurück</span>
                    <?php endif; ?>

                    <span class="page-info">Seite <?= $page ?> von <?= $totalPages ?></span>

                    <?php if ($page < $totalPages): ?>
                        <a href="?tel_filter=<?= $telemetryFilter ?>&page=<?= $page + 1 ?>" class="btn btn-outline">Weiter
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
</body>
</html>