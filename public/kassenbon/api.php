<?php
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$itemId = (int)($data['item_id'] ?? 0);
$categoryName = trim($data['category_name'] ?? '');

if (!$itemId || $categoryName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige Parameter']);
    exit;
}

try {
    $pdo = \Kai\Tools\Shared\Db\Database::getInstance()->getConnection();
    $stmt = $pdo->prepare("UPDATE kb_items SET category = :cat WHERE id = :id");
    $stmt->execute([':cat' => $categoryName, ':id' => $itemId]);
    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}