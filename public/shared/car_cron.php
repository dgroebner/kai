<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// 1. Auth-Check: Cron-/API-Token erforderlich (oder angemeldeter Benutzer mit car_write)
if (!Auth::cronTokenMatches() && !Auth::tronityWebhookTokenMatches() && !Auth::hasPermission('car_write')) {
    Auth::requireCronToken('shared/car_cron.php');
}

// -------------------------------------------------------------------------
// ASYNCHRONE ENTKOPPLUNG: HTTP-Verbindung sofort schließen
// -------------------------------------------------------------------------
ignore_user_abort(true);
set_time_limit(300);

$responseMessage = "OK - Car Cronjob im Hintergrund gestartet.";

if (function_exists('fastcgi_finish_request')) {
    echo $responseMessage;
    fastcgi_finish_request();
} else {
    ob_start();
    echo $responseMessage;
    header('Connection: close');
    header('Content-Length: ' . (string)ob_get_length());
    ob_end_flush();
    @ob_flush();
    flush();
}

// -------------------------------------------------------------------------
// AB HIER LÄUFT DER PROZESS ASYNCHRON IM HINTERGRUND WEITER
// -------------------------------------------------------------------------

$logger = new Logger(14);

try {
    $logger->info("Cronjob (car_cron.php): Starte Fahrzeug-Telemetrie-Abgleich...");

    $tronitySync = new \Kai\Tools\Car\Tronity\TronitySyncService(logger: $logger);
    if ($tronitySync->isConfigured()) {
        $syncRes = $tronitySync->sync();
        $logger->info("Cronjob (car_cron.php): TRONITY-Fahrzeugdaten abgeglichen.", ['result' => $syncRes]);

        $telemetrySync = new \Kai\Tools\Car\Tronity\TronityTelemetrySync(logger: $logger);
        $telemetrySync->syncCharges();
    } else {
        $logger->warn("Cronjob (car_cron.php): TRONITY ist nicht konfiguriert.");
    }

} catch (\Throwable $e) {
    $logger->error("Cronjob (car_cron.php): Kritischer Fehler im Hintergrund-Task!", [
        'error' => $e->getMessage()
    ]);
}
