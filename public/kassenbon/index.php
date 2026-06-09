<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../bootstrap.php';

// Auth-Check
if (!isset($_SESSION['user_email'])) {
    header('Location: https://kai.agent-smith.de/login.php');
    exit;
}

use Kai\Tools\Shared\Db\Database;

// Konfiguration
$limit = 15; // Anzahl der Kassenbons pro Seite
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

try {
    $pdo = Database::getInstance()->getConnection();

    // 1. Gesamtzahl der Bons ermitteln (für die Seitenberechnung)
    $totalReceipts = $pdo->query("SELECT COUNT(*) FROM kb_receipts")->fetchColumn();
    $totalPages = ceil($totalReceipts / $limit);

    // 2. Nur die Bons der aktuellen Seite holen
    $stmtReceipts = $pdo->prepare("SELECT * FROM kb_receipts ORDER BY purchase_date DESC, id DESC LIMIT :limit OFFSET :offset");
    // Wichtig: PDO muss wissen, dass das Integers sind, sonst knallt es beim LIMIT
    $stmtReceipts->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtReceipts->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtReceipts->execute();
    $receipts = $stmtReceipts->fetchAll();

    // 3. Nur die Items für die ANGEZEIGTEN Bons laden
    $itemsByReceipt = [];
    if (!empty($receipts)) {
        $receiptIds = array_column($receipts, 'id');
        $placeholders = implode(',', array_fill(0, count($receiptIds), '?'));
        
        $stmtItems = $pdo->prepare("SELECT * FROM kb_items WHERE receipt_id IN ($placeholders)");
        $stmtItems->execute($receiptIds);
        $allItems = $stmtItems->fetchAll();
        
        foreach ($allItems as $item) {
            $itemsByReceipt[$item['receipt_id']][] = $item;
        }
    }

} catch (\Throwable $e) {
    die("Datenbankfehler: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kassenbon Dashboard</title>
    
    <link rel="stylesheet" href="../css/style.css">
    
    <style>
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--bg-surface-hover);
            padding-bottom: 1rem;
        }
        
        .header-actions h1 { margin-bottom: 0; border: none; padding: 0; }

        /* Tabelle im Dark-Theme */
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--bg-surface-hover); }
        th { background-color: var(--bg-surface); font-weight: 600; color: var(--text-muted); }
        
        /* Haupt-Zeile (Klickbar) */
        .receipt-row { cursor: pointer; transition: background-color 0.2s; }
        .receipt-row:hover { background-color: var(--bg-surface-hover); }
        .store-name { font-weight: 600; color: var(--text-main); }
        .total-price { font-weight: bold; color: var(--accent); }

        /* Aufklapp-Details */
        .details-row { display: none; background-color: var(--bg-main); }
        .details-row.active { display: table-row; }
        
        .details-container { 
            padding: 20px; 
            margin: 10px 0;
            background-color: var(--bg-surface);
            border-radius: var(--border-radius); 
            border-left: 4px solid var(--accent); 
        }
        
        .details-table th { background-color: rgba(0,0,0,0.2); font-size: 0.9em; }
        .details-table td { font-size: 0.9em; padding: 8px 10px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .details-table tr:last-child td { border-bottom: none; }
        
        .category-badge { 
            display: inline-block; 
            padding: 4px 10px; 
            background: var(--bg-main); 
            border: 1px solid var(--bg-surface-hover);
            border-radius: 12px; 
            font-size: 0.8em; 
            color: var(--text-muted); 
        }
    </style>
</head>
<body>

<div class="container">
    
    <div class="header-actions">
        <h1>🛒 Meine eBons</h1>
        <a href="../index.php" class="btn btn-outline">← Zurück zur Übersicht</a>
    </div>
    
    <?php if (empty($receipts)): ?>
        <div class="card" style="text-align: center;">
            <p>Noch keine Kassenbons in der Datenbank. Sende eine E-Mail mit PDF-Anhang und warte auf den Cronjob!</p>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Händler</th>
                    <th>Gesamtbetrag</th>
                    <th>Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($receipts as $receipt): ?>
                    <tr class="receipt-row" onclick="toggleDetails(<?= $receipt['id'] ?>)">
                        <td><?= date('d.m.Y', strtotime($receipt['purchase_date'])) ?></td>
                        <td class="store-name"><?= htmlspecialchars($receipt['store']) ?></td>
                        <td class="total-price"><?= number_format($receipt['total'], 2, ',', '.') ?> €</td>
                        <td><small style="color: var(--accent);">▼ Details</small></td>
                    </tr>
                    
                    <tr class="details-row" id="details-<?= $receipt['id'] ?>">
                        <td colspan="4" style="padding: 0 15px;">
                            <div class="details-container">
                                <strong style="display: block; margin-bottom: 10px; color: var(--text-muted);">
                                    Positionen für <?= htmlspecialchars($receipt['store']) ?>:
                                </strong>
                                <table class="details-table">
                                    <thead>
                                        <tr>
                                            <th>Menge</th>
                                            <th>Artikel</th>
                                            <th>Kategorie</th>
                                            <th>Einzelpreis</th>
                                            <th>Gesamt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (isset($itemsByReceipt[$receipt['id']])): ?>
                                            <?php foreach ($itemsByReceipt[$receipt['id']] as $item): ?>
                                                <tr>
                                                    <td><?= number_format($item['quantity'], 3, ',', '.') ?> x</td>
                                                    <td style="color: var(--text-main);"><?= htmlspecialchars($item['name']) ?></td>
                                                    <td><span class="category-badge"><?= htmlspecialchars($item['category']) ?></span></td>
                                                    <td><?= number_format($item['unit_price'], 2, ',', '.') ?> €</td>
                                                    <td><?= number_format($item['total_price'], 2, ',', '.') ?> €</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5">Keine Positionen gefunden.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
		<?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>" class="btn btn-outline">&laquo; Vorherige</a>
            <?php else: ?>
                <span class="btn btn-outline" style="opacity: 0.5; cursor: not-allowed;">&laquo; Vorherige</span>
            <?php endif; ?>

            <span class="page-info">Seite <?= $page ?> von <?= $totalPages ?></span>

            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?>" class="btn btn-outline">Nächste &raquo;</a>
            <?php else: ?>
                <span class="btn btn-outline" style="opacity: 0.5; cursor: not-allowed;">Nächste &raquo;</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    function toggleDetails(receiptId) {
        const detailsRow = document.getElementById('details-' + receiptId);
        
        // Toggle Logik
        if (detailsRow.classList.contains('active')) {
            detailsRow.classList.remove('active');
        } else {
            // Erst alle anderen schließen (Akkordeon-Effekt)
            document.querySelectorAll('.details-row.active').forEach(row => {
                row.classList.remove('active');
            });
            // Dann das geklickte öffnen
            detailsRow.classList.add('active');
        }
    }
</script>

</body>
</html>