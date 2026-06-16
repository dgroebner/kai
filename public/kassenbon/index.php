<?php
use Kai\Tools\Kassenbon\CategoryAnalyzer;
use Kai\Tools\Shared\Db\Database;
use \PDO;

require_once __DIR__ . '/../../bootstrap.php';

$categoryAnalyzer = new CategoryAnalyzer();

// Auth-Check
if (!isset($_SESSION['user_email'])) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// CSRF-Token generieren und in der Session speichern
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- AJAX Handler für das Inline-Update ---
// Fängt den POST-Request aus unserem JavaScript ab und speichert die neue Kategorie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_category') {
    header('Content-Type: application/json');

    // CSRF-Token prüfen
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ungültiger CSRF-Token']);
        exit;
    }

    try {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $newCategory = trim($_POST['category'] ?? '');
        
        if ($itemId > 0 && !empty($newCategory)) {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("UPDATE kb_items SET category = :cat WHERE id = :id");
            $stmt->execute([':cat' => $newCategory, ':id' => $itemId]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Ungültige Daten']);
        }
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Datenbankfehler']);
    }
    exit;
}
// ------------------------------------------

// Konfiguration Paginierung
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$receipts = [];
$itemsByReceipt = [];
$totalPages = 0;
$allCategories = [];

try {
    $pdo = Database::getInstance()->getConnection();

    // Alle aktuell bekannten Kategorien für das Dropdown holen
    $stmtCats = $pdo->query("SELECT DISTINCT category FROM kb_items WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
    $allCategories = $stmtCats->fetchAll(PDO::FETCH_COLUMN);

    $totalReceipts = $pdo->query("SELECT COUNT(*) FROM kb_receipts")->fetchColumn();
    $totalPages = ceil($totalReceipts / $limit);

    $stmtReceipts = $pdo->prepare("SELECT * FROM kb_receipts ORDER BY purchase_date DESC, id DESC LIMIT :limit OFFSET :offset");
    $stmtReceipts->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtReceipts->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtReceipts->execute();
    $receipts = $stmtReceipts->fetchAll();

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
    die("Datenbankfehler: " . $e->getMessage() . " in Zeile " . $e->getLine());
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
        .header-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; border-bottom: 1px solid var(--bg-surface-hover); padding-bottom: 1rem; }
        .header-actions h1 { margin-bottom: 0; border: none; padding: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--bg-surface-hover); }
        th { background-color: var(--bg-surface); font-weight: 600; color: var(--text-muted); }
        .receipt-row { cursor: pointer; transition: background-color 0.2s; }
        .receipt-row:hover { background-color: var(--bg-surface-hover); }
        .store-name { font-weight: 600; color: var(--text-main); }
        .total-price { font-weight: bold; color: var(--accent); }
        .details-row { display: none; background-color: var(--bg-main); }
        .details-row.active { display: table-row; }
        .details-container { padding: 20px; margin: 10px 0; background-color: var(--bg-surface); border-radius: var(--border-radius); border-left: 4px solid var(--accent); }
        .details-table th { background-color: rgba(0,0,0,0.2); font-size: 0.9em; }
        .details-table td { font-size: 0.9em; padding: 8px 10px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .details-table tr:last-child td { border-bottom: none; }
        
        .category-badge { display: inline-block; padding: 4px 10px; background: var(--bg-main); border: 1px solid var(--bg-surface-hover); border-radius: 12px; font-size: 0.8em; color: var(--text-muted); transition: all 0.2s; }
        .pagination { display: flex; justify-content: center; align-items: center; gap: 15px; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--bg-surface-hover); }
        .page-info { color: var(--text-muted); font-size: 0.9em; }

        /* --- Neue CSS Klassen für Inline-Edit --- */
        .category-cell { position: relative; }
        
        /* Edit-Icon (Stift) beim Hovern anzeigen */
        .category-view { display: flex; align-items: center; gap: 8px; }
        .edit-icon { opacity: 0; cursor: pointer; font-size: 0.9em; transition: opacity 0.2s; filter: grayscale(1); }
        .category-view:hover .edit-icon { opacity: 1; filter: grayscale(0); }
        
        /* Eingabefeld & Buttons */
        .category-edit { display: none; position: relative; }
        .category-input-group { display: flex; align-items: center; gap: 5px; }
        .category-input { padding: 4px 8px; background: var(--bg-main); border: 1px solid var(--accent); color: var(--text-main); border-radius: 4px; font-size: 0.85em; width: 140px; outline: none; }
        .action-btn { background: none; border: none; cursor: pointer; padding: 0; font-size: 1.1em; opacity: 0.8; transition: transform 0.1s; }
        .action-btn:hover { opacity: 1; transform: scale(1.1); }
        
        /* Autocomplete Dropdown */
        .autocomplete-list { position: absolute; top: 100%; left: 0; background: var(--bg-surface); border: 1px solid var(--bg-surface-hover); border-radius: 4px; padding: 0; margin: 4px 0 0 0; list-style: none; width: 180px; max-height: 150px; overflow-y: auto; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.3); display: none; }
        .autocomplete-list li { padding: 8px 12px; font-size: 0.85em; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.02); }
        .autocomplete-list li:hover { background: var(--bg-surface-hover); color: var(--accent); }
    </style>
