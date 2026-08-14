<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../src/Bank/RuleMatcher.php';

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Bank\RuleMatcher;

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

    // ----------------------------------------------------
    // Regelsystem-Aktionen (Phase 2.2)
    // ----------------------------------------------------

    // 1. Live-Test eines Regex-Musters
    if ($action === 'test_rule_pattern') {
        $textPattern = trim((string)($data['text_pattern'] ?? ''));
        
        if ($textPattern === '') {
            echo json_encode(['success' => true, 'match_count' => 0]);
            exit;
        }

        $matcher = new RuleMatcher($pdo);
        $stmtAll = $pdo->query("SELECT id, merchant_raw FROM bank_giro_transactions");
        $allTx = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

        $matchCount = 0;
        foreach ($allTx as $tx) {
            if ($matcher->matchesRule($tx['merchant_raw'], $textPattern)) {
                $matchCount++;
            }
        }

        echo json_encode(['success' => true, 'match_count' => $matchCount]);
        exit;
    }

    // 2. Regel speichern oder aktualisieren
    if ($action === 'save_rule') {
        $ruleId = filter_var($data['rule_id'] ?? null, FILTER_VALIDATE_INT);
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
        $textPattern = trim((string)($data['text_pattern'] ?? ''));
        $payeePattern = trim((string)($data['payee_pattern'] ?? '')) ?: null;
        $tagIds = is_array($data['tag_ids'] ?? null) ? array_map('intval', $data['tag_ids']) : [];
        $priority = filter_var($data['priority'] ?? 10, FILTER_VALIDATE_INT) ?: 10;

        if (empty($textPattern) && empty($payeePattern)) {
            Auth::sendJsonError(400, 'Mindestens ein Muster (Text oder Empfänger) muss angegeben werden.');
        }

        $jsonTagIds = json_encode($tagIds);

        $pdo->beginTransaction();

        if ($ruleId) {
            $stmt = $pdo->prepare("
                UPDATE bank_tag_rules 
                SET text_pattern = :text_pattern, payee_pattern = :payee_pattern, tag_ids = :tag_ids, priority = :priority 
                WHERE id = :id
            ");
            $stmt->execute([
                ':text_pattern'  => $textPattern,
                ':payee_pattern' => $payeePattern,
                ':tag_ids'       => $jsonTagIds,
                ':priority'      => $priority,
                ':id'            => $ruleId
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO bank_tag_rules (text_pattern, payee_pattern, tag_ids, priority) 
                VALUES (:text_pattern, :payee_pattern, :tag_ids, :priority)
            ");
            $stmt->execute([
                ':text_pattern'  => $textPattern,
                ':payee_pattern' => $payeePattern,
                ':tag_ids'       => $jsonTagIds,
                ':priority'      => $priority
            ]);
            $ruleId = (int)$pdo->lastInsertId();
        }

        // 1. Für die spezifische Transaktion sofort zuweisen
        if ($txId) {
            $stmtTx = $pdo->prepare("UPDATE bank_giro_transactions SET matched_rule_id = :rule_id WHERE id = :tx_id");
            $stmtTx->execute([':rule_id' => $ruleId, ':tx_id' => $txId]);

            $pdo->prepare("DELETE FROM bank_transaction_tags WHERE transaction_id = :tx_id")->execute([':tx_id' => $txId]);
            $stmtTag = $pdo->prepare("INSERT IGNORE INTO bank_transaction_tags (transaction_id, tag_id) VALUES (:tx_id, :tag_id)");
            foreach ($tagIds as $tId) {
                $stmtTag->execute([':tx_id' => $txId, ':tag_id' => $tId]);
            }
        }

        $pdo->commit();

        // 2. RETROAKTIV: Auf alle verbleibenden regel-losen Umsätze in der DB anwenden!
        $matcher = new RuleMatcher($pdo);
        $matchedCount = $matcher->applyRuleToAllTransactions($ruleId);

        echo json_encode(['success' => true, 'rule_id' => $ruleId, 'retroactive_matches' => $matchedCount]);
        exit;
    }

    // 3. Regel löschen
    if ($action === 'delete_rule') {
        $ruleId = filter_var($data['rule_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$ruleId) {
            Auth::sendJsonError(400, 'Ungültige Rule-ID');
        }

        $pdo->beginTransaction();

        // A: Transaktionen ermitteln, die mit dieser Regel verknüpft waren
        $stmtTxs = $pdo->prepare("SELECT id FROM bank_giro_transactions WHERE matched_rule_id = :rule_id");
        $stmtTxs->execute([':rule_id' => $ruleId]);
        $affectedTxIds = $stmtTxs->fetchAll(PDO::FETCH_COLUMN);

        // B: Regel löschen (Setzt matched_rule_id durch FK ON DELETE SET NULL automatisch auf NULL)
        $stmt = $pdo->prepare("DELETE FROM bank_tag_rules WHERE id = :id");
        $stmt->execute([':id' => $ruleId]);

        // C: Tags bei vormals getaggten Umsätzen der Regel entfernen
        if (!empty($affectedTxIds)) {
            $inClause = implode(',', array_map('intval', $affectedTxIds));
            $pdo->exec("DELETE FROM bank_transaction_tags WHERE transaction_id IN ($inClause)");
        }

        $pdo->commit();

        echo json_encode(['success' => true]);
        exit;
    }

    // 3. Regel löschen
    if ($action === 'delete_rule') {
        $ruleId = filter_var($data['rule_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$ruleId) {
            Auth::sendJsonError(400, 'Ungültige Rule-ID');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("DELETE FROM bank_tag_rules WHERE id = :id");
        $stmt->execute([':id' => $ruleId]);

        $pdo->commit();

        echo json_encode(['success' => true]);
        exit;
    }

    Auth::sendJsonError(400, 'Unbekannte Aktion');

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    (new Logger())->error('bank/api.php: Fehler bei API-Aktion', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler');
}