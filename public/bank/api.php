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

$txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
$categoryInput = trim((string)($data['category_name'] ?? ''));

if ($txId === false || $txId === null || $txId <= 0
    || $categoryInput === '' || mb_strlen($categoryInput) > 100) {
    Auth::sendJsonError(400, 'Ungültige Parameter');
}

// 4. Logik ausführen
try {
    $db = Database::getInstance()->getConnection();

    // Kategorie anlegen und Transaktion verknüpfen — atomar, damit keine
    // verwaisten Kategorien entstehen, falls das Update fehlschlägt.
    $db->beginTransaction();

    $stmtCheck = $db->prepare("SELECT id FROM bank_categories WHERE LOWER(name) = LOWER(:name) LIMIT 1");
    $stmtCheck->execute([':name' => $categoryInput]);
    $categoryId = $stmtCheck->fetchColumn();

    if ($categoryId === false) {
        $stmtInsert = $db->prepare("INSERT INTO bank_categories (name) VALUES (:name)");
        $stmtInsert->execute([':name' => $categoryInput]);
        $categoryId = (int)$db->lastInsertId();
    } else {
        $categoryId = (int)$categoryId;
    }

    $stmtUpdate = $db->prepare("UPDATE bank_cc_transactions SET category_id = :cat_id WHERE id = :id");
    $stmtUpdate->execute([':cat_id' => $categoryId, ':id' => $txId]);

    $db->commit();

    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    (new Logger())->error('bank/api.php: Kategorie konnte nicht gespeichert werden.', [
        'error' => $e->getMessage(),
    ]);
    Auth::sendJsonError(500, 'Interner Fehler');
}
