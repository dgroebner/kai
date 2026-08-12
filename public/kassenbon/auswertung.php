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

$categoryAnalyzer = new CategoryAnalyzer();
$logger = new Logger();

// ----------------------------------------------------
// Zeitraum-Berechnung (Woche, Monat, Jahr)
// ----------------------------------------------------
$type = $_GET['type'] ?? 'monat';
if (!in_array($type, ['woche', 'monat', 'jahr'])) {
    $type = 'monat';
}

$dateParam = $_GET['date'] ?? date('Y-m-d');
// Datumsformat validieren
$dateTime = DateTime::createFromFormat('Y-m-d', $dateParam);
if (!$dateTime) {
    $dateTime = new DateTime();
}
$refDate = $dateTime->format('Y-m-d');

if ($type === 'woche') {
    // ISO-8601 Woche geht von Montag bis Sonntag
    $dayOfWeek = (int)$dateTime->format('N'); // 1 = Montag, 7 = Sonntag
    $startDt = clone $dateTime;
    if ($dayOfWeek > 1) {
        $startDt->modify('-' . ($dayOfWeek - 1) . ' days');
    }
    $startDate = $startDt->format('Y-m-d 00:00:00');
    
    $endDt = clone $startDt;
    $endDt->modify('+6 days');
    $endDate = $endDt->format('Y-m-d 23:59:59');
    
    // Sprungdaten (vorherige Woche / nächste Woche)
    $prevDate = (clone $startDt)->modify('-7 days')->format('Y-m-d');
    $nextDate = (clone $startDt)->modify('+7 days')->format('Y-m-d');
    
    $periodLabel = "KW " . $startDt->format('W') . " (" . $startDt->format('d.m.Y') . " - " . $endDt->format('d.m.Y') . ")";
    $navLabelPrev = "Vorherige Woche";
    $navLabelNext = "Nächste Woche";
} elseif ($type === 'jahr') {
    $startDate = $dateTime->format('Y-01-01 00:00:00');
    $endDate = $dateTime->format('Y-12-31 23:59:59');
    
    $startDt = new DateTime($startDate);
    $endDt = new DateTime($endDate);
    
    // Sprungdaten (vorheriges Jahr / nächstes Jahr)
    $prevDate = (clone $startDt)->modify('-1 year')->format('Y-m-d');
    $nextDate = (clone $startDt)->modify('+1 year')->format('Y-m-d');
    
    $periodLabel = "Jahr " . $startDt->format('Y');
    $navLabelPrev = "Vorheriges Jahr";
    $navLabelNext = "Nächstes Jahr";
} else { // 'monat'
    $startDate = $dateTime->format('Y-m-01 00:00:00');
    $endDate = $dateTime->format('Y-m-t 23:59:59');
    
    $startDt = new DateTime($startDate);
    $endDt = new DateTime($endDate);
    
    // Sprungdaten (vorheriger Monat / nächster Monat)
    $prevDate = (clone $startDt)->modify('-1 month')->format('Y-m-d');
    $nextDate = (clone $startDt)->modify('+1 month')->format('Y-m-d');
    
    $germanMonths = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
        5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
    ];
    $monthNum = (int)$startDt->format('n');
    $periodLabel = $germanMonths[$monthNum] . " " . $startDt->format('Y');
    $navLabelPrev = "Vorheriger Monat";
    $navLabelNext = "Nächster Monat";
}

// ----------------------------------------------------
// DB-Abfrage & Analyse
// ----------------------------------------------------
$items = [];
$analysis = [];
$categoryColorMap = [];

