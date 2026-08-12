<?php
require_once __DIR__ . '/../../bootstrap.php';

if (!isset($_SESSION['user_email'])) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

use Kai\Tools\Shared\Db\Database;

$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

try {
    $pdo = Database::getInstance()->getConnection();
    
    $totalReceipts = $pdo->query("SELECT COUNT(*) FROM kb_receipts")->fetchColumn();
    $totalPages = ceil($totalReceipts / $limit);

    $stmt = $pdo->prepare("
        SELECT r.*, COUNT(i.id) as item_count 
        FROM kb_receipts r 
        LEFT JOIN kb_items i ON r.id = i.receipt_id 
        GROUP BY r.id 
        ORDER BY r.purchase_date DESC, r.id DESC 
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    die("Datenbankfehler.");
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                <table class="receipts-table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Händler</th>
                            <th>Positionen</th>
                            <th class="text-right">Gesamtbetrag</th>
                            <th class="text-right">Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($receipts as $receipt): ?>
                            <tr>
                                <td><?= date('d.m.Y', strtotime($receipt['purchase_date'])) ?></td>
                                <td class="amount-bold"><?= htmlspecialchars($receipt['store']) ?></td>
                                <td><?= (int)$receipt['item_count'] ?> Positionen</td>
                                <td class="text-right amount-bold"><?= number_format((float)$receipt['total'], 2, ',', '.') ?> €</td>
                                <td class="text-right">
                                    <a href="detail.php?id=<?= $receipt['id'] ?>" class="btn btn-sm btn-outline">Details &rarr;</a>
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
</body>
</html>