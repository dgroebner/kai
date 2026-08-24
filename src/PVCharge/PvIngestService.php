<?php

namespace Kai\Tools\PVCharge;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Log\Logger;
use PDO;

class PvIngestService
{
    /**
     * Whitelist aller Spalten, die per Telemetrie-Ingest beschrieben werden dürfen.
     * Spaltennamen können nicht als Prepared-Statement-Parameter gebunden werden,
     * deshalb ist die Allowlist die einzige zulässige Absicherung gegen SQL-Injection.
     */
    private const ALLOWED_COLUMNS = [
        'last_update',
        'system_flag',
        'comm_status',
        'battery_status',
        'pv_power_w',
        'yield_daily_kwh',
        'yield_total_kwh',
        'battery_soc_pct',
        'battery_soh_pct',
        'battery_power_w',
        'battery_voltage_v',
        'battery_current_a',
        'battery_temp_c',
        'battery_max_charge_a',
        'battery_max_discharge_a',
        'battery_energy_in_kwh',
        'battery_energy_out_kwh',
        'grid_p1_w',
        'grid_p2_w',
        'grid_p3_w',
        'grid_total_w',
        'house_load_w',
    ];

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
     * Filtert die übergebenen Spalten/Werte gegen die Allowlist.
     * Unbekannte Spaltennamen werden verworfen und protokolliert.
     *
     * @return array{0: string[], 1: list<mixed>}
     */
    private function filterAllowedColumns(array $columns, array $values): array
    {
        $safeColumns = [];
        $safeValues = [];
        $rejected = [];

        foreach (array_values($columns) as $index => $column) {
            if (in_array($column, self::ALLOWED_COLUMNS, true)) {
                $safeColumns[] = $column;
                $safeValues[] = array_values($values)[$index] ?? null;
            } else {
                $rejected[] = (string)$column;
            }
        }

        if ($rejected !== []) {
            $this->logger->warn('PvIngestService: Unbekannte Spalten im Payload verworfen.', [
                'columns' => $rejected,
            ]);
        }

        return [$safeColumns, $safeValues];
    }

    /**
     * @param array $columns
     * @param array $values
     * @return void
     */
    public function insertTelemetryData(array $columns, array $values): void
    {
        [$columns, $values] = $this->filterAllowedColumns($columns, $values);

        if ($columns === []) {
            $this->logger->warn('PvIngestService: Telemetrie-Payload ohne gültige Spalten verworfen.');
            return;
        }

        // --- Messfehler-Prüfung: Hauslast < 10 W ignorieren ---
        $houseIndex = array_search('house_load_w', $columns, true);
        if ($houseIndex !== false) {
            $houseLoad = (float)$values[$houseIndex];
            if ($houseLoad < 10) {
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

    private function checkBatteryFullyCharged(array $columns, array $values): void
    {
        $socIndex = array_search('battery_soc_pct', $columns, true);
        if ($socIndex === false) {
            return;
        }

        $currentSoc = (int)$values[$socIndex];

        // Prüfen, ob der aktuelle Wert 100% beträgt
        if ($currentSoc < 100) {
            return;
        }

        // Vorherigen SoC-Wert aus der DB abrufen
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

        // Finalen Tagesertrag aus der Telemetrie berechnen
        $yieldStmt = $this->dbCon->query("
            SELECT MAX(yield_daily_kwh) 
            FROM pv_telemetry 
            WHERE DATE(last_update) = CURDATE()
        ");
        $maxDailyKwh = (float)$yieldStmt->fetchColumn();

        if ($maxDailyKwh > 0) {
            $finalWh = (int)round($maxDailyKwh * 1000);

            // Eindeutige Platzhalter für Insert und On Duplicate Key Update verwendet, um den Fehler zu beheben
            $updateStmt = $this->dbCon->prepare("
                INSERT INTO pv_forecast_daily (forecast_date, real_watt_hours_day, watt_hours_day)
                VALUES (CURDATE(), :wh1, 0)
                ON DUPLICATE KEY UPDATE real_watt_hours_day = :wh2
            ");
            $updateStmt->execute([
                ':wh1' => $finalWh,
                ':wh2' => $finalWh
            ]);
        }
    }

    /**
     * @param array $columns
     * @param array $values
     * @return void
     */
    public function upsertLiveData(array $columns, array $values): void
    {
        [$columns, $values] = $this->filterAllowedColumns($columns, $values);

        if ($columns === []) {
            $this->logger->warn('PvIngestService: Live-Payload ohne gültige Spalten verworfen.');
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $colNames = implode(', ', $columns);

        $updateStmt = implode(', ', array_map(fn($col) => "$col=VALUES($col)", $columns));

        $sql = "INSERT INTO pv_live (id, $colNames) VALUES (1, $placeholders) ON DUPLICATE KEY UPDATE $updateStmt";
        $stmt = $this->dbCon->prepare($sql);

        $stmt->execute($values);
    }
}