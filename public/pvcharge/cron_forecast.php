<?php
require_once __DIR__ . '/../../bootstrap.php';

$secretToken = $_ENV['CRON_TOKEN'] ?? null;

if (empty($secretToken) || !isset($_GET['token']) || $_GET['token'] !== $secretToken) {
    http_response_code(403);
    die("Zugriff verweigert.\n");
}

use Kai\Tools\PVCharge\SolarForecastService;
use Kai\Tools\Shared\Log\Logger;

$logger = new Logger();

try {
    $logger->info("Cronjob (SolarForecast): Starte Abruf der Solar-Prognose...");
    
    $service = new SolarForecastService();
    $success = $service->fetchAndStoreForecast();
    
    if ($success) {
        $logger->info("Cronjob (SolarForecast): Abruf erfolgreich abgeschlossen.");
        echo "OK - Solar-Prognose erfolgreich aktualisiert.";
    } else {
        $logger->error("Cronjob (SolarForecast): Abruf fehlgeschlagen.");
        http_response_code(500);
        echo "FEHLER - Abruf fehlgeschlagen.";
    }
} catch (\Throwable $e) {
    $logger->error("Cronjob (SolarForecast): Kritischer Fehler beim Ausführen des Imports!", [
        'error' => $e->getMessage()
    ]);
    
    http_response_code(500);
    echo "FEHLER - Details im Log.";
}
