<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\Weather\WeatherEvaluator;
use Kai\Tools\Weather\WeatherService;

Auth::requirePage('weather_read');
try {
    $weatherService = new WeatherService();
    $forecast = $weatherService->getForecastFromDb();

// --- Historische Wetterdaten ---
$historyFilter = $_GET['hist_filter'] ?? 'tag';
$validFilters = ['tag', 'letzter_tag', 'woche', 'monat'];
if (!in_array($historyFilter, $validFilters)) {
    $historyFilter = 'tag';
}
$histRows = $weatherService->getHistoricalHourlyData($historyFilter);

$chartLabels = [];
$chartTemp = [];
$chartPrecip = [];
$chartWind = [];
$chartHum = [];
$chartPress = [];
foreach ($histRows as $r) {
    $chartLabels[] = date('d.m. H:i', strtotime($r['forecast_time']));
    $chartTemp[] = (float)$r['temperature_2m'];
    $chartPrecip[] = (float)$r['precipitation'];
    $chartWind[] = (float)$r['wind_speed_10m'];
    $chartHum[] = (float)$r['relative_humidity_2m'];
    $chartPress[] = (float)$r['surface_pressure'];
}

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

function getWindDirectionText($deg) {
    if ($deg === null || $deg === '--') return '--';
    $val = intval(($deg / 22.5) + .5);
    $arr = ["N", "NNO", "NO", "ONO", "O", "OSO", "SO", "SSO", "S", "SSW", "SW", "WSW", "W", "WNW", "NW", "NNW"];
    return $arr[($val % 16)];
}

function getWeatherIconAndText($code, $isNight = false)
{
    if ($code === null || $code === '') return ['icon' => '❓', 'text' => 'Unbekannt'];
    $code = (int)$code;
    
    if ($isNight) {
        if ($code == 0) return ['icon' => '🌙', 'text' => 'Klar'];
        if ($code == 1 || $code == 2) return ['icon' => '🌙☁️', 'text' => 'Heiter / Wolkig'];
    } else {
        if ($code == 0) return ['icon' => '☀️', 'text' => 'Klar'];
        if ($code == 1 || $code == 2) return ['icon' => '⛅', 'text' => 'Heiter / Wolkig'];
    }
    
    if ($code == 3) return ['icon' => '☁️', 'text' => 'Bedeckt'];
    if ($code == 45 || $code == 48) return ['icon' => '🌫️', 'text' => 'Nebel'];
    if (in_array($code, [51, 53, 55])) return ['icon' => '🌧️', 'text' => 'Nieselregen'];
    if (in_array($code, [61, 63, 65])) return ['icon' => '🌧️', 'text' => 'Regen'];
    if (in_array($code, [71, 73, 75, 77])) return ['icon' => '❄️', 'text' => 'Schnee'];
    if (in_array($code, [80, 81, 82])) return ['icon' => '🌦️', 'text' => 'Regenschauer'];
    if (in_array($code, [85, 86])) return ['icon' => '🌨️', 'text' => 'Schneeschauer'];
    if (in_array($code, [95, 96, 99])) return ['icon' => '⛈️', 'text' => 'Gewitter'];
    return ['icon' => '❓', 'text' => 'Unbekannt'];
}


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
    <script src="../js/chart.min.js?v=<?= APP_VERSION ?>"></script>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <h1>Wetter Leipzig-Holzhausen</h1>
        <div class="page-header-actions">
            <?php
            $updatedAtStr = $forecast['current']['updated_at'] ?? null;
            if ($updatedAtStr):
                ?>
                <span class="last-update">Stand: <?= date('d.m.Y H:i', strtotime($updatedAtStr)) ?> Uhr</span>
            <?php endif; ?>
            <a href="../index.php" class="btn btn-outline">Zurück</a>
        </div>
    </header>

    <main>
                <div class="period-switcher" style="margin-bottom: 1.5rem; display: flex; gap: 0.5rem; justify-content: center;">
            <button class="btn" id="btn-tab-diorama" data-tab="diorama">Diorama</button>
            <button class="btn btn-outline" id="btn-tab-dashboard" data-tab="dashboard">Dashboard</button>
            <button class="btn btn-outline" id="btn-tab-history" data-tab="history">Historie</button>
        </div>
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

            $weatherCode = $forecast['current']['weather_code'] ?? 0;
            $cloudCover = $forecast['current']['cloud_cover'] ?? 0;
            $windSpeed = $forecast['current']['wind_speed_10m'] ?? 0;
            $windGusts = $forecast['current']['wind_gusts_10m'] ?? 0;
            $windDirection = $forecast['current']['wind_direction_10m'] ?? 270;
            $rain = $forecast['current']['rain'] ?? 0;
            $showers = $forecast['current']['showers'] ?? 0;
            $snowfall = $forecast['current']['snowfall'] ?? 0;
            $precip = $forecast['current']['precipitation'] ?? 0;

            $sunriseStr = $forecast['daily']['sunrise'][0] ?? date('Y-m-d 06:00:00');
            $sunsetStr = $forecast['daily']['sunset'][0] ?? date('Y-m-d 20:00:00');
            $sunrise = strtotime(is_array($sunriseStr) ? $sunriseStr[0] : $sunriseStr);
            $sunset = strtotime(is_array($sunsetStr) ? $sunsetStr[0] : $sunsetStr);
            $now = time();
            
            // Exakter Wechsel zwischen Tag und Nacht anhand der genauen Sonnenauf- und Untergangszeiten
            $isNight = ($now >= $sunset || $now < $sunrise);

            $isSnowing = ($snowfall > 0 || in_array($weatherCode, [71, 73, 75, 77, 85, 86]));
            $isRaining = (!$isSnowing && ($rain > 0 || $showers > 0 || $precip > 0.1 || in_array($weatherCode, [51, 53, 55, 61, 63, 65, 80, 81, 82])));

            // Regen-Intensität bestimmen (light, medium, heavy)
            $rainIntensity = 'light';
            if ($precip >= 3.0 || in_array($weatherCode, [65, 82])) {
                $rainIntensity = 'heavy';
            } elseif ($precip >= 1.0 || in_array($weatherCode, [55, 63, 81])) {
                $rainIntensity = 'medium';
            }

            // Schnee-Intensität bestimmen (light, medium, heavy)
            $snowIntensity = 'light';
            if ($snowfall >= 2.0 || in_array($weatherCode, [75, 86])) {
                $snowIntensity = 'heavy';
            } elseif ($snowfall >= 0.5 || in_array($weatherCode, [73])) {
                $snowIntensity = 'medium';
            }

            $isStorm = ($windSpeed > 30);
            $isFog = ($weatherCode == 45 || $weatherCode == 48);
            $isCloudy = ($cloudCover > 30 || in_array($weatherCode, [3, 45, 48, 51, 53, 55, 61, 63, 65, 80, 81, 82, 71, 73, 75, 77, 85, 86]));

            $isGoldenHour = false;
            if (abs($now - $sunrise) <= 3600 || abs($now - $sunset) <= 3600) {
                $isGoldenHour = true;
            }

            // Debug-Override: Nur bei weather_write-Recht aktiv; verändert keine Backend-Daten
            $hasDebugAccess = Auth::hasPermission('weather_write');
            if ($hasDebugAccess && isset($_GET['dbg'])) {
                if (isset($_GET['dbg_night']))      $isNight      = (bool)(int)$_GET['dbg_night'];
                if (isset($_GET['dbg_golden']))     $isGoldenHour = (bool)(int)$_GET['dbg_golden'];
                if (isset($_GET['dbg_cloud']))      $cloudCover   = max(0, min(100, (int)$_GET['dbg_cloud']));

                // Debug Regen (Aus, Leicht, Mäßig, Stark inkl. Abwärtskompatibilität)
                if (isset($_GET['dbg_rain'])) {
                    $rVal = (string)$_GET['dbg_rain'];
                    if ($rVal === 'heavy' || isset($_GET['dbg_heavyrain'])) {
                        $isRaining = true;
                        $isSnowing = false;
                        $rainIntensity = 'heavy';
                        $precip = 4.5;
                    } elseif ($rVal === 'medium' || $rVal === '1') {
                        $isRaining = true;
                        $isSnowing = false;
                        $rainIntensity = 'medium';
                        $precip = 2.0;
                    } elseif ($rVal === 'light') {
                        $isRaining = true;
                        $isSnowing = false;
                        $rainIntensity = 'light';
                        $precip = 0.6;
                    } elseif ($rVal === '0' || $rVal === '') {
                        $isRaining = false;
                    }
                } elseif (isset($_GET['dbg_heavyrain']) && (int)$_GET['dbg_heavyrain'] === 1) {
                    $isRaining = true;
                    $isSnowing = false;
                    $rainIntensity = 'heavy';
                    $precip = 4.5;
                }

                // Debug Schnee (Aus, Leicht, Mäßig, Stark)
                if (isset($_GET['dbg_snow'])) {
                    $sVal = (string)$_GET['dbg_snow'];
                    if ($sVal === 'heavy') {
                        $isSnowing = true;
                        $isRaining = false;
                        $snowIntensity = 'heavy';
                        $snowfall = 3.5;
                    } elseif ($sVal === 'medium' || $sVal === '1') {
                        $isSnowing = true;
                        $isRaining = false;
                        $snowIntensity = 'medium';
                        $snowfall = 1.2;
                    } elseif ($sVal === 'light') {
                        $isSnowing = true;
                        $isRaining = false;
                        $snowIntensity = 'light';
                        $snowfall = 0.3;
                    } elseif ($sVal === '0' || $sVal === '') {
                        $isSnowing = false;
                    }
                }

                if (isset($_GET['dbg_fog']))        $isFog        = (bool)(int)$_GET['dbg_fog'];
                if (isset($_GET['dbg_wind']))       $windSpeed    = max(0, min(120, (int)$_GET['dbg_wind']));
                if (isset($_GET['dbg_gusts']))      $windGusts    = max(0, min(150, (int)$_GET['dbg_gusts']));
                if (isset($_GET['dbg_winddir']))    $windDirection = max(0, min(360, (int)$_GET['dbg_winddir']));
                if (isset($_GET['dbg_moon']))       $moonPhase    = max(0.0, min(1.0, (float)$_GET['dbg_moon']));
                if (isset($_GET['dbg_season']))     {
                    $allowedSeasons = ['spring.jpeg', 'summer.jpeg', 'autmn.jpeg', 'winter.jpeg'];
                    $s = $_GET['dbg_season'];
                    if (in_array($s, $allowedSeasons, true)) { $bgUrl = "../assets/weather/$s"; }
                }
                // Abhaengige Berechnungen nach Override neu ermitteln
                $isStorm    = ($windSpeed > 30);
                $isNewMoon  = ($moonPhase < 0.03 || $moonPhase > 0.97);
                $isFullMoon = ($moonPhase >= 0.47 && $moonPhase <= 0.53);
                if ($moonPhase <= 0.5) {
                    $maskOffsetPx = -80 * ($moonPhase / 0.5);
                } else {
                    $maskOffsetPx = 80 * ((1.0 - $moonPhase) / 0.5);
                }
            }

            if ($isStorm && $isSnowing) {
                $greeting = "Moin! Echtes Schneegestöber heute, zieh dich warm an!";
            } elseif ($isStorm && $isRaining) {
                $greeting = "Moin! Echtes Schietwetter heute, halt dich fest und bleib trocken!";
            } elseif ($isStorm) {
                $greeting = "Moin! Pustet ordentlich da draußen, mach lieber die Fenster zu.";
            } elseif ($isSnowing) {
                $greeting = "Moin! Es schneit! Pack dich gut ein.";
            } elseif ($isRaining) {
                $greeting = "Moin! Regenschirm aufspannen, von oben kommt ordentlich was runter.";
            } elseif ($isFog) {
                $greeting = "Moin! Ziemlich neblig heute, fahr vorsichtig.";
            } elseif ($isNight) {
                $greeting = "Gute Nacht! Zeit zum Chillen, es ist dunkel.";
            } elseif ($currentTemp > 25) {
                $greeting = "Moin! Pack die Badehose ein, feinstes Sommerwetter heute!";
            } else {
                $greeting = "Moin! Ganz entspanntes Wetter heute in Leipzig-Holzhausen.";
            }
            ?>
            <div id="tab-diorama">
                <div class="diorama-card">
                    <!-- Die coole Sprachblase -->
                    <div class="weather-speech-bubble">
                        <span class="weather-speech-icon">💬</span>
                        <p><strong>Hey!</strong> <?= $greeting ?></p>
                        <?php if ($hasDebugAccess): ?>
                            <button class="diorama-debug-btn" id="diorama-debug-open" title="Diorama Debug-Panel">🔧</button>
                        <?php endif; ?>
                    </div>

                    <div class="diorama-container"
                         data-wind-speed="<?= (int)$windSpeed ?>"
                         data-wind-gusts="<?= (int)$windGusts ?>"
                         data-wind-dir="<?= (int)$windDirection ?>">
                        <!-- Basis-Jahreszeiten-Bild -->
                        <img src="<?= $bgUrl ?>" alt="Jahreszeit Hintergrund">

                        <!-- Transparentes SVG-Overlay (Wetter-Effekte) -->
                        <svg viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" class="diorama-svg">
                            <?php
                            $moonPhase = $forecast['current']['moon_phase'] ?? ($forecast['daily']['moon_phase'][0] ?? 0.5);
                            $isNewMoon = ($moonPhase < 0.03 || $moonPhase > 0.97);
                            $isFullMoon = ($moonPhase >= 0.47 && $moonPhase <= 0.53);
                            
                            if ($moonPhase <= 0.5) {
                                $maskOffsetPx = -80 * ($moonPhase / 0.5);
                            } else {
                                $maskOffsetPx = 80 * ((1.0 - $moonPhase) / 0.5);
                            }
                            ?>
                            <defs>
                                <linearGradient id="goldenGrad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#fb923c"/>
                                    <stop offset="50%" stop-color="#fcd34d"/>
                                    <stop offset="100%" stop-color="#f87171"/>
                                </linearGradient>
                                <?php if (!$isFullMoon && !$isNewMoon): ?>
                                <mask id="moonMask">
                                    <rect width="100%" height="100%" fill="white"/>
                                    <circle cx="calc(92% + <?= $maskOffsetPx ?>px)" cy="7%" r="40" fill="black"/>
                                </mask>
                                <?php endif; ?>
                            </defs>

                            <?php if ($isNight): ?>
                                <!-- Nacht-Verdunkelung (global) -->
                                <rect width="100%" height="100%" fill="#0a192f" opacity="0.45"/>
                            <?php endif; ?>

                            <?php if ($isGoldenHour && !$isNight): ?>
                                <!-- Daemmerung / Golden Hour (global) -->
                                <rect width="100%" height="100%" fill="url(#goldenGrad)" opacity="0.25"
                                      style="mix-blend-mode: overlay;"/>
                            <?php endif; ?>

                            <?php if ($isNight): ?>
                                <!-- Sterne (Funkeln) -->
                                <g fill="#ffffff">
                                    <?php
                                    // Bereich 1 (Links vom Dach, strikt begrenzt)
                                    // Wenn Wolken >= 50%, verdecken sie die linke Seite komplett
                                    if ($cloudCover < 50) {
                                        for($i=0; $i<10; $i++) {
                                            $x = rand(50, 560);
                                            $y = rand(30, 160);
                                            $r = rand(10, 20) / 10;
                                            $dur = rand(3, 7);
                                            $delay = rand(0, 5);
                                            echo "<circle cx=\"$x\" cy=\"$y\" r=\"$r\"><animate attributeName=\"opacity\" values=\"0.1;0.9;0.1\" dur=\"{$dur}s\" begin=\"{$delay}s\" repeatCount=\"indefinite\"/></circle>";
                                        }
                                    }
                                    // Bereich 2 (Rechts vom Dach, strikt begrenzt)
                                    // Wenn Wolken >= 100%, verdecken sie auch die rechte Seite
                                    if ($cloudCover < 95) {
                                        for($i=0; $i<10; $i++) {
                                            $x = rand(1180, 1450);
                                            $y = rand(30, 160);
                                            $r = rand(10, 20) / 10;
                                            $dur = rand(3, 7);
                                            $delay = rand(0, 5);
                                            echo "<circle cx=\"$x\" cy=\"$y\" r=\"$r\"><animate attributeName=\"opacity\" values=\"0.1;0.9;0.1\" dur=\"{$dur}s\" begin=\"{$delay}s\" repeatCount=\"indefinite\"/></circle>";
                                        }
                                    }
                                    ?>
                                </g>

                                <!-- Sternschnuppen (strikt in den 2 Bereichen, fliegen vom Dach weg) -->
                                <?php for ($s = 1; $s <= 2; $s++): 
                                    if ($s == 1) {
                                        if ($cloudCover >= 50) continue; // Links von Wolken verdeckt
                                        // Bereich 1 (Links) -> Fliegt immer nach LINKS, weg vom Dach
                                        $direction = -1;
                                        $startX = rand(400, 500);
                                        $dx = rand(150, 200) * $direction;
                                    } else {
                                        if ($cloudCover >= 95) continue; // Rechts von Wolken verdeckt
                                        // Bereich 2 (Rechts) -> Fliegt immer nach RECHTS, weg vom Dach
                                        $direction = 1;
                                        $startX = rand(1180, 1220);
                                        $dx = rand(150, 200) * $direction;
                                    }
                                    $startY = rand(20, 50);
                                    $dy = rand(60, 100); 
                                    
                                    $tailX = -($dx * 0.2);
                                    $tailY = -($dy * 0.2);
                                    
                                    $delay = rand(2, 12);
                                    $repeat = rand(15, 35);
                                ?>
                                <g opacity="0">
                                    <line x1="0" y1="0" x2="<?= $tailX ?>" y2="<?= $tailY ?>" stroke="#ffffff" stroke-width="2" stroke-linecap="round" opacity="0.6"/>
                                    <circle cx="0" cy="0" r="1.5" fill="#ffffff" />
                                    <animateTransform attributeName="transform" type="translate" from="<?= $startX ?> <?= $startY ?>" to="<?= $startX+$dx ?> <?= $startY+$dy ?>" dur="0.6s" begin="<?= $delay ?>s; shooting<?= $s ?>.end+<?= $repeat ?>s" id="shooting<?= $s ?>" />
                                    <animate attributeName="opacity" values="0; 1; 1; 0" keyTimes="0; 0.1; 0.7; 1" dur="0.6s" begin="shooting<?= $s ?>.begin" />
                                </g>
                                <?php endfor; ?>

                                <!-- Mond (Rechts) -->
                                <?php if (!$isNewMoon && $cloudCover < 90): ?>
                                <circle cx="92%" cy="7%" r="40" fill="#facc15" opacity="0.9" <?= (!$isFullMoon) ? 'mask="url(#moonMask)"' : '' ?>/>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- Sonne (Rechts) -->
                                <?php if ($cloudCover < 90): ?>
                                <g transform="translate(1472, 63)">
                                    <!-- Animierte Sonnenstrahlen (Pulsieren) -->
                                    <circle cx="0" cy="0" r="50" fill="#fef08a" opacity="0.4">
                                        <animate attributeName="r" values="50;55;50" dur="4s" repeatCount="indefinite" />
                                        <animate attributeName="opacity" values="0.4;0.2;0.4" dur="4s" repeatCount="indefinite" />
                                    </circle>
                                    <circle cx="0" cy="0" r="42" fill="#fde047" opacity="0.8">
                                        <animate attributeName="r" values="42;45;42" dur="3s" repeatCount="indefinite" />
                                    </circle>
                                    <!-- Harter Sonnenkern -->
                                    <circle cx="0" cy="0" r="35" fill="#eab308" />
                                </g>
                                <?php endif; ?>
                            <?php endif; ?>

                            <!-- WOLKEN (in sicheren Zonen platziert, abhängig von $cloudCover) -->
                            <?php if ($cloudCover > 0): 
                                $l = max(40, 90 - ($cloudCover * 0.5)); // 90 bis 40
                                $cColor = "hsl(215, 20%, {$l}%)";
                                
                                // Wie viele Wolken-Gruppen (0 bis 6)
                                $numClouds = floor(($cloudCover / 100) * 6);
                                if ($cloudCover > 0 && $numClouds == 0) $numClouds = 1;
                                
                                // Sichere Wolken-Positionen (überlagern nicht das Haus)
                                $cloudPositions = [
                                    ['x' => -20, 'y' => 10,  's' => 1.4], // Links aussen
                                    ['x' => 150, 'y' => 30,  's' => 1.2], // Links mitte
                                    ['x' => 320, 'y' => -10, 's' => 1.0], // Links ans Dach ran
                                    ['x' => 1080,'y' => 20,  's' => 1.0], // Rechts ans Dach ran
                                    ['x' => 1250,'y' => 0,   's' => 1.2], // Rechts mitte
                                    ['x' => 1420,'y' => 40,  's' => 1.3], // Rechts aussen
                                ];
                            ?>
                                <g fill="<?= $cColor ?>" opacity="0.85">
                                    <?php for($c = 0; $c < $numClouds && $c < count($cloudPositions); $c++): 
                                        $pos = $cloudPositions[$c];
                                    ?>
                                        <g transform="translate(<?= $pos['x'] ?>, <?= $pos['y'] ?>) scale(<?= $pos['s'] ?>)">
                                            <circle cx="100" cy="80" r="40"/>
                                            <circle cx="150" cy="50" r="60"/>
                                            <circle cx="210" cy="70" r="50"/>
                                            <rect x="80" y="50" width="150" height="70" rx="35"/>
                                        </g>
                                    <?php endfor; ?>
                                </g>
                            <?php endif; ?>

                            <?php if ($isFog): ?>
                                <!-- Nebel (grauer milchiger Schleier) -->
                                <rect width="100%" height="100%" fill="#cbd5e1" opacity="0.35"/>
                            <?php endif; ?>

                            <?php if ($isRaining): ?>
                                <!-- Mehrschichtiger, nahtlos animierter Regen mit Tiefenstaffelung -->
                                <?php
                                if ($rainIntensity === 'heavy') {
                                    $bgCount = 90;
                                    $fgCount = 70;
                                    $bgLenMin = 45; $bgLenMax = 70;
                                    $fgLenMin = 75; $fgLenMax = 110;
                                    $bgStroke = 1.4;
                                    $fgStroke = 2.4;
                                    $bgOpacity = 0.55;
                                    $fgOpacity = 0.85;
                                    $durBg = '0.45s';
                                    $durFg = '0.34s';
                                } elseif ($rainIntensity === 'medium') {
                                    $bgCount = 55;
                                    $fgCount = 45;
                                    $bgLenMin = 30; $bgLenMax = 50;
                                    $fgLenMin = 50; $fgLenMax = 75;
                                    $bgStroke = 1.2;
                                    $fgStroke = 1.9;
                                    $bgOpacity = 0.45;
                                    $fgOpacity = 0.75;
                                    $durBg = '0.62s';
                                    $durFg = '0.48s';
                                } else { // light
                                    $bgCount = 32;
                                    $fgCount = 24;
                                    $bgLenMin = 20; $bgLenMax = 35;
                                    $fgLenMin = 35; $fgLenMax = 50;
                                    $bgStroke = 1.0;
                                    $fgStroke = 1.5;
                                    $bgOpacity = 0.35;
                                    $fgOpacity = 0.6;
                                    $durBg = '0.85s';
                                    $durFg = '0.65s';
                                }
                                ?>
                                <g class="rain-layer" style="--rain-fall-dur-bg: <?= $durBg ?>; --rain-fall-dur-fg: <?= $durFg ?>;">
                                    <!-- Hintergrund-Regen: feiner, dezent, etwas langsamer -->
                                    <g class="diorama-rain-bg" stroke="#93c5fd" stroke-width="<?= $bgStroke ?>" stroke-linecap="round" opacity="<?= $bgOpacity ?>">
                                        <?php
                                        mt_srand(42);
                                        for ($i = 0; $i < $bgCount; $i++):
                                            $rx = mt_rand(-400, 2000);
                                            $ry = mt_rand(0, 899);
                                            $rlen = mt_rand($bgLenMin, $bgLenMax);
                                        ?>
                                            <line x1="<?= $rx ?>" y1="<?= $ry ?>" x2="<?= $rx ?>" y2="<?= $ry + $rlen ?>"/>
                                            <line x1="<?= $rx ?>" y1="<?= $ry + 900 ?>" x2="<?= $rx ?>" y2="<?= $ry + 900 + $rlen ?>"/>
                                        <?php endfor; ?>
                                    </g>

                                    <!-- Vordergrund-Regen: längere, prägnantere Schlieren, schneller -->
                                    <g class="diorama-rain-fg" stroke="#bfdbfe" stroke-width="<?= $fgStroke ?>" stroke-linecap="round" opacity="<?= $fgOpacity ?>">
                                        <?php
                                        mt_srand(1337);
                                        for ($i = 0; $i < $fgCount; $i++):
                                            $rx = mt_rand(-400, 2000);
                                            $ry = mt_rand(0, 899);
                                            $rlen = mt_rand($fgLenMin, $fgLenMax);
                                        ?>
                                            <line x1="<?= $rx ?>" y1="<?= $ry ?>" x2="<?= $rx ?>" y2="<?= $ry + $rlen ?>"/>
                                            <line x1="<?= $rx ?>" y1="<?= $ry + 900 ?>" x2="<?= $rx ?>" y2="<?= $ry + 900 + $rlen ?>"/>
                                        <?php endfor; ?>
                                    </g>

                                    <?php if ($rainIntensity === 'heavy'): ?>
                                        <!-- Bodenspritzer bei Starkregen -->
                                        <g>
                                            <?php
                                            mt_srand(2024);
                                            for ($s = 0; $s < 12; $s++):
                                                $sx = mt_rand(100, 1500);
                                                $sy = mt_rand(800, 875);
                                                $sDel = ($s * 0.08);
                                                $sDur = 0.45;
                                            ?>
                                                <ellipse cx="<?= $sx ?>" cy="<?= $sy ?>" rx="1" ry="0.5" fill="none" stroke="#bfdbfe" stroke-width="1.2" opacity="0">
                                                    <animate attributeName="rx" values="1;9;15" dur="<?= $sDur ?>s" begin="<?= $sDel ?>s" repeatCount="indefinite" />
                                                    <animate attributeName="ry" values="0.5;3;5" dur="<?= $sDur ?>s" begin="<?= $sDel ?>s" repeatCount="indefinite" />
                                                    <animate attributeName="opacity" values="0;0.75;0" dur="<?= $sDur ?>s" begin="<?= $sDel ?>s" repeatCount="indefinite" />
                                                </ellipse>
                                            <?php endfor; ?>
                                        </g>
                                    <?php endif; ?>
                                </g>
                            <?php endif; ?>

                            <?php if ($isSnowing): ?>
                                <!-- Mehrschichtiger, sanft taumelnder Schneefall -->
                                <?php
                                if ($snowIntensity === 'heavy') {
                                    $bgSnowCount = 65;
                                    $fgSnowCount = 50;
                                    $durBg = ($isStorm) ? '4.0s' : '5.5s';
                                    $durFg = ($isStorm) ? '2.8s' : '3.8s';
                                } elseif ($snowIntensity === 'medium') {
                                    $bgSnowCount = 40;
                                    $fgSnowCount = 30;
                                    $durBg = ($isStorm) ? '5.2s' : '7.0s';
                                    $durFg = ($isStorm) ? '3.8s' : '5.0s';
                                } else { // light
                                    $bgSnowCount = 20;
                                    $fgSnowCount = 15;
                                    $durBg = '8.5s';
                                    $durFg = '6.2s';
                                }
                                ?>
                                <g class="snow-layer" style="--snow-fall-dur-bg: <?= $durBg ?>; --snow-fall-dur-fg: <?= $durFg ?>;">
                                    <!-- Hintergrund-Schnee: kleinere Flocken, dezent, langsamer Fall -->
                                    <g class="diorama-snow-bg" fill="#e2e8f0" opacity="0.65">
                                        <g class="diorama-snow-sway-1">
                                            <?php
                                            mt_srand(777);
                                            for ($i = 0; $i < (int)($bgSnowCount * 0.5); $i++):
                                                $cx = mt_rand(-300, 1900);
                                                $cy = mt_rand(0, 899);
                                                $r = mt_rand(15, 28) / 10;
                                            ?>
                                                <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>"/>
                                                <circle cx="<?= $cx ?>" cy="<?= $cy + 900 ?>" r="<?= $r ?>"/>
                                            <?php endfor; ?>
                                        </g>
                                        <g class="diorama-snow-sway-2">
                                            <?php
                                            for ($i = 0; $i < (int)($bgSnowCount * 0.5); $i++):
                                                $cx = mt_rand(-300, 1900);
                                                $cy = mt_rand(0, 899);
                                                $r = mt_rand(15, 28) / 10;
                                            ?>
                                                <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>"/>
                                                <circle cx="<?= $cx ?>" cy="<?= $cy + 900 ?>" r="<?= $r ?>"/>
                                            <?php endfor; ?>
                                        </g>
                                    </g>

                                    <!-- Vordergrund-Schnee: größere, flauschige Flocken mit Taumelbewegung -->
                                    <g class="diorama-snow-fg" fill="#ffffff" opacity="0.9">
                                        <g class="diorama-snow-sway-2">
                                            <?php
                                            mt_srand(999);
                                            for ($i = 0; $i < (int)($fgSnowCount * 0.5); $i++):
                                                $cx = mt_rand(-300, 1900);
                                                $cy = mt_rand(0, 899);
                                                $r = ($snowIntensity === 'heavy') ? mt_rand(30, 55) / 10 : mt_rand(25, 45) / 10;
                                            ?>
                                                <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>"/>
                                                <circle cx="<?= $cx ?>" cy="<?= $cy + 900 ?>" r="<?= $r ?>"/>
                                            <?php endfor; ?>
                                        </g>
                                        <g class="diorama-snow-sway-3">
                                            <?php
                                            for ($i = 0; $i < (int)($fgSnowCount * 0.5); $i++):
                                                $cx = mt_rand(-300, 1900);
                                                $cy = mt_rand(0, 899);
                                                $r = ($snowIntensity === 'heavy') ? mt_rand(32, 58) / 10 : mt_rand(26, 48) / 10;
                                            ?>
                                                <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>"/>
                                                <circle cx="<?= $cx ?>" cy="<?= $cy + 900 ?>" r="<?= $r ?>"/>
                                            <?php endfor; ?>
                                        </g>
                                    </g>
                                </g>
                            <?php endif; ?>

                            <?php if ($windSpeed >= 15): ?>
                                <!-- Wind-Schlieren-Layer (Layer 2): Sichtbarkeit und Böen werden durch JS gesteuert -->
                                <g id="wind-gust-layer">
                                    <defs>
                                        <linearGradient id="windGradL" x1="0%" y1="0%" x2="100%" y2="0%">
                                            <stop offset="0%" stop-color="#e2e8f0" stop-opacity="0"/>
                                            <stop offset="40%" stop-color="#e2e8f0" stop-opacity="0.55"/>
                                            <stop offset="100%" stop-color="#e2e8f0" stop-opacity="0"/>
                                        </linearGradient>
                                        <linearGradient id="windGradR" x1="0%" y1="0%" x2="100%" y2="0%">
                                            <stop offset="0%" stop-color="#e2e8f0" stop-opacity="0"/>
                                            <stop offset="60%" stop-color="#e2e8f0" stop-opacity="0.4"/>
                                            <stop offset="100%" stop-color="#e2e8f0" stop-opacity="0"/>
                                        </linearGradient>
                                    </defs>
                                    <!-- Schliere 1: breite Hauptströmung quer durch den Garten -->
                                    <path d="M -100 480 Q 300 430 600 490 T 1200 440 T 1700 460"
                                          stroke="url(#windGradL)" stroke-width="14" fill="none" stroke-linecap="round"/>
                                    <!-- Schliere 2: etwas höher, schlanker -->
                                    <path d="M -100 350 Q 250 310 550 370 T 1100 320 T 1700 355"
                                          stroke="url(#windGradL)" stroke-width="8" fill="none" stroke-linecap="round"/>
                                    <!-- Schliere 3: Bodennahe Strömung -->
                                    <path d="M -100 620 Q 400 580 700 630 T 1300 590 T 1700 615"
                                          stroke="url(#windGradL)" stroke-width="10" fill="none" stroke-linecap="round"/>
                                    <!-- Schliere 4: leichte obere Brise -->
                                    <path d="M -100 250 Q 350 220 650 270 T 1250 230 T 1700 255"
                                          stroke="url(#windGradR)" stroke-width="6" fill="none" stroke-linecap="round"/>
                                    <!-- Schliere 5: tiefer Bodenwirbel -->
                                    <path d="M -100 730 Q 300 700 600 740 T 1200 710 T 1700 730"
                                          stroke="url(#windGradR)" stroke-width="7" fill="none" stroke-linecap="round"/>
                                </g>
                            <?php endif; ?>
                            
                            <?php if (!$isNight && !$isWinter && !$isRaining && !$isSnowing && !$isStorm): ?>
                                <!-- Bienen im Vordergrund -->
                                <g>
                                    <?php 
                                    // 8 Bienen schwirren um die Blumen (unten links und unten rechts)
                                    for($b = 0; $b < 8; $b++): 
                                        if (rand(0, 1)) {
                                            $baseX = rand(50, 300); // Pool / Blumen links
                                        } else {
                                            $baseX = rand(1000, 1500); // Grill / Baum rechts
                                        }
                                        $baseY = rand(780, 880);
                                        $dur = rand(40, 90) / 10; // 4.0s bis 9.0s
                                        
                                        $points = [];
                                        for($p = 0; $p < 5; $p++) {
                                            $px = $baseX + rand(-60, 60);
                                            $py = $baseY + rand(-40, 40);
                                            $points[] = "$px $py";
                                        }
                                        $points[] = $points[0]; // Loop schliessen
                                    ?>
                                        <g>
                                            <animateTransform attributeName="transform" type="translate" values="<?= implode(';', $points) ?>" dur="<?= $dur ?>s" repeatCount="indefinite" />
                                            <!-- Bienenkörper -->
                                            <ellipse cx="0" cy="0" rx="3.5" ry="2.5" fill="#facc15" />
                                            <!-- Schwarzer Streifen -->
                                            <rect x="-1" y="-2.5" width="2" height="5" fill="#1f2937" />
                                        </g>
                                    <?php endfor; ?>
                                </g>
                            <?php endif; ?>
                        </svg>
                    </div>
                </div>

                <?php if ($hasDebugAccess): ?>
                <!-- Debug-Panel-Modal (nur für weather_write sichtbar) -->
                <div id="diorama-debug-modal" class="diorama-debug-overlay" hidden>
                    <div class="diorama-debug-panel">
                        <div class="diorama-debug-header">
                            <span>🔧 Diorama Debug-Panel</span>
                            <button class="diorama-debug-close" id="diorama-debug-close">✕</button>
                        </div>
                        <form id="diorama-debug-form" method="get" action="">
                            <input type="hidden" name="dbg" value="1">
                            <div class="diorama-debug-body">

                                <div class="diorama-debug-section">Jahreszeit &amp; Licht</div>

                                <label class="diorama-debug-row">
                                    <span>Jahreszeit</span>
                                    <select name="dbg_season">
                                        <option value="">– Live –</option>
                                        <option value="spring.jpeg" <?= (strpos($bgUrl,'spring')!==false) ? 'selected' : '' ?>>Frühling</option>
                                        <option value="summer.jpeg" <?= (strpos($bgUrl,'summer')!==false) ? 'selected' : '' ?>>Sommer</option>
                                        <option value="autmn.jpeg"  <?= (strpos($bgUrl,'autmn')!==false)  ? 'selected' : '' ?>>Herbst</option>
                                        <option value="winter.jpeg" <?= (strpos($bgUrl,'winter')!==false) ? 'selected' : '' ?>>Winter</option>
                                    </select>
                                </label>

                                <label class="diorama-debug-row">
                                    <span>Nacht</span>
                                    <input type="checkbox" name="dbg_night" value="1" <?= $isNight ? 'checked' : '' ?>>
                                </label>

                                <label class="diorama-debug-row">
                                    <span>Goldene Stunde</span>
                                    <input type="checkbox" name="dbg_golden" value="1" <?= $isGoldenHour ? 'checked' : '' ?>>
                                </label>

                                <label class="diorama-debug-row">
                                    <span>Mondphase <span id="dbg-moon-val"><?= number_format($moonPhase, 2) ?></span></span>
                                    <input type="range" name="dbg_moon" min="0" max="1" step="0.01"
                                           value="<?= htmlspecialchars((string)$moonPhase, ENT_QUOTES, 'UTF-8') ?>"
                                           data-output="dbg-moon-val">
                                </label>

                                <div class="diorama-debug-section">Bewölkung &amp; Niederschlag</div>

                                <label class="diorama-debug-row">
                                    <span>Bedeckung <span id="dbg-cloud-val"><?= (int)$cloudCover ?>%</span></span>
                                    <input type="range" name="dbg_cloud" min="0" max="100" step="5"
                                           value="<?= (int)$cloudCover ?>" data-output="dbg-cloud-val" data-suffix="%">
                                </label>

                                <label class="diorama-debug-row">
                                    <span>Regen</span>
                                    <select name="dbg_rain">
                                        <option value="0" <?= !$isRaining ? 'selected' : '' ?>>– Kein Regen –</option>
                                        <option value="light" <?= ($isRaining && $rainIntensity === 'light') ? 'selected' : '' ?>>Leicht (Niesel)</option>
                                        <option value="medium" <?= ($isRaining && $rainIntensity === 'medium') ? 'selected' : '' ?>>Mäßig</option>
                                        <option value="heavy" <?= ($isRaining && $rainIntensity === 'heavy') ? 'selected' : '' ?>>Stark (Wolkenbruch)</option>
                                    </select>
                                </label>

                                <label class="diorama-debug-row">
                                    <span>Schnee</span>
                                    <select name="dbg_snow">
                                        <option value="0" <?= !$isSnowing ? 'selected' : '' ?>>– Kein Schnee –</option>
                                        <option value="light" <?= ($isSnowing && $snowIntensity === 'light') ? 'selected' : '' ?>>Leicht</option>
                                        <option value="medium" <?= ($isSnowing && $snowIntensity === 'medium') ? 'selected' : '' ?>>Mäßig</option>
                                        <option value="heavy" <?= ($isSnowing && $snowIntensity === 'heavy') ? 'selected' : '' ?>>Stark (Schneegestöber)</option>
                                    </select>
                                </label>

                                <label class="diorama-debug-row">
                                    <span>Nebel</span>
                                    <input type="checkbox" name="dbg_fog" value="1" <?= $isFog ? 'checked' : '' ?>>
                                </label>

                                <div class="diorama-debug-section">Wind</div>

                                <label class="diorama-debug-row">
                                    <span>Windgeschwindigkeit <span id="dbg-wind-val"><?= (int)$windSpeed ?> km/h</span></span>
                                    <input type="range" name="dbg_wind" min="0" max="120" step="1"
                                           value="<?= (int)$windSpeed ?>" data-output="dbg-wind-val" data-suffix=" km/h">
                                </label>

                                <label class="diorama-debug-row">
                                    <span>Böen <span id="dbg-gusts-val"><?= (int)$windGusts ?> km/h</span></span>
                                    <input type="range" name="dbg_gusts" min="0" max="150" step="1"
                                           value="<?= (int)$windGusts ?>" data-output="dbg-gusts-val" data-suffix=" km/h">
                                </label>

                                <label class="diorama-debug-row">
                                    <span>Windrichtung <span id="dbg-winddir-val"><?= (int)$windDirection ?>°</span></span>
                                    <input type="range" name="dbg_winddir" min="0" max="360" step="5"
                                           value="<?= (int)$windDirection ?>" data-output="dbg-winddir-val" data-suffix="°">
                                </label>

                            </div>
                            <div class="diorama-debug-footer">
                                <button type="submit" class="btn">Anwenden (Reload)</button>
                                <a href="?" class="btn btn-outline">Zurücksetzen</a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Die 5 Entscheidungskriterien im modernen Grid-Layout -->
                <div class="weather-decision-grid">
                    <?php
                    $icons = [
                            'umbrella' => '☔',
                            'jacket' => '🧥',
                            'winter' => '🧣',
                            'pool' => '🏊',
                            'watering' => '🌱',
                            'laundry' => '👕'
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
            </div> <!-- End tab-diorama -->

            <div id="tab-dashboard" class="hidden">
                <!-- A. Aktuelle Wetterlage -->
                <h3 style="margin-bottom: 1rem;">Aktuelle Wetterlage</h3>
                <div class="kpi-grid" style="margin-bottom: 2rem;">
                    <?php
                    $currTemp = $forecast['current']['temperature_2m'] ?? '--';
                    $appTemp = $forecast['current']['apparent_temperature'] ?? '--';
                    $currCode = $forecast['current']['weather_code'] ?? -1;
                    $currIconText = getWeatherIconAndText($currCode, $isNight);
                    $currIcon = $currIconText['icon'];
                    $currText = $currIconText['text'];
                    
                    $currPrecip = $forecast['current']['precipitation'] ?? 0;
                    $nextHourProb = 0;
                    $nowTime = time();
                    if (!empty($forecast['hourly']['time'])) {
                        foreach ($forecast['hourly']['time'] as $i => $timeStr) {
                            $t = strtotime($timeStr);
                            if ($t >= $nowTime) {
                                $nextHourProb = $forecast['hourly']['precipitation_probability'][$i] ?? 0;
                                break;
                            }
                        }
                    }

                    $currWind = $forecast['current']['wind_speed_10m'] ?? 0;
                    $currGusts = $forecast['current']['wind_gusts_10m'] ?? 0;
                    $currDir = $forecast['current']['wind_direction_10m'] ?? 0;

                    $currHum = $forecast['current']['relative_humidity_2m'] ?? '--';
                    $currPress = $forecast['current']['surface_pressure'] ?? '--';
                    $currCloud = $forecast['current']['cloud_cover'] ?? '--';

                    // Farben fuer die KPIs berechnen
                    $tempColor = 'var(--text-main)';
                    if ($currTemp < 5) $tempColor = '#3b82f6'; // Blau fuer Kalt
                    elseif ($currTemp >= 25) $tempColor = 'var(--color-red, #ef4444)'; // Rot fuer Heiss
                    elseif ($currTemp >= 20) $tempColor = 'var(--color-orange, #f97316)'; // Orange fuer Warm
                    elseif ($currTemp >= 10) $tempColor = 'var(--color-green, #22c55e)'; // Gruen fuer Angenehm

                    $precipColor = 'var(--text-main)';
                    if ($currPrecip > 2.0) $precipColor = 'var(--color-blue, #3b82f6)';
                    elseif ($currPrecip > 0) $precipColor = '#60a5fa'; // Helles Blau

                    $windColor = 'var(--text-main)';
                    if ($currWind > 50) $windColor = 'var(--color-red, #ef4444)'; // Sturm
                    elseif ($currWind > 30) $windColor = 'var(--color-orange, #f97316)'; // Windig

                    $humColor = 'var(--text-main)';
                    if ($currHum > 70) $humColor = 'var(--color-blue, #3b82f6)'; // Sehr feucht
                    elseif ($currHum < 30) $humColor = 'var(--color-orange, #f97316)'; // Sehr trocken
                    ?>
                                        <div class="kpi-card">
                        <div class="kpi-label">Temperatur & Zustand</div>
                        <div class="kpi-value-sm" style="color: <?= $tempColor ?>;"><?= number_format((float)$currTemp, 1, ',', '.') ?> &deg;C</div>
                        <div class="kpi-subtext"><?= $currIconText['icon'] ?> <?= $currIconText['text'] ?><br>🌡️ Gefühlt: <?= number_format((float)$appTemp, 1, ',', '.') ?> &deg;C</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">Niederschlag</div>
                        <div class="kpi-value-sm" style="color: <?= $precipColor ?>;"><?= number_format((float)$currPrecip, 1, ',', '.') ?> mm</div>
                        <div class="kpi-subtext">☔ Regenrisiko (1h): <?= $nextHourProb ?> %</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">Wind</div>
                        <div class="kpi-value-sm" style="color: <?= $windColor ?>;"><?= number_format((float)$currWind, 1, ',', '.') ?> km/h</div>
                        <div class="kpi-subtext">💨 Böen: <?= number_format((float)$currGusts, 1, ',', '.') ?> km/h<br>🧭 Richtung: <?= getWindDirectionText($currDir) ?></div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">Rel. Luftfeuchtigkeit</div>
                        <div class="kpi-value-sm" style="color: <?= $humColor ?>;"><?= $currHum ?> %</div>
                        <div class="kpi-subtext">⏬ Druck: <?= $currPress ?> hPa<br>☁️ Wolken: <?= $currCloud ?> %</div>
                    </div>
                </div>

                <!-- B. 12-Stunden-Prognose -->
                <h3 style="margin-bottom: 1rem;">12-Stunden-Prognose</h3>
                <div class="hourly-forecast-container">
                    <?php
                    $count = 0;
                    if (!empty($forecast['hourly']['time'])) {
                        foreach ($forecast['hourly']['time'] as $i => $timeStr) {
                            $t = strtotime($timeStr);
                            if ($t >= $nowTime - 3600 && $count < 12) {
                                $hCode = $forecast['hourly']['weather_code'][$i] ?? -1;
                                
                                // Tag/Nacht für die stündliche Prognose ermitteln
                                $hIsNight = false;
                                $dateStr = date('Y-m-d', $t);
                                $dIdx = array_search($dateStr, $forecast['daily']['time'] ?? []);
                                if ($dIdx !== false && !empty($forecast['daily']['sunset'][$dIdx]) && !empty($forecast['daily']['sunrise'][$dIdx])) {
                                    $hSunset = strtotime($forecast['daily']['sunset'][$dIdx]);
                                    $hSunrise = strtotime($forecast['daily']['sunrise'][$dIdx]);
                                    $hIsNight = ($t >= $hSunset || $t < $hSunrise);
                                } else {
                                    $hour = (int)date('H', $t);
                                    $hIsNight = ($hour >= 20 || $hour < 6); // Fallback
                                }
                                
                                $hIcon = getWeatherIconAndText($hCode, $hIsNight)['icon'];
                                $hTemp = number_format((float)($forecast['hourly']['temperature_2m'][$i] ?? 0), 1, ',', '.');
                                $hProb = $forecast['hourly']['precipitation_probability'][$i] ?? 0;
                                $hPrecip = $forecast['hourly']['precipitation'][$i] ?? 0;
                                $hWind = $forecast['hourly']['wind_speed_10m'][$i] ?? 0;
                                ?>
                                <div class="hourly-card">
                                    <div class="hourly-time"><?= date('H:i', $t) ?></div>
                                    <div class="hourly-icon"><?= $hIcon ?></div>
                                    <div class="hourly-temp"><?= $hTemp ?>&deg;</div>
                                    <div class="hourly-detail">
                                        <?php if ($hProb > 0): ?>
                                            <span style="color: var(--color-blue);">💧 <?= $hProb ?>% (<?= $hPrecip ?>mm)</span>
                                        <?php else: ?>
                                            <span style="opacity: 0.5;">💧 0%</span>
                                        <?php endif; ?>
                                        <span>💨 <?= $hWind ?> km/h</span>
                                    </div>
                                </div>
                                <?php
                                $count++;
                            }
                        }
                    }
                    ?>
                </div>

                <!-- C. 7-Tage-Trend -->
                <h3 style="margin-bottom: 1rem;">7-Tage-Trend</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Wetter</th>
                            <th>Temperatur</th>
                            <th>Niederschlag</th>
                            <th>Sonne</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php
                        if (!empty($forecast['daily']['time'])) {
                            $wdays = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
                            foreach ($forecast['daily']['time'] as $i => $dateStr) {
                                if (!$dateStr) continue;
                                $t = strtotime($dateStr);
                                $wday = $wdays[date('w', $t)];
                                $dCode = $forecast['daily']['weather_code'][$i] ?? null;

                                if ($dCode === null) {
                                    // Fallback for missing future days
                                    echo "<tr><td>{$wday} " . date('d.m.', $t) . "</td><td colspan='4' style='opacity: 0.5; text-align: center;'>-- Keine Daten --</td></tr>";
                                    continue;
                                }

                                $dIconText = getWeatherIconAndText($dCode);
                                $tMin = $forecast['daily']['temperature_2m_min'][$i] ?? '--';
                                $tMax = $forecast['daily']['temperature_2m_max'][$i] ?? '--';
                                $dProb = $forecast['daily']['precipitation_probability_max'][$i] ?? 0;
                                $dPrecip = $forecast['daily']['precipitation_sum'][$i] ?? 0;

                                $sunshineSeconds = $forecast['daily']['sunshine_duration'][$i] ?? 0;
                                $sunHours = ($sunshineSeconds > 0) ? round($sunshineSeconds / 3600, 1) : 0;

                                $sunriseT = strtotime($forecast['daily']['sunrise'][$i] ?? '');
                                $sunsetT = strtotime($forecast['daily']['sunset'][$i] ?? '');
                                $sunriseStr = $sunriseT ? date('H:i', $sunriseT) : '--';
                                $sunsetStr = $sunsetT ? date('H:i', $sunsetT) : '--';

                                ?>
                                <tr>
                                    <td><strong><?= $wday ?></strong><br><small
                                                class="text-muted"><?= date('d.m.', $t) ?></small></td>
                                    <td>
                                        <span style="font-size: 1.5rem; vertical-align: middle; margin-right: 0.5rem;"><?= $dIconText['icon'] ?></span> <?= $dIconText['text'] ?>
                                    </td>
                                    <td>
                                        <?= number_format((float)$tMin, 1, ',', '.') ?> &deg;C <br>
                                        <strong style="color: var(--color-orange);"><?= number_format((float)$tMax, 1, ',', '.') ?>
                                            &deg;C</strong>
                                    </td>
                                    <td><?= $dProb ?>% Risiko<br><small class="text-muted"><?= $dPrecip ?> mm</small>
                                    </td>
                                    <td><?= $sunHours ?> h<br><small class="text-muted">🌅 <?= $sunriseStr ?>
                                            🌇 <?= $sunsetStr ?></small></td>
                                </tr>
                                <?php
                            }
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div> <!-- End tab-dashboard -->

            <div id="tab-history" class="hidden">
                <div class="chart-section u-mt-lg" id="history-section">
                    <h3 style="margin-bottom: 1rem;">Historische Wetterdaten</h3>
                    
                    <!-- Zeit-Filterbuttons -->
                    <div class="period-switcher" style="margin-bottom: 1.5rem;">
                        <a href="?tab=history&hist_filter=tag#history-section" class="btn <?= $historyFilter === 'tag' ? '' : 'btn-outline' ?>">Heute</a>
                        <a href="?tab=history&hist_filter=letzter_tag#history-section" class="btn <?= $historyFilter === 'letzter_tag' ? '' : 'btn-outline' ?>">Letzter Tag</a>
                        <a href="?tab=history&hist_filter=woche#history-section" class="btn <?= $historyFilter === 'woche' ? '' : 'btn-outline' ?>">Letzte 7 Tage</a>
                        <a href="?tab=history&hist_filter=monat#history-section" class="btn <?= $historyFilter === 'monat' ? '' : 'btn-outline' ?>">Letzte 30 Tage</a>
                    </div>
                    
                    <!-- Grafische Chart-Auswertung -->
                    <?php if (!empty($histRows)): ?>
                        <div class="card u-mb-lg" style="padding: 1rem 1.5rem;">
                            <div style="position: relative; height:320px; width:100%;">
                                <canvas id="weatherHistoryChart"
                                        data-labels="<?= htmlspecialchars(json_encode($chartLabels), ENT_QUOTES, 'UTF-8') ?>"
                                        data-temp="<?= htmlspecialchars(json_encode($chartTemp), ENT_QUOTES, 'UTF-8') ?>"
                                        data-precip="<?= htmlspecialchars(json_encode($chartPrecip), ENT_QUOTES, 'UTF-8') ?>"
                                        data-wind="<?= htmlspecialchars(json_encode($chartWind), ENT_QUOTES, 'UTF-8') ?>"
                                        data-hum="<?= htmlspecialchars(json_encode($chartHum), ENT_QUOTES, 'UTF-8') ?>"
                                        data-press="<?= htmlspecialchars(json_encode($chartPress), ENT_QUOTES, 'UTF-8') ?>">
                                </canvas>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-data" style="text-align: center; opacity: 0.6;">
                            Keine historischen Wetterdaten für den ausgewählten Zeitraum gefunden.
                        </div>
                    <?php endif; ?>
                </div>
            </div> <!-- End tab-history -->

        <?php endif; ?>
    </main>
</div>
<script src="../js/http.js?v=<?= APP_VERSION ?>" defer></script>
<script src="../js/weather.js?v=<?= APP_VERSION ?>" defer></script>


</body>
</html>