try {
    $pdo = Database::getInstance()->getConnection();
    
    $stmtItems = $pdo->prepare("
        SELECT i.*, r.purchase_date 
        FROM kb_items i
        JOIN kb_receipts r ON i.receipt_id = r.id
        WHERE r.purchase_date BETWEEN :start AND :end
        ORDER BY r.purchase_date DESC, r.id DESC, i.id ASC
    ");
    $stmtItems->execute([
        ':start' => $startDate,
        ':end' => $endDate
    ]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    
    $analysis = $categoryAnalyzer->analyze($items);
    
    $niceColors = [
        '#3b82f6', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', 
        '#06b6d4', '#f97316', '#84cc16', '#a855f7', '#14b8a6', 
        '#ef4444', '#64748b'
    ];
    
    $colorIndex = 0;
    foreach ($analysis['categories'] as $cat => $data) {
        $categoryColorMap[$cat] = $niceColors[$colorIndex % count($niceColors)];
        $colorIndex++;
    }
    
} catch (\Throwable $e) {
    $logger->error("Kassenbon auswertung.php: Datenbankfehler.", ['error' => $e->getMessage()]);
    die("Interner Fehler. Bitte versuche es später erneut.");
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon-Auswertung</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>

<div class="container" id="auswertung-container">
    <!-- Standard-Header wie bei Kreditkarten -->
    <header class="page-header">
        <h1>📈 Bon-Auswertung</h1>
        <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
    </header>
    
    <!-- Schnelleinstellungen -->
    <div class="period-switcher">
        <a href="?type=woche&date=<?= htmlspecialchars($refDate) ?>" class="btn <?= $type === 'woche' ? '' : 'btn-outline' ?>">Woche</a>
        <a href="?type=monat&date=<?= htmlspecialchars($refDate) ?>" class="btn <?= $type === 'monat' ? '' : 'btn-outline' ?>">Monat</a>
        <a href="?type=jahr&date=<?= htmlspecialchars($refDate) ?>" class="btn <?= $type === 'jahr' ? '' : 'btn-outline' ?>">Jahr</a>
    </div>
    
    <!-- Zeitraum Navigation -->
    <div class="period-navigation">
        <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($prevDate) ?>" class="btn btn-outline">◀ <?= htmlspecialchars($navLabelPrev) ?></a>
        <div class="current-period-label">
            <?= htmlspecialchars($periodLabel) ?>
            <span class="period-range-sub">
                Auswertungszeitraum: <?= date('d.m.Y', strtotime($startDate)) ?> bis <?= date('d.m.Y', strtotime($endDate)) ?>
            </span>
        </div>
        <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($nextDate) ?>" class="btn btn-outline"><?= htmlspecialchars($navLabelNext) ?> ▶</a>
    </div>
    
    <?php if (empty($items)): ?>
        <div class="card" style="text-align: center; padding: 3rem 1.5rem;">
            <p style="font-size: 1.1rem; color: var(--text-muted); margin-bottom: 0;">Keine Kassenbon-Positionen für diesen Zeitraum gefunden.</p>
        </div>
    <?php else: ?>
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
            
            <div class="category-chart-container js-chart-container" data-grand-total="<?= $analysis['grand_total'] ?>">
                <!-- Interactive SVG will be injected/updated by JS -->
            </div>
        </div>
        
        <h2>Einzelpositionen</h2>
        <div class="table-responsive">
            <table class="details-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Menge</th>
                        <th>Artikel</th>
                        <th style="min-width: 180px;">Kategorie</th>
                        <th style="text-align: right;">Einzelpreis</th>
                        <th style="text-align: right;">Gesamt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): 
                        $itemColor = $categoryColorMap[$item['category']] ?? '#64748b';
                    ?>
                        <tr class="js-item-row" data-category="<?= htmlspecialchars($item['category']) ?>">
                            <td><?= date('d.m.Y', strtotime($item['purchase_date'])) ?></td>
                            <td><?= number_format($item['quantity'], 3, ',', '.') ?> x</td>
                            <td style="color: var(--text-main); font-weight: 500;"><?= htmlspecialchars($item['name']) ?></td>
                            <td>
                                <span class="category-badge js-cat-label" style="--category-color: <?= $itemColor ?>;"><?= htmlspecialchars($item['category']) ?></span>
                            </td>
                            <td style="text-align: right;"><?= number_format($item['unit_price'], 2, ',', '.') ?> €</td>
                            <td style="text-align: right; font-weight: bold; color: var(--text-main);"><?= number_format($item['total_price'], 2, ',', '.') ?> €</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="pagination" id="items-pagination">
            <!-- Dynamic pagination buttons will be injected by JavaScript -->
        </div>
    <?php endif; ?>
</div>

<script src="../js/auswertung.js?v=<?= APP_VERSION ?>" defer></script>

</body>
</html>
