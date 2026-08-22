<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\PVCharge\PvIngestService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

Auth::requireCronToken('pvcharge/cron_forecast.php');
Auth::requireMethod('POST');

header('Content-Type: application/json; charset=utf-8');
$logger = new Logger();

try {
    $service = new PvIngestService();

    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
        $logger->error("Car Telemetry API: Ungültiges JSON empfangen.", ['raw' => substr($rawInput, 0, 500)]);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Bad Request: Invalid JSON body'
        ]);
        exit;
    }

    $type = $payload['type'] ?? '';
    $d = $payload['data'] ?? [];

    $pdo = new PDO('mysql:host=localhost;dbname=deine_db', 'user', 'password', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $columns = array_keys($d);
    $values = array_values($d);

    $logger->info("PV-Telemetrie: Import vom typ $type gestartet.");
    if ($type === 'live') {
        $service->upsertLiveData($columns, $values);
    } elseif ($type === 'telemetry') {
        $service->insertTelemetryData($columns, $values);
    }

    echo json_encode(['status' => 'ok', 'type' => $type]);

} catch (Throwable $e) {
    $logger->error("PV-Telemetrie: Kritischer Fehler beim Ausführen des Imports vom typ $type!", [
        'error' => $e->getMessage()
    ]);

    http_response_code(500);
    echo "FEHLER - Details im Log.";
}