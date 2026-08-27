<?php

namespace Kai\Tools\Shared\Log;

use Exception;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Push\WebPushService;
use Kai\Tools\System\UserProfileRepository;

class ActivityLogger
{
    private Database $db;
    private Logger $logger;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->logger = new Logger();
    }

    public function logReceipt(int $receiptId, string $storeName = ''): void
    {
        $message = $storeName !== ''
            ? "Neuer E-Bon erfasst ($storeName)"
            : "Neuer E-Bon erfasst";

        $this->log(
            'receipt_created',
            $message,
            "/kassenbon/detail.php?id=" . $receiptId,
            $receiptId
        );
    }

    // --- Spezifische Helper-Methoden ---

    /**
     * Allgemeiner Log-Eintrag — schreibt in activity_log und versendet eine Web-Push-Benachrichtigung,
     * wenn der Benutzer für diesen Event-Typ Push aktiviert hat.
     */
    public function log(string $eventType, string $message, ?string $linkUrl = null, ?int $entityId = null): void
    {
        $dbCon = $this->db->getConnection();

        try {
            $stmnt = $dbCon->prepare("
                INSERT INTO activity_log (event_type, message, link_url, entity_id, created_at) 
                VALUES (:event_type, :message, :link_url, :entity_id, NOW())
            ");

            $stmnt->execute([
                'event_type' => $eventType,
                'message' => $message,
                'link_url' => $linkUrl,
                'entity_id' => $entityId,
            ]);
        } catch (Exception $e) {
            $this->logger->error("ActivityLogger: Fehler bei save log.", ['error' => $e->getMessage()]);
        }

        // Web-Push-Benachrichtigung versenden, wenn VAPID konfiguriert und Benutzer eingeloggt
        $this->dispatchPushNotification($eventType, $message, $linkUrl);
    }

    /**
     * Sendet eine Web-Push-Benachrichtigung an den aktuell eingeloggten Benutzer,
     * sofern er für diesen Event-Typ Push-Benachrichtigungen aktiviert hat.
     * Fehler beim Push-Versand werden geloggt, aber nie nach außen weitergegeben.
     */
    private function dispatchPushNotification(string $eventType, string $message, ?string $linkUrl): void
    {
        // Nur wenn VAPID konfiguriert ist
        if (empty($_ENV['VAPID_PUBLIC_KEY']) || empty($_ENV['VAPID_PRIVATE_KEY'])) {
            return;
        }

        // Benutzer-E-Mail aus Session — kann auch in Cron-Kontexten fehlen
        $userEmail = $_SESSION['user_email'] ?? '';
        if (empty($userEmail)) {
            return;
        }

        try {
            $profileRepo = new UserProfileRepository();
            $preferences = $profileRepo->getPreferences($userEmail);

            // Nur senden, wenn für diesen Event-Typ Push aktiviert
            if (isset($preferences[$eventType]) && !$preferences[$eventType]) {
                return;
            }

            $url = !empty($linkUrl) ? (rtrim(APP_URL, '/') . $linkUrl) : APP_URL;

            new WebPushService()->sendToUser($userEmail, 'Kai – Neue Aktivität', $message, $url);
        } catch (Exception $e) {
            $this->logger->error("ActivityLogger: Fehler beim Web-Push-Versand.", ['error' => $e->getMessage()]);
        }
    }

    public function logCreditCardStatement(int $statementId, string $period = ''): void
    {
        $message = $period !== ''
            ? "Neue Kreditkartenabrechnung erfasst ($period)"
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
        $this->log(
            'bank_data_imported',
            "Neue Bankdaten erfasst ($count Transaktionen)",
            "/bank/index.php"
        );
    }

    public function logPvForecastLoaded(?string $date = null): void
    {
        $message = $date
            ? "Neue PV-Prognose geladen ($date)"
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
            ? "Neue Fahrzeugdaten geladen ($carModel)"
            : "Neue Fahrzeugdaten geladen";

        $this->log(
            'car_telemetry_loaded',
            $message,
            "/car/index.php"
        );
    }
}
