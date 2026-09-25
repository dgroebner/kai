-- Inkrementelle Migration für ebon_product_mappings
ALTER TABLE ebon_product_mappings MODIFY COLUMN product_master_id INT NULL DEFAULT NULL COMMENT 'Zugewiesener Master-Artikel (NULL = ignoriert)';
