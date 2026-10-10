<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Car\VehicleDashboardRepository;
use Kai\Tools\Shared\Security\Auth;

// Auth-Check — immer zuerst
Auth::requirePage('car_read');

$csrfToken = Auth::csrfToken();

$vehicleDashboardRepository = new VehicleDashboardRepository();
$tab = $_GET['tab'] ?? 'dashboard';

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
    } catch (Exception) {
        return $utcTimeString;
    }
}

/**
 * Formatiert Datums-/Zeitangaben von Reisen, die bereits in lokaler Zeit (Europe/Berlin) gespeichert sind.
 */
function formatTripDateTime(?string $timeString, string $format = 'd.m.Y H:i'): string
{
    if (!$timeString) {
        return '–';
    }
    try {
        $dt = new DateTime($timeString, new DateTimeZone('Europe/Berlin'));
        return $dt->format($format);
    } catch (Exception) {
        return $timeString;
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
    $st = strtoupper(trim($state));
    if (str_contains($st, 'CHARGING_HV_BATTERY') || str_contains($st, 'CHARGIN') || $st === 'CHARGE_STATE_CHARGING' || $st === 'CHARGING') {
        if (!str_contains($st, 'NOT_READY')) {
            return ['icon' => '⚡', 'label' => 'Lädt', 'color' => '#10b981'];
        }
    }
    if (str_contains($st, 'DISCHARGING')) {
        return ['icon' => '🔋', 'label' => 'Erhaltung', 'color' => '#3b82f6'];
    }
    if (str_contains($st, 'READY_FOR_CHARGING') || str_contains($st, 'READY_F')) {
        return ['icon' => '🔌', 'label' => 'Bereit', 'color' => '#f59e0b'];
    }
    return ['icon' => '💤', 'label' => 'Aus', 'color' => '#64748b'];
}

// ----------------------------------------------------
// 1. Live-Fahrzeugstatus (Unabhängig vom Zeitraum)
// ----------------------------------------------------
$state = $vehicleDashboardRepository->getLatestState();
$currentVehicleSoc = ($state && isset($state['soc_percent'])) ? (int)$state['soc_percent'] : null;

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

// Geofencing für Standort („Zuhause“ / „Unterwegs“)
$geofenceService = new \Kai\Tools\Car\Tronity\GeofenceService();
$isHome = ($state && !empty($state['latitude']) && !empty($state['longitude']))
    ? $geofenceService->isHome((float)$state['latitude'], (float)$state['longitude'])
    : null;

// ----------------------------------------------------
// 2. Zeitraum-Berechnung (Woche, Monat, Jahr in Ortszeit)
// ----------------------------------------------------
$type = $_GET['type'] ?? 'monat';
if (!in_array($type, ['woche', 'monat', 'jahr'])) {
    $type = 'monat';
}

$dateParam = $_GET['date'] ?? null;

// Falls im Tab "charges" kein konkretes Datum übergeben wurde und der aktuelle Monat noch keine Ladevorgänge hat:
// Auf den Monat des letzten Ladevorgangs springen, falls vorhanden.
$chargeRepo = ($tab === 'charges') ? new \Kai\Tools\Car\VehicleChargeRepository() : null;
$latestChargeTime = null;
if ($tab === 'charges' && $chargeRepo) {
    $latestChargeTime = $chargeRepo->getLatestChargeTime();
    if ($dateParam === null && $latestChargeTime !== null) {
        $nowLocal = new DateTime('now', new DateTimeZone('Europe/Berlin'));
        $checkStartUtc = (new DateTime($nowLocal->format('Y-m-01 00:00:00'), new DateTimeZone('Europe/Berlin')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $checkEndUtc = (new DateTime($nowLocal->format('Y-m-t 23:59:59'), new DateTimeZone('Europe/Berlin')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        if ($chargeRepo->countCharges($checkStartUtc, $checkEndUtc) === 0) {
            $lastChargeDt = new DateTime($latestChargeTime, new DateTimeZone('UTC'));
            $lastChargeDt->setTimezone(new DateTimeZone('Europe/Berlin'));
            $dateParam = $lastChargeDt->format('Y-m-d');
        }
    }
}

if ($dateParam === null) {
    $dateParam = date('Y-m-d');
}

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

$chargeStats = [];
$charges = [];

if ($tab === 'charges') {
    $chargeRepo = $chargeRepo ?? new \Kai\Tools\Car\VehicleChargeRepository();
    $chargeStats = $chargeRepo->getChargeStats($startDateUtc, $endDateUtc);
    
    $totalChargeEntries = $chargeRepo->countCharges($startDateUtc, $endDateUtc);
    $totalChargePages = max(1, ceil($totalChargeEntries / $perPage));
    
    $charges = $chargeRepo->getCharges($startDateUtc, $endDateUtc, $perPage, $offset);
    if ($latestChargeTime === null) {
        $latestChargeTime = $chargeRepo->getLatestChargeTime();
    }
}

$trips = [];
$totalTrips = 0;
$tripPage = 1;
$tripsPerPage = 10;
$totalTripPages = 1;

if ($tab === 'trips') {
    $tripRepo = new \Kai\Tools\Car\TripRepository();
    $totalTrips = $tripRepo->countTrips();
    $tripPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $totalTripPages = max(1, (int)ceil($totalTrips / $tripsPerPage));
    if ($tripPage > $totalTripPages && $totalTrips > 0) {
        $tripPage = $totalTripPages;
    }
    $tripOffset = ($tripPage - 1) * $tripsPerPage;
    $trips = $tripRepo->getTrips(limit: $tripsPerPage, offset: $tripOffset);
}

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
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">

    <header>
        <div class="page-header">
            <h1>🚐 VW ID.Buzz</h1>
            <div class="page-header-actions">
                <?php if ($state): ?>
                    <span class="last-update">Fahrzeugdaten von: <?= formatToLocalTime($state['car_captured_at']) ?> Uhr</span>
                <?php endif; ?>
                <button type="button" id="btn-tronity-sync" class="btn btn-outline" title="Live-Daten über TRONITY abrufen">🔄 Aktualisieren</button>
                <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
            </div>
        </div>
        <div class="subtitle">
            Fahrzeug-Telemetrie &amp; Batterie-Dashboard
        </div>
    </header>

    <main class="u-mt-lg">
        <div class="period-switcher sub-nav-tabs" style="justify-content: flex-start; margin-bottom: 1.5rem;">
            <a href="index.php?tab=dashboard&type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>" class="btn <?= $tab === 'dashboard' ? '' : 'btn-outline' ?>">📊 Dashboard</a>
            <a href="index.php?tab=charges&type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>" class="btn <?= $tab === 'charges' ? '' : 'btn-outline' ?>">🔌 Ladevorgänge</a>
            <a href="index.php?tab=trips&type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>" class="btn <?= $tab === 'trips' ? '' : 'btn-outline' ?>">🗺️ Reisen &amp; Ladeplaner</a>
        </div>


        <?php if ($tab === 'dashboard'): ?>

        <?php if (!$state): ?>
            <div class="no-data">
                Noch keine Telemetriedaten vorhanden.<br>
                <small>Der erste Datenpunkt erscheint nach dem ersten erfolgreichen API-Call an
                    <code>/car/telemetry</code> oder Klick auf <strong>„Aktualisieren“</strong>.</small>
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
                    <div class="car-info-item">
                        <span class="info-label">Standort</span>
                        <div>
                        <?php if (!empty($state['latitude']) && !empty($state['longitude'])): ?>
                            <span class="status-pill <?= $isHome ? 'status-pill-home' : 'status-pill-away' ?>">
                                <?= $isHome ? '🏠 Zuhause' : '🚗 Unterwegs' ?>
                            </span>
                            <a href="https://www.openstreetmap.org/?mlat=<?= urlencode((string)$state['latitude']) ?>&mlon=<?= urlencode((string)$state['longitude']) ?>#map=16/<?= urlencode((string)$state['latitude']) ?>/<?= urlencode((string)$state['longitude']) ?>"
                               target="_blank" rel="noopener noreferrer" class="status-pill status-pill-location" title="Auf Karte öffnen">
                                📍 Karte
                            </a>
                        <?php else: ?>
                            <span class="status-pill" style="--pill-color: var(--text-muted, #94a3b8);">–</span>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="kpi-grid">

                <div class="kpi-card">
                    <div class="kpi-label">Reichweite</div>
                    <div class="kpi-value kpi-value-sm text-info">
                        <?= $state['range_km'] > 0 ? number_format($state['range_km'], 0, ',', '.') . '<span class="kpi-unit"> km</span>' : '<span class="text-muted">–</span>' ?>
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
                <a href="?tab=dashboard&type=woche&date=<?= htmlspecialchars($refDate) ?>"
                   class="btn <?= $type === 'woche' ? '' : 'btn-outline' ?>">Woche</a>
                <a href="?tab=dashboard&type=monat&date=<?= htmlspecialchars($refDate) ?>"
                   class="btn <?= $type === 'monat' ? '' : 'btn-outline' ?>">Monat</a>
                <a href="?tab=dashboard&type=jahr&date=<?= htmlspecialchars($refDate) ?>"
                   class="btn <?= $type === 'jahr' ? '' : 'btn-outline' ?>">Jahr</a>
            </div>

            <!-- Zeitraum Navigation (Vorherige / Nächste) -->
            <div class="period-navigation">
                <a href="?tab=dashboard&type=<?= $type ?>&date=<?= htmlspecialchars($prevDate) ?>"
                   class="btn btn-outline">◀ <?= htmlspecialchars($navLabelPrev) ?></a>
                <div class="current-period-label">
                    <?= htmlspecialchars($periodLabel) ?>
                    <span class="period-range-sub">
                    Auswertungszeitraum: <?= date('d.m.Y', strtotime($startDateLocal)) ?> bis <?= date('d.m.Y', strtotime($endDateLocal)) ?>
                </span>
                </div>
                <a href="?tab=dashboard&type=<?= $type ?>&date=<?= htmlspecialchars($nextDate) ?>"
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

                            $points[] = "$x,$y";
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
                             style="--chart-pad-left: <?= $padL ?>px; --chart-pad-right: <?= $padR ?>px;">
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
                            $pointsEff[] = "$x,$yEff";

                            $yTemp = round($padT + $chartH * (1 - ($d['temp'] - $minTemp) / $rangeTemp), 1);
                            $pointsTemp[] = "$x,$yTemp";

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

                            <!-- Temperatur-Linie (Orange gestrichelt) -->
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
                                <a href="?tab=dashboard&type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>&page=<?= $page - 1 ?>"
                                   class="btn btn-outline btn-sm">◀ Zurück</a>
                            <?php endif; ?>

                            <span class="pagination-gap">
                        Seite <?= $page ?> von <?= $totalPages ?>
                    </span>

                            <?php if ($page < $totalPages): ?>
                                <a href="?tab=dashboard&type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>&page=<?= $page + 1 ?>"
                                   class="btn btn-outline btn-sm">Weiter ▶</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="no-data">Keine Telemetrie-Einträge im gewählten Zeitraum vorhanden.</div>
                <?php endif; ?>
            </div>

        <?php endif; ?>
        <?php elseif ($tab === 'charges'): ?>
            <!-- Zeitraum-Auswertung -->
            <div class="period-switcher">
                <a href="?tab=charges&type=woche&date=<?= htmlspecialchars($refDate) ?>"
                   class="btn <?= $type === 'woche' ? '' : 'btn-outline' ?>">Woche</a>
                <a href="?tab=charges&type=monat&date=<?= htmlspecialchars($refDate) ?>"
                   class="btn <?= $type === 'monat' ? '' : 'btn-outline' ?>">Monat</a>
                <a href="?tab=charges&type=jahr&date=<?= htmlspecialchars($refDate) ?>"
                   class="btn <?= $type === 'jahr' ? '' : 'btn-outline' ?>">Jahr</a>
            </div>

            <!-- Zeitraum Navigation (Vorherige / Nächste) -->
            <div class="period-navigation">
                <a href="?tab=charges&type=<?= $type ?>&date=<?= htmlspecialchars($prevDate) ?>"
                   class="btn btn-outline">◀ <?= htmlspecialchars($navLabelPrev) ?></a>
                <div class="current-period-label">
                    <?= htmlspecialchars($periodLabel) ?>
                    <span class="period-range-sub">
                        Auswertungszeitraum: <?= date('d.m.Y', strtotime($startDateLocal)) ?> bis <?= date('d.m.Y', strtotime($endDateLocal)) ?>
                    </span>
                </div>
                <a href="?tab=charges&type=<?= $type ?>&date=<?= htmlspecialchars($nextDate) ?>"
                   class="btn btn-outline"><?= htmlspecialchars($navLabelNext) ?> ▶</a>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Geladen Gesamt</div>
                    <div class="kpi-value kpi-value-sm text-info">
                        <?= number_format($chargeStats['total_charged'] ?? 0, 1, ',', '.') ?> <span class="kpi-unit">kWh</span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">PV-Anteil</div>
                    <div class="kpi-value kpi-value-sm text-info">
                        <?= number_format($chargeStats['total_pv'] ?? 0, 1, ',', '.') ?> <span class="kpi-unit">kWh</span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Netzbezug</div>
                    <div class="kpi-value kpi-value-sm text-info">
                        <?= number_format($chargeStats['total_grid'] ?? 0, 1, ',', '.') ?> <span class="kpi-unit">kWh</span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Ladekosten</div>
                    <div class="kpi-value kpi-value-sm text-info">
                        <?= number_format($chargeStats['total_cost'] ?? 0, 2, ',', '.') ?> <span class="kpi-unit">€</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem;">
                    <h2 style="margin: 0;">Ladevorgänge</h2>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" class="btn btn-outline btn-sm js-btn-manage-tariffs">⚡ Ladetarife verwalten</button>
                    </div>
                </div>
                
                <?php if (empty($charges)): ?>
                    <div class="no-data u-mt-md">
                        <p>Keine Ladevorgänge im gewählten Zeitraum (<?= htmlspecialchars($periodLabel) ?>).</p>
                        <?php if ($latestChargeTime !== null): 
                            $lastDt = (new DateTime($latestChargeTime, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'));
                        ?>
                            <p class="u-mt-sm">
                                <a href="?tab=charges&type=monat&date=<?= $lastDt->format('Y-m-d') ?>" class="btn btn-outline btn-sm">
                                    📅 Zum letzten Ladevorgang springen (<?= $lastDt->format('d.m.Y') ?>)
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="list-group u-mt-md">
                        <?php foreach ($charges as $charge): 
                            $isHome = $charge['location_type'] === 'HOME';
                            $startTm = formatToLocalTime($charge['start_time'], 'H:i');
                            $endTm = formatToLocalTime($charge['end_time'], 'H:i');
                            $dateStr = formatToLocalTime($charge['start_time'], 'd.m.Y');
                            
                            $durHours = floor($charge['duration_min'] / 60);
                            $durMins = $charge['duration_min'] % 60;
                            $durStr = $durHours > 0 ? "{$durHours}h {$durMins}m" : "{$durMins}m";
                            
                            $modeIcon = $charge['charge_mode'] === 'DC' ? '⚡ DC' : '🔌 AC';
                            $hasStation = !empty($charge['station_name']) || !empty($charge['station_operator']);
                            $hasCoords = !empty($charge['lat']) && !empty($charge['lon']);
                            $hasReceipt = !empty($charge['receipt_id']);
                        ?>
                            <div class="list-item" style="display: flex; flex-direction: column; gap: 0.5rem; padding: 1.2rem; margin-bottom: 1rem; background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-color); border-radius: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                                    <div>
                                        <strong><?= $dateStr ?></strong> <span class="u-muted" style="margin: 0 0.5rem;">|</span> 
                                        <?= $startTm ?> (<?= $charge['soc_start_pct'] ?>%) &rarr; <?= $endTm ?> (<?= $charge['soc_end_pct'] ?>%)
                                    </div>
                                    <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
                                        <?php if ($isHome): ?>
                                            <span class="badge badge-success">🏠 Zuhause</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">📍 Unterwegs</span>
                                            <?php if (!empty($charge['station_operator'])): ?>
                                                <span class="badge badge-primary">⚡ <?= htmlspecialchars($charge['station_operator']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($charge['station_name'])): ?>
                                                <span class="badge badge-outline" title="<?= htmlspecialchars($charge['station_name']) ?>" style="max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    <?= htmlspecialchars($charge['station_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($hasCoords): ?>
                                                <a href="https://www.openstreetmap.org/?mlat=<?= $charge['lat'] ?>&mlon=<?= $charge['lon'] ?>#map=18/<?= $charge['lat'] ?>/<?= $charge['lon'] ?>" target="_blank" rel="noopener" class="btn btn-outline btn-xs" title="Ladestation in OpenStreetMap ansehen" style="font-size: 0.75rem; padding: 2px 6px;">🗺️ OSM</a>
                                                <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode(($charge['station_name'] ? $charge['station_name'] . ', ' : '') . $charge['lat'] . ',' . $charge['lon']) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-xs" title="In Google Maps ansehen" style="font-size: 0.75rem; padding: 2px 6px;">📍 Maps</a>
                                            <?php endif; ?>
                                            <?php if ($hasCoords && !$hasStation): ?>
                                                <button type="button" class="btn btn-outline btn-xs js-btn-resolve-station" data-charge-id="<?= $charge['id'] ?>" title="Ladestation über OpenStreetMap ermitteln" style="font-size: 0.75rem; padding: 2px 6px;">🔍 Station suchen</button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <span class="badge badge-info"><?= $modeIcon ?></span>
                                    </div>
                                </div>
                                
                                <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; margin-top: 0.5rem; font-size: 0.9rem; align-items: flex-end;">
                                    <div>
                                        <div class="u-muted" style="font-size: 0.75rem; text-transform: uppercase;">Geladen</div>
                                        <strong><?= number_format($charge['charged_net_kwh'], 1, ',', '.') ?> kWh</strong> 
                                        <span class="text-success">(+<?= $charge['delta_soc_pct'] ?>%)</span>
                                    </div>
                                    <div>
                                        <div class="u-muted" style="font-size: 0.75rem; text-transform: uppercase;">Max. Leistung</div>
                                        <strong><?= number_format($charge['avg_charge_power_kw'], 1, ',', '.') ?> kW</strong>
                                    </div>
                                    <div>
                                        <div class="u-muted" style="font-size: 0.75rem; text-transform: uppercase;">Dauer</div>
                                        <strong><?= $durStr ?></strong>
                                    </div>
                                    
                                    <?php if ($isHome): ?>
                                        <div>
                                            <div class="u-muted" style="font-size: 0.75rem; text-transform: uppercase;">Solar (PV)</div>
                                            <strong class="text-success">☀️ <?= number_format($charge['home_pv_kwh'] ?? 0, 1, ',', '.') ?> kWh</strong>
                                        </div>
                                        <div>
                                            <div class="u-muted" style="font-size: 0.75rem; text-transform: uppercase;">Netzbezug</div>
                                            <strong class="text-danger">⚡ <?= number_format($charge['home_grid_kwh'] ?? 0, 1, ',', '.') ?> kWh</strong>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div>
                                        <div class="u-muted" style="font-size: 0.75rem; text-transform: uppercase;">Kosten &amp; Beleg</div>
                                        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                            <?php if ($hasReceipt): ?>
                                                <span class="badge badge-success" style="font-size: 0.85rem; padding: 4px 8px;">
                                                    🧾 <a href="../kassenbon/detail.php?id=<?= (int)$charge['receipt_id'] ?>" target="_blank" style="color: inherit; text-decoration: underline; font-weight: bold;">
                                                        <?= number_format($charge['cost_eur'] ?? 0, 2, ',', '.') ?> € (<?= htmlspecialchars($charge['receipt_store'] ?? $charge['tariff_category'] ?? 'E-Bon') ?>)
                                                    </a>
                                                </span>
                                                <button type="button" class="btn btn-outline btn-xs js-btn-unlink-receipt" data-charge-id="<?= $charge['id'] ?>" title="Beleg-Zuordnung entfernen" style="padding: 2px 6px; font-size: 0.75rem;">✕</button>
                                            <?php elseif (!empty($charge['cost_eur']) && $charge['cost_eur'] > 0): ?>
                                                <strong><?= number_format($charge['cost_eur'], 2, ',', '.') ?> €</strong>
                                                <?php if (!empty($charge['tariff_category'])): ?>
                                                    <span class="badge badge-info" style="font-size: 0.75rem;"><?= htmlspecialchars($charge['tariff_category']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!$isHome): ?>
                                                    <button type="button" class="btn btn-outline btn-xs js-btn-open-assign-modal" 
                                                        data-charge-id="<?= $charge['id'] ?>" 
                                                        data-kwh="<?= $charge['charged_net_kwh'] ?>" 
                                                        data-mode="<?= $charge['charge_mode'] ?? 'DC' ?>" 
                                                        data-operator="<?= htmlspecialchars($charge['station_operator'] ?? '') ?>" 
                                                        title="Kosten anpassen / Beleg zuordnen" style="font-size: 0.75rem; padding: 2px 6px;">✏️</button>
                                                <?php endif; ?>
                                            <?php elseif ($isHome): ?>
                                                <strong class="text-success" title="Ladung erfolgte komplett kostenfrei (100% PV)">0,00 € ☀️</strong>
                                            <?php else: ?>
                                                <span class="u-muted">–</span>
                                                <button type="button" class="btn btn-outline btn-xs js-btn-open-assign-modal" 
                                                    data-charge-id="<?= $charge['id'] ?>" 
                                                    data-kwh="<?= $charge['charged_net_kwh'] ?>" 
                                                    data-mode="<?= $charge['charge_mode'] ?? 'DC' ?>" 
                                                    data-operator="<?= htmlspecialchars($charge['station_operator'] ?? '') ?>" 
                                                    title="Kosten / E-Bon zuordnen" style="font-size: 0.75rem; padding: 2px 6px;">
                                                    🧾 Beleg / Tarif zuordnen
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Paginierung -->
                    <?php if ($totalChargePages > 1): ?>
                        <div class="pagination u-mt-lg">
                            <?php if ($page > 1): ?>
                                <a href="?tab=charges&type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>&page=<?= $page - 1 ?>" class="btn btn-outline btn-sm">◀ Zurück</a>
                            <?php endif; ?>

                            <span class="pagination-gap">Seite <?= $page ?> von <?= $totalChargePages ?></span>

                            <?php if ($page < $totalChargePages): ?>
                                <a href="?tab=charges&type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>&page=<?= $page + 1 ?>" class="btn btn-outline btn-sm">Weiter ▶</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                <?php endif; ?>
            </div>

            <!-- MODAL: BELEG ODER TARIF ZUORDNEN -->
            <div id="assign-cost-modal" class="modal-overlay app-modal" style="display: none;">
                <div class="modal-card" style="max-width: 580px;">
                    <div class="modal-header">
                        <h3>⚡ Ladekosten &amp; Beleg zuordnen</h3>
                        <button type="button" class="modal-close js-btn-close-modal" aria-label="Schließen">&times;</button>
                    </div>
                    <div class="modal-body" style="gap: 1.25rem;">
                        <div id="assign-charge-info" style="padding: 0.75rem; background: rgba(255, 255, 255, 0.04); border: 1px solid var(--border-color); border-radius: 6px; font-size: 0.9rem;">
                            <!-- Dynamischer Header via JS -->
                        </div>

                        <!-- SEKTION 1: KASSENBON / E-BON -->
                        <div style="border-top: 1px solid var(--border-color); padding-top: 1rem;">
                            <h4 style="margin: 0 0 0.5rem 0; display: flex; align-items: center; gap: 0.5rem;">
                                🧾 <span>E-Bon / Ladeabrechnung verknüpfen</span>
                            </h4>
                            <div style="margin-bottom: 0.75rem;">
                                <input type="text" id="assign-receipt-search" class="form-control" placeholder="🔍 Belege filtern (z. B. Ionity, EnBW, Datum)..." style="width: 100%;">
                            </div>
                            <div id="assign-receipts-list">
                                <!-- Vorschläge via JS -->
                            </div>
                        </div>

                        <!-- SEKTION 2: LADETARIF ANWENDEN -->
                        <div style="border-top: 1px solid var(--border-color); padding-top: 1rem;">
                            <h4 style="margin: 0 0 0.5rem 0;">⚡ Ladetarif anwenden</h4>
                            <div style="display: grid; grid-template-columns: 1fr auto; gap: 0.75rem; align-items: end;">
                                <div>
                                    <label for="assign-tariff-select" class="form-label" style="font-size: 0.85rem;">Tarif auswählen</label>
                                    <select id="assign-tariff-select" class="form-control" style="width: 100%;">
                                        <!-- Tarife via JS -->
                                    </select>
                                </div>
                                <button type="button" id="js-btn-apply-tariff" class="btn btn-primary" style="white-space: nowrap;">
                                    ⚡ Tarif anwenden
                                </button>
                            </div>
                            <div style="margin-top: 0.5rem; font-size: 0.85rem;">
                                Voraussichtliche Kosten: <span id="assign-tariff-preview">–</span>
                            </div>
                        </div>

                        <!-- SEKTION 3: BETRAG MANUELL EINTRAGEN -->
                        <div style="border-top: 1px solid var(--border-color); padding-top: 1rem;">
                            <h4 style="margin: 0 0 0.5rem 0;">✍️ Betrag manuell eintragen</h4>
                            <div style="display: grid; grid-template-columns: 120px 1fr auto; gap: 0.75rem; align-items: end;">
                                <div>
                                    <label for="assign-manual-amount" class="form-label" style="font-size: 0.85rem;">Betrag (€)</label>
                                    <input type="number" id="assign-manual-amount" class="form-control" step="0.01" min="0" placeholder="0.00" style="width: 100%;">
                                </div>
                                <div>
                                    <label for="assign-manual-category" class="form-label" style="font-size: 0.85rem;">Kategorie / Notiz</label>
                                    <input type="text" id="assign-manual-category" class="form-control" placeholder="z. B. Ad-hoc Ladekarte" style="width: 100%;">
                                </div>
                                <button type="button" id="js-btn-save-manual-cost" class="btn btn-outline" style="white-space: nowrap;">
                                    💾 Speichern
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="display: flex; justify-content: flex-end;">
                        <button type="button" class="btn btn-outline js-btn-close-modal">Schließen</button>
                    </div>
                </div>
            </div>

            <!-- MODAL: LADETARIFE VERWALTEN -->
            <div id="manage-tariffs-modal" class="modal-overlay app-modal" style="display: none;">
                <div class="modal-card" style="max-width: 650px;">
                    <div class="modal-header">
                        <h3>⚡ Meine bevorzugten Ladetarife</h3>
                        <button type="button" class="modal-close js-btn-close-modal" aria-label="Schließen">&times;</button>
                    </div>
                    <div class="modal-body" style="gap: 1.25rem;">
                        <div style="font-size: 0.9rem; color: var(--text-muted);">
                            Hinterlege hier deine Ladekarten und Abos (z. B. EnBW, Ionity, EWE Go). Bei Ladevorgängen unterwegs wird der passende Tarif anhand der Ladestation automatisch zugeordnet.
                        </div>

                        <!-- LISTE DER TARIFE -->
                        <div id="tariffs-list-container">
                            <!-- Dynamische Liste via JS -->
                        </div>

                        <!-- FORMULAR: TARIF ANLEGEN / BEARBEITEN -->
                        <div style="border-top: 1px solid var(--border-color); padding-top: 1rem;">
                            <h4 id="tariff-form-title" style="margin: 0 0 0.75rem 0;">Neuen Tarif anlegen</h4>
                            <form id="tariff-form" style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <input type="hidden" name="id" value="">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                                    <div>
                                        <label class="form-label" style="font-size: 0.85rem;">Tarifname *</label>
                                        <input type="text" name="name" class="form-control" required placeholder="z. B. EnBW mobility+ L" style="width: 100%;">
                                    </div>
                                    <div>
                                        <label class="form-label" style="font-size: 0.85rem;">Betreiber-Filter (Match)</label>
                                        <input type="text" name="operator_match" class="form-control" placeholder="z. B. EnBW,mobility+ oder *" style="width: 100%;">
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                                    <div>
                                        <label class="form-label" style="font-size: 0.85rem;">AC-Preis (€/kWh) *</label>
                                        <input type="number" step="0.0001" min="0" name="price_ac_eur_kwh" class="form-control" required placeholder="0.3900" style="width: 100%;">
                                    </div>
                                    <div>
                                        <label class="form-label" style="font-size: 0.85rem;">DC-Preis (€/kWh) *</label>
                                        <input type="number" step="0.0001" min="0" name="price_dc_eur_kwh" class="form-control" required placeholder="0.3900" style="width: 100%;">
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label" style="font-size: 0.85rem;">Notiz / Beschreibung</label>
                                    <input type="text" name="notes" class="form-control" placeholder="z. B. Vorteilstarif, Monatsgrundgebühr 5,99 €" style="width: 100%;">
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <input type="checkbox" id="field-is-default-tariff" name="is_default" value="1">
                                    <label for="field-is-default-tariff" style="margin: 0; cursor: pointer; font-size: 0.85rem;">
                                        Als Standard-Tarif verwenden (Fallback für fremde Stationen)
                                    </label>
                                </div>
                                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.5rem;">
                                    <button type="button" id="js-btn-reset-tariff-form" class="btn btn-outline btn-sm">Zurücksetzen</button>
                                    <button type="submit" class="btn btn-primary btn-sm">💾 Tarif speichern</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="modal-footer" style="display: flex; justify-content: flex-end;">
                        <button type="button" class="btn btn-outline js-btn-close-modal">Schließen</button>
                    </div>
                </div>
            </div>

        <?php elseif ($tab === 'trips'): ?>
            <div class="trips-container">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h2 style="margin: 0 0 0.25rem 0;">🗺️ Reisekosten- &amp; Ladeplanung</h2>
                        <div style="color: var(--text-muted); font-size: 0.9rem;">
                            Ganzheitlicher Lebenszyklus: Vorbereitung &amp; PV-Vorladekette, Vorabend-Netzladung, ABRP-Navigation &amp; Cent-genauer Kostenabgleich
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary js-btn-new-trip">
                        ➕ Neue Reise planen
                    </button>
                </div>

                <?php if (empty($trips)): ?>
                    <div class="card" style="padding: 3rem 1.5rem; text-align: center; color: var(--text-muted);">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">🚐🗺️</div>
                        <h3 style="margin: 0 0 0.5rem 0; color: var(--text-main);">Noch keine Reisen geplant</h3>
                        <p style="max-width: 500px; margin: 0 auto 1.5rem auto; font-size: 0.95rem;">
                            Plane deine nächste Fahrt bequem über den Button <strong>„Neue Reise planen“</strong>
                            oder sende Termineinladungen (.ics) an dein IMAP-Postfach, um Routen und PV-Vorladungen automatisch zu generieren.
                        </p>
                        <button type="button" class="btn btn-primary js-btn-new-trip">
                            ➕ Erste Reise anlegen
                        </button>
                    </div>
                <?php else: ?>
                    <div class="trips-list" style="display: flex; flex-direction: column; gap: 1.25rem;">
                        <?php foreach ($trips as $t): 
                            $depLocal = formatTripDateTime($t['departure_time']);
                            $retLocal = !empty($t['return_time']) ? formatTripDateTime($t['return_time']) : null;
                            $tCost = round((float)$t['home_charge_cost'] + (float)$t['en_route_charge_cost'] + (float)$t['additional_cost'], 2);
                            $tDist = (float)$t['total_distance_km'];
                            $cPer100 = ($tDist > 0) ? round(($tCost / $tDist) * 100.0, 2) : 0.0;
                            
                            $statusBadgeClass = match($t['status']) {
                                'aktiv' => 'badge-success',
                                'abgeschlossen' => 'badge-neutral',
                                'storniert' => 'badge-danger',
                                default => 'badge-warning',
                            };
                        ?>
                            <div class="card" style="padding: 1.25rem;">
                                <!-- Kopfzeile: Titel & Status links, Aktions-Buttons rechts ausgerichtet -->
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem; flex-wrap: wrap; gap: 0.75rem;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                        <h3 style="margin: 0; font-size: 1.2rem; font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($t['title']) ?></h3>
                                        <span class="badge <?= $statusBadgeClass ?>"><?= htmlspecialchars(ucfirst($t['status'])) ?></span>
                                        <?php if (!empty($t['is_round_trip'])): ?>
                                            <span class="badge badge-info" title="Hin- und Rückreise von zu Hause">🔄 Rundreise</span>
                                        <?php endif; ?>
                                        <?php if (!empty($t['parent_trip_id'])): ?>
                                            <span class="badge badge-neutral" title="Verschachtelter Ausflug im Urlaub">🏖️ Ausflug zu „<?= htmlspecialchars($t['parent_title'] ?? 'Hauptreise') ?>“</span>
                                        <?php endif; ?>
                                    </div>

                                    <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
                                        <button type="button" class="btn btn-primary btn-sm js-btn-view-trip" data-trip-id="<?= (int)$t['id'] ?>">
                                            🔍 Details &amp; Abrechnung
                                        </button>
                                        <?php if (!empty($t['abrp_deep_link'])): ?>
                                            <a href="<?= htmlspecialchars($t['abrp_deep_link']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm" title="In ABRP App öffnen">
                                                ⚡ ABRP
                                            </a>
                                        <?php endif; ?>
                                        <?php 
                                            $gmapsTarget = !empty($t['destination_lat']) && !empty($t['destination_lon'])
                                                ? ((float)$t['destination_lat'] . ',' . (float)$t['destination_lon'])
                                                : urlencode($t['destination_address']);
                                            $gmapsUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . $gmapsTarget;
                                        ?>
                                        <a href="<?= htmlspecialchars($gmapsUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm" title="Navigation in Google Maps öffnen">
                                            🗺️ Maps
                                        </a>
                                        <?php if (empty($t['parent_trip_id'])): ?>
                                            <button type="button" class="btn btn-outline btn-sm js-btn-new-subtrip" data-parent-id="<?= (int)$t['id'] ?>" data-parent-dest="<?= htmlspecialchars($t['destination_address']) ?>" title="Ausflug während des Aufenthalts anlegen">
                                                🏖️ Ausflug +
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline btn-sm js-btn-recalc-trip" data-trip-id="<?= (int)$t['id'] ?>" title="Route und Vorladekette neu kalkulieren">
                                            🔄
                                        </button>
                                        <button type="button" class="btn btn-outline btn-sm js-btn-delete-trip" data-trip-id="<?= (int)$t['id'] ?>" title="Reise löschen" style="color: var(--danger, #ef4444);">
                                            🗑️
                                        </button>
                                    </div>
                                </div>

                                <!-- Adress- & Reisezeit-Zeile über die volle Breite -->
                                <div style="margin-bottom: 0.85rem;">
                                    <div style="color: var(--text-muted); font-size: 0.85rem; display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap;">
                                        <span>📍</span>
                                        <strong><?= htmlspecialchars($t['start_address']) ?></strong>
                                        <span style="opacity: 0.6;">&rarr;</span>
                                        <strong><?= htmlspecialchars($t['destination_address']) ?></strong>
                                    </div>
                                    <div style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.25rem;">
                                        🗓️ Abfahrt: <strong style="color: var(--text-main);"><?= $depLocal ?></strong><?= $retLocal ? " &bull; Rückkehr: <strong style=\"color: var(--text-main);\">{$retLocal}</strong>" : '' ?>
                                    </div>
                                </div>

                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 0.75rem; background: var(--bg-surface); border: 1px solid var(--bg-surface-hover); padding: 0.85rem 1rem; border-radius: 8px; font-size: 0.85rem;">
                                    <div>
                                        <div style="color: var(--text-muted); font-size: 0.75rem; margin-bottom: 0.2rem; text-transform: uppercase; letter-spacing: 0.04em;">Strecke</div>
                                        <div style="font-weight: 700; font-size: 1rem; color: var(--color-blue);">
                                            <?= $tDist > 0 ? number_format($tDist, 1, ',', '.') . ' <span style="font-size:0.75rem; font-weight:normal;">km</span>' : '<span style="color:var(--text-muted); font-weight:normal;">– km</span>' ?>
                                        </div>
                                        <?php if (!empty($t['actual_distance_km'])): ?>
                                            <div style="font-size: 0.7rem; color: var(--color-green); margin-top: 0.15rem;">
                                                Ist: <?= number_format((float)$t['actual_distance_km'], 1, ',', '.') ?> km
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div style="color: var(--text-muted); font-size: 0.75rem; margin-bottom: 0.2rem; text-transform: uppercase; letter-spacing: 0.04em;">Bedarf</div>
                                        <div style="font-weight: 700; font-size: 1rem; color: var(--text-main);">
                                            <?= (float)$t['estimated_consumption_kwh'] > 0 ? number_format((float)$t['estimated_consumption_kwh'], 1, ',', '.') . ' <span style="font-size:0.75rem; font-weight:normal;">kWh</span>' : '<span style="color:var(--text-muted); font-weight:normal;">– kWh</span>' ?>
                                        </div>
                                        <?php if (!empty($t['actual_consumption_kwh'])): ?>
                                            <div style="font-size: 0.7rem; color: var(--color-green); margin-top: 0.15rem;">
                                                Ist: <?= number_format((float)$t['actual_consumption_kwh'], 1, ',', '.') ?> kWh
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div style="color: var(--text-muted); font-size: 0.75rem; margin-bottom: 0.2rem; text-transform: uppercase; letter-spacing: 0.04em;">Start-SoC</div>
                                        <div style="font-weight: 700; font-size: 1rem; color: <?= (int)$t['planned_departure_soc'] >= 100 ? 'var(--color-red)' : 'var(--color-green)' ?>;">
                                            <?= htmlspecialchars($t['planned_departure_soc']) ?> <span style="font-size:0.75rem; font-weight:normal;">%</span>
                                        </div>
                                        <?php if (!empty($t['can_drive_without_charging']) && (int)($t['min_departure_soc'] ?? 0) < (int)$t['planned_departure_soc']): ?>
                                            <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.15rem;" title="Mindest-Akkustand um die Fahrt ohne Ladestopp zu schaffen">
                                                Min: <strong style="color: <?= ($currentVehicleSoc !== null && $currentVehicleSoc >= (int)$t['min_departure_soc']) ? 'var(--color-green)' : 'var(--text-main)' ?>;"><?= (int)$t['min_departure_soc'] ?>%</strong>
                                                <?php if ($currentVehicleSoc !== null): ?>
                                                    <?php if ($currentVehicleSoc >= (int)$t['min_departure_soc']): ?>
                                                        <span style="color: var(--color-green); font-size: 0.75rem; font-weight: bold;" title="Aktueller Akkustand (<?= $currentVehicleSoc ?>%) reicht ohne Nachladen!">✓</span>
                                                    <?php else: ?>
                                                        <span style="color: var(--color-orange); font-size: 0.75rem;" title="Aktuell <?= $currentVehicleSoc ?>% (noch mind. +<?= ((int)$t['min_departure_soc'] - $currentVehicleSoc) ?>% bis Min-SoC)">⚡</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($t['actual_arrival_soc'] !== null): ?>
                                            <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.15rem;">
                                                Ziel-SoC: <strong style="color:var(--text-main);"><?= (int)$t['actual_arrival_soc'] ?>%</strong>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div style="color: var(--text-muted); font-size: 0.75rem; margin-bottom: 0.2rem; text-transform: uppercase; letter-spacing: 0.04em;">Unterwegs</div>
                                        <div style="font-weight: 700; font-size: 1rem; color: var(--color-orange);">
                                            <?= number_format((float)$t['en_route_charge_kwh'], 1, ',', '.') ?> <span style="font-size:0.75rem; font-weight:normal;">kWh</span>
                                        </div>
                                    </div>
                                    <div>
                                        <div style="color: var(--text-muted); font-size: 0.75rem; margin-bottom: 0.2rem; text-transform: uppercase; letter-spacing: 0.04em;">Kostenbilanz</div>
                                        <div style="font-weight: 700; font-size: 1rem; color: var(--color-green);">
                                            <?= number_format($tCost, 2, ',', '.') ?> €
                                        </div>
                                        <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.1rem;"><?= number_format($cPer100, 2, ',', '.') ?> € / 100 km</div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalTripPages > 1): ?>
                        <div class="pagination u-mt-lg">
                            <?php if ($tripPage > 1): ?>
                                <a href="?tab=trips&page=<?= $tripPage - 1 ?>" class="btn btn-outline btn-sm">◀ Zurück</a>
                            <?php endif; ?>

                            <span class="pagination-gap">
                                Seite <?= $tripPage ?> von <?= $totalTripPages ?> (<?= $totalTrips ?> Reisen)
                            </span>

                            <?php if ($tripPage < $totalTripPages): ?>
                                <a href="?tab=trips&page=<?= $tripPage + 1 ?>" class="btn btn-outline btn-sm">Weiter ▶</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- MODAL 1: REISE-DETAILS & BUCHUNGS-ZUORDNUNG -->
            <div id="trip-detail-modal" class="modal-overlay app-modal hidden" style="display: none;">
                <div class="modal-card modal-card--lg" style="max-height: 90vh; overflow-y: auto;">
                    <div class="modal-header">
                        <h3>🔍 Reisedetails &amp; Abrechnungsabgleich</h3>
                        <button type="button" class="modal-close js-btn-close-modal" aria-label="Schließen">&times;</button>
                    </div>
                    <div class="modal-body" id="trip-detail-content">
                        <!-- Wird dynamisch über car_trips.js befüllt -->
                    </div>
                    <div class="modal-footer" style="display: flex; justify-content: flex-end;">
                        <button type="button" class="btn btn-outline js-btn-close-modal">Schließen</button>
                    </div>
                </div>
            </div>

            <!-- MODAL 2: NEUE REISE PLANEN -->
            <div id="trip-edit-modal" class="modal-overlay app-modal hidden" style="display: none;">
                <div class="modal-card" style="max-width: 550px;">
                    <div class="modal-header">
                        <h3 id="trip-modal-title">➕ Neue Reise planen</h3>
                        <button type="button" class="modal-close js-btn-close-modal" aria-label="Schließen">&times;</button>
                    </div>
                    <form id="trip-edit-form">
                        <input type="hidden" name="trip_id" value="">
                        <div class="modal-body" style="gap: 1rem;">
                            <div>
                                <label for="field-title" class="form-label">Titel / Anlass *</label>
                                <input type="text" id="field-title" name="title" class="form-control" required placeholder="z. B. Ostseeurlaub Rügen" style="width: 100%;">
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                                <div>
                                    <label for="field-start" class="form-label">Startort</label>
                                    <input type="text" id="field-start" name="start_address" class="form-control" value="Zuhause" style="width: 100%;">
                                </div>
                                <div>
                                    <label for="field-dest" class="form-label">Zielort / Adresse *</label>
                                    <input type="text" id="field-dest" name="destination_address" class="form-control" required placeholder="z. B. Binz, Rügen" style="width: 100%;">
                                </div>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                                <div>
                                    <label for="field-dep" class="form-label">Abfahrtszeitpunkt *</label>
                                    <input type="datetime-local" id="field-dep" name="departure_time" class="form-control" required style="width: 100%;">
                                </div>
                                <div>
                                    <label for="field-ret" class="form-label">Rückkehr (optional)</label>
                                    <input type="datetime-local" id="field-ret" name="return_time" class="form-control" style="width: 100%;">
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                                <input type="checkbox" id="field-roundtrip" name="is_round_trip" value="1">
                                <label for="field-roundtrip" style="margin: 0; cursor: pointer; font-size: 0.9rem;">
                                    🔄 Als Rundreise planen (Heim &rarr; Ziel &rarr; Heim)
                                </label>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; border-top: 1px solid var(--border-color, #e2e8f0); padding-top: 0.75rem;">
                                <div>
                                    <label for="field-target-soc" class="form-label">Ziel-SoC am Ort (%)</label>
                                    <input type="number" id="field-target-soc" name="target_arrival_soc" class="form-control" value="10" min="5" max="50" style="width: 100%;">
                                </div>
                                <div>
                                    <label for="field-dep-soc" class="form-label">Geplanter Start-SoC (%)</label>
                                    <select id="field-dep-soc" name="planned_departure_soc" class="form-control" style="width: 100%;">
                                        <option value="80" selected>80 % (Alltags-Ladestand / Akkuschonung)</option>
                                        <option value="90">90 % (Erweiterter Radius)</option>
                                        <option value="100">100 % (Vollgeladen für Langstrecke)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                            <button type="button" class="btn btn-outline js-btn-close-modal">Abbrechen</button>
                            <button type="submit" class="btn btn-primary">💾 Route berechnen &amp; planen</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- MODAL 3: AUSFLUG IM URLAUB PLANEN -->
            <div id="subtrip-modal" class="modal-overlay app-modal hidden" style="display: none;">
                <div class="modal-card" style="max-width: 500px;">
                    <div class="modal-header">
                        <h3>🏖️ Ausflug während des Aufenthalts</h3>
                        <button type="button" class="modal-close js-btn-close-modal" aria-label="Schließen">&times;</button>
                    </div>
                    <form id="subtrip-form">
                        <input type="hidden" name="parent_trip_id" value="">
                        <div class="modal-body" style="gap: 1rem;">
                            <div>
                                <label for="field-sub-title" class="form-label">Titel des Ausflugs *</label>
                                <input type="text" id="field-sub-title" name="title" class="form-control" required placeholder="z. B. Fahrt zum Kap Arkona" style="width: 100%;">
                            </div>
                            <div>
                                <label for="field-sub-start" class="form-label">Startort (Unterkunft)</label>
                                <input type="text" id="field-sub-start" name="start_address" class="form-control" readonly style="width: 100%; background: var(--bg-subtle, #f8fafc);">
                            </div>
                            <div>
                                <label for="field-sub-dest" class="form-label">Ausflugsziel *</label>
                                <input type="text" id="field-sub-dest" name="destination_address" class="form-control" required placeholder="z. B. Kap Arkona Parkplatz" style="width: 100%;">
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                                <div>
                                    <label for="field-sub-dep" class="form-label">Abfahrt *</label>
                                    <input type="datetime-local" id="field-sub-dep" name="departure_time" class="form-control" required style="width: 100%;">
                                </div>
                                <div>
                                    <label for="field-sub-ret" class="form-label">Rückkehr</label>
                                    <input type="datetime-local" id="field-sub-ret" name="return_time" class="form-control" style="width: 100%;">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                            <button type="button" class="btn btn-outline js-btn-close-modal">Abbrechen</button>
                            <button type="submit" class="btn btn-primary">💾 Ausflug speichern</button>
                        </div>
                    </form>
                </div>
            </div>

        <?php endif; ?>
    </main>
</div>

<script src="../js/telemetry.js?v=<?= APP_VERSION ?>" defer></script>
<script src="../js/car_trips.js?v=<?= APP_VERSION ?>" defer></script>
<script src="../js/car_charges.js?v=<?= APP_VERSION ?>" defer></script>
<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
</body>
</html>

