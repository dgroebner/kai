<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check (AGENTS.md)
Auth::requireApi();

// 2. HTTP-Methoden-Check
Auth::requireMethod('POST');

// 3. Input validieren
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    Auth::sendJsonError(400, 'Ungültige Anfrage');
}

$action = $data['action'] ?? '';
$pdo = Database::getInstance()->getConnection();

try {
    if ($action === 'add_tag_to_tx') {
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
        $tagId = filter_var($data['tag_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$txId || !$tagId) {
            Auth::sendJsonError(400, 'Ungültige Parameter');
        }

        $stmt = $pdo->prepare("INSERT IGNORE INTO bank_transaction_tags (transaction_id, tag_id) VALUES (:tx_id, :tag_id)");
        $stmt->execute([':tx_id' => $txId, ':tag_id' => $tagId]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'remove_tag_from_tx') {
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
        $tagId = filter_var($data['tag_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$txId || !$tagId) {
            Auth::sendJsonError(400, 'Ungültige Parameter');
        }

        $stmt = $pdo->prepare("DELETE FROM bank_transaction_tags WHERE transaction_id = :tx_id AND tag_id = :tag_id");
        $stmt->execute([':tx_id' => $txId, ':tag_id' => $tagId]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'create_and_assign_tag') {
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string)($data['name'] ?? ''));
        $color = trim((string)($data['color'] ?? '#3b82f6'));

        if (!$txId || $name === '' || mb_strlen($name) > 50) {
            Auth::sendJsonError(400, 'Name ungültig oder zu lang');
        }

        // Tag anlegen falls nicht vorhanden
        $stmtCreate = $pdo->prepare("INSERT INTO bank_tags (name, color) VALUES (:name, :color) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
        $stmtCreate->execute([':name' => $name, ':color' => $color]);
        $tagId = (int)$pdo->lastInsertId();

        // Tag zuweisen
        $stmtAssign = $pdo->prepare("INSERT IGNORE INTO bank_transaction_tags (transaction_id, tag_id) VALUES (:tx_id, :tag_id)");
        $stmtAssign->execute([':tx_id' => $txId, ':tag_id' => $tagId]);

        echo json_encode([
            'success' => true, 
            'tag' => [
                'id'    => $tagId,
                'name'  => $name,
                'color' => $color
            ]
        ]);
        exit;
    }

    Auth::sendJsonError(400, 'Unbekannte Aktion');

} catch (\Throwable $e) {
    (new Logger())->error('bank/api.php: Fehler bei Tag-Aktion', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler');
}