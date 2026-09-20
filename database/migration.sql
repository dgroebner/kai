-- Migration: can_escalate für Gamification-Vorlagen und Aufgaben
ALTER TABLE `gamification_task_templates` ADD COLUMN `can_escalate` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_cooking_day`;
ALTER TABLE `gamification_tasks` ADD COLUMN `can_escalate` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_bounty`;
