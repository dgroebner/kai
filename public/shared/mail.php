<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Bank\AiTagClassifier;
use Kai\Tools\Bank\BankAccountRepository;
use Kai\Tools\Bank\BankTransactionRepository;
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
    $logger->info("Cronjob (mail.php): Starte zentralen MailDispatcher (Asynchron)...");

    $db = Database::getInstance();
    $geminiClient = new GeminiClient();
    $imapClient = new ImapClient($_ENV['IMAP_USER_KASSENBON'], $_ENV['IMAP_PASS_KASSENBON']);

    // 1. Credit Card Services
    $visaParser = new VisaPdfParser($geminiClient);
    $creditCardService = new CreditCardService($db, $visaParser);

    // 2. Giro Bank Services (NEU)
    $bankRepo = new BankTransactionRepository();
    $bankAccountRepo = new BankAccountRepository();
    $aiClassifier = new AiTagClassifier($geminiClient);

    // 3. Kassenbon-Services
    $receiptAnalyzer = new ReceiptAnalyzer();
    $receiptRepository = new ReceiptRepository();

    // 4. Dispatcher mit korrekten Argumenten ausführen
    $dispatcher = new MailDispatcher(
        $imapClient,
        $creditCardService,
        $receiptAnalyzer,
        $receiptRepository
    );

    $dispatcher->dispatch();

    $logger->info("Cronjob (mail.php): MailDispatcher im Hintergrund erfolgreich beendet.");

    // 5. Schule / Vertretungsplan synchronisieren (heute und nächster Schultag)
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

    // 6. Gamification / Familien-Quests Fristen und Tagesaufgaben abgleichen
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


} catch (Throwable $e) {
    $logger->error("Cronjob (mail.php): Kritischer Fehler im Hintergrund-Task!", [
        'error' => $e->getMessage()
    ]);
}