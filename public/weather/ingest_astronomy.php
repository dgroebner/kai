<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\Weather\AstronomyIngestService;

header('Content-Type: application/json; charset=utf-8');

// Nur per CRON/API Token erlauben (Header X-API-Key oder Bearer)
if (!Auth::cronTokenMatches(false)) {
    (new Logger())->error('Weather Ingest Astronomy: Unbefugter Zugriff versucht (Ungültiges oder fehlendes Token).');
    Auth::sendJsonError(401, 'Unauthorized');
}
Auth::requireMethod('POST');

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['data']) || !is_array($input['data'])) {
    Auth::sendJsonError(400, 'Missing or invalid data payload.');
}

try {
    $service = new AstronomyIngestService();
    $result = $service->processIngest($input['data']);
    
    echo json_encode(['success' => true, 'result' => $result]);
} catch (\Throwable $e) {
    (new Logger())->error('Weather Ingest Astronomy: Fehler', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler');
}
