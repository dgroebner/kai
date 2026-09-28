<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Bank\CreditCardService;
use Kai\Tools\Bank\Parser\VisaPdfParser;
use Kai\Tools\Kassenbon\ReceiptAnalyzer;
use Kai\Tools\Kassenbon\ReceiptRepository;
use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Mail\ImapClient;
use Kai\Tools\Shared\Mail\MailDispatcher;
use Kai\Tools\Shared\Security\Auth;

Auth::requireCronToken('shared/mail.php');

// -------------------------------------------------------------------------
// ASYNCHRONE ENTKOPPLUNG: HTTP-Verbindung sofort schließen
// -------------------------------------------------------------------------
ignore_user_abort(true);
set_time_limit(300);

$responseMessage = "OK - MailDispatcher im Hintergrund gestartet.";

if (function_exists('fastcgi_finish_request')) {
    echo $responseMessage;
    fastcgi_finish_request();
} else {
    ob_start();
    echo $responseMessage;
    header('Connection: close');
    header('Content-Length: ' . ob_get_length());
    ob_end_flush();
    @ob_flush();
    flush();
}

// -------------------------------------------------------------------------
// AB HIER LÄUFT DER PROZESS ASYNCHRON IM HINTERGRUND WEITER
// -------------------------------------------------------------------------

$logger = new Logger(14);

try {
    $logger->info("Cronjob (mail.php): Starte Hintergrund-Jobs...");

    $db = Database::getInstance();

    // 1. Schule / Vertretungsplan synchronisieren (heute und nächster Schultag)
    try {
        $schoolService = new \Kai\Tools\School\SchoolService();
        $schoolResults = $schoolService->syncTodayAndNext();
        $logger->info("Cronjob (mail.php): Schul-Vertretungspläne abgeglichen.", ['results' => $schoolResults]);

        // Auch Beste Schule synchronisieren
        $besteSync = new \Kai\Tools\School\BesteSchuleSyncService();
        $besteResults = $besteSync->syncAll();
        $logger->info("Cronjob (mail.php): Beste Schule abgeglichen.", ['results' => $besteResults]);
    } catch (Throwable $se) {
        $logger->warn("Cronjob (mail.php): Fehler beim Schuldaten-Abgleich.", ['error' => $se->getMessage()]);
    }

    // 2. Gamification / Familien-Quests Fristen und Tagesaufgaben abgleichen
    try {
        $escalationService = new \Kai\Tools\Gamification\GamificationEscalationService(
            db: $db,
            logger: $logger,
            activityLogger: new \Kai\Tools\Shared\Log\ActivityLogger($db)
        );
        $gamifService = new \Kai\Tools\Gamification\GamificationService(
            db: $db,
            escalationService: $escalationService
        );
        $gamifService->syncDailyState();
        $logger->info("Cronjob (mail.php): Familien-Quests Fristen und Tagesaufgaben synchronisiert.");
    } catch (Throwable $ge) {
        $logger->warn("Cronjob (mail.php): Fehler beim Gamification-Abgleich.", ['error' => $ge->getMessage()]);
    }

    // 3. Kalender / Geburtstage & Jahrestage Erinnerungen prüfen und versenden
    try {
        $calendarService = new \Kai\Tools\Calendar\CalendarService($db, $logger);
        $sentReminders = $calendarService->processDueReminders();
        if ($sentReminders > 0) {
            $logger->info("Cronjob (mail.php): Kalender-Erinnerungen versendet.", ['count' => $sentReminders]);
        }
    } catch (Throwable $ce) {
        $logger->warn("Cronjob (mail.php): Fehler beim Kalender-Erinnerungsabgleich.", ['error' => $ce->getMessage()]);
    }

    // 4. Open Food Facts Queue: Neue und abgelaufene Artikel einreihen
    try {
        $offRepo = new \Kai\Tools\Kassenbon\OpenFoodFactsQueueRepository();
        $newlyQueued = $offRepo->enqueueAllPendingItems();
        if ($newlyQueued > 0) {
            $logger->info("OFF-Queue: $newlyQueued neue Artikel eingereiht.");
        }
    } catch (Throwable $e) {
        $logger->error('OFF-Queue Enqueue fehlgeschlagen.', ['error' => $e->getMessage()]);
    }

    // 5. Mail-Verarbeitung (Kreditkartenabrechnungen & E-Bons via Gemini)
    try {
        $logger->info("Cronjob (mail.php): Starte MailDispatcher...");

        $geminiClient = new GeminiClient();
        $imapClient = new ImapClient($_ENV['IMAP_USER_KASSENBON'], $_ENV['IMAP_PASS_KASSENBON']);

        $visaParser = new VisaPdfParser($geminiClient);
        $creditCardService = new CreditCardService($db, $visaParser);

        $receiptAnalyzer = new ReceiptAnalyzer();
        $receiptRepository = new ReceiptRepository();

        $dispatcher = new MailDispatcher(
            $imapClient,
            $creditCardService,
            $receiptAnalyzer,
            $receiptRepository
        );

        $dispatcher->dispatch();
        $logger->info("Cronjob (mail.php): MailDispatcher erfolgreich beendet.");

        // 5b. Einkaufslisten-Lernen aus neuen eBons aktualisieren
        try {
            $learningService = new \Kai\Tools\Einkaufsliste\LearningService();
            $learningStats = $learningService->learnFromReceipts();
            $logger->info("Cronjob (mail.php): Einkaufslisten-Lernen aktualisiert.", ['stats' => $learningStats]);
        } catch (Throwable $le) {
            $logger->warn("Cronjob (mail.php): Fehler beim Einkaufslisten-Lernen.", ['error' => $le->getMessage()]);
        }

    } catch (Throwable $me) {
        $logger->error("Cronjob (mail.php): Fehler im MailDispatcher.", ['error' => $me->getMessage()]);
    }

    $logger->info("Cronjob (mail.php): Alle Hintergrund-Jobs abgeschlossen.");

} catch (Throwable $e) {
    $logger->error("Cronjob (mail.php): Kritischer Fehler im Hintergrund-Task!", [
        'error' => $e->getMessage()
    ]);
}