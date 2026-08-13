<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check — immer zuerst
Auth::requireApi();

// 2. HTTP-Methoden-Check
Auth::requireMethod('POST');

// 3. Input validieren & bereinigen
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    Auth::sendJsonError(400, 'Ungültige Anfrage');
}

Auth::requireCsrfToken($data);

$itemId = filter_var($data['item_id'] ?? null, FILTER_VALIDATE_INT);
$categoryName = trim((string)($data['category_name'] ?? ''));

if ($itemId === false || $itemId === null || $itemId <= 0
    || $categoryName === '' || mb_strlen($categoryName) > 100) {
    Auth::sendJsonError(400, 'Ungültige Parameter');
}

// 4. Logik ausführen
try {
    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->prepare("UPDATE kb_items SET category = :cat WHERE id = :id");
    $stmt->execute([':cat' => $categoryName, ':id' => $itemId]);

    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    (new Logger())->error('kassenbon/api.php: Kategorie konnte nicht gespeichert werden.', [
        'error' => $e->getMessage(),
    ]);
    Auth::sendJsonError(500, 'Interner Fehler');
}
