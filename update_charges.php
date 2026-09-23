<?php
require_once __DIR__ . '/bootstrap.php';
$db = \Kai\Tools\Shared\Db\Database::getInstance()->getConnection();
$stmt = $db->query("SELECT id, start_time, end_time FROM vehicle_charges WHERE location_type = 'PUBLIC'");
$charges = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sync = new \Kai\Tools\Car\Tronity\TronityTelemetrySync();
$geo = new \Kai\Tools\Car\Tronity\GeofenceService();
$analyzer = new \Kai\Tools\Car\Tronity\HomeChargeAnalyzer();

foreach ($charges as $c) {
    $stmt2 = $db->prepare("
        SELECT latitude, longitude 
        FROM vehicle_telemetry_log 
        WHERE latitude IS NOT NULL 
        AND car_captured_at BETWEEN :start - INTERVAL 2 HOUR AND :end + INTERVAL 2 HOUR
        ORDER BY ABS(TIMESTAMPDIFF(SECOND, car_captured_at, :start)) ASC 
        LIMIT 1
    ");
    $stmt2->execute([':start' => $c['start_time'], ':end' => $c['end_time']]);
    $loc = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($loc) {
        $type = $geo->getLocationType((float)$loc['latitude'], (float)$loc['longitude']);
        if ($type === 'HOME') {
            echo "Charge {$c['id']} updated to HOME\n";
            $db->prepare("UPDATE vehicle_charges SET location_type = 'HOME', lat = ?, lon = ? WHERE id = ?")
               ->execute([(float)$loc['latitude'], (float)$loc['longitude'], $c['id']]);
               
            // Recalculate PV stats
            $cFull = $db->prepare("SELECT charged_net_kwh FROM vehicle_charges WHERE id = ?");
            $cFull->execute([$c['id']]);
            $charged = $cFull->fetchColumn();
            
            $analysis = $analyzer->analyze($c['start_time'], $c['end_time'], (float)$charged);
            $db->prepare("UPDATE vehicle_charges SET home_meter_kwh=?, home_pv_kwh=?, home_grid_kwh=? WHERE id=?")
               ->execute([$analysis['home_meter_kwh'], $analysis['home_pv_kwh'], $analysis['home_grid_kwh'], $c['id']]);
        }
    }
}
echo "Done\n";
