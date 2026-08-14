<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// 1. Auth-Check — immer zuerst (AGENTS.md)
Auth::requirePage();

$logger = new Logger();

// ----------------------------------------------------
// 2. Zeitraum-Berechnung (Woche, Monat, Jahr)
// ----------------------------------------------------
$type = $_GET['type'] ?? 'monat';
if (!in_array($type, ['woche', 'monat', 'jahr'])) {
    $type = 'monat';
}

$dateParam = $_GET['date'] ?? date('Y-m-d');
$dateTime = DateTime::createFromFormat('Y-m-d', $dateParam);
if (!$dateTime) {
    $dateTime = new DateTime();
}
$refDate = $dateTime->format('Y-m-d');

if ($type === 'woche') {
    $dayOfWeek = (int)$dateTime->format('N');
    $startDt = clone $dateTime;
    if ($dayOfWeek > 1) {
        $startDt->modify('-' . ($dayOfWeek - 1) . ' days');
    }
    $startDate = $startDt->format('Y-m-d');
    
    $endDt = clone $startDt;
    $endDt->modify('+6 days');
    $endDate = $endDt->format('Y-m-d');
    
    $prevDate = (clone $startDt)->modify('-7 days')->format('Y-m-d');
    $nextDate = (clone $startDt)->modify('+7 days')->format('Y-m-d');
    
    $periodLabel = "KW " . $startDt->format('W') . " (" . $startDt->format('d.m.Y') . " - " . $endDt->format('d.m.Y') . ")";
    $navLabelPrev = "Vorherige Woche";
    $navLabelNext = "Nächste Woche";
} elseif ($type === 'jahr') {
    $startDate = $dateTime->format('Y-01-01');
    $endDate = $dateTime->format('Y-12-31');
    
    $startDt = new DateTime($startDate);
    $endDt = new DateTime($endDate);
    
    $prevDate = (clone $startDt)->modify('-1 year')->format('Y-m-d');
    $nextDate = (clone $startDt)->modify('+1 year')->format('Y-m-d');
    
    $periodLabel = "Jahr " . $startDt->format('Y');
    $navLabelPrev = "Vorheriges Jahr";
    $navLabelNext = "Nächstes Jahr";
} else { // 'monat'
    $startDate = $dateTime->format('Y-01-01');
    $endDate = $dateTime->format('Y-12-31');
    
    // Für Monatsansicht: Erster bis letzter Tag des Monats
    $startDate = $dateTime->format('Y-m-01');
    $endDate = $dateTime->format('Y-m-t');
    
    $startDt = new DateTime($startDate);
    $endDt = new DateTime($endDate);
    
    $prevDate = (clone $startDt)->modify('-1 month')->format('Y-m-d');
    $nextDate = (clone $startDt)->modify('+1 month')->format('Y-m-d');
    
    $germanMonths = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
        5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
    ];
    $periodLabel = $germanMonths[(int)$startDt->format('n')] . " " . $startDt->format('Y');
    $navLabelPrev = "Vorheriger Monat";
    $navLabelNext = "Nächster Monat";
}

// ----------------------------------------------------
// 3. Paginierung & Filter-Parameter
// ----------------------------------------------------
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;
$selectedTagId = isset($_GET['tag_id']) ? (int)$_GET['tag_id'] : null;

$transactions = [];
$availableTags = [];
$tagStats = [];
$totalPages = 1;
$totalTransactions = 0;

