<?php

namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Lesezugriffe auf Kassenbons und deren Positionen für die Ansichten
 * unter `public/kassenbon/`.
 *
 * Die Schreib- und Importlogik liegt bewusst getrennt in `ReceiptRepository`.
 */
class ReceiptQueryRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Gesamtzahl der gespeicherten Bons (für die Paginierung).
     */
    public function countReceipts(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM kb_receipts")->fetchColumn();
    }

    /**
     * Eine Seite der Bon-Übersicht inklusive Positionsanzahl und
     * Buchungsdaten der verknüpften Giro- bzw. Kreditkartenumsätze.
     */
    public function getReceipts(int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                r.*,
                COUNT(i.id) as item_count,
                gt.booking_date AS giro_booking_date,
                ct.booking_date AS cc_booking_date,
                ct.statement_id AS cc_statement_id
            FROM kb_receipts r
            LEFT JOIN kb_items i ON r.id = i.receipt_id
            LEFT JOIN bank_giro_transactions gt ON r.bank_giro_transaction_id = gt.id
            LEFT JOIN bank_cc_transactions ct ON r.bank_cc_transaction_id = ct.id
            GROUP BY r.id
            ORDER BY r.purchase_date DESC, r.id DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Einzelnen Bon inklusive Verknüpfung zu Girokonto oder Kreditkarte laden.
     */
    public function getReceiptById(int $receiptId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                r.*,
                gt.booking_date AS giro_booking_date,
                ct.booking_date AS cc_booking_date,
                ct.statement_id AS cc_statement_id
            FROM kb_receipts r
            LEFT JOIN bank_giro_transactions gt ON r.bank_giro_transaction_id = gt.id
            LEFT JOIN bank_cc_transactions ct ON r.bank_cc_transaction_id = ct.id
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => $receiptId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Alle Positionen eines Bons in Erfassungsreihenfolge.
     */
    public function getItemsForReceipt(int $receiptId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM kb_items WHERE receipt_id = :id ORDER BY id");
        $stmt->execute([':id' => $receiptId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Alle Positionen eines Zeitraums inklusive Einkaufsdatum für die Auswertung.
     */
    public function getItemsForPeriod(string $startDate, string $endDate): array
    {
        $stmt = $this->pdo->prepare("
            SELECT i.*, r.purchase_date
            FROM kb_items i
            JOIN kb_receipts r ON i.receipt_id = r.id
            WHERE r.purchase_date BETWEEN :start AND :end
            ORDER BY r.purchase_date DESC, r.id DESC, i.id
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
