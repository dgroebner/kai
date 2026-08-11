<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$txId = (int)($data['tx_id'] ?? 0);
$categoryId = (int)($data['category_id'] ?? 0);

if (!$txId || !$categoryId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige Parameter']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("UPDATE bank_cc_transactions SET category_id = :cat_id WHERE id = :id");
    $stmt->execute([':cat_id' => $categoryId, ':id' => $txId]);

    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}