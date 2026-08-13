<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// 1. Auth-Check — immer zuerst (AGENTS.md)
Auth::requirePage();

try {
    $db = Database::getInstance()->getConnection();
    // TODO: Repository-Aufrufe für Giro-Umsätze & Tags
} catch (\Throwable $e) {
    (new Logger())->error('bank/index.php: Datenbankfehler.', ['error' => $e->getMessage()]);
    http_response_code(500);
    exit('Interner Fehler. Bitte versuche es später erneut.');
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
    <div class="container">
        <header class="page-header">
            <h1>🏦 Girokonto Umsätze</h1>
            <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
        </header>

        <!-- Tab-Switcher -->
        <div class="period-switcher" style="justify-content: flex-start; margin-bottom: 1.5rem;">
            <a href="index.php" class="btn">🏦 Girokonto</a>
            <a href="creditcard.php" class="btn btn-outline">💳 Kreditkarte</a>
        </div>

        <main>
            <section class="card">
                <!-- Stack-Table für Umsätze & Tags -->
                <div class="table-responsive">
                    <table class="stack-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Buchungstext</th>
                                <th>Tags</th>
                                <th class="text-right">Betrag</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Buchungen iterieren... -->
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
    <script src="../js/bank.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>