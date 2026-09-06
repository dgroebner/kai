<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\Weather\WeatherEvaluator;
use Kai\Tools\Weather\WeatherService;

Auth::requirePage();
try {
    $weatherService = new WeatherService();
    $forecast = $weatherService->getForecastFromDb();
    $sensorData = $weatherService->getLatestSensorData();
} catch (Exception $e) {
    $forecast = null;
    $sensorData = null;
}

$evaluator = new WeatherEvaluator();
$eval = [];
if ($forecast) {
    $eval = $evaluator->evaluate($forecast, $sensorData);
}

$currentTemp = $forecast['current']['temperature_2m'] ?? '--';
$currentWeatherCode = $forecast['current']['weather_code'] ?? 0;

$isWinter = date('n') >= 11 || date('n') <= 2;
$isSummer = date('n') >= 6 && date('n') <= 8;

$treeColor = $isWinter ? '#8B4513' : '#228B22';
$skyColor = ($currentWeatherCode <= 3) ? '#87CEEB' : '#A9A9A9';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= Auth::csrfToken() ?>">
    <title>Wetter Diorama</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <h1>Wetter Leipzig-Holzhausen</h1>
        <div class="page-header-actions">
            <a href="../index.php" class="btn btn-outline">Zurück</a>
        </div>
    </header>

    <main>
        <?php if (!$forecast): ?>
            <p>Fehler beim Laden der Wetterdaten.</p>
        <?php else: ?>
            <div class="diorama-card">
                <!-- Die fröhliche Sprachblase wie im Konzept -->
                <div class="weather-speech-bubble">
                    <span class="weather-speech-icon">🐶☀️</span>
                    <p><strong>Moin Leipzig-Holzhausen!</strong> Heute zeigt sich das Wetter von seiner besten Seite.
                    </p>
                </div>

                <div class="diorama-container" style="background: <?= $skyColor ?>;">
                    <svg viewBox="0 0 800 450" width="100%" height="auto" class="diorama-svg">
                        <!-- Definitionen für Farbverläufe und Schatten -->
                        <defs>
                            <linearGradient id="skyGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#38bdf8"/>
                                <stop offset="100%" stop-color="#bae6fd"/>
                            </linearGradient>
                            <linearGradient id="groundGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#4ade80"/>
                                <stop offset="100%" stop-color="#16a34a"/>
                            </linearGradient>
                            <filter id="softShadow" x="-10%" y="-10%" width="120%" height="120%">
                                <feDropShadow dx="0" dy="4" stdDeviation="4" flood-opacity="0.15"/>
                            </filter>
                        </defs>

                        <!-- 1. HIMMEL -->
                        <rect width="800" height="350" fill="url(#skyGrad)"/>

                        <?php if ($currentWeatherCode <= 3): ?>
                            <!-- Sonne mit Strahlen -->
                            <g transform="translate(700, 80)" filter="url(#softShadow)">
                                <circle r="35" fill="#facc15"/>
                                <!-- Sonnenstrahlen -->
                                <path d="M0-50 L0-42 M0 42 L0 50 M-50 0 L-42 0 M42 0 L50 0 M-35-35 L-29-29 M35 35 L29 29 M-35 35 L-29 29 M35-35 L29-29"
                                      stroke="#facc15" stroke-width="5" stroke-linecap="round"/>
                            </g>
                        <?php endif; ?>

                        <!-- 2. HINTERGRUND-HÜGEL & LANDSCHAFT -->
                        <path d="M0 300 Q 250 240 500 280 T 800 260 L 800 350 L 0 350 Z" fill="#22c55e" opacity="0.6"/>

                        <!-- 3. HAUS -->
                        <g transform="translate(80, 160)" filter="url(#softShadow)">
                            <!-- Haus-Wand -->
                            <rect x="0" y="60" width="180" height="130" fill="#f8fafc" rx="4"/>
                            <!-- Dach -->
                            <polygon points="-15,60 90,-15 195,60" fill="#dc2626"/>
                            <!-- Schornstein -->
                            <rect x="130" y="5" width="20" height="35" fill="#94a3b8"/>
                            <!-- Fenster -->
                            <rect x="30" y="85" width="40" height="45" fill="#38bdf8" rx="4"/>
                            <rect x="110" y="85" width="40" height="45" fill="#38bdf8" rx="4"/>
                            <!-- Tür -->
                            <rect x="70" y="135" width="40" height="55" fill="#78350f" rx="2"/>
                        </g>

                        <!-- 4. BAUM (Jahreszeiten-abhängig) -->
                        <g transform="translate(620, 150)" filter="url(#softShadow)">
                            <!-- Stamm -->
                            <rect x="-12" y="50" width="24" height="120" fill="#78350f" rx="4"/>
                            <!-- Krone -->
                            <?php if ($isWinter): ?>
                                <!-- Kahle Äste im Winter -->
                                <path d="M0 60 Q-40 20 -60 10 M-30 35 Q-10 10 -20 -15 M0 40 Q30 15 50 5 M20 30 Q10 0 30 -20"
                                      stroke="#78350f" stroke-width="6" fill="none" stroke-linecap="round"/>
                            <?php else: ?>
                                <!-- Saftige grüne Baumkrone -->
                                <circle cx="0" cy="10" r="65" fill="<?= $treeColor ?>"/>
                                <circle cx="-35" cy="30" r="45" fill="<?= $treeColor ?>"/>
                                <circle cx="35" cy="25" r="50" fill="<?= $treeColor ?>"/>
                            <?php endif; ?>
                        </g>

                        <!-- 5. VORDERGRUND & WIESE -->
                        <rect x="0" y="320" width="800" height="130" fill="url(#groundGrad)"/>

                        <!-- 6. DER BRAUNE LABRADOR (Maskottchen) -->
                        <g transform="translate(350, 270)" filter="url(#softShadow)">
                            <!-- Einfacher, aber niedlicher Comic-Labrador-Korpus -->
                            <ellipse cx="40" cy="40" rx="35" ry="22" fill="#92400e"/>
                            <circle cx="15" cy="25" r="18" fill="#92400e"/>
                            <!-- Schlappohren -->
                            <path d="M5 20 Q-5 30 5 45 Z" fill="#78350f"/>
                            <!-- Schnauze & Auge -->
                            <circle cx="8" cy="23" r="2" fill="#1e293b"/>
                            <ellipse cx="2" cy="28" rx="4" ry="3" fill="#451a03"/>
                            <!-- Rute -->
                            <path d="M70 35 Q90 20 85 10" stroke="#92400e" stroke-width="6" fill="none"
                                  stroke-linecap="round"/>
                            <!-- Pfoten -->
                            <rect x="20" y="55" width="8" height="15" fill="#78350f" rx="3"/>
                            <rect x="50" y="55" width="8" height="15" fill="#78350f" rx="3"/>
                        </g>
                    </svg>
                </div>
            </div>

            <!-- Die 5 Entscheidungskriterien im modernen Grid-Layout -->
            <div class="weather-decision-grid">
                <?php foreach ($eval as $key => $info): ?>
                    <div class="weather-decision-card <?= $info['status'] ? 'active-yes' : 'active-no' ?>">
                        <div class="decision-icon">
                            <?= $info['status'] ? '✅' : '❌' ?>
                        </div>
                        <div class="decision-text">
                            <?= htmlspecialchars($info['text']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<script src="../js/http.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
