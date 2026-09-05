<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\Weather\WeatherService;

header('Content-Type: application/json; charset=utf-8');

// Nur per CRON/API Token erlauben
Auth::cronTokenMatches(false);
Auth::requireMethod('POST');

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['temperature_c']) || !isset($input['soil_moisture_pct']) || !isset($input['wind_kmh'])) {
    Auth::sendJsonError(400, 'Missing sensor data parameters.');
}

$temp = (float)$input['temperature_c'];
$soil = (int)$input['soil_moisture_pct'];
$wind = (float)$input['wind_kmh'];

try {
    $service = new WeatherService();
    $service->saveSensorData($temp, $soil, $wind);
    
    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    (new \Kai\Tools\Shared\Log\Logger())->error('Weather Ingest: Fehler', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler');
}
