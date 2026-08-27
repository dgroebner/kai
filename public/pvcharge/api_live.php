<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\PVCharge\PvTelemetryRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// Auth-Check — JSON-Endpunkt, deshalb 401 statt Redirect
Auth::requireApi();

try {
    // Neueste Live-Daten abrufen
    $liveData = new PvTelemetryRepository()->getLatestLiveData();

    if ($liveData === []) {
        echo json_encode(['success' => false, 'error' => 'Keine Live-Daten gefunden']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'last_update' => $liveData['last_update'] ?? '',
            'pv_power_w' => (float)($liveData['pv_power_w'] ?? 0),
            'house_load_w' => (float)($liveData['house_load_w'] ?? 0),
            'grid_total_w' => (float)($liveData['grid_total_w'] ?? 0),
            'battery_soc_pct' => (int)($liveData['battery_soc_pct'] ?? 0),
            'battery_power_w' => (float)($liveData['battery_power_w'] ?? 0),
        ]
    ]);
} catch (Throwable $e) {
    new Logger()->error('pvcharge/api_live.php: Datenbankfehler.', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler');
}