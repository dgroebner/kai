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
