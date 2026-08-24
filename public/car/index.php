<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Car\VehicleDashboardRepository;
use Kai\Tools\Shared\Security\Auth;

// Auth-Check — immer zuerst
Auth::requirePage();

$csrfToken = Auth::csrfToken();

$vehicleDashboardRepository = new VehicleDashboardRepository();

// ----------------------------------------------------
// Hilfsfunktionen (inkl. Zeitzonenkonvertierung)
// ----------------------------------------------------

/**
 * Wandelt einen UTC-Zeitstempel für JEDEN Datenpunkt individuell
 * unter Berücksichtigung der am Erfassungstag gültigen Sommer-/Winterzeit in deutsche Ortszeit um.
 */
function formatToLocalTime(?string $utcTimeString, string $format = 'd.m.Y H:i'): string
{
    if (!$utcTimeString) {
        return '–';
    }
    try {
        $dt = new DateTime($utcTimeString, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        return $dt->format($format);
    } catch (Exception $e) {
        return $utcTimeString;
    }
}

function socColor(int $soc): string
{
    if ($soc >= 60) return '#10b981';
    if ($soc >= 25) return '#f59e0b';
    return '#ef4444';
}

function chargingLabel(string $state): array
{
    return match ($state) {
        'CHARGE_STATE_CHARGING_HV_BATTERY' => ['icon' => '⚡', 'label' => 'Lädt', 'color' => '#10b981'],
        'CHARGE_STATE_DISCHARGING' => ['icon' => '🔋', 'label' => 'Erhaltung', 'color' => '#3b82f6'],
        'CHARGE_STATE_READY_FOR_CHARGING' => ['icon' => '🔌', 'label' => 'Bereit', 'color' => '#f59e0b'],
        default => ['icon' => '💤', 'label' => 'Aus', 'color' => '#64748b'],
    };
}

// ----------------------------------------------------
// 1. Live-Fahrzeugstatus (Unabhängig vom Zeitraum)
// ----------------------------------------------------
$state = $vehicleDashboardRepository->getLatestState();

// Aktuellen Effizienz-Index für das KPI-Widget berechnen
$currentEff = null;
$effRating = ['label' => 'Keine Daten', 'color' => 'var(--text-muted)'];

if ($state && $state['soc_percent'] > 0 && $state['range_km'] > 0) {
    $currentEff = round((float)$state['range_km'] / (int)$state['soc_percent'], 2);

    if ($currentEff >= 5.0) {
        $effRating = ['label' => '🌱 Optimal', 'color' => 'var(--car-green)'];
    } elseif ($currentEff >= 4.0) {
        $effRating = ['label' => '⚡ Normal', 'color' => 'var(--car-blue)'];
    } else {
        $effRating = ['label' => '🔥 Hoher Verbrauch', 'color' => 'var(--car-orange)'];
    }
}

// ----------------------------------------------------
// 2. Zeitraum-Berechnung (Woche, Monat, Jahr in Ortszeit)
// ----------------------------------------------------
$type = $_GET['type'] ?? 'monat';
if (!in_array($type, ['woche', 'monat', 'jahr'])) {
    $type = 'monat';
}

$dateParam = $_GET['date'] ?? date('Y-m-d');
$dateTime = DateTime::createFromFormat('Y-m-d', $dateParam, new DateTimeZone('Europe/Berlin'));
if (!$dateTime) {
    $dateTime = new DateTime('now', new DateTimeZone('Europe/Berlin'));
}
$refDate = $dateTime->format('Y-m-d');

if ($type === 'woche') {
    $dayOfWeek = (int)$dateTime->format('N');
    $startDt = clone $dateTime;
    if ($dayOfWeek > 1) {
        $startDt->modify('-' . ($dayOfWeek - 1) . ' days');
    }
    $startDateLocal = $startDt->format('Y-m-d 00:00:00');

    $endDt = clone $startDt;
    $endDt->modify('+6 days');
    $endDateLocal = $endDt->format('Y-m-d 23:59:59');

    $prevDate = (clone $startDt)->modify('-7 days')->format('Y-m-d');
    $nextDate = (clone $startDt)->modify('+7 days')->format('Y-m-d');

    $periodLabel = "KW " . $startDt->format('W') . " (" . $startDt->format('d.m.Y') . " - " . $endDt->format('d.m.Y') . ")";
    $navLabelPrev = "Vorherige Woche";
    $navLabelNext = "Nächste Woche";
} elseif ($type === 'jahr') {
    $startDateLocal = $dateTime->format('Y-01-01 00:00:00');
    $endDateLocal = $dateTime->format('Y-12-31 23:59:59');

    $startDt = new DateTime($startDateLocal, new DateTimeZone('Europe/Berlin'));
    $endDt = new DateTime($endDateLocal, new DateTimeZone('Europe/Berlin'));

    $prevDate = (clone $startDt)->modify('-1 year')->format('Y-m-d');
    $nextDate = (clone $startDt)->modify('+1 year')->format('Y-m-d');

    $periodLabel = "Jahr " . $startDt->format('Y');
    $navLabelPrev = "Vorheriges Jahr";
    $navLabelNext = "Nächstes Jahr";
} else { // 'monat'
    $startDateLocal = $dateTime->format('Y-m-01 00:00:00');
    $endDateLocal = $dateTime->format('Y-m-t 23:59:59');

    $startDt = new DateTime($startDateLocal, new DateTimeZone('Europe/Berlin'));
    $endDt = new DateTime($endDateLocal, new DateTimeZone('Europe/Berlin'));

    $prevDate = (clone $startDt)->modify('-1 month')->format('Y-m-d');
    $nextDate = (clone $startDt)->modify('+1 month')->format('Y-m-d');

    $germanMonths = [
            1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
            5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
    ];
    $monthNum = (int)$startDt->format('n');
    $periodLabel = $germanMonths[$monthNum] . " " . $startDt->format('Y');
    $navLabelPrev = "Vorheriger Monat";
    $navLabelNext = "Nächster Monat";
}

// Lokale Grenzen für DB-Abfrage in UTC konvertieren
$startDtUtc = new DateTime($startDateLocal, new DateTimeZone('Europe/Berlin'));
$startDtUtc->setTimezone(new DateTimeZone('UTC'));
$startDateUtc = $startDtUtc->format('Y-m-d H:i:s');

$endDtUtc = new DateTime($endDateLocal, new DateTimeZone('Europe/Berlin'));
$endDtUtc->setTimezone(new DateTimeZone('UTC'));
$endDateUtc = $endDtUtc->format('Y-m-d H:i:s');

// ----------------------------------------------------
// 3. DB-Abfragen für gefilterte Historie (mit UTC-Grenzen)
// ----------------------------------------------------

// Zeitreihe für gefilterten Zeitraum (SoC-Verlauf Chart)
$history = $vehicleDashboardRepository->getTelemetryHistory($startDateUtc, $endDateUtc);

// Paginierung der Telemetrielog-Tabelle
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

$totalEntries = $vehicleDashboardRepository->countTelemetryEntries($startDateUtc, $endDateUtc);
$totalPages = max(1, ceil($totalEntries / $perPage));

$recentLog = $vehicleDashboardRepository->getTelemetryPage($startDateUtc, $endDateUtc, $perPage, $offset);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>VW ID.Buzz – Fahrzeug-Dashboard · Kai</title>
    <meta name="description"
          content="Live-Übersicht des Fahrzeugstatus, Batterieladestand und Telemetrie-Historie des VW ID.Buzz.">
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="container">

    <header>
        <div class="page-header">
            <h1>🚐 VW ID.Buzz</h1>
            <div class="page-header-actions">
                <?php if ($state): ?>
                    <span class="last-update">Fahrzeugdaten von: <?= formatToLocalTime($state['car_captured_at']) ?> Uhr</span>
                <?php endif; ?>
                <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
            </div>
        </div>
        <div class="subtitle">
            Fahrzeug-Telemetrie &amp; Batterie-Dashboard
        </div>
    </header>

    <main class="u-mt-lg">

        <?php if (!$state): ?>
            <div class="no-data">
                Noch keine Telemetriedaten vorhanden.<br>
                <small>Der erste Datenpunkt erscheint nach dem ersten erfolgreichen API-Call an
                    <code>/car/telemetry</code>.</small>
            </div>
        <?php else:
            $charging = chargingLabel($state['charging_state']);
            $socCol = socColor((int)$state['soc_percent']);
            ?>

            <!-- 1. LIVE IST-DATEN (Als strukturierte Status-Card) -->
            <div class="card car-info-card u-mb-lg">
                <div class="car-info-grid">
                    <div class="car-info-item">
                        <span class="info-label">Fahrgestellnummer (VIN)</span>
                        <span class="info-value vin-code"><?= htmlspecialchars($state['vin'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="car-info-item">
                        <span class="info-label">Lade-Status</span>
                        <div>
						<span class="status-pill" style="--pill-color: <?= $charging['color'] ?>;">
							<?= $charging['icon'] ?> <?= $charging['label'] ?>
						</span>
                        </div>
                    </div>
                    <div class="car-info-item">
                        <span class="info-label">Fahrzeug-Schloss</span>
                        <div>
						<span class="status-pill <?= $state['is_locked'] ? 'status-pill-locked' : 'status-pill-unlocked' ?>">
							<?= $state['is_locked'] ? '🔒 Gesperrt' : '🔓 Offen' ?>
						</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="kpi-grid">

                <div class="kpi-card">
                    <div class="kpi-label">Reichweite</div>
                    <div class="kpi-value kpi-value-sm text-info">
                        <?= number_format($state['range_km'], 0, ',', '.') ?><span class="kpi-unit"> km</span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Ladestand</div>
                    <div class="kpi-value kpi-value-sm kpi-value-colored" style="--value-color: <?= $socCol ?>;">
                        <?= (int)$state['soc_percent'] ?><span class="kpi-unit"> %</span>
                    </div>
                    <div class="soc-bar-wrap">
                        <div class="soc-bar-bg">
                            <div class="soc-bar-fill"
                                 style="width:<?= (int)$state['soc_percent'] ?>%; background:<?= $socCol ?>;"></div>
                        </div>
                        <div class="soc-target-label">Ziel: <?= (int)$state['target_soc'] ?> %</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Effizienz-Index</div>
                    <div class="kpi-value kpi-value-sm kpi-value-colored"
                         style="--value-color: <?= $effRating['color'] ?>;">
                        <?= $currentEff ? number_format($currentEff, 1, ',', '.') : '–' ?>
                        <span class="kpi-unit">km / %</span>
                    </div>
                    <div class="kpi-note" style="--value-color: <?= $effRating['color'] ?>;">
                        <?= $effRating['label'] ?>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Ladeleistung</div>
                    <div class="kpi-value kpi-value-sm kpi-value-colored"
                         style="--value-color: <?= $state['charge_power_kw'] > 0 ? 'var(--car-green)' : 'var(--text-muted)' ?>;">
                        <?= number_format($state['charge_power_kw'], 1, ',', '.') ?><span class="kpi-unit"> kW</span>
                    </div>

                    <?php if ($state['charge_power_kw'] > 0 && !empty($state['estimated_finish_at'])): ?>
                        <div class="kpi-note" style="--value-color: var(--car-green);">
                            ⏱️ Fertig ca. <?= formatToLocalTime($state['estimated_finish_at'], 'H:i') ?> Uhr
                        </div>
                    <?php else: ?>
                        <div class="kpi-note kpi-note-muted">
                            <?= $state['plug_connected'] ? 'Stecker bereit' : 'Nicht verbunden' ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Batterie Temp.</div>
                    <div class="kpi-value kpi-value-sm">
                        <?= number_format($state['battery_temp_min'], 1, ',', '.') ?>
                        – <?= number_format($state['battery_temp_max'], 1, ',', '.') ?>
                        <span class="kpi-unit">°C</span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Außentemperatur</div>
                    <div class="kpi-value kpi-value-sm text-warning">
                        <?= number_format($state['outdoor_temp_c'], 1, ',', '.') ?><span class="kpi-unit"> °C</span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-label">Kilometerstand</div>
                    <div class="kpi-value kpi-value-sm">
                        <?= number_format($state['mileage_km'], 0, ',', '.') ?><span class="kpi-unit"> km</span>
                    </div>
                </div>
            </div>

            <!-- 2. HISTORIE & ZEITRAUM-AUSWERTUNG -->
            <div class="section-title">📊 Historie &amp; Auswertung</div>

            <!-- Umschalter: Woche / Monat / Jahr -->
            <div class="period-switcher">
                <a href="?type=woche&date=<?= htmlspecialchars($refDate) ?>"
                   class="btn <?= $type === 'woche' ? '' : 'btn-outline' ?>">Woche</a>
                <a href="?type=monat&date=<?= htmlspecialchars($refDate) ?>"
                   class="btn <?= $type === 'monat' ? '' : 'btn-outline' ?>">Monat</a>
                <a href="?type=jahr&date=<?= htmlspecialchars($refDate) ?>"
                   class="btn <?= $type === 'jahr' ? '' : 'btn-outline' ?>">Jahr</a>
            </div>

            <!-- Zeitraum Navigation (Vorherige / Nächste) -->
            <div class="period-navigation">
                <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($prevDate) ?>"
                   class="btn btn-outline">◀ <?= htmlspecialchars($navLabelPrev) ?></a>
                <div class="current-period-label">
                    <?= htmlspecialchars($periodLabel) ?>
                    <span class="period-range-sub">
                    Auswertungszeitraum: <?= date('d.m.Y', strtotime($startDateLocal)) ?> bis <?= date('d.m.Y', strtotime($endDateLocal)) ?>
                </span>
                </div>
                <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($nextDate) ?>"
                   class="btn btn-outline"><?= htmlspecialchars($navLabelNext) ?> ▶</a>
            </div>

            <!-- 1. CHART: SoC-VERLAUF MIT SKALA & TOOLTIPS -->
            <div class="chart-section">
                <div class="chart-label">
                    🔋 Ladestand-Verlauf (SoC in %)
                </div>
                <?php if (!empty($history)): ?>
                    <div class="soc-chart-container" id="chart1-container">
                        <div class="chart-tooltip" id="tooltip1"></div>
                        <?php
                        $count = count($history);
                        $svgW = 1000;
                        $svgH = 180;
                        $padL = 40;
                        $padR = 20;
                        $padT = 15;
                        $padB = 25;
                        $chartW = $svgW - $padL - $padR;
                        $chartH = $svgH - $padT - $padB;

                        $points = [];
                        $dataNodes = [];

                        foreach ($history as $i => $row) {
                            $x = ($count > 1) ? round($padL + ($i / ($count - 1)) * $chartW, 1) : $padL + ($chartW / 2);
                            $soc = (int)$row['soc_percent'];
                            $y = round($padT + $chartH * (1 - $soc / 100), 1);

                            $points[] = "{$x},{$y}";
                            $timeFormatted = formatToLocalTime($row['car_captured_at'], 'd.m. H:i');
                            $dataNodes[] = [
                                    'x' => $x, 'y' => $y,
                                    'soc' => $soc,
                                    'range' => $row['range_km'],
                                    'time' => $timeFormatted
                            ];
                        }
                        $polyline = implode(' ', $points);

                        $dateFormat = ($type === 'woche') ? 'd.m. H:i' : (($type === 'jahr') ? 'd.m.Y' : 'd.m. H:i');
                        $firstTs = formatToLocalTime($history[0]['car_captured_at'], $dateFormat);
                        $lastTs = formatToLocalTime($history[$count - 1]['car_captured_at'], $dateFormat);
                        ?>
                        <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" class="soc-chart-svg" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="socGrad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.35"/>
                                    <stop offset="100%" stop-color="#3b82f6" stop-opacity="0"/>
                                </linearGradient>
                            </defs>

                            <!-- Horizontal Grid Lines & Y-Axis Skala (0%, 25%, 50%, 75%, 100%) -->
                            <?php for ($v = 0; $v <= 100; $v += 25):
                                $gy = round($padT + $chartH * (1 - $v / 100), 1);
                                ?>
                                <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $svgW - $padR ?>" y2="<?= $gy ?>"
                                      stroke="rgba(255,255,255,0.08)" stroke-dasharray="2 2"/>
                                <text x="<?= $padL - 8 ?>" y="<?= $gy + 4 ?>" fill="var(--text-muted)" font-size="10"
                                      text-anchor="end"><?= $v ?>%
                                </text>
                            <?php endfor; ?>

                            <!-- Fläche & Linie -->
                            <polygon
                                    points="<?= $padL ?>,<?= $svgH - $padB ?> <?= $polyline ?> <?= $svgW - $padR ?>,<?= $svgH - $padB ?>"
                                    fill="url(#socGrad)"/>
                            <polyline points="<?= $polyline ?>" fill="none" stroke="#3b82f6" stroke-width="2.5"
                                      stroke-linejoin="round" stroke-linecap="round"/>

                            <!-- Interaktive Mouseover-Punkte -->
                            <?php foreach ($dataNodes as $node): ?>
                                <circle class="chart-point"
                                        cx="<?= $node['x'] ?>"
                                        cy="<?= $node['y'] ?>"
                                        r="3.5"
                                        fill="#3b82f6"
                                        stroke="#1e293b"
                                        stroke-width="1.5"
                                        data-tooltip="<strong><?= $node['time'] ?> Uhr</strong><br>Ladestand: <?= $node['soc'] ?> %<br>Reichweite: <?= $node['range'] ? $node['range'] . ' km' : '–' ?>"/>
                            <?php endforeach; ?>
                        </svg>

                        <div class="chart-axis-labels"
                             style="--chart-pad-left: <?= (int)$padL ?>px; --chart-pad-right: <?= (int)$padR ?>px;">
                            <span><?= $firstTs ?> Uhr</span>
                            <span><?= $count ?> Datenpunkte</span>
                            <span><?= $lastTs ?> Uhr</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data">Keine Telemetrie-Einträge im gewählten Zeitraum vorhanden.</div>
                <?php endif; ?>
            </div>

            <!-- 2. CHART: EFFIZIENZ VS. TEMPERATUR MIT SKALEN & TOOLTIPS -->
            <div class="chart-section">
                <div class="chart-label">
                    🌡️ Außentemperatur vs. Effizienz (km pro 1% Akku)
                </div>
                <?php
                $effData = [];
                foreach ($history as $row) {
                    if (!empty($row['soc_percent']) && !empty($row['range_km']) && $row['soc_percent'] > 0 && $row['range_km'] > 0) {
                        $effData[] = [
                                'time' => formatToLocalTime($row['car_captured_at'], 'd.m. H:i'),
                                'temp' => (float)$row['outdoor_temp_c'],
                                'km_per_percent' => round((int)$row['range_km'] / (int)$row['soc_percent'], 2)
                        ];
                    }
                }
                ?>
                <?php if (count($effData) >= 2): ?>
                    <div class="soc-chart-container" id="chart2-container">
                        <div class="chart-tooltip" id="tooltip2"></div>
                        <?php
                        $countEff = count($effData);
                        $svgW = 1000;
                        $svgH = 180;
                        $padL = 40;
                        $padR = 40;
                        $padT = 15;
                        $padB = 25;
                        $chartW = $svgW - $padL - $padR;
                        $chartH = $svgH - $padT - $padB;

                        $minTemp = min(array_column($effData, 'temp')) - 2;
                        $maxTemp = max(array_column($effData, 'temp')) + 2;
                        $rangeTemp = max(1, $maxTemp - $minTemp);

                        $minEff = 2.0;
                        $maxEff = 6.0;
                        $rangeEff = $maxEff - $minEff;

                        $pointsEff = [];
                        $pointsTemp = [];
                        $nodesEff = [];

                        foreach ($effData as $i => $d) {
                            $x = ($countEff > 1) ? round($padL + ($i / ($countEff - 1)) * $chartW, 1) : $padL + ($chartW / 2);

                            $yEff = round($padT + $chartH * (1 - ($d['km_per_percent'] - $minEff) / $rangeEff), 1);
                            $pointsEff[] = "{$x},{$yEff}";

                            $yTemp = round($padT + $chartH * (1 - ($d['temp'] - $minTemp) / $rangeTemp), 1);
                            $pointsTemp[] = "{$x},{$yTemp}";

                            $nodesEff[] = [
                                    'x' => $x, 'yEff' => $yEff, 'yTemp' => $yTemp,
                                    'time' => $d['time'],
                                    'eff' => $d['km_per_percent'],
                                    'temp' => $d['temp']
                            ];
                        }
                        ?>
                        <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" class="soc-chart-svg" preserveAspectRatio="none">
                            <!-- Left Y-Axis: Effizienz Skala (Green, 2.0 - 6.0) -->
                            <?php for ($e = 2; $e <= 6; $e += 1):
                                $gy = round($padT + $chartH * (1 - ($e - $minEff) / $rangeEff), 1);
                                ?>
                                <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $svgW - $padR ?>" y2="<?= $gy ?>"
                                      stroke="rgba(255,255,255,0.06)" stroke-dasharray="2 2"/>
                                <text x="<?= $padL - 8 ?>" y="<?= $gy + 3 ?>" fill="#10b981" font-size="9"
                                      font-weight="600" text-anchor="end"><?= number_format($e, 1) ?></text>
                            <?php endfor; ?>

                            <!-- Right Y-Axis: Temperatur Skala (Orange, Min - Max) -->
                            <text x="<?= $svgW - $padR + 8 ?>" y="<?= $padT + 4 ?>" fill="#f59e0b" font-size="9"
                                  font-weight="600"><?= round($maxTemp) ?>°C
                            </text>
                            <text x="<?= $svgW - $padR + 8 ?>" y="<?= $svgH - $padB ?>" fill="#f59e0b" font-size="9"
                                  font-weight="600"><?= round($minTemp) ?>°C
                            </text>

                            <!-- Temperatur Linie (Orange gestrichelt) -->
                            <polyline points="<?= implode(' ', $pointsTemp) ?>" fill="none" stroke="#f59e0b"
                                      stroke-width="2" stroke-dasharray="4"/>
                            <!-- Effizienz Linie (Grün durchgezogen) -->
                            <polyline points="<?= implode(' ', $pointsEff) ?>" fill="none" stroke="#10b981"
                                      stroke-width="2.5" stroke-linejoin="round"/>

                            <!-- Points -->
                            <?php foreach ($nodesEff as $node): ?>
                                <circle class="chart-point" cx="<?= $node['x'] ?>" cy="<?= $node['yEff'] ?>" r="3.5"
                                        fill="#10b981" stroke="#1e293b" stroke-width="1.5"
                                        data-tooltip="<strong><?= $node['time'] ?> Uhr</strong><br>Effizienz: <?= $node['eff'] ?> km / %<br>Temperatur: <?= $node['temp'] ?> °C"/>
                            <?php endforeach; ?>
                        </svg>

                        <div class="chart-axis-labels chart-axis-labels-lg"
                             style="--chart-pad-left: <?= (int)$padL ?>px; --chart-pad-right: <?= (int)$padR ?>px;">
                            <span class="legend-scale-eff">🟢 Effizienz-Skala (km / % SoC, Links)</span>
                            <span class="legend-scale-temp">🟠 Außentemperatur-Skala (°C, Rechts)</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data">Ggf. noch zu wenige Datenpunkte mit erfasster Reichweite für den gewählten
                        Zeitraum.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Telemetrie-Log Tabelle mit Paginierung -->
            <div class="chart-section">
                <strong class="table-label">
                    Telemetrie-Einträge (<?= (int)$totalEntries ?> Einträge im Zeitraum)
                </strong>
                <?php if (!empty($recentLog)): ?>
                    <div class="table-responsive">
                        <table class="log-table stack-table">
                            <thead>
                            <tr>
                                <th>Zeitpunkt</th>
                                <th>SoC</th>
                                <th>Reichweite</th>
                                <th>Kilometerstand</th>
                                <th>Ladeleistung</th>
                                <th>Außentemp.</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentLog as $row):
                                $sc = socColor((int)$row['soc_percent']);
                                ?>
                                <tr>
                                    <td data-label="Zeitpunkt" class="u-muted">
                                        <?= formatToLocalTime($row['car_captured_at']) ?> Uhr
                                    </td>
                                    <td data-label="SoC">
                                <span class="badge status-pill" style="--pill-color: <?= $sc ?>;">
                                    <?= (int)$row['soc_percent'] ?> %
                                </span>
                                    </td>
                                    <td data-label="Reichweite">
                                        <div class="inline-edit-cell">
                                            <input type="number"
                                                   class="inline-range-input"
                                                   data-vin="<?= htmlspecialchars($state['vin'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                   data-captured="<?= htmlspecialchars($row['car_captured_at'], ENT_QUOTES, 'UTF-8') ?>"
                                                   value="<?= $row['range_km'] > 0 ? (int)$row['range_km'] : '' ?>"
                                                   placeholder="—">
                                            <span class="input-unit">km</span>
                                        </div>
                                    </td>
                                    <td data-label="Kilometerstand"><?= number_format($row['mileage_km'], 0, ',', '.') ?>
                                        km
                                    </td>
                                    <td data-label="Ladeleistung">
                                        <?php if ($row['charge_power_kw'] > 0): ?>
                                            <span class="badge badge-success">
                                        ⚡ <?= number_format($row['charge_power_kw'], 1, ',', '.') ?> kW
                                    </span>
                                        <?php else: ?>
                                            <span class="u-muted">–</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Außentemp."><?= number_format($row['outdoor_temp_c'], 1, ',', '.') ?>
                                        °C
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Paginierungs-Steuerung -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>&page=<?= $page - 1 ?>"
                                   class="btn btn-outline btn-sm">◀ Zurück</a>
                            <?php endif; ?>

                            <span class="pagination-gap">
                        Seite <?= $page ?> von <?= $totalPages ?>
                    </span>

                            <?php if ($page < $totalPages): ?>
                                <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>&page=<?= $page + 1 ?>"
                                   class="btn btn-outline btn-sm">Weiter ▶</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="no-data">Keine Telemetrie-Einträge im gewählten Zeitraum vorhanden.</div>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </main>
</div>

<!-- Inline-Editing Logik für Reichweite -->
<script src="../js/http.js?v=<?= APP_VERSION ?>" defer></script>
<script src="../js/telemetry.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>