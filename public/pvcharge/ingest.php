<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\PVCharge\PvIngestService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

Auth::requireCronToken('pvcharge/ingest.php');
Auth::requireMethod('POST');

header('Content-Type: application/json; charset=utf-8');
$logger = new Logger();
$type = '';

try {
    $service = new PvIngestService();

    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (!is_array($payload)) {
        $logger->error('pvcharge/ingest.php: Ungültiges JSON empfangen.', ['raw' => substr($rawInput, 0, 500)]);
        Auth::sendJsonError(400, 'Bad Request: Invalid JSON body');
    }

    $type = (string)($payload['type'] ?? '');
    $data = $payload['data'] ?? null;

    if (!in_array($type, ['live', 'telemetry'], true) || !is_array($data) || $data === []) {
        $logger->error('pvcharge/ingest.php: Ungültiger Payload.', ['type' => $type]);
        Auth::sendJsonError(400, 'Bad Request: Invalid payload');
    }

    $columns = array_keys($data);
    $values = array_values($data);

    if ($type === 'live') {
        $service->upsertLiveData($columns, $values);
    } else {
        $service->insertTelemetryData($columns, $values);
    }

    echo json_encode(['status' => 'ok', 'type' => $type]);

} catch (Throwable $e) {
    $logger->error("PV-Telemetrie: Kritischer Fehler beim Ausführen des Imports vom Typ '$type'!", [
        'error' => $e->getMessage()
    ]);

    Auth::sendJsonError(500, 'Interner Fehler');
}