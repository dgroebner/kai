<?php
require_once __DIR__ . '/../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\DailyWisdomService;

// Auth-Check — immer zuerst
Auth::requirePage();

// Weisheit des Tages abrufen (inkl. Lazy Evaluation & Fallback-Schutz)
$dailyWisdom = null;
$cleanWisdomText = '';
$luckyNumbers = [];
try {
    $wisdomService = new DailyWisdomService();
    $dailyWisdom = $wisdomService->getWisdomForToday();
} catch (Throwable) {
    $dailyWisdom = DailyWisdomService::FALLBACK_WISDOMS[0];
}

if (!empty($dailyWisdom)) {
    $cleanWisdomText = $dailyWisdom;
    if (str_starts_with($dailyWisdom, 'Konfuzius sagt:')) {
        $cleanWisdomText = trim(substr($dailyWisdom, strlen('Konfuzius sagt:')));
    }

    // Deterministische Glückszahlen für den aktuellen Kalendertag generieren
    $todaySeed = (int) sprintf('%u', crc32(date('Y-m-d')));
    mt_srand($todaySeed);
    while (count($luckyNumbers) < 6) {
        $num = mt_rand(1, 49);
        if (!in_array($num, $luckyNumbers, true)) {
            $luckyNumbers[] = $num;
        }
    }
    sort($luckyNumbers);
}
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
            <button type="button" id="open-briefing-btn" class="btn btn-outline briefing-header-btn" title="Daily Briefing anzeigen">☀️ Briefing</button>
            <a href="profile.php" class="btn btn-outline">👤 Profil</a>
            <a href="login.php?logout=1" class="btn btn-outline">Sicher abmelden</a>
        </div>
    </header>

    <main>
        <?php if (!empty($dailyWisdom)): ?>
            <section class="card daily-wisdom-card" aria-label="Weisheit des Tages">
                <div class="daily-wisdom-header">
                    <span class="daily-wisdom-badge">✨ Weisheit des Tages</span>
                    <span class="daily-wisdom-hint js-wisdom-hint">🥠 Glückskeks</span>
                </div>
                <div class="fortune-cookie-container js-wisdom-trigger" role="button" tabindex="0" aria-haspopup="dialog" aria-expanded="false" aria-label="Glückskeks öffnen für die Weisheit des Tages">
                    <div class="fortune-cookie-visual">
                        <div class="fortune-cookie-icon-wrap">
                            <svg viewBox="0 0 100 85" class="fortune-cookie-svg" aria-hidden="true">
                                <defs>
                                    <linearGradient id="cookieGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                        <stop offset="0%" stop-color="#fcd34d" />
                                        <stop offset="50%" stop-color="#f59e0b" />
                                        <stop offset="100%" stop-color="#b45309" />
                                    </linearGradient>
                                    <linearGradient id="cookieCreaseGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                                        <stop offset="0%" stop-color="#92400e" />
                                        <stop offset="100%" stop-color="#451a03" />
                                    </linearGradient>
                                </defs>
                                <!-- Keks-Körper -->
                                <path class="cookie-body" d="M 50,16 C 30,16 12,28 8,46 C 4,62 18,78 36,78 C 45,78 49,66 50,56 C 51,66 55,78 64,78 C 82,78 96,62 92,46 C 88,28 70,16 50,16 Z" fill="url(#cookieGrad)" stroke="#b45309" stroke-width="2" />
                                <!-- Keks-Falte / Tiefe -->
                                <path class="cookie-crease" d="M 50,30 Q 50,52 38,70 Q 50,58 50,30 Z" fill="url(#cookieCreaseGrad)" opacity="0.65" />
                                <!-- Glanzlicht -->
                                <path class="cookie-highlight" d="M 22,32 C 16,42 16,56 22,64" stroke="#fef3c7" stroke-width="2.5" stroke-linecap="round" fill="none" opacity="0.8" />
                                <!-- Zettelstreifen aus dem Keks -->
                                <rect class="cookie-slip" x="42" y="34" width="16" height="15" rx="2" fill="#fffdfa" stroke="#d5c7a3" stroke-width="1.2" />
                            </svg>
                        </div>
                    </div>
                    <div class="fortune-cookie-info">
                        <div class="fortune-cookie-title">Dein persönlicher Glückskeks</div>
                        <div class="fortune-cookie-desc js-cookie-desc">Knacke den Keks, um Konfuzius' Weisheit des Tages zu enthüllen.</div>
                        <button type="button" class="btn-cookie-open js-cookie-btn">🥠 Keks öffnen</button>
                    </div>
                </div>
            </section>
        <?php endif; ?>

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

            <?php if (Auth::hasPermission('calendar_read')): ?>
                <a href="calendar/index.php" class="card tool-card">
                    <span class="tool-card-icon">🎉</span>
                    <span class="tool-card-title">Geburtstage &amp; Jahrestage</span>
                </a>
            <?php endif; ?>

            <?php if (Auth::hasPermission('gamification_read')): ?>
                <a href="gamification/index.php" class="card tool-card">
                    <span class="tool-card-icon">🏆</span>
                    <span class="tool-card-title">Familien-Quests</span>
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
<?php if (!empty($dailyWisdom)): ?>
<!-- Papierrollen-Popup für die Weisheit des Tages -->
<div id="wisdom-modal" class="wisdom-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="wisdom-modal-author" hidden>
    <div class="wisdom-modal-backdrop js-wisdom-close"></div>
    <div class="wisdom-modal-dialog">
        <button type="button" class="wisdom-modal-close-btn js-wisdom-close" aria-label="Schriftrolle schließen">&times;</button>
        
        <div class="wisdom-scroll-container">
            <!-- Oberer Holzstab mit Zierknäufen -->
            <div class="wisdom-scroll-rod top">
                <span class="scroll-rod-knob left"></span>
                <span class="scroll-rod-knob right"></span>
            </div>

            <!-- Pergamentpapier -->
            <div class="wisdom-scroll-body">
                <div class="wisdom-scroll-seal" title="Weisheit (智)">智</div>
                
                <header class="wisdom-scroll-header">
                    <span class="wisdom-scroll-tag">✨ Aus dem Glückskeks</span>
                    <h2 id="wisdom-modal-author" class="wisdom-scroll-author">Konfuzius spricht</h2>
                </header>

                <div class="wisdom-scroll-divider">
                    <span class="divider-line"></span>
                    <span class="divider-symbol">❖</span>
                    <span class="divider-line"></span>
                </div>

                <blockquote class="wisdom-scroll-quote">
                    „<?= htmlspecialchars($cleanWisdomText, ENT_QUOTES, 'UTF-8') ?>“
                </blockquote>

                <div class="wisdom-scroll-divider">
                    <span class="divider-line"></span>
                    <span class="divider-symbol">❖</span>
                    <span class="divider-line"></span>
                </div>

                <div class="wisdom-scroll-footer">
                    <div class="wisdom-lucky-numbers">
                        <span class="lucky-label">🍀 Glückszahlen des Tages:</span>
                        <span class="lucky-values"><?= implode(' · ', $luckyNumbers) ?></span>
                    </div>
                </div>
            </div>

            <!-- Unterer Holzstab mit Zierknäufen -->
            <div class="wisdom-scroll-rod bottom">
                <span class="scroll-rod-knob left"></span>
                <span class="scroll-rod-knob right"></span>
            </div>
        </div>

        <div class="wisdom-modal-actions">
            <button type="button" class="btn btn-cookie-close js-wisdom-close">Schriftrolle einrollen</button>
        </div>
    </div>
</div>
<?php endif; ?>
<script src="js/http.js?v=<?= APP_VERSION ?>"></script>
<script src="js/system.js?v=<?= APP_VERSION ?>"></script>
<script src="js/briefing.js?v=<?= APP_VERSION ?>" defer></script>
<script src="js/wisdom-cookie.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>