<?php

namespace Kai\Tools\Einkaufsliste;

use Exception;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;

/**
 * Repository für die aktive Einkaufsliste mit Markt-Splitting und Gang-Sortierung.
 */
class ShoppingListRepository
{
    private PDO $pdo;
    private Logger $logger;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->logger = new Logger();
    }

    /**
     * Holt alle Einkaufslisten-Elemente, optional gefiltert nach Markt.
     * Sortierung erfolgt strikt nach Gang-Reihenfolge des Markts.
     *
     * @param string|null $market 'Rewe', 'Globus' oder null für alle
     * @param bool $includeChecked Ob abgehakte Artikel mitgeladen werden sollen
     * @return array<int, array<string, mixed>>
     */
    public function getItems(?string $market = null, bool $includeChecked = true): array
    {
        $sql = "
            SELECT 
                s.*,
                COALESCE(mc.sort_order, 999) AS aisle_order,
                pm.avg_interval_days,
                pm.last_purchased_at
            FROM shopping_list_items s
            LEFT JOIN market_categories mc 
                ON s.market = mc.market AND s.category = mc.category_name
            LEFT JOIN product_master pm 
                ON s.product_id = pm.id
            WHERE 1=1
        ";

        $params = [];
        if ($market !== null && $market !== '' && $market !== 'all') {
            $sql .= " AND s.market = :market";
            $params[':market'] = $market;
        }

        if (!$includeChecked) {
            $sql .= " AND s.is_checked = 0";
        }

        // Sortierung: Unchecked zuerst, dann nach Gang (aisle_order), dann Kategorie, dann Name
        $sql .= " ORDER BY s.is_checked ASC, aisle_order ASC, s.category ASC, s.name ASC, s.id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Zählt die offenen und erledigten Positionen pro Markt.
     *
     * @return array<string, array{total: int, open: int, checked: int}>
     */
    public function getItemCountsByMarket(): array
    {
        $stmt = $this->pdo->query("
            SELECT 
                market,
                COUNT(*) AS total,
                SUM(CASE WHEN is_checked = 0 THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN is_checked = 1 THEN 1 ELSE 0 END) AS checked_count
            FROM shopping_list_items
            GROUP BY market
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [
            'all' => ['total' => 0, 'open' => 0, 'checked' => 0],
            'Rewe' => ['total' => 0, 'open' => 0, 'checked' => 0],
            'Globus' => ['total' => 0, 'open' => 0, 'checked' => 0],
        ];

        foreach ($rows as $row) {
            $m = $row['market'];
            $open = (int)$row['open_count'];
            $checked = (int)$row['checked_count'];
            $total = (int)$row['total'];

            $result[$m] = ['total' => $total, 'open' => $open, 'checked' => $checked];
            $result['all']['total'] += $total;
            $result['all']['open'] += $open;
            $result['all']['checked'] += $checked;
        }

        return $result;
    }

    /**
     * Findet einen Eintrag anhand seiner ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM shopping_list_items WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Fügt eine neue Position zur Einkaufsliste hinzu.
     *
     * @param array{
     *     name: string,
     *     quantity?: float,
     *     unit?: ?string,
     *     market?: string,
     *     category?: ?string,
     *     is_spontaneous?: int|bool,
     *     source?: string,
     *     product_id?: ?int
     * } $data
     * @return int
     */
    public function addItem(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO shopping_list_items
                (product_id, name, quantity, unit, market, category, is_spontaneous, source, is_checked, created_at)
            VALUES
                (:product_id, :name, :quantity, :unit, :market, :category, :is_spontaneous, :source, 0, NOW())
        ");

        $stmt->execute([
            ':product_id' => $data['product_id'] ?? null,
            ':name' => trim($data['name']),
            ':quantity' => isset($data['quantity']) ? (float)$data['quantity'] : 1.00,
            ':unit' => !empty($data['unit']) ? trim($data['unit']) : 'Stück',
            ':market' => !empty($data['market']) ? trim($data['market']) : 'Rewe',
            ':category' => !empty($data['category']) ? trim($data['category']) : 'Sonstiges',
            ':is_spontaneous' => !empty($data['is_spontaneous']) ? 1 : 0,
            ':source' => $data['source'] ?? 'manual',
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Aktualisiert eine bestehende Position.
     */
    public function updateItem(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['name'])) {
            $fields[] = "name = :name";
            $params[':name'] = trim((string)$data['name']);
        }
        if (isset($data['quantity'])) {
            $fields[] = "quantity = :quantity";
            $params[':quantity'] = (float)$data['quantity'];
        }
        if (isset($data['unit'])) {
            $fields[] = "unit = :unit";
            $params[':unit'] = trim((string)$data['unit']);
        }
        if (isset($data['market'])) {
            $fields[] = "market = :market";
            $params[':market'] = trim((string)$data['market']);
        }
        if (isset($data['category'])) {
            $fields[] = "category = :category";
            $params[':category'] = trim((string)$data['category']);
        }
        if (isset($data['is_spontaneous'])) {
            $fields[] = "is_spontaneous = :is_spontaneous";
            $params[':is_spontaneous'] = !empty($data['is_spontaneous']) ? 1 : 0;
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $sql = "UPDATE shopping_list_items SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Schaltet den Abhake-Status einer Position um.
     */
    public function toggleCheck(int $id, ?bool $forceStatus = null): bool
    {
        $item = $this->findById($id);
        if (!$item) {
            return false;
        }

        $newStatus = $forceStatus !== null ? ($forceStatus ? 1 : 0) : ((int)$item['is_checked'] === 1 ? 0 : 1);
        $checkedAt = $newStatus === 1 ? date('Y-m-d H:i:s') : null;

        $stmt = $this->pdo->prepare("
            UPDATE shopping_list_items 
            SET is_checked = :is_checked, checked_at = :checked_at, updated_at = NOW()
            WHERE id = :id
        ");

        return $stmt->execute([
            ':is_checked' => $newStatus,
            ':checked_at' => $checkedAt,
            ':id' => $id,
        ]);
    }

    /**
     * Löscht eine Position aus der Liste.
     */
    public function deleteItem(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM shopping_list_items WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Liefert alle aktuell offenen (nicht abgehakten) Artikelnamen zur Duplikatsvermeidung bei Vorschlägen.
     *
     * @return string[]
     */
    public function getActiveItemNames(): array
    {
        $stmt = $this->pdo->query("
            SELECT DISTINCT LOWER(TRIM(name)) 
            FROM shopping_list_items 
            WHERE is_checked = 0
        ");

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Holt alle abgehakten Artikel, optional gefiltert nach Markt.
     *
     * @param string|null $market
     * @return array<int, array<string, mixed>>
     */
    public function getCheckedItems(?string $market = null): array
    {
        $sql = "SELECT * FROM shopping_list_items WHERE is_checked = 1";
        $params = [];

        if ($market !== null && $market !== '' && $market !== 'all') {
            $sql .= " AND market = :market";
            $params[':market'] = $market;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Schließt den Einkauf ab:
     * Holt alle abgehakten Artikel und löscht sie aus der aktiven Liste.
     * Gibt die Liste der erledigten Artikel zurück.
     *
     * @param string|null $market
     * @return array<int, array<string, mixed>>
     */
    public function completeCheckedItems(?string $market = null): array
    {
        $checkedItems = $this->getCheckedItems($market);
        if (empty($checkedItems)) {
            return [];
        }

        $ids = array_column($checkedItems, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->pdo->prepare("DELETE FROM shopping_list_items WHERE id IN ($placeholders)");
        $stmt->execute($ids);

        return $checkedItems;
    }
}
