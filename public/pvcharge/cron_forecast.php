<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\PVCharge\SolarForecastService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

Auth::requireCronToken('pvcharge/cron_forecast.php');

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
} catch (Throwable $e) {
    $logger->error("Cronjob (SolarForecast): Kritischer Fehler beim Ausführen des Imports!", [
        'error' => $e->getMessage()
    ]);

    http_response_code(500);
    echo "FEHLER - Details im Log.";
}
