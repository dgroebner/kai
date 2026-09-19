-- Inkrementelle Schema-Erweiterungen für Produktiv-Updates

-- Unique-Index für vehicle_telemetry_log (ermöglicht Duplikaterkennung via ON DUPLICATE KEY UPDATE)
-- HINWEIS: Bei bestehenden Daten zuvor Duplikate bereinigen (siehe root migration.sql)
ALTER TABLE `vehicle_telemetry_log`
ADD UNIQUE KEY IF NOT EXISTS `uq_vin_car_captured_at` (`vin`, `car_captured_at`);

-- Spalte charging_state auf VARCHAR(50) erweitern, um alle VW Enum-Werte vollständig aufzunehmen
ALTER TABLE `vehicle_state`
MODIFY COLUMN `charging_state` VARCHAR(50) NOT NULL DEFAULT 'unknown';
