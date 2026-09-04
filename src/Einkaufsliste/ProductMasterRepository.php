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
            SELECT * FROM product_master 
            WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))
            LIMIT 1
        ");
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Sucht einen Artikel anhand seiner ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM product_master WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Liefert alle Artikel des Artikelstamms.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query("
            SELECT * FROM product_master 
            ORDER BY preferred_market ASC, name ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sucht Artikel anhand eines Teilstrings für Autocomplete.
     */
    public function search(string $query, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM product_master
            WHERE name LIKE :query
            ORDER BY name ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':query', '%' . $query . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Speichert einen Artikel im Artikelstamm oder aktualisiert vorhandene Werte.
     *
     * @param array{
     *     name: string,
     *     preferred_market?: string,
     *     default_category?: ?string,
     *     default_unit?: ?string,
     *     avg_interval_days?: ?float,
     *     last_purchased_at?: ?string,
     *     holiday_factor?: float
     * } $data
     * @return int ID des Artikels
     */
    public function saveOrUpdate(array $data): int
    {
        $existing = $this->findByName($data['name']);

        if ($existing) {
            $id = (int)$existing['id'];
            $stmt = $this->pdo->prepare("
                UPDATE product_master SET
                    preferred_market = COALESCE(:preferred_market, preferred_market),
                    default_category = COALESCE(:default_category, default_category),
                    default_unit = COALESCE(:default_unit, default_unit),
                    avg_interval_days = COALESCE(:avg_interval_days, avg_interval_days),
                    last_purchased_at = COALESCE(:last_purchased_at, last_purchased_at),
                    holiday_factor = COALESCE(:holiday_factor, holiday_factor),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $id,
                ':preferred_market' => $data['preferred_market'] ?? null,
                ':default_category' => $data['default_category'] ?? null,
                ':default_unit' => $data['default_unit'] ?? null,
                ':avg_interval_days' => isset($data['avg_interval_days']) ? (float)$data['avg_interval_days'] : null,
                ':last_purchased_at' => $data['last_purchased_at'] ?? null,
                ':holiday_factor' => isset($data['holiday_factor']) ? (float)$data['holiday_factor'] : null,
            ]);
            return $id;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO product_master 
                (name, preferred_market, default_category, default_unit, avg_interval_days, last_purchased_at, holiday_factor)
            VALUES 
                (:name, :preferred_market, :default_category, :default_unit, :avg_interval_days, :last_purchased_at, :holiday_factor)
        ");
        $stmt->execute([
            ':name' => trim($data['name']),
            ':preferred_market' => $data['preferred_market'] ?? 'Rewe',
            ':default_category' => $data['default_category'] ?? null,
            ':default_unit' => $data['default_unit'] ?? 'Stück',
            ':avg_interval_days' => isset($data['avg_interval_days']) ? (float)$data['avg_interval_days'] : null,
            ':last_purchased_at' => $data['last_purchased_at'] ?? null,
            ':holiday_factor' => isset($data['holiday_factor']) ? (float)$data['holiday_factor'] : 1.00,
        ]);

        return (int)$this->pdo->lastInsertId();
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
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPredictableProducts(): array
    {
        $stmt = $this->pdo->query("
            SELECT * FROM product_master
            WHERE avg_interval_days IS NOT NULL 
              AND avg_interval_days > 0 
              AND last_purchased_at IS NOT NULL
            ORDER BY name ASC
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
