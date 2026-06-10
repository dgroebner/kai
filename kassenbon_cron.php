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

$logger = new Logger(14); // Log-ID für System-Ereignisse

try {
    echo "Starte Kassenbon-Scanner...\n";
    
    $task = new ScannerTask();
    $task->run();
    
    echo "Scanner-Task erfolgreich abgeschlossen.\n";
} catch (\Throwable $e) {
    $errorMessage = "Kritischer Fehler im Cronjob: " . $e->getMessage();
    echo $errorMessage . "\n";
    $logger->error("System: " . $errorMessage);
    
    // Setzt den Exit-Code auf 1, damit Strato weiß, dass der Job fehlschlug
    exit(1); 
}
