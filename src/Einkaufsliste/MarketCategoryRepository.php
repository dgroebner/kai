<?php

namespace Kai\Tools\Einkaufsliste;

use Exception;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;

/**
 * Verwaltet Märkte und Gang-Reihenfolgen (Aisles) für das 2-Märkte-Splitting.
 */
class MarketCategoryRepository
{
    private PDO $pdo;
    private Logger $logger;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->logger = new Logger();
    }

    /**
     * Liefert alle Gänge/Kategorien für einen Markt in korrekter Gang-Reihenfolge.
     *
     * @param string $market Z. B. 'Rewe' oder 'Globus'
     * @return array<int, array<string, mixed>>
     */
    public function getCategoriesForMarket(string $market): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, market, category_name, sort_order
            FROM market_categories
            WHERE market = :market
            ORDER BY sort_order ASC, category_name ASC
        ");
        $stmt->execute([':market' => $market]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Liefert alle Gänge gruppiert nach Markt (z. B. ['Rewe' => [...], 'Globus' => [...]]).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getAllCategoriesGrouped(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, market, category_name, sort_order
            FROM market_categories
            ORDER BY market ASC, sort_order ASC, category_name ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = ['Rewe' => [], 'Globus' => []];
        foreach ($rows as $row) {
            $market = $row['market'];
            if (!isset($grouped[$market])) {
                $grouped[$market] = [];
            }
            $grouped[$market][] = $row;
        }

        return $grouped;
    }

    /**
     * Liefert eine Map von Kategorie-Name => Sortierindex für einen Markt.
     *
     * @param string $market
     * @return array<string, int>
     */
    public function getAisleOrderMap(string $market): array
    {
        $categories = $this->getCategoriesForMarket($market);
        $map = [];
        foreach ($categories as $cat) {
            $map[$cat['category_name']] = (int)$cat['sort_order'];
        }
        return $map;
    }

    /**
     * Fügt eine neue Kategorie/Gang für einen Markt hinzu oder aktualisiert die Reihenfolge.
     */
    public function saveCategory(string $market, string $categoryName, int $sortOrder = 0): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO market_categories (market, category_name, sort_order)
            VALUES (:market, :category_name, :sort_order)
            ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)
        ");
        $stmt->execute([
            ':market' => trim($market),
            ':category_name' => trim($categoryName),
            ':sort_order' => $sortOrder
        ]);
    }

    /**
     * Aktualisiert die Gang-Reihenfolge anhand einer sortierten Liste von Kategorie-Namen.
     *
     * @param string $market
     * @param string[] $orderedCategoryNames
     */
    public function updateSortOrder(string $market, array $orderedCategoryNames): void
    {
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("
                UPDATE market_categories
                SET sort_order = :sort_order
                WHERE market = :market AND category_name = :category_name
            ");

            $order = 1;
            foreach ($orderedCategoryNames as $categoryName) {
                $trimmed = trim((string)$categoryName);
                if ($trimmed === '') {
                    continue;
                }
                $stmt->execute([
                    ':sort_order' => $order++,
                    ':market' => $market,
                    ':category_name' => $trimmed
                ]);
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error("MarketCategoryRepository: Fehler beim Aktualisieren der Sortierung.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Löscht eine Gang-Kategorie anhand ihrer ID.
     */
    public function deleteCategory(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM market_categories WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
