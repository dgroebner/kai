<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Games\HangmanService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check — immer zuerst
Auth::requireApi();

// 2. HTTP-Methoden-Check
Auth::requireMethod('POST');

// 3. Input validieren & bereinigen (inkl. CSRF-Token)
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    Auth::sendJsonError(400, 'Ungültige JSON-Anfrage');
}
Auth::requireCsrfToken($input);

$action = trim((string)($input['action'] ?? ''));
$logger = new Logger();

try {
    $hangmanService = new HangmanService();

    switch ($action) {
        case 'generate_word':
            $category = !empty($input['category']) ? trim((string)$input['category']) : null;
            $wordData = $hangmanService->generateWord($category);

            echo json_encode([
                'success' => true,
                'data' => $wordData,
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'validate_word':
            $rawWord = trim((string)($input['word'] ?? ''));
            $cleanWord = HangmanService::sanitizeWord($rawWord);
            $len = strlen($cleanWord);

            if ($len < 4 || $len > 14) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Das Wort muss zwischen 4 und 14 Buchstaben lang sein.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            echo json_encode([
                'success' => true,
                'clean_word' => $cleanWord,
                'length' => $len,
            ], JSON_UNESCAPED_UNICODE);
            exit;

        default:
            Auth::sendJsonError(400, 'Unbekannte Aktion: ' . $action);
    }
} catch (Throwable $e) {
    $logger->error('public/games/api.php: Fehler bei Anfrage.', [
        'action' => $action,
        'error' => $e->getMessage(),
    ]);
    Auth::sendJsonError(500, 'Interner Fehler beim Verarbeiten der Spiel-Anfrage.');
}
