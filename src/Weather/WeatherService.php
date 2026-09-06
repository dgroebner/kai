<?php

namespace Kai\Tools\Weather;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;

class WeatherService
{
    private \PDO $pdo;
    private Logger $logger;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->logger = new Logger();
    }

    public function getForecastFromDb(): ?array
    {
        $forecast = ['current' => [], 'hourly' => [], 'daily' => []];

        // 1. Current State
        $stmt = $this->pdo->query("SELECT * FROM weather_state WHERE id = 1");
        $state = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($state) {
            $forecast['current'] = $state;
        }

        // 2. Hourly
        $stmt = $this->pdo->query("SELECT * FROM weather_forecast_hourly ORDER BY forecast_time ASC");
        $hourlyRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $forecast['hourly'] = [];
        foreach ($hourlyRows as $row) {
            foreach ($row as $k => $v) {
                if ($k == 'forecast_time') {
                    $forecast['hourly']['time'][] = $v;
                } else {
                    $forecast['hourly'][$k][] = is_numeric($v) ? (strpos($v, '.') !== false ? (float)$v : (int)$v) : $v;
                }
            }
        }

        // 3. Daily
        // Remove LIMIT 1 to fetch all days
        $stmt = $this->pdo->query("SELECT * FROM weather_forecast_daily ORDER BY forecast_date ASC");
        $dailyRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $forecast['daily'] = [];
        foreach ($dailyRows as $row) {
            foreach ($row as $k => $v) {
                if ($k == 'forecast_date') {
                    $forecast['daily']['time'][] = $v;
                } else {
                    $forecast['daily'][$k][] = is_numeric($v) ? (strpos($v, '.') !== false ? (float)$v : (int)$v) : $v;
                }
            }
        }

        if (empty($forecast['current']) && empty($forecast['hourly']['time'])) {
            return null;
        }
        return $forecast;
    }
    
    public function saveSensorData(float $temp, int $soil, float $wind): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO weather_sensor_live (temperature_c, soil_moisture_pct, wind_kmh, updated_at)
            VALUES (:t, :s, :w, NOW())
        ");
        $stmt->execute([
            ':t' => $temp,
            ':s' => $soil,
            ':w' => $wind
        ]);
    }
    
    public function getLatestSensorData(): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM weather_sensor_live ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($row) {
            $updatedAt = strtotime($row['updated_at']);
            if (time() - $updatedAt < 1800) {
                return $row;
            }
        }
        return null;
    }
}
