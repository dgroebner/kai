<?php
require_once __DIR__ . '/../../bootstrap.php';

$secretToken = $_ENV['CRON_TOKEN'] ?? null;

if (empty($secretToken) || !isset($_GET['token']) || $_GET['token'] !== $secretToken) {
    http_response_code(403);
    die("Zugriff verweigert.\n");
}

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
    $logger->info("Cronjob (mail.php): Starte zentralen MailDispatcher...");

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

    echo "OK - MailDispatcher lief fehlerfrei durch.";

} catch (\Throwable $e) {
    $logger->error("Cronjob (mail.php): Kritischer Fehler im MailDispatcher!", [
        'error' => $e->getMessage()
    ]);

    http_response_code(500);
    echo "FEHLER - Details im Log.";
}