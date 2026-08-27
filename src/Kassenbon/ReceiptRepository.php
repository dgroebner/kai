<?php

namespace Kai\Tools\Kassenbon;

use Exception;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;

class ReceiptRepository
{
    private PDO $db;
    private Logger $logger;

    public function __construct()
    {
        // Holt sich die exakt gleiche Verbindung, die wir vorhin getestet haben
        $this->db = Database::getInstance()->getConnection();
        $this->logger = new Logger(14);
    }

    /**
     * Holt alle bisher bekannten Kategorien aus der Datenbank für den Gemini-Kontext.
     */
    public function getKnownCategories(): array
    {
        try {
            $stmt = $this->db->query("SELECT DISTINCT category FROM kb_items WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
            $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $this->logger->info("ReceiptRepository: " . count($categories) . " bekannte Kategorien geladen.");
            return $categories;
        } catch (Exception $e) {
            $this->logger->error("ReceiptRepository: Fehler beim Laden der Kategorien.", ['error' => $e->getMessage()]);
            return []; // Im Fehlerfall leeres Array zurückgeben, damit der Prozess weiterläuft
        }
    }

    /**
     * Prüft, ob ein Dateihash bereits in der Datenbank existiert.
     */
    public function receiptExists(string $hash): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM kb_receipts WHERE file_hash = :hash");
        $stmt->execute([':hash' => $hash]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Speichert den Bon. (Signatur um $fileHash erweitert)
     * @throws Exception
     */
    public function saveReceipt(array $data, ?string $fileHash = null): int
    {
        try {
            $this->logger->info("ReceiptRepository: Starte Transaktion für Kassenbon von '{$data['store']}'...");
            $this->db->beginTransaction();

            // 1. Metadaten in kb_receipts speichern
            $stmtReceipt = $this->db->prepare("
				INSERT INTO kb_receipts (file_hash, store, purchase_date, total) 
				VALUES (:hash, :store, :date, :total)
			");

            $stmtReceipt->execute([
                ':hash' => $fileHash,
                ':store' => $data['store'] ?? 'Unbekannt',
                ':date' => $data['date'] ?? date('Y-m-d'),
                ':total' => $data['total'] ?? 0.00
            ]);

            $receiptId = $this->db->lastInsertId();

            // 2. Positionen in kb_items speichern
            $stmtItem = $this->db->prepare("
                INSERT INTO kb_items (receipt_id, name, quantity, unit_price, total_price, category) 
                VALUES (:receipt_id, :name, :quantity, :unit_price, :total_price, :category)
            ");

            $itemCount = 0;
            foreach ($data['items'] as $item) {
                // Typ-Sicherheit: Falls die KI Preise mal als String schickt, normalisieren wir sie
                $quantity = (float)($item['quantity'] ?? 1.000);
                $unitPrice = (float)($item['unit_price'] ?? 0.00);
                $totalPrice = (float)($item['total_price'] ?? 0.00);

                $stmtItem->execute([
                    ':receipt_id' => $receiptId,
                    ':name' => $item['name'] ?? 'Unbekannt',
                    ':quantity' => $quantity,
                    ':unit_price' => $unitPrice,
                    ':total_price' => $totalPrice,
                    ':category' => $item['category'] ?? 'Sonstiges'
                ]);
                $itemCount++;
            }

            // 3. Transaktion abschließen
            $this->db->commit();
            $this->logger->info("ReceiptRepository: Bon erfolgreich gespeichert. ID: $receiptId mit $itemCount Positionen.");

            return $receiptId;

        } catch (Exception $e) {
            // Im Fehlerfall alles verwerfen!
            $this->db->rollBack();
            $this->logger->error("ReceiptRepository: Transaktion fehlgeschlagen! Rollback ausgeführt.", ['error' => $e->getMessage()]);
            throw new Exception("Kassenbon konnte nicht in der Datenbank gespeichert werden.");
        }
    }

    /**
     * Sucht nach der zuletzt verwendeten Kategorie für einen bestimmten Artikelnamen.
     */
    public function getKnownCategoryForProduct(string $productName): ?string
    {
        $stmt = $this->db->prepare("
            SELECT category 
            FROM kb_items 
            WHERE name = :name AND category != '' AND category IS NOT NULL
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([':name' => $productName]);
        $result = $stmt->fetchColumn();

        return $result ?: null;
    }

    /**
     * Gibt für eine Liste von Artikelnamen die jeweils zuletzt verwendete Kategorie zurück.
     * Vermeidet N+1-Queries durch eine einzige IN-Abfrage.
     *
     * @param string[] $productNames
     * @return array<string, string> Map von Artikelname => Kategorie
     */
    public function getKnownCategoriesForProducts(array $productNames): array
    {
        if (empty($productNames)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productNames), '?'));
        $stmt = $this->db->prepare("
            SELECT name, category
            FROM kb_items
            WHERE name IN ($placeholders)
              AND category != ''
              AND category IS NOT NULL
            ORDER BY id DESC
        ");
        $stmt->execute($productNames);
        $rows = $stmt->fetchAll();

        // Nur den ersten (neuesten) Treffer pro Artikelname behalten
        $result = [];
        foreach ($rows as $row) {
            if (!isset($result[$row['name']])) {
                $result[$row['name']] = $row['category'];
            }
        }
        return $result;
    }

    /**
     * Ändert die Kategorie einer einzelnen Bon-Position.
     */
    public function updateItemCategory(int $itemId, string $categoryName): void
    {
        $stmt = $this->db->prepare("UPDATE kb_items SET category = :cat WHERE id = :id");
        $stmt->execute([':cat' => $categoryName, ':id' => $itemId]);
    }
}