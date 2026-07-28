<?php
require_once __DIR__ . '/../bootstrap.php';

use Kai\Tools\Shared\Db\Database;

try {
    echo "Verbinde mit der Datenbank...\n";
    $db = Database::getInstance()->getConnection();

    echo "Erstelle Tabelle vehicle_state...\n";
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
    $db->exec($sqlState);
    echo "Tabelle vehicle_state erfolgreich erstellt oder existiert bereits.\n";

    echo "Erstelle Tabelle vehicle_telemetry_log...\n";
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
    $db->exec($sqlLog);
    echo "Tabelle vehicle_telemetry_log erfolgreich erstellt oder existiert bereits.\n";

    echo "Migration erfolgreich abgeschlossen!\n";
} catch (\Throwable $e) {
    echo "Fehler bei der Migration: " . $e->getMessage() . "\n";
    exit(1);
}
