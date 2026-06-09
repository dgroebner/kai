<?php
require_once __DIR__ . '/../bootstrap.php';

// Auth-Check
if (!isset($_SESSION['user_email'])) {
    header('Location: https://kai.agent-smith.de/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kai's Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>Willkommen, <?= htmlspecialchars($_SESSION['user_name']) ?></h1>
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
                    <h3>🏠 Home Automation</h3>
                    <p>Google Home Status & Routinen-Logs (z.B. Gute Nacht Routine).</p>
                    <a href="#" class="btn" style="opacity: 0.6;">In Entwicklung</a>
                </div>
                
            </div>
        </main>

        <footer>
            <a href="login.php?logout=1" class="btn btn-outline">Sicher abmelden</a>
        </footer>
    </div>
</body>
</html>