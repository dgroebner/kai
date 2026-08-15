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
                  OR :merchant_short != '' AND merchant_raw LIKE :merchant_short
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
                  OR :merchant_short != '' AND merchant_name LIKE :merchant_short
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
            $merchantShort = strlen($storeName) > 3 ? '%' . substr($storeName, 0, 4) . '%' : '';

            // 1. Erst auf dem Girokonto suchen
            $stmtFindGiro->execute([
                ':amount'         => $expectedGiroAmount,
                ':date_start'     => $dateStart,
                ':date_end'       => $dateEnd,
                ':merchant'       => $merchantParam,
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
}