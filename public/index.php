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
    <title>Kai's Dashboard</title>
</head>
<body style="font-family: sans-serif; padding: 20px;">
    <h1>Willkommen, <?= htmlspecialchars($_SESSION['user_name']) ?></h1>
    <p>Authentifiziert als: <?= htmlspecialchars($_SESSION['user_email']) ?></p>
    <hr>
    
    <h3>Deine Tools</h3>
    <ul>
        <li>Tool 1 (Platzhalter)</li>
        <li>Tool 2 (Platzhalter)</li>
    </ul>

    <p><a href="login.php?logout=1">Abmelden</a></p>
</body>
</html>