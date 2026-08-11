-- Schema for PV charging management

CREATE TABLE IF NOT EXISTS `pv_charging_plans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `empfehlung_text` TEXT NOT NULL,
    `lade_fenster` JSON NOT NULL,
    `naechste_pruefung_empfohlen` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
	`real_watt_hours_day` INT DEFAULT NULL,
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE pv_car_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    google_event_id VARCHAR(255) UNIQUE, -- Verhindert doppelte Importe beim Synch
    summary VARCHAR(255) NOT NULL,       -- Titel des Termins (z.B. "Anreise Usedom")
    location VARCHAR(255) NULL,          -- Zukünftiges Feature-Feld für die Zieladresse
    start_time DATETIME NOT NULL,        -- Ab wann das Auto weg ist (oder Lade-Start)
    end_time DATETIME NOT NULL,          -- Bis wann das Auto weg ist (oder Abfahrt)
    status_type ENUM('away', 'charge_target') NOT NULL, -- 'away' = Wallbox sperren, 'charge_target' = Volladen
    target_soc INT NULL,                 -- Der geparste Wunsch-SoC (z.B. 100 oder 80)
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);


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
	`estimated_finish_at` datetime DEFAULT NULL,
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

CREATE TABLE IF NOT EXISTS `kb_receipts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_hash` varchar(64) DEFAULT NULL,
  `store` varchar(255) NOT NULL,
  `purchase_date` date NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_file_hash` (`file_hash`)
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
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


ALTER TABLE `kb_items`
  ADD CONSTRAINT `kb_items_ibfk_1` FOREIGN KEY (`receipt_id`) REFERENCES `kb_receipts` (`id`) ON DELETE CASCADE;
COMMIT;

-- 1. Stammdaten für Konten (Giro, Tagesgeld, Kreditkarte, etc.)
CREATE TABLE IF NOT EXISTS `bank_accounts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `account_name` VARCHAR(100) NOT NULL,            -- z.B. "Hausbank Giro", "Visa Card", "Tagesgeld"
  `bank_name` VARCHAR(100) NOT NULL,               -- z.B. "DVK", "DKB", "Sparkasse"
  `account_type` ENUM('checking', 'savings', 'credit_card', 'other') NOT NULL DEFAULT 'checking',
  `iban` VARCHAR(34) NULL,                          -- IBAN (falls vorhanden) zur Auto-Erkennung
  `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 2. Bankumsätze (Girokonto & Tagesgeld aus CSV-Imports)
CREATE TABLE IF NOT EXISTS `bank_transactions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `account_id` INT UNSIGNED NOT NULL,              -- Verweis auf bank_accounts (Giro / Tagesgeld)
  `booking_date` DATE NOT NULL,                     -- Buchungstag
  `valuta_date` DATE NULL,                          -- Wertstellung
  `amount` DECIMAL(10, 2) NOT NULL,                 -- Betrag (+ / -)
  `applicant_name` VARCHAR(255) NULL,              -- Empfänger / Empfängerin / Auftraggeber
  `applicant_iban` VARCHAR(34) NULL,
  `purpose` TEXT NULL,                             -- Verwendungszweck
  `source_type` ENUM('csv_import', 'manual') NOT NULL DEFAULT 'csv_import',
  `hash` VARCHAR(64) NOT NULL,                      -- SHA-256 zur Vermeidung von Import-Duplikaten
  `matched_receipt_id` INT UNSIGNED NULL,            -- Verknüpfung direkt zu kb_receipts (falls Girocard)
  `category_id` INT UNSIGNED NULL,                  -- Verweis auf kb_categories
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE CASCADE,
  UNIQUE KEY `uniq_account_hash` (`account_id`, `hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 3. Kopfdaten der Kreditkarten-Monatsabrechnung (Visa PDF)
CREATE TABLE IF NOT EXISTS `bank_cc_statements` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `account_id` INT UNSIGNED NOT NULL,              -- Verweis auf das Kreditkarten-Konto in bank_accounts
  `statement_date` DATE NOT NULL,                  -- Rechnungsdatum / Abrechnungsmonat (z.B. 2026-05-26)
  `due_date` DATE NULL,                            -- Geplantes Einzugsdatum auf dem Girokonto (z.B. 2026-06-02)
  `total_amount` DECIMAL(10, 2) NOT NULL,          -- Gesamtsumme der Abrechnung
  `pdf_filename` VARCHAR(255) NULL,                -- Gespeichertes PDF im storage/
  `reference_iban_suffix` VARCHAR(8) NULL,         -- Letchte Ziffern der Referenz-IBAN für Auto-Matching
  `bank_transaction_id` INT UNSIGNED NULL,         -- Verweis auf die Ausgleichsabbuchung in bank_transactions
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`bank_transaction_id`) REFERENCES `bank_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 4. Einzelpositionen der Kreditkartenabrechnung
CREATE TABLE IF NOT EXISTS `bank_cc_transactions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `statement_id` INT UNSIGNED NOT NULL,             -- Verweis auf den Abrechnungskopf (bank_cc_statements)
  `booking_date` DATE NOT NULL,                     -- Kaufdatum
  `valuta_date` DATE NULL,                          -- Buchungsdatum
  `card_number_suffix` VARCHAR(8) NULL,              -- Kennzeichnung der Sub-Karte (z.B. "*1234")
  `merchant_name` VARCHAR(255) NOT NULL,              -- Händler / Verwendungszweck
  `amount` DECIMAL(10, 2) NOT NULL,                   -- Einzelbetrag
  `category_id` INT UNSIGNED NULL,                    -- Verweis auf deine bestehenden E-Bon-Kategorien (kb_categories)
  `matched_receipt_id` INT UNSIGNED NULL,             -- Verknüpfung direkt zu kb_receipts (falls E-Bon vorhanden)
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`statement_id`) REFERENCES `bank_cc_statements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
