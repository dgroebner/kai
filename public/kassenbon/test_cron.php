<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Kassenbon\ScannerTask;

$logger = new Logger(14); // 14 Tage Aufbewahrung

try {
    $logger->info("Manueller Cronjob (Test) gestartet.");
    
    // Limits hochsetzen, falls es ein Memory-Problem ist
    ini_set('memory_limit', '512M');
    ini_set('max_execution_time', '120');

    $task = new ScannerTask();
    $task->run();
    
    $logger->info("Cronjob erfolgreich beendet.");
    echo "Durchlauf beendet. Bitte prüfe die Log-Datei in /storage/logs/";

} catch (\Throwable $e) { // Fängt Exceptions UND fatale PHP Errors!
    $logger->error("Absturz im Cronjob!", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    echo "<b style='color:red;'>Es gab einen kritischen Fehler! Details stehen im Log.</b>";
}