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

-- Händler-Normalisierung für bestehende E-Bons (kb_receipts)
UPDATE kb_receipts SET store = 'REWE' WHERE LOWER(store) LIKE '%rewe%';
UPDATE kb_receipts SET store = 'Globus' WHERE LOWER(store) LIKE '%globus%';
UPDATE kb_receipts SET store = 'Obi' WHERE LOWER(store) LIKE '%obi%';
UPDATE kb_receipts SET store = 'Edeka' WHERE LOWER(store) LIKE '%edeka%' OR LOWER(store) LIKE '%e-center%' OR LOWER(store) LIKE '%e center%';
UPDATE kb_receipts SET store = 'Netto' WHERE LOWER(store) LIKE '%netto%';
UPDATE kb_receipts SET store = 'Lidl' WHERE LOWER(store) LIKE '%lidl%';
UPDATE kb_receipts SET store = 'Aldi' WHERE LOWER(store) LIKE '%aldi%';
UPDATE kb_receipts SET store = 'Penny' WHERE LOWER(store) LIKE '%penny%';
UPDATE kb_receipts SET store = 'Kaufland' WHERE LOWER(store) LIKE '%kaufland%';
UPDATE kb_receipts SET store = 'Flaschenpost' WHERE LOWER(store) LIKE '%flaschenpost%';
UPDATE kb_receipts SET store = 'Fressnapf' WHERE LOWER(store) LIKE '%fressnapf%';
UPDATE kb_receipts SET store = 'dm' WHERE LOWER(store) LIKE '%dm-drogerie%' OR LOWER(store) = 'dm';
UPDATE kb_receipts SET store = 'Rossmann' WHERE LOWER(store) LIKE '%rossmann%';

-- Händler-Normalisierung für Kreditkartenbuchungen (bank_cc_transactions)
UPDATE bank_cc_transactions SET merchant_name = 'REWE' WHERE LOWER(merchant_name) LIKE '%rewe%';
UPDATE bank_cc_transactions SET merchant_name = 'Globus' WHERE LOWER(merchant_name) LIKE '%globus%';
UPDATE bank_cc_transactions SET merchant_name = 'Obi' WHERE LOWER(merchant_name) LIKE '%obi%';
UPDATE bank_cc_transactions SET merchant_name = 'Edeka' WHERE LOWER(merchant_name) LIKE '%edeka%' OR LOWER(merchant_name) LIKE '%e-center%' OR LOWER(merchant_name) LIKE '%e center%';
UPDATE bank_cc_transactions SET merchant_name = 'Netto' WHERE LOWER(merchant_name) LIKE '%netto%';
UPDATE bank_cc_transactions SET merchant_name = 'Lidl' WHERE LOWER(merchant_name) LIKE '%lidl%';
UPDATE bank_cc_transactions SET merchant_name = 'Aldi' WHERE LOWER(merchant_name) LIKE '%aldi%';
UPDATE bank_cc_transactions SET merchant_name = 'Penny' WHERE LOWER(merchant_name) LIKE '%penny%';
UPDATE bank_cc_transactions SET merchant_name = 'Kaufland' WHERE LOWER(merchant_name) LIKE '%kaufland%';
UPDATE bank_cc_transactions SET merchant_name = 'Flaschenpost' WHERE LOWER(merchant_name) LIKE '%flaschenpost%' OR LOWER(merchant_name) LIKE '%flaschenp%';
UPDATE bank_cc_transactions SET merchant_name = 'Fressnapf' WHERE LOWER(merchant_name) LIKE '%fressnapf%';
UPDATE bank_cc_transactions SET merchant_name = 'dm' WHERE LOWER(merchant_name) LIKE '%dm-drogerie%' OR LOWER(merchant_name) = 'dm';
UPDATE bank_cc_transactions SET merchant_name = 'Rossmann' WHERE LOWER(merchant_name) LIKE '%rossmann%';


-- Open Food Facts Cache & Raspi-Queue

