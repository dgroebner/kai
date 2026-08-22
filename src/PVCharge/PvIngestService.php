<?php

namespace Kai\Tools\PVCharge;

use Kai\Tools\Shared\Db\Database;
use PDO;

class PvIngestService
{
    private Database $db;
    private PDO $dbCon;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->dbCon = $this->db->getConnection();
    }


    /**
     * @param array $columns
     * @param mixed $values
     * @return void
     */
    public function insertTelemetryData(array $columns, mixed $values): void
    {
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $colNames = implode(', ', $columns);

        $sql = "INSERT INTO pv_telemetry ($colNames) VALUES ($placeholders)";
        $stmt = $this->dbCon->prepare($sql);
        $stmt->execute($values);

        // --- Automatisches Setzen des finalen Tagesertrags ab 22:30 Uhr ---
        $this->checkAndFinalizeDailyYield();
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
