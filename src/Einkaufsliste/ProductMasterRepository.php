<?php

namespace Kai\Tools\Einkaufsliste;

use Exception;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;

/**
 * Verwaltet den lernenden Artikelstamm mit Markt-Präferenz und Verbrauchsintervallen.
 */
class ProductMasterRepository
{
    private PDO $pdo;
    private Logger $logger;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->logger = new Logger();
    }

    /**
     * Sucht einen Artikel anhand seines exakten Namens.
     */
    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *, COALESCE(NULLIF(custom_label, ''), name) AS display_name
            FROM product_master 
            WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))
            LIMIT 1
        ");
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Sucht einen Artikel anhand des Namens ODER eines vergebenen Labels.
     */
    public function findByLabelOrName(string $term): ?array
    {
        $clean = trim($term);
        $stmt = $this->pdo->prepare("
            SELECT *, COALESCE(NULLIF(custom_label, ''), name) AS display_name
            FROM product_master 
            WHERE LOWER(TRIM(name)) = LOWER(TRIM(:term_name))
               OR LOWER(TRIM(COALESCE(custom_label, ''))) = LOWER(TRIM(:term_label))
            LIMIT 1
        ");
        $stmt->execute([
            ':term_name' => $clean,
            ':term_label' => $clean,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Sucht einen Artikel anhand seiner ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *, COALESCE(NULLIF(custom_label, ''), name) AS display_name
            FROM product_master 
            WHERE id = :id 
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Liefert alle Artikel des Artikelstamms, optional gefiltert.
     *
     * @param bool|null $activeOnly true für aktive (nicht ignorierte), false für ignorierte, null für alle
     * @param string|null $market 'Rewe', 'Globus' oder null für alle
     * @param string|null $search Suchbegriff für Name oder Label
     * @return array<int, array<string, mixed>>
     */
    public function getAll(?bool $activeOnly = null, ?string $market = null, ?string $search = null): array
    {
        $sql = "
            SELECT *,
                   COALESCE(NULLIF(custom_label, ''), name) AS display_name
            FROM product_master 
            WHERE 1=1
        ";
        $params = [];

        if ($activeOnly !== null) {
            $sql .= " AND is_ignored = :is_ignored";
            $params[':is_ignored'] = $activeOnly ? 0 : 1;
        }

        if ($market !== null && $market !== '' && $market !== 'all') {
            $sql .= " AND preferred_market = :market";
            $params[':market'] = $market;
        }

        if ($search !== null && trim($search) !== '') {
            $sql .= " AND (name LIKE :search_name OR custom_label LIKE :search_label)";
            $term = '%' . trim($search) . '%';
            $params[':search_name'] = $term;
            $params[':search_label'] = $term;
        }

        $sql .= " ORDER BY is_ignored ASC, preferred_market ASC, display_name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Liefert zusammenfassende Zähler für das UI (Gesamt, Aktiv, Ignoriert, mit Label).
     *
     * @return array{total: int, active: int, ignored: int, labeled: int}
     */
    public function getStats(): array
    {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN is_ignored = 0 THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN is_ignored = 1 THEN 1 ELSE 0 END) AS ignored_count,
                SUM(CASE WHEN custom_label IS NOT NULL AND TRIM(custom_label) != '' THEN 1 ELSE 0 END) AS labeled_count
            FROM product_master
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total' => (int)($row['total'] ?? 0),
            'active' => (int)($row['active_count'] ?? 0),
            'ignored' => (int)($row['ignored_count'] ?? 0),
            'labeled' => (int)($row['labeled_count'] ?? 0),
        ];
    }

    /**
     * Sucht Artikel anhand eines Teilstrings für Autocomplete (durchsucht Name & Label).
     */
    public function search(string $query, int $limit = 10, bool $activeOnly = true): array
    {
        $sql = "
            SELECT *,
                   COALESCE(NULLIF(custom_label, ''), name) AS display_name
            FROM product_master
            WHERE (name LIKE :query_name OR custom_label LIKE :query_label)
        ";
        if ($activeOnly) {
            $sql .= " AND is_ignored = 0";
        }
        $sql .= " ORDER BY display_name ASC LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $term = '%' . $query . '%';
        $stmt->bindValue(':query_name', $term, PDO::PARAM_STR);
        $stmt->bindValue(':query_label', $term, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Speichert einen Artikel im Artikelstamm oder aktualisiert vorhandene Werte.
     * Schützt bestehende custom_label und is_ignored vor versehentlichem Überschreiben.
     *
     * @param array{
     *     name: string,
     *     custom_label?: ?string,
     *     preferred_market?: string,
     *     default_category?: ?string,
     *     default_unit?: ?string,
     *     avg_interval_days?: ?float,
     *     last_purchased_at?: ?string,
     *     holiday_factor?: float,
     *     is_ignored?: int|bool
     * } $data
     * @return int ID des Artikels
     */
    public function saveOrUpdate(array $data): int
    {
        $existing = $this->findByName($data['name']);

        if ($existing) {
            $id = (int)$existing['id'];
            $fields = [
                'preferred_market = COALESCE(:preferred_market, preferred_market)',
                'default_category = COALESCE(:default_category, default_category)',
                'default_unit = COALESCE(:default_unit, default_unit)',
                'avg_interval_days = COALESCE(:avg_interval_days, avg_interval_days)',
                'last_purchased_at = COALESCE(:last_purchased_at, last_purchased_at)',
                'holiday_factor = COALESCE(:holiday_factor, holiday_factor)',
            ];
            $params = [
                ':id' => $id,
                ':preferred_market' => $data['preferred_market'] ?? null,
                ':default_category' => $data['default_category'] ?? null,
                ':default_unit' => $data['default_unit'] ?? null,
                ':avg_interval_days' => isset($data['avg_interval_days']) ? (float)$data['avg_interval_days'] : null,
                ':last_purchased_at' => $data['last_purchased_at'] ?? null,
                ':holiday_factor' => isset($data['holiday_factor']) ? (float)$data['holiday_factor'] : null,
            ];

            if (array_key_exists('custom_label', $data)) {
                $fields[] = 'custom_label = :custom_label';
                $params[':custom_label'] = !empty(trim((string)$data['custom_label'])) ? trim((string)$data['custom_label']) : null;
            }
            if (array_key_exists('is_ignored', $data)) {
                $fields[] = 'is_ignored = :is_ignored';
                $params[':is_ignored'] = !empty($data['is_ignored']) ? 1 : 0;
            }

            $fields[] = 'updated_at = NOW()';
            $sql = "UPDATE product_master SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $id;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO product_master 
                (name, custom_label, preferred_market, default_category, default_unit, avg_interval_days, last_purchased_at, holiday_factor, is_ignored)
            VALUES 
                (:name, :custom_label, :preferred_market, :default_category, :default_unit, :avg_interval_days, :last_purchased_at, :holiday_factor, :is_ignored)
        ");
        $stmt->execute([
            ':name' => trim($data['name']),
            ':custom_label' => !empty($data['custom_label']) ? trim((string)$data['custom_label']) : null,
            ':preferred_market' => $data['preferred_market'] ?? 'Rewe',
            ':default_category' => $data['default_category'] ?? null,
            ':default_unit' => $data['default_unit'] ?? 'Stück',
            ':avg_interval_days' => isset($data['avg_interval_days']) ? (float)$data['avg_interval_days'] : null,
            ':last_purchased_at' => $data['last_purchased_at'] ?? null,
            ':holiday_factor' => isset($data['holiday_factor']) ? (float)$data['holiday_factor'] : 1.00,
            ':is_ignored' => !empty($data['is_ignored']) ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Aktualisiert gezielt Stammdaten über die Pflege-Oberfläche.
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public function updateMaster(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        if (array_key_exists('custom_label', $data)) {
            $val = trim((string)($data['custom_label'] ?? ''));
            $fields[] = "custom_label = :custom_label";
            $params[':custom_label'] = $val !== '' ? $val : null;
        }
        if (array_key_exists('is_ignored', $data)) {
            $fields[] = "is_ignored = :is_ignored";
            $params[':is_ignored'] = !empty($data['is_ignored']) ? 1 : 0;
        }
        if (isset($data['preferred_market'])) {
            $fields[] = "preferred_market = :preferred_market";
            $params[':preferred_market'] = trim((string)$data['preferred_market']);
        }
        if (array_key_exists('default_category', $data)) {
            $cat = trim((string)($data['default_category'] ?? ''));
            $fields[] = "default_category = :default_category";
            $params[':default_category'] = $cat !== '' ? $cat : null;
        }
        if (isset($data['default_unit'])) {
            $fields[] = "default_unit = :default_unit";
            $params[':default_unit'] = trim((string)$data['default_unit']);
        }
        if (array_key_exists('avg_interval_days', $data)) {
            $fields[] = "avg_interval_days = :avg_interval_days";
            $params[':avg_interval_days'] = $data['avg_interval_days'] !== null && $data['avg_interval_days'] !== '' ? (float)$data['avg_interval_days'] : null;
        }
        if (isset($data['holiday_factor'])) {
            $fields[] = "holiday_factor = :holiday_factor";
            $params[':holiday_factor'] = (float)$data['holiday_factor'];
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $sql = "UPDATE product_master SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Schaltet den Ignorieren-Status für einen Artikel um (1-Klick-Aktion).
     *
     * @param int $id
     * @param bool|null $force Neuer Status oder null für Umschalten
     * @return bool|null Neuer Zustand (true=ignoriert, false=aktiv) oder null bei Fehler
     */
    public function toggleIgnore(int $id, ?bool $force = null): ?bool
    {
        $product = $this->findById($id);
        if (!$product) {
            return null;
        }

        $current = (int)($product['is_ignored'] ?? 0);
        $newStatus = $force !== null ? ($force ? 1 : 0) : ($current === 1 ? 0 : 1);

        $stmt = $this->pdo->prepare("UPDATE product_master SET is_ignored = :is_ignored, updated_at = NOW() WHERE id = :id");
        $stmt->execute([
            ':is_ignored' => $newStatus,
            ':id' => $id,
        ]);

        return (bool)$newStatus;
    }

    /**
     * Aktualisiert Kaufdatum und Verbrauchsintervall nach einem Wocheneinkauf.
     */
    public function recordPurchase(int $productId, string $purchaseDate, bool $isSpontaneous = false): void
    {
        $product = $this->findById($productId);
        if (!$product) {
            return;
        }

        $lastPurchased = $product['last_purchased_at'];
        $currentInterval = $product['avg_interval_days'] !== null ? (float)$product['avg_interval_days'] : null;

        // Wenn nicht spontan und ein altes Kaufdatum vorliegt: gleitenden Durchschnitt berechnen
        if (!$isSpontaneous && $lastPurchased !== null) {
            $daysDiff = (strtotime($purchaseDate) - strtotime($lastPurchased)) / 86400;
            if ($daysDiff > 0 && $daysDiff < 180) { // Plausibilitätsbereich: zwischen 1 Tag und 6 Monaten
                if ($currentInterval !== null && $currentInterval > 0) {
                    // Exponentiell geglättetes / gewichtetes Intervall (70% alt, 30% neu)
                    $newInterval = ($currentInterval * 0.7) + ($daysDiff * 0.3);
                } else {
                    $newInterval = (float)$daysDiff;
                }
                $currentInterval = round($newInterval, 1);
            }
        }

        $stmt = $this->pdo->prepare("
            UPDATE product_master SET
                last_purchased_at = :purchase_date,
                avg_interval_days = :interval_days,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':purchase_date' => $purchaseDate,
            ':interval_days' => $currentInterval,
            ':id' => $productId,
        ]);
    }

    /**
     * Liefert alle Artikel, für die Verbrauchsintervalle und Kaufdaten vorliegen.
     * Schließt ignorierte Artikel (is_ignored = 1) strikt aus.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPredictableProducts(): array
    {
        $stmt = $this->pdo->query("
            SELECT *,
                   COALESCE(NULLIF(custom_label, ''), name) AS display_name
            FROM product_master
            WHERE is_ignored = 0
              AND avg_interval_days IS NOT NULL 
              AND avg_interval_days > 0 
              AND last_purchased_at IS NOT NULL
            ORDER BY display_name ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Löscht einen Artikel aus dem Artikelstamm.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM product_master WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
