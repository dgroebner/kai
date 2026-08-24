<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Kassenbon\CategoryAnalyzer;
use Kai\Tools\Kassenbon\ReceiptQueryRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// Auth-Check — muss als erstes stehen, bevor irgendwelche Logik läuft
Auth::requirePage();

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
    $items = (new ReceiptQueryRepository())->getItemsForPeriod($startDate, $endDate);
    
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
    http_response_code(500);
    exit("Interner Fehler. Bitte versuche es später erneut.");
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
        <div class="card empty-state">
            <p class="empty-state-text">Keine Kassenbon-Positionen für diesen Zeitraum gefunden.</p>
        </div>
    <?php else: ?>
        <div class="category-analysis-wrapper">
            <div class="category-table-container">
                <strong class="table-label-strong">Kategorien-Anteil:</strong>
                <table class="category-share-table">
                    <thead>
                        <tr>
                            <th>Kategorie</th>
                            <th class="text-right col-share">Anteil</th>
                            <th class="text-right col-total">Gesamt</th>
                        </tr>
                    </thead>
                    <tbody class="js-category-table-body">
                        <?php
                        $colorIndex = 0;
                        foreach ($analysis['categories'] as $cat => $data):
                            $color = $niceColors[$colorIndex % count($niceColors)];
                            $colorIndex++;
                        ?>
                            <tr class="js-category-row" data-category="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>" data-percentage="<?= number_format($data['percentage'], 4, '.', '') ?>" data-total="<?= number_format($data['total'], 4, '.', '') ?>" data-color="<?= $color ?>" style="--category-color: <?= $color ?>;">
                                <td>
                                    <span class="category-color-dot" style="background-color: <?= $color ?>;"></span>
                                    <span class="category-name"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td class="percentage-cell text-right amount-bold"><?= number_format($data['percentage'], 1, ',', '.') ?>%</td>
                                <td class="total-cell text-right"><?= number_format($data['total'], 2, ',', '.') ?> €</td>
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
            <table class="details-table stack-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Menge</th>
                        <th>Artikel</th>
                        <th class="col-category">Kategorie</th>
                        <th class="text-right">Einzelpreis</th>
                        <th class="text-right">Gesamt</th>
                    </tr>
                </thead>
                <tbody>
					<?php foreach ($items as $item): 
						$catName = $item['category'] ?? 'Sonstiges';
						$itemColor = $categoryColorMap[$catName] ?? '#64748b';
					?>
						<tr class="js-item-row" data-category="<?= htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?>">
							<td data-label="Datum"><?= date('d.m.Y', strtotime($item['purchase_date'])) ?></td>
							<td data-label="Menge"><?= number_format((float)$item['quantity'], 3, ',', '.') ?> x</td>
							<td data-label="Artikel" class="cell-strong">
							    <?= htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
							</td>
							<td data-label="Kategorie">
								<!-- Badge-Farbe dynamisch an die Map der Legende binden -->
								<span class="category-badge clickable-badge" style="color: <?= $itemColor ?>; border-color: <?= $itemColor ?>;">
									<?= htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?>
								</span>
							</td>
							<td data-label="Einzelpreis" class="text-right">
							    <?= number_format((float)$item['unit_price'], 2, ',', '.') ?> €
							</td>
							<td data-label="Gesamt" class="cell-amount">
							    <?= number_format((float)$item['total_price'], 2, ',', '.') ?> €
							</td>
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
