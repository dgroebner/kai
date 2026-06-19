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
        .pagination { display: flex; justify-content: center; align-items: center; gap: 15px; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--bg-surface-hover); flex-wrap: wrap; }
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

        /* --- Analyse-Bereich (Tabelle & Donut Diagramm) --- */
        .category-analysis-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
            margin-bottom: 20px;
            padding: 20px;
            background: rgba(0, 0, 0, 0.15);
            border-radius: var(--border-radius);
            border: 1px solid var(--bg-surface-hover);
        }
        .category-table-container {
            flex: 1;
            min-width: 0;
        }
        .category-chart-container {
            flex: 0 0 220px;
            width: 220px;
            height: 220px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        @media (max-width: 650px) {
            .category-analysis-wrapper {
                flex-direction: column;
                align-items: center;
                padding: 12px;
                gap: 15px;
            }
            .category-table-container {
                width: 100%;
            }
        }
        
        /* Mobile-spezifische Anpassungen */
        @media (max-width: 600px) {
            th, td {
                padding: 8px 8px;
                font-size: 0.85em;
            }
            .receipt-row td {
                white-space: nowrap;
            }
            .store-name {
                max-width: 120px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            /* Kein Seitenabstand in der Details-Zeile auf Mobilgeräten */
            .details-row > td {
                padding-left: 0 !important;
                padding-right: 0 !important;
            }
            .details-container {
                padding: 10px;
                margin: 4px 0;
                border-left-width: 3px;
                border-radius: 0;
            }
            .header-actions {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .header-actions a {
                width: 100%;
                text-align: center;
            }
            .pagination {
                gap: 8px;
            }
            .pagination .btn {
                padding: 0.5rem 0.85rem;
                font-size: 0.85em;
                flex: 1 1 auto;
                text-align: center;
            }
            .page-info {
                width: 100%;
                text-align: center;
                order: -1;
                margin-bottom: 5px;
            }
            /* Stift-Icon auf Touch-Geräten immer anzeigen */
            .edit-icon {
                opacity: 0.6 !important;
            }
            /* category-input im Edit-Modus schmaler machen */
            .category-input {
                width: 110px;
            }
            /* Detailstabelle: Spalten kompakter */
            .details-table th,
            .details-table td {
                padding: 6px 8px;
            }
            /* Kategorie-Analyse-Tabelle kompakter */
            .category-share-table th,
            .category-share-table td {
                padding: 5px 6px;
                font-size: 0.8em;
            }
        }
        
        .category-share-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 4px;
            margin-top: 5px;
        }
        .category-share-table th {
            padding: 6px 10px;
            font-size: 0.8em;
            color: var(--text-muted);
            border-bottom: 2px solid var(--bg-surface-hover);
            background: transparent;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .category-share-table td {
            padding: 8px 10px;
            background: rgba(255, 255, 255, 0.02);
            border: none;
            font-size: 0.85em;
            transition: all 0.2s ease;
        }
        .category-share-table td:first-child {
            border-top-left-radius: 4px;
            border-bottom-left-radius: 4px;
        }
        .category-share-table td:last-child {
            border-top-right-radius: 4px;
            border-bottom-right-radius: 4px;
        }
        .category-color-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 8px;
            vertical-align: middle;
        }
        
        /* Interaktives Highlight & Glow */
        .js-category-row {
            cursor: pointer;
            transition: transform 0.15s ease;
        }
        .js-category-row:hover {
            transform: scale(1.01);
        }
        .js-category-row:hover td,
        .js-category-row.highlight td {
            background-color: var(--bg-surface-hover) !important;
            color: var(--text-main) !important;
            outline: 1px solid var(--category-color);
            box-shadow: 0 0 10px var(--category-color);
        }
        
        /* SVG Slices */
        .chart-slice {
            cursor: pointer;
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), filter 0.25s, stroke 0.2s, stroke-width 0.2s;
            transform-origin: 110px 110px;
        }

        /* Dynamisch gefärbte Kategorienlabels in den Einzelpositionen */
        .category-badge[style*="--category-color"] {
            background-color: color-mix(in srgb, var(--category-color) 15%, transparent) !important;
            color: var(--category-color) !important;
            border-color: var(--category-color) !important;
        }
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

<script src="../js/kassenbon.js?v=<?= time() ?>"></script>

</body>
</html>