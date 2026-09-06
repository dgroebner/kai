<?php

namespace Kai\Tools\Weather;

class WeatherEvaluator
{
    /**
     * @return array<string, array{status: bool, text: string}>
     */
    public function evaluate(array $forecast, ?array $sensorData = null): array
    {
        $currentTemp = $forecast['current']['temperature_2m'] ?? 20.0;
        $currentWind = $forecast['current']['wind_speed_10m'] ?? 0.0;

        if ($sensorData && isset($sensorData['temperature_c'])) {
            $currentTemp = (float)$sensorData['temperature_c'];
        }
        if ($sensorData && isset($sensorData['wind_kmh'])) {
            $currentWind = (float)$sensorData['wind_kmh'];
        }

        $hourlyTime = $forecast['hourly']['time'] ?? [];
        $hourlyPrecip = $forecast['hourly']['precipitation'] ?? [];
        $hourlyPrecipProb = $forecast['hourly']['precipitation_probability'] ?? [];
        $hourlyTemp = $forecast['hourly']['temperature_2m'] ?? [];

        $maxPrecip = 0.0;
        $maxPrecipProb = 0;
        $maxTempToday = $currentTemp;

        $now = time();

        for ($i = 0; $i < count($hourlyTime); $i++) {
            $t = strtotime($hourlyTime[$i]);
            // Naechste 12 Stunden
            if ($t >= $now && $t <= $now + (12 * 3600)) {
                if (isset($hourlyPrecip[$i]) && $hourlyPrecip[$i] > $maxPrecip) {
                    $maxPrecip = $hourlyPrecip[$i];
                }
                if (isset($hourlyPrecipProb[$i]) && $hourlyPrecipProb[$i] > $maxPrecipProb) {
                    $maxPrecipProb = $hourlyPrecipProb[$i];
                }
                if (isset($hourlyTemp[$i]) && $hourlyTemp[$i] > $maxTempToday) {
                    $maxTempToday = $hourlyTemp[$i];
                }
            }
        }

        // 1. Regenschirm
        $umbrella = false;
        $umbrellaText = 'Alles trocken, Schirm kann zuhause bleiben.';
        if ($maxPrecipProb > 40 || $maxPrecip > 1.0) {
            $umbrella = true;
            $umbrellaText = 'Nimm \'nen Schirm mit, sonst wirst du klatschnass!';
        }

        // 2. Jacke
        $jacket = false;
        $jacketText = 'T-Shirt reicht, genieß es!';
        $windchill = $currentTemp;
        if ($currentWind > 20) {
            $windchill -= 2;
        }
        if ($windchill < 15) {
            $jacket = true;
            $jacketText = 'Zieh dir was drüber, echt fresh draußen.';
        }

        // 3. Schal und Muetze
        $winterGear = false;
        $winterText = 'Brauchst keine Winterausrüstung.';
        if ($windchill < 5) {
            $winterGear = true;
            $winterText = 'Freeze-Gefahr! Mütze und Schal sind heute Pflicht.';
        }

        // 4. Pool
        $pool = false;
        $poolText = 'Viel zu kalt für den Pool, bleib lieber im Trockenen.';
        if ($maxTempToday >= 17) {
            $pool = true;
            $poolText = 'Pool-Time! Perfektes Wetter zum Reinspringen.';
        }

        // 5. Giessen
        $dailyPrecip = $forecast['daily']['precipitation_sum'][0] ?? 0;
        $watering = false;
        $wateringText = 'Garten ist safe, Erde ist noch feucht genug.';

        $soil = $sensorData['soil_moisture_pct'] ?? 100;
        if ($dailyPrecip < 2.0 && $maxTempToday > 20 && $soil < 40) {
            $watering = true;
            $wateringText = 'Garten-Duty ruft: Die Pflanzen brauchen dringend Wasser!';
        } elseif ($dailyPrecip < 2.0 && !$sensorData && $maxTempToday > 22) {
            $watering = true;
            $wateringText = 'Check mal den Garten, könnte trocken sein.';
        }

        // 6. Wäsche
        $rain6h = false;
        $rain12h = false;
        $rain24h = false;

        for ($i = 0; $i < count($hourlyTime); $i++) {
            $t = strtotime($hourlyTime[$i]);
            if ($t >= $now && $t <= $now + (24 * 3600)) {
                $prob = $hourlyPrecipProb[$i] ?? 0;
                $precip = $hourlyPrecip[$i] ?? 0;
                
                // Kriterien für Wäsche: >30% Wahrscheinlichkeit oder >0.1mm Regen
                if ($prob > 30 || $precip > 0.1) {
                    if ($t <= $now + (6 * 3600)) $rain6h = true;
                    if ($t <= $now + (12 * 3600)) $rain12h = true;
                    if ($t <= $now + (24 * 3600)) $rain24h = true;
                }
            }
        }

        $laundry = false;
        if ($rain6h) {
            $laundry = false;
            $laundryText = 'Besser nicht: Regen in den nächsten 6h.';
        } elseif ($rain12h) {
            $laundry = true;
            $laundryText = 'Safe für 6h, aber Regen in 6-12h.';
        } elseif ($rain24h) {
            $laundry = true;
            $laundryText = 'Safe für 12h, Regen in 12-24h.';
        } else {
            $laundry = true;
            $laundryText = 'Perfekt! Nächste 24h komplett trocken.';
        }

        return [
            'umbrella' => ['status' => $umbrella, 'text' => $umbrellaText],
            'jacket' => ['status' => $jacket, 'text' => $jacketText],
            'winter' => ['status' => $winterGear, 'text' => $winterText],
            'pool' => ['status' => $pool, 'text' => $poolText],
            'watering' => ['status' => $watering, 'text' => $wateringText],
            'laundry' => ['status' => $laundry, 'text' => $laundryText],
        ];
    }
}
