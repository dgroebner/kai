<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Car\VehicleDashboardRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check — immer zuerst
Auth::requireApi();

// 2. HTTP-Methoden-Check
Auth::requireMethod('POST');

$logger = new Logger();

// 3. Input validieren & bereinigen
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    Auth::sendJsonError(400, 'Ungültige Anfrage');
}

Auth::requireCsrfToken($input);

$vin = trim((string)($input['vin'] ?? ''));
$carCapturedAt = trim((string)($input['car_captured_at'] ?? ''));
$rangeKm = null;

if (isset($input['range_km']) && $input['range_km'] !== '') {
    $rangeKm = filter_var($input['range_km'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => 2000],
    ]);
    if ($rangeKm === false) {
        Auth::sendJsonError(400, 'Ungültige Reichweite');
    }
}

if (strlen($vin) !== 17 || $carCapturedAt === '' || strtotime($carCapturedAt) === false) {
    Auth::sendJsonError(400, 'Fehlende oder ungültige Parameter');
}

try {
    new VehicleDashboardRepository()->updateRange($vin, $carCapturedAt, $rangeKm);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    $logger->error("update_range.php: Fehler beim Aktualisieren der Reichweite.", ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Server-Fehler');
}