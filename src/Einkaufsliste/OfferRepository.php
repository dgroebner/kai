<?php

namespace Kai\Tools\Einkaufsliste;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use Throwable;

/**
 * Repository für gecachte Supermarkt-Angebote (shopping_product_offers)
 * sowie Preis- und Marken-Referenzdaten aus historischen eBons.
 */
class OfferRepository
{
    private const string FLIGHT_CACHE_KEY = 'marktguru_verified_flights_04289';
    private static bool $schemaChecked = false;

    private PDO $pdo;
    private Logger $logger;

    public function __construct(?PDO $pdo = null, ?Logger $logger = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->logger = $logger ?? new Logger();
        $this->ensureSchema();
    }

    /**
     * Stellt sicher, dass die Angebots-Tabelle und die Tracking-Spalten im Artikelstamm existieren.
     */
    private function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS `shopping_product_offers` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `product_master_id` INT NOT NULL,
                    `external_offer_id` VARCHAR(64) NOT NULL,
                    `leaflet_flight_id` VARCHAR(64) NULL,
                    `market` ENUM('Rewe', 'Globus') NOT NULL DEFAULT 'Rewe',
                    `brand_name` VARCHAR(150) NULL,
                    `product_title` VARCHAR(255) NOT NULL,
                    `description` VARCHAR(500) NULL,
                    `unit_size` VARCHAR(60) NULL,
                    `price` DECIMAL(8, 2) NOT NULL,
                    `old_price` DECIMAL(8, 2) NULL,
                    `reference_price` VARCHAR(100) NULL,
                    `normalized_ref_price` DECIMAL(8, 2) NULL,
                    `is_preferred_brand` TINYINT(1) NOT NULL DEFAULT 0,
                    `valid_from` DATE NULL,
                    `valid_to` DATE NOT NULL,
                    `is_dismissed` TINYINT(1) NOT NULL DEFAULT 0,
                    `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_product_offer_market` (`product_master_id`, `external_offer_id`, `market`),
                    FOREIGN KEY (`product_master_id`) REFERENCES `product_master`(`id`) ON DELETE CASCADE,
                    INDEX `idx_valid_to` (`valid_to`),
                    INDEX `idx_product_valid` (`product_master_id`, `is_dismissed`, `valid_to`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $colsStmt = $this->pdo->query("SHOW COLUMNS FROM `product_master` LIKE 'offers_checked_at'");
            if ($colsStmt && $colsStmt->rowCount() === 0) {
                $this->pdo->exec("
                    ALTER TABLE `product_master`
                        ADD COLUMN `offer_search_term` VARCHAR(150) NULL DEFAULT NULL AFTER `is_ignored`,
                        ADD COLUMN `offers_checked_at` DATETIME NULL DEFAULT NULL AFTER `offer_search_term`
                ");
            }
        } catch (Throwable $e) {
            $this->logger->warn('OfferRepository::ensureSchema Hinweis.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Lädt alle aktuell gültigen und nicht verworfenen Angebote für eine Liste von Master-Artikel-IDs.
     *
     * @param int[] $productIds
     * @return array<int, array<int, array<string, mixed>>> Gruppiert nach product_master_id
     */
    public function getActiveOffersByProductIds(array $productIds): array
    {
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn(int $id) => $id > 0)));
        if (empty($cleanIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $sql = "
            SELECT *
            FROM shopping_product_offers
            WHERE product_master_id IN ($placeholders)
              AND is_dismissed = 0
              AND valid_to >= CURDATE()
            ORDER BY is_preferred_brand DESC, price ASC, id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($cleanIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $grouped = [];
        foreach ($rows as $row) {
            $pid = (int)$row['product_master_id'];
            $row['id'] = (int)$row['id'];
            $row['product_master_id'] = $pid;
            $row['price'] = (float)$row['price'];
            $row['old_price'] = $row['old_price'] !== null ? (float)$row['old_price'] : null;
            $row['normalized_ref_price'] = $row['normalized_ref_price'] !== null ? (float)$row['normalized_ref_price'] : null;
            $row['is_preferred_brand'] = (int)$row['is_preferred_brand'] === 1;
            $grouped[$pid][] = $row;
        }

        return $grouped;
    }

    /**
     * Ermittelt für eine Liste von Master-Artikeln die zugeordneten eBon-Namen (Marken-Gedächtnis)
     * sowie den historischen Durchschnittspreis aus kb_items.
     *
     * @param int[] $productIds
     * @return array<int, array{ebon_names: string[], avg_price: ?float}>
     */
    public function getKnownEbonDataByProductIds(array $productIds): array
    {
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn(int $id) => $id > 0)));
        if (empty($cleanIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $result = [];
        foreach ($cleanIds as $id) {
            $result[$id] = ['ebon_names' => [], 'avg_price' => null];
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT product_master_id, ebon_name
                FROM ebon_product_mappings
                WHERE product_master_id IN ($placeholders)
            ");
            $stmt->execute($cleanIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pid = (int)$row['product_master_id'];
                $result[$pid]['ebon_names'][] = (string)$row['ebon_name'];
            }

            // Historischer Durchschnittspreis aus Kassenbon-Positionen (letzte 18 Monate)
            $priceStmt = $this->pdo->prepare("
                SELECT m.product_master_id,
                       ROUND(AVG(
                           CASE
                               WHEN COALESCE(i.unit_price, 0) > 0 THEN i.unit_price
                               WHEN COALESCE(i.quantity, 1) > 0 THEN i.total_price / i.quantity
                               ELSE i.total_price
                           END
                       ), 2) AS avg_unit_price
                FROM ebon_product_mappings m
                INNER JOIN kb_items i ON i.name = m.ebon_name
                WHERE m.product_master_id IN ($placeholders)
                  AND i.total_price > 0
                  AND i.is_discount = 0
                GROUP BY m.product_master_id
            ");
            $priceStmt->execute($cleanIds);
            foreach ($priceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pid = (int)$row['product_master_id'];
                if ($row['avg_unit_price'] !== null && (float)$row['avg_unit_price'] > 0) {
                    $result[$pid]['avg_price'] = (float)$row['avg_unit_price'];
                }
            }
        } catch (Throwable $e) {
            $this->logger->debug('OfferRepository::getKnownEbonDataByProductIds Hinweis.', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Speichert die gefundenen Angebote eines Master-Artikels für einen Markt (UPSERT)
     * und aktualisiert den Prüfzeitpunkt offers_checked_at.
     *
     * @param int $productId
     * @param array<int, array<string, mixed>> $offers
     * @param string $market 'Rewe' oder 'Globus'
     */
    public function saveOffersForProduct(int $productId, array $offers, string $market = 'Rewe'): int
    {
        $validMarket = $market === 'Globus' ? 'Globus' : 'Rewe';
        $keptExternalIds = [];
        $saved = 0;

        $upsertStmt = $this->pdo->prepare("
            INSERT INTO shopping_product_offers (
                product_master_id, external_offer_id, leaflet_flight_id, market,
                brand_name, product_title, description, unit_size,
                price, old_price, reference_price, normalized_ref_price,
                is_preferred_brand, valid_from, valid_to, is_dismissed, fetched_at
            ) VALUES (
                :product_master_id, :external_offer_id, :leaflet_flight_id, :market,
                :brand_name, :product_title, :description, :unit_size,
                :price, :old_price, :reference_price, :normalized_ref_price,
                :is_preferred_brand, :valid_from, :valid_to, 0, NOW()
            )
            ON DUPLICATE KEY UPDATE
                leaflet_flight_id = VALUES(leaflet_flight_id),
                brand_name = VALUES(brand_name),
                product_title = VALUES(product_title),
                description = VALUES(description),
                unit_size = VALUES(unit_size),
                price = VALUES(price),
                old_price = VALUES(old_price),
                reference_price = VALUES(reference_price),
                normalized_ref_price = VALUES(normalized_ref_price),
                is_preferred_brand = VALUES(is_preferred_brand),
                valid_from = VALUES(valid_from),
                valid_to = VALUES(valid_to),
                fetched_at = NOW()
        ");

        foreach ($offers as $offer) {
            $extId = substr(trim((string)($offer['external_offer_id'] ?? '')), 0, 64);
            if ($extId === '') {
                continue;
            }
            $keptExternalIds[] = $extId;

            $upsertStmt->execute([
                ':product_master_id' => $productId,
                ':external_offer_id' => $extId,
                ':leaflet_flight_id' => !empty($offer['leaflet_flight_id']) ? substr((string)$offer['leaflet_flight_id'], 0, 64) : null,
                ':market' => $validMarket,
                ':brand_name' => !empty($offer['brand_name']) ? mb_substr((string)$offer['brand_name'], 0, 150, 'UTF-8') : null,
                ':product_title' => mb_substr((string)($offer['product_title'] ?? ''), 0, 255, 'UTF-8'),
                ':description' => !empty($offer['description']) ? mb_substr((string)$offer['description'], 0, 500, 'UTF-8') : null,
                ':unit_size' => !empty($offer['unit_size']) ? mb_substr((string)$offer['unit_size'], 0, 60, 'UTF-8') : null,
                ':price' => round((float)($offer['price'] ?? 0), 2),
                ':old_price' => isset($offer['old_price']) && $offer['old_price'] !== null ? round((float)$offer['old_price'], 2) : null,
                ':reference_price' => !empty($offer['reference_price']) ? mb_substr((string)$offer['reference_price'], 0, 100, 'UTF-8') : null,
                ':normalized_ref_price' => isset($offer['normalized_ref_price']) && $offer['normalized_ref_price'] !== null ? round((float)$offer['normalized_ref_price'], 2) : null,
                ':is_preferred_brand' => !empty($offer['is_preferred_brand']) ? 1 : 0,
                ':valid_from' => !empty($offer['valid_from']) ? (string)$offer['valid_from'] : null,
                ':valid_to' => (string)($offer['valid_to'] ?? date('Y-m-d', strtotime('+6 days'))),
            ]);
            $saved++;
        }

        // Nicht mehr enthaltene (und nicht manuell verworfene) Angebote dieses Marktes aufräumen
        if (empty($keptExternalIds)) {
            $delStmt = $this->pdo->prepare("
                DELETE FROM shopping_product_offers
                WHERE product_master_id = :pid AND market = :market AND is_dismissed = 0
            ");
            $delStmt->execute([':pid' => $productId, ':market' => $validMarket]);
        } else {
            $placeholders = implode(',', array_fill(0, count($keptExternalIds), '?'));
            $delSql = "
                DELETE FROM shopping_product_offers
                WHERE product_master_id = ?
                  AND market = ?
                  AND is_dismissed = 0
                  AND external_offer_id NOT IN ($placeholders)
            ";
            $delStmt = $this->pdo->prepare($delSql);
            $delStmt->execute(array_merge([$productId, $validMarket], $keptExternalIds));
        }

        $this->markProductChecked($productId);

        return $saved;
    }

    /**
     * Aktualisiert den Zeitstempel der letzten Angebotsprüfung am Master-Artikel.
     */
    public function markProductChecked(int $productId): void
    {
        $stmt = $this->pdo->prepare("UPDATE product_master SET offers_checked_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $productId]);
    }

    /**
     * Markiert ein einzelnes Angebot dauerhaft als unpassend (is_dismissed = 1).
     */
    public function dismissOffer(int $offerId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE shopping_product_offers SET is_dismissed = 1 WHERE id = :id");
        return $stmt->execute([':id' => $offerId]);
    }

    /**
     * Entfernt abgelaufene Angebote (älter als 3 Tage nach Gültigkeitsende).
     */
    public function cleanupExpiredOffers(): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM shopping_product_offers WHERE valid_to < DATE_SUB(CURDATE(), INTERVAL 3 DAY)");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Liest den persistenten Cache verifizierter Prospekt-IDs (leafletFlightId -> bool) aus system_settings.
     *
     * @return array<string, bool>
     */
    public function getVerifiedFlightsCache(): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1");
            $stmt->execute([':key' => self::FLIGHT_CACHE_KEY]);
            $val = $stmt->fetchColumn();
            if (is_string($val) && $val !== '') {
                $decoded = json_decode($val, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (Throwable) {
            // Ignorieren
        }
        return [];
    }

    /**
     * Speichert den Cache verifizierter Prospekt-IDs (begrenzt auf die letzten 50 Einträge).
     *
     * @param array<string, bool> $flights
     */
    public function saveVerifiedFlightsCache(array $flights): void
    {
        try {
            if (count($flights) > 50) {
                $flights = array_slice($flights, -50, 50, true);
            }
            $stmt = $this->pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, label)
                VALUES (:key, :val, 'Verifizierte Marktguru Prospekt-IDs (04289)')
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([
                ':key' => self::FLIGHT_CACHE_KEY,
                ':val' => json_encode($flights),
            ]);
        } catch (Throwable) {
            // Ignorieren
        }
    }
}
