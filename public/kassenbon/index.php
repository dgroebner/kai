<?php
require_once __DIR__ . '/../../bootstrap.php';

// Auth-Check — muss als erstes stehen, bevor irgendwelche Logik läuft
if (!isset($_SESSION['user_email'])) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

use Kai\Tools\Kassenbon\CategoryAnalyzer;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use \PDO;

$categoryAnalyzer = new CategoryAnalyzer();
$logger = new Logger();

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
    $logger->error("Kassenbon index.php: Datenbankfehler beim Laden der Übersicht.", ['error' => $e->getMessage()]);
    die("Interner Fehler. Bitte versuche es später erneut.");
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kassenbon Dashboard</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
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
        <div class="table-responsive">
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
                                $niceColors = [
                                    '#3b82f6', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', 
                                    '#06b6d4', '#f97316', '#84cc16', '#a855f7', '#14b8a6', 
                                    '#ef4444', '#64748b'
                                ];
                                $categoryColorMap = [];
                                $colorIndex = 0;
                                foreach ($analysis['categories'] as $cat => $data) {
                                    $categoryColorMap[$cat] = $niceColors[$colorIndex % count($niceColors)];
                                    $colorIndex++;
                                }
                                ?>
                                <div class="category-analysis-wrapper">
                                    <div class="category-table-container">
                                        <strong style="color: var(--text-muted); font-size: 0.9em; display: block; margin-bottom: 5px;">Kategorien-Anteil:</strong>
                                        <table class="category-share-table">
                                            <thead>
                                                <tr>
                                                    <th>Kategorie</th>
                                                    <th style="text-align: right; width: 60px;">Anteil</th>
                                                    <th style="text-align: right; width: 90px;">Gesamt</th>
                                                </tr>
                                            </thead>
                                            <tbody class="js-category-table-body">
                                                <?php
                                                $colorIndex = 0;
                                                foreach ($analysis['categories'] as $cat => $data):
                                                    $color = $niceColors[$colorIndex % count($niceColors)];
                                                    $colorIndex++;
                                                ?>
                                                    <tr class="js-category-row" data-category="<?= htmlspecialchars($cat) ?>" data-percentage="<?= number_format($data['percentage'], 4, '.', '') ?>" data-total="<?= number_format($data['total'], 4, '.', '') ?>" data-color="<?= $color ?>" style="--category-color: <?= $color ?>;">
                                                        <td>
                                                            <span class="category-color-dot" style="background-color: <?= $color ?>;"></span>
                                                            <span class="category-name"><?= htmlspecialchars($cat) ?></span>
                                                        </td>
                                                        <td class="percentage-cell" style="text-align: right; font-weight: 500;"><?= number_format($data['percentage'], 1, ',', '.') ?>%</td>
                                                        <td class="total-cell" style="text-align: right;"><?= number_format($data['total'], 2, ',', '.') ?> €</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="category-chart-container js-chart-container">
                                        <!-- Interactive SVG will be injected/updated by JS -->
                                    </div>
                                </div>
                                <div class="table-responsive">
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
                                                <?php foreach ($itemsByReceipt[$receipt['id']] as $item): 
                                                    $itemColor = $categoryColorMap[$item['category']] ?? '#64748b';
                                                ?>
                                                    <tr class="js-item-row" data-total-price="<?= number_format($item['total_price'], 4, '.', '') ?>">
                                                        <td><?= number_format($item['quantity'], 3, ',', '.') ?> x</td>
                                                        <td style="color: var(--text-main);"><?= htmlspecialchars($item['name']) ?></td>
                                                        
                                                        <td class="category-cell" data-item-id="<?= $item['id'] ?>">
                                                            
                                                            <div class="category-view">
                                                                <span class="category-badge js-cat-label" style="--category-color: <?= $itemColor ?>;"><?= htmlspecialchars($item['category']) ?></span>
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
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div><!-- /.table-responsive (main) -->

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

<script src="../js/kassenbon.js?v=<?= APP_VERSION ?>" defer></script>

</body>
</html>