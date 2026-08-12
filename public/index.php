<?php
require_once __DIR__ . '/../bootstrap.php';

// Auth-Check
if (!isset($_SESSION['user_email'])) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}
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
            <h1>Willkommen, <?= htmlspecialchars($_SESSION['user_name']) ?></h1>
			<a href="login.php?logout=1" class="btn btn-outline">Sicher abmelden</a>
            <div class="subtitle">Authentifiziert als: <?= htmlspecialchars($_SESSION['user_email']) ?></div>
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
					<h3>💳 Kreditkarten</h3>
					<p>Monatsabrechnungen, Einzelumsätze und Auswertungen der Visa-Karten.</p>
					<a href="bank/index.php" class="btn">Öffnen</a>
				</div>

                <div class="card">
                    <h3>☀️ PV-Solarprognose</h3>
                    <p>Aktuelle Ertragsprognose der Photovoltaikanlage (4,7 kWp) für die kommenden Tage.</p>
                    <a href="pvcharge/index.php" class="btn">Öffnen</a>
                </div>

                <div class="card">
                    <h3>🚐 VW ID.Buzz</h3>
                    <p>Live-Telemetrie des Fahrzeugs: Ladestand, Reichweite, Temperaturen und Verlaufshistorie.</p>
                    <a href="car/index.php" class="btn">Öffnen</a>
                </div>
                
            </div>
        </main>
    </div>
</body>
</html>