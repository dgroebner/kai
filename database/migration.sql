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
