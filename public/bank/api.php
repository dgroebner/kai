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
$categoryInput = trim($data['category_name'] ?? '');

if (!$txId || $categoryInput === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige Parameter']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    // 1. Prüfen, ob Kategorie bereits existiert (Case-Insensitive)
    $stmtCheck = $db->prepare("SELECT id FROM bank_categories WHERE LOWER(name) = LOWER(:name) LIMIT 1");
    $stmtCheck->execute([':name' => $categoryInput]);
    $categoryId = $stmtCheck->fetchColumn();

    // 2. Falls nicht vorhanden: Neu anlegen
    if (!$categoryId) {
        $stmtInsert = $db->prepare("INSERT INTO bank_categories (name) VALUES (:name)");
        $stmtInsert->execute([':name' => $categoryInput]);
        $categoryId = (int)$db->lastInsertId();
    } else {
        $categoryId = (int)$categoryId;
    }

    // 3. Transaktion mit der Kategorie-ID verknüpfen
    $stmtUpdate = $db->prepare("UPDATE bank_cc_transactions SET category_id = :cat_id WHERE id = :id");
    $stmtUpdate->execute([':cat_id' => $categoryId, ':id' => $txId]);

    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}