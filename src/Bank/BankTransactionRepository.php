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
     * Importiert Transaktionen und ignoriert bereits vorhandene Zeilen (Hash-Match).
     *
     * @param array $transactions
     * @return array Statistik der verarbeiteten Daten
     */
    public function importTransactions(array $transactions): array
    {
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO bank_giro_transactions 
            (tx_hash, booking_date, valuta_date, type, merchant_raw, amount) 
            VALUES (:tx_hash, :booking_date, :valuta_date, :type, :merchant_raw, :amount)
        ");

        $imported = 0;
        $ignored  = 0;

        foreach ($transactions as $tx) {
            $stmt->execute([
                ':tx_hash'      => $tx['tx_hash'],
                ':booking_date' => $tx['booking_date'],
                ':valuta_date'  => $tx['valuta_date'],
                ':type'         => $tx['type'],
                ':merchant_raw' => $tx['raw_text'],
                ':amount'       => $tx['amount'],
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
}