try {
    $pdo = Database::getInstance()->getConnection();

    // Alle vorhandenen Tags für Popover & Auto-Suggest laden
    $stmtAllTags = $pdo->query("SELECT id, name, color FROM bank_tags ORDER BY name ASC");
    $availableTags = $stmtAllTags->fetchAll(PDO::FETCH_ASSOC);

    // Tag-Statistik für den Zeitraum ermitteln (Summen & Häufigkeit)
    $stmtStats = $pdo->prepare("
        SELECT 
            t.id, t.name, t.color,
            COUNT(DISTINCT bt.id) AS tx_count,
            SUM(bt.amount) AS total_amount
        FROM bank_tags t
        JOIN bank_transaction_tags tt ON t.id = tt.tag_id
        JOIN bank_giro_transactions bt ON tt.transaction_id = bt.id
        WHERE bt.booking_date BETWEEN :start AND :end
        GROUP BY t.id, t.name, t.color
        ORDER BY tx_count DESC, t.name ASC
    ");
    $stmtStats->execute([':start' => $startDate, ':end' => $endDate]);
    $tagStats = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

    // SQL-Filter vorbereiten
    $whereClause = "WHERE bt.booking_date BETWEEN :start AND :end";
    $params = [':start' => $startDate, ':end' => $endDate];

    if ($selectedTagId) {
        $whereClause .= " AND bt.id IN (SELECT transaction_id FROM bank_transaction_tags WHERE tag_id = :tag_id)";
        $params[':tag_id'] = $selectedTagId;
    }

    // Gesamtzahl für Paginierung
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM bank_giro_transactions bt {$whereClause}");
    $stmtCount->execute($params);
    $totalTransactions = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalTransactions / $limit));

    // Umsätze mit zugewiesenen Tags & Regel-Informationen laden
    $stmtTx = $pdo->prepare("
        SELECT 
            bt.*,
            r.text_pattern AS matched_text_pattern,
            GROUP_CONCAT(CONCAT(t.id, ':', t.name, ':', t.color) SEPARATOR '||') AS tag_data
        FROM bank_giro_transactions bt
        LEFT JOIN bank_tag_rules r ON bt.matched_rule_id = r.id
        LEFT JOIN bank_transaction_tags tt ON bt.id = tt.transaction_id
        LEFT JOIN bank_tags t ON tt.tag_id = t.id
        {$whereClause}
        GROUP BY bt.id
        ORDER BY bt.booking_date DESC, bt.id DESC
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $val) {
        $stmtTx->bindValue($key, $val);
    }
    $stmtTx->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtTx->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtTx->execute();
    
    $rawTxs = $stmtTx->fetchAll(PDO::FETCH_ASSOC);
	

    // Tags pro Transaktion strukturiert aufbereiten
    foreach ($rawTxs as $row) {
        $tags = [];
        if (!empty($row['tag_data'])) {
            $tagParts = explode('||', $row['tag_data']);
            foreach ($tagParts as $part) {
                list($tId, $tName, $tColor) = explode(':', $part);
                $tags[] = [
                    'id'    => (int)$tId,
                    'name'  => $tName,
                    'color' => $tColor ?: '#3b82f6'
                ];
            }
        }
        $row['tags'] = $tags;
        $transactions[] = $row;
    }
	
	// Echte Gesamtsummen direkt aus den Transaktionen ermitteln (ohne Tag-Doppelzählungen)
    $stmtPeriodTotals = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) AS total_expenses,
            SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS total_income
        FROM bank_giro_transactions
        WHERE booking_date BETWEEN :start AND :end
    ");
    $stmtPeriodTotals->execute([':start' => $startDate, ':end' => $endDate]);
    $periodTotals = $stmtPeriodTotals->fetch(PDO::FETCH_ASSOC);

    $realTotalExpenses = abs((float)($periodTotals['total_expenses'] ?? 0));
    $realTotalIncome   = (float)($periodTotals['total_income'] ?? 0);

} catch (\Throwable $e) {
    $logger->error("bank/index.php: Fehler beim Laden der Umsätze.", ['error' => $e->getMessage()]);
    http_response_code(500);
    exit("Interner Fehler. Bitte versuche es später erneut.");
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Girokonto Umsätze – Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="container" id="giro-container" data-tags='<?= htmlspecialchars(json_encode($availableTags, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'>

    <header class="page-header">
        <h1>🏦 Girokonto Umsätze</h1>
        <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
    </header>

    <!-- Tab-Switcher (Girokonto / Kreditkarte) -->
    <div class="period-switcher" style="justify-content: flex-start; margin-bottom: 1.5rem;">
        <a href="index.php" class="btn">🏦 Girokonto</a>
        <a href="creditcard.php" class="btn btn-outline">💳 Kreditkarte</a>
    </div>

    <!-- Schnelleinstellungen (Typ) -->
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

    <!-- Visuelle Tag-Übersicht & Verteilung (Getrennt nach Ausgaben & Einnahmen) -->
    <?php if (!empty($tagStats)): ?>
        <?php
            // In Ausgaben und Einnahmen aufteilen
            $expensesStats = array_filter($tagStats, fn($s) => (float)$s['total_amount'] < 0);
            $incomeStats   = array_filter($tagStats, fn($s) => (float)$s['total_amount'] > 0);

            $prepareGroup = function(array $group) {
                $totalAbs = 0;
                $maxAbs = 0;
                foreach ($group as &$s) {
                    $s['abs_amount'] = abs((float)$s['total_amount']);
                    $totalAbs += $s['abs_amount'];
                    if ($s['abs_amount'] > $maxAbs) {
                        $maxAbs = $s['abs_amount'];
                    }
                }
                unset($s);
                usort($group, fn($a, $b) => $b['abs_amount'] <=> $a['abs_amount']);
                return [$group, $totalAbs, $maxAbs];
            };

            list($expensesStats, $expTotalAbs, $expMaxAbs) = $prepareGroup($expensesStats);
            list($incomeStats, $incTotalAbs, $incMaxAbs)   = $prepareGroup($incomeStats);
        ?>
        
        <section class="card" style="margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <div>
                    <strong class="table-label-strong" style="margin-bottom: 0; font-size: 1.05rem;">📊 Übersicht im Zeitraum:</strong>
                    <span style="font-size: 0.85rem; color: var(--text-muted); margin-left: 0.5rem;">
                        Tatsächliche Ausgaben: <strong class="text-danger">-<?= number_format($realTotalExpenses, 2, ',', '.') ?> €</strong> | 
                        Einnahmen: <strong class="text-success">+<?= number_format($realTotalIncome, 2, ',', '.') ?> €</strong>
                    </span>
                </div>
                <button type="button" id="btn-reset-tag-filter" class="btn btn-outline hidden" style="padding: 0.2rem 0.6rem; font-size: 0.8rem;">
                    ✖ Filter aufheben
                </button>
            </div>

            <!-- A: AUSGABEN -->
            <?php if (!empty($expensesStats)): ?>
                <div style="margin-bottom: 1.5rem;">
                    <div style="font-size: 0.85rem; font-weight: 600; color: var(--color-red); margin-bottom: 0.4rem; display: flex; justify-content: space-between; align-items: center;">
                        <span>🔴 Ausgaben – Verteilung nach Kategorien</span>
                        <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;">(Relative Gewichtung der Kategorien)</span>
                    </div>
                    
                    <div class="tag-distribution-bar" id="exp-distribution-bar" style="display: flex; height: 8px; border-radius: 4px; overflow: hidden; background: var(--bg-surface-hover); margin-bottom: 0.75rem;">
                        <?php foreach ($expensesStats as $stat): 
                            $pct = $expTotalAbs > 0 ? ($stat['abs_amount'] / $expTotalAbs) * 100 : 0;
                            if ($pct <= 0) continue;
                        ?>
                            <div class="tag-bar-segment" 
                                 style="width: <?= number_format($pct, 2, '.', '') ?>%; background-color: <?= $stat['color'] ?>;" 
                                 title="<?= htmlspecialchars($stat['name']) ?>: <?= number_format($pct, 1, ',', '.') ?>% (<?= number_format($stat['abs_amount'], 2, ',', '.') ?> €)">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="exp-tags-grid" class="tag-stats-grid">
                        <?php foreach ($expensesStats as $stat): ?>
                            <?php 
                                $isActive = $selectedTagId === (int)$stat['id']; 
                                $formattedAmt = number_format($stat['abs_amount'], 2, ',', '.') . ' €';
                                $fillPct = $expMaxAbs > 0 ? ($stat['abs_amount'] / $expMaxAbs) * 100 : 0;
                            ?>
                            <div class="tag-stat-card js-filter-tag-card <?= $isActive ? 'active' : '' ?>" 
                                 data-filter-tag-id="<?= $stat['id'] ?>"
                                 style="--tag-color: <?= $stat['color'] ?>; cursor: pointer;">
                               <div class="tag-stat-bg-bar" style="width: <?= number_format($fillPct, 1, '.', '') ?>%;"></div>
                               <div class="tag-stat-content">
                                   <div class="tag-stat-header">
                                       <span class="tag-color-dot" style="background-color: <?= $stat['color'] ?>;"></span>
                                       <span class="tag-stat-name"><?= htmlspecialchars($stat['name']) ?></span>
                                   </div>
                                   <div class="tag-stat-metrics">
                                       <span class="tag-stat-amount text-danger">-<?= $formattedAmt ?></span>
                                       <span class="tag-stat-count"><?= $stat['tx_count'] ?> Buchung<?= $stat['tx_count'] === 1 ? '' : 'en' ?></span>
                                   </div>
                               </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- B: EINNAHMEN -->
            <?php if (!empty($incomeStats)): ?>
                <div>
                    <div style="font-size: 0.85rem; font-weight: 600; color: var(--color-green); margin-bottom: 0.4rem; display: flex; justify-content: space-between; align-items: center;">
                        <span>🟢 Einnahmen – Verteilung nach Kategorien</span>
                        <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;">(Relative Gewichtung der Kategorien)</span>
                    </div>
                    
                    <div class="tag-distribution-bar" id="inc-distribution-bar" style="display: flex; height: 8px; border-radius: 4px; overflow: hidden; background: var(--bg-surface-hover); margin-bottom: 0.75rem;">
                        <?php foreach ($incomeStats as $stat): 
                            $pct = $incTotalAbs > 0 ? ($stat['abs_amount'] / $incTotalAbs) * 100 : 0;
                            if ($pct <= 0) continue;
                        ?>
                            <div class="tag-bar-segment" 
                                 style="width: <?= number_format($pct, 2, '.', '') ?>%; background-color: <?= $stat['color'] ?>;" 
                                 title="<?= htmlspecialchars($stat['name']) ?>: <?= number_format($pct, 1, ',', '.') ?>% (<?= number_format($stat['abs_amount'], 2, ',', '.') ?> €)">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="inc-tags-grid" class="tag-stats-grid">
                        <?php foreach ($incomeStats as $stat): ?>
                            <?php 
                                $isActive = $selectedTagId === (int)$stat['id']; 
                                $formattedAmt = number_format($stat['abs_amount'], 2, ',', '.') . ' €';
                                $fillPct = $incMaxAbs > 0 ? ($stat['abs_amount'] / $incMaxAbs) * 100 : 0;
                            ?>
                            <div class="tag-stat-card js-filter-tag-card <?= $isActive ? 'active' : '' ?>" 
                                 data-filter-tag-id="<?= $stat['id'] ?>"
                                 style="--tag-color: <?= $stat['color'] ?>; cursor: pointer;">
                               <div class="tag-stat-bg-bar" style="width: <?= number_format($fillPct, 1, '.', '') ?>%;"></div>
                               <div class="tag-stat-content">
                                   <div class="tag-stat-header">
                                       <span class="tag-color-dot" style="background-color: <?= $stat['color'] ?>;"></span>
                                       <span class="tag-stat-name"><?= htmlspecialchars($stat['name']) ?></span>
                                   </div>
                                   <div class="tag-stat-metrics">
                                       <span class="tag-stat-amount text-success">+<?= $formattedAmt ?></span>
                                       <span class="tag-stat-count"><?= $stat['tx_count'] ?> Buchung<?= $stat['tx_count'] === 1 ? '' : 'en' ?></span>
                                   </div>
                               </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Transaktions-Tabelle -->
    <main>
        <section class="card">
            <?php if (empty($transactions)): ?>
                <p class="text-center text-muted" style="padding: 2rem 0;">Keine Umsätze für diesen Zeitraum vorhanden.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="stack-table">
                        <thead>
                            <tr>
                                <th style="width: 110px;">Datum</th>
                                <th>Buchungstext</th>
                                <th>Tags & Regel</th>
                                <th class="text-right" style="width: 130px;">Betrag</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $tx): ?>
                                <tr data-tx-id="<?= $tx['id'] ?>" data-amount="<?= $tx['amount'] ?>">
                                    <td data-label="Datum">
                                        <?= date('d.m.Y', strtotime($tx['booking_date'])) ?>
                                    </td>
                                    <td data-label="Text">
                                        <strong style="display:block;"><?= htmlspecialchars($tx['type'] ?? 'Buchung', ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span class="text-muted" style="font-size: 0.85rem;">
                                            <?= htmlspecialchars($tx['merchant_raw'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td data-label="Tags">
                                        <div class="tag-pill-group js-tag-group" data-tx-id="<?= $tx['id'] ?>">
                                            <!-- Regel-Indikator (Zauberstab / Blitz) -->
                                            <?php if (!empty($tx['matched_rule_id'])): ?>
                                                <button type="button" 
                                                        class="btn-rule-indicator active js-open-rule-builder" 
                                                        data-tx-id="<?= $tx['id'] ?>" 
                                                        data-rule-id="<?= $tx['matched_rule_id'] ?>"
                                                        data-merchant-raw="<?= htmlspecialchars($tx['merchant_raw'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-text-pattern="<?= htmlspecialchars($tx['matched_text_pattern'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                        title="Regel aktiv: <?= htmlspecialchars($tx['matched_text_pattern'] ?? '', ENT_QUOTES, 'UTF-8') ?> (Klicken zum Bearbeiten)">
                                                    ⚡
                                                </button>
                                            <?php else: ?>
                                                <button type="button" 
                                                        class="btn-rule-indicator js-open-rule-builder" 
                                                        data-tx-id="<?= $tx['id'] ?>" 
                                                        data-merchant-raw="<?= htmlspecialchars($tx['merchant_raw'], ENT_QUOTES, 'UTF-8') ?>"
                                                        title="Keine Regel verknüpft (Klicken zum Erstellen)">
                                                    🪄
                                                </button>
                                            <?php endif; ?>

                                            <!-- Tags der Transaktion -->
                                            <?php foreach ($tx['tags'] as $tag): ?>
                                                <span class="badge tag-badge clickable-tag" 
                                                      data-tag-id="<?= $tag['id'] ?>"
                                                      style="color: <?= htmlspecialchars($tag['color']) ?>; border-color: <?= htmlspecialchars($tag['color']) ?>;">
                                                    <?= htmlspecialchars($tag['name']) ?>
                                                    <span class="remove-tag-btn" data-tx-id="<?= $tx['id'] ?>" data-tag-id="<?= $tag['id'] ?>">&times;</span>
                                                </span>
                                            <?php endforeach; ?>

                                            <button type="button" class="btn-add-tag js-open-tag-popover" data-tx-id="<?= $tx['id'] ?>" title="Tag manuell hinzufügen">+</button>
                                        </div>
                                    </td>
                                    <td data-label="Betrag" class="text-right amount-bold <?= $tx['amount'] < 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= number_format((float)$tx['amount'], 2, ',', '.') ?> €
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Paginierung -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination" style="margin-top: 1.5rem;">
                        <?php if ($page > 1): ?>
                            <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>&page=<?= $page - 1 ?><?= $selectedTagId ? '&tag_id='.$selectedTagId : '' ?>" class="btn btn-outline">&laquo; Vorherige</a>
                        <?php else: ?>
                            <span class="btn btn-outline disabled">&laquo; Vorherige</span>
                        <?php endif; ?>

                        <span class="page-info">Seite <?= $page ?> von <?= $totalPages ?></span>

                        <?php if ($page < $totalPages): ?>
                            <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>&page=<?= $page + 1 ?><?= $selectedTagId ? '&tag_id='.$selectedTagId : '' ?>" class="btn btn-outline">Nächste &raquo;</a>
                        <?php else: ?>
                            <span class="btn btn-outline disabled">Nächste &raquo;</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>

</div>

<script src="../js/http.js?v=<?= APP_VERSION ?>" defer></script>
<script src="../js/bank.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>