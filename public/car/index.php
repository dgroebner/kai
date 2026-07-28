<?php
require_once __DIR__ . '/../../bootstrap.php';

// Auth-Check
if (!isset($_SESSION['user_email'])) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

use Kai\Tools\Shared\Db\Database;

$db = Database::getInstance()->getConnection();

// --- Aktueller Fahrzeugstatus ---
$stateStmt = $db->query("
    SELECT *
    FROM vehicle_state
    ORDER BY updated_at DESC
    LIMIT 1
");
$state = $stateStmt ? $stateStmt->fetch() : null;

// --- Zeitreihe: letzte 24 Stunden (SoC-Verlauf) ---
$historyStmt = $db->prepare("
    SELECT timestamp, soc_percent, range_km, charge_power_kw, outdoor_temp_c
    FROM vehicle_telemetry_log
    WHERE timestamp >= NOW() - INTERVAL 24 HOUR
    ORDER BY timestamp ASC
");
$historyStmt->execute();
$history = $historyStmt->fetchAll();

// --- Zeitreihe: letzte 30 Tage (Kilometerstand für Verbrauchsschätzung) ---
$mileageStmt = $db->prepare("
    SELECT DATE(timestamp) as day, MAX(mileage_km) as max_km, MIN(mileage_km) as min_km
    FROM vehicle_telemetry_log
    WHERE timestamp >= NOW() - INTERVAL 30 DAY
    GROUP BY DATE(timestamp)
    ORDER BY day ASC
");
$mileageStmt->execute();
$mileageHistory = $mileageStmt->fetchAll();

// --- Letzte 10 Log-Einträge für Tabelle ---
$logStmt = $db->prepare("
    SELECT timestamp, soc_percent, range_km, mileage_km, charge_power_kw, outdoor_temp_c
    FROM vehicle_telemetry_log
    ORDER BY timestamp DESC
    LIMIT 10
");
$logStmt->execute();
$recentLog = $logStmt->fetchAll();

// --- Hilfsfunktionen ---
function socColor(int $soc): string {
    if ($soc >= 60) return '#10b981';  // grün
    if ($soc >= 25) return '#f59e0b';  // orange
    return '#ef4444';                   // rot
}

function chargingLabel(string $state): array {
    return match($state) {
        'charging'         => ['icon' => '⚡', 'label' => 'Lädt',     'color' => '#10b981'],
        'conservation'     => ['icon' => '🔋', 'label' => 'Erhaltung', 'color' => '#3b82f6'],
        'readyForCharging' => ['icon' => '🔌', 'label' => 'Bereit',   'color' => '#f59e0b'],
        default            => ['icon' => '💤', 'label' => 'Aus',       'color' => '#64748b'],
    };
}
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
        /* ========================================
           ID.Buzz Dashboard – spezifische Styles
           ======================================== */
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

        /* ---- Header ---- */
        .page-header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .page-header h1 { margin-bottom: 0; }
        .last-update {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        /* ---- Status-Banner (Fahrzeugzustand) ---- */
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
        .status-banner .vin-label {
            font-size: 0.75rem;
            letter-spacing: 0.1em;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .status-banner .vin {
            font-family: monospace;
            font-size: 0.95rem;
            color: var(--car-blue);
        }
        .status-divider {
            width: 1px;
            height: 2.5rem;
            background: var(--bg-surface-hover);
        }
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

        /* ---- KPI-Karten ---- */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(165px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .kpi-card {
            background: var(--bg-surface);
            border: 1px solid var(--bg-surface-hover);
            border-radius: var(--border-radius);
            padding: 1.25rem 1.5rem;
            transition: all 0.2s;
        }
        .kpi-card:hover {
            border-color: var(--car-blue);
            box-shadow: 0 0 14px var(--car-blue-glow);
        }
        .kpi-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            margin-bottom: 0.4rem;
        }
        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1.1;
        }
        .kpi-unit {
            font-size: 0.88rem;
            color: var(--text-muted);
            font-weight: 400;
        }

        /* ---- SoC-Balken ---- */
        .soc-bar-wrap {
            margin-top: 0.6rem;
        }
        .soc-bar-bg {
            width: 100%;
            height: 6px;
            background: var(--bg-surface-hover);
            border-radius: 99px;
            overflow: hidden;
        }
        .soc-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width 0.6s ease;
        }
        .soc-target-label {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 0.3rem;
        }

        /* ---- Section Titles ---- */
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--bg-surface-hover);
        }
        .chart-section { margin-bottom: 2.5rem; }

        /* ---- SoC-Verlauf Chart ---- */
        .soc-chart-container {
            position: relative;
            height: 200px;
            background: var(--bg-surface);
            border: 1px solid var(--bg-surface-hover);
            border-radius: var(--border-radius);
            padding: 1rem;
            overflow: hidden;
        }
        .soc-chart-svg {
            width: 100%;
            height: 100%;
        }

        /* ---- Log-Tabelle ---- */
        .log-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
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
        .log-table td {
            padding: 0.7rem 0.8rem;
            border-bottom: 1px solid var(--bg-surface-hover);
            vertical-align: middle;
            white-space: nowrap;
        }
        .log-table tr:last-child td { border-bottom: none; }
        .log-table tr:hover td { background: var(--bg-surface-hover); }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.2rem 0.6rem;
            border-radius: 99px;
            font-size: 0.72rem;
            font-weight: 600;
        }

        /* ---- Kilometerstand / Tagesfahrleistung ---- */
        .km-bar-wrap { display: flex; align-items: center; gap: 0.6rem; margin-top: 0.4rem; }
        .km-bar-bg {
            flex: 1;
            height: 6px;
            background: var(--bg-surface-hover);
            border-radius: 99px;
            overflow: hidden;
        }
        .km-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--car-blue), var(--car-green));
            border-radius: 99px;
        }

        /* ---- No-Data ---- */
        .no-data {
            padding: 2.5rem;
            text-align: center;
            color: var(--text-muted);
            background: var(--bg-surface);
            border-radius: var(--border-radius);
            border: 1px dashed var(--bg-surface-hover);
        }

        @media (max-width: 600px) {
            .status-divider { display: none; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .log-table th:nth-child(n+4),
            .log-table td:nth-child(n+4) { display: none; }
        }
    </style>
</head>
<body>
<div class="container">

    <header>
        <div class="page-header">
            <h1>🚐 VW ID.Buzz</h1>
            <?php if ($state): ?>
                <span class="last-update">Aktualisiert: <?= date('d.m.Y H:i', strtotime($state['updated_at'])) ?> Uhr</span>
            <?php endif; ?>
        </div>
        <div class="subtitle">
            Fahrzeug-Telemetrie &amp; Batterie-Dashboard &nbsp;·&nbsp;
            <a href="../index.php" style="color:var(--accent);text-decoration:none;">← Dashboard</a>
        </div>
    </header>

    <main>

    <?php if (!$state): ?>
        <div class="no-data">
            Noch keine Telemetriedaten vorhanden.<br>
            <small>Der erste Datenpunkt erscheint nach dem ersten erfolgreichen API-Call an <code>/car/telemetry</code>.</small>
        </div>
    <?php else:
        $charging = chargingLabel($state['charging_state']);
        $socCol   = socColor((int)$state['soc_percent']);
    ?>

        <!-- Status-Banner -->
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

        <!-- KPI-Karten -->
        <div class="kpi-grid">

            <!-- SoC -->
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

            <!-- Reichweite -->
            <div class="kpi-card">
                <div class="kpi-label">Reichweite</div>
                <div class="kpi-value" style="color:var(--car-blue)">
                    <?= number_format($state['range_km'], 0, ',', '.') ?><span class="kpi-unit"> km</span>
                </div>
            </div>

            <!-- Kilometerstand -->
            <div class="kpi-card">
                <div class="kpi-label">Kilometerstand</div>
                <div class="kpi-value" style="color:var(--text-main); font-size:1.5rem">
                    <?= number_format($state['mileage_km'], 0, ',', '.') ?><span class="kpi-unit"> km</span>
                </div>
            </div>

            <!-- Ladeenergie -->
            <div class="kpi-card">
                <div class="kpi-label">Ladeleistung</div>
                <div class="kpi-value" style="color:<?= $state['charge_power_kw'] > 0 ? 'var(--car-green)' : 'var(--text-muted)' ?>">
                    <?= number_format($state['charge_power_kw'], 1, ',', '.') ?><span class="kpi-unit"> kW</span>
                </div>
            </div>

            <!-- Außentemperatur -->
            <div class="kpi-card">
                <div class="kpi-label">Außentemperatur</div>
                <div class="kpi-value" style="color:var(--car-orange)">
                    <?= number_format($state['outdoor_temp_c'], 1, ',', '.') ?><span class="kpi-unit"> °C</span>
                </div>
            </div>

            <!-- Batterietemperatur -->
            <div class="kpi-card">
                <div class="kpi-label">Batterie Temp.</div>
                <div class="kpi-value" style="color:var(--text-main); font-size:1.4rem">
                    <?= number_format($state['battery_temp_min'], 1, ',', '.') ?> – <?= number_format($state['battery_temp_max'], 1, ',', '.') ?>
                    <span class="kpi-unit">°C</span>
                </div>
            </div>

        </div>

        <!-- SoC-Verlauf (letzte 24h) -->
        <div class="chart-section">
            <div class="section-title">SoC-Verlauf – letzte 24 Stunden</div>
            <?php if (!empty($history)): ?>
                <div class="soc-chart-container">
                    <?php
                    // SVG-Polyline aus den Datenpunkten berechnen
                    $count = count($history);
                    $points = [];
                    $svgW = 1000; $svgH = 160; $padT = 10; $padB = 20;
                    foreach ($history as $i => $row) {
                        $x = ($count > 1) ? round($i / ($count - 1) * $svgW, 1) : $svgW / 2;
                        $y = round($padT + ($svgH - $padT - $padB) * (1 - $row['soc_percent'] / 100), 1);
                        $points[] = "{$x},{$y}";
                    }
                    $polyline = implode(' ', $points);
                    // Erstes/Letztes Label
                    $firstTs = date('H:i', strtotime($history[0]['timestamp']));
                    $lastTs  = date('H:i', strtotime($history[$count - 1]['timestamp']));
                    ?>
                    <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" class="soc-chart-svg" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="socGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.35"/>
                                <stop offset="100%" stop-color="#3b82f6" stop-opacity="0"/>
                            </linearGradient>
                        </defs>
                        <!-- Füllfläche unter der Linie -->
                        <polygon
                            points="0,<?= $svgH - $padB ?> <?= $polyline ?> <?= $svgW ?>,<?= $svgH - $padB ?>"
                            fill="url(#socGrad)"/>
                        <!-- Linie -->
                        <polyline
                            points="<?= $polyline ?>"
                            fill="none"
                            stroke="#3b82f6"
                            stroke-width="2.5"
                            stroke-linejoin="round"
                            stroke-linecap="round"/>
                    </svg>
                    <!-- Zeitbeschriftung -->
                    <div style="display:flex;justify-content:space-between;margin-top:-0.5rem;font-size:0.7rem;color:var(--text-muted);">
                        <span><?= $firstTs ?></span>
                        <span><?= $lastTs ?></span>
                    </div>
                </div>
            <?php else: ?>
                <div class="no-data">Noch keine Verlaufsdaten für die letzten 24 Stunden vorhanden.</div>
            <?php endif; ?>
        </div>

        <!-- Letzte Einträge -->
        <div class="chart-section">
            <div class="section-title">Letzte Telemetrie-Einträge</div>
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
                            <td style="color:var(--text-muted)"><?= date('d.m.Y H:i', strtotime($row['timestamp'])) ?></td>
                            <td>
                                <span class="badge" style="background:<?= $sc ?>22; color:<?= $sc ?>">
                                    <?= $row['soc_percent'] ?> %
                                </span>
                            </td>
                            <td><?= number_format($row['range_km'], 0, ',', '.') ?> km</td>
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
            <?php else: ?>
                <div class="no-data">Noch keine Log-Einträge vorhanden.</div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    </main>

    <footer style="margin-top: 2.5rem;">
        <a href="../index.php" class="btn btn-outline">← Zurück zum Dashboard</a>
    </footer>

</div>
</body>
</html>
