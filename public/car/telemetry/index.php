<?php
require_once __DIR__ . '/../../../bootstrap.php';

use Kai\Tools\Car\TelemetryRepository;
use Kai\Tools\Shared\Log\Logger;

header('Content-Type: application/json');

$logger = new Logger(14);

// 1. Authentifizierung
$secretToken = $_ENV['CRON_TOKEN'] ?? null;
$receivedToken = null;

// Header auslesen (Case-Insensitive)
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $name => $value) {
        $lowerName = strtolower($name);
        if ($lowerName === 'x-api-key') {
            $receivedToken = $value;
            break;
        } elseif ($lowerName === 'authorization') {
            if (preg_match('/Bearer\s+(.*)$/i', $value, $matches)) {
                $receivedToken = $matches[1];
                break;
            }
        }
    }
}

// Fallback über $_SERVER
if ($receivedToken === null) {
    if (isset($_SERVER['HTTP_X_API_KEY'])) {
        $receivedToken = $_SERVER['HTTP_X_API_KEY'];
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $receivedToken = $matches[1];
        }
    }
}

// Timing-sicherer Vergleich
if (empty($secretToken) || empty($receivedToken) || !hash_equals($secretToken, $receivedToken)) {
    $logger->error("Car Telemetry API: Unbefugter Zugriff versucht (Ungültiges/fehlendes Token).");
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}
// 2. Nur POST-Anfragen für Telemetrie-Upload erlauben
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed. Use POST.'
    ]);
    exit;
}

// Raw Payload lesen
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    $logger->error("Car Telemetry API: Ungültiges JSON empfangen.", ['raw' => substr($rawInput, 0, 500)]);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Bad Request: Invalid JSON body'
    ]);
    exit;
}

// 4. Payload Validierung
$validationError = validatePayload($data);
if ($validationError !== null) {
    $logger->error("Car Telemetry API: Payload-Validierung fehlgeschlagen.", ['missing_field' => $validationError]);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => "Bad Request: Missing or invalid payload field: {$validationError}"
    ]);
    exit;
}

// 5. Speichern der Telemetriedaten
try {
    $repo = new TelemetryRepository();
    $repo->saveTelemetry($data);

    http_response_code(201); // 201 Created
    echo json_encode([
        'success' => true,
        'message' => 'Telemetry updated'
    ]);
} catch (\Throwable $e) {
    $logger->error("Car Telemetry API: Fehler beim Speichern der Telemetriedaten.", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal Server Error: ' . $e->getMessage()
    ]);
}

/**
 * Validiert die Struktur des empfangenen Telemetrie-Payloads.
 *
 * @param mixed $data
 * @return string|null Name des fehlerhaften Feldes oder null bei Erfolg
 */
function validatePayload($data): ?string {
    if (!is_array($data)) {
        return 'root';
    }
    if (!isset($data['vin']) || !is_string($data['vin']) || strlen(trim($data['vin'])) !== 17) {
        return 'vin';
    }
    if (!isset($data['captured_at']) || !is_string($data['captured_at']) || strtotime($data['captured_at']) === false) {
        return 'captured_at';
    }
    if (!isset($data['battery']) || !is_array($data['battery'])) {
        return 'battery';
    }
    
    // Validierung der Batterie-Werte
    foreach (['soc', 'target_soc', 'charge_power_kw', 'max_temp_c', 'min_temp_c'] as $key) {
        if (!isset($data['battery'][$key]) || !is_numeric($data['battery'][$key])) {
            return "battery.{$key}";
        }
    }
    
    if (!isset($data['status']) || !is_array($data['status'])) {
        return 'status';
    }
    if (!isset($data['status']['charging_state']) || !is_string($data['status']['charging_state'])) {
        return 'status.charging_state';
    }
    if (!isset($data['status']['plug_connected']) || (!is_bool($data['status']['plug_connected']) && !is_numeric($data['status']['plug_connected']))) {
        return 'status.plug_connected';
    }
    if (!isset($data['status']['is_locked']) || (!is_bool($data['status']['is_locked']) && !is_numeric($data['status']['is_locked']))) {
        return 'status.is_locked';
    }
    
    // Validierung der Status-Werte
    foreach (['mileage_km', 'range_km', 'outdoor_temp_c'] as $key) {
        if (!isset($data['status'][$key]) || !is_numeric($data['status'][$key])) {
            return "status.{$key}";
        }
    }
    
    return null;
}
