<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\Kassenbon\ReceiptMatcher;

// Auth-Check — immer zuerst
Auth::requirePage();

$csrfToken = Auth::csrfToken();
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

try {
    $pdo = Database::getInstance()->getConnection();

    // Auto-Sync beim Aufruf der Kassenbon-Übersicht
    $matcher = new ReceiptMatcher($pdo);
    $matcher->syncUnlinkedReceipts();
    
    $totalReceipts = (int)$pdo->query("SELECT COUNT(*) FROM kb_receipts")->fetchColumn();
    $totalPages = (int)ceil($totalReceipts / $limit);

    $stmt = $pdo->prepare("
        SELECT 
            r.*, 
            COUNT(i.id) as item_count,
            gt.booking_date AS giro_booking_date,
            ct.booking_date AS cc_booking_date,
            ct.statement_id AS cc_statement_id
        FROM kb_receipts r 
        LEFT JOIN kb_items i ON r.id = i.receipt_id 
        LEFT JOIN bank_giro_transactions gt ON r.bank_giro_transaction_id = gt.id
        LEFT JOIN bank_cc_transactions ct ON r.bank_cc_transaction_id = ct.id
        GROUP BY r.id 
        ORDER BY r.purchase_date DESC, r.id DESC 
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Kandidatenanzahl für offene Bons ermitteln
    foreach ($receipts as &$receipt) {
        $receipt['candidate_count'] = 0;
        if (empty($receipt['bank_giro_transaction_id']) && empty($receipt['bank_cc_transaction_id'])) {
            $candidates = $matcher->getCandidatesForReceipt((int)$receipt['id']);
            $receipt['candidate_count'] = count($candidates['giro']) + count($candidates['cc']);
        }
    }
    unset($receipt);

} catch (\Throwable $e) {
    (new Logger())->error('kassenbon/index.php: Datenbankfehler.', ['error' => $e->getMessage()]);
    http_response_code(500);
    exit('Interner Fehler. Bitte versuche es später erneut.');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Meine eBons - Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
    <div class="container">
        <header class="page-header">
            <h1>🛒 Meine eBons</h1>
            <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
        </header>

        <section class="card">
            <div class="table-responsive">
                <table class="receipts-table stack-table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Händler</th>
                            <th>Zahlungsstatus</th>
                            <th>Positionen</th>
                            <th class="text-right">Gesamtbetrag</th>
                            <th class="text-right">Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($receipts as $receipt): ?>
                            <tr>
                                <td data-label="Datum"><?= date('d.m.Y', strtotime($receipt['purchase_date'])) ?></td>
                                <td data-label="Händler" class="amount-bold"><?= htmlspecialchars($receipt['store'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
								<td data-label="Zahlungsstatus">
									<?php if (!empty($receipt['bank_giro_transaction_id'])): ?>
										<a href="../bank/index.php?tx=<?= (int)$receipt['bank_giro_transaction_id'] ?>" class="badge badge-success badge-link" title="Auf Girokonto verbucht">
											🟢 Girokonto
										</a>										
									<?php elseif (!empty($receipt['bank_cc_transaction_id'])): ?>
										<a href="../bank/detail.php?id=<?= (int)$receipt['cc_statement_id'] ?>" class="badge badge-success badge-link" title="Über Kreditkarte verbucht">
											🟢 Kreditkarte
										</a>
									<?php elseif ($receipt['candidate_count'] > 0): ?>
										<button type="button" class="badge badge-warning badge-button js-open-candidate-modal" data-receipt-id="<?= (int)$receipt['id'] ?>">
											🟡 <?= (int)$receipt['candidate_count'] ?> Kandidat(en)
										</button>
									<?php else: ?>
										<button type="button" class="badge badge-warning badge-button js-open-candidate-modal" data-receipt-id="<?= (int)$receipt['id'] ?>">
											🟡 Offen
										</button>
									<?php endif; ?>
								</td>
                                <td data-label="Positionen"><?= (int)$receipt['item_count'] ?></td>
                                <td data-label="Gesamtbetrag" class="text-right amount-bold">
								    <?= number_format((float)$receipt['total'], 2, ',', '.') ?> €
								</td>
								<td data-label="Aktion" class="text-right">
									<a href="detail.php?id=<?= (int)$receipt['id'] ?>" class="btn btn-sm btn-outline btn-icon-only" title="Details anzeigen">🔍</a>
								</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>" class="btn btn-outline">&laquo; Vorherige</a>
                    <?php endif; ?>
                    <span class="page-info">Seite <?= $page ?> von <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>" class="btn btn-outline">Nächste &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script src="../js/http.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="../js/kassenbon-candidates.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>