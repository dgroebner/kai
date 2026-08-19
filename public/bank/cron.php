<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Security\TokenEncryptionService;
use Kai\Tools\Bank\BankAccountRepository;
use Kai\Tools\Bank\BankGiroService;
use Kai\Tools\Bank\BankTransactionRepository;
use Kai\Tools\Bank\CategoryMatcher;
use Kai\Tools\Bank\AiTagClassifier;
use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Bank\ComdirectClient;

// 1. Cron-Token absichern (AGENTS.md)
Auth::requireCronToken('bank/cron.php');

$logger = new Logger(14);
$db = Database::getInstance();
$pdo = $db->getConnection();
$activityLogger = new ActivityLogger($db);

try {
    // 2. Checking Account (Girokonto) ermitteln
    $stmtAccount = $pdo->query("SELECT id FROM bank_accounts WHERE account_type = 'checking' LIMIT 1");
    $accountId = $stmtAccount->fetchColumn();

    if (!$accountId) {
        $logger->error("bank/cron.php: Kein Girokonto in der Datenbank gefunden.");
        http_response_code(404);
        echo "Fehler: Kein Girokonto konfiguriert.\n";
        exit;
    }

    // 3. Tokens aus DB laden
    $encryptionService = new TokenEncryptionService($_ENV['BANK_ENCRYPTION_KEY']);
    $repo = new BankAccountRepository();
    $tokens = $repo->getApiTokens((int)$accountId, $encryptionService);

    if (!$tokens || empty($tokens['refresh_token'])) {
        // Token fehlt komplett -> Nachricht im Aktivitäts-Log schreiben
        $activityLogger->log(
            'bank_sync_failed',
            'Automatischer Bank-Sync fehlgeschlagen: Keine API-Zugangsdaten vorhanden. Bitte manuell anmelden.',
            '/bank/index.php'
        );
        $logger->error("bank/cron.php: Keine API-Tokens oder kein Refresh-Token in DB vorhanden.");
        http_response_code(401);
        echo "Fehler: Keine API-Tokens vorhanden. Log geschrieben.\n";
        exit;
    }

    // 4. Token erneuern über Refresh Token
    $client = new ComdirectClient();
    try {
        $refreshedTokens = $client->refreshAccessToken($tokens['refresh_token']);
        $repo->saveApiTokens((int)$accountId, $refreshedTokens, $encryptionService);
        $tokens = $refreshedTokens;
    } catch (\Throwable $e) {
        // Refresh fehlgeschlagen (z.B. Refresh Token abgelaufen) -> Log schreiben
        $activityLogger->log(
            'bank_sync_failed',
            'Automatischer Bank-Sync fehlgeschlagen: Die API-Zugangsdaten sind abgelaufen. Bitte erneut manuell anmelden.',
            '/bank/index.php'
        );
        $logger->error("bank/cron.php: Token-Refresh fehlgeschlagen", ['error' => $e->getMessage()]);
        http_response_code(401);
        echo "Fehler: Token-Refresh fehlgeschlagen. Log geschrieben.\n";
        exit;
    }

    // 5. Sync ausführen
    $geminiClient = new GeminiClient();
    $bankRepo = new BankTransactionRepository();
    $categoryMatcher = new CategoryMatcher();
    $aiClassifier = new AiTagClassifier($geminiClient);

    $bankGiroService = new BankGiroService(
        $bankRepo,
        $categoryMatcher,
        $aiClassifier
    );

    $stats = $bankGiroService->syncWithComdirectApi($tokens);

    // Ausgabe
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Sync erfolgreich abgeschlossen!\n";
    echo "Importiert: {$stats['imported']}\n";
    echo "Ignoriert: {$stats['ignored']}\n";
    echo "Getaggt: {$stats['tagged']}\n";

} catch (\Throwable $e) {
    $logger->error("bank/cron.php: Kritischer Fehler im Cronjob", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo "Kritischer Fehler: " . $e->getMessage() . "\n";
}
