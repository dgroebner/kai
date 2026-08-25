<?php
// public/shared/head-pwa.php
// Zentraler PWA-Head-Include fuer alle Seiten.
// Einbinden direkt nach dem <head>-Tag mit:
//   <?php include __DIR__ . '/../shared/head-pwa.php'; ?>  (Domain-Unterseiten)
//   <?php include __DIR__ . '/shared/head-pwa.php'; ?>     (Root-Seiten)
?>
<!-- PWA Manifest & Theme Color -->
<link rel="manifest" href="<?= APP_URL ?>/manifest.json">
<meta name="theme-color" content="#0f172a">

<!-- iOS PWA Support -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Kai">
<link rel="apple-touch-icon" href="<?= APP_URL ?>/apple-touch-icon.png">

<!-- PWA Registration Script -->
<script src="<?= APP_URL ?>/js/pwa-register.js?v=<?= APP_VERSION ?>" defer></script>