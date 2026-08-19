<?php

namespace Kai\Tools\Bank;

use PDO;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;

class StatementMatcher
{
    private PDO $pdo;
    private Logger $logger;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->logger = new Logger(14);
    }

    /**
     * Gleicht alle unverknüpften Kreditkartenabrechnungen mit Girokonto-Umsätzen ab.
     *
     * @return int Anzahl neu verknüpfter Abrechnungen
     */
    public function syncUnlinkedStatements(): int
    {
        // 1. Alle noch nicht verknüpften Abrechnungen laden
        $stmtUnlinked = $this->pdo->query("
            SELECT id, statement_date, total_amount 
            FROM bank_cc_statements 
            WHERE bank_transaction_id IS NULL
        ");
        $unlinkedStatements = $stmtUnlinked->fetchAll(PDO::FETCH_ASSOC);

        if (empty($unlinkedStatements)) {
            return 0;
        }

        $linkedCount = 0;

        $stmtFindGiroTx = $this->pdo->prepare("
            SELECT id 
            FROM bank_giro_transactions 
            WHERE amount = :amount 
              AND booking_date BETWEEN :date_start AND :date_end
              AND (
                  remittance_info REGEXP 'Solaris|ADAC|Kreditkarte.*Abrechnung'
                  OR remittance_info LIKE '%Abrechnung%'
              )
            ORDER BY booking_date ASC 
            LIMIT 1
        ");

        $stmtUpdateStatement = $this->pdo->prepare("
            UPDATE bank_cc_statements 
            SET bank_transaction_id = :giro_id 
            WHERE id = :statement_id
        ");

        foreach ($unlinkedStatements as $statement) {
            $statementId = (int)$statement['id'];
            $statementDate = $statement['statement_date'];
            
            // Girokonto-Abbuchungen sind negativ -> Betrag invertieren
            $expectedGiroAmount = -abs((float)$statement['total_amount']);

            // Zeitfenster: statement_date bis 14 Tage danach
            $dateStart = $statementDate;
            $dateEnd   = date('Y-m-d', strtotime($statementDate . ' +14 days'));

            $stmtFindGiroTx->execute([
                ':amount'     => $expectedGiroAmount,
                ':date_start' => $dateStart,
                ':date_end'   => $dateEnd,
            ]);

            $giroTxId = $stmtFindGiroTx->fetchColumn();

            if ($giroTxId) {
                $stmtUpdateStatement->execute([
                    ':giro_id'       => (int)$giroTxId,
                    ':statement_id'  => $statementId,
                ]);
                $linkedCount++;
                $this->logger->info("StatementMatcher: Abrechnung #{$statementId} erfolgreich mit Giro-Transaktion #{$giroTxId} verknüpft.");
            }
        }

        return $linkedCount;
    }
}