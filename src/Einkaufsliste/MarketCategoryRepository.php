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
     * Liefert eine flache Liste aller bekannten, eindeutigen Kategorie-Namen aus market_categories.
     *
     * @return string[]
     */
    public function getKnownCategoryNames(): array
    {
        $stmt = $this->pdo->query("SELECT DISTINCT category_name FROM market_categories ORDER BY category_name ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Normalisiert eine beliebige Kategorie-Zeichenkette (z. B. aus eBons, OCR, Freitext)
     * auf die offizielle kanonische Kategorie aus market_categories.
     */
    public function canonicalizeCategory(?string $category): ?string
    {
        if ($category === null) {
            return null;
        }

        $trimmed = trim($category);
        if ($trimmed === '') {
            return null;
        }

        $lower = mb_strtolower($trimmed, 'UTF-8');

        // Exakte Übereinstimmungen mit bekannten Kategorien
        $knownCategories = [
            'brot & backwaren' => 'Brot & Backwaren',
            'obst & gemüse' => 'Obst & Gemüse',
            'frischetheke (fleisch & wurst, käse)' => 'Frischetheke (Fleisch & Wurst, Käse)',
            'molkereiprodukte & eier' => 'Molkereiprodukte & Eier',
            'gewürze, öle & fertiggerichte' => 'Gewürze, Öle & Fertiggerichte',
            'müsli, brotaufstriche & kaffee/tee' => 'Müsli, Brotaufstriche & Kaffee/Tee',
            'nudeln & reis' => 'Nudeln & Reis',
            'konserven' => 'Konserven',
            'süßwaren & knabberartikel' => 'Süßwaren & Knabberartikel',
            'drogerie' => 'Drogerie',
            'haushalt' => 'Haushalt',
            'getränke' => 'Getränke',
            'spirituosen' => 'Spirituosen',
            'tiefkühlkost' => 'Tiefkühlkost',
            'sonstiges' => 'Sonstiges',
        ];

        if (isset($knownCategories[$lower])) {
            return $knownCategories[$lower];
        }

        // Synonyme / eBon- und Gemini-Kategorien auf kanonische Marktkategorien abbilden
        $synonyms = [
            // Molkerei
            'milch & käse' => 'Molkereiprodukte & Eier',
            'milch und käse' => 'Molkereiprodukte & Eier',
            'milch' => 'Molkereiprodukte & Eier',
            'käse' => 'Molkereiprodukte & Eier',
            'molkereiprodukte' => 'Molkereiprodukte & Eier',
            'molkerei' => 'Molkereiprodukte & Eier',
            'joghurt' => 'Molkereiprodukte & Eier',
            'butter' => 'Molkereiprodukte & Eier',
            'eier' => 'Molkereiprodukte & Eier',
            'quark' => 'Molkereiprodukte & Eier',
            'sahne' => 'Molkereiprodukte & Eier',

            // Backwaren
            'brot & gebäck' => 'Brot & Backwaren',
            'brot und gebäck' => 'Brot & Backwaren',
            'brot' => 'Brot & Backwaren',
            'backwaren' => 'Brot & Backwaren',
            'brötchen' => 'Brot & Backwaren',
            'gebäck' => 'Brot & Backwaren',
            'bäckerei' => 'Brot & Backwaren',

            // Frischetheke / Fleisch / Wurst / Fisch
            'fleisch & wurst' => 'Frischetheke (Fleisch & Wurst, Käse)',
            'fleisch und wurst' => 'Frischetheke (Fleisch & Wurst, Käse)',
            'fleisch' => 'Frischetheke (Fleisch & Wurst, Käse)',
            'wurst' => 'Frischetheke (Fleisch & Wurst, Käse)',
            'frischetheke' => 'Frischetheke (Fleisch & Wurst, Käse)',
            'fisch' => 'Frischetheke (Fleisch & Wurst, Käse)',
            'geflügel' => 'Frischetheke (Fleisch & Wurst, Käse)',
            'hackfleisch' => 'Frischetheke (Fleisch & Wurst, Käse)',

            // Obst & Gemüse
            'obst' => 'Obst & Gemüse',
            'gemüse' => 'Obst & Gemüse',
            'früchte' => 'Obst & Gemüse',
            'salat' => 'Obst & Gemüse',
            'beeren' => 'Obst & Gemüse',

            // Müsli / Frühstück / Kaffee / Tee
            'cerealien' => 'Müsli, Brotaufstriche & Kaffee/Tee',
            'müsli' => 'Müsli, Brotaufstriche & Kaffee/Tee',
            'frühstück' => 'Müsli, Brotaufstriche & Kaffee/Tee',
            'brotaufstriche' => 'Müsli, Brotaufstriche & Kaffee/Tee',
            'aufstrich' => 'Müsli, Brotaufstriche & Kaffee/Tee',
            'marmelade' => 'Müsli, Brotaufstriche & Kaffee/Tee',
            'honig' => 'Müsli, Brotaufstriche & Kaffee/Tee',
            'kaffee' => 'Müsli, Brotaufstriche & Kaffee/Tee',
            'tee' => 'Müsli, Brotaufstriche & Kaffee/Tee',
            'kaffee & tee' => 'Müsli, Brotaufstriche & Kaffee/Tee',
            'kaffee/tee' => 'Müsli, Brotaufstriche & Kaffee/Tee',

            // Nudeln & Reis
            'nudeln' => 'Nudeln & Reis',
            'pasta' => 'Nudeln & Reis',
            'reis' => 'Nudeln & Reis',
            'spaghetti' => 'Nudeln & Reis',

            // Konserven
            'dosen' => 'Konserven',

            // Gewürze, Öle & Fertiggerichte
            'gewürze' => 'Gewürze, Öle & Fertiggerichte',
            'gewürze & öle' => 'Gewürze, Öle & Fertiggerichte',
            'öle' => 'Gewürze, Öle & Fertiggerichte',
            'öl' => 'Gewürze, Öle & Fertiggerichte',
            'essig' => 'Gewürze, Öle & Fertiggerichte',
            'fertiggerichte' => 'Gewürze, Öle & Fertiggerichte',
            'saucen' => 'Gewürze, Öle & Fertiggerichte',

            // Süßwaren & Knabberartikel
            'süßwaren' => 'Süßwaren & Knabberartikel',
            'süßwaren & snacks' => 'Süßwaren & Knabberartikel',
            'süßigkeiten' => 'Süßwaren & Knabberartikel',
            'knabberartikel' => 'Süßwaren & Knabberartikel',
            'knabberzeug' => 'Süßwaren & Knabberartikel',
            'snacks' => 'Süßwaren & Knabberartikel',
            'chips' => 'Süßwaren & Knabberartikel',
            'schokolade' => 'Süßwaren & Knabberartikel',
            'kekse' => 'Süßwaren & Knabberartikel',

            // Drogerie
            'pflege & gesundheit' => 'Drogerie',
            'pflege und gesundheit' => 'Drogerie',
            'pflege' => 'Drogerie',
            'körperpflege' => 'Drogerie',
            'kosmetik' => 'Drogerie',
            'hygiene' => 'Drogerie',
            'shampoo' => 'Drogerie',
            'seife' => 'Drogerie',
            'zahnpflege' => 'Drogerie',

            // Haushalt
            'haushaltswaren' => 'Haushalt',
            'reinigung' => 'Haushalt',
            'waschmittel' => 'Haushalt',
            'putzmittel' => 'Haushalt',
            'toilettenpapier' => 'Haushalt',
            'tierbedarf' => 'Haushalt',
            'tiernahrung' => 'Haushalt',

            // Getränke
            'softdrinks' => 'Getränke',
            'wasser' => 'Getränke',
            'saft' => 'Getränke',

            // Spirituosen
            'alkohol' => 'Spirituosen',
            'wein' => 'Spirituosen',
            'bier' => 'Spirituosen',
            'sekt' => 'Spirituosen',
            'schnaps' => 'Spirituosen',

            // Tiefkühl
            'tiefkühl' => 'Tiefkühlkost',
            'tk' => 'Tiefkühlkost',
            'eis' => 'Tiefkühlkost',
        ];

        if (isset($synonyms[$lower])) {
            return $synonyms[$lower];
        }

        // Substring-Suche nach signifikanten Schlüsselwörtern
        foreach ($synonyms as $syn => $canonical) {
            if (mb_strlen($syn, 'UTF-8') >= 4 && str_contains($lower, $syn)) {
                return $canonical;
            }
        }

        return 'Sonstiges';
    }

    /**
     * Repariert ungültige oder veraltete Kategorien in product_master und shopping_list_items.
     */
    public function repairInvalidCategories(): int
    {
        $repairs = [
            'Molkereiprodukte & Eier' => ['Milch & Käse', 'Milch und Käse', 'Milch', 'Käse', 'Molkerei', 'Joghurt', 'Butter', 'Eier'],
            'Brot & Backwaren' => ['Brot & Gebäck', 'Brot und Gebäck', 'Backwaren', 'Brot', 'Gebäck', 'Brötchen', 'Bäckerei'],
            'Frischetheke (Fleisch & Wurst, Käse)' => ['Fleisch & Wurst', 'Fleisch und Wurst', 'Fleisch', 'Wurst', 'Fisch', 'Geflügel'],
            'Müsli, Brotaufstriche & Kaffee/Tee' => ['Cerealien', 'Müsli', 'Kaffee & Tee', 'Kaffee/Tee', 'Kaffee', 'Tee', 'Aufstrich', 'Marmelade', 'Honig'],
            'Süßwaren & Knabberartikel' => ['Süßwaren', 'Süßwaren & Snacks', 'Snacks', 'Knabberartikel', 'Knabberzeug', 'Chips', 'Schokolade'],
            'Drogerie' => ['Pflege & Gesundheit', 'Pflege und Gesundheit', 'Pflege', 'Körperpflege', 'Kosmetik', 'Hygiene'],
            'Haushalt' => ['Tierbedarf', 'Tiernahrung', 'Haushaltswaren', 'Reinigung', 'Waschmittel'],
            'Nudeln & Reis' => ['Nudeln', 'Pasta', 'Reis'],
            'Gewürze, Öle & Fertiggerichte' => ['Gewürze', 'Öle', 'Öl', 'Essig', 'Fertiggerichte', 'Saucen'],
            'Tiefkühlkost' => ['Tiefkühl', 'TK'],
        ];

        $totalUpdated = 0;

        foreach ($repairs as $canonical => $invalidList) {
            $inClause = implode(',', array_fill(0, count($invalidList), '?'));

            // 1. product_master reparieren
            $stmt1 = $this->pdo->prepare("UPDATE product_master SET default_category = ? WHERE default_category IN ($inClause)");
            $stmt1->execute(array_merge([$canonical], $invalidList));
            $totalUpdated += $stmt1->rowCount();

            // 2. shopping_list_items reparieren
            $stmt2 = $this->pdo->prepare("UPDATE shopping_list_items SET category = ? WHERE category IN ($inClause)");
            $stmt2->execute(array_merge([$canonical], $invalidList));
            $totalUpdated += $stmt2->rowCount();
        }

        // Alle restlichen Kategorien, die gar nicht in market_categories vorkommen, auf 'Sonstiges' setzen
        $stmt3 = $this->pdo->query("
            UPDATE product_master pm
            LEFT JOIN (SELECT DISTINCT category_name FROM market_categories) mc ON pm.default_category = mc.category_name
            SET pm.default_category = 'Sonstiges'
            WHERE pm.default_category IS NOT NULL AND pm.default_category != '' AND mc.category_name IS NULL
        ");
        if ($stmt3) {
            $totalUpdated += $stmt3->rowCount();
        }

        $stmt4 = $this->pdo->query("
            UPDATE shopping_list_items s
            LEFT JOIN (SELECT DISTINCT category_name FROM market_categories) mc ON s.category = mc.category_name
            SET s.category = 'Sonstiges'
            WHERE s.category IS NOT NULL AND s.category != '' AND mc.category_name IS NULL
        ");
        if ($stmt4) {
            $totalUpdated += $stmt4->rowCount();
        }

        return $totalUpdated;
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
