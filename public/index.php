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
    <meta name="csrf-token" content="<?= Auth::csrfToken() ?>">
    <title>Kai's Dashboard</title>
    <link rel="stylesheet" href="css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <h1>Willkommen, <?= htmlspecialchars($_SESSION['user_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="page-header-actions">
            <span class="last-update">Authentifiziert als: <?= htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            <a href="profile.php" class="btn btn-outline">👤 Profil</a>
            <a href="login.php?logout=1" class="btn btn-outline">Sicher abmelden</a>
        </div>
    </header>

    <main>
        <div class="tool-grid">

            <?php if (Auth::hasPermission('weather_read')): ?>
                <a href="weather/index.php" class="card tool-card">
                    <span class="tool-card-icon">🌤️</span>
                    <span class="tool-card-title">Wetter</span>
                </a>
            <?php endif; ?>

            <?php if (Auth::hasPermission('shopping_read')): ?>
                <a href="einkaufsliste/index.php" class="card tool-card">
                    <span class="tool-card-icon">🛒</span>
                    <span class="tool-card-title">Einkaufsliste</span>
                </a>
            <?php endif; ?>

            <?php if (Auth::hasPermission('school_read')): ?>
                <a href="school/index.php" class="card tool-card">
                    <span class="tool-card-icon">🎒</span>
                    <span class="tool-card-title">Schule</span>
                </a>
            <?php endif; ?>

            <?php if (Auth::hasPermission('pv_read')): ?>
                <a href="pvcharge/index.php" class="card tool-card">
                    <span class="tool-card-icon">⚡</span>
                    <span class="tool-card-title">Energie</span>
                </a>
            <?php endif; ?>

            <?php if (Auth::hasPermission('ebon_read')): ?>
                <a href="kassenbon/index.php" class="card tool-card">
                    <span class="tool-card-icon">🧾</span>
                    <span class="tool-card-title">E-Bons</span>
                </a>
            <?php endif; ?>

            <?php if (Auth::hasPermission('finance_read')): ?>
                <a href="bank/index.php" class="card tool-card">
                    <span class="tool-card-icon">🏦</span>
                    <span class="tool-card-title">Finanzen</span>
                </a>
            <?php endif; ?>

            <?php if (Auth::hasPermission('car_read')): ?>
                <a href="car/index.php" class="card tool-card">
                    <span class="tool-card-icon">🚗</span>
                    <span class="tool-card-title">E-Auto</span>
                </a>
            <?php endif; ?>

            <?php if (Auth::hasPermission('system_read')): ?>
                <a href="system/index.php" class="card tool-card">
                    <span class="tool-card-icon">⚙️</span>
                    <span class="tool-card-title">System</span>
                </a>
            <?php endif; ?>

        </div>
    </main>
    <!-- Globaler App Footer -->
    <footer class="app-footer">
        <div>kai v<?= APP_VERSION ?></div>
    </footer>
</div>
<script src="js/http.js?v=<?= APP_VERSION ?>"></script>
<script src="js/system.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>