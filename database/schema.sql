-- Schema for PV charging management

CREATE TABLE IF NOT EXISTS `pv_charging_plans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `empfehlung_text` TEXT NOT NULL,
    `lade_fenster` JSON NOT NULL,
    `naechste_pruefung_empfohlen` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
