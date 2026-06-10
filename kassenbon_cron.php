<?php
// 1. CLI-Sicherheitscheck: Nur Ausführung über die Kommandozeile erlauben!
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Zugriff verweigert: Dieses Skript kann nur als Cronjob ausgefuehrt werden.\n");
}

// 2. Autoloader und Umgebung laden
require_once __DIR__ . '/bootstrap.php';

use Kai\Tools\Kassenbon\ScannerTask;
use Kai\Tools\Shared\Log\Logger;

// Wir nutzen die bekannte ID 14 für System-Ereignisse
$logger = new Logger(14); 

try {
    $logger->info("Cronjob: Starte Kassenbon-Scanner...");
    
    $task = new ScannerTask();
    $task->run();
    
    $logger->info("Cronjob: Scanner-Task erfolgreich abgeschlossen.");
} catch (\Throwable $e) {
    // Falls das Skript komplett crasht, haben wir den Fehler sauber im Log
    $logger->error("Cronjob: Kritischer Fehler beim Ausführen des Tasks!", [
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine()
    ]);
    
    // Setzt den Exit-Code auf 1, damit das Betriebssystem (Strato) weiß, dass der Job fehlschlug
    exit(1); 
}
