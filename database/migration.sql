-- Migration: Reisekosten- & Ladeplanung (Car Domain)
CREATE TABLE IF NOT EXISTS `car_trips` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `parent_trip_id` INT UNSIGNED NULL,
    `calendar_uid` VARCHAR(255) NULL,
    `title` VARCHAR(255) NOT NULL,
    `start_address` VARCHAR(255) NOT NULL,
    `start_lat` DECIMAL(10, 7) NOT NULL,
    `start_lon` DECIMAL(10, 7) NOT NULL,
    `destination_address` VARCHAR(255) NOT NULL,
    `destination_lat` DECIMAL(10, 7) NOT NULL,
    `destination_lon` DECIMAL(10, 7) NOT NULL,
    `departure_time` DATETIME NOT NULL,
    `return_time` DATETIME NULL,
    `is_round_trip` TINYINT(1) NOT NULL DEFAULT 0,
    `target_arrival_soc` INT NOT NULL DEFAULT 10,
    `planned_departure_soc` INT NOT NULL DEFAULT 100,
    `total_distance_km` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    `estimated_consumption_kwh` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    `en_route_charge_kwh` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    `routing_provider` VARCHAR(50) NOT NULL DEFAULT 'ORS_HEURISTIC',
    `abrp_deep_link` TEXT NULL,
    `status` ENUM('entwurf', 'geplant', 'aktiv', 'abgeschlossen', 'storniert') NOT NULL DEFAULT 'geplant',
    `home_charge_cost` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `en_route_charge_cost` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `additional_cost` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`parent_trip_id`) REFERENCES `car_trips`(`id`) ON DELETE CASCADE,
    INDEX `idx_calendar_uid` (`calendar_uid`),
    INDEX `idx_departure_time` (`departure_time`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `car_trip_charging_steps` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `trip_id` INT UNSIGNED NOT NULL,
    `step_type` ENUM('pv_precharge', 'evening_grid', 'en_route_fast') NOT NULL,
    `scheduled_date` DATE NOT NULL,
    `target_soc` INT NOT NULL,
    `planned_kwh` DECIMAL(6, 2) NOT NULL,
    `status` ENUM('geplant', 'erledigt', 'uebersprungen') NOT NULL DEFAULT 'geplant',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`trip_id`) REFERENCES `car_trips`(`id`) ON DELETE CASCADE,
    INDEX `idx_scheduled_date` (`scheduled_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `car_trip_transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `trip_id` INT UNSIGNED NOT NULL,
    `transaction_type` ENUM('giro', 'creditcard') NOT NULL,
    `transaction_id` INT NOT NULL,
    `cost_category` ENUM('charge', 'toll', 'parking', 'other') NOT NULL DEFAULT 'charge',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`trip_id`) REFERENCES `car_trips`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_trip_tx` (`transaction_type`, `transaction_id`),
    INDEX `idx_trip_cost_cat` (`trip_id`, `cost_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW `bank_trip_transactions` AS SELECT * FROM `car_trip_transactions`;

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `label`) VALUES
('home_address', 'Zuhause', 'Heimatadresse (Text)'),
('home_latitude', '51.2956492', 'Breitengrad Zuhause (GPS)'),
('home_longitude', '12.4541829', 'Längengrad Zuhause (GPS)'),
('home_geofence_radius_m', '200', 'Heim-Geofence Radius (Meter)'),
('grid_import_price_kwh', '0.2689', 'Netzbezugspreis Strom (€/kWh)'),
('grid_export_price_kwh', '0.06', 'Einspeisevergütung Strom (€/kWh)'),
('trip_calendar_allowed_senders', '', 'Erlaubte Kalender-Absender für Reisen (kommagetrennt)'),
('trip_calendar_auto_accept', '1', 'Termineinladungen für Reisen automatisch bestätigen (1/0)');

