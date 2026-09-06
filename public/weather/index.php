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
            <?php
                $m = (int)date('n');
                if ($m >= 3 && $m <= 5) $season = 'spring.jpeg';
                elseif ($m >= 6 && $m <= 8) $season = 'summer.jpeg';
                elseif ($m >= 9 && $m <= 11) $season = 'autmn.jpeg';
                else $season = 'winter.jpeg';
                $bgUrl = "../assets/weather/" . $season;

                $windSpeed = $forecast['current']['wind_speed_10m'] ?? 0;
                $rain = $forecast['current']['rain'] ?? 0;
                $showers = $forecast['current']['showers'] ?? 0;
                $precip = $forecast['current']['precipitation'] ?? 0;
                $isNight = isset($forecast['current']['is_day']) && $forecast['current']['is_day'] == 0;

                $isRaining = ($rain > 0 || $showers > 0 || $precip > 0.5);
                $isStorm = ($windSpeed > 30);

                if ($isStorm && $isRaining) {
                    $greeting = "Moin! Echtes Schietwetter heute, halt dich fest und bleib trocken!";
                } elseif ($isStorm) {
                    $greeting = "Moin! Pustet ordentlich da draußen, mach lieber die Fenster zu.";
                } elseif ($isRaining) {
                    $greeting = "Moin! Regenschirm aufspannen, von oben kommt ordentlich was runter.";
                } elseif ($isNight) {
                    $greeting = "Gute Nacht! Zeit zum Chillen, es ist dunkel.";
                } elseif ($currentTemp > 25) {
                    $greeting = "Moin! Pack die Badehose ein, feinstes Sommerwetter heute!";
                } else {
                    $greeting = "Moin! Ganz entspanntes Wetter heute in Leipzig-Holzhausen.";
                }
            ?>
            <div class="diorama-card">
                <!-- Die coole Sprachblase -->
                <div class="weather-speech-bubble">
                    <span class="weather-speech-icon">💬</span>
                    <p><strong>Hey!</strong> <?= $greeting ?></p>
                </div>

                <div class="diorama-container" style="position: relative; line-height: 0;">
                    <!-- Basis-Jahreszeiten-Bild -->
                    <img src="<?= $bgUrl ?>" alt="Jahreszeit Hintergrund" style="width: 100%; height: auto; display: block; object-fit: cover; aspect-ratio: 16/9;">
                    
                    <!-- Transparentes SVG-Overlay (Wetter-Effekte) -->
                    <svg viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" class="diorama-svg" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none;">
                        <?php if ($isNight): ?>
                            <!-- Nacht-Verdunkelung -->
                            <rect width="100%" height="100%" fill="#0a192f" opacity="0.45"/>
                            <!-- Mond (dynamische Mondphase aus Backend falls verfuegbar, hier Sichel simuliert) -->
                            <circle cx="85%" cy="15%" r="40" fill="#facc15" opacity="0.9"/>
                            <circle cx="83%" cy="13%" r="35" fill="#0a192f" opacity="0.7"/>
                        <?php endif; ?>
                        
                        <?php if ($isRaining): ?>
                            <!-- Regen (schraege Linien) -->
                            <g stroke="#60a5fa" stroke-width="2.5" opacity="0.6">
                                <line x1="200" y1="-50" x2="50" y2="950" />
                                <line x1="400" y1="-100" x2="250" y2="800" />
                                <line x1="600" y1="0" x2="450" y2="900" />
                                <line x1="800" y1="-200" x2="650" y2="700" />
                                <line x1="1000" y1="0" x2="850" y2="900" />
                                <line x1="1200" y1="-50" x2="1050" y2="850" />
                                <line x1="1400" y1="100" x2="1250" y2="1000" />
                                <line x1="1600" y1="0" x2="1450" y2="900" />
                                <line x1="1800" y1="-100" x2="1650" y2="800" />
                            </g>
                        <?php endif; ?>
                        
                        <?php if ($isStorm): ?>
                            <!-- Wind-Boen (geschwungene Linien im Himmel) -->
                            <g stroke="#e2e8f0" stroke-width="6" fill="none" opacity="0.4">
                                <path d="M -100 200 Q 200 100 400 250 T 900 150" />
                                <path d="M 300 350 Q 600 250 800 400 T 1400 300" />
                                <path d="M 800 100 Q 1100 50 1300 200 T 1800 100" />
                            </g>
                        <?php endif; ?>
                    </svg>
                </div>
            </div>

            <!-- Die 5 Entscheidungskriterien im modernen Grid-Layout -->
            <div class="weather-decision-grid">
                <?php 
                $icons = [
                    'umbrella' => '☔',
                    'jacket' => '🧥',
                    'winter' => '🧣',
                    'pool' => '🏊',
                    'watering' => '🌱'
                ];
                foreach ($eval as $key => $info): 
                    $activeClass = $info['status'] ? 'active-yes' : 'active-no';
                    $icon = $icons[$key] ?? '❓';
                ?>
                    <div class="weather-decision-card <?= $activeClass ?>">
                        <div class="decision-icon">
                            <?= $icon ?>
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
