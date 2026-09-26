<?php

namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Verwaltet den lokalen Cache und die Abfrage-Warteschlange für Open Food Facts.
 * Dient als Schnittstelle für die asynchrone Delegierung an den lokalen Raspberry Pi.
 */
class OpenFoodFactsQueueRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Reiht ein Produkt in die Queue ein (Status: pending), falls noch nicht vorhanden.
     */
    public function enqueueProduct(string $productKey, string $searchTerm): void
    {
        $cleanKey = mb_strtolower(trim($productKey), 'UTF-8');
        $cleanSearch = trim($searchTerm);
        if ($cleanKey === '' || $cleanSearch === '') {
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO kb_off_products (product_key, search_term, status)
            VALUES (:key, :search, 'pending')
            ON DUPLICATE KEY UPDATE
                search_term = VALUES(search_term)
        ");
        $stmt->execute([
            ':key' => $cleanKey,
            ':search' => $cleanSearch,
        ]);
    }

    /**
     * Reiht mehrere Produkte in einem Rutsch ein.
     *
     * @param array<int, array{key: string, search: string}> $items
     */
    public function enqueueBatch(array $items): int
    {
        if (empty($items)) {
            return 0;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO kb_off_products (product_key, search_term, status)
            VALUES (:key, :search, 'pending')
            ON DUPLICATE KEY UPDATE search_term = VALUES(search_term)
        ");

        $count = 0;
        foreach ($items as $item) {
            $key = mb_strtolower(trim($item['key'] ?? ''), 'UTF-8');
            $search = trim($item['search'] ?? '');
            if ($key !== '' && $search !== '') {
                $stmt->execute([':key' => $key, ':search' => $search]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Holt anstehende Jobs für den Worker (Raspi).
     *
     * @return array<int, array{id: int, product_key: string, search_term: string}>
     */
    public function getPendingJobs(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, product_key, search_term, attempts
            FROM kb_off_products
            WHERE status = 'pending' AND attempts < 5
            ORDER BY last_queried_at ASC, id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $updateStmt = $this->pdo->prepare("
                UPDATE kb_off_products
                SET attempts = attempts + 1, last_queried_at = NOW()
                WHERE id IN ($placeholders)
            ");
            $updateStmt->execute($ids);
        }

        return $rows;
    }

    /**
     * Speichert das von Raspi ermittelte Ergebnis.
     *
     * @param array{
     *     product_key: string,
     *     found: bool,
     *     code?: string|null,
     *     product_name?: string|null,
     *     brands?: string|null,
     *     quantity?: string|null,
     *     nutriscore_grade?: string|null,
     *     image_url?: string|null,
     *     categories?: string|null,
     *     confidence?: float|null
     * } $data
     */
    public function saveResult(array $data): void
    {
        $key = mb_strtolower(trim($data['product_key'] ?? ''), 'UTF-8');
        if ($key === '') {
            return;
        }

        $found = (bool)($data['found'] ?? false);
        $status = $found ? 'completed' : 'not_found';

        $stmt = $this->pdo->prepare("
            INSERT INTO kb_off_products (
                product_key, search_term, status, code, product_name, brands,
                quantity, nutriscore_grade, image_url, categories, confidence, last_queried_at
            ) VALUES (
                :key, :search, :status, :code, :pname, :brands,
                :qty, :nutri, :img, :cats, :confidence, NOW()
            ) ON DUPLICATE KEY UPDATE
                status = :status_up,
                code = :code_up,
                product_name = :pname_up,
                brands = :brands_up,
                quantity = :qty_up,
                nutriscore_grade = :nutri_up,
                image_url = :img_up,
                categories = :cats_up,
                confidence = :confidence_up,
                last_queried_at = NOW()
        ");

        $code = !empty($data['code']) ? (string)$data['code'] : null;
        $pname = !empty($data['product_name']) ? (string)$data['product_name'] : null;
        $brands = !empty($data['brands']) ? (string)$data['brands'] : null;
        $qty = !empty($data['quantity']) ? (string)$data['quantity'] : null;
        $nutri = !empty($data['nutriscore_grade']) ? strtoupper((string)$data['nutriscore_grade']) : null;
        $img = !empty($data['image_url']) ? (string)$data['image_url'] : null;
        $cats = !empty($data['categories']) ? (string)$data['categories'] : null;
        $search = (string)($data['search_term'] ?? $key);
        $confidence = isset($data['confidence']) ? round((float)$data['confidence'], 2) : null;

        $params = [
            ':key' => $key,
            ':search' => $search,
            ':status' => $status,
            ':code' => $code,
            ':pname' => $pname,
            ':brands' => $brands,
            ':qty' => $qty,
            ':nutri' => $nutri,
            ':img' => $img,
            ':cats' => $cats,
            ':confidence' => $confidence,
            ':status_up' => $status,
            ':code_up' => $code,
            ':pname_up' => $pname,
            ':brands_up' => $brands,
            ':qty_up' => $qty,
            ':nutri_up' => $nutri,
            ':img_up' => $img,
            ':cats_up' => $cats,
            ':confidence_up' => $confidence,
        ];

        $stmt->execute($params);
    }

    /**
     * Liest die gecachten Daten für einen Produkt-Key aus.
     */
    public function getProduct(string $productKey): ?array
    {
        $key = mb_strtolower(trim($productKey), 'UTF-8');
        $stmt = $this->pdo->prepare("SELECT * FROM kb_off_products WHERE product_key = :key LIMIT 1");
        $stmt->execute([':key' => $key]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Liest für mehrere Keys die gecachten Daten (Batch).
     *
     * @param string[] $keys
     * @return array<string, array<string, mixed>>
     */
    public function getProductsByKeys(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $cleanKeys = array_map(fn($k) => mb_strtolower(trim($k), 'UTF-8'), $keys);
        $cleanKeys = array_values(array_unique(array_filter($cleanKeys)));

        if (empty($cleanKeys)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cleanKeys), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM kb_off_products WHERE product_key IN ($placeholders)");
        $stmt->execute($cleanKeys);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['product_key']] = $row;
        }

        return $result;
    }

    /**
     * Reiht alle kb_items-Artikel in die OFF-Queue ein, die:
     *   a) noch keinen Eintrag in kb_off_products haben, ODER
     *   b) den Status 'not_found' haben UND last_queried_at älter als 90 Tage ist.
     *
     * Schritt 1: INSERT IGNORE für komplett neue Artikel.
     * Schritt 2: UPDATE für abgelaufene not_found-Einträge (90-Tage-Cooldown).
     *
     * @return int Anzahl der neu in die Queue eingereihten Artikel (nur Schritt 1).
     */
    public function enqueueAllPendingItems(): int
    {
        // Schritt 1: Alle kb_items eintragen, die noch gar nicht in der Queue sind.
        // INSERT IGNORE sorgt dafür, dass bestehende Einträge (egal welchen Status)
        // unberührt bleiben; nur echte Neulinge werden eingefügt.
        $insertStmt = $this->pdo->prepare("
            INSERT IGNORE INTO kb_off_products (product_key, search_term, status)
            SELECT
                LOWER(TRIM(ki.name)) AS product_key,
                ki.name              AS search_term,
                'pending'            AS status
            FROM kb_items AS ki
            LEFT JOIN kb_off_products AS off
                ON off.product_key = LOWER(TRIM(ki.name))
            WHERE off.product_key IS NULL
              AND TRIM(ki.name) != ''
        ");
        $insertStmt->execute();
        $newlyInserted = (int) $insertStmt->rowCount();

        // Schritt 2: Abgelaufene not_found-Einträge zurücksetzen (90-Tage-Cooldown).
        // Kein Schema-Change nötig – der Cron-Job in mail.php übernimmt das Auffrischen.
        $refreshStmt = $this->pdo->prepare("
            UPDATE kb_off_products
            SET status          = 'pending',
                attempts        = 0,
                last_queried_at = NULL
            WHERE status          = 'not_found'
              AND last_queried_at < NOW() - INTERVAL 90 DAY
        ");
        $refreshStmt->execute();

        return $newlyInserted;
    }
}

