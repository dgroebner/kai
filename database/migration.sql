DROP TABLE IF EXISTS `vehicle_trips`;

CREATE TABLE IF NOT EXISTS `vehicle_charges` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tronity_charge_id` VARCHAR(100) NOT NULL UNIQUE,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NOT NULL,
    `duration_min` INT NOT NULL,
    `soc_start_pct` INT NOT NULL,
    `soc_end_pct` INT NOT NULL,
    `delta_soc_pct` INT NOT NULL,
    `charged_net_kwh` DECIMAL(6,2) NOT NULL,
    `avg_charge_power_kw` DECIMAL(5,2) NOT NULL,
    `charge_mode` VARCHAR(10) NULL,
    `lat` DECIMAL(10, 7) NULL,
    `lon` DECIMAL(10, 7) NULL,
    `location_type` ENUM('HOME', 'PUBLIC', 'UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
    `tariff_category` VARCHAR(50) NULL,
    `home_meter_kwh` DECIMAL(6,2) NULL,
    `home_pv_kwh` DECIMAL(6,2) NULL,
    `home_grid_kwh` DECIMAL(6,2) NULL,
    `loss_kwh` DECIMAL(6,2) NULL,
    `loss_pct` DECIMAL(5,1) NULL,
    `cost_eur` DECIMAL(6,2) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_start_time` (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