CREATE TABLE IF NOT EXISTS `kb_off_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_key` varchar(255) NOT NULL,
  `search_term` varchar(255) NOT NULL,
  `status` enum('pending','completed','not_found','failed') NOT NULL DEFAULT 'pending',
  `code` varchar(64) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `brands` varchar(255) DEFAULT NULL,
  `quantity` varchar(100) DEFAULT NULL,
  `nutriscore_grade` varchar(10) DEFAULT NULL,
  `image_url` text DEFAULT NULL,
  `categories` text DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_queried_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_product_key` (`product_key`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- OFF-Queue: 90-Tage-Cooldown für not_found-Artikel (kein Schema-Change nötig)
-- ---------------------------------------------------------------------------
-- Artikel, die vom Raspi nicht in Open Food Facts gefunden wurden (status = 'not_found'),
-- werden automatisch nach 90 Tagen erneut in die Queue eingereiht (status = 'pending',
-- attempts = 0, last_queried_at = NULL).
--
-- Dieser Mechanismus wird vollständig durch den stündlichen Cron-Job in
-- public/shared/mail.php (Schritt 8) über
--   OpenFoodFactsQueueRepository::enqueueAllPendingItems()
-- gesteuert. Das entsprechende SQL lautet:
--
--   UPDATE kb_off_products
--   SET status = 'pending', attempts = 0, last_queried_at = NULL
--   WHERE status = 'not_found'
--     AND last_queried_at < NOW() - INTERVAL 90 DAY;
--
-- Gleichzeitig reiht derselbe Aufruf alle kb_items-Artikel, die noch gar nicht
-- in kb_off_products vorhanden sind, als neue pending-Jobs ein:
--
--   INSERT IGNORE INTO kb_off_products (product_key, search_term, status)
--   SELECT LOWER(TRIM(ki.name)), ki.name, 'pending'
--   FROM kb_items AS ki
--   LEFT JOIN kb_off_products AS off ON off.product_key = LOWER(TRIM(ki.name))
--   WHERE off.product_key IS NULL AND TRIM(ki.name) != '';
--
-- Es ist kein Datenbankschema-Änderung erforderlich; alle benötigten Spalten
-- (status, attempts, last_queried_at) sind bereits in kb_off_products vorhanden.
-- ---------------------------------------------------------------------------

-- OFF-Produkte: Confidence-Score für Treffsicherheit der Suche (0.00-1.00)
-- Werte: >= 0.60 = zuverlässig, 0.30-0.59 = unsicher, < 0.30 = unzuverlässig
ALTER TABLE `kb_off_products`
    ADD COLUMN IF NOT EXISTS `confidence` DECIMAL(3,2) NULL DEFAULT NULL
    COMMENT 'Aehnlichkeits-Score zwischen Suchbegriff und Produktnamen (0.00-1.00)';

-- OFF-Produkte: Bestehende Einträge mit Händlerpräfix (z. B. 'rewe:::...') auf kanonischen Product-Key bereinigen
UPDATE IGNORE `kb_off_products`
SET `product_key` = SUBSTRING_INDEX(`product_key`, ':::', -1)
WHERE `product_key` LIKE '%:::%';

-- Daily Briefing: Präferenzen für Widgets in user_profiles
ALTER TABLE `user_profiles`
    ADD COLUMN IF NOT EXISTS `briefing_preferences` JSON DEFAULT NULL AFTER `notification_preferences`;

-- Tägliche Weisheit für Kai (Daily Wisdom)
CREATE TABLE IF NOT EXISTS `daily_wisdoms` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `wisdom_date` DATE NOT NULL UNIQUE,
    `content` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fahrzeug-Telemetrie: Spalte charging_state auf VARCHAR(50) erweitern
ALTER TABLE `vehicle_state`
    MODIFY COLUMN `charging_state` VARCHAR(50) NOT NULL DEFAULT 'unknown';

-- Einkaufsliste: Bereinigung verfälschter eBon-Kategorien auf kanonische Markt-Kategorien
UPDATE product_master SET default_category = 'Molkereiprodukte & Eier' WHERE default_category IN ('Milch & Käse', 'Milch und Käse', 'Milch', 'Käse', 'Molkerei', 'Joghurt', 'Butter', 'Eier');
UPDATE product_master SET default_category = 'Brot & Backwaren' WHERE default_category IN ('Brot & Gebäck', 'Brot und Gebäck', 'Backwaren', 'Brot', 'Gebäck', 'Brötchen', 'Bäckerei');
UPDATE product_master SET default_category = 'Frischetheke (Fleisch & Wurst, Käse)' WHERE default_category IN ('Fleisch & Wurst', 'Fleisch und Wurst', 'Fleisch', 'Wurst', 'Fisch', 'Geflügel');
UPDATE product_master SET default_category = 'Müsli, Brotaufstriche & Kaffee/Tee' WHERE default_category IN ('Cerealien', 'Müsli', 'Kaffee & Tee', 'Kaffee/Tee', 'Kaffee', 'Tee', 'Aufstrich', 'Marmelade', 'Honig');
UPDATE product_master SET default_category = 'Süßwaren & Knabberartikel' WHERE default_category IN ('Süßwaren', 'Süßwaren & Snacks', 'Snacks', 'Knabberartikel', 'Knabberzeug', 'Chips', 'Schokolade');
UPDATE product_master SET default_category = 'Drogerie' WHERE default_category IN ('Pflege & Gesundheit', 'Pflege und Gesundheit', 'Pflege', 'Körperpflege', 'Kosmetik', 'Hygiene');
UPDATE product_master SET default_category = 'Haushalt' WHERE default_category IN ('Tierbedarf', 'Tiernahrung', 'Haushaltswaren', 'Reinigung', 'Waschmittel');

UPDATE shopping_list_items SET category = 'Molkereiprodukte & Eier' WHERE category IN ('Milch & Käse', 'Milch und Käse', 'Milch', 'Käse', 'Molkerei', 'Joghurt', 'Butter', 'Eier');
UPDATE shopping_list_items SET category = 'Brot & Backwaren' WHERE category IN ('Brot & Gebäck', 'Brot und Gebäck', 'Backwaren', 'Brot', 'Gebäck', 'Brötchen', 'Bäckerei');
UPDATE shopping_list_items SET category = 'Frischetheke (Fleisch & Wurst, Käse)' WHERE category IN ('Fleisch & Wurst', 'Fleisch und Wurst', 'Fleisch', 'Wurst', 'Fisch', 'Geflügel');
UPDATE shopping_list_items SET category = 'Müsli, Brotaufstriche & Kaffee/Tee' WHERE category IN ('Cerealien', 'Müsli', 'Kaffee & Tee', 'Kaffee/Tee', 'Kaffee', 'Tee', 'Aufstrich', 'Marmelade', 'Honig');
UPDATE shopping_list_items SET category = 'Süßwaren & Knabberartikel' WHERE category IN ('Süßwaren', 'Süßwaren & Snacks', 'Snacks', 'Knabberartikel', 'Knabberzeug', 'Chips', 'Schokolade');
UPDATE shopping_list_items SET category = 'Drogerie' WHERE category IN ('Pflege & Gesundheit', 'Pflege und Gesundheit', 'Pflege', 'Körperpflege', 'Kosmetik', 'Hygiene');
UPDATE shopping_list_items SET category = 'Haushalt' WHERE category IN ('Tierbedarf', 'Tiernahrung', 'Haushaltswaren', 'Reinigung', 'Waschmittel');

-- ---------------------------------------------------------------------------
-- Astronomie: Aktueller Beobachtungsstatus & Himmelsereignisse
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `weather_astronomy_state` (
    `id` INT NOT NULL DEFAULT 1,
    `kp_current` DECIMAL(3,1) NULL DEFAULT 0.0,
    `kp_max_next_24h` DECIMAL(3,1) NULL DEFAULT 0.0,
    `kp_forecast_json` JSON NULL,
    `aurora_chance` VARCHAR(32) NOT NULL DEFAULT 'none',
    `visible_planets_json` JSON NULL,
    `active_meteor_showers_json` JSON NULL,
    `moon_phase_name` VARCHAR(64) NULL,
    `moon_illumination` DECIMAL(4,3) NULL,
    `summary_text` TEXT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weather_astronomy_events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_key` VARCHAR(100) NOT NULL UNIQUE,
    `event_type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `event_date` DATE NOT NULL,
    `peak_time` DATETIME NULL,
    `end_date` DATE NULL,
    `magnitude` DECIMAL(4,2) NULL,
    `visibility_rating` VARCHAR(32) NOT NULL DEFAULT 'good',
    `details_json` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_astro_date` (`event_date`),
    INDEX `idx_astro_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Gamification: Pause / Aussetzen für wiederkehrende Vorlagen (z. B. Klassenfahrt / Urlaub)
-- ---------------------------------------------------------------------------
ALTER TABLE `gamification_task_templates`
    ADD COLUMN IF NOT EXISTS `paused_from` DATE NULL AFTER `can_escalate`,
    ADD COLUMN IF NOT EXISTS `paused_until` DATE NULL AFTER `paused_from`,
    ADD COLUMN IF NOT EXISTS `pause_reason` VARCHAR(150) NULL AFTER `paused_until`;

-- ---------------------------------------------------------------------------
-- Sächsische Schulferien (2025 bis 2028)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `school_holidays` (`name`, `state_code`, `year`, `start_date`, `end_date`) VALUES
('Winterferien', 'SN', 2025, '2025-02-17', '2025-03-01'),
('Osterferien', 'SN', 2025, '2025-04-18', '2025-04-25'),
('Unterrichtsfreier Tag', 'SN', 2025, '2025-05-30', '2025-05-30'),
('Sommerferien', 'SN', 2025, '2025-06-28', '2025-08-08'),
('Herbstferien', 'SN', 2025, '2025-10-06', '2025-10-18'),
('Weihnachtsferien', 'SN', 2025, '2025-12-22', '2026-01-02'),
('Winterferien', 'SN', 2026, '2026-02-09', '2026-02-21'),
('Osterferien', 'SN', 2026, '2026-04-03', '2026-04-10'),
('Unterrichtsfreier Tag', 'SN', 2026, '2026-05-15', '2026-05-15'),
('Sommerferien', 'SN', 2026, '2026-07-04', '2026-08-14'),
('Herbstferien', 'SN', 2026, '2026-10-12', '2026-10-24'),
('Weihnachtsferien', 'SN', 2026, '2026-12-23', '2027-01-02'),
('Winterferien', 'SN', 2027, '2027-02-08', '2027-02-19'),
('Osterferien', 'SN', 2027, '2027-03-26', '2027-04-02'),
('Unterrichtsfreier Tag', 'SN', 2027, '2027-05-07', '2027-05-07'),
('Pfingstferien', 'SN', 2027, '2027-05-15', '2027-05-18'),
('Sommerferien', 'SN', 2027, '2027-07-10', '2027-08-20'),
('Herbstferien', 'SN', 2027, '2027-10-11', '2027-10-23'),
('Weihnachtsferien', 'SN', 2027, '2027-12-23', '2028-01-01'),
('Winterferien', 'SN', 2028, '2028-02-14', '2028-02-26'),
('Osterferien', 'SN', 2028, '2028-04-14', '2028-04-22'),
('Unterrichtsfreier Tag', 'SN', 2028, '2028-05-26', '2028-05-26'),
('Sommerferien', 'SN', 2028, '2028-07-22', '2028-09-01'),
('Herbstferien', 'SN', 2028, '2028-10-23', '2028-11-03'),
('Weihnachtsferien', 'SN', 2028, '2028-12-23', '2029-01-02');



