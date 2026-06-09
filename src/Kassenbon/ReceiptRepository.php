<?php
namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\DB\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use Exception;

class ReceiptRepository {
    private PDO $db;
    private Logger $logger;

    public function __construct() {
        // Holt sich die exakt gleiche Verbindung, die wir vorhin getestet haben
        $this->db = Database::getInstance()->getConnection();
        $this->logger = new Logger(14);
    }

    /**
     * Holt alle bisher bekannten Kategorien aus der Datenbank für den Gemini-Kontext.
     */
    public function getKnownCategories(): array {
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
     * Speichert den Bon und alle Positionen atomar in die Datenbank.
     */
    public function saveReceipt(array $data): int {
        try {
            $this->logger->info("ReceiptRepository: Starte Transaktion für Kassenbon von '{$data['store']}'...");
            $this->db->beginTransaction();

            // 1. Metadaten in kb_receipts speichern
            $stmtReceipt = $this->db->prepare("
                INSERT INTO kb_receipts (store, purchase_date, total) 
                VALUES (:store, :date, :total)
            ");
            
            $stmtReceipt->execute([
                ':store' => $data['store'] ?? 'Unbekannt',
                ':date'  => $data['date'] ?? date('Y-m-d'),
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
                $quantity   = (float) ($item['quantity'] ?? 1.000);
                $unitPrice  = (float) ($item['unit_price'] ?? 0.00);
                $totalPrice = (float) ($item['total_price'] ?? 0.00);

                $stmtItem->execute([
                    ':receipt_id'  => $receiptId,
                    ':name'        => $item['name'] ?? 'Unbekannt',
                    ':quantity'    => $quantity,
                    ':unit_price'  => $unitPrice,
                    ':total_price' => $totalPrice,
                    ':category'    => $item['category'] ?? 'Sonstiges'
                ]);
                $itemCount++;
            }

            // 3. Transaktion abschließen
            $this->db->commit();
            $this->logger->info("ReceiptRepository: Bon erfolgreich gespeichert. ID: {$receiptId} mit {$itemCount} Positionen.");
            
            return $receiptId;

        } catch (Exception $e) {
            // Im Fehlerfall alles verwerfen!
            $this->db->rollBack();
            $this->logger->error("ReceiptRepository: Transaktion fehlgeschlagen! Rollback ausgeführt.", ['error' => $e->getMessage()]);
            throw new Exception("Kassenbon konnte nicht in der Datenbank gespeichert werden.");
        }
    }
}