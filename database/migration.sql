-- ==========================================================================
-- MIGRATION v1.36.0: DOMAIN RECIPES (Kochrezept-Assistent & Küchenbegleiter)
-- ==========================================================================

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `label`) VALUES
('stove_type', 'Ceranfeld', 'Herd-Typ (z. B. Ceranfeld, Induktion, Gas)'),
('stove_levels', '12', 'Anzahl Herdstufen (Skala z. B. 9 oder 12)'),
('oven_default_mode', 'Umluft', 'Bevorzugte Backofen-Beheizungsart'),
('recipe_prefer_shallots', '1', 'Rezepte: Schalotten statt Zwiebeln bevorzugen (1/0)'),
('recipe_default_adults', '2', 'Rezepte: Standard-Anzahl Erwachsene'),
('recipe_default_kids', '2', 'Rezepte: Standard-Anzahl Kinder / Jugendliche'),
('recipe_kid_portion_pct', '80', 'Rezepte: Portionsgröße pro Kind in % (z. B. 80)'),
('recipe_household_preferences', '', 'Rezepte: Freitext-Haushaltspräferenzen & No-Gos für KI');

-- Initiale Rechte ausschließlich für die Gruppe 'Admin' vergeben
INSERT IGNORE INTO `group_permissions` (`group_id`, `permission`)
SELECT `id`, 'recipes_read' FROM `groups` WHERE `name` = 'Admin';

INSERT IGNORE INTO `group_permissions` (`group_id`, `permission`)
SELECT `id`, 'recipes_write' FROM `groups` WHERE `name` = 'Admin';

CREATE TABLE IF NOT EXISTS `recipes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(191) NOT NULL,
    `description` TEXT NULL,
    `category` VARCHAR(80) NOT NULL DEFAULT 'Hauptgericht',
    `source_type` ENUM('url', 'photo', 'pdf', 'manual', 'text') NOT NULL DEFAULT 'manual',
    `source_url` VARCHAR(500) NULL,
    `prep_time_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `cook_time_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_time_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `default_adults` INT UNSIGNED NOT NULL DEFAULT 2,
    `default_kids` INT UNSIGNED NOT NULL DEFAULT 2,
    `default_servings` DECIMAL(5,2) NOT NULL DEFAULT 3.60,
    `calories_per_serving` INT UNSIGNED NULL,
    `protein_per_serving` DECIMAL(6,1) NULL,
    `carbs_per_serving` DECIMAL(6,1) NULL,
    `fat_per_serving` DECIMAL(6,1) NULL,
    `equipment_list` TEXT NULL,
    `is_favorite` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` VARCHAR(191) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_recipes_title` (`title`),
    INDEX `idx_recipes_category` (`category`),
    INDEX `idx_recipes_fav` (`is_favorite`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recipe_variants` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recipe_id` INT UNSIGNED NOT NULL,
    `variant_name` VARCHAR(120) NOT NULL DEFAULT 'Standard (Familien-Variante)',
    `is_default` TINYINT(1) NOT NULL DEFAULT 1,
    `notes` TEXT NULL,
    `calories_per_serving` INT UNSIGNED NULL,
    `protein_per_serving` DECIMAL(6,1) NULL,
    `carbs_per_serving` DECIMAL(6,1) NULL,
    `fat_per_serving` DECIMAL(6,1) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_rv_recipe` (`recipe_id`),
    CONSTRAINT `fk_rv_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `recipe_variants`
    ADD COLUMN IF NOT EXISTS `calories_per_serving` INT UNSIGNED NULL AFTER `notes`,
    ADD COLUMN IF NOT EXISTS `protein_per_serving` DECIMAL(6,1) NULL AFTER `calories_per_serving`,
    ADD COLUMN IF NOT EXISTS `carbs_per_serving` DECIMAL(6,1) NULL AFTER `protein_per_serving`,
    ADD COLUMN IF NOT EXISTS `fat_per_serving` DECIMAL(6,1) NULL AFTER `carbs_per_serving`;

CREATE TABLE IF NOT EXISTS `recipe_ingredients` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `variant_id` INT UNSIGNED NOT NULL,
    `ingredient_name` VARCHAR(150) NOT NULL,
    `quantity` DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    `unit` VARCHAR(50) NOT NULL DEFAULT 'Stück',
    `package_size` DECIMAL(8,2) NULL,
    `package_unit` VARCHAR(50) NULL,
    `leftover_tip` VARCHAR(255) NULL,
    `market` VARCHAR(50) NOT NULL DEFAULT 'Rewe',
    `shopping_category` VARCHAR(100) NOT NULL DEFAULT 'Sonstiges',
    `is_pantry_staple` TINYINT(1) NOT NULL DEFAULT 0,
    `prep_note` VARCHAR(150) NULL,
    `component_name` VARCHAR(100) NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX `idx_ri_variant` (`variant_id`),
    INDEX `idx_ri_name` (`ingredient_name`),
    CONSTRAINT `fk_ri_variant` FOREIGN KEY (`variant_id`) REFERENCES `recipe_variants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recipe_components` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `variant_id` INT UNSIGNED NOT NULL,
    `component_name` VARCHAR(100) NOT NULL,
    `pot_or_appliance` VARCHAR(100) NULL,
    `preheat_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `active_cook_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `passive_cook_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `rest_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX `idx_rc_variant` (`variant_id`),
    CONSTRAINT `fk_rc_variant` FOREIGN KEY (`variant_id`) REFERENCES `recipe_variants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recipe_steps` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `component_id` INT UNSIGNED NOT NULL,
    `step_number` INT UNSIGNED NOT NULL,
    `phase` ENUM('mise_en_place_pre', 'preheat', 'cooking', 'mise_en_place_parallel', 'resting', 'serving') NOT NULL DEFAULT 'cooking',
    `title` VARCHAR(150) NULL,
    `instruction` TEXT NOT NULL,
    `ingredients_inline` TEXT NULL,
    `stove_level` VARCHAR(30) NULL,
    `oven_setting` VARCHAR(80) NULL,
    `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_passive_wait` TINYINT(1) NOT NULL DEFAULT 0,
    `parallel_hint` VARCHAR(255) NULL,
    `start_offset_before_finish_min` INT UNSIGNED NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX `idx_rs_component` (`component_id`),
    CONSTRAINT `fk_rs_component` FOREIGN KEY (`component_id`) REFERENCES `recipe_components`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
