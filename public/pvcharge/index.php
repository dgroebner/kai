<?php
require_once __DIR__ . '/../../bootstrap.php';

// Auth-Check
if (!isset($_SESSION['user_email'])) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;

$logger = new Logger();

// CSRF-Token generieren
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_real_yield') {
    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        die("Ungültiger CSRF-Token.");
    }

    $yieldData = $_POST['real_yield'] ?? [];
    
    $db->beginTransaction();
    try {
        $updateStmt = $db->prepare("
            UPDATE pv_forecast_daily 
            SET real_watt_hours_day = :real_wh 
            WHERE forecast_date = :date
        ");
        
        foreach ($yieldData as $date => $kwhValue) {
            // Wenn das Feld leer gelassen wurde, tragen wir NULL ein
            if ($kwhValue === '') {
                $updateStmt->execute([':real_wh' => null, ':date' => $date]);
                continue;
            }
            
            // Komma durch Punkt ersetzen und in Wattstunden umrechnen
            $kwh = (float)str_replace(',', '.', $kwhValue);
            $wh = (int)round($kwh * 1000);
            
            $updateStmt->execute([
                ':real_wh' => $wh,
                ':date' => $date
            ]);
        }
        $db->commit();
        $successMessage = "Tatsächliche Erträge erfolgreich gespeichert.";
    } catch (Exception $e) {
        $db->rollBack();
        $logger->error("PVCharge index.php: Fehler beim Speichern der echten Erträge.", ['error' => $e->getMessage()]);
        $errorMessage = "Fehler beim Speichern. Bitte versuche es später erneut.";
    }
}

