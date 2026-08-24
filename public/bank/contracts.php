<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Bank\BankContractRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// Auth-Check
Auth::requirePage();

$logger = new Logger();
$contractRepo = new BankContractRepository();

$statusFilter = $_GET['status'] ?? 'aktiv';
$contracts = $contractRepo->getAllContracts($statusFilter !== 'all' ? $statusFilter : null);

// Berechnung von monatlichen Gesamtsummen für aktive Verträge (getrennt nach Einnahmen und Ausgaben)
$totalMonthlyExpenses = 0;
$totalMonthlyIncome = 0;

foreach ($contracts as $c) {
    if ($c['status'] === 'aktiv') {
        $amount = (float)$c['betrag'];
        $monthlyFactor = 0;

        switch ($c['frequenz']) {
            case 'monatlich':
                $monthlyFactor = 1;
                break;
            case 'vierteljaehrlich':
                $monthlyFactor = 1 / 3;
                break;
            case 'halbjaehrlich':
                $monthlyFactor = 1 / 6;
                break;
            case 'jaehrlich':
                $monthlyFactor = 1 / 12;
                break;
            case 'einmalig':
                // Einmalige fließen hier nicht in die monatliche Summe ein
                break;
        }

        if (($c['direction'] ?? 'expense') === 'income') {
            $totalMonthlyIncome += $amount * $monthlyFactor;
        } else {
            $totalMonthlyExpenses += $amount * $monthlyFactor;
        }
    }
}

$netMonthlyCashflow = $totalMonthlyIncome - $totalMonthlyExpenses;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verträge & Fixkosten – Kai</title>
    <meta name="csrf-token" content="<?= Auth::csrfToken() ?>">
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="container">

    <header class="page-header">
        <h1>📑 Verträge & Fixkosten</h1>
        <div class="page-header-actions">
            <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
        </div>
    </header>

    <!-- Tab-Switcher (Girokonto / Kreditkarte / Verträge) -->
    <div class="period-switcher" style="justify-content: flex-start; margin-bottom: 1.5rem;">
        <a href="index.php" class="btn btn-outline">🏦 Girokonto</a>
        <a href="creditcard.php" class="btn btn-outline">💳 Kreditkarte</a>
        <a href="contracts.php" class="btn">📑 Verträge</a>
    </div>

    <!-- KPI / Übersichtskarte -->
    <section class="kpi-grid"
             style="margin-bottom: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <div class="kpi-card" style="border: 1px solid var(--color-green, #10b981);">
            <div class="kpi-label">📈 Fix-Einnahmen (ø mtl.)</div>
            <div class="kpi-value_sm" style="font-size: 1.3rem; color: var(--color-green, #10b981);">
                +<?= number_format($totalMonthlyIncome, 2, ',', '.') ?> €
            </div>
        </div>
        <div class="kpi-card" style="border: 1px solid var(--color-red, #ef4444);">
            <div class="kpi-label">📉 Fixkosten (ø mtl.)</div>
            <div class="kpi-value_sm" style="font-size: 1.3rem; color: var(--color-red, #ef4444);">
                -<?= number_format($totalMonthlyExpenses, 2, ',', '.') ?> €
            </div>
        </div>
        <div class="kpi-card" style="border: 1px solid var(--accent);">
            <div class="kpi-label">💰 Netto-Cashflow (mtl.)</div>
            <div class="kpi-value_sm"
                 style="font-size: 1.3rem; color: <?= $netMonthlyCashflow >= 0 ? 'var(--color-green, #10b981)' : 'var(--color-red, #ef4444)' ?>;">
                <?= $netMonthlyCashflow >= 0 ? '+' : '' ?><?= number_format($netMonthlyCashflow, 2, ',', '.') ?> €
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">📋 Aktive Verträge</div>
            <div class="kpi-value_sm" style="font-size: 1.3rem;">
                <?= count(array_filter($contracts, fn($c) => $c['status'] === 'aktiv')) ?>
            </div>
        </div>
    </section>

    <!-- Filter & Aktionen -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <div class="period-switcher" style="margin-bottom: 0;">
            <a href="?status=aktiv" class="btn <?= $statusFilter === 'aktiv' ? '' : 'btn-outline' ?>"
               style="padding: 0.4rem 0.8rem; font-size: 0.85rem;">Aktiv</a>
            <a href="?status=pausiert" class="btn <?= $statusFilter === 'pausiert' ? '' : 'btn-outline' ?>"
               style="padding: 0.4rem 0.8rem; font-size: 0.85rem;">Pausiert</a>
            <a href="?status=gekuendigt" class="btn <?= $statusFilter === 'gekuendigt' ? '' : 'btn-outline' ?>"
               style="padding: 0.4rem 0.8rem; font-size: 0.85rem;">Gekündigt</a>
            <a href="?status=all" class="btn <?= $statusFilter === 'all' ? '' : 'btn-outline' ?>"
               style="padding: 0.4rem 0.8rem; font-size: 0.85rem;">Alle</a>
        </div>
        <button type="button" class="btn btn-blue" id="btn-add-contract">
            + Neuen Vertrag anlegen
        </button>
    </div>

    <!-- Vertrags-Tabelle -->
    <main>
        <section class="card">
            <?php if (empty($contracts)): ?>
                <p class="text-center text-muted" style="padding: 2rem 0;">Keine Verträge in diesem Filter gefunden.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="stack-table">
                        <thead>
                        <tr>
                            <th>Name / Typ</th>
                            <th>Auftraggeber / Mandat</th>
                            <th>Rhythmus</th>
                            <th>Kategorie</th>
                            <th class="text-right">Betrag</th>
                            <th class="text-right" style="width: 100px;">Akt.</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($contracts as $c):
                            $isIncome = ($c['direction'] ?? 'expense') === 'income';
                            ?>
                            <tr id="contract-<?= $c['id'] ?>">
                                <td data-label="Name">
                                    <strong><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">
                                        <?= htmlspecialchars($c['type'], ENT_QUOTES, 'UTF-8') ?>
                                        <span class="badge"
                                              style="font-size: 0.65rem; padding: 0.1rem 0.3rem; margin-left: 0.3rem;"><?= htmlspecialchars($c['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </td>
                                <td data-label="Auftraggeber" style="font-size: 0.85rem; color: var(--text-muted);">
                                    <?= htmlspecialchars($c['auftraggeber'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($c['mandatsnummer'])): ?>
                                        <br><span
                                                style="font-size: 0.75rem;">Mandat: <?= htmlspecialchars($c['mandatsnummer'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Rhythmus" style="font-size: 0.85rem;">
                                    <?= ucfirst(htmlspecialchars($c['frequenz'], ENT_QUOTES, 'UTF-8')) ?>
                                    <?php if ($c['variabel']): ?>
                                        <span style="font-size: 0.75rem; color: var(--color-yellow, #eab308); display: block;">(variabel)</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Kategorie" style="font-size: 0.85rem;">
                                    <?= htmlspecialchars($c['category_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td data-label="Betrag"
                                    class="text-right amount-bold <?= $isIncome ? 'text-success' : 'text-danger' ?>">
                                    <?= $isIncome ? '+' : '-' ?><?= number_format((float)$c['betrag'], 2, ',', '.') ?>
                                    €
                                </td>
                                <td data-label="Aktionen" class="text-right">
                                    <button type="button" class="btn btn-outline js-edit-contract"
                                            data-contract='<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>'
                                            style="padding: 0.2rem 0.5rem; font-size: 0.8rem;">
                                        ✏️
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

</div>
<script src="../js/http.js?v=<?= APP_VERSION ?>" defer></script>
<script src="../js/bank.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>