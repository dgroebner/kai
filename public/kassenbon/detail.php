<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// Auth-Check — immer zuerst
Auth::requirePage();

$csrfToken = Auth::csrfToken();

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id <= 0) {
    http_response_code(400);
    exit('Ungültiger Kassenbon.');
}

try {
    $pdo = Database::getInstance()->getConnection();

    // Bon inkl. Verknüpfung zu Girokonto oder Kreditkarte laden
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            gt.booking_date AS giro_booking_date,
            ct.booking_date AS cc_booking_date,
            ct.statement_id AS cc_statement_id
        FROM kb_receipts r
        LEFT JOIN bank_giro_transactions gt ON r.bank_giro_transaction_id = gt.id
        LEFT JOIN bank_cc_transactions ct ON r.bank_cc_transaction_id = ct.id
        WHERE r.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receipt) {
        http_response_code(404);
        exit('Kassenbon nicht gefunden.');
    }

    $stmtItems = $pdo->prepare("SELECT * FROM kb_items WHERE receipt_id = :id ORDER BY id");
    $stmtItems->execute([':id' => $id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $stmtCats = $pdo->query("SELECT DISTINCT category FROM kb_items WHERE category IS NOT NULL AND category != '' ORDER BY category");
    $allCategories = $stmtCats->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    (new Logger())->error('kassenbon/detail.php: Datenbankfehler.', ['error' => $e->getMessage()]);
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
    <title>eBon <?= htmlspecialchars($receipt['store'] ?? '', ENT_QUOTES, 'UTF-8') ?> - Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
    <div class="container">
        <header class="page-header">
            <div>
                <h1>🛒 <?= htmlspecialchars($receipt['store'] ?? '', ENT_QUOTES, 'UTF-8') ?> <small class="page-header-sub">(<?= date('d.m.Y', strtotime($receipt['purchase_date'])) ?>)</small></h1>
                <div style="margin-top: 0.5rem;">
                    <?php if (!empty($receipt['bank_giro_transaction_id'])): ?>
                        <a href="../bank/index.php" class="badge badge-success" style="text-decoration: none; font-size: 0.85rem;" title="Zur Girokonto-Ansicht wechseln">
                            🟢 Girokonto abgebucht (<?= date('d.m.Y', strtotime($receipt['giro_booking_date'])) ?>) &rarr;
                        </a>
                    <?php elseif (!empty($receipt['bank_cc_transaction_id'])): ?>
                        <a href="../bank/detail.php?id=<?= (int)$receipt['cc_statement_id'] ?>" class="badge badge-success" style="text-decoration: none; font-size: 0.85rem;" title="Zur Kreditkartenabrechnung wechseln">
                            🟢 Kreditkarte abgebucht (<?= date('d.m.Y', strtotime($receipt['cc_booking_date'])) ?>) &rarr;
                        </a>
                    <?php else: ?>
                        <span class="badge badge-warning" style="font-size: 0.85rem;">🟡 Zahlung offen</span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="index.php" class="btn btn-outline">&larr; Zurück zu der Übersicht</a>
        </header>

        <main id="kassenbonDetailApp"
              data-categories='<?= htmlspecialchars(json_encode($allCategories), ENT_QUOTES, 'UTF-8') ?>'
              data-items='<?= htmlspecialchars(json_encode($items), ENT_QUOTES, 'UTF-8') ?>'
              data-total="<?= (float)$receipt['total'] ?>">

            <!-- Kopfbereich: Donut & Legende -->
            <section class="card">
                <h3>Kategorien-Anteil</h3>
                <div class="analysis-grid">
                    <div class="category-legend" id="categoryLegend"></div>
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                        <div class="chart-center-text">
                            <span class="label">Gesamt</span>
                            <span class="value"><?= number_format((float)$receipt['total'], 2, ',', '.') ?> €</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Einzelpositionen -->
            <section class="card u-mt-lg">
                <div class="page-header page-header-flush">
                    <h2>Positionen</h2>
                    <button id="resetFilterBtn" class="btn btn-sm btn-outline hidden">Filter zurücksetzen</button>
                </div>

                <div class="table-responsive">
                    <table class="receipts-table stack-table" id="itemsTable">
                        <thead>
                            <tr>
                                <th>Menge</th>
                                <th>Artikel</th>
                                <th>Kategorie</th>
                                <th class="text-right">Einzelpreis</th>
                                <th class="text-right">Gesamt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr data-item-id="<?= (int)$item['id'] ?>" data-category-name="<?= htmlspecialchars($item['category'] ?? 'Sonstiges', ENT_QUOTES, 'UTF-8') ?>">
                                    <td data-label="Menge"><?= number_format((float)$item['quantity'], 3, ',', '.') ?> x</td>
                                    <td data-label="Artikel" class="amount-bold"><?= htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td data-label="Kategorie" class="category-cell">
                                        <span class="clickable-badge" data-item-id="<?= (int)$item['id'] ?>">
                                            <span class="badge-text"><?= htmlspecialchars($item['category'] ?? 'Sonstiges', ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="edit-icon">✏️</span>
                                        </span>
                                    </td>
                                    <td data-label="Einzelpreis" class="text-right">
									    <?= number_format((float)$item['unit_price'], 2, ',', '.') ?> €
									</td>
                                    <td data-label="Gesamt" class="text-right amount-bold">
									    <?= number_format((float)$item['total_price'], 2, ',', '.') ?> €
									</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script src="../js/chart.min.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="../js/http.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="../js/kassenbon.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>