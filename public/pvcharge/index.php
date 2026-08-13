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
    } catch (\Throwable $e) {
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
			<div class="page-header-actions">
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
                <div class="kpi-value kpi-value-colored" style="--value-color: <?= $systemBias >= 0 ? 'var(--pv-green)' : 'var(--color-red)' ?>;">
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
					<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
					
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
					<small>Bitte den Forecast-Cronjob einmalig manuell ausführen:<br>
					<code class="text-warning"><?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/pvcharge/cron_forecast.php?token=...</code></small>
				</div>
			<?php endif; ?>
		</div>
    </main>
</div>
<script src="../js/pv-dashboard.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
