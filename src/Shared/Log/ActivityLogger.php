<?php

namespace Kai\Tools\Shared\Log;

use Exception;
use Kai\Tools\School\SchoolStudentRepository;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Push\PushSubscriptionRepository;
use Kai\Tools\Shared\Push\WebPushService;
use Kai\Tools\System\PermissionService;
use Kai\Tools\System\UserProfileRepository;

class ActivityLogger
{
    private Database $db;
    private Logger $logger;
    private PermissionService $permissionService;
    private SchoolStudentRepository $studentRepo;
    private UserProfileRepository $userProfileRepo;
    private PushSubscriptionRepository $subscriptionRepo;

    public function __construct(
        Database $db,
        ?Logger $logger = null,
        ?PermissionService $permissionService = null,
        ?SchoolStudentRepository $studentRepo = null,
        ?UserProfileRepository $userProfileRepo = null,
        ?PushSubscriptionRepository $subscriptionRepo = null
    ) {
        $this->db = $db;
        $this->logger = $logger ?? new Logger();
        $this->permissionService = $permissionService ?? new PermissionService($this->db);
        $this->studentRepo = $studentRepo ?? new SchoolStudentRepository($this->db);
        $this->userProfileRepo = $userProfileRepo ?? new UserProfileRepository($this->db);
        $this->subscriptionRepo = $subscriptionRepo ?? new PushSubscriptionRepository($this->db);
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

        // Web-Push-Benachrichtigung an alle berechtigten und interessierten Abonnenten versenden
        $this->dispatchPushNotification($eventType, $message, $linkUrl, $entityId);
    }

    /**
     * Sendet eine Web-Push-Benachrichtigung an alle berechtigten Benutzer,
     * die für diesen Event-Typ Push-Benachrichtigungen aktiviert haben.
     * Fehler beim Push-Versand werden geloggt, aber nie nach außen weitergegeben.
     */
    private function dispatchPushNotification(string $eventType, string $message, ?string $linkUrl, ?int $entityId = null): void
    {
        // Nur wenn VAPID konfiguriert ist
        if (empty($_ENV['VAPID_PUBLIC_KEY']) || empty($_ENV['VAPID_PRIVATE_KEY'])) {
            return;
        }

        try {
            $subscribedEmails = $this->subscriptionRepo->findAllSubscribedEmails();
            if (empty($subscribedEmails)) {
                return;
            }

            $requiredPermission = UserProfileRepository::EVENT_PERMISSIONS[$eventType] ?? null;
            $webPushService = new WebPushService($this->subscriptionRepo, $this->logger);
            $url = !empty($linkUrl) ? (rtrim(APP_URL, '/') . $linkUrl) : APP_URL;

            foreach ($subscribedEmails as $userEmail) {
                // 1. Berechtigungsprüfung: Hat der Nutzer das nötige Recht für dieses Modul/Event?
                if ($requiredPermission !== null && !$this->permissionService->userHasPermission($userEmail, $requiredPermission)) {
                    continue;
                }

                // 2. Präferenzprüfung: Hat der Nutzer Benachrichtigungen für diesen Event-Typ aktiviert?
                $preferences = $this->userProfileRepo->getPreferences($userEmail);
                if (isset($preferences[$eventType]) && !$preferences[$eventType]) {
                    continue;
                }

                // 3. Datenschutz-Prüfung für Schulkinder:
                // Wenn es sich um kinderspezifische Schulereignisse handelt (Noten, Hausaufgaben):
                // - Schüler dürfen ausschließlich Benachrichtigungen für ihr eigenes Profil erhalten
                // - Eltern / Admins (nicht mit einem Schülerprofil verknüpft) erhalten Benachrichtigungen für alle Kinder
                if (in_array($eventType, ['school_grades_updated', 'school_notes_updated'], true) && $entityId !== null) {
                    $matchedStudent = $this->studentRepo->getByEmail($userEmail);
                    if ($matchedStudent !== null && (int)$matchedStudent['id'] !== $entityId) {
                        continue;
                    }
                }

                $webPushService->sendToUser($userEmail, 'Kai – Neue Aktivität', $message, $url);
            }
        } catch (\Throwable $e) {
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

    public function logShoppingCompleted(int $itemCount = 0, string $market = 'Einkauf'): void
    {
        $message = "Einkauf abgeschlossen ({$market}, {$itemCount} Artikel)";
        $this->log(
            'shopping_completed',
            $message,
            "/einkaufsliste/index.php"
        );
    }
}

