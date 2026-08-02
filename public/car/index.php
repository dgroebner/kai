<?php
require_once __DIR__ . '/../../bootstrap.php';

// Auth-Check
if (!isset($_SESSION['user_email'])) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

use Kai\Tools\Shared\Db\Database;

$db = Database::getInstance()->getConnection();

// ----------------------------------------------------
// Hilfsfunktionen (inkl. Zeitzonenkonvertierung)
// ----------------------------------------------------

/**
 * Wandelt einen UTC-Zeitstempel für JEDEN Datenpunkt individuell 
 * unter Berücksichtigung der am Erfassungstag gültigen Sommer-/Winterzeit in deutsche Ortszeit um.
 */
function formatToLocalTime(?string $utcTimeString, string $format = 'd.m.Y H:i'): string {
    if (!$utcTimeString) {
        return '–';
    }
    try {
        $dt = new DateTime($utcTimeString, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        return $dt->format($format);
    } catch (\Exception $e) {
        return $utcTimeString;
    }
}

function socColor(int $soc): string {
    if ($soc >= 60) return '#10b981';
    if ($soc >= 25) return '#f59e0b';
    return '#ef4444';
}

function chargingLabel(string $state): array {
    return match($state) {
        'charging'         => ['icon' => '⚡', 'label' => 'Lädt',     'color' => '#10b981'],
        'conservation'     => ['icon' => '🔋', 'label' => 'Erhaltung', 'color' => '#3b82f6'],
        'readyForCharging' => ['icon' => '🔌', 'label' => 'Bereit',   'color' => '#f59e0b'],
        default            => ['icon' => '💤', 'label' => 'Aus',       'color' => '#64748b'],
    };
}

// ----------------------------------------------------
// 1. Live-Fahrzeugstatus (Unabhängig vom Zeitraum)
// ----------------------------------------------------
$stateStmt = $db->query("
    SELECT *
    FROM vehicle_state
    ORDER BY updated_at DESC
    LIMIT 1
");
$state = $stateStmt ? $stateStmt->fetch() : null;

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
$historyStmt = $db->prepare("
    SELECT car_captured_at, soc_percent, range_km, charge_power_kw, outdoor_temp_c
    FROM vehicle_telemetry_log
    WHERE car_captured_at BETWEEN :start AND :end
    ORDER BY car_captured_at ASC
");
$historyStmt->execute([
    ':start' => $startDateUtc,
    ':end'   => $endDateUtc
]);
$history = $historyStmt->fetchAll();

// Paginierung der Telemetrielog-Tabelle
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM vehicle_telemetry_log 
    WHERE car_captured_at BETWEEN :start AND :end
");
$countStmt->execute([':start' => $startDateUtc, ':end' => $endDateUtc]);
$totalEntries = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalEntries / $perPage));

$logStmt = $db->prepare("
    SELECT car_captured_at, soc_percent, range_km, mileage_km, charge_power_kw, outdoor_temp_c
    FROM vehicle_telemetry_log
    WHERE car_captured_at BETWEEN :start AND :end
    ORDER BY car_captured_at DESC
    LIMIT :limit OFFSET :offset
");
$logStmt->bindValue(':start', $startDateUtc);
$logStmt->bindValue(':end', $endDateUtc);
$logStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$logStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$logStmt->execute();
$recentLog = $logStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VW ID.Buzz – Fahrzeug-Dashboard · Kai</title>
    <meta name="description" content="Live-Übersicht des Fahrzeugstatus, Batterieladestand und Telemetrie-Historie des VW ID.Buzz.">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        :root {
            --car-blue:       #3b82f6;
            --car-blue-dim:   rgba(59, 130, 246, 0.15);
            --car-blue-glow:  rgba(59, 130, 246, 0.4);
            --car-green:      #10b981;
            --car-green-dim:  rgba(16, 185, 129, 0.15);
            --car-orange:     #f59e0b;
            --car-orange-dim: rgba(245, 158, 11, 0.15);
            --car-red:        #ef4444;
            --car-red-dim:    rgba(239, 68, 68, 0.15);
        }

        .page-header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .page-header h1 { margin-bottom: 0; }
        .last-update { font-size: 0.8rem; color: var(--text-muted); }

        /* Status-Banner */
        .status-banner {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            background: var(--bg-surface);
            border: 1px solid var(--bg-surface-hover);
            border-radius: var(--border-radius);
            padding: 1.25rem 1.5rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        .status-banner .vin-label { font-size: 0.75rem; letter-spacing: 0.1em; color: var(--text-muted); text-transform: uppercase; }
        .status-banner .vin { font-family: monospace; font-size: 0.95rem; color: var(--car-blue); }
        .status-divider { width: 1px; height: 2.5rem; background: var(--bg-surface-hover); }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.85rem;
            border-radius: 99px;
            font-size: 0.82rem;
            font-weight: 600;
            background: var(--bg-surface-hover);
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(165px, 1fr));
            gap: 1rem;
            margin-bottom: 2.5rem;
        }
        .kpi-card {
            background: var(--bg-surface);
            border: 1px solid var(--bg-surface-hover);
            border-radius: var(--border-radius);
            padding: 1.25rem 1.5rem;
            transition: all 0.2s;
        }
        .kpi-card:hover { border-color: var(--car-blue); box-shadow: 0 0 14px var(--car-blue-glow); }
        .kpi-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-muted); margin-bottom: 0.4rem; }
        .kpi-value { font-size: 2rem; font-weight: 700; line-height: 1.1; }
        .kpi-unit { font-size: 0.88rem; color: var(--text-muted); font-weight: 400; }

        .soc-bar-wrap { margin-top: 0.6rem; }
        .soc-bar-bg { width: 100%; height: 6px; background: var(--bg-surface-hover); border-radius: 99px; overflow: hidden; }
        .soc-bar-fill { height: 100%; border-radius: 99px; transition: width 0.6s ease; }
        .soc-target-label { font-size: 0.7rem; color: var(--text-muted); margin-top: 0.3rem; }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--bg-surface-hover);
        }
        .chart-section { margin-bottom: 2.5rem; }

        /* Period Switcher & Nav */
        .period-switcher {
            display: flex;
            gap: 10px;
            margin-bottom: 1.25rem;
            justify-content: center;
        }
        .period-switcher .btn { min-width: 100px; text-align: center; }

        .period-navigation {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-surface);
            padding: 0.8rem 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            border: 1px solid var(--bg-surface-hover);
            gap: 15px;
        }
        .current-period-label {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-main);
            text-align: center;
        }
        .period-range-sub {
            display: block;
            font-size: 0.8em;
            color: var(--text-muted);
            font-weight: normal;
            margin-top: 2px;
        }

		/* SoC-Chart & Tooltip Styling */
		.soc-chart-container {
			position: relative;
			height: 220px;
			background: var(--bg-surface);
			border: 1px solid var(--bg-surface-hover);
			border-radius: var(--border-radius);
			padding: 1rem;
			overflow: visible;
		}
		.soc-chart-svg { 
			width: 100%; 
			height: 100%; 
			overflow: visible; 
		}

		/* Verhindert, dass Linien/Flächen das Mouseover der Datenpunkte blockieren */
		.soc-chart-svg polygon,
		.soc-chart-svg polyline {
			pointer-events: none;
		}

		.chart-point {
			cursor: pointer;
			pointer-events: all;
			transition: r 0.15s ease, fill 0.15s ease;
		}

		.chart-point:hover {
			r: 6 !important;
			fill: #ffffff !important;
			stroke-width: 3;
		}

		/* Globales Tooltip Popup */
		.chart-tooltip {
			position: fixed;
			display: none;
			pointer-events: none;
			background: rgba(15, 23, 42, 0.95);
			border: 1px solid var(--car-blue);
			color: #fff;
			padding: 6px 10px;
			border-radius: 6px;
			font-size: 0.75rem;
			box-shadow: 0 4px 12px rgba(0,0,0,0.5);
			z-index: 9999;
			white-space: nowrap;
			transform: translate(-50%, -100%);
		}

        /* Log-Tabelle & Paginierung */
        .log-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .log-table th {
            text-align: left;
            padding: 0.6rem 0.8rem;
            color: var(--text-muted);
            font-weight: 500;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-bottom: 1px solid var(--bg-surface-hover);
            white-space: nowrap;
        }
        .log-table td { padding: 0.7rem 0.8rem; border-bottom: 1px solid var(--bg-surface-hover); vertical-align: middle; white-space: nowrap; }
        .log-table tr:hover td { background: var(--bg-surface-hover); }

        .badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.6rem; border-radius: 99px; font-size: 0.72rem; font-weight: 600; }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }
        .pagination .btn-sm { padding: 0.3rem 0.7rem; font-size: 0.85rem; }

        .no-data {
            padding: 2.5rem;
            text-align: center;
            color: var(--text-muted);
            background: var(--bg-surface);
            border-radius: var(--border-radius);
            border: 1px dashed var(--bg-surface-hover);
        }

        @media (max-width: 650px) {
            .period-navigation { flex-direction: column; align-items: stretch; text-align: center; gap: 10px; }
            .period-navigation .btn { width: 100%; }
            .status-divider { display: none; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .log-table th:nth-child(n+4), .log-table td:nth-child(n+4) { display: none; }
        }
    </style>
</head>
<body>
<div class="container">

    <header>
        <div class="page-header">
            <h1>🚐 VW ID.Buzz</h1>
            <?php if ($state): ?>
                <span class="last-update">Fahrzeugdaten von: <?= formatToLocalTime($state['car_captured_at']) ?> Uhr</span>
            <?php endif; ?>
        </div>
        <div class="subtitle">
            Fahrzeug-Telemetrie &amp; Batterie-Dashboard &nbsp;·&nbsp;
            <a href="../index.php" style="color:var(--accent);text-decoration:none;">← Hauptübersicht</a>
        </div>
    </header>

    <main style="margin-top: 1.5rem;">

    <?php if (!$state): ?>
        <div class="no-data">
            Noch keine Telemetriedaten vorhanden.<br>
            <small>Der erste Datenpunkt erscheint nach dem ersten erfolgreichen API-Call an <code>/car/telemetry</code>.</small>
        </div>
    <?php else:
        $charging = chargingLabel($state['charging_state']);
        $socCol   = socColor((int)$state['soc_percent']);
    ?>

        <!-- 1. LIVE IST-DATEN (Zeitraum-unabhängig) -->
        <div class="status-banner">
            <div>
                <div class="vin-label">VIN</div>
                <div class="vin"><?= htmlspecialchars($state['vin']) ?></div>
            </div>
            <div class="status-divider"></div>
            <div>
                <span class="status-pill" style="background:<?= $charging['color'] ?>22; color:<?= $charging['color'] ?>">
                    <?= $charging['icon'] ?> <?= $charging['label'] ?>
                </span>
            </div>
            <div>
                <span class="status-pill" style="<?= $state['is_locked'] ? 'background:var(--car-green-dim);color:var(--car-green)' : 'background:var(--car-red-dim);color:var(--car-red)' ?>">
                    <?= $state['is_locked'] ? '🔒 Gesperrt' : '🔓 Offen' ?>
                </span>
            </div>
            <div>
                <span class="status-pill" style="<?= $state['plug_connected'] ? 'background:var(--car-blue-dim);color:var(--car-blue)' : '' ?>">
                    <?= $state['plug_connected'] ? '🔌 Stecker verbunden' : '⭕ Kein Stecker' ?>
                </span>
            </div>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">Ladestand</div>
                <div class="kpi-value" style="color: <?= $socCol ?>">
                    <?= $state['soc_percent'] ?><span class="kpi-unit"> %</span>
                </div>
                <div class="soc-bar-wrap">
                    <div class="soc-bar-bg">
                        <div class="soc-bar-fill" style="width:<?= $state['soc_percent'] ?>%; background:<?= $socCol ?>"></div>
                    </div>
                    <div class="soc-target-label">Ziel: <?= $state['target_soc'] ?> %</div>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-label">Reichweite</div>
                <div class="kpi-value" style="color:var(--car-blue)">
                    <?= number_format($state['range_km'], 0, ',', '.') ?><span class="kpi-unit"> km</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-label">Kilometerstand</div>
                <div class="kpi-value" style="color:var(--text-main); font-size:1.5rem">
                    <?= number_format($state['mileage_km'], 0, ',', '.') ?><span class="kpi-unit"> km</span>
                </div>
            </div>

			<div class="kpi-card">
				<div class="kpi-label">Ladeleistung</div>
				<div class="kpi-value" style="color:<?= $state['charge_power_kw'] > 0 ? 'var(--car-green)' : 'var(--text-muted)' ?>">
					<?= number_format($state['charge_power_kw'], 1, ',', '.') ?><span class="kpi-unit"> kW</span>
				</div>
				
				<?php if ($state['charge_power_kw'] > 0 && !empty($state['estimated_finish_at'])): ?>
					<div style="font-size: 0.75rem; color: var(--car-green); margin-top: 0.4rem; font-weight: 600;">
						⏱️ Fertig ca. <?= formatToLocalTime($state['estimated_finish_at'], 'H:i') ?> Uhr
					</div>
				<?php else: ?>
					<div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.4rem;">
						<?= $state['plug_connected'] ? 'Stecker bereit' : 'Nicht verbunden' ?>
					</div>
				<?php endif; ?>
			</div>
			
			<div class="kpi-card">
				<div class="kpi-label">Effizienz-Index</div>
				<div class="kpi-value" style="color: <?= $effRating['color'] ?>">
					<?= $currentEff ? number_format($currentEff, 1, ',', '.') : '–' ?>
					<span class="kpi-unit">km / %</span>
				</div>
				<div style="font-size: 0.75rem; color: <?= $effRating['color'] ?>; margin-top: 0.4rem; font-weight: 600;">
					<?= $effRating['label'] ?>
				</div>
			</div>

            <div class="kpi-card">
                <div class="kpi-label">Außentemperatur</div>
                <div class="kpi-value" style="color:var(--car-orange)">
                    <?= number_format($state['outdoor_temp_c'], 1, ',', '.') ?><span class="kpi-unit"> °C</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-label">Batterie Temp.</div>
                <div class="kpi-value" style="color:var(--text-main); font-size:1.4rem">
                    <?= number_format($state['battery_temp_min'], 1, ',', '.') ?> – <?= number_format($state['battery_temp_max'], 1, ',', '.') ?>
                    <span class="kpi-unit">°C</span>
                </div>
            </div>
        </div>

        <!-- 2. HISTORIE & ZEITRAUM-AUSWERTUNG -->
        <div class="section-title">📊 Historie &amp; Auswertung</div>

        <!-- Umschalter: Woche / Monat / Jahr -->
        <div class="period-switcher">
            <a href="?type=woche&date=<?= htmlspecialchars($refDate) ?>" class="btn <?= $type === 'woche' ? '' : 'btn-outline' ?>">Woche</a>
            <a href="?type=monat&date=<?= htmlspecialchars($refDate) ?>" class="btn <?= $type === 'monat' ? '' : 'btn-outline' ?>">Monat</a>
            <a href="?type=jahr&date=<?= htmlspecialchars($refDate) ?>" class="btn <?= $type === 'jahr' ? '' : 'btn-outline' ?>">Jahr</a>
        </div>

        <!-- Zeitraum Navigation (Vorherige / Nächste) -->
        <div class="period-navigation">
            <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($prevDate) ?>" class="btn btn-outline">◀ <?= htmlspecialchars($navLabelPrev) ?></a>
            <div class="current-period-label">
                <?= htmlspecialchars($periodLabel) ?>
                <span class="period-range-sub">
                    Auswertungszeitraum: <?= date('d.m.Y', strtotime($startDateLocal)) ?> bis <?= date('d.m.Y', strtotime($endDateLocal)) ?>
                </span>
            </div>
            <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($nextDate) ?>" class="btn btn-outline"><?= htmlspecialchars($navLabelNext) ?> ▶</a>
        </div>

        <!-- 1. CHART: SoC-VERLAUF MIT SKALA & TOOLTIPS -->
        <div class="chart-section">
            <div style="font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.8rem; font-weight: 600;">
                🔋 Ladestand-Verlauf (SoC in %)
            </div>
            <?php if (!empty($history)): ?>
                <div class="soc-chart-container" id="chart1-container">
                    <div class="chart-tooltip" id="tooltip1"></div>
                    <?php
                    $count = count($history);
                    $svgW = 1000; $svgH = 180; $padL = 40; $padR = 20; $padT = 15; $padB = 25;
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
                    $lastTs  = formatToLocalTime($history[$count - 1]['car_captured_at'], $dateFormat);
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
                            <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $svgW - $padR ?>" y2="<?= $gy ?>" stroke="rgba(255,255,255,0.08)" stroke-dasharray="2 2" />
                            <text x="<?= $padL - 8 ?>" y="<?= $gy + 4 ?>" fill="var(--text-muted)" font-size="10" text-anchor="end"><?= $v ?>%</text>
                        <?php endfor; ?>

                        <!-- Fläche & Linie -->
                        <polygon points="<?= $padL ?>,<?= $svgH - $padB ?> <?= $polyline ?> <?= $svgW - $padR ?>,<?= $svgH - $padB ?>" fill="url(#socGrad)"/>
                        <polyline points="<?= $polyline ?>" fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>

                        <!-- Interaktive Mouseover-Punkte -->
                        <?php foreach ($dataNodes as $node): ?>
                            <circle class="chart-point" 
                                    cx="<?= $node['x'] ?>" 
                                    cy="<?= $node['y'] ?>" 
                                    r="3.5" 
                                    fill="#3b82f6" 
                                    stroke="#1e293b" 
                                    stroke-width="1.5"
                                    data-tooltip="<strong><?= $node['time'] ?> Uhr</strong><br>Ladestand: <?= $node['soc'] ?> %<br>Reichweite: <?= $node['range'] ? $node['range'].' km' : '–' ?>" />
                        <?php endforeach; ?>
                    </svg>

                    <div style="display:flex;justify-content:space-between;margin-top:-0.2rem;padding-left:<?= $padL ?>px;padding-right:<?= $padR ?>px;font-size:0.7rem;color:var(--text-muted);">
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
            <div style="font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.8rem; font-weight: 600;">
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
                    $svgW = 1000; $svgH = 180; $padL = 40; $padR = 40; $padT = 15; $padB = 25;
                    $chartW = $svgW - $padL - $padR;
                    $chartH = $svgH - $padT - $padB;

                    $minTemp = min(array_column($effData, 'temp')) - 2;
                    $maxTemp = max(array_column($effData, 'temp')) + 2;
                    $rangeTemp = max(1, $maxTemp - $minTemp);

                    $minEff = 2.0; $maxEff = 6.0; 
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
                            <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $svgW - $padR ?>" y2="<?= $gy ?>" stroke="rgba(255,255,255,0.06)" stroke-dasharray="2 2" />
                            <text x="<?= $padL - 8 ?>" y="<?= $gy + 3 ?>" fill="#10b981" font-size="9" font-weight="600" text-anchor="end"><?= number_format($e, 1) ?></text>
                        <?php endfor; ?>

                        <!-- Right Y-Axis: Temperatur Skala (Orange, Min - Max) -->
                        <text x="<?= $svgW - $padR + 8 ?>" y="<?= $padT + 4 ?>" fill="#f59e0b" font-size="9" font-weight="600"><?= round($maxTemp) ?>°C</text>
                        <text x="<?= $svgW - $padR + 8 ?>" y="<?= $svgH - $padB ?>" fill="#f59e0b" font-size="9" font-weight="600"><?= round($minTemp) ?>°C</text>

                        <!-- Temperatur Linie (Orange gestrichelt) -->
                        <polyline points="<?= implode(' ', $pointsTemp) ?>" fill="none" stroke="#f59e0b" stroke-width="2" stroke-dasharray="4" />
                        <!-- Effizienz Linie (Grün durchgezogen) -->
                        <polyline points="<?= implode(' ', $pointsEff) ?>" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linejoin="round" />

                        <!-- Points -->
                        <?php foreach ($nodesEff as $node): ?>
                            <circle class="chart-point" cx="<?= $node['x'] ?>" cy="<?= $node['yEff'] ?>" r="3.5" fill="#10b981" stroke="#1e293b" stroke-width="1.5"
                                    data-tooltip="<strong><?= $node['time'] ?> Uhr</strong><br>Effizienz: <?= $node['eff'] ?> km / %<br>Temperatur: <?= $node['temp'] ?> °C" />
                        <?php endforeach; ?>
                    </svg>

                    <div style="display:flex; justify-content:space-between; margin-top:-0.2rem; padding-left:<?= $padL ?>px; padding-right:<?= $padR ?>px; font-size:0.75rem; color:var(--text-muted);">
                        <span style="color:#10b981;">🟢 Effizienz-Skala (km / % SoC, Links)</span>
                        <span style="color:#f59e0b;">🟠 Außentemperatur-Skala (°C, Rechts)</span>
                    </div>
                </div>
            <?php else: ?>
                <div class="no-data">Ggf. noch zu wenige Datenpunkte mit erfasster Reichweite für den gewählten Zeitraum.</div>
            <?php endif; ?>
        </div>

        <!-- Telemetrie-Log Tabelle mit Paginierung -->
        <div class="chart-section">
            <strong style="color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.8rem;">
                Telemetrie-Einträge (<?= $totalEntries ?> Einträge im Zeitraum)
            </strong>
            <?php if (!empty($recentLog)): ?>
            <div class="table-responsive">
                <table class="log-table">
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
                            <td style="color:var(--text-muted)"><?= formatToLocalTime($row['car_captured_at']) ?> Uhr</td>
                            <td>
                                <span class="badge" style="background:<?= $sc ?>22; color:<?= $sc ?>">
                                    <?= $row['soc_percent'] ?> %
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 5px;">
                                    <input type="number" 
                                           class="inline-range-input" 
                                           data-vin="<?= htmlspecialchars($state['vin']) ?>" 
                                           data-captured="<?= htmlspecialchars($row['car_captured_at']) ?>" 
                                           value="<?= $row['range_km'] > 0 ? (int)$row['range_km'] : '' ?>" 
                                           placeholder="—" 
                                           style="width: 65px; padding: 2px 6px; font-size: 0.85rem; background: var(--bg-surface-hover); border: 1px solid transparent; color: var(--text-main); border-radius: 4px; text-align: right;">
                                    <span style="font-size: 0.8rem; color: var(--text-muted);">km</span>
                                </div>
                            </td>
                            <td><?= number_format($row['mileage_km'], 0, ',', '.') ?> km</td>
                            <td>
                                <?php if ($row['charge_power_kw'] > 0): ?>
                                    <span class="badge" style="background:var(--car-green-dim);color:var(--car-green)">
                                        ⚡ <?= number_format($row['charge_power_kw'], 1, ',', '.') ?> kW
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted)">–</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($row['outdoor_temp_c'], 1, ',', '.') ?> °C</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginierungs-Steuerung -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>&page=<?= $page - 1 ?>" class="btn btn-outline btn-sm">◀ Zurück</a>
                    <?php endif; ?>

                    <span style="font-size:0.85rem; color:var(--text-muted); margin: 0 8px;">
                        Seite <?= $page ?> von <?= $totalPages ?>
                    </span>

                    <?php if ($page < $totalPages): ?>
                        <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>&page=<?= $page + 1 ?>" class="btn btn-outline btn-sm">Weiter ▶</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php else: ?>
                <div class="no-data">Keine Telemetrie-Einträge im gewählten Zeitraum vorhanden.</div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    </main>

    <footer style="margin-top: 2.5rem;">
        <a href="../index.php" class="btn btn-outline">← Zurück zum Hauptdashboard</a>
    </footer>

</div>

<!-- Inline-Editing Logik für Reichweite -->
<script src="../js/telemetry.js?v=<?= time() ?>"></script>
</body>
</html>