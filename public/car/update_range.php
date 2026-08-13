<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;
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

if (isset($input['range_km']) && $input['range_km'] !== null && $input['range_km'] !== '') {
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
    $db = Database::getInstance()->getConnection();

    // 1. In vehicle_telemetry_log aktualisieren
    $stmtLog = $db->prepare("
        UPDATE vehicle_telemetry_log 
        SET range_km = :range_km 
        WHERE vin = :vin AND car_captured_at = :car_captured_at
    ");
    $stmtLog->execute([
        ':range_km' => $rangeKm,
        ':vin' => $vin,
        ':car_captured_at' => $carCapturedAt
    ]);

    // 2. Falls es der aktuellste Eintrag ist, auch vehicle_state aktualisieren
    $stmtState = $db->prepare("
        UPDATE vehicle_state 
        SET range_km = COALESCE(:range_km, range_km)
        WHERE vin = :vin AND car_captured_at = :car_captured_at
    ");
    $stmtState->execute([
        ':range_km' => $rangeKm,
        ':vin' => $vin,
        ':car_captured_at' => $carCapturedAt
    ]);

    echo json_encode(['success' => true]);

} catch (\Throwable $e) {
    $logger->error("update_range.php: Fehler beim Aktualisieren der Reichweite.", ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Server-Fehler');
}