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

-- Erweiterung car_trips für reale Ist-Verbrauchsdaten aus Telemetrie
ALTER TABLE `car_trips`
    ADD COLUMN IF NOT EXISTS `actual_distance_km` DECIMAL(8, 2) NULL AFTER `en_route_charge_kwh`,
    ADD COLUMN IF NOT EXISTS `actual_consumption_kwh` DECIMAL(8, 2) NULL AFTER `actual_distance_km`,
    ADD COLUMN IF NOT EXISTS `actual_arrival_soc` INT NULL AFTER `actual_consumption_kwh`,
    ADD COLUMN IF NOT EXISTS `telemetry_matched_at` DATETIME NULL AFTER `actual_arrival_soc`;

-- Erweiterung vehicle_charges für Ladestationen, Betreiber und E-Bon-Zuordnung
ALTER TABLE `vehicle_charges`
    ADD COLUMN IF NOT EXISTS `station_name` VARCHAR(255) NULL AFTER `tariff_category`,
    ADD COLUMN IF NOT EXISTS `station_operator` VARCHAR(100) NULL AFTER `station_name`,
    ADD COLUMN IF NOT EXISTS `receipt_id` INT NULL AFTER `cost_eur`,
    ADD INDEX IF NOT EXISTS `idx_receipt_id` (`receipt_id`);

-- Tabelle für konfigurierbare Ladetarife (Unterwegs & Roaming)
CREATE TABLE IF NOT EXISTS `car_charging_tariffs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `operator_match` VARCHAR(255) NULL,
    `price_ac_eur_kwh` DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    `price_dc_eur_kwh` DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    `blocking_fee_after_min` INT NULL,
    `blocking_fee_per_min` DECIMAL(6,4) NULL,
    `monthly_fee_eur` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `notes` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `car_charging_tariffs` (`id`, `name`, `operator_match`, `price_ac_eur_kwh`, `price_dc_eur_kwh`, `is_default`, `notes`) VALUES
(1, 'Stadtwerke Leipzig L-Charge', 'Stadtwerke Leipzig,L-Charge,Leipziger Gruppe,Leipziger Stadtwerke', 0.3900, 0.4900, 0, 'Stromkundentarif der Leipziger Stadtwerke (L-Gruppe)'),
(2, 'Vattenfall InCharge', 'Vattenfall,InCharge', 0.4400, 0.5900, 0, 'Kostenloser Tarif mit Anmeldung (Vattenfall InCharge Netz)'),
(3, 'EnBW mobility+', 'EnBW,mobility+', 0.5900, 0.5900, 0, 'Kostenloser Tarif mit Anmeldung (EnBW Ladestationen)'),
(4, 'ADAC Tarif für Aral Pulse', 'Aral,pulse,ADAC', 0.5700, 0.5700, 0, 'ADAC e-Charge Vorteilskonditionen an Aral pulse Stationen'),
(5, 'Standard Roaming', '*', 0.5900, 0.6900, 1, 'Standard Roaming-Fallback für sonstige Stationen')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    operator_match = VALUES(operator_match),
    price_ac_eur_kwh = VALUES(price_ac_eur_kwh),
    price_dc_eur_kwh = VALUES(price_dc_eur_kwh),
    is_default = VALUES(is_default),
    notes = VALUES(notes);



