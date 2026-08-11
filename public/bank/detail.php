<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;

$id = (int)($_GET['id'] ?? 0);
$db = Database::getInstance()->getConnection();

$stmtInfo = $db->prepare("SELECT * FROM bank_cc_statements WHERE id = :id");
$stmtInfo->execute([':id' => $id]);
$statement = $stmtInfo->fetch(PDO::FETCH_ASSOC);

if (!$statement) {
    die("Abrechnung nicht gefunden.");
}

$stmtTx = $db->prepare("
    SELECT t.*, c.name AS category_name 
    FROM bank_cc_transactions t
    LEFT JOIN bank_categories c ON t.category_id = c.id
    WHERE t.statement_id = :id
    ORDER BY t.booking_date DESC
");
$stmtTx->execute([':id' => $id]);
$transactions = $stmtTx->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Abrechnungsdetails - <?= htmlspecialchars($statement['statement_date']) ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container">
        <header class="page-header">
            <h1>Abrechnung vom <?= htmlspecialchars($statement['statement_date']) ?></h1>
            <a href="index.php" class="btn btn-secondary">Zurück</a>
        </header>

        <section class="card">
            <p><strong>Fälligkeit:</strong> <?= htmlspecialchars($statement['due_date'] ?? '-') ?></p>
            <p><strong>Gesamtbetrag:</strong> <?= number_format((float)$statement['total_amount'], 2, ',', '.') ?> €</p>
        </section>

        <section class="card">
            <h2>Einzelumsätze</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Händler / Ort</th>
                        <th>Karte</th>
                        <th>Kategorie</th>
                        <th>Betrag</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx): ?>
                        <tr>
                            <td><?= htmlspecialchars($tx['booking_date']) ?></td>
                            <td><?= htmlspecialchars($tx['merchant_name']) ?></td>
                            <td>*<?= htmlspecialchars($tx['card_number_suffix'] ?? '----') ?></td>
                            <td><span class="badge"><?= htmlspecialchars($tx['category_name'] ?? 'Sonstiges') ?></span></td>
                            <td class="<?= $tx['amount'] < 0 ? 'text-danger' : 'text-success' ?>">
                                <?= number_format((float)$tx['amount'], 2, ',', '.') ?> €
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>
</body>
</html>