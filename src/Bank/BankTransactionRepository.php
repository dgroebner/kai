<?php
namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Db\Database;

class BankTransactionRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Importiert Transaktionen.
     * Unterstützt sowohl das alte CSV-Format (ohne account_id/transaction_id) 
     * als auch das neue API-Format.
     */
    public function importTransactions(array $transactions): array
    {
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO bank_giro_transactions 
            (account_id, tx_hash, transaction_id, booking_date, valuta_date, type, merchant_raw, amount) 
            VALUES (:account_id, :tx_hash, :transaction_id, :booking_date, :valuta_date, :type, :merchant_raw, :amount)
        ");

        $imported = 0;
        $ignored  = 0;

        foreach ($transactions as $tx) {
            $stmt->execute([
                ':account_id'     => $tx['account_id'] ?? null,
                ':tx_hash'        => $tx['tx_hash'],
                ':transaction_id' => $tx['transaction_id'] ?? null,
                ':booking_date'   => $tx['booking_date'],
                ':valuta_date'    => $tx['valuta_date'],
                ':type'           => $tx['type'] ?? null,
                ':merchant_raw'   => $tx['raw_text'] ?? '',
                ':amount'         => $tx['amount'],
            ]);

            if ($stmt->rowCount() > 0) {
                $imported++;
            } else {
                $ignored++;
            }
        }

        return [
            'imported' => $imported,
            'ignored'  => $ignored
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
            SELECT t.id, t.merchant_raw 
            FROM bank_giro_transactions t
            LEFT JOIN bank_transaction_tags tt ON t.id = tt.transaction_id
            WHERE tt.transaction_id IS NULL
        ");

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
                ':tag_id'         => (int)$tagId
            ]);
        }
    }

    /**
     * Gibt alle bekannten Tag-Namen zurück.
     */
    public function getAllTagNames(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM bank_tags");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
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

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}