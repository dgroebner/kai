<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\Weather\WeatherService;
use Kai\Tools\Weather\WeatherEvaluator;

Auth::requirePage();

$weatherService = new WeatherService();
$forecast = $weatherService->getCachedForecast();
$sensorData = $weatherService->getLatestSensorData();

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
    <style>
        .diorama-container {
            width: 100%; max-width: 600px; margin: 0 auto;
            border-radius: 12px; overflow: hidden;
            background: <?= $skyColor ?>;
            position: relative;
        }
        .weather-list {
            list-style: none; padding: 0; margin-top: 20px;
        }
        .weather-list li {
            padding: 10px; border-bottom: 1px solid #ddd;
        }
        .status-true { color: green; font-weight: bold; }
        .status-false { color: gray; }
    </style>
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
            <div class="diorama-container">
                <svg viewBox="0 0 600 400" width="100%" height="400">
                    <!-- Sky -->
                    <rect width="600" height="400" fill="<?= $skyColor ?>"/>
                    
                    <?php if ($currentWeatherCode <= 3): ?>
                        <!-- Sun -->
                        <circle cx="500" cy="80" r="40" fill="#FFD700" />
                    <?php endif; ?>
                    
                    <!-- Ground -->
                    <rect x="0" y="300" width="600" height="100" fill="#228B22"/>
                    
                    <!-- House -->
                    <rect x="50" y="150" width="150" height="150" fill="#F5DEB3"/>
                    <polygon points="50,150 125,70 200,150" fill="#A52A2A"/>
                    
                    <!-- Tree -->
                    <rect x="400" y="200" width="20" height="100" fill="#8B4513"/>
                    <circle cx="410" cy="180" r="60" fill="<?= $treeColor ?>"/>
                    
                    <!-- Dog -->
                    <rect x="250" y="270" width="40" height="30" fill="#8B4513" rx="10"/>
                    <circle cx="290" cy="265" r="15" fill="#8B4513"/>
                </svg>
            </div>
            
            <ul class="weather-list">
                <?php foreach ($eval as $key => $info): ?>
                    <li>
                        <span class="<?= $info['status'] ? 'status-true' : 'status-false' ?>">
                            <?= $info['status'] ? '✅' : '❌' ?>
                        </span>
                        <?= htmlspecialchars($info['text']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>
</div>
<script src="../js/http.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
