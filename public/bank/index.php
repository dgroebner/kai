<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Bank\BankAccountRepository;
use Kai\Tools\Bank\BankTagRepository;
use Kai\Tools\Bank\GiroOverviewRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\Shared\Security\Sanitizer;

// 1. Auth-Check — immer zuerst (AGENTS.md)
Auth::requirePage();

$logger = new Logger();

// Anzahl der Umsätze pro Seite (auch für die Sprungberechnung per ?tx=ID)
const TRANSACTIONS_PER_PAGE = 25;

$accountRepository = new BankAccountRepository();
$giroRepository = new GiroOverviewRepository();
$tagRepository = new BankTagRepository();

// Girokonto dynamisch auflösen — alle Abfragen dieser Seite beziehen sich darauf
$checkingAccount = $accountRepository->getAccountByType('checking');
if ($checkingAccount === null) {
    $logger->error('bank/index.php: Kein aktives Girokonto gefunden.');
    http_response_code(500);
    exit("Kein Girokonto konfiguriert.");
}
$accountId = (int)$checkingAccount['id'];

// ----------------------------------------------------
// 1.5 Direkter Transaktions-Sprung per ?tx=ID & Seitenberechnung
// ----------------------------------------------------
$highlightTxId = isset($_GET['tx']) ? (int)$_GET['tx'] : null;
if ($highlightTxId && !isset($_GET['page'])) {
    try {
        // Datum der Transaktion holen
        $txBookingDate = $giroRepository->getBookingDate($highlightTxId);

        if ($txBookingDate) {
            $type = 'monat';
            $dateParam = $txBookingDate;

            // Monat-Start und Ende für den Zähl-Query bestimmen
            $dateTime = DateTime::createFromFormat('Y-m-d', $txBookingDate);
            $startDate = $dateTime->format('Y-m-01');
            $endDate = $dateTime->format('Y-m-t');

            // Ermitteln, wie viele Transaktionen in diesem Monat *nach* unserer Ziel-Transaktion liegen (für den Offset/Seitenindex)
            $position = $giroRepository->countTransactionsBefore(
                    $accountId,
                    $startDate,
                    $endDate,
                    $txBookingDate,
                    $highlightTxId
            );

            // Berechnen, auf welcher Seite sich die Transaktion befindet
            $targetPage = floor($position / TRANSACTIONS_PER_PAGE) + 1;

            if ($targetPage > 1) {
                // Auf die korrekte Seite weiterleiten
                header("Location: index.php?type=monat&date={$dateParam}&page={$targetPage}&tx={$highlightTxId}#tx-{$highlightTxId}");
                exit;
            }
        }
    } catch (Throwable $e) {
        $logger->error("Bank->index.php - Fehler bei Einstiegspunktberechnung!", ['error' => $e->getMessage()]);
    }
}

// ----------------------------------------------------
// 2. Zeitraum-Berechnung (Woche, Monat, Jahr)
// ----------------------------------------------------
if (!isset($type)) {
    $type = $_GET['type'] ?? 'monat';
}
if (!in_array($type, ['woche', 'monat', 'jahr'])) {
    $type = 'monat';
}

if (!isset($dateParam)) {
    $dateParam = $_GET['date'] ?? date('Y-m-d');
}
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
$limit = TRANSACTIONS_PER_PAGE;
$offset = ($page - 1) * $limit;
$selectedTagId = isset($_GET['tag_id']) ? (int)$_GET['tag_id'] : null;

$transactions = [];
$availableTags = [];
$tagStats = [];
$totalPages = 1;
$totalTransactions = 0;

