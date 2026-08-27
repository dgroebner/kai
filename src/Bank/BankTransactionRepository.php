<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Db\Database;
use PDO;

class BankTransactionRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Importiert Transaktionen.
     */
    public function importTransactions(array $transactions): array
    {
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO bank_giro_transactions 
            (account_id, transaction_id, booking_date, valuta_date, type, amount, remittance_info, remitter, debitor, creditor, end_to_end_reference, dc_creditor_id, dc_mandate_id) 
            VALUES (:account_id, :transaction_id, :booking_date, :valuta_date, :type, :amount, :remittance_info, :remitter, :debitor, :creditor, :end_to_end_reference, :dc_creditor_id, :dc_mandate_id)
        ");

        $imported = 0;
        $ignored = 0;

        foreach ($transactions as $tx) {
            $stmt->execute([
                ':account_id' => $tx['account_id'] ?? null,
                ':transaction_id' => $tx['transaction_id'] ?? null,
                ':booking_date' => $tx['booking_date'],
                ':valuta_date' => $tx['valuta_date'],
                ':type' => $tx['type'] ?? null,
                ':remittance_info' => $tx['remittance_info'] ?? '',
                ':amount' => $tx['amount'],
                ':remitter' => $tx['remitter'],
                ':debitor' => $tx['debitor'],
                ':creditor' => $tx['creditor'],
                ':end_to_end_reference' => $tx['end_to_end_reference'],
                ':dc_creditor_id' => $tx['dc_creditor_id'],
                ':dc_mandate_id' => $tx['dc_mandate_id'],
            ]);

            if ($stmt->rowCount() > 0) {
                $imported++;
            } else {
                $ignored++;
            }
        }

        return [
            'imported' => $imported,
            'ignored' => $ignored
        ];
    }

    /**
     * Hilfsmethode, um für alte Importe das Standard-Girokonto zu ermitteln.
     */
    public function getDefaultCheckingAccountId(): ?int
    {
        $stmt = $this->pdo->query("
            SELECT id 
            FROM bank_accounts 
            WHERE account_type = 'checking' 
            ORDER BY id ASC LIMIT 1
        ");

        $result = $stmt->fetchColumn();
        return $result !== false ? (int)$result : null;
    }

    /**
     * Lädt alle Transaktionen, die noch kein Tag in bank_transaction_tags besitzen.
     */
    public function getUntaggedTransactions(): array
    {
        $stmt = $this->pdo->query("
            SELECT t.id, t.remittance_info 
            FROM bank_giro_transactions t
            LEFT JOIN bank_transaction_tags tt ON t.id = tt.transaction_id
            WHERE tt.transaction_id IS NULL
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Weist einer Transaktion ein oder mehrere Tag-IDs zu.
     */
    public function assignTagsToTransaction(int $transactionId, array $tagIds): void
    {
        if (empty($tagIds)) {
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO bank_transaction_tags (transaction_id, tag_id) 
            VALUES (:transaction_id, :tag_id)
        ");

        foreach ($tagIds as $tagId) {
            $stmt->execute([
                ':transaction_id' => $transactionId,
                ':tag_id' => (int)$tagId
            ]);
        }
    }

    /**
     * Gibt alle bekannten Tag-Namen zurück.
     */
    public function getAllTagNames(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM bank_tags");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Ermittelt die Tag-IDs zu einer Liste von Tag-Namen.
     */
    public function getTagIdsByNames(array $tagNames): array
    {
        if (empty($tagNames)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($tagNames), '?'));
        $stmt = $this->pdo->prepare("SELECT id FROM bank_tags WHERE name IN ($placeholders)");
        $stmt->execute($tagNames);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Lädt die für Vertragserkennung relevanten Felder eines Umsatzes.
     */
    public function getTransactionById(int $transactionId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, remittance_info, remitter, creditor, debitor, dc_mandate_id, dc_creditor_id, amount
            FROM bank_giro_transactions
            WHERE id = :id
        ");
        $stmt->execute([':id' => $transactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Setzt (oder entfernt) die getroffene Tagging-Regel eines Umsatzes.
     */
    public function setMatchedRule(int $transactionId, ?int $ruleId): void
    {
        $stmt = $this->pdo->prepare("UPDATE bank_giro_transactions SET matched_rule_id = :rule_id WHERE id = :tx_id");
        $stmt->execute([':rule_id' => $ruleId, ':tx_id' => $transactionId]);
    }

    /**
     * Ermittelt alle Umsatz-IDs, die einer bestimmten Tagging-Regel zugeordnet sind.
     *
     * @return int[]
     */
    public function getTransactionIdsByRule(int $ruleId): array
    {
        $stmt = $this->pdo->prepare("SELECT id FROM bank_giro_transactions WHERE matched_rule_id = :rule_id");
        $stmt->execute([':rule_id' => $ruleId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Verknüpft einen Umsatz mit einem Vertrag (oder löst die Verknüpfung mit null).
     */
    public function assignContract(int $transactionId, ?int $contractId): void
    {
        $stmt = $this->pdo->prepare("UPDATE bank_giro_transactions SET contract_id = :contract_id WHERE id = :tx_id");
        $stmt->execute([':contract_id' => $contractId, ':tx_id' => $transactionId]);
    }

    /**
     * Hebt die Vertragszuordnung aller Umsätze eines Vertrags auf.
     */
    public function unlinkContract(int $contractId): void
    {
        $stmt = $this->pdo->prepare("UPDATE bank_giro_transactions SET contract_id = NULL WHERE contract_id = :id");
        $stmt->execute([':id' => $contractId]);
    }

    /**
     * Zählt die Umsätze, auf die die übergebenen Vertragsmuster zutreffen (Live-Test im Regel-Editor).
     * Alle angegebenen Kriterien wirken als UND-Verknüpfung; leere Kriterien werden ignoriert.
     */
    public function countMatchingContractPatterns(
        ?string $mandateId = null,
        ?string $creditorId = null,
        ?string $payee = null,
        ?string $textPattern = null
    ): int
    {
        $conditions = [];
        $params = [];

        if ($mandateId !== null && $mandateId !== '') {
            $conditions[] = "dc_mandate_id = :mandate_id";
            $params[':mandate_id'] = $mandateId;
        }
        if ($creditorId !== null && $creditorId !== '') {
            $conditions[] = "dc_creditor_id = :creditor_id";
            $params[':creditor_id'] = $creditorId;
        }
        if ($payee !== null && $payee !== '') {
            $conditions[] = "(remitter LIKE :payee_remitter OR creditor LIKE :payee_creditor)";
            $params[':payee_remitter'] = '%' . $payee . '%';
            $params[':payee_creditor'] = '%' . $payee . '%';
        }
        if ($textPattern !== null && $textPattern !== '') {
            $conditions[] = "remittance_info REGEXP :text_pattern";
            $params[':text_pattern'] = $textPattern;
        }

        if ($conditions === []) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM bank_giro_transactions WHERE " . implode(' AND ', $conditions)
        );
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }
}