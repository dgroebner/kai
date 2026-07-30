<?php
namespace Kai\Tools\Car;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use Exception;
use DateTime;

class TelemetryRepository {
    private PDO $db;
    private Logger $logger;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->logger = new Logger(14);
    }

    /**
     * Schätzt die Reichweite basierend auf historischen Daten.
     * Berücksichtigt bevorzugt Datenpunkte in einem ähnlichen Temperaturbereich (±5°C).
     */
    private function calculateInterpolatedRange(string $vin, int $socPercent, ?float $outdoorTempC = null): int {
        if ($socPercent <= 0) {
            return 0;
        }

        try {
            $avgFactor = null;

            // 1. Wenn eine Außentemperatur vorliegt, primär im Fenster ±5°C suchen
            if ($outdoorTempC !== null) {
                $stmtTemp = $this->db->prepare("
                    SELECT AVG(range_km / soc_percent) as avg_factor
                    FROM vehicle_telemetry_log
                    WHERE vin = :vin 
                      AND range_km IS NOT NULL 
                      AND range_km > 0 
                      AND soc_percent > 0
                      AND outdoor_temp_c BETWEEN :temp_min AND :temp_max
                ");
                $stmtTemp->execute([
                    ':vin'      => $vin,
                    ':temp_min' => $outdoorTempC - 5.0,
                    ':temp_max' => $outdoorTempC + 5.0
                ]);
                $avgFactor = $stmtTemp->fetchColumn();
            }

            // 2. Fallback: Wenn noch keine Logs im Temperaturbereich existieren, globalen Durchschnitt nehmen
            if (!$avgFactor || $avgFactor <= 0) {
                $stmtGlobal = $this->db->prepare("
                    SELECT AVG(range_km / soc_percent) as avg_factor
                    FROM vehicle_telemetry_log
                    WHERE vin = :vin 
                      AND range_km IS NOT NULL 
                      AND range_km > 0 
                      AND soc_percent > 0
                ");
                $stmtGlobal->execute([':vin' => $vin]);
                $avgFactor = $stmtGlobal->fetchColumn();
            }

            // 3. Fallback: Harter Standardwert (ca. 3.8 km / % SoC), falls die DB noch komplett leer ist
            if (!$avgFactor || $avgFactor <= 0) {
                $avgFactor = 3.8;
            }

            return (int)round($socPercent * $avgFactor);

        } catch (Exception $e) {
            return (int)round($socPercent * 3.8);
        }
    }

    /**
     * Speichert / aktualisiert den Live-Status in vehicle_state.
     */
    public function saveState(array $data): bool {
        try {
            $vin = $data['vin'];
            $capturedAtObj = new DateTime($data['captured_at']);
            $carCapturedAt = $capturedAtObj->format('Y-m-d H:i:s');

            $socPercent     = isset($data['battery']['soc']) ? (int)$data['battery']['soc'] : null;
            $targetSoc      = isset($data['battery']['target_soc']) ? (int)$data['battery']['target_soc'] : null;
            $chargePowerKw  = isset($data['battery']['charge_power_kw']) ? (float)$data['battery']['charge_power_kw'] : null;
            $batteryTempMax = isset($data['battery']['max_temp_c']) ? (float)$data['battery']['max_temp_c'] : null;
            $batteryTempMin = isset($data['battery']['min_temp_c']) ? (float)$data['battery']['min_temp_c'] : null;

            $chargingState  = $data['status']['charging_state'] ?? null;
            $plugConnected  = isset($data['status']['plug_connected']) ? ($data['status']['plug_connected'] ? 1 : 0) : null;
            $isLocked       = isset($data['status']['is_locked']) ? ($data['status']['is_locked'] ? 1 : 0) : null;
            $mileageKm      = isset($data['status']['mileage_km']) ? (int)$data['status']['mileage_km'] : null;
            $outdoorTempC   = isset($data['status']['outdoor_temp_c']) ? (float)$data['status']['outdoor_temp_c'] : null;

			// Reichweite ermitteln / interpolieren (unter Berücksichtigung der Außentemperatur)
            $rangeKm = isset($data['status']['range_km']) && (int)$data['status']['range_km'] > 0
                       ? (int)$data['status']['range_km']
                       : null;

            if ($rangeKm === null && $socPercent !== null) {
                $rangeKm = $this->calculateInterpolatedRange($vin, $socPercent, $outdoorTempC);
            }

            $stmtState = $this->db->prepare("
                INSERT INTO `vehicle_state` (
                    `vin`, 
                    `car_captured_at`, 
                    `soc_percent`, 
                    `target_soc`, 
                    `charge_power_kw`, 
                    `battery_temp_max`, 
                    `battery_temp_min`, 
                    `charging_state`, 
                    `plug_connected`, 
                    `is_locked`, 
                    `mileage_km`, 
                    `range_km`, 
                    `outdoor_temp_c`
                ) VALUES (
                    :vin, 
                    :car_captured_at, 
                    COALESCE(:soc_percent, 0), 
                    COALESCE(:target_soc, 0), 
                    COALESCE(:charge_power_kw, 0.0), 
                    COALESCE(:battery_temp_max, 0.0), 
                    COALESCE(:battery_temp_min, 0.0), 
                    COALESCE(:charging_state, 'unknown'), 
                    COALESCE(:plug_connected, 0), 
                    COALESCE(:is_locked, 1), 
                    COALESCE(:mileage_km, 0), 
                    COALESCE(:range_km, 0), 
                    COALESCE(:outdoor_temp_c, 0.0)
                ) ON DUPLICATE KEY UPDATE
                    `car_captured_at`  = VALUES(`car_captured_at`),
                    `soc_percent`      = CASE WHEN VALUES(`soc_percent`) = 0 THEN `soc_percent` ELSE VALUES(`soc_percent`) END,
                    `target_soc`       = CASE WHEN VALUES(`target_soc`) = 0 THEN `target_soc` ELSE VALUES(`target_soc`) END,
                    `charge_power_kw`  = CASE WHEN VALUES(`charge_power_kw`) = 0.0 THEN `charge_power_kw` ELSE VALUES(`charge_power_kw`) END,
                    `battery_temp_max` = CASE WHEN VALUES(`battery_temp_max`) = 0.0 THEN `battery_temp_max` ELSE VALUES(`battery_temp_max`) END,
                    `battery_temp_min` = CASE WHEN VALUES(`battery_temp_min`) = 0.0 THEN `battery_temp_min` ELSE VALUES(`battery_temp_min`) END,
                    `charging_state`   = CASE WHEN VALUES(`charging_state`) = 'unknown' THEN `charging_state` ELSE VALUES(`charging_state`) END,
                    `plug_connected`   = VALUES(`plug_connected`),
                    `is_locked`        = VALUES(`is_locked`),
                    `mileage_km`       = CASE WHEN VALUES(`mileage_km`) = 0 THEN `mileage_km` ELSE VALUES(`mileage_km`) END,
                    `outdoor_temp_c`   = CASE WHEN VALUES(`outdoor_temp_c`) = 0.0 THEN `outdoor_temp_c` ELSE VALUES(`outdoor_temp_c`) END,
                    `updated_at`       = CURRENT_TIMESTAMP
            ");

            $stmtState->execute([
                ':vin'              => $vin,
                ':car_captured_at'  => $carCapturedAt,
                ':soc_percent'      => $socPercent,
                ':target_soc'       => $targetSoc,
                ':charge_power_kw'  => $chargePowerKw,
                ':battery_temp_max' => $batteryTempMax,
                ':battery_temp_min' => $batteryTempMin,
                ':charging_state'   => $chargingState,
                ':plug_connected'   => $plugConnected,
                ':is_locked'        => $isLocked,
                ':mileage_km'       => $mileageKm,
                ':range_km'         => $rangeKm,
                ':outdoor_temp_c'   => $outdoorTempC
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error("TelemetryRepository: Fehler bei saveState.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Schreibt einen Log-Eintrag in vehicle_telemetry_log.
     */
    public function saveLog(array $data): bool {
        try {
            $vin = $data['vin'];
            $capturedAtObj = new DateTime($data['captured_at']);
            $carCapturedAt = $capturedAtObj->format('Y-m-d H:i:s');

            $stmtCurrent = $this->db->prepare("
                SELECT mileage_km, range_km, outdoor_temp_c 
                FROM vehicle_state 
                WHERE vin = :vin
            ");
            $stmtCurrent->execute([':vin' => $vin]);
            $currentState = $stmtCurrent->fetch(PDO::FETCH_ASSOC) ?: [];

            $socPercent    = (int)($data['battery']['soc'] ?? 0);
            $chargePowerKw = (float)($data['battery']['charge_power_kw'] ?? 0.0);
            
            $mileageKm     = isset($data['status']['mileage_km']) && $data['status']['mileage_km'] !== null
                             ? (int)$data['status']['mileage_km']
                             : (int)($currentState['mileage_km'] ?? 0);

			// Reichweite bestimmen / interpolieren
            $rangeKm = isset($data['status']['range_km']) && (int)$data['status']['range_km'] > 0
                       ? (int)$data['status']['range_km']
                       : null;

            if ($rangeKm === null && $socPercent > 0) {
                $rangeKm = $this->calculateInterpolatedRange($vin, $socPercent, $outdoorTempC);
            }

            if ($rangeKm === null || $rangeKm === 0) {
                $rangeKm = (int)($currentState['range_km'] ?? 0);
            }

            $outdoorTempC  = isset($data['status']['outdoor_temp_c']) && $data['status']['outdoor_temp_c'] !== null
                             ? (float)$data['status']['outdoor_temp_c']
                             : (float)($currentState['outdoor_temp_c'] ?? 0.0);

            $rawPayload    = json_encode($data);

            $stmtLog = $this->db->prepare("
                INSERT INTO `vehicle_telemetry_log` (
                    `vin`, 
                    `car_captured_at`, 
                    `soc_percent`, 
                    `charge_power_kw`, 
                    `range_km`, 
                    `mileage_km`, 
                    `outdoor_temp_c`, 
                    `raw_payload`
                ) VALUES (
                    :vin, 
                    :car_captured_at, 
                    :soc_percent, 
                    :charge_power_kw, 
                    :range_km, 
                    :mileage_km, 
                    :outdoor_temp_c, 
                    :raw_payload
                ) ON DUPLICATE KEY UPDATE
                    `soc_percent`     = VALUES(`soc_percent`),
                    `charge_power_kw` = VALUES(`charge_power_kw`),
                    `mileage_km`      = IF(VALUES(`mileage_km`) > 0, VALUES(`mileage_km`), `mileage_km`),
                    `outdoor_temp_c`  = VALUES(`outdoor_temp_c`),
                    `raw_payload`     = VALUES(`raw_payload`)
            ");

            $stmtLog->execute([
                ':vin'             => $vin,
                ':car_captured_at' => $carCapturedAt,
                ':soc_percent'     => $socPercent,
                ':charge_power_kw' => $chargePowerKw,
                ':range_km'        => $rangeKm,
                ':mileage_km'      => $mileageKm,
                ':outdoor_temp_c'  => $outdoorTempC,
                ':raw_payload'     => $rawPayload
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error("TelemetryRepository: Fehler bei saveLog.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}