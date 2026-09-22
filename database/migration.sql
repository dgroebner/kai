
-- Entfernen der Kochen-Funktionalität (v1.11.19)
ALTER TABLE gamification_task_templates DROP COLUMN is_cooking_day;
ALTER TABLE gamification_tasks DROP COLUMN is_cooking_day, DROP COLUMN recipe_title, DROP COLUMN recipe_details, DROP COLUMN recipe_status;
DROP TABLE IF EXISTS gamification_ratings;

