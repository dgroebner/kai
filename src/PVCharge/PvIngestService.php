<?php

namespace Kai\Tools\PVCharge;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Log\Logger;
use PDO;

class PvIngestService
{
    private Database $db;
    private PDO $dbCon;
    private Logger $logger;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->dbCon = $this->db->getConnection();
        $this->logger = new Logger();
    }


    /**
     * @param array $columns
     * @param mixed $values
     * @return void
     */
    public function insertTelemetryData(array $columns, mixed $values): void
    {
        // --- Messfehler-Prüfung: Hauslast < 10 W ignorieren ---
        $houseIndex = array_search('house_load_w', $columns);
        if ($houseIndex !== false) {
            $houseLoad = (float)$values[$houseIndex];
            if ($houseLoad < 100) {
                $this->logger->warn("PvIngestService: Telemetrie-Messfehler ignoriert (Hauslast zu gering: {$houseLoad} W).");
                return;
            }
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $colNames = implode(', ', $columns);

        $sql = "INSERT INTO pv_telemetry ($colNames) VALUES ($placeholders)";
        $stmt = $this->dbCon->prepare($sql);
        $stmt->execute($values);

        // --- Prüfung auf 100% Batterieladung ---
        $this->checkBatteryFullyCharged($columns, $values);

        // --- Automatisches Setzen des finalen Tagesertrags ab 22:30 Uhr ---
        $this->checkAndFinalizeDailyYield();
    }

    private function checkBatteryFullyCharged(array $columns, mixed $values): void
    {
        $socIndex = array_search('battery_soc_pct', $columns);
        if ($socIndex === false) {
            return;
        }

        $currentSoc = (int)$values[$socIndex];

        // Prüfen, ob der aktuelle Wert 100% beträgt
        if ($currentSoc < 100) {
            return;
        }

        // Vorherigen SoC-Wert aus der DB abrufen (der zweitjüngste Eintrag, da der aktuelle gerade eingefügt wurde)
        $stmt = $this->dbCon->query("
            SELECT battery_soc_pct 
            FROM pv_telemetry 
            ORDER BY last_update DESC, id DESC 
            LIMIT 1 OFFSET 1
        ");
        $previousSoc = $stmt->fetchColumn();

        // Wenn kein vorheriger Wert existiert oder dieser bereits bei 100% lag, nichts tun
        if ($previousSoc === false || (int)$previousSoc >= 100) {
            return;
        }

        // Aktivität loggen, da die Batterie von < 100% auf 100% gewechselt ist
        $activityLogger = new ActivityLogger($this->db);
        $activityLogger->log(
            'battery_fully_charged',
            'Die Batterie hat 100% Ladestand erreicht.',
            '/pvcharge/index.php'
        );
    }

    private function checkAndFinalizeDailyYield(): void
    {
        // Prüfen, ob es nach 22:30 Uhr ist
        if (date('H:i') < '22:30') {
            return;
        }

        // Prüfen, ob die PV-Leistung aktuell 0 W beträgt
        $checkStmt = $this->dbCon->query("
            SELECT pv_power_w 
            FROM pv_telemetry 
            WHERE DATE(last_update) = CURDATE() 
            ORDER BY last_update DESC 
            LIMIT 1
        ");
        $latestPv = $checkStmt->fetchColumn();

        if ($latestPv === false || (float)$latestPv > 0) {
            return; // Es ist noch nicht dunkel / PV liefert noch etwas
        }

        // Prüfen, ob für heute in pv_forecast_daily der Realwert noch fehlt (NULL)
        $forecastCheckStmt = $this->dbCon->query("
            SELECT real_watt_hours_day 
            FROM pv_forecast_daily 
            WHERE forecast_date = CURDATE()
        ");
        $currentRealWh = $forecastCheckStmt->fetchColumn();

        // Wenn kein Datensatz existiert oder real_watt_hours_day bereits gesetzt ist, abbrechen
        if ($currentRealWh !== false && $currentRealWh !== null) {
            return;
        }

        // Finalen Tagesertrag aus der Telemetrie berechnen (z.B. der maximale yield_daily_kwh des Tages oder Summe)
        // Je nachdem, wie deine Tabelle aufgebaut ist, nehmen wir hier den tagesaktuellen Höchstwert der kWh und rechnen ihn in Wh um:
        $yieldStmt = $this->dbCon->query("
            SELECT MAX(yield_daily_kwh) 
            FROM pv_telemetry 
            WHERE DATE(last_update) = CURDATE()
        ");
        $maxDailyKwh = (float)$yieldStmt->fetchColumn();

        if ($maxDailyKwh > 0) {
            $finalWh = (int)round($maxDailyKwh * 1000);

            // In pv_forecast_daily eintragen (upsert, falls Zeile existiert)
            $updateStmt = $this->dbCon->prepare("
                INSERT INTO pv_forecast_daily (forecast_date, real_watt_hours_day, watt_hours_day)
                VALUES (CURDATE(), :wh, 0)
                ON DUPLICATE KEY UPDATE real_watt_hours_day = :wh
            ");
            $updateStmt->execute([':wh' => $finalWh]);
        }
    }

    /**
     * @param array $columns
     * @param mixed $values
     * @return void
     */
    public function upsertLiveData(array $columns, mixed $values): void
    {
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $colNames = implode(', ', $columns);

        $updateStmt = implode(', ', array_map(fn($col) => "$col=VALUES($col)", $columns));

        $sql = "INSERT INTO pv_live (id, $colNames) VALUES (1, $placeholders) ON DUPLICATE KEY UPDATE $updateStmt";
        $stmt = $this->dbCon->prepare($sql);

        $stmt->execute($values);
    }
}