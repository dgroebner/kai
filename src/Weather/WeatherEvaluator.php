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
        $umbrellaText = 'Kein Regen in Sicht.';
        if ($maxPrecipProb > 40 || $maxPrecip > 1.0) {
            $umbrella = true;
            $umbrellaText = 'Schirm einpacken, es koennte nass werden!';
        }

        // 2. Jacke
        $jacket = false;
        $jacketText = 'T-Shirt Wetter!';
        $windchill = $currentTemp;
        if ($currentWind > 20) {
            $windchill -= 2;
        }
        if ($windchill < 15) {
            $jacket = true;
            $jacketText = 'Eine Jacke ist ratsam.';
        }

        // 3. Schal und Muetze
        $winterGear = false;
        $winterText = 'Nicht noetig.';
        if ($windchill < 5) {
            $winterGear = true;
            $winterText = 'Muetze und Schal nicht vergessen!';
        }

        // 4. Pool
        $pool = false;
        $poolText = 'Zu kalt fuer den Pool.';
        if ($maxTempToday >= 28) {
            $pool = true;
            $poolText = 'Ab in den Pool, es wird heiss!';
        }

        // 5. Giessen
        $dailyPrecip = $forecast['daily']['precipitation_sum'][0] ?? 0;
        $watering = false;
        $wateringText = 'Boden ist feucht genug.';
        
        $soil = $sensorData['soil_moisture_pct'] ?? 100;
        if ($dailyPrecip < 2.0 && $maxTempToday > 20 && $soil < 40) {
            $watering = true;
            $wateringText = 'Pflanzen brauchen Wasser!';
        } elseif ($dailyPrecip < 2.0 && !$sensorData && $maxTempToday > 22) {
             $watering = true;
             $wateringText = 'Pflanzen koennten Wasser brauchen.';
        }

        return [
            'umbrella' => ['status' => $umbrella, 'text' => $umbrellaText],
            'jacket'   => ['status' => $jacket, 'text' => $jacketText],
            'winter'   => ['status' => $winterGear, 'text' => $winterText],
            'pool'     => ['status' => $pool, 'text' => $poolText],
            'watering' => ['status' => $watering, 'text' => $wateringText],
        ];
    }
}
