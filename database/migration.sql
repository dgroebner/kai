-- ==========================================================================
-- DOMAIN: TRANSIT (ÖPNV & Pendler-Monitor)
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `transit_profiles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_email` VARCHAR(191) NULL,
    `student_id` INT UNSIGNED NULL,
    `profile_name` VARCHAR(100) NOT NULL,
    `origin_station_name` VARCHAR(100) NOT NULL,
    `origin_station_id` VARCHAR(50) NOT NULL,
    `destination_station_name` VARCHAR(100) NOT NULL,
    `destination_station_id` VARCHAR(50) NOT NULL,
    `via_station_name` VARCHAR(100) NULL,
    `via_station_id` VARCHAR(50) NULL,
    `preferred_lines` VARCHAR(100) NULL,
    `transport_modes` VARCHAR(50) NULL DEFAULT 'all',
    `active_days` VARCHAR(50) NOT NULL DEFAULT '[1,2,3,4,5]',
    `morning_window_start` TIME NOT NULL DEFAULT '06:30:00',
    `morning_window_end` TIME NOT NULL DEFAULT '09:00:00',
    `evening_window_start` TIME NOT NULL DEFAULT '15:00:00',
    `evening_window_end` TIME NOT NULL DEFAULT '19:00:00',
    `only_school_days` TINYINT(1) NOT NULL DEFAULT 0,
    `min_transfer_minutes` INT UNSIGNED NOT NULL DEFAULT 4,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_transit_user` (`user_email`),
    INDEX `idx_transit_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transit_cache` (
    `cache_key` VARCHAR(191) PRIMARY KEY,
    `payload` LONGTEXT NOT NULL,
    `expires_at` DATETIME NOT NULL,
    INDEX `idx_transit_cache_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standardberechtigungen für Admin-Gruppe zuweisen, falls vorhanden
INSERT IGNORE INTO `group_permissions` (`group_id`, `permission`)
SELECT `id`, 'transit_read' FROM `groups` WHERE `name` = 'Admin';

INSERT IGNORE INTO `group_permissions` (`group_id`, `permission`)
SELECT `id`, 'transit_write' FROM `groups` WHERE `name` = 'Admin';


-- ==========================================================================
-- DOMAIN: EINKAUFSLISTE (Supermarkt-Angebote via Marktguru API)
-- ==========================================================================

ALTER TABLE `product_master`
    ADD COLUMN IF NOT EXISTS `offer_search_term` VARCHAR(150) NULL DEFAULT NULL AFTER `is_ignored`,
    ADD COLUMN IF NOT EXISTS `offers_checked_at` DATETIME NULL DEFAULT NULL AFTER `offer_search_term`;

CREATE TABLE IF NOT EXISTS `shopping_product_offers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_master_id` INT NOT NULL,
    `external_offer_id` VARCHAR(64) NOT NULL COMMENT 'Eindeutige Angebots-ID von Marktguru',
    `leaflet_flight_id` VARCHAR(64) NULL COMMENT 'Prospekt-Ausgabe-ID zur Filial-Verifikation',
    `market` ENUM('Rewe', 'Globus') NOT NULL DEFAULT 'Rewe' COMMENT 'Normalisierter Markt',
    `brand_name` VARCHAR(150) NULL COMMENT 'Marke aus brand.name (z.B. Meggle)',
    `product_title` VARCHAR(255) NOT NULL COMMENT 'Produktname aus product.name',
    `description` VARCHAR(500) NULL COMMENT 'Mengen/Sorten-Details aus description',
    `unit_size` VARCHAR(60) NULL COMMENT 'Extrahierte Packungsgröße (z.B. 250 g)',
    `price` DECIMAL(8, 2) NOT NULL COMMENT 'Aktueller Angebotspreis',
    `old_price` DECIMAL(8, 2) NULL COMMENT 'Streichpreis / UVP falls vorhanden',
    `reference_price` VARCHAR(100) NULL COMMENT 'Grundpreis (z.B. 1 kg = 7,16 €)',
    `normalized_ref_price` DECIMAL(8, 2) NULL COMMENT 'Numerischer Grundpreis zum Vergleich',
    `is_preferred_brand` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Marke aus eBon-Historie bekannt',
    `valid_from` DATE NULL,
    `valid_to` DATE NOT NULL,
    `is_dismissed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Vom Nutzer als unpassend markiert',
    `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_product_offer_market` (`product_master_id`, `external_offer_id`, `market`),
    FOREIGN KEY (`product_master_id`) REFERENCES `product_master`(`id`) ON DELETE CASCADE,
    INDEX `idx_valid_to` (`valid_to`),
    INDEX `idx_product_valid` (`product_master_id`, `is_dismissed`, `valid_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
