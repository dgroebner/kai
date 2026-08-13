<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// Auth-Check — immer zuerst
Auth::requirePage();

$csrfToken = Auth::csrfToken();

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id <= 0) {
    http_response_code(400);
    exit('Ungültige Abrechnung.');
}

try {
    $db = Database::getInstance()->getConnection();

    $stmtInfo = $db->prepare("SELECT * FROM bank_cc_statements WHERE id = :id");
    $stmtInfo->execute([':id' => $id]);
    $statement = $stmtInfo->fetch(PDO::FETCH_ASSOC);

    if (!$statement) {
        http_response_code(404);
        exit('Abrechnung nicht gefunden.');
    }

    // Transaktionen laden
    $stmtTx = $db->prepare("
        SELECT t.*, c.name AS category_name 
        FROM bank_cc_transactions t
        LEFT JOIN bank_categories c ON t.category_id = c.id
        WHERE t.statement_id = :id
        ORDER BY t.booking_date DESC
    ");
    $stmtTx->execute([':id' => $id]);
    $transactions = $stmtTx->fetchAll(PDO::FETCH_ASSOC);

    // Alle verfügbaren Kategorien für das Inline-Dropdown laden
    $stmtCats = $db->query("SELECT id, name FROM bank_categories ORDER BY name");
    $allCategories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    (new Logger())->error('bank/detail.php: Datenbankfehler.', ['error' => $e->getMessage()]);
    http_response_code(500);
    exit('Interner Fehler. Bitte versuche es später erneut.');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Abrechnung vom <?= date('d.m.Y', strtotime($statement['statement_date'])) ?> - Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
    <div class="container">
        <header class="page-header">
            <h1>💳 Abrechnung vom <?= date('d.m.Y', strtotime($statement['statement_date'])) ?></h1>
            <a href="index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
        </header>

		<main id="bankDetailApp"
			  data-categories='<?= htmlspecialchars(json_encode($allCategories), ENT_QUOTES, 'UTF-8') ?>'
			  data-transactions='<?= htmlspecialchars(json_encode($transactions), ENT_QUOTES, 'UTF-8') ?>'
			  data-total="<?= (float)$statement['total_amount'] ?>">
            <!-- Kopfbereich: Kategorien-Analyse mit Donut-Chart -->
            <section class="card category-analysis-card">
                <h3>Kategorien-Anteil</h3>
                <div class="analysis-grid">
                    <!-- Linke Spalte: Klickbare Legende -->
                    <div class="category-legend" id="categoryLegend">
                        <!-- Wird dynamisch per JS befüllt -->
                    </div>
                    
                    <!-- Rechte Spalte: ChartJS Pie/Donut -->
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                        <div class="chart-center-text">
                            <span class="label">Gesamt</span>
                            <span class="value"><?= number_format((float)$statement['total_amount'], 2, ',', '.') ?> €</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Einzelpositionen / Umsatztabelle -->
            <section class="card">
                <div class="table-header-flex">
                    <h2>Positionen</h2>
                    <button id="resetFilterBtn" class="btn btn-small btn-outline hidden">Filter zurücksetzen</button>
                </div>

                <table class="receipts-table stack-table" id="transactionsTable">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Händler / Ort</th>
                            <th>Karte</th>
                            <th>Kategorie</th>
                            <th class="text-right">Betrag</th>
                        </tr>
                    </thead>
                    <tbody>
						<?php foreach ($transactions as $tx): 
							$amount = (float)$tx['amount'];
							$isRefund = $amount > 0; // Positive Beträge = Gutschriften
							$displayAmount = abs($amount);
						?>
						<tr data-tx-id="<?= (int)$tx['id'] ?>" 
							data-category-id="<?= (int)$tx['category_id'] ?>" 
							data-category-name="<?= htmlspecialchars($tx['category_name'] ?? 'Sonstiges', ENT_QUOTES, 'UTF-8') ?>">
								
								<td data-label="Datum"><?= date('d.m.Y', strtotime($tx['booking_date'])) ?></td>
								<td data-label="Händler / Ort"><?= htmlspecialchars($tx['merchant_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
								<td data-label="Karte">*<?= htmlspecialchars($tx['card_number_suffix'] ?? '----', ENT_QUOTES, 'UTF-8') ?></td>
								
								<!-- Kategorie mit dynamischer Farbe & Stift-Icon -->
								<td  data-label="Kategorie" class="category-cell">
									<span class="category-badge clickable-badge" 
										  title="Kategorie bearbeiten" 
										  data-tx-id="<?= (int)$tx['id'] ?>">
										<span class="badge-text"><?= htmlspecialchars($tx['category_name'] ?? 'Sonstiges', ENT_QUOTES, 'UTF-8') ?></span>
										<span class="edit-icon">✏️</span>
									</span>
								</td>
								
								<!-- Beträge: Weiß für Ausgaben, Grün + Badge für Gutschriften -->
								<td data-label="Betrag" class="text-right">
									<?php if ($isRefund): ?>
										<span class="refund-badge">Gutschrift</span>
										<span class="text-success">+<?= number_format($displayAmount, 2, ',', '.') ?> €</span>
									<?php else: ?>
										<span><?= number_format($displayAmount, 2, ',', '.') ?> €</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>

	<script src="../js/chart.min.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="../js/http.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="../js/bank.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>