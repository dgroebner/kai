<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Bank\CreditCardRepository;
use Kai\Tools\Bank\StatementMatcher;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// Auth-Check — immer zuerst
Auth::requirePage();

// Paginierungseinstellungen
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

try {
    // Automatischen Abgleich für evtl. neu eingetroffene Abrechnungen ausführen
    new StatementMatcher()->syncUnlinkedStatements();

    $creditCardRepository = new CreditCardRepository();

    // Gesamtzahl für Paginierung ermitteln
    $totalStatements = $creditCardRepository->countStatements();
    $totalPages = max(1, (int)ceil($totalStatements / $limit));

    // Abrechnungen inklusive Abbuchungsstatus vom Girokonto laden
    $statements = $creditCardRepository->getStatements($limit, $offset);
} catch (Throwable $e) {
    new Logger()->error('bank/creditcard.php: Datenbankfehler.', ['error' => $e->getMessage()]);
    http_response_code(500);
    exit('Interner Fehler. Bitte versuche es später erneut.');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= Auth::csrfToken() ?>">
    <title>Kreditkarten - Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <!-- Header mit Titel links und Button rechts oben -->
    <header class="page-header">
        <h1>💳 Kreditkartenabrechnungen</h1>
        <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
    </header>

    <!-- Tab-Switcher -->
    <div class="period-switcher" style="justify-content: flex-start; margin-bottom: 1.5rem;">
        <a href="index.php" class="btn btn-outline">🏦 Girokonto</a>
        <a href="creditcard.php" class="btn">💳 Kreditkarte</a>
        <a href="contracts.php" class="btn btn-outline">📑 Verträge</a>
    </div>

    <main>
        <section class="card">
            <div class="table-responsive">
                <table class="receipts-table stack-table">
                    <thead>
                    <tr>
                        <th>Abrechnung</th>
                        <th>Fälligkeit</th>
                        <th>Status</th>
                        <th>Positionen</th>
                        <th class="text-right">Gesamtbetrag</th>
                        <th class="text-right">Aktion</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($statements)): ?>
                        <tr>
                            <td colspan="5" class="text-center">Keine Abrechnungen gefunden.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($statements as $stmtRow): ?>
                            <tr>
                                <td data-label="Abrechnung">
                                    <?= date('d.m.Y', strtotime($stmtRow['statement_date'])) ?>
                                </td>
                                <td data-label="Fälligkeit">
                                    <?= $stmtRow['due_date'] ? date('d.m.Y', strtotime($stmtRow['due_date'])) : '-' ?>
                                </td>
                                <td data-label="Status">
                                    <?php if (!empty($stmtRow['giro_booking_date'])): ?>
                                        <a href="index.php?tx=<?= (int)$stmtRow['bank_transaction_id'] ?>"
                                           class="badge badge-success" style="text-decoration: none;"
                                           title="Auf Girokonto verbucht">
                                            🟢 Abgebucht (<?= date('d.m.Y', strtotime($stmtRow['giro_booking_date'])) ?>)
                                        </a>
                                    <?php else: ?>
                                        <span class="badge badge-warning"
                                              title="Noch keine Abbuchung auf dem Girokonto gefunden">
                                                    🟡 Offen
                                                </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Positionen"><?= (int)$stmtRow['tx_count'] ?></td>
                                <td data-label="Gesamtbetrag" class="text-right amount-bold">
                                    <?= number_format((float)$stmtRow['total_amount'], 2, ',', '.') ?> €
                                </td>
                                <td data-label="Aktion" class="text-right">
                                    <a href="detail.php?id=<?= (int)$stmtRow['id'] ?>" class="btn-link">
                                        &#9660; Details
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginierung am unteren Ende -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>" class="btn btn-outline">&laquo; Vorherige</a>
                    <?php else: ?>
                        <span class="btn btn-outline disabled">&laquo; Vorherige</span>
                    <?php endif; ?>

                    <span class="page-info">Seite <?= $page ?> von <?= $totalPages ?></span>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>" class="btn btn-outline">Nächste &raquo;</a>
                    <?php else: ?>
                        <span class="btn btn-outline disabled">Nächste &raquo;</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
</body>
</html>