<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Kassenbon\ReceiptMatcher;
use Kai\Tools\Kassenbon\ReceiptRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check — immer zuerst
Auth::requireApi('ebon_read');

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
    $matcher = new \Kai\Tools\Kassenbon\ReceiptMatcher();

    if ($action === 'lookup_open_food_facts') {
        $query = trim((string)($data['query'] ?? ''));
        $productKey = trim((string)($data['product_key'] ?? $query));
        if ($query === '' || mb_strlen($query) > 200) {
            Auth::sendJsonError(400, 'Ungültiger Suchbegriff');
        }

        $queueRepo = new \Kai\Tools\Kassenbon\OpenFoodFactsQueueRepository();
        $cached = $queueRepo->getProduct($productKey);

        if ($cached && in_array($cached['status'], ['completed', 'not_found'], true)) {
            $isFound = $cached['status'] === 'completed';
            echo json_encode([
                'success' => true,
                'data' => [
                    'found' => $isFound,
                    'status' => $cached['status'],
                    'code' => $cached['code'],
                    'product_name' => $cached['product_name'],
                    'brands' => $cached['brands'],
                    'quantity' => $cached['quantity'],
                    'nutriscore_grade' => $cached['nutriscore_grade'],
                    'image_url' => $cached['image_url'],
                    'categories' => $cached['categories'],
                    'confidence' => isset($cached['confidence']) ? (float)$cached['confidence'] : null,
                    'source' => 'cache'
                ]
            ]);
            exit;
        }

        // In Warteschlange für den lokalen Raspi-Worker einreihen
        $queueRepo->enqueueProduct($productKey, $query);

        echo json_encode([
            'success' => true,
            'data' => [
                'found' => false,
                'status' => 'pending',
                'queued' => true,
                'message' => 'In Warteschlange eingereiht (wird via Raspi-Worker synchronisiert)'
            ]
        ]);
        exit;
    }

    if ($action === 'enqueue_all_off') {
        $items = $data['items'] ?? [];
        if (!is_array($items)) {
            Auth::sendJsonError(400, 'Ungültige Parameter');
        }
        $queueRepo = new \Kai\Tools\Kassenbon\OpenFoodFactsQueueRepository();
        $count = $queueRepo->enqueueBatch($items);

        echo json_encode(['success' => true, 'enqueued' => $count]);
        exit;
    }

    if ($action === 'sync_receipts') {
        if (!Auth::hasPermission('ebon_write')) {
            Auth::sendJsonError(403, 'Keine Schreibberechtigung');
        }
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
        if (!Auth::hasPermission('ebon_write')) {
            Auth::sendJsonError(403, 'Keine Schreibberechtigung');
        }
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
        if (!Auth::hasPermission('ebon_write')) {
            Auth::sendJsonError(403, 'Keine Schreibberechtigung');
        }
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
