<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Datenbankzugriffe für Kreditkarten-Abrechnungen und -Umsätze
 * (`bank_cc_statements`, `bank_cc_transactions`, `bank_categories`).
 */
class CreditCardRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Gesamtzahl der importierten Abrechnungen (für die Paginierung).
     */
    public function countStatements(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM bank_cc_statements")->fetchColumn();
    }

    /**
     * Lädt eine Seite der Abrechnungsübersicht inklusive Umsatzanzahl und
     * Abbuchungsdatum der verknüpften Girokonto-Buchung.
     */
    public function getStatements(int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*,
                   COUNT(t.id) AS tx_count,
                   g.booking_date AS giro_booking_date
            FROM bank_cc_statements s
            LEFT JOIN bank_cc_transactions t ON s.id = t.statement_id
            LEFT JOIN bank_giro_transactions g ON s.bank_transaction_id = g.id
            GROUP BY s.id
            ORDER BY s.statement_date DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lädt eine einzelne Abrechnung inklusive des Abbuchungsdatums vom Girokonto.
     */
    public function getStatementById(int $statementId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*,
                   g.booking_date AS giro_booking_date
            FROM bank_cc_statements s
            LEFT JOIN bank_giro_transactions g ON s.bank_transaction_id = g.id
            WHERE s.id = :id
        ");
        $stmt->execute([':id' => $statementId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Lädt die Umsätze einer Abrechnung inklusive Kategorie und verknüpftem E-Bon.
     */
    public function getTransactionsForStatement(int $statementId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                t.*,
                c.name AS category_name,
                rec.id AS linked_receipt_id
            FROM bank_cc_transactions t
            LEFT JOIN bank_categories c ON t.category_id = c.id
            LEFT JOIN kb_receipts rec ON t.id = rec.bank_cc_transaction_id
            WHERE t.statement_id = :id
            ORDER BY t.booking_date DESC
        ");
        $stmt->execute([':id' => $statementId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lädt alle Kategorien für das Inline-Dropdown.
     */
    public function getAllCategories(): array
    {
        $stmt = $this->pdo->query("SELECT id, name FROM bank_categories ORDER BY name");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Weist einem Kreditkarten-Umsatz eine Kategorie zu.
     */
    public function updateTransactionCategory(int $transactionId, int $categoryId): void
    {
        $stmt = $this->pdo->prepare("UPDATE bank_cc_transactions SET category_id = :cat_id WHERE id = :tx_id");
        $stmt->execute([':cat_id' => $categoryId, ':tx_id' => $transactionId]);
    }
}
