<?php
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json');

// Auth-Check
if (!isset($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
    exit;
}

use Kai\Tools\Shared\Db\Database;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$vin = $input['vin'] ?? null;
$carCapturedAt = $input['car_captured_at'] ?? null;
$rangeKm = isset($input['range_km']) && $input['range_km'] !== null ? (int)$input['range_km'] : null;

if (!$vin || !$carCapturedAt) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Fehlende Parameter']);
    exit;
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
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}