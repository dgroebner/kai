<?php
// 1. Sicherheit: Erlaube Ausführung nur mit korrektem Token!
// Ändere diesen String in ein sicheres Passwort deiner Wahl
$secretToken = 'Mein_Geheimer_Cron_Token_2026';

if (!isset($_GET['token']) || $_GET['token'] !== $secretToken) {
    http_response_code(403);
    die("Zugriff verweigert.\n");
}

// 2. Autoloader und Umgebung laden
require_once __DIR__ . '/bootstrap.php';

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
