<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;

Auth::requireCronToken('shared/mail.php');

// -------------------------------------------------------------------------
// ASYNCHRONE ENT KOPPLUNG: HTTP-Verbindung sofort schließen
// -------------------------------------------------------------------------
ignore_user_abort(true); // Verhindert, dass das Skript abbricht, wenn der Aufrufer auflegt
set_time_limit(300);     // Reichende Ausführungszeit für Mail/Gemini-Verarbeitung gewähren

// Antwort für den Aufrufer vorbereiten
$responseMessage = "OK - MailDispatcher im Hintergrund gestartet.";

if (function_exists('fastcgi_finish_request')) {
    // Perfekt für PHP-FPM / FastCGI Setup
    echo $responseMessage;
    fastcgi_finish_request(); // Schließt die HTTP-Verbindung sofort!
} else {
    // Fallback für klassischen Apache / Puffer-Modus
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

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Mail\ImapClient;
use Kai\Tools\Shared\Mail\MailDispatcher;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Bank\Parser\VisaPdfParser;
use Kai\Tools\Bank\CreditCardService;
use Kai\Tools\Kassenbon\ReceiptAnalyzer;
use Kai\Tools\Kassenbon\ReceiptRepository;

$logger = new Logger(14);

try {
    $logger->info("Cronjob (mail.php): Starte zentralen MailDispatcher (Asynchron)...");

    $db = Database::getInstance();
    $geminiClient = new GeminiClient();
    $imapClient = new ImapClient($_ENV['IMAP_USER_KASSENBON'], $_ENV['IMAP_PASS_KASSENBON']);

    // Bank-Services
    $visaParser = new VisaPdfParser($geminiClient);
    $creditCardService = new CreditCardService($db, $visaParser);

    // Kassenbon-Services
    $receiptAnalyzer = new ReceiptAnalyzer();
    $receiptRepository = new ReceiptRepository();

    // Dispatcher ausführen
    $dispatcher = new MailDispatcher(
        $imapClient,
        $creditCardService,
        $receiptAnalyzer,
        $receiptRepository
    );

    $dispatcher->dispatch();

    $logger->info("Cronjob (mail.php): MailDispatcher im Hintergrund erfolgreich beendet.");

} catch (\Throwable $e) {
    $logger->error("Cronjob (mail.php): Kritischer Fehler im Hintergrund-Task!", [
        'error' => $e->getMessage()
    ]);
}