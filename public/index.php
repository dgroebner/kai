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
                    <div class="daily-wisdom-actions">
                        <?php if (Auth::hasPermission('system_write')): ?>
                            <button type="button" class="wisdom-debug-btn js-wisdom-reset" title="Admin: Glückskeks wieder verschließen (Knacken erneut testen)" aria-label="Glückskeks zurücksetzen">🔄</button>
                        <?php endif; ?>
                        <span class="daily-wisdom-hint js-wisdom-hint">🥠 Glückskeks</span>
                    </div>
                </div>
                <div class="fortune-cookie-container js-wisdom-trigger" role="button" tabindex="0" aria-haspopup="dialog" aria-expanded="false" aria-label="Glückskeks öffnen für die Weisheit des Tages">
                    <div class="fortune-cookie-visual">
                        <div class="fortune-cookie-icon-wrap">
                            <svg viewBox="0 0 130 90" class="fortune-cookie-svg" aria-hidden="true">
                                <defs>
                                    <linearGradient id="cookieGradLeft" x1="0%" y1="0%" x2="100%" y2="100%">
                                        <stop offset="0%" stop-color="#fcd34d" />
                                        <stop offset="45%" stop-color="#f59e0b" />
                                        <stop offset="100%" stop-color="#b45309" />
                                    </linearGradient>
                                    <linearGradient id="cookieGradRight" x1="100%" y1="0%" x2="0%" y2="100%">
                                        <stop offset="0%" stop-color="#fcd34d" />
                                        <stop offset="45%" stop-color="#f59e0b" />
                                        <stop offset="100%" stop-color="#b45309" />
                                    </linearGradient>
                                    <linearGradient id="cookieCreaseGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                                        <stop offset="0%" stop-color="#92400e" />
                                        <stop offset="100%" stop-color="#451a03" />
                                    </linearGradient>
                                    <filter id="paperShadow" x="-20%" y="-20%" width="140%" height="140%">
                                        <feDropShadow dx="0" dy="2" stdDeviation="2" flood-color="#000" flood-opacity="0.3" />
                                    </filter>
                                </defs>

                                <!-- Krümel beim Zerbrechen -->
                                <g class="cookie-crumbs">
                                    <circle class="cookie-crumb c1" cx="62" cy="73" r="2.2" fill="#d97706" />
                                    <circle class="cookie-crumb c2" cx="54" cy="77" r="1.6" fill="#b45309" />
                                    <circle class="cookie-crumb c3" cx="74" cy="75" r="1.8" fill="#f59e0b" />
                                    <circle class="cookie-crumb c4" cx="66" cy="79" r="1.3" fill="#fcd34d" />
                                </g>

                                <!-- Papierstreifen in der Mitte -->
                                <g class="cookie-paper-strip">
                                    <rect x="33" y="36" width="64" height="24" rx="3" fill="#fffdf7" stroke="#d6c69f" stroke-width="1.2" filter="url(#paperShadow)" />
                                    <!-- Rotes chinesisches Mini-Siegel links -->
                                    <rect x="38" y="41" width="7" height="14" rx="1.5" fill="#dc2626" />
                                    <!-- Textlinien-Andeutung -->
                                    <line x1="49" y1="44" x2="89" y2="44" stroke="#78350f" stroke-width="1.8" stroke-linecap="round" opacity="0.75" />
                                    <line x1="49" y1="50" x2="83" y2="50" stroke="#78350f" stroke-width="1.8" stroke-linecap="round" opacity="0.65" />
                                    <!-- Glückszahlen-Punkte -->
                                    <circle cx="53" cy="55" r="1" fill="#b45309" />
                                    <circle cx="59" cy="55" r="1" fill="#b45309" />
                                    <circle cx="65" cy="55" r="1" fill="#b45309" />
                                    <circle cx="71" cy="55" r="1" fill="#b45309" />
                                    <circle cx="77" cy="55" r="1" fill="#b45309" />
                                </g>

                                <!-- Linke Kekshälfte -->
                                <g class="cookie-half cookie-half-left">
                                    <path d="M 65,16 C 45,16 27,28 23,46 C 19,62 33,78 51,78 C 58,78 62,68 64,57 L 62,45 L 65,33 Z" fill="url(#cookieGradLeft)" stroke="#a15309" stroke-width="1.8" />
                                    <!-- Falte / Schatten links -->
                                    <path d="M 65,30 Q 64,48 53,68 Q 63,55 64,30 Z" fill="url(#cookieCreaseGrad)" opacity="0.55" />
                                    <!-- Glanzlicht links -->
                                    <path d="M 37,32 C 31,42 31,56 37,64" stroke="#fef3c7" stroke-width="2.5" stroke-linecap="round" fill="none" opacity="0.75" />
                                </g>

                                <!-- Rechte Kekshälfte -->
                                <g class="cookie-half cookie-half-right">
                                    <path d="M 65,16 C 85,16 103,28 107,46 C 111,62 97,78 79,78 C 72,78 68,68 66,57 L 68,45 L 65,33 Z" fill="url(#cookieGradRight)" stroke="#a15309" stroke-width="1.8" />
                                    <!-- Falte / Schatten rechts -->
                                    <path d="M 65,30 Q 66,48 77,68 Q 67,55 66,30 Z" fill="url(#cookieCreaseGrad)" opacity="0.55" />
                                    <!-- Glanzlicht rechts -->
                                    <path d="M 93,32 C 99,42 99,56 93,64" stroke="#fef3c7" stroke-width="2.5" stroke-linecap="round" fill="none" opacity="0.75" />
                                </g>
                            </svg>
                        </div>
                    </div>
                    <div class="fortune-cookie-info">
                        <div class="fortune-cookie-title">Glückskeks des Tages</div>
                        <div class="fortune-cookie-desc js-cookie-desc">Knacke den Keks, um Konfuzius' Weisheit für heute zu enthüllen.</div>
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