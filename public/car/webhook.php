<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Car\Tronity\TronitySyncService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

$logger = new Logger(14);

// 1. Challenge-Handshake (falls TRONITY einen GET-Handshake oder ein Challenge-Feld sendet)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $challenge = $_GET['challenge'] ?? $_GET['hub_challenge'] ?? null;
    if ($challenge !== null) {
        if (!Auth::tronityWebhookTokenMatches()) {
            $logger->warn('Car Webhook: Challenge-Aufruf mit ungültigem Token verweigert.');
            Auth::sendJsonError(401, 'Unauthorized');
        }
        echo json_encode(['challenge' => $challenge]);
        exit;
    }

    // Status-Prüfung für Browser / Testaufrufe
    if (Auth::tronityWebhookTokenMatches()) {
        echo json_encode(['success' => true, 'message' => 'TRONITY Webhook Endpoint bereit.']);
        exit;
    }
    Auth::sendJsonError(401, 'Unauthorized');
}

// 2. Nur POST für Webhook-Events erlauben
Auth::requireMethod('POST');

// 3. Raw Payload einlesen
$rawInput = file_get_contents('php://input');

// 4. Authentifizierung (Token im Query-Param / Header ODER Signatur-Prüfung)
$isTokenValid = Auth::tronityWebhookTokenMatches();
$isSigValid = Auth::verifyTronitySignature($rawInput);

if (!$isTokenValid && !$isSigValid) {
    $logger->warn('Car Webhook: Unbefugter Zugriff versucht (ungültiges Token / Signatur).');
    Auth::sendJsonError(401, 'Unauthorized');
}

// 5. Payload parsen
$data = json_decode($rawInput, true);

// Optionaler Challenge-Response bei POST Handshake
if (is_array($data) && isset($data['challenge'])) {
    echo json_encode(['challenge' => $data['challenge']]);
    exit;
}

$eventType = is_array($data) ? ($data['event'] ?? $data['type'] ?? 'telemetry_push') : 'raw_push';
$logger->info('Car Webhook: Event von TRONITY empfangen.', [
    'event' => $eventType,
    'has_data' => !empty($data)
]);

// 6. Livedaten synchronisieren
try {
    $syncService = new TronitySyncService();
    $result = $syncService->sync(null, is_array($data) ? $data : null);

    // Ladevorgänge bei jedem Webhook-Aufruf synchronisieren (schnelles Fallback)
    $telemetrySync = new \Kai\Tools\Car\Tronity\TronityTelemetrySync();
    $telemetrySync->syncCharges();

    echo json_encode([
        'success' => true,
        'message' => 'Webhook empfangen und verarbeitet',
        'data' => $result
    ]);
} catch (\Throwable $e) {
    $logger->error('Car Webhook: Fehler bei der Verarbeitung.', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler bei Webhook-Verarbeitung');
}
