-- Schema for PV charging management
CREATE TABLE IF NOT EXISTS `pv_forecast_hourly` (
    `forecast_time` DATETIME PRIMARY KEY,
    `watts` INT NOT NULL,
    `watt_hours` INT NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `pv_forecast_daily` (
    `forecast_date` DATE PRIMARY KEY,
    `watt_hours_day` INT NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `real_watt_hours_day` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pv_telemetry (
                              id INT AUTO_INCREMENT PRIMARY KEY,
                              last_update DATETIME,
                              system_flag INT,
                              comm_status INT,
                              battery_status INT,
                              pv_power_w INT,
                              yield_daily_kwh DECIMAL(6,2),
                              yield_total_kwh DECIMAL(10,2),
                              battery_soc_pct INT,
                              battery_soh_pct INT,
                              battery_power_w INT,
                              battery_voltage_v DECIMAL(5,1),
                              battery_current_a DECIMAL(5,1),
                              battery_temp_c DECIMAL(4,1),
                              battery_max_charge_a DECIMAL(5,1),
                              battery_max_discharge_a DECIMAL(5,1),
                              battery_energy_in_kwh DECIMAL(8,2),
                              battery_energy_out_kwh DECIMAL(8,2),
                              grid_p1_w DECIMAL(8,2),
                              grid_p2_w DECIMAL(8,2),
                              grid_p3_w DECIMAL(8,2),
                              grid_total_w DECIMAL(8,2),
                              house_load_w DECIMAL(8,2),
                              INDEX idx_pv_telemetry_last_update (last_update)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pv_live LIKE pv_telemetry;
ALTER TABLE pv_live MODIFY id INT NOT NULL;

-- Schema for VW ID.Buzz Car Telemetry

CREATE TABLE IF NOT EXISTS `vehicle_state` (
    `vin` VARCHAR(17) PRIMARY KEY,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `car_captured_at` DATETIME NOT NULL,
    `soc_percent` INT NOT NULL,
    `target_soc` INT NOT NULL,
    `charge_power_kw` DECIMAL(5, 2) NOT NULL,
    `battery_temp_max` DECIMAL(4, 1) NOT NULL,
    `battery_temp_min` DECIMAL(4, 1) NOT NULL,
    `charging_state` VARCHAR(50) NOT NULL,
    `plug_connected` TINYINT(1) NOT NULL,
    `is_locked` TINYINT(1) NOT NULL,
    `mileage_km` INT NOT NULL,
    `range_km` INT NOT NULL,
    `outdoor_temp_c` DECIMAL(4, 1) NOT NULL,
    `latitude` DECIMAL(10, 7) DEFAULT NULL,
    `longitude` DECIMAL(10, 7) DEFAULT NULL,
    `estimated_finish_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    `latitude` DECIMAL(10, 7) DEFAULT NULL,
    `longitude` DECIMAL(10, 7) DEFAULT NULL,
    `raw_payload` LONGTEXT NOT NULL,
    INDEX `idx_vin_timestamp` (`vin`, `timestamp`),
    UNIQUE KEY `uq_vin_car_captured_at` (`vin`, `car_captured_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- DOMAIN: BANKING & FINANZEN
-- ==========================================================================

-- 1. Stammdaten für Konten (Giro, Tagesgeld, Kreditkarte, etc.)
CREATE TABLE IF NOT EXISTS bank_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_name VARCHAR(100) NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    account_type ENUM('checking', 'savings', 'credit_card', 'other') NOT NULL DEFAULT 'checking',
    iban VARCHAR(34) NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    current_balance DECIMAL(12, 2) DEFAULT NULL,
    api_credentials TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Girokonto Transaktionen (CSV-Imports, E-Mail-Import & API)
CREATE TABLE IF NOT EXISTS bank_giro_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED DEFAULT NULL,
--    tx_hash VARCHAR(64) NOT NULL,
    transaction_id VARCHAR(100) NOT NULL,
    booking_date DATE NOT NULL,
    valuta_date DATE NOT NULL,
    type VARCHAR(100) NULL,
    remitter VARCHAR(100) NULL,
    debitor  VARCHAR(100) NULL,
    creditor VARCHAR(100) NULL,
    end_to_end_reference VARCHAR(50) NULL,
    dc_creditor_id VARCHAR(50) NULL,
    dc_mandate_id VARCHAR(50) NULL,
    remittance_info TEXT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    matched_rule_id INT NULL,
    contract_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_account_transaction (account_id, transaction_id),
    FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (matched_rule_id) REFERENCES bank_tag_rules(id) ON DELETE SET NULL,
    FOREIGN KEY (contract_id) REFERENCES bank_contracts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Kreditkarten-Kategorien (bestehend für Visa-Abrechnungen)
CREATE TABLE IF NOT EXISTS bank_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Kopfdaten der Kreditkarten-Monatsabrechnung (Visa PDF)
CREATE TABLE IF NOT EXISTS bank_cc_statements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    statement_date DATE NOT NULL,
    due_date DATE NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    pdf_filename VARCHAR(255) NULL,
    reference_iban_suffix VARCHAR(8) NULL,
    bank_transaction_id INT NULL, 
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (bank_transaction_id) REFERENCES bank_giro_transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Einzelpositionen der Kreditkartenabrechnung
CREATE TABLE IF NOT EXISTS bank_cc_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    statement_id INT UNSIGNED NOT NULL,
    booking_date DATE NOT NULL,
    valuta_date DATE NULL,
    card_number_suffix VARCHAR(8) NULL,
    merchant_name VARCHAR(255) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    category_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (statement_id) REFERENCES bank_cc_statements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Universelle Tags & Zuordnungen (Girokonto)
CREATE TABLE IF NOT EXISTS bank_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    color VARCHAR(7) DEFAULT '#3b82f6',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_transaction_tags (
    transaction_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (transaction_id, tag_id),
    FOREIGN KEY (transaction_id) REFERENCES bank_giro_transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES bank_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Regel-Gedächtnis für Girokonto Vorkategorisierung
CREATE TABLE IF NOT EXISTS bank_tag_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payee_pattern VARCHAR(100) NULL,
    text_pattern VARCHAR(100) NULL,
    tag_ids JSON NOT NULL,
    priority INT DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Verträge, Abos, Abgaben und Kredite
CREATE TABLE IF NOT EXISTS bank_contracts (
                                              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                              name VARCHAR(255) NOT NULL,
    direction ENUM('expense', 'income') NOT NULL DEFAULT 'expense',
    type ENUM('vertrag', 'abo', 'abgabe', 'kredit', 'arbeitsvertrag', 'kindergeld', 'unterhalt') NOT NULL DEFAULT 'vertrag',
    status ENUM('aktiv', 'pausiert', 'gekuendigt', 'beendet') NOT NULL DEFAULT 'aktiv',

    -- Identifikation aus Buchungen
    auftraggeber VARCHAR(255) NULL,
    mandatsnummer VARCHAR(100) NULL,
    iban VARCHAR(34) NULL,

    -- Finanzielle Details & Rhythmus
    betrag DECIMAL(10, 2) NOT NULL,
    frequenz ENUM('monatlich', 'vierteljaehrlich', 'halbjaehrlich', 'jaehrlich', 'einmalig') NOT NULL DEFAULT 'monatlich',
    variabel TINYINT(1) NOT NULL DEFAULT 0,

    -- Zeitfenster & Prognose
    start_datum DATE NULL,
    end_datum DATE NULL,

    -- Verknüpfung zu bestehenden Kategorien
    category_id INT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (category_id) REFERENCES bank_categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Regeln zur automatischen/manuellen Zuordnung von Buchungen zu Verträgen
CREATE TABLE IF NOT EXISTS bank_contract_rules (
                                                   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                                   contract_id INT UNSIGNED NOT NULL,
                                                   pattern_type ENUM('regex', 'exact_match', 'substring') NOT NULL DEFAULT 'substring',
    pattern_value VARCHAR(255) NOT NULL,
    priority INT NOT NULL DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (contract_id) REFERENCES bank_contracts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Persistierte Standardisierte KI-Finanzreports
CREATE TABLE IF NOT EXISTS bank_financial_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_type ENUM('month', 'year') NOT NULL,
    period_target VARCHAR(10) NOT NULL,
    period_reference VARCHAR(20) NOT NULL,
    aggregated_data LONGTEXT NOT NULL,
    ai_analysis LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_period (period_type, period_target)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `event_type` VARCHAR(50) NOT NULL,
  `message` VARCHAR(255) NOT NULL,
  `link_url` VARCHAR(255) DEFAULT NULL,
  `entity_id` INT DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_event_type` (`event_type`),
  INDEX `idx_is_read` (`is_read`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kb_receipts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bank_giro_transaction_id` INT NULL DEFAULT NULL,
  `bank_cc_transaction_id` INT UNSIGNED NULL DEFAULT NULL,
  `shopping_session_id` INT NULL DEFAULT NULL,
  `file_hash` varchar(64) DEFAULT NULL,
  `store` varchar(255) NOT NULL,
  `purchase_date` date NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_file_hash` (`file_hash`),
  FOREIGN KEY (`bank_giro_transaction_id`) REFERENCES `bank_giro_transactions`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`bank_cc_transaction_id`) REFERENCES `bank_cc_transactions`(`id`) ON DELETE SET NULL,
  INDEX `idx_shopping_session_id` (`shopping_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kb_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT 1.000,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `category` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `receipt_id` (`receipt_id`),
  KEY `idx_category` (`category`),
  CONSTRAINT `kb_items_ibfk_1` FOREIGN KEY (`receipt_id`) REFERENCES `kb_receipts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key` VARCHAR(100) PRIMARY KEY,
    `setting_value` TEXT NOT NULL,
	`label` VARCHAR(100) NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- DOMAIN: USER PROFILES & NOTIFICATIONS (Benutzer & Benachrichtigungen)
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `user_profiles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_email` VARCHAR(255) NOT NULL UNIQUE,
    `notification_preferences` JSON DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_email` (`user_email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_email` VARCHAR(255) NOT NULL,
    `endpoint`   VARCHAR(2048) NOT NULL,
    `p256dh`     VARCHAR(512) NOT NULL,
    `auth`       VARCHAR(256) NOT NULL,
    `user_agent` VARCHAR(512) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_push_user_email` (`user_email`),
    UNIQUE KEY `uq_push_endpoint` (`endpoint`(500))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- DOMAIN: EINKAUFSLISTE (Intelligente Einkaufsliste mit 2-Märkte-Splitting)
-- ==========================================================================

-- 1. Markt- und Gang-Sortierung
CREATE TABLE IF NOT EXISTS `market_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `market` VARCHAR(50) NOT NULL,
    `category_name` VARCHAR(100) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_market_cat` (`market`, `category_name`),
    INDEX `idx_market_sort` (`market`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial-Gänge (Einheitlich für Rewe & Globus) eintragen, sofern die Tabelle leer ist
INSERT IGNORE INTO `market_categories` (`market`, `category_name`, `sort_order`) VALUES
('Rewe', 'Brot & Backwaren', 10),
('Rewe', 'Obst & Gemüse', 20),
('Rewe', 'Frischetheke (Fleisch & Wurst, Käse)', 30),
('Rewe', 'Molkereiprodukte & Eier', 40),
('Rewe', 'Gewürze, Öle & Fertiggerichte', 50),
('Rewe', 'Müsli, Brotaufstriche & Kaffee/Tee', 60),
('Rewe', 'Nudeln & Reis', 65),
('Rewe', 'Konserven', 70),
('Rewe', 'Süßwaren & Knabberartikel', 80),
('Rewe', 'Drogerie', 90),
('Rewe', 'Haushalt', 100),
('Rewe', 'Getränke', 110),
('Rewe', 'Spirituosen', 115),
('Rewe', 'Tiefkühlkost', 120),
('Rewe', 'Sonstiges', 130),
('Globus', 'Brot & Backwaren', 10),
('Globus', 'Obst & Gemüse', 20),
('Globus', 'Frischetheke (Fleisch & Wurst, Käse)', 30),
('Globus', 'Molkereiprodukte & Eier', 40),
('Globus', 'Gewürze, Öle & Fertiggerichte', 50),
('Globus', 'Müsli, Brotaufstriche & Kaffee/Tee', 60),
('Globus', 'Nudeln & Reis', 65),
('Globus', 'Konserven', 70),
('Globus', 'Süßwaren & Knabberartikel', 80),
('Globus', 'Drogerie', 90),
('Globus', 'Haushalt', 100),
('Globus', 'Getränke', 110),
('Globus', 'Spirituosen', 115),
('Globus', 'Tiefkühlkost', 120),
('Globus', 'Sonstiges', 130);

-- 2. Artikelstamm & Markt-Zuordnung (Lernendes System)
CREATE TABLE IF NOT EXISTS `product_master` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `custom_label` VARCHAR(255) NULL DEFAULT NULL,
    `preferred_market` VARCHAR(50) NOT NULL DEFAULT 'Rewe',
    `default_category` VARCHAR(100) NULL,
    `default_unit` VARCHAR(50) DEFAULT 'Stück',
    `avg_interval_days` DECIMAL(5, 1) NULL,
    `last_purchased_at` DATE NULL,
    `holiday_factor` DECIMAL(3, 2) NOT NULL DEFAULT 1.00,
    `is_ignored` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_product_name` (`name`),
    INDEX `idx_preferred_market` (`preferred_market`),
    INDEX `idx_last_purchased` (`last_purchased_at`),
    INDEX `idx_is_ignored` (`is_ignored`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Aktive Einkaufsliste
CREATE TABLE IF NOT EXISTS `shopping_list_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NULL,
    `name` VARCHAR(255) NOT NULL,
    `quantity` DECIMAL(8, 2) NOT NULL DEFAULT 1.00,
    `unit` VARCHAR(50) NULL DEFAULT 'Stück',
    `market` VARCHAR(50) NOT NULL DEFAULT 'Rewe',
    `category` VARCHAR(100) NULL,
    `note` VARCHAR(255) NULL,
    `is_spontaneous` TINYINT(1) NOT NULL DEFAULT 0,
    `source` ENUM('manual', 'suggestion', 'recipe', 'spontaneous') NOT NULL DEFAULT 'manual',
    `is_checked` TINYINT(1) NOT NULL DEFAULT 0,
    `checked_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `product_master`(`id`) ON DELETE SET NULL,
    INDEX `idx_market_checked` (`market`, `is_checked`),
    INDEX `idx_checked` (`is_checked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Aktive Einkaufs-Sessions
CREATE TABLE IF NOT EXISTS `shopping_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_type` ENUM('wocheneinkauf', 'spontaneinkauf') NOT NULL DEFAULT 'wocheneinkauf',
    `status` ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    `notes` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Historisierte Artikel abgeschlossener Einkäufe (Session Items)
CREATE TABLE IF NOT EXISTS `shopping_session_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_id` INT NOT NULL,
    `product_id` INT NULL,
    `name` VARCHAR(255) NOT NULL,
    `quantity` DECIMAL(8, 2) NOT NULL DEFAULT 1.00,
    `unit` VARCHAR(50) NULL DEFAULT 'Stück',
    `market` VARCHAR(50) NOT NULL DEFAULT 'Rewe',
    `category` VARCHAR(100) NULL,
    `is_spontaneous` TINYINT(1) NOT NULL DEFAULT 0,
    `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`session_id`) REFERENCES `shopping_sessions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `product_master`(`id`) ON DELETE SET NULL,
    INDEX `idx_session_id` (`session_id`),
    INDEX `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. eBon-Produktzuordnung (Mapping: Rohname aus Kassenbon → Master-Artikel)
CREATE TABLE IF NOT EXISTS `ebon_product_mappings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ebon_name` VARCHAR(255) NOT NULL COMMENT 'Rohname aus dem Kassenbon (kb_items.name)',
    `product_master_id` INT NOT NULL COMMENT 'Zugewiesener Master-Artikel',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_ebon_name` (`ebon_name`),
    FOREIGN KEY (`product_master_id`) REFERENCES `product_master`(`id`) ON DELETE CASCADE,
    INDEX `idx_product_master_id` (`product_master_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Sächsische Schulferien (zur automatischen Anpassung von Bedarfs- und Mengenvorschlägen)
CREATE TABLE IF NOT EXISTS `school_holidays` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `state_code` VARCHAR(10) NOT NULL DEFAULT 'SN',
    `year` INT NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_holiday_period` (`state_code`, `name`, `start_date`),
    INDEX `idx_holiday_dates` (`state_code`, `start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- DOMAIN: WEATHER (Wettermodul)
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `weather_cache` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `data_type` VARCHAR(50) NOT NULL,
    `payload` JSON NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_data_type` (`data_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weather_sensor_live` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `temperature_c` DECIMAL(4,1) NULL,
    `soil_moisture_pct` INT NULL,
    `wind_kmh` DECIMAL(4,1) NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ==========================================================================
-- WEATHER MODEL TABLES (ICON / ECMWF)
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `weather_state` (
    `id` INT PRIMARY KEY DEFAULT 1,
    `temperature` DECIMAL(4,1),
    `weather_code` INT,
    `wind_speed` DECIMAL(4,1),
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weather_forecast_daily` (
    `forecast_date` DATE PRIMARY KEY,
    `temperature_max` DECIMAL(4,1),
    `temperature_min` DECIMAL(4,1),
    `weather_code` INT,
    `precipitation_sum` DECIMAL(5,2),
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weather_forecast_hourly` (
    `forecast_time` DATETIME PRIMARY KEY,
    `temperature` DECIMAL(4,1),
    `precipitation_probability` INT,
    `precipitation` DECIMAL(5,2),
    `weather_code` INT,
    `wind_speed` DECIMAL(4,1),
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `weather_cache`;
DROP TABLE IF EXISTS weather_state;
DROP TABLE IF EXISTS weather_forecast_daily;
DROP TABLE IF EXISTS weather_forecast_hourly;
CREATE TABLE IF NOT EXISTS `weather_state` (
    `id` INT PRIMARY KEY DEFAULT 1,
    `temperature_2m` DECIMAL(10,2),
    `relative_humidity_2m` DECIMAL(10,2),
    `is_day` INT,
    `apparent_temperature` DECIMAL(10,2),
    `precipitation` DECIMAL(10,2),
    `showers` DECIMAL(10,2),
    `rain` DECIMAL(10,2),
    `snowfall` DECIMAL(10,2),
    `weather_code` INT,
    `cloud_cover` DECIMAL(10,2),
    `surface_pressure` DECIMAL(10,2),
    `wind_gusts_10m` DECIMAL(10,2),
    `wind_direction_10m` DECIMAL(10,2),
    `wind_speed_10m` DECIMAL(10,2),
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weather_forecast_daily` (
    `forecast_date` DATE PRIMARY KEY,
    `weather_code` INT,
    `temperature_2m_max` DECIMAL(10,2),
    `temperature_2m_min` DECIMAL(10,2),
    `apparent_temperature_max` DECIMAL(10,2),
    `apparent_temperature_min` DECIMAL(10,2),
    `uv_index_max` DECIMAL(10,2),
    `sunrise` DATETIME,
    `sunset` DATETIME,
    `daylight_duration` DECIMAL(10,2),
    `sunshine_duration` DECIMAL(10,2),
    `moonrise` DATETIME,
    `moonset` DATETIME,
    `moon_phase` DECIMAL(10,2),
    `rain_sum` DECIMAL(10,2),
    `showers_sum` DECIMAL(10,2),
    `snowfall_sum` DECIMAL(10,2),
    `precipitation_sum` DECIMAL(10,2),
    `precipitation_hours` DECIMAL(10,2),
    `precipitation_probability_max` INT,
    `wind_speed_10m_max` DECIMAL(10,2),
    `wind_gusts_10m_max` DECIMAL(10,2),
    `wind_direction_10m_dominant` DECIMAL(10,2),
    `shortwave_radiation_sum` DECIMAL(10,2),
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weather_forecast_hourly` (
    `forecast_time` DATETIME PRIMARY KEY,
    `temperature_2m` DECIMAL(10,2),
    `relative_humidity_2m` DECIMAL(10,2),
    `dew_point_2m` DECIMAL(10,2),
    `apparent_temperature` DECIMAL(10,2),
    `precipitation_probability` INT,
    `precipitation` DECIMAL(10,2),
    `rain` DECIMAL(10,2),
    `showers` DECIMAL(10,2),
    `snowfall` DECIMAL(10,2),
    `snow_depth` DECIMAL(10,2),
    `weather_code` INT,
    `cloud_cover` DECIMAL(10,2),
    `surface_pressure` DECIMAL(10,2),
    `visibility` DECIMAL(10,2),
    `evapotranspiration` DECIMAL(10,2),
    `wind_speed_10m` DECIMAL(10,2),
    `wind_direction_10m` DECIMAL(10,2),
    `wind_gusts_10m` DECIMAL(10,2),
    `soil_temperature_0cm` DECIMAL(10,2),
    `soil_moisture_0_to_1cm` DECIMAL(10,2),
    `uv_index` DECIMAL(10,2),
    `sunshine_duration` DECIMAL(10,2),
    `total_column_integrated_water_vapour` DECIMAL(10,2),
    `cape` DECIMAL(10,2),
    `lifted_index` DECIMAL(10,2),
    `convective_inhibition` DECIMAL(10,2),
    `freezing_level_height` DECIMAL(10,2),
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ==========================================================================
-- DOMAIN: SYSTEM (Roles & Permissions)
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `users` (
    `email` VARCHAR(255) PRIMARY KEY,
    `name` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groups` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `group_permissions` (
    `group_id` INT NOT NULL,
    `permission` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`group_id`, `permission`),
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_groups` (
    `user_email` VARCHAR(255) NOT NULL,
    `group_id` INT NOT NULL,
    PRIMARY KEY (`user_email`, `group_id`),
    FOREIGN KEY (`user_email`) REFERENCES `users`(`email`) ON DELETE CASCADE,
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- DOMAIN: SCHOOL (Vertretungsplan & Schüler-Verwaltung)
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `school_students` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `class_name` VARCHAR(20) NOT NULL,
    `beste_schule_id` VARCHAR(50) NULL,
    `excluded_subjects` VARCHAR(255) NULL,
    `user_email` VARCHAR(255) NULL,
    `display_color` VARCHAR(20) NOT NULL DEFAULT '#2563eb',
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_email` (`user_email`),
    INDEX `idx_class_name` (`class_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_plans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `plan_date` DATE NOT NULL UNIQUE,
    `plan_timestamp` VARCHAR(100) NULL,
    `school_week` VARCHAR(20) NULL,
    `raw_hash` VARCHAR(64) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_plan_date` (`plan_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_plan_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `plan_date` DATE NOT NULL,
    `class_name` VARCHAR(20) NOT NULL,
    `lesson_number` INT NOT NULL,
    `start_time` VARCHAR(10) NOT NULL,
    `end_time` VARCHAR(10) NOT NULL,
    `subject` VARCHAR(50) NOT NULL,
    `subject_original` VARCHAR(50) NULL,
    `teacher` VARCHAR(50) NULL,
    `teacher_original` VARCHAR(50) NULL,
    `room` VARCHAR(50) NULL,
    `room_original` VARCHAR(50) NULL,
    `course_group` VARCHAR(50) NULL,
    `info` TEXT NULL,
    `is_cancelled` TINYINT(1) NOT NULL DEFAULT 0,
    `is_substitution` TINYINT(1) NOT NULL DEFAULT 0,
    `is_room_change` TINYINT(1) NOT NULL DEFAULT 0,
    `is_moved` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`plan_date`) REFERENCES `school_plans`(`plan_date`) ON DELETE CASCADE,
    INDEX `idx_plan_date_class` (`plan_date`, `class_name`),
    INDEX `idx_lesson_number` (`lesson_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_global_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `plan_date` DATE NOT NULL,
    `note_text` TEXT NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`plan_date`) REFERENCES `school_plans`(`plan_date`) ON DELETE CASCADE,
    INDEX `idx_note_plan_date` (`plan_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==========================================================================
-- Domain: Beste Schule (Noten, Fehlzeiten, Journal)
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `school_beste_grades` (
    `id` INT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `collection_name` VARCHAR(255) NOT NULL,
    `grade_value` VARCHAR(50) NOT NULL,
    `given_at` DATE NOT NULL,
    `read_status` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`student_id`) REFERENCES `school_students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_beste_absences` (
    `id` INT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `from_time` DATETIME NOT NULL,
    `to_time` DATETIME NOT NULL,
    `absence_type` VARCHAR(100) NOT NULL,
    `is_unexcused` TINYINT(1) NOT NULL DEFAULT 0,
    `note` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`student_id`) REFERENCES `school_students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_beste_journal` (
    `id` INT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `lesson_date` DATE NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `missing_homework` TINYINT(1) NOT NULL DEFAULT 0,
    `missing_equipment` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`student_id`) REFERENCES `school_students`(`id`) ON DELETE CASCADE,
    INDEX `idx_journal_lesson_date` (`lesson_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `school_beste_notes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` INT NOT NULL,
    `lesson_date` DATE NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `type_name` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `api_note_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_student_note` (`student_id`, `api_note_id`),
    FOREIGN KEY (`student_id`) REFERENCES `school_students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- DOMAIN: GAMIFICATION (Familien-Quests & Aufgaben-Ökosystem)
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `gamification_profiles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_email` VARCHAR(255) NOT NULL UNIQUE,
    `display_name` VARCHAR(100) NOT NULL,
    `role` ENUM('parent', 'child') NOT NULL DEFAULT 'child',
    `avatar_icon` VARCHAR(50) NOT NULL DEFAULT '⭐',
    `color` VARCHAR(20) NOT NULL DEFAULT '#3b82f6',
    `xp` INT UNSIGNED NOT NULL DEFAULT 0,
    `coins` INT UNSIGNED NOT NULL DEFAULT 0,
    `streak_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `streak_shields` INT UNSIGNED NOT NULL DEFAULT 0,
    `streak_freeze_until` DATE NULL,
    `streak_freeze_reason` VARCHAR(100) NULL,
    `last_completed_date` DATE NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_email`) REFERENCES `users`(`email`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gamification_task_templates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `category` VARCHAR(50) NOT NULL DEFAULT 'haushalt',
    `base_xp` INT UNSIGNED NOT NULL DEFAULT 50,
    `base_coins` INT UNSIGNED NOT NULL DEFAULT 20,
    `recurrence` ENUM('none', 'daily', 'weekly', 'interval') NOT NULL DEFAULT 'none',
    `recurrence_days` VARCHAR(50) NULL,
    `due_time` TIME NULL,
    `assigned_profile_id` INT NULL,
    `is_cooking_day` TINYINT(1) NOT NULL DEFAULT 0,
    `can_escalate` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`assigned_profile_id`) REFERENCES `gamification_profiles`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gamification_tasks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `template_id` INT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `category` VARCHAR(50) NOT NULL DEFAULT 'haushalt',
    `assigned_profile_id` INT NULL,
    `origin_profile_id` INT NULL,
    `status` ENUM('planned', 'in_progress', 'submitted', 'in_review', 'completed', 'escalated', 'rejected', 'cancelled') NOT NULL DEFAULT 'planned',
    `is_bounty` TINYINT(1) NOT NULL DEFAULT 0,
    `can_escalate` TINYINT(1) NOT NULL DEFAULT 1,
    `bounty_bonus_coins` INT UNSIGNED NOT NULL DEFAULT 0,
    `bounty_bonus_xp` INT UNSIGNED NOT NULL DEFAULT 0,
    `claimed_by_profile_id` INT NULL,
    `claimed_at` DATETIME NULL,
    `due_date` DATE NULL,
    `due_time` TIME NULL,
    `base_xp` INT UNSIGNED NOT NULL DEFAULT 50,
    `base_coins` INT UNSIGNED NOT NULL DEFAULT 20,
    `planning_bonus_coins` INT UNSIGNED NOT NULL DEFAULT 0,
    `initiative_bonus_coins` INT UNSIGNED NOT NULL DEFAULT 0,
    `final_xp` INT UNSIGNED NOT NULL DEFAULT 0,
    `final_coins` INT UNSIGNED NOT NULL DEFAULT 0,
    `submission_notes` TEXT NULL,
    `rejection_reason` TEXT NULL,
    `parent_feedback` TEXT NULL,
    `is_cooking_day` TINYINT(1) NOT NULL DEFAULT 0,
    `recipe_title` VARCHAR(255) NULL,
    `recipe_details` TEXT NULL,
    `recipe_status` ENUM('none', 'pitched', 'approved', 'rejected') NOT NULL DEFAULT 'none',
    `submitted_at` DATETIME NULL,
    `reviewed_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`template_id`) REFERENCES `gamification_task_templates`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`assigned_profile_id`) REFERENCES `gamification_profiles`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`origin_profile_id`) REFERENCES `gamification_profiles`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`claimed_by_profile_id`) REFERENCES `gamification_profiles`(`id`) ON DELETE SET NULL,
    INDEX `idx_gamif_status_date` (`status`, `due_date`),
    INDEX `idx_gamif_bounty` (`is_bounty`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gamification_task_helpers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT NOT NULL,
    `helper_profile_id` INT NOT NULL,
    `bonus_xp` INT UNSIGNED NOT NULL DEFAULT 25,
    `bonus_coins` INT UNSIGNED NOT NULL DEFAULT 10,
    `status` ENUM('pending', 'confirmed', 'rejected') NOT NULL DEFAULT 'pending',
    `note` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`task_id`) REFERENCES `gamification_tasks`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`helper_profile_id`) REFERENCES `gamification_profiles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gamification_ratings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT NOT NULL,
    `rater_profile_id` INT NOT NULL,
    `rating_stars` TINYINT UNSIGNED NOT NULL,
    `comment` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_gamif_task_rater` (`task_id`, `rater_profile_id`),
    FOREIGN KEY (`task_id`) REFERENCES `gamification_tasks`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`rater_profile_id`) REFERENCES `gamification_profiles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gamification_achievements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `key_name` VARCHAR(100) NOT NULL UNIQUE,
    `title` VARCHAR(150) NOT NULL,
    `description` TEXT NOT NULL,
    `icon` VARCHAR(50) NOT NULL DEFAULT '🏆',
    `metric_type` ENUM('rescue_count', 'task_count', 'category_count', 'streak_days', 'cooking_rating_avg', 'initiative_count', 'xp_total') NOT NULL,
    `metric_target` INT UNSIGNED NOT NULL,
    `metric_parameter` VARCHAR(100) NULL,
    `reward_xp` INT UNSIGNED NOT NULL DEFAULT 100,
    `reward_coins` INT UNSIGNED NOT NULL DEFAULT 50,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gamification_profile_achievements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `profile_id` INT NOT NULL,
    `achievement_id` INT NOT NULL,
    `unlocked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_gamif_profile_achievement` (`profile_id`, `achievement_id`),
    FOREIGN KEY (`profile_id`) REFERENCES `gamification_profiles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`achievement_id`) REFERENCES `gamification_achievements`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gamification_rewards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `coin_cost` INT UNSIGNED NOT NULL,
    `icon` VARCHAR(50) NOT NULL DEFAULT '🎁',
    `type` ENUM('voucher', 'privilege', 'allowance', 'event', 'item') NOT NULL DEFAULT 'privilege',
    `min_age` INT UNSIGNED NULL,
    `cooldown_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gamification_redemptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `reward_id` INT NOT NULL,
    `profile_id` INT NOT NULL,
    `coin_cost` INT UNSIGNED NOT NULL,
    `status` ENUM('requested', 'approved', 'rejected', 'fulfilled') NOT NULL DEFAULT 'requested',
    `request_note` TEXT NULL,
    `parent_note` TEXT NULL,
    `reviewed_by_email` VARCHAR(255) NULL,
    `reviewed_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`reward_id`) REFERENCES `gamification_rewards`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`profile_id`) REFERENCES `gamification_profiles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gamification_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `profile_id` INT NOT NULL,
    `amount_xp` INT NOT NULL DEFAULT 0,
    `amount_coins` INT NOT NULL DEFAULT 0,
    `reason` VARCHAR(255) NOT NULL,
    `reference_type` VARCHAR(50) NULL,
    `reference_id` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`profile_id`) REFERENCES `gamification_profiles`(`id`) ON DELETE CASCADE,
    INDEX `idx_gamif_tx_profile` (`profile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial-Stammdaten für Erfolge / Abzeichen
INSERT IGNORE INTO `gamification_achievements` (`key_name`, `title`, `description`, `icon`, `metric_type`, `metric_target`, `metric_parameter`, `reward_xp`, `reward_coins`) VALUES
('rescue_hero', 'Die Feuerwehr', 'Rette mindestens 1 überfällige Aufgabe eines Geschwisterkinds', '🚒', 'rescue_count', 1, NULL, 100, 50),
('rescue_master', 'Retter in der Not', 'Rette 5 überfällige Aufgaben vom Schwarzen Brett', '🦸', 'rescue_count', 5, NULL, 250, 150),
('streak_3', 'Am Ball bleiben', 'Erledige an 3 Tagen in Folge Aufgaben ohne Versäumnis', '🔥', 'streak_days', 3, NULL, 150, 50),
('streak_7', 'Wochen-Champion', 'Erledige an 7 Tagen in Folge Aufgaben ohne Versäumnis', '⚡', 'streak_days', 7, NULL, 300, 100),
('initiative_starter', 'Macher-Geist', 'Reiche deine erste Spontan-Hilfe selbstständig ein', '💡', 'initiative_count', 1, NULL, 100, 50),
('cooking_star', 'Sternekoch', 'Koche ein Familienessen mit einem Schnitt von mind. 4 Sternen', '👨‍🍳', 'cooking_rating_avg', 4, NULL, 200, 100),
('task_10', 'Fleißiges Bienchen', 'Erledige insgesamt 10 Aufgaben erfolgreich', '🐝', 'task_count', 10, NULL, 200, 100);

-- Initial-Stammdaten für Belohnungen
INSERT IGNORE INTO `gamification_rewards` (`title`, `description`, `coin_cost`, `icon`, `type`, `cooldown_days`) VALUES
('30 Min. extra Bildschirmzeit', 'Einlösbar nach Absprache für Tablet, Konsole oder PC', 100, '📱', 'privilege', 1),
('Wunsch-Essen am Wochenende', 'Du bestimmst, was samstags oder sonntags gekocht wird', 150, '🍕', 'privilege', 7),
('Ausflugsziel aussuchen', 'Gemeinsamer Familienausflug an einen Ort deiner Wahl', 300, '🎢', 'event', 14),
('5 € Taschengeld-Zuschuss', 'Direkte Auszahlung auf dein Taschengeld', 250, '💶', 'allowance', 14);

