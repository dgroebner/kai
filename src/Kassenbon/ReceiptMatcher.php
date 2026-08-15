<?php

namespace Kai\Tools\Kassenbon;

use PDO;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;

class ReceiptMatcher
{
    private PDO $pdo;
    private Logger $logger;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->logger = new Logger(14);
    }

    /**
     * Gleicht alle unverknüpften Kassenbons mit Girokonto- und Kreditkarten-Umsätzen ab.
     *
     * @return array Summe der Verknüpfungen ['giro' => int, 'cc' => int]
     */
    public function syncUnlinkedReceipts(): array
    {
        $stmtUnlinked = $this->pdo->query("
            SELECT id, purchase_date, total, store 
            FROM kb_receipts 
            WHERE bank_giro_transaction_id IS NULL 
              AND bank_cc_transaction_id IS NULL
        ");
        $unlinkedReceipts = $stmtUnlinked->fetchAll(PDO::FETCH_ASSOC);

        if (empty($unlinkedReceipts)) {
            return ['giro' => 0, 'cc' => 0];
        }

        $linkedGiro = 0;
        $linkedCc = 0;

        // Query A: Girokonto-Umsatz suchen (Betrag negativ)
        $stmtFindGiro = $this->pdo->prepare("
            SELECT id 
            FROM bank_giro_transactions 
            WHERE amount = :amount 
              AND booking_date BETWEEN :date_start AND :date_end
              AND (
                  merchant_raw LIKE :merchant
                  OR (:has_short = 1 AND merchant_raw LIKE :merchant_short)
              )
            ORDER BY booking_date ASC 
            LIMIT 1
        ");

        // Query B: Kreditkarten-Transaktion suchen
        $stmtFindCc = $this->pdo->prepare("
            SELECT id 
            FROM bank_cc_transactions 
            WHERE (amount = :amount OR amount = :amount_neg)
              AND booking_date BETWEEN :date_start AND :date_end
              AND (
                  merchant_name LIKE :merchant
                  OR (:has_short = 1 AND merchant_name LIKE :merchant_short)
              )
            ORDER BY booking_date ASC 
            LIMIT 1
        ");

        $stmtUpdateGiro = $this->pdo->prepare("UPDATE kb_receipts SET bank_giro_transaction_id = :tx_id WHERE id = :receipt_id");
        $stmtUpdateCc   = $this->pdo->prepare("UPDATE kb_receipts SET bank_cc_transaction_id = :tx_id WHERE id = :receipt_id");

        foreach ($unlinkedReceipts as $receipt) {
            $receiptId    = (int)$receipt['id'];
            $purchaseDate = date('Y-m-d', strtotime($receipt['purchase_date']));
            $totalAmount  = (float)$receipt['total'];
            $storeName    = trim((string)$receipt['store']);

            $expectedGiroAmount = -abs($totalAmount);
            $expectedCcAmount   = abs($totalAmount);

            $dateStart = $purchaseDate;
            $dateEnd   = date('Y-m-d', strtotime($purchaseDate . ' +14 days'));

            $merchantParam = '%' . $storeName . '%';
            $hasShort      = strlen($storeName) > 3 ? 1 : 0;
            $merchantShort = $hasShort ? '%' . substr($storeName, 0, 4) . '%' : '';

            // 1. Erst auf dem Girokonto suchen
            $stmtFindGiro->execute([
                ':amount'         => $expectedGiroAmount,
                ':date_start'     => $dateStart,
                ':date_end'       => $dateEnd,
                ':merchant'       => $merchantParam,
                ':has_short'      => $hasShort,
                ':merchant_short' => $merchantShort
            ]);
            $giroTxId = $stmtFindGiro->fetchColumn();

            if ($giroTxId) {
                $stmtUpdateGiro->execute([':tx_id' => $giroTxId, ':receipt_id' => $receiptId]);
                $linkedGiro++;
                $this->logger->info("ReceiptMatcher: Kassenbon #{$receiptId} mit Giro-Transaktion #{$giroTxId} verknüpft.");
                continue;
            }

            // 2. Falls nicht auf Giro, auf Kreditkarte suchen
            $stmtFindCc->execute([
                ':amount'         => $expectedCcAmount,
                ':amount_neg'     => -$expectedCcAmount,
                ':date_start'     => $dateStart,
                ':date_end'       => $dateEnd,
                ':merchant'       => $merchantParam,
                ':has_short'      => $hasShort,
                ':merchant_short' => $merchantShort
            ]);
            $ccTxId = $stmtFindCc->fetchColumn();

            if ($ccTxId) {
                $stmtUpdateCc->execute([':tx_id' => $ccTxId, ':receipt_id' => $receiptId]);
                $linkedCc++;
                $this->logger->info("ReceiptMatcher: Kassenbon #{$receiptId} mit KK-Transaktion #{$ccTxId} verknüpft.");
            }
        }

        return ['giro' => $linkedGiro, 'cc' => $linkedCc];
    }

    /**
     * Ermittelt potenzielle Giro- und Kreditkarten-Kandidaten für einen Bon im erweiterten Zeitfenster (+10 Tage).
     */
    public function getCandidatesForReceipt(int $receiptId): array
    {
        $stmt = $this->pdo->prepare("SELECT purchase_date, total, store FROM kb_receipts WHERE id = :id");
        $stmt->execute([':id' => $receiptId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$receipt) {
            return ['giro' => [], 'cc' => []];
        }

        $purchaseDate = date('Y-m-d', strtotime($receipt['purchase_date']));
        $dateEnd = date('Y-m-d', strtotime($purchaseDate . ' +10 days'));
        $storeName = trim((string)$receipt['store']);
        $merchantParam = '%' . $storeName . '%';

        // 1. Giro-Kandidaten (unverknüpft im Zeitraum)[cite: 16]
        $stmtGiro = $this->pdo->prepare("
            SELECT id, booking_date, amount, merchant_raw, 'giro' AS account_type
            FROM bank_giro_transactions
            WHERE booking_date BETWEEN :date_start AND :date_end
              AND merchant_raw LIKE :merchant
              AND id NOT IN (SELECT bank_giro_transaction_id FROM kb_receipts WHERE bank_giro_transaction_id IS NOT NULL)
            ORDER BY booking_date ASC
        ");
        $stmtGiro->execute([
            ':date_start' => $purchaseDate, 
            ':date_end'   => $dateEnd, 
            ':merchant'   => $merchantParam
        ]);
        $giroCandidates = $stmtGiro->fetchAll(PDO::FETCH_ASSOC);

        // 2. Kreditkarten-Kandidaten (unverknüpft im Zeitraum)[cite: 16]
        $stmtCc = $this->pdo->prepare("
            SELECT id, booking_date, amount, merchant_name AS merchant_raw, 'cc' AS account_type
            FROM bank_cc_transactions
            WHERE booking_date BETWEEN :date_start AND :date_end
              AND merchant_name LIKE :merchant
              AND id NOT IN (SELECT bank_cc_transaction_id FROM kb_receipts WHERE bank_cc_transaction_id IS NOT NULL)
            ORDER BY booking_date ASC
        ");
        $stmtCc->execute([
            ':date_start' => $purchaseDate, 
            ':date_end'   => $dateEnd, 
            ':merchant'   => $merchantParam
        ]);
        $ccCandidates = $stmtCc->fetchAll(PDO::FETCH_ASSOC);

        return [
            'giro' => $giroCandidates,
            'cc'   => $ccCandidates
        ];
    }

    /**
     * Verknüpft einen Kassenbon manuell mit einer Transaktion und setzt optional das Bargeld-Tag[cite: 16].
     */
    public function linkReceiptManually(int $receiptId, int $txId, string $accountType, bool $applyCashTag = false): bool
    {
        if ($accountType === 'giro') {
            $stmt = $this->pdo->prepare("UPDATE kb_receipts SET bank_giro_transaction_id = :tx_id, bank_cc_transaction_id = NULL WHERE id = :receipt_id");
            $stmt->execute([':tx_id' => $txId, ':receipt_id' => $receiptId]);

            if ($applyCashTag) {
                $this->assignCashTagToGiroTx($txId);
            }
        } elseif ($accountType === 'cc') {
            $stmt = $this->pdo->prepare("UPDATE kb_receipts SET bank_cc_transaction_id = :tx_id, bank_giro_transaction_id = NULL WHERE id = :receipt_id");
            $stmt->execute([':tx_id' => $txId, ':receipt_id' => $receiptId]);
        } else {
            return false;
        }

        $this->logger->info("ReceiptMatcher: Kassenbon #{$receiptId} manuell mit {$accountType}-Transaktion #{$txId} verknüpft.");
        return true;
    }

    /**
     * Hilfsmethode: Weist einer Giro-Transaktion das Tag "Bargeld" zu[cite: 16].
     */
    private function assignCashTagToGiroTx(int $txId): void
    {
        // Prüfen ob Tag "Bargeld" existiert, ansonsten anlegen
        $stmtTag = $this->pdo->prepare("SELECT id FROM bank_tags WHERE name = 'Bargeld' LIMIT 1");
        $stmtTag->execute();
        $tagId = $stmtTag->fetchColumn();

        if (!$tagId) {
            $stmtCreate = $this->pdo->prepare("INSERT INTO bank_tags (name, color) VALUES ('Bargeld', '#f59e0b')");
            $stmtCreate->execute();
            $tagId = $this->pdo->lastInsertId();
        }

        // Verknüpfung in bank_transaction_tags herstellen (Ignore falls bereits verknüpft)
        $stmtLink = $this->pdo->prepare("INSERT IGNORE INTO bank_transaction_tags (transaction_id, tag_id) VALUES (:tx_id, :tag_id)");
        $stmtLink->execute([':tx_id' => $txId, ':tag_id' => $tagId]);
    }
}