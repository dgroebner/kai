<?php

namespace Kai\Tools\Shared\Log;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Exception;

class ActivityLogger
{
    private Database $db;
    private Logger $logger;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->logger = new Logger();
    }

    /**
     * Allgemeiner Log-Eintrag
     */
    public function log(string $eventType, string $message, ?string $linkUrl = null, ?int $entityId = null): void
    {
        $dbCon = $this->db->getConnection();

        try {
            $dbCon->beginTransaction();

            $stmnt = $dbCon->prepare("
                INSERT INTO activity_log (event_type, message, link_url, entity_id, created_at) 
                VALUES (:event_type, :message, :link_url, :entity_id, NOW())
            ");

            $stmnt->execute([
                'event_type' => $eventType,
                'message'    => $message,
                'link_url'   => $linkUrl,
                'entity_id'  => $entityId,
            ]);

            $dbCon->commit();
        } catch (Exception $e) {
            if ($dbCon->inTransaction()) {
                $dbCon->rollBack();
            }
            $this->logger->error("ActivityLogger: Fehler bei save log.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    // --- Spezifische Helper-Methoden ---

    public function logReceipt(int $receiptId, string $storeName = ''): void
    {
        $message = $storeName !== '' 
            ? "Neuer E-Bon erfasst ({$storeName})" 
            : "Neuer E-Bon erfasst";
            
        $this->log(
            'receipt_created',
            $message,
            "/kassenbon/detail.php?id=" . $receiptId,
            $receiptId
        );
    }

    public function logCreditCardStatement(int $statementId, string $period = ''): void
    {
        $message = $period !== '' 
            ? "Neue Kreditkartenabrechnung erfasst ({$period})" 
            : "Neue Kreditkartenabrechnung erfasst";

        $this->log(
            'creditcard_statement_created',
            $message,
            "/bank/creditcard.php?id=" . $statementId,
            $statementId
        );
    }

    public function logBankDataImport(int $count = 0): void
    {
        $message = $count > 0 
            ? "Neue Bankdaten erfasst ({$count} Transaktionen)" 
            : "Neue Bankdaten erfasst";

        $this->log(
            'bank_data_imported',
            $message,
            "/bank/index.php"
        );
    }

    public function logPvForecastLoaded(?string $date = null): void
    {
        $message = $date 
            ? "Neue PV-Prognose geladen ({$date})" 
            : "Neue PV-Prognose geladen";

        $this->log(
            'pv_forecast_loaded',
            $message,
            "/pvcharge/index.php"
        );
    }

    public function logCarTelemetryLoaded(?string $carModel = null): void
    {
        $message = $carModel 
            ? "Neue Fahrzeugdaten geladen ({$carModel})" 
            : "Neue Fahrzeugdaten geladen";

        $this->log(
            'car_telemetry_loaded',
            $message,
            "/car/index.php"
        );
    }
}