try {
    // Konten für die dynamische Navigation laden
    $accounts = $accountRepository->getAllAccounts();

    // Salden aus dem bereits geladenen $accounts-Array filtern
    $checkingBalance = 0;
    $savingsBalance = 0;
    foreach ($accounts as $acc) {
        if ($acc['account_type'] === 'checking') {
            $checkingBalance = $acc['current_balance'];
        } elseif ($acc['account_type'] === 'savings') {
            $savingsBalance = $acc['current_balance'];
        }
    }

    // Alle vorhandenen Tags für Popover & Auto-Suggest laden
    $availableTags = $tagRepository->getAllTags();

    // Tag-Statistik für den Zeitraum ermitteln (Summen & Häufigkeit)
    $tagStats = $tagRepository->getTagStatistics($accountId, $startDate, $endDate);

    // Gesamtzahl für Paginierung
    $totalTransactions = $giroRepository->countTransactions($accountId, $startDate, $endDate, $selectedTagId);
    $totalPages = max(1, (int)ceil($totalTransactions / $limit));

    // Umsätze mit zugewiesenen Tags, Regel-Informationen, Kreditkartenabrechnung & E-Bons laden
    $transactions = $giroRepository->getTransactions(
            $accountId,
            $startDate,
            $endDate,
            $selectedTagId,
            $limit,
            $offset
    );

    // Echte Gesamtsummen direkt aus den Transaktionen ermitteln (ohne Tag-Doppelzählungen)
    $periodTotals = $giroRepository->getPeriodTotals($accountId, $startDate, $endDate);
    $realTotalExpenses = $periodTotals['expenses'];
    $realTotalIncome = $periodTotals['income'];

    // Zeitpunkt der letzten Kontoaktualisierung
    $accountLastUpdate = $accountRepository->getUpdatedAt($accountId);

} catch (Throwable $e) {
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
    <meta name="csrf-token" content="<?= Auth::csrfToken() ?>">
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="container" id="giro-container"
     data-tags='<?= htmlspecialchars(json_encode($availableTags, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'>

    <header class="page-header">
        <h1>🏦 Girokonto Umsätze</h1>
        <div class="page-header-actions">
            <?php if ($accountLastUpdate): ?>
                <span class="last-update">Zuletzt aktualisiert: <?= htmlspecialchars(date('d.m.Y H:i', strtotime($accountLastUpdate)), ENT_QUOTES, 'UTF-8') ?> Uhr</span>
            <?php endif; ?>
            <button type="button" id="btn-open-sync" class="btn btn-blue">
                🔄 API Sync
            </button>
            <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
        </div>
    </header>

    <!-- START: Sync Modal Overlay -->
    <div id="sync-modal" class="rule-modal-overlay hidden">
        <div class="rule-modal-card" style="max-width: 450px;">
            <div class="rule-modal-header">
                <h3>🔄 Comdirect API Sync</h3>
                <button type="button" class="rule-modal-close" id="btn-cancel-sync-x">&times;</button>
            </div>

            <div class="rule-modal-body">
                <!-- Schritt 1: Zugangsdaten (wird nur eingeblendet, wenn Token abgelaufen/leer sind) -->
                <div id="sync-step-credentials" class="hidden">
                    <p class="subtitle" style="margin-bottom: 1rem;">
                        Die API-Zugangsdaten sind abgelaufen oder nicht vorhanden. Bitte gib deine Comdirect
                        Zugangsdaten ein, um neue Tokens anzufordern.
                    </p>

                    <div style="margin-bottom: 0.8rem;">
                        <label class="chart-label" style="margin-bottom: 0.3rem;">Zugangsnummer:</label>
                        <input type="text" id="sync-access-id" class="tag-search-input" placeholder="Zugangsnummer"
                               style="font-size: 0.95rem; padding: 0.6rem 0.8rem;">
                    </div>

                    <div style="margin-bottom: 1.2rem;">
                        <label class="chart-label" style="margin-bottom: 0.3rem;">PIN / Passwort:</label>
                        <input type="password" id="sync-pin-input" class="tag-search-input" placeholder="Banking PIN"
                               style="font-size: 0.95rem; padding: 0.6rem 0.8rem;">
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                        <button type="button" id="btn-cancel-sync" class="btn btn-outline">Abbrechen</button>
                        <button type="button" id="btn-submit-credentials" class="btn">Token anfordern & Sync starten
                        </button>
                    </div>
                </div>

                <!-- Schritt 1b: photoTAN Freigabe (Achtung vor Sperrung) -->
                <div id="sync-step-phototan" class="hidden">
                    <p class="subtitle"
                       style="margin-bottom: 1rem; color: var(--color-yellow, #eab308); font-weight: bold;">
                        ⚠️ WICHTIGER HINWEIS ZUR KONTOSPERRUNG
                    </p>
                    <p style="margin-bottom: 1rem; font-size: 0.9rem; line-height: 1.4; color: var(--text-main);">
                        Bitte bestätige die photoTAN in der comdirect photoTAN-App auf deinem Smartphone.<br><br>
                        <strong>Achtung: Nach 2 Fehlversuchen darf kein dritter Versuch erfolgen!</strong><br>
                        Sollte die TAN-Bestätigung fehlschlagen, melde dich bitte zuerst auf der comdirect-Webseite an
                        und führe dort eine erfolgreiche photoTAN-Bestätigung durch. Erst danach darfst du es hier
                        erneut versuchen.
                    </p>

                    <div id="phototan-lock-container" class="hidden"
                         style="margin-bottom: 1rem; padding: 0.75rem; border: 1px solid var(--color-red, #ef4444); border-radius: 4px; background-color: rgba(239, 68, 68, 0.05);">
                        <p style="color: var(--color-red, #ef4444); font-size: 0.85rem; margin-bottom: 0.5rem; font-weight: bold;">
                            🔒 ZUGANGSMETHODE GESPERRT (2 Fehlversuche)
                        </p>
                        <div style="display: flex; align-items: flex-start; gap: 0.5rem;">
                            <input type="checkbox" id="sync-reset-lock-chk"
                                   style="margin-top: 0.2rem; cursor: pointer;">
                            <label for="sync-reset-lock-chk"
                                   style="font-size: 0.8rem; cursor: pointer; color: var(--text-main);">
                                Ich habe mich auf der comdirect-Webseite erfolgreich angemeldet und die Sperre dort
                                aufgehoben / bestätigt.
                            </label>
                        </div>
                    </div>

                    <div style="margin-bottom: 1.2rem; display: flex; align-items: flex-start; gap: 0.5rem;">
                        <input type="checkbox" id="sync-phototan-confirm" style="margin-top: 0.2rem; cursor: pointer;">
                        <label for="sync-phototan-confirm"
                               style="font-size: 0.85rem; cursor: pointer; color: var(--text-main); user-select: none;">
                            Ich habe den Hinweis verstanden und die photoTAN in der App freigegeben.
                        </label>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                        <button type="button" id="btn-cancel-phototan-btn" class="btn btn-outline">Abbrechen</button>
                        <button type="button" id="btn-submit-phototan" class="btn" disabled>Sync fortsetzen</button>
                    </div>
                </div>

                <!-- Schritt 2: Fortschrittsanzeige -->
                <div id="sync-step-progress" class="hidden">
                    <ul style="list-style: none; padding: 0; margin: 0 0 1.5rem 0; font-size: 0.95rem; line-height: 2.2;">
                        <li id="task-auth" style="color: var(--text-muted);">⏳ Authentifizierung &
                            Token-Validierung...
                        </li>
                        <li id="task-balance" style="color: var(--text-muted);">⏳ Konten- & Salden-Abgleich...</li>
                        <li id="task-tx" style="color: var(--text-muted);">⏳ Transaktions-Import...</li>
                        <li id="task-rules" style="color: var(--text-muted);">⏳ KI-Regeln & Kategorisierung...</li>
                    </ul>
                    <div id="sync-result-msg" style="margin-bottom: 1rem; font-weight: bold; min-height: 1.5rem;"></div>
                    <button type="button" id="btn-close-sync" class="btn hidden" style="width: 100%;">Schließen & Neu
                        laden
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- ENDE: Sync Modal Overlay -->

    <!-- Tab-Switcher (Girokonto / Kreditkarte) -->
    <div class="period-switcher" style="justify-content: flex-start; margin-bottom: 1.5rem;">
        <a href="index.php" class="btn">🏦 Girokonto</a>
        <a href="creditcard.php" class="btn btn-outline">💳 Kreditkarte</a>
        <a href="contracts.php" class="btn btn-outline">📑 Verträge</a>
    </div>

    <!-- Salden-Dashboard -->
    <section class="kpi-grid" style="margin-bottom: 1.5rem;">
        <div class="kpi-card" style="border: 1px solid var(--accent);">
            <div class="kpi-label">🏦 Girokonto</div>
            <div class="kpi-value" style="font-size: 1.5rem;">
                <?= number_format((float)$checkingBalance, 2, ',', '.') ?> €
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">💰 Sparkonto</div>
            <div class="kpi-value" style="font-size: 1.5rem; color: var(--color-green);">
                <?= number_format((float)$savingsBalance, 2, ',', '.') ?> €
            </div>
        </div>
    </section>

    <!-- Schnelleinstellungen (Typ) -->
    <div class="period-switcher">
        <a href="?type=woche&date=<?= htmlspecialchars($refDate) ?>"
           class="btn <?= $type === 'woche' ? '' : 'btn-outline' ?>">Woche</a>
        <a href="?type=monat&date=<?= htmlspecialchars($refDate) ?>"
           class="btn <?= $type === 'monat' ? '' : 'btn-outline' ?>">Monat</a>
        <a href="?type=jahr&date=<?= htmlspecialchars($refDate) ?>"
           class="btn <?= $type === 'jahr' ? '' : 'btn-outline' ?>">Jahr</a>
    </div>

    <!-- Zeitraum Navigation -->
    <div class="period-navigation">
        <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($prevDate) ?>"
           class="btn btn-outline">◀ <?= htmlspecialchars($navLabelPrev) ?></a>
        <div class="current-period-label">
            <?= htmlspecialchars($periodLabel) ?>
            <span class="period-range-sub">
                Auswertungszeitraum: <?= date('d.m.Y', strtotime($startDate)) ?> bis <?= date('d.m.Y', strtotime($endDate)) ?>
            </span>
        </div>
        <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($nextDate) ?>"
           class="btn btn-outline"><?= htmlspecialchars($navLabelNext) ?> ▶</a>
    </div>

    <!-- Visuelle Tag-Übersicht & Verteilung (Getrennt nach Ausgaben & Einnahmen) -->
    <?php if (!empty($tagStats)): ?>
        <?php
        // In Ausgaben und Einnahmen aufteilen
        $expensesStats = array_filter($tagStats, fn($s) => (float)$s['total_amount'] < 0);
        $incomeStats = array_filter($tagStats, fn($s) => (float)$s['total_amount'] > 0);

        $prepareGroup = function (array $group) {
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
        list($incomeStats, $incTotalAbs, $incMaxAbs) = $prepareGroup($incomeStats);
        ?>

        <section class="card" style="margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <div>
                    <strong class="table-label-strong" style="margin-bottom: 0; font-size: 1.05rem;">📊 Übersicht im
                        Zeitraum:</strong>
                    <span style="font-size: 0.85rem; color: var(--text-muted); margin-left: 0.5rem;">
                        Tatsächliche Ausgaben: <strong
                                class="text-danger">-<?= number_format($realTotalExpenses, 2, ',', '.') ?> €</strong> |
                        Einnahmen: <strong
                                class="text-success">+<?= number_format($realTotalIncome, 2, ',', '.') ?> €</strong>
                    </span>
                </div>
                <button type="button" id="btn-reset-tag-filter" class="btn btn-outline hidden"
                        style="padding: 0.2rem 0.6rem; font-size: 0.8rem;">
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

                    <div class="tag-distribution-bar" id="exp-distribution-bar"
                         style="display: flex; height: 8px; border-radius: 4px; overflow: hidden; background: var(--bg-surface-hover); margin-bottom: 0.75rem;">
                        <?php foreach ($expensesStats as $stat):
                            $pct = $expTotalAbs > 0 ? ($stat['abs_amount'] / $expTotalAbs) * 100 : 0;
                            if ($pct <= 0) continue;
                            ?>
                            <div class="tag-bar-segment"
                                 style="width: <?= number_format($pct, 2, '.', '') ?>%; background-color: <?= Sanitizer::hexColor($stat['color']) ?>;"
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
                                 style="--tag-color: <?= Sanitizer::hexColor($stat['color']) ?>; cursor: pointer;">
                                <div class="tag-stat-bg-bar"
                                     style="width: <?= number_format($fillPct, 1, '.', '') ?>%;"></div>
                                <div class="tag-stat-content">
                                    <div class="tag-stat-header">
                                        <span class="tag-color-dot"
                                              style="background-color: <?= Sanitizer::hexColor($stat['color']) ?>;"></span>
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

                    <div class="tag-distribution-bar" id="inc-distribution-bar"
                         style="display: flex; height: 8px; border-radius: 4px; overflow: hidden; background: var(--bg-surface-hover); margin-bottom: 0.75rem;">
                        <?php foreach ($incomeStats as $stat):
                            $pct = $incTotalAbs > 0 ? ($stat['abs_amount'] / $incTotalAbs) * 100 : 0;
                            if ($pct <= 0) continue;
                            ?>
                            <div class="tag-bar-segment"
                                 style="width: <?= number_format($pct, 2, '.', '') ?>%; background-color: <?= Sanitizer::hexColor($stat['color']) ?>;"
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
                                 style="--tag-color: <?= Sanitizer::hexColor($stat['color']) ?>; cursor: pointer;">
                                <div class="tag-stat-bg-bar"
                                     style="width: <?= number_format($fillPct, 1, '.', '') ?>%;"></div>
                                <div class="tag-stat-content">
                                    <div class="tag-stat-header">
                                        <span class="tag-color-dot"
                                              style="background-color: <?= Sanitizer::hexColor($stat['color']) ?>;"></span>
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
                <p class="text-center text-muted" style="padding: 2rem 0;">Keine Umsätze für diesen Zeitraum
                    vorhanden.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="stack-table">
                        <thead>
                        <tr>
                            <th style="width: 110px;">Datum</th>
                            <th>Buchungstext</th>
                            <th>Vertrag</th>
                            <th>Regel</th>
                            <th>Tags</th>
                            <th class="text-right" style="width: 130px;">Betrag</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($transactions as $tx): ?>
                            <tr data-tx-id="<?= $tx['id'] ?>" data-amount="<?= $tx['amount'] ?>">
                                <td data-label="Datum">
                                    <?= date('d.m.Y', strtotime($tx['booking_date'])) ?>
                                </td>
                                <td data-label="Text" style="position: relative; align-items: flex-start;">
                                    <div style="flex: 1; min-width: 0; padding-right: 40px; text-align: left;">
                                        <strong style="display:block;"><?= htmlspecialchars($tx['type'] ?? 'Buchung', ENT_QUOTES, 'UTF-8') ?></strong>

                                        <?php
                                        // Beteiligten je nach Buchungsrichtung anzeigen (Auftraggeber bzw. Empfänger)
                                        $counterpartyLabel = 'Auftraggeber';
                                        $counterparty = trim((string)($tx['remitter'] ?? ''));
                                        if ($counterparty === '') {
                                            $counterparty = trim((string)($tx['creditor'] ?? ''));
                                            $counterpartyLabel = 'Empfänger';
                                        }
                                        if ($counterparty === '') {
                                            $counterparty = trim((string)($tx['debitor'] ?? ''));
                                            $counterpartyLabel = 'Zahlungspflichtiger';
                                        }
                                        ?>
                                        <div class="text-muted"
                                             style="font-size: 0.85rem; display: block; margin-top: 4px;">
                                            <?= htmlspecialchars($counterpartyLabel, ENT_QUOTES, 'UTF-8') ?>
                                            : <?= htmlspecialchars($counterparty !== '' ? $counterparty : '-', ENT_QUOTES, 'UTF-8') ?>
                                            <br>
                                            Buchungstext: <?= htmlspecialchars($tx['remittance_info'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                        </div>

                                        <!-- Links -->
                                        <div style="margin-top: 0.5rem; display: flex; flex-direction: column; gap: 4px; align-items: flex-start;">
                                            <?php if (!empty($tx['linked_statement_id'])): ?>
                                                <a href="detail.php?id=<?= (int)$tx['linked_statement_id'] ?>"
                                                   class="badge badge-primary" style="font-size: 0.75rem;">💳
                                                    Kreditkarten-Abrechnung &rarr;</a>
                                            <?php endif; ?>
                                            <?php if (!empty($tx['linked_receipt_id'])): ?>
                                                <a href="../kassenbon/detail.php?id=<?= (int)$tx['linked_receipt_id'] ?>"
                                                   class="badge badge-success" style="font-size: 0.75rem;">🧾 E-Bon
                                                    vorhanden &rarr;</a>
                                            <?php endif; ?>
                                            <?php if (!empty($tx['contract_id'])): ?>
                                                <a href="contracts.php?status=all#contract-<?= (int)$tx['contract_id'] ?>"
                                                   class="badge"
                                                   style="font-size: 0.75rem; color: var(--accent); border-color: var(--accent); background: rgba(59, 130, 246, 0.05);">
                                                    📑
                                                    Vertrag: <?= htmlspecialchars($tx['contract_name'], ENT_QUOTES, 'UTF-8') ?>
                                                    &rarr;
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Neuer, dezenter Lupen-Button (liegt absolut über dem Padding des Wrappers) -->
                                    <button type="button" class="btn-tx-details js-open-details"
                                            data-tx='<?= htmlspecialchars(json_encode($tx), ENT_QUOTES, 'UTF-8') ?>'
                                            title="Details anzeigen">
                                        🔍
                                    </button>
                                </td>
                                <td data-label="Vertrag">
                                    <?php if (!empty($tx['contract_id'])): ?>
                                        <button type="button" class="btn-rule-indicator active js-open-contract-rule"
                                                data-contract-id="<?= $tx['contract_id'] ?>" title="Vertrag verknüpft">
                                            📑
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn-rule-indicator js-open-contract-rule"
                                                data-tx-id="<?= $tx['id'] ?>"
                                                data-mandate-id="<?= htmlspecialchars($tx['dc_mandate_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-remitter="<?= htmlspecialchars($tx['remitter'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-creditor="<?= htmlspecialchars($tx['creditor'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-creditor-id="<?= htmlspecialchars($tx['dc_creditor_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-debitor="<?= htmlspecialchars($tx['debitor'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-remittance-info="<?= htmlspecialchars($tx['remittance_info'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                title="Vertrag zuordnen">📑
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Tags">
                                    <!-- Regel-Indikator (Zauberstab / Blitz) -->
                                    <?php if (!empty($tx['matched_rule_id'])): ?>
                                        <?php $ruleHint = trim(($tx['matched_text_pattern'] ?? '') . ' | ' . ($tx['matched_payee_pattern'] ?? ''), " |"); ?>
                                        <button type="button"
                                                class="btn-rule-indicator active js-open-rule-builder"
                                                data-tx-id="<?= $tx['id'] ?>"
                                                data-rule-id="<?= $tx['matched_rule_id'] ?>"
                                                data-remittance-info="<?= htmlspecialchars($tx['remittance_info'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-remitter="<?= htmlspecialchars($tx['remitter'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-debitor="<?= htmlspecialchars($tx['debitor'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-creditor="<?= htmlspecialchars($tx['creditor'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-text-pattern="<?= htmlspecialchars($tx['matched_text_pattern'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-payee-pattern="<?= htmlspecialchars($tx['matched_payee_pattern'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                title="Regel aktiv: <?= htmlspecialchars($ruleHint, ENT_QUOTES, 'UTF-8') ?> (Klicken zum Bearbeiten)">
                                            ⚡
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
                                                class="btn-rule-indicator js-open-rule-builder"
                                                data-tx-id="<?= $tx['id'] ?>"
                                                data-remittance-info="<?= htmlspecialchars($tx['remittance_info'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-remitter="<?= htmlspecialchars($tx['remitter'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-debitor="<?= htmlspecialchars($tx['debitor'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-creditor="<?= htmlspecialchars($tx['creditor'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                title="Keine Regel verknüpft (Klicken zum Erstellen)">
                                            🪄
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Tags">
                                    <div class="tag-pill-group js-tag-group" data-tx-id="<?= $tx['id'] ?>">
                                        <!-- Tags der Transaktion -->
                                        <?php foreach ($tx['tags'] as $tag): ?>
                                            <span class="badge tag-badge clickable-tag"
                                                  data-tag-id="<?= $tag['id'] ?>"
                                                  style="color: <?= Sanitizer::hexColor($tag['color']) ?>; border-color: <?= Sanitizer::hexColor($tag['color']) ?>;">
                                                    <?= htmlspecialchars($tag['name']) ?>
                                                    <span class="remove-tag-btn" data-tx-id="<?= $tx['id'] ?>"
                                                          data-tag-id="<?= $tag['id'] ?>">&times;</span>
                                                </span>
                                        <?php endforeach; ?>

                                        <button type="button" class="btn-add-tag js-open-tag-popover"
                                                data-tx-id="<?= $tx['id'] ?>" title="Tag manuell hinzufügen">+
                                        </button>
                                    </div>
                                </td>
                                <td data-label="Betrag"
                                    class="text-right amount-bold <?= $tx['amount'] < 0 ? 'text-danger' : 'text-success' ?>">
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
                            <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>&page=<?= $page - 1 ?><?= $selectedTagId ? '&tag_id=' . $selectedTagId : '' ?>"
                               class="btn btn-outline">&laquo; Vorherige</a>
                        <?php else: ?>
                            <span class="btn btn-outline disabled">&laquo; Vorherige</span>
                        <?php endif; ?>

                        <span class="page-info">Seite <?= $page ?> von <?= $totalPages ?></span>

                        <?php if ($page < $totalPages): ?>
                            <a href="?type=<?= $type ?>&date=<?= htmlspecialchars($refDate) ?>&page=<?= $page + 1 ?><?= $selectedTagId ? '&tag_id=' . $selectedTagId : '' ?>"
                               class="btn btn-outline">Nächste &raquo;</a>
                        <?php else: ?>
                            <span class="btn btn-outline disabled">Nächste &raquo;</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>

</div>

<script src="../js/bank.js?v=<?= APP_VERSION ?>" defer></script>
<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
</body>
</html>