</head>
<body>

<div class="container" data-categories='<?= htmlspecialchars(json_encode($allCategories), ENT_QUOTES, 'UTF-8') ?>' data-csrf-token='<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>'>
    <div class="header-actions">
        <h1>🛒 Meine eBons</h1>
        <a href="../index.php" class="btn btn-outline">← Zurück zur Übersicht</a>
    </div>
    
    <?php if (empty($receipts)): ?>
        <div class="card" style="text-align: center;">
            <p>Noch keine Kassenbons in der Datenbank.</p>
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
                    <tr class="receipt-row js-toggle-receipt" data-id="<?= $receipt['id'] ?>">
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
                                <?php
                                $analysis = $categoryAnalyzer->analyze($itemsByReceipt[$receipt['id']] ?? []);
                                ?>
                                <div style="margin-bottom: 15px; padding: 10px; background: rgba(0,0,0,0.1); border-radius: 5px;">
                                    <strong style="color: var(--text-muted); font-size: 0.9em;">Kategorien-Anteil:</strong>
                                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 5px;">
                                        <?php foreach ($analysis['categories'] as $cat => $data): ?>
                                            <span class="category-badge" title="<?= number_format($data['total'], 2, ',', '.') ?> €">
                                                <?= htmlspecialchars($cat) ?>: <?= number_format($data['percentage'], 1, ',', '.') ?>%
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <table class="details-table">
                                    <thead>
                                        <tr>
                                            <th>Menge</th>
                                            <th>Artikel</th>
                                            <th style="min-width: 200px;">Kategorie</th>
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
                                                    
                                                    <td class="category-cell" data-item-id="<?= $item['id'] ?>">
                                                        
                                                        <div class="category-view">
                                                            <span class="category-badge js-cat-label"><?= htmlspecialchars($item['category']) ?></span>
                                                            <span class="edit-icon js-edit-cat" title="Kategorie bearbeiten">✏️</span>
                                                        </div>

                                                        <div class="category-edit">
                                                            <div class="category-input-group">
                                                                <input type="text" class="category-input js-cat-input" value="<?= htmlspecialchars($item['category']) ?>" autocomplete="off">
                                                                <button class="action-btn js-save-cat" title="Übernehmen">✅</button>
                                                                <button class="action-btn js-cancel-cat" title="Abbrechen">❌</button>
                                                            </div>
                                                            <ul class="autocomplete-list js-autocomplete"></ul>
                                                        </div>

                                                    </td>
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

    <?php endif; ?>
</div>

<script src="../js/kassenbon.js"></script>

</body>
</html>