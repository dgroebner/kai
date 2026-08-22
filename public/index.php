<?php
require_once __DIR__ . '/../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;

// Auth-Check — immer zuerst
Auth::requirePage();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kai's Dashboard</title>
    <link rel="stylesheet" href="css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="container">
    <header class="page-header">
        <h1>Willkommen, <?= htmlspecialchars($_SESSION['user_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="page-header-actions">
            <span class="last-update">Authentifiziert als: <?= htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            <a href="login.php?logout=1" class="btn btn-outline">Sicher abmelden</a>
        </div>
    </header>

    <main>
        <div class="tool-grid">

            <div class="card">
                <h3>🛒 eBons</h3>
                <p>Automatische KI-Auswertung der Haushalts-Kassenbons und Einzelpreise für die Küchenplanung.</p>
                <a href="kassenbon/index.php" class="btn">Öffnen</a>
            </div>

            <div class="card">
                <h3>📈 Bon-Auswertung</h3>
                <p>Auswertung der über die Kassenbons erfassten Positionen nach Zeitraum und Kategorien.</p>
                <a href="kassenbon/auswertung.php" class="btn">Öffnen</a>
            </div>

            <div class="card">
                <h3>🏦 Finanzen</h3>
                <p>Girokonto-Umsätze, Kreditkartenabrechnungen und Tag-Auswertungen im Überblick.</p>
                <a href="bank/index.php" class="btn">Öffnen</a>
            </div>

            <div class="card">
                <h3>⚡ Energie-Dashboard</h3>
                <p>Live-Telemetrie und Ertragsprognose der Photovoltaikanlage (4,7 kWp) für die kommenden Tage.</p>
                <a href="pvcharge/index.php" class="btn">Öffnen</a>
            </div>

            <div class="card">
                <h3>🚐 VW ID.Buzz</h3>
                <p>Live-Telemetrie des Fahrzeugs: Ladestand, Reichweite, Temperaturen und Verlaufshistorie.</p>
                <a href="car/index.php" class="btn">Öffnen</a>
            </div>

            <div class="card">
                <h3>📋 Aktivitäts-Log</h3>
                <p>Übersicht aller System-Ereignisse: Neue Kassenbons, Bankdaten, Fahrzeug-Telemetrie und
                    PV-Prognosen.</p>
                <a href="system/index.php" class="btn">Öffnen</a>
            </div>

        </div>
    </main>
</div>
</body>
</html>