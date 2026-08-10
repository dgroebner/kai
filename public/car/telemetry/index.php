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

// 3. Payload Validierung (Geglättet für Partial Updates)
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

// 4. Speichern der Telemetriedaten
try {
    $repo = new TelemetryRepository();
    
    // Prüfen, ob SoC Telemetrie enthalten ist
    $hasTelemetry = (isset($data['battery']['soc']) && $data['battery']['soc'] > 0);

    // Live-State wird IMMER aktualisiert (setzt Zeitstempel neu, behält Nicht-Null-Werte via COALESCE)
    $repo->saveState($data);

    // Historien-Log wird NUR bei echten Telemetriedaten geschrieben
    if ($hasTelemetry) {
        $repo->saveLog($data);
    }

    http_response_code(201); // 201 Created
    echo json_encode([
        'success' => true,
        'message' => $hasTelemetry ? 'Telemetry log & state updated' : 'Keep-alive state updated'
    ]);
} catch (\Throwable $e) {
    $logger->error("Car Telemetry API: Fehler beim Speichern der Telemetriedaten.", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal Server Error'
    ]);
}

/**
 * Validiert die Grundstruktur des empfangenen Telemetrie-Payloads.
 * Feldern in battery/status ist NULL nun gestattet (Partial Updates).
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
    if (!isset($data['status']) || !is_array($data['status'])) {
        return 'status';
    }

    // Validierung der Batterie-Werte (dürfen auch null sein)
    foreach (['soc', 'target_soc', 'charge_power_kw', 'max_temp_c', 'min_temp_c'] as $key) {
        if (array_key_exists($key, $data['battery']) && $data['battery'][$key] !== null && !is_numeric($data['battery'][$key])) {
            return "battery.{$key}";
        }
    }

    // Validierung der Status-Werte (dürfen auch null sein)
    foreach (['mileage_km', 'range_km', 'outdoor_temp_c'] as $key) {
        if (array_key_exists($key, $data['status']) && $data['status'][$key] !== null && !is_numeric($data['status'][$key])) {
            return "status.{$key}";
        }
    }

    return null;
}