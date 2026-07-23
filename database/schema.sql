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
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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