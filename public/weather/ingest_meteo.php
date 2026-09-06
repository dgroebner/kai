<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\Weather\WeatherIngestService;
use Kai\Tools\Shared\Log\Logger;

header('Content-Type: application/json; charset=utf-8');

// Nutze den Cron-Token Mechanismus, analog zu PV-Daten
Auth::cronTokenMatches(false);
Auth::requireMethod('POST');

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['data']) || !is_array($input['data'])) {
    Auth::sendJsonError(400, 'Missing or invalid data payload.');
}

try {
    $service = new WeatherIngestService();
    $service->processForecast($input['data']);
    
    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    (new Logger())->error('Weather Ingest Meteo: Fehler', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler');
}
