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
     * Speichert / aktualisiert den Live-Status in vehicle_state.
     * Nutzt COALESCE, damit Nicht-Null-Werte bei Rumpf-Updates erhalten bleiben.
     */
    public function saveState(array $data): bool {
        try {
            $vin = $data['vin'];
            $capturedAtObj = new DateTime($data['captured_at']);
            $carCapturedAt = $capturedAtObj->format('Y-m-d H:i:s');

            // Nullable Variablen
            $socPercent     = isset($data['battery']['soc']) ? (int)$data['battery']['soc'] : null;
            $targetSoc      = isset($data['battery']['target_soc']) ? (int)$data['battery']['target_soc'] : null;
            $chargePowerKw  = isset($data['battery']['charge_power_kw']) ? (float)$data['battery']['charge_power_kw'] : null;
            $batteryTempMax = isset($data['battery']['max_temp_c']) ? (float)$data['battery']['max_temp_c'] : null;
            $batteryTempMin = isset($data['battery']['min_temp_c']) ? (float)$data['battery']['min_temp_c'] : null;

            $chargingState  = $data['status']['charging_state'] ?? null;
            $plugConnected  = isset($data['status']['plug_connected']) ? ($data['status']['plug_connected'] ? 1 : 0) : null;
            $isLocked       = isset($data['status']['is_locked']) ? ($data['status']['is_locked'] ? 1 : 0) : null;
            $mileageKm      = isset($data['status']['mileage_km']) ? (int)$data['status']['mileage_km'] : null;
            $rangeKm        = isset($data['status']['range_km']) ? (int)$data['status']['range_km'] : null;
            $outdoorTempC   = isset($data['status']['outdoor_temp_c']) ? (float)$data['status']['outdoor_temp_c'] : null;

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
                    COALESCE(:target_soc_val, 0), 
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
                    `soc_percent`      = IF(:soc_percent IS NULL, `soc_percent`, :soc_percent),
                    `target_soc`       = IF(:target_soc_val IS NULL, `target_soc`, :target_soc_val),
                    `charge_power_kw`  = IF(:charge_power_kw IS NULL, `charge_power_kw`, :charge_power_kw),
                    `battery_temp_max` = IF(:battery_temp_max IS NULL, `battery_temp_max`, :battery_temp_max),
                    `battery_temp_min` = IF(:battery_temp_min IS NULL, `battery_temp_min`, :battery_temp_min),
                    `charging_state`   = IF(:charging_state IS NULL, `charging_state`, :charging_state),
                    `plug_connected`   = IF(:plug_connected IS NULL, `plug_connected`, :plug_connected),
                    `is_locked`        = IF(:is_locked IS NULL, `is_locked`, :is_locked),
                    `mileage_km`       = IF(:mileage_km IS NULL, `mileage_km`, :mileage_km),
                    `range_km`         = IF(:range_km IS NULL, `range_km`, :range_km),
                    `outdoor_temp_c`   = IF(:outdoor_temp_c IS NULL, `outdoor_temp_c`, :outdoor_temp_c),
                    `updated_at`       = CURRENT_TIMESTAMP
            ");

            $stmtState->execute([
                ':vin'              => $vin,
                ':car_captured_at'  => $carCapturedAt,
                ':soc_percent'      => $socPercent,
                ':target_soc_val'   => $targetSoc,
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
     * Schreibt einen vollen Log-Eintrag in vehicle_telemetry_log.
     */
    public function saveLog(array $data): bool {
        try {
            $vin = $data['vin'];
            $capturedAtObj = new DateTime($data['captured_at']);
            $carCapturedAt = $capturedAtObj->format('Y-m-d H:i:s');

            $socPercent    = (int)($data['battery']['soc'] ?? 0);
            $chargePowerKw = (float)($data['battery']['charge_power_kw'] ?? 0.0);
            $rangeKm       = (int)($data['status']['range_km'] ?? 0);
            $mileageKm     = (int)($data['status']['mileage_km'] ?? 0);
            $outdoorTempC  = (float)($data['status']['outdoor_temp_c'] ?? 0.0);
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
                )
            ");

            $stmtLog->execute([
                ':vin' => $vin,
                ':car_captured_at' => $carCapturedAt,
                ':soc_percent' => $socPercent,
                ':charge_power_kw' => $chargePowerKw,
                ':range_km' => $rangeKm,
                ':mileage_km' => $mileageKm,
                ':outdoor_temp_c' => $outdoorTempC,
                ':raw_payload' => $rawPayload
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error("TelemetryRepository: Fehler bei saveLog.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}