-- Migration: can_escalate für Gamification-Vorlagen und Aufgaben
ALTER TABLE `gamification_task_templates` ADD COLUMN `can_escalate` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_cooking_day`;
ALTER TABLE `gamification_tasks` ADD COLUMN `can_escalate` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_bounty`;

-- Migration: Streak-Schild & Urlaubs-/Klassenfahrts-Pausenschutz
ALTER TABLE `gamification_profiles` ADD COLUMN `streak_shields` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `streak_days`;
ALTER TABLE `gamification_profiles` ADD COLUMN `streak_freeze_until` DATE NULL AFTER `streak_shields`;
ALTER TABLE `gamification_profiles` ADD COLUMN `streak_freeze_reason` VARCHAR(100) NULL AFTER `streak_freeze_until`;

