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
    `charging_state` VARCHAR(20) NOT NULL,
    `plug_connected` TINYINT(1) NOT NULL,
    `is_locked` TINYINT(1) NOT NULL,
    `mileage_km` INT NOT NULL,
    `range_km` INT NOT NULL,
    `outdoor_temp_c` DECIMAL(4, 1) NOT NULL,
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
    `raw_payload` LONGTEXT NOT NULL,
    INDEX `idx_vin_timestamp` (`vin`, `timestamp`)
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
  `file_hash` varchar(64) DEFAULT NULL,
  `store` varchar(255) NOT NULL,
  `purchase_date` date NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_file_hash` (`file_hash`),
  FOREIGN KEY (`bank_giro_transaction_id`) REFERENCES `bank_giro_transactions`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`bank_cc_transaction_id`) REFERENCES `bank_cc_transactions`(`id`) ON DELETE SET NULL
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

-- 2. Artikelstamm & Markt-Zuordnung (Lernendes System)
CREATE TABLE IF NOT EXISTS `product_master` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `preferred_market` VARCHAR(50) NOT NULL DEFAULT 'Rewe',
    `default_category` VARCHAR(100) NULL,
    `default_unit` VARCHAR(50) DEFAULT 'Stück',
    `avg_interval_days` DECIMAL(5, 1) NULL,
    `last_purchased_at` DATE NULL,
    `holiday_factor` DECIMAL(3, 2) NOT NULL DEFAULT 1.00,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_product_name` (`name`),
    INDEX `idx_preferred_market` (`preferred_market`),
    INDEX `idx_last_purchased` (`last_purchased_at`)
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

-- 4. Sächsische Schulferien (zur automatischen Anpassung von Bedarfs- und Mengenvorschlägen)
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

