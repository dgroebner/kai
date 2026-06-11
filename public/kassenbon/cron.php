<?php
require_once __DIR__ . '/../../bootstrap.php';

$secretToken = $_ENV['CRON_TOKEN'] ?? null;

if (empty($secretToken) || !isset($_GET['token']) || $_GET['token'] !== $secretToken) {
    http_response_code(403);
    die("Zugriff verweigert.\n");
}

use Kai\Tools\Kassenbon\ScannerTask;
use Kai\Tools\Shared\Log\Logger;

$logger = new Logger(14); 

try {
    $logger->info("Cronjob: Starte Kassenbon-Scanner (via HTTP-Trigger)...");
    
    $task = new ScannerTask();
    $task->run();
    
    $logger->info("Cronjob: Scanner-Task erfolgreich abgeschlossen.");
    
    // Kurze Erfolgsmeldung für den Webaufruf
    echo "OK - Scanner lief fehlerfrei durch.";
} catch (\Throwable $e) {
    $logger->error("Cronjob: Kritischer Fehler beim Ausführen des Tasks!", [
        'error' => $e->getMessage()
    ]);
    
    http_response_code(500);
    echo "FEHLER - Details im Log.";
}
