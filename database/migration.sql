-- Inkrementelle Migration für ebon_product_mappings
ALTER TABLE ebon_product_mappings MODIFY COLUMN product_master_id INT NULL DEFAULT NULL COMMENT 'Zugewiesener Master-Artikel (NULL = ignoriert)';

-- Kalender / Geburtstage & Jahrestage
CREATE TABLE IF NOT EXISTS `calendar_events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(150) NOT NULL,
    `event_type` ENUM('birthday', 'anniversary', 'memorial', 'other') NOT NULL DEFAULT 'birthday',
    `event_day` TINYINT UNSIGNED NOT NULL,
    `event_month` TINYINT UNSIGNED NOT NULL,
    `event_year` SMALLINT UNSIGNED NULL,
    `category` VARCHAR(50) NOT NULL DEFAULT 'Familie',
    `notify_days_advance` VARCHAR(100) NOT NULL DEFAULT '0,1,3',
    `notes` TEXT NULL,
    `created_by` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_calendar_month_day` (`event_month`, `event_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendar_event_notifications` (
    `event_id` INT NOT NULL,
    `user_email` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`event_id`, `user_email`),
    INDEX `idx_cal_notif_email` (`user_email`),
    FOREIGN KEY (`event_id`) REFERENCES `calendar_events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calendar_notification_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `user_email` VARCHAR(255) NOT NULL,
    `target_year` SMALLINT UNSIGNED NOT NULL,
    `days_advance` INT NOT NULL,
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_notif_log` (`event_id`, `user_email`, `target_year`, `days_advance`),
    INDEX `idx_cal_log_user` (`user_email`),
    FOREIGN KEY (`event_id`) REFERENCES `calendar_events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 
-- Finanzverträge: Fälligkeitstag (Tag im Monat 1-31)
ALTER TABLE bank_contracts ADD COLUMN faelligkeitstag TINYINT UNSIGNED NULL AFTER frequenz;

-- Bestehende Verträge mit dem Fälligkeitstag der letzten zugeordneten Buchung initialisieren
UPDATE bank_contracts c
JOIN (
    SELECT contract_id, DAY(MAX(booking_date)) AS last_day
    FROM bank_giro_transactions
    WHERE contract_id IS NOT NULL
    GROUP BY contract_id
) b ON c.id = b.contract_id
SET c.faelligkeitstag = b.last_day;
