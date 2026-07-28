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
     * Erstellt die benötigten Tabellen in der Datenbank, falls sie noch nicht existieren.
     * 
     * @return void
     * @throws Exception
     */
    public function migrate(): void {
        try {
            $this->logger->info("TelemetryRepository: Starte Datenbank-Tabellen Erstellung...");
            
            $sqlState = "
                CREATE TABLE IF NOT EXISTS `vehicle_state` (
                    `vin` VARCHAR(17) PRIMARY KEY,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `car_captured_at` DATETIME NOT NULL,
                    `soc_percent` INT NOT NULL,
                    `target_soc` INT NOT NULL,
                    `charge_power_kw` DECIMAL(5, 2) NOT NULL,
                    `battery_temp_max` DECIMAL(4, 1) NOT NULL,
                    `battery_temp_min` DECIMAL(4, 1) NOT NULL,
                    `charging_state` VARCHAR(20) NOT NULL,
                    `plug_connected` TINYINT(1) NOT NULL,
                    `is_locked` TINYINT(1) NOT NULL,
                    `mileage_km` INT NOT NULL,
                    `range_km` INT NOT NULL,
                    `outdoor_temp_c` DECIMAL(4, 1) NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            $this->db->exec($sqlState);

            $sqlLog = "
                CREATE TABLE IF NOT EXISTS `vehicle_telemetry_log` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `vin` VARCHAR(17) NOT NULL,
                    `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `car_captured_at` DATETIME NOT NULL,
                    `soc_percent` INT NOT NULL,
                    `charge_power_kw` DECIMAL(5, 2) NOT NULL,
                    `range_km` INT NOT NULL,
                    `mileage_km` INT NOT NULL,
                    `outdoor_temp_c` DECIMAL(4, 1) NOT NULL,
                    `raw_payload` LONGTEXT NOT NULL,
                    INDEX `idx_vin_timestamp` (`vin`, `timestamp`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            $this->db->exec($sqlLog);
            
            $this->logger->info("TelemetryRepository: Tabellen erfolgreich angelegt oder verifiziert.");
        } catch (Exception $e) {
            $this->logger->error("TelemetryRepository: Fehler bei der Migration der Tabellen.", ['error' => $e->getMessage()]);
            throw new Exception("Fehler beim Erstellen der Telemetrie-Tabellen: " . $e->getMessage());
        }
    }

    /**
     * Speichert die empfangenen Telemetriedaten in vehicle_state (Upsert) und vehicle_telemetry_log.
     *
     * @param array $data Das geparste JSON-Array
     * @return bool True bei Erfolg
     * @throws Exception
     */
    public function saveTelemetry(array $data): bool {
        try {
            $this->logger->info("TelemetryRepository: Starte Speichern der Telemetriedaten für VIN: " . ($data['vin'] ?? 'Unbekannt'));

            // 1. Daten extrahieren und vorverarbeiten
            $vin = $data['vin'];
            
            $capturedAtObj = new DateTime($data['captured_at']);
            $carCapturedAt = $capturedAtObj->format('Y-m-d H:i:s');

            $socPercent = (int)($data['battery']['soc'] ?? 0);
            $targetSoc = (int)($data['battery']['target_soc'] ?? 0);
            $chargePowerKw = (float)($data['battery']['charge_power_kw'] ?? 0.0);
            $batteryTempMax = (float)($data['battery']['max_temp_c'] ?? 0.0);
            $batteryTempMin = (float)($data['battery']['min_temp_c'] ?? 0.0);

            $chargingState = $data['status']['charging_state'] ?? 'off';
            $plugConnected = ($data['status']['plug_connected'] ?? false) ? 1 : 0;
            $isLocked = ($data['status']['is_locked'] ?? true) ? 1 : 0;
            $mileageKm = (int)($data['status']['mileage_km'] ?? 0);
            $rangeKm = (int)($data['status']['range_km'] ?? 0);
            $outdoorTempC = (float)($data['status']['outdoor_temp_c'] ?? 0.0);

            $rawPayload = json_encode($data);

            // 2. Transaktion starten
            $this->db->beginTransaction();

            // 3. vehicle_state (Upsert)
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
                    :soc_percent, 
                    :target_soc, 
                    :charge_power_kw, 
                    :battery_temp_max, 
                    :battery_temp_min, 
                    :charging_state, 
                    :plug_connected, 
                    :is_locked, 
                    :mileage_km, 
                    :range_km, 
                    :outdoor_temp_c
                ) ON DUPLICATE KEY UPDATE
                    `car_captured_at` = VALUES(`car_captured_at`),
                    `soc_percent` = VALUES(`soc_percent`),
                    `target_soc` = VALUES(`target_soc`),
                    `charge_power_kw` = VALUES(`charge_power_kw`),
                    `battery_temp_max` = VALUES(`battery_temp_max`),
                    `battery_temp_min` = VALUES(`battery_temp_min`),
                    `charging_state` = VALUES(`charging_state`),
                    `plug_connected` = VALUES(`plug_connected`),
                    `is_locked` = VALUES(`is_locked`),
                    `mileage_km` = VALUES(`mileage_km`),
                    `range_km` = VALUES(`range_km`),
                    `outdoor_temp_c` = VALUES(`outdoor_temp_c`),
                    `updated_at` = CURRENT_TIMESTAMP
            ");

            $stmtState->execute([
                ':vin' => $vin,
                ':car_captured_at' => $carCapturedAt,
                ':soc_percent' => $socPercent,
                ':target_soc' => $targetSoc,
                ':charge_power_kw' => $chargePowerKw,
                ':battery_temp_max' => $batteryTempMax,
                ':battery_temp_min' => $batteryTempMin,
                ':charging_state' => $chargingState,
                ':plug_connected' => $plugConnected,
                ':is_locked' => $isLocked,
                ':mileage_km' => $mileageKm,
                ':range_km' => $rangeKm,
                ':outdoor_temp_c' => $outdoorTempC
            ]);

            // 4. vehicle_telemetry_log
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

            // 5. Commit
            $this->db->commit();
            $this->logger->info("TelemetryRepository: Telemetriedaten erfolgreich gespeichert für VIN: " . $vin);
            return true;

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error("TelemetryRepository: Fehler beim Speichern der Telemetriedaten.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
