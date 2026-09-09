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
    public function getItems(?string $market = null, bool $includeChecked = true, ?int $isSpontaneous = null): array
    {
        $joinMarket = ($market !== null && $market !== '' && $market !== 'all') ? $market : 'Rewe';
        
        $sql = "
            SELECT 
                s.*,
                COALESCE(mc.sort_order, 999) AS aisle_order,
                pm.avg_interval_days,
                pm.last_purchased_at
            FROM shopping_list_items s
            LEFT JOIN market_categories mc 
                ON (mc.market = s.market OR (s.market = 'Übergreifend' AND mc.market = :join_market))
                AND s.category = mc.category_name
            LEFT JOIN product_master pm 
                ON s.product_id = pm.id
            WHERE 1=1
        ";

        $params = [':join_market' => $joinMarket];
        if ($market !== null && $market !== '' && $market !== 'all') {
            $sql .= " AND s.market IN (:market, 'Übergreifend')";
            $params[':market'] = $market;
        }

        if ($isSpontaneous !== null) {
            $sql .= " AND s.is_spontaneous = :is_spontaneous";
            $params[':is_spontaneous'] = $isSpontaneous ? 1 : 0;
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
            'Übergreifend' => ['total' => 0, 'open' => 0, 'checked' => 0],
        ];

        foreach ($rows as $row) {
            $m = $row['market'];
            $open = (int)$row['open_count'];
            $checked = (int)$row['checked_count'];
            $total = (int)$row['total'];

            if (!isset($result[$m])) {
                $result[$m] = ['total' => 0, 'open' => 0, 'checked' => 0];
            }
            $result[$m]['total'] += $total;
            $result[$m]['open'] += $open;
            $result[$m]['checked'] += $checked;

            $result['all']['total'] += $total;
            $result['all']['open'] += $open;
            $result['all']['checked'] += $checked;
        }

        // Add 'Übergreifend' to specific markets so they show up in the tabs
        if (isset($result['Übergreifend'])) {
            $u_open = $result['Übergreifend']['open'];
            $u_checked = $result['Übergreifend']['checked'];
            $u_total = $result['Übergreifend']['total'];

            $result['Rewe']['total'] += $u_total;
            $result['Rewe']['open'] += $u_open;
            $result['Rewe']['checked'] += $u_checked;

            $result['Globus']['total'] += $u_total;
            $result['Globus']['open'] += $u_open;
            $result['Globus']['checked'] += $u_checked;
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
                (product_id, name, quantity, unit, market, category, note, is_spontaneous, source, is_checked, created_at)
            VALUES
                (:product_id, :name, :quantity, :unit, :market, :category, :note, :is_spontaneous, :source, 0, NOW())
        ");

        $stmt->execute([
            ':product_id' => $data['product_id'] ?? null,
            ':name' => trim($data['name']),
            ':quantity' => isset($data['quantity']) ? (float)$data['quantity'] : 1.00,
            ':unit' => !empty($data['unit']) ? trim($data['unit']) : 'Stück',
            ':market' => !empty($data['market']) ? trim($data['market']) : 'Rewe',
            ':category' => !empty($data['category']) ? trim($data['category']) : 'Sonstiges',
            ':note' => !empty($data['note']) ? trim($data['note']) : null,
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
        if (array_key_exists('note', $data)) {
            $fields[] = "note = :note";
            $params[':note'] = !empty($data['note']) ? trim((string)$data['note']) : null;
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
            $sql .= " AND market IN (:market, 'Übergreifend')";
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

    /**
     * Ermittelt den aktuellen Synchronisationszustand (Hash) der Einkaufsliste
     * und aktiven Einkaufssessions zur Erkennung externer Änderungen.
     *
     * @return array{hash: string, item_count: int, checked_count: int, active_session_id: ?int}
     */
    public function getSyncState(): array
    {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) AS item_count,
                COALESCE(MAX(updated_at), '1970-01-01 00:00:00') AS max_updated,
                COALESCE(SUM(is_checked), 0) AS checked_count,
                COALESCE(MAX(id), 0) AS max_id
            FROM shopping_list_items
        ");
        $itemsState = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sessionStmt = $this->pdo->query("
            SELECT id, status, updated_at
            FROM shopping_sessions
            WHERE status = 'active'
            ORDER BY id DESC
            LIMIT 1
        ");
        $sessionState = $sessionStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $raw = implode('|', [
            $itemsState['item_count'] ?? 0,
            $itemsState['max_updated'] ?? '',
            $itemsState['checked_count'] ?? 0,
            $itemsState['max_id'] ?? 0,
            $sessionState['id'] ?? 'none',
            $sessionState['status'] ?? 'none',
            $sessionState['updated_at'] ?? ''
        ]);

        return [
            'hash' => md5($raw),
            'item_count' => (int)($itemsState['item_count'] ?? 0),
            'checked_count' => (int)($itemsState['checked_count'] ?? 0),
            'active_session_id' => !empty($sessionState['id']) ? (int)$sessionState['id'] : null,
        ];
    }
}

