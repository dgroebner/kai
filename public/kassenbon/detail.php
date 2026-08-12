<?php
require_once __DIR__ . '/../../bootstrap.php';

if (!isset($_SESSION['user_email'])) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

use Kai\Tools\Shared\Db\Database;

$id = (int)($_GET['id'] ?? 0);
$pdo = Database::getInstance()->getConnection();

$stmt = $pdo->prepare("SELECT * FROM kb_receipts WHERE id = :id");
$stmt->execute([':id' => $id]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$receipt) {
    die("Kassenbon nicht gefunden.");
}

$stmtItems = $pdo->prepare("SELECT * FROM kb_items WHERE receipt_id = :id ORDER BY id ASC");
$stmtItems->execute([':id' => $id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$stmtCats = $pdo->query("SELECT DISTINCT category FROM kb_items WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
$allCategories = $stmtCats->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eBon <?= htmlspecialchars($receipt['store']) ?> - Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
    <div class="container">
        <header class="page-header">
            <h1>🛒 <?= htmlspecialchars($receipt['store']) ?> <small style="font-size: 0.6em; color: var(--text-muted);">(<?= date('d.m.Y', strtotime($receipt['purchase_date'])) ?>)</small></h1>
            <a href="index.php" class="btn btn-outline">&larr; Zurück zu den Mails</a>
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
            <section class="card" style="margin-top: 1.5rem;">
                <div class="page-header" style="border-bottom: none; margin-bottom: 0; padding-bottom: 0;">
                    <h2>Positionen</h2>
                    <button id="resetFilterBtn" class="btn btn-sm btn-outline hidden">Filter zurücksetzen</button>
                </div>

                <div class="table-responsive">
                    <table class="receipts-table" id="itemsTable">
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
                                <tr data-item-id="<?= $item['id'] ?>" data-category-name="<?= htmlspecialchars($item['category'] ?? 'Sonstiges') ?>">
                                    <td><?= number_format((float)$item['quantity'], 3, ',', '.') ?> x</td>
                                    <td class="amount-bold"><?= htmlspecialchars($item['name']) ?></td>
                                    <td class="category-cell">
                                        <span class="clickable-badge" data-item-id="<?= $item['id'] ?>">
                                            <span class="badge-text"><?= htmlspecialchars($item['category'] ?? 'Sonstiges') ?></span>
                                            <span class="edit-icon">✏️</span>
                                        </span>
                                    </td>
                                    <td class="text-right"><?= number_format((float)$item['unit_price'], 2, ',', '.') ?> €</td>
                                    <td class="text-right amount-bold"><?= number_format((float)$item['total_price'], 2, ',', '.') ?> €</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script src="../js/chart.min.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="../js/kassenbon.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>