// --- Tagesprognosen (nächste 7 Tage) ---
$dailyStmt = $db->prepare("
    SELECT forecast_date, watt_hours_day, real_watt_hours_day
    FROM pv_forecast_daily
    WHERE forecast_date >= CURDATE() - INTERVAL 3 DAY
    ORDER BY forecast_date ASC
    LIMIT 10
");
$dailyStmt->execute();
$dailyForecasts = $dailyStmt->fetchAll();

// --- Stündliche Prognosen für heute ---
$hourlyStmt = $db->prepare("
    SELECT forecast_time, watts, watt_hours
    FROM pv_forecast_hourly
    WHERE DATE(forecast_time) = CURDATE()
    ORDER BY forecast_time ASC
");
$hourlyStmt->execute();
$hourlyForecasts = $hourlyStmt->fetchAll();

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
function getWeatherLabel(float $kwh): array {
    if ($kwh > 16) return ['icon' => '☀️', 'label' => 'Sehr sonnig'];
    if ($kwh > 10) return ['icon' => '🌤️', 'label' => 'Heiter'];
    if ($kwh > 5)  return ['icon' => '⛅', 'label' => 'Bewölkt'];
    return ['icon' => '🌧️', 'label' => 'Stark bewölkt'];
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
// Faktor berechnen (Fallback auf 1.0, falls noch keine echten Daten vorliegen)
$biasFactor = ($systemBias !== null) ? (1 + ($systemBias / 100)) : 1.0;

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PV-Solarprognose – Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="container">

	<header>
		<div class="page-header">
			<h1>☀️ PV-Solarprognose</h1>
			<div style="display: flex; align-items: center; gap: 1rem;">
				<?php if ($lastUpdate): ?>
					<span class="last-update">Zuletzt aktualisiert: <?= date('d.m.Y H:i', strtotime($lastUpdate)) ?> Uhr</span>
				<?php endif; ?>
				<a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
			</div>
		</div>
		<div class="subtitle">
			Ertragsprognose für die 4,2-kWp-Anlage am Standort 51.30°N / 12.45°O
		</div>
	</header>

    <main>

        <!-- KPI-Karten -->
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
                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.2rem;">
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
                <div class="kpi-value" style="font-size:1.4rem;">
                    <?= $wx['icon'] ?> <span class="kpi-unit"><?= $wx['label'] ?></span>
                </div>
            </div>
			<div class="kpi-card">
                <div class="kpi-label">Systemabweichung</div>
                <div class="kpi-value" style="color: <?= $systemBias >= 0 ? 'var(--pv-green)' : '#e74c3c' ?>;">
                    <?= $systemBias !== null ? ($systemBias > 0 ? '+' : '') . number_format($systemBias, 1, ',', '.') : '–' ?>
                    <span class="kpi-unit">%</span>
                </div>
            </div>
        </div>

        <!-- Tages-Leistungsverlauf (Stundenwerte) -->
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
		
        <div class="chart-section">
			<div class="section-title">Mehrtages-Prognose & Erfassung</div>
			
			<?php if (isset($successMessage)): ?>
				<div class="alert alert-success" style="color: var(--pv-green); margin-bottom: 1rem; font-weight: bold; background: var(--pv-green-dim); padding: 0.75rem; border-radius: var(--border-radius); border: 1px solid var(--pv-green);"><?= $successMessage ?></div>
			<?php endif; ?>
			<?php if (isset($errorMessage)): ?>
				<div class="alert alert-danger" style="color: #e74c3c; margin-bottom: 1rem; font-weight: bold; background: rgba(231, 76, 60, 0.15); padding: 0.75rem; border-radius: var(--border-radius); border: 1px solid #e74c3c;"><?= $errorMessage ?></div>
			<?php endif; ?>

			<?php if (!empty($dailyForecasts)):
				$maxDayWh = max(array_column($dailyForecasts, 'watt_hours_day'));
				if ($maxDayWh < 1) $maxDayWh = 1;
			?>
				<form action="" method="POST">
					<input type="hidden" name="action" value="save_real_yield">
					<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
					
					<div class="table-responsive">
						<table class="forecast-table stack-table">
							<thead>
								<tr>
									<th>Datum</th>
									<th>Wetter</th>
									<th colspan="2">Prognose</th>
									<th style="color: var(--pv-yellow); text-align: right; padding-right: 1.5rem;">Tatsächlich</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($dailyForecasts as $day):
									$kwh = round($day['watt_hours_day'] / 1000, 2);
									$isToday = ($day['forecast_date'] === date('Y-m-d'));
									$barPct  = round($day['watt_hours_day'] / $maxDayWh * 100);
									$wx      = getWeatherLabel($kwh);
									$dateObj = DateTime::createFromFormat('Y-m-d', $day['forecast_date']);
									$weekday = ['So','Mo','Di','Mi','Do','Fr','Sa'][(int)$dateObj->format('w')];

									// Realen Wert aus DB in kWh für das Input-Feld umrechnen
									$realKwh = '';
									if (isset($day['real_watt_hours_day']) && $day['real_watt_hours_day'] !== null) {
										$realKwh = number_format($day['real_watt_hours_day'] / 1000, 2, ',', '');
									}
								?>
								<tr>
									<td data-label="Datum">
										<strong><?= $weekday ?>, <?= $dateObj->format('d.m.') ?></strong>
										<?php if ($isToday): ?>
											<span class="today-badge">HEUTE</span>
										<?php endif; ?>
									</td>
									<td data-label="Wetter"><?= $wx['icon'] ?> <?= $wx['label'] ?></td>
									<td data-label="Prognose" class="yield-value" style="white-space: nowrap; text-align: right;">
										<?php 
										$correctedKwh = $kwh * $biasFactor; 
										?>
										<strong><?= number_format($correctedKwh, 2, ',', '.') ?> kWh</strong>
										<?php if ($systemBias !== null): ?>
											<span style="font-size: 0.78rem; color: var(--text-muted); font-weight: 400; margin-left: 0.4rem;">
												(<?= number_format($kwh, 2, ',', '.') ?>)
											</span>
										<?php endif; ?>
									</td>
									<td data-label="Tatsächlich" style="text-align: right; padding-right: 1rem;">
										<input type="text" 
											   name="real_yield[<?= $day['forecast_date'] ?>]" 
											   value="<?= $realKwh ?>" 
											   placeholder="-,--" 
											   style="width: 70px; text-align: right; padding: 0.3rem; border-radius: 4px; border: 1px solid var(--bg-surface-hover); background: var(--bg-surface); color: var(--text-main); font-family: monospace; font-size: 0.9rem;"
											   <?= ($day['forecast_date'] > date('Y-m-d')) ? 'disabled' : '' ?> 
										> <small style="color: var(--text-muted);">kWh</small>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<div style="text-align: right; margin-top: 1.5rem; padding-bottom: 0.5rem;">
						<button type="submit" class="btn" style="background: var(--pv-yellow); color: #000; font-weight: bold; padding: 0.6rem 1.5rem; border: none; border-radius: var(--border-radius); cursor: pointer;">
							💾 Erträge speichern
						</button>
					</div>
				</form>
			<?php else: ?>
				<div class="no-data">
					Noch keine Prognosedaten in der Datenbank.<br>
					<small>Bitte den Forecast-Cronjob einmalig manuell ausführen:<br>
					<code style="color:var(--pv-yellow)"><?= APP_URL ?>/pvcharge/cron_forecast.php?token=...</code></small>
				</div>
			<?php endif; ?>
		</div>
    </main>
</div>
<script src="../js/pv-dashboard.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
