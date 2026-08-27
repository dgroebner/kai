<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Kassenbon\ReceiptMatcher;
use Kai\Tools\Kassenbon\ReceiptRepository;
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

$action = $data['action'] ?? 'update_category';

try {
    $matcher = new ReceiptMatcher();

    if ($action === 'sync_receipts') {
        $result = $matcher->syncUnlinkedReceipts();

        echo json_encode(['success' => true, 'linked' => $result]);
        exit;
    }

    if ($action === 'get_candidates') {
        $receiptId = filter_var($data['receipt_id'] ?? null, FILTER_VALIDATE_INT);
        if ($receiptId === false || $receiptId === null || $receiptId <= 0) {
            Auth::sendJsonError(400, 'Ungültige Parameter');
        }

        $candidates = $matcher->getCandidatesForReceipt($receiptId);

        echo json_encode(['success' => true, 'candidates' => $candidates]);
        exit;
    }

    if ($action === 'link_manual') {
        $receiptId = filter_var($data['receipt_id'] ?? null, FILTER_VALIDATE_INT);
        $txId = filter_var($data['tx_id'] ?? null, FILTER_VALIDATE_INT);
        $accountType = trim((string)($data['account_type'] ?? ''));
        $applyCashTag = (bool)($data['apply_cash_tag'] ?? false);

        if ($receiptId === false || $receiptId === null || $receiptId <= 0
            || $txId === false || $txId === null || $txId <= 0
            || !in_array($accountType, ['giro', 'cc'])) {
            Auth::sendJsonError(400, 'Ungültige Parameter');
        }

        $success = $matcher->linkReceiptManually($receiptId, $txId, $accountType, $applyCashTag);

        if ($success) {
            echo json_encode(['success' => true]);
        } else {
            Auth::sendJsonError(500, 'Verknüpfung fehlgeschlagen');
        }
        exit;
    }

    if ($action === 'update_category') {
        $itemId = filter_var($data['item_id'] ?? null, FILTER_VALIDATE_INT);
        $categoryName = trim((string)($data['category_name'] ?? ''));

        if ($itemId === false || $itemId === null || $itemId <= 0
            || $categoryName === '' || mb_strlen($categoryName) > 100) {
            Auth::sendJsonError(400, 'Ungültige Parameter');
        }

        new ReceiptRepository()->updateItemCategory($itemId, $categoryName);

        echo json_encode(['success' => true]);
        exit;
    }

    Auth::sendJsonError(400, 'Unbekannte Aktion');

} catch (Throwable $e) {
    new Logger()->error('kassenbon/api.php: Fehler bei Ausführung.', [
        'error' => $e->getMessage(),
    ]);
    Auth::sendJsonError(500, 'Interner Fehler');
}