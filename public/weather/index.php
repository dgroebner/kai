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

function getWeatherIconAndText($code)
{
    if ($code === null || $code === '') return ['icon' => '❓', 'text' => 'Unbekannt'];
    $code = (int)$code;
    if ($code == 0) return ['icon' => '☀️', 'text' => 'Klar'];
    if ($code == 1 || $code == 2) return ['icon' => '⛅', 'text' => 'Heiter / Wolkig'];
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
            $rain = $forecast['current']['rain'] ?? 0;
            $showers = $forecast['current']['showers'] ?? 0;
            $snowfall = $forecast['current']['snowfall'] ?? 0;
            $precip = $forecast['current']['precipitation'] ?? 0;
            $isNight = isset($forecast['current']['is_day']) && $forecast['current']['is_day'] == 0;

            $sunriseStr = $forecast['daily']['sunrise'][0] ?? date('Y-m-d 06:00:00');
            $sunsetStr = $forecast['daily']['sunset'][0] ?? date('Y-m-d 20:00:00');
            $sunrise = strtotime(is_array($sunriseStr) ? $sunriseStr[0] : $sunriseStr);
            $sunset = strtotime(is_array($sunsetStr) ? $sunsetStr[0] : $sunsetStr);
            $now = time();

            $isSnowing = ($snowfall > 0 || in_array($weatherCode, [71, 73, 75, 77, 85, 86]));
            $isRaining = (!$isSnowing && ($rain > 0 || $showers > 0 || $precip > 0.1 || in_array($weatherCode, [51, 53, 55, 61, 63, 65, 80, 81, 82])));
            $isStorm = ($windSpeed > 30);
            $isFog = ($weatherCode == 45 || $weatherCode == 48);
            $isCloudy = ($cloudCover > 30 || in_array($weatherCode, [3, 45, 48, 51, 53, 55, 61, 63, 65, 80, 81, 82, 71, 73, 75, 77, 85, 86]));

            $isGoldenHour = false;
            if (abs($now - $sunrise) <= 3600 || abs($now - $sunset) <= 3600) {
                $isGoldenHour = true;
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
                    </div>

                    <div class="diorama-container" style="position: relative; line-height: 0;">
                        <!-- Basis-Jahreszeiten-Bild -->
                        <img src="<?= $bgUrl ?>" alt="Jahreszeit Hintergrund"
                             style="width: 100%; height: auto; display: block; object-fit: cover; aspect-ratio: 16/9;">

                        <!-- Transparentes SVG-Overlay (Wetter-Effekte) -->
                        <svg viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" class="diorama-svg"
                             style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none;">
                            <defs>
                                <linearGradient id="goldenGrad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#fb923c"/>
                                    <stop offset="50%" stop-color="#fcd34d"/>
                                    <stop offset="100%" stop-color="#f87171"/>
                                </linearGradient>
                                <mask id="moonMask">
                                    <rect width="100%" height="100%" fill="white"/>
                                    <circle cx="90%" cy="5%" r="35" fill="black"/>
                                </mask>
                            </defs>

                            <?php if ($isNight): ?>
                                <!-- Nacht-Verdunkelung -->
                                <rect width="100%" height="100%" fill="#0a192f" opacity="0.45"/>
                                <!-- Mond -->
                                <circle cx="92%" cy="7%" r="40" fill="#facc15" opacity="0.9" mask="url(#moonMask)"/>
                            <?php endif; ?>

                            <?php if ($isGoldenHour && !$isNight): ?>
                                <!-- Daemmerung / Golden Hour -->
                                <rect width="100%" height="100%" fill="url(#goldenGrad)" opacity="0.25"
                                      style="mix-blend-mode: overlay;"/>
                            <?php endif; ?>

                            <?php if ($isCloudy): ?>
                                <!-- Wolken-Ebene (Top-Left Bereich) -->
                                <g fill="#ffffff">
                                    <!-- Grosse Wolke -->
                                    <g transform="translate(10, 20) scale(1.4)" opacity="0.8">
                                        <circle cx="100" cy="80" r="40"/>
                                        <circle cx="150" cy="50" r="60"/>
                                        <circle cx="210" cy="70" r="50"/>
                                        <rect x="80" y="50" width="150" height="70" rx="35"/>
                                    </g>
                                    <!-- Mittlere Wolke Rand links -->
                                    <g transform="translate(-50, -10) scale(1.2)" opacity="0.6">
                                        <circle cx="100" cy="80" r="40"/>
                                        <circle cx="150" cy="50" r="60"/>
                                        <circle cx="210" cy="70" r="50"/>
                                        <rect x="80" y="50" width="150" height="70" rx="35"/>
                                    </g>
                                    <!-- Wolke unten links -->
                                    <g transform="translate(-100, 150) scale(1.1)" opacity="0.7">
                                        <circle cx="100" cy="80" r="40"/>
                                        <circle cx="150" cy="50" r="60"/>
                                        <circle cx="210" cy="70" r="50"/>
                                        <rect x="80" y="50" width="150" height="70" rx="35"/>
                                    </g>
                                    <!-- Sehr hohe, kleine Wolke -->
                                    <g transform="translate(250, -20) scale(0.9)" opacity="0.6">
                                        <circle cx="100" cy="80" r="40"/>
                                        <circle cx="150" cy="50" r="60"/>
                                        <circle cx="210" cy="70" r="50"/>
                                        <rect x="80" y="50" width="150" height="70" rx="35"/>
                                    </g>
                                </g>
                            <?php endif; ?>

                            <?php if ($isFog): ?>
                                <!-- Nebel (grauer milchiger Schleier) -->
                                <rect width="100%" height="100%" fill="#cbd5e1" opacity="0.35"/>
                            <?php endif; ?>

                            <?php if ($isRaining): ?>
                                <!-- Regen mit Intensitaetssteuerung -->
                                <?php
                                $rainWidth = ($precip >= 2.0) ? 3.5 : 1.5;
                                $rainOpacity = ($precip >= 2.0) ? 0.7 : 0.4;
                                $rainSpacing = ($precip >= 2.0) ? 70 : 180;
                                ?>
                                <g stroke="#60a5fa" stroke-width="<?= $rainWidth ?>" opacity="<?= $rainOpacity ?>">
                                    <?php for ($x = -200; $x <= 2000; $x += $rainSpacing): ?>
                                        <line x1="<?= $x ?>" y1="-100" x2="<?= $x - 150 ?>" y2="1100"/>
                                        <?php if ($precip >= 2.0): ?>
                                            <line x1="<?= $x + 35 ?>" y1="-50" x2="<?= $x - 115 ?>" y2="1050"/>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </g>
                            <?php endif; ?>

                            <?php if ($isSnowing): ?>
                                <!-- Schnee (fallende Flocken) -->
                                <g fill="#ffffff" opacity="0.8">
                                    <?php for ($i = 0; $i < 60; $i++):
                                        $cx = rand(0, 1600);
                                        $cy = rand(0, 900);
                                        $r = rand(2, 6);
                                        ?>
                                        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r ?>"/>
                                    <?php endfor; ?>
                                </g>
                            <?php endif; ?>

                            <?php if ($isStorm): ?>
                                <!-- Wind-Boen -->
                                <g stroke="#e2e8f0" stroke-width="6" fill="none" opacity="0.4">
                                    <path d="M -100 200 Q 200 100 400 250 T 900 150"/>
                                    <path d="M 300 350 Q 600 250 800 400 T 1400 300"/>
                                    <path d="M 800 100 Q 1100 50 1300 200 T 1800 100"/>
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
                    $currIconText = getWeatherIconAndText($currCode);

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
                                $hIcon = getWeatherIconAndText($hCode)['icon'];
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







