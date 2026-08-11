<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;

$db = Database::getInstance()->getConnection();

// Filter nach Karteninhaber (optional)
$holderFilter = $_GET['holder'] ?? '';

// Abrechnungen abfragen
$sql = "
    SELECT s.*, 
           COUNT(t.id) AS tx_count,
           SUM(CASE WHEN t.amount < 0 THEN t.amount ELSE 0 END) AS total_spent
    FROM bank_cc_statements s
    LEFT JOIN bank_cc_transactions t ON s.id = t.statement_id
    GROUP BY s.id
    ORDER BY s.statement_date DESC
";
$stmt = $db->query($sql);
$statements = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Meine Kreditkartenabrechnungen - Kai</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container">
        <header class="page-header">
            <h1>💳 Kreditkartenabrechnungen</h1>
            <a href="../index.php" class="btn btn-secondary">Zurück zur Übersicht</a>
        </header>

        <section class="card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Abrechnungsdatum</th>
                        <th>Fälligkeit</th>
                        <th>Positionen</th>
                        <th>Abbuchungsbetrag</th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($statements)): ?>
                        <tr>
                            <td colspan="5" class="text-center">Noch keine Abrechnungen vorhanden.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($statements as $stmtRow): ?>
                            <tr>
                                <td><?= htmlspecialchars($stmtRow['statement_date']) ?></td>
                                <td><?= htmlspecialchars($stmtRow['due_date'] ?? '-') ?></td>
                                <td><?= (int)$stmtRow['tx_count'] ?></td>
                                <td class="amount-bold"><?= number_format((float)$stmtRow['total_amount'], 2, ',', '.') ?> €</td>
                                <td>
                                    <a href="detail.php?id=<?= (int)$stmtRow['id'] ?>" class="btn btn-small">Details</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>
</body>
</html>