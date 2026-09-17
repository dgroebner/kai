<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Assistant\AssistantService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

$logger = new Logger(14);

// 1. Authentifizierung über Bearer-Token oder X-API-Key (kein Query-Parameter)
if (!Auth::assistantTokenMatches()) {
    $logger->error('Assistant API: Unbefugter Zugriff versucht (Ungültiges oder fehlendes Token).');
    Auth::sendJsonError(401, 'Unauthorized');
}

// 2. Schnelle Health-/Ping-Prüfung bei GET-Anfragen
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo json_encode([
        'success' => true,
        'status' => 'ready',
        'service' => 'Kai Assistant Gateway',
        'time' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Nur POST für Aktions- und Sprachbefehle
Auth::requireMethod('POST');

// 4. Input lesen & validieren
$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (!is_array($payload)) {
    // Falls leer oder form-encoded: Fallback auf $_POST
    $payload = !empty($_POST) ? $_POST : [];
}

// 5. Befehl an AssistantService delegieren
try {
    $service = new AssistantService();
    $result = $service->handle($payload);

    if (empty($result['success'])) {
        http_response_code(400);
    } else {
        http_response_code(200);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    $logger->error('Assistant API: Fehler bei der Befehlsverarbeitung.', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler bei der Verarbeitung');
}
