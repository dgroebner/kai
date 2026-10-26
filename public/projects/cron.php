<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Projects\Db\ProjectsDatabase;
use Kai\Tools\Projects\Mail\ProjectsMailboxCleaner;
use Kai\Tools\Projects\ProjectsPushService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// 1. Cron-Token prüfen (PROJECTS_CRON_TOKEN oder CRON_TOKEN)
$cronToken = $_ENV['PROJECTS_CRON_TOKEN'] ?? ($_ENV['CRON_TOKEN'] ?? '');
$provided = $_GET['token'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');

if (empty($cronToken) || empty($provided) || !hash_equals($cronToken, $provided)) {
    http_response_code(403);
    echo "Zugriff verweigert: Ungültiger Projects-Cron-Token.\n";
    exit;
}

// -------------------------------------------------------------------------
// ASYNCHRONE ENTKOPPLUNG: HTTP-Verbindung sofort schließen
// -------------------------------------------------------------------------
ignore_user_abort(true);
set_time_limit(300);

$responseMessage = "OK - Projects Cronjob im Hintergrund gestartet.";

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
// HINTERGRUND-TASKS FÜR PROJECTS
// -------------------------------------------------------------------------
$logger = new Logger(14);

try {
    $logger->info("Projects Cron: Starte periodische Jobs...");

    // 1. Postfach-Cleaner ausführen (Löscht restlos eingehende Mails an projects@agent-smith.de)
    try {
        $cleaner = new ProjectsMailboxCleaner($logger);
        $cleanResult = $cleaner->purgeMailbox();
        $logger->info("Projects Cron: Postfach-Cleaner beendet.", $cleanResult);
    } catch (Throwable $pe) {
        $logger->error("Projects Cron: Fehler beim Bereinigen des Postfachs: " . $pe->getMessage());
    }

    // 2. Abgelaufene Auth-Tokens aus proj_auth_tokens bereinigen
    try {
        $db = ProjectsDatabase::getInstance()->getConnection();
        $delStmt = $db->prepare("DELETE FROM proj_auth_tokens WHERE expires_at < NOW()");
        $delStmt->execute();
        $deletedTokens = $delStmt->rowCount();
        if ($deletedTokens > 0) {
            $logger->info("Projects Cron: {$deletedTokens} abgelaufene Tokens entfernt.");
        }
    } catch (Throwable $te) {
        $logger->warn("Projects Cron: Fehler beim Token-Cleanup: " . $te->getMessage());
    }

    // 3. Feierabend-Push-Erinnerungen prüfen & senden
    try {
        $pushSvc = new ProjectsPushService(logger: $logger);
        $sentCount = $pushSvc->processDueReminders();
        if ($sentCount > 0) {
            $logger->info("Projects Cron: {$sentCount} Feierabend-Erinnerungen versendet.");
        }
    } catch (Throwable $pushErr) {
        $logger->warn("Projects Cron: Fehler beim Push-Versand: " . $pushErr->getMessage());
    }

    $logger->info("Projects Cron: Alle Aufgaben abgeschlossen.");

} catch (Throwable $e) {
    $logger->error("Projects Cron: Kritischer Fehler im Hintergrund-Task: " . $e->getMessage());
}
