<?php
require_once __DIR__ . '/../../../bootstrap.php';

use Kai\Tools\Car\TelemetryRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

$logger = new Logger(14);

// 1. Authentifizierung über den API-Token (X-API-Key oder Bearer, kein Query-Parameter)
if (!Auth::cronTokenMatches(false)) {
    $logger->error("Car Telemetry API: Unbefugter Zugriff versucht (Ungültiges/fehlendes Token).");
    Auth::sendJsonError(401, 'Unauthorized');
}

// 2. Nur POST-Anfragen für Telemetrie-Upload erlauben
Auth::requireMethod('POST');

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