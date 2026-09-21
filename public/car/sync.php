<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Car\Tronity\TronitySyncService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check — nur authentifizierte Benutzer
Auth::requireApi();

// 2. HTTP-Methoden-Check
Auth::requireMethod('POST');

// 3. CSRF-Token validieren
$input = json_decode(file_get_contents('php://input'), true) ?? [];
Auth::requireCsrfToken($input);

$logger = new Logger(14);

try {
    $service = new TronitySyncService(logger: $logger);

    if (!$service->isConfigured()) {
        Auth::sendJsonError(400, 'TRONITY ist nicht konfiguriert (TRONITY_CLIENT_ID oder TRONITY_CLIENT_SECRET fehlt).');
    }

    $result = $service->sync();

    $msg = !empty($result['is_new'])
        ? 'Fahrzeugdaten erfolgreich über TRONITY synchronisiert.'
        : 'Fahrzeugdaten sind unverändert (Stand: ' . ($result['captured_at'] ?? '–') . ').';

    echo json_encode([
        'success' => true,
        'message' => $msg,
        'data' => $result,
    ]);
} catch (Throwable $e) {
    $logger->error('public/car/sync.php: Fehler beim TRONITY-Sync.', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Synchronisation fehlgeschlagen: ' . $e->getMessage());
}
