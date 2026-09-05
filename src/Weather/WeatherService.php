<?php

namespace Kai\Tools\Weather;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;

class WeatherService
{
    // Koordinaten Leipzig-Holzhausen: 51.30°N / 12.45°O
    private const LAT = 51.30;
    private const LON = 12.45;
    
    // Wir rufen daily (precipitation_sum, temperature_2m_max, min) und hourly ab
    private const API_URL = 'https://api.open-meteo.com/v1/forecast?latitude=' . self::LAT . '&longitude=' . self::LON . '&current=temperature_2m,wind_speed_10m,weather_code&hourly=temperature_2m,precipitation_probability,precipitation,wind_speed_10m,wind_gusts_10m,cloud_cover&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,sunrise,sunset&timezone=Europe%2FBerlin';

    private \PDO $pdo;
    private Logger $logger;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->logger = new Logger();
    }

    public function fetchAndCacheForecast(): ?array
    {
        $ch = curl_init(self::API_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->logger->error('WeatherService: Fehler beim Abruf der Open-Meteo API', ['http_code' => $httpCode]);
            return null;
        }

        $data = json_decode($response, true);
        if (!$data) {
            $this->logger->error('WeatherService: Ungueltiges JSON von Open-Meteo API');
            return null;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO weather_cache (data_type, payload, updated_at)
            VALUES ('open_meteo_forecast', :payload, NOW())
            ON DUPLICATE KEY UPDATE payload = :payload, updated_at = NOW()
        ");
        $stmt->execute([':payload' => json_encode($data)]);

        return $data;
    }

    public function getCachedForecast(bool $forceRefresh = false): ?array
    {
        $stmt = $this->pdo->prepare("SELECT payload, updated_at FROM weather_cache WHERE data_type = 'open_meteo_forecast'");
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $now = time();

        if ($row && !$forceRefresh) {
            $updatedAt = strtotime($row['updated_at']);
            $payload = json_decode($row['payload'], true);
            
            // Negativer Cache
            if (isset($payload['error_limit'])) {
                $expiresAt = $payload['expires_at'] ?? 0;
                if ($now < $expiresAt) {
                    return null;
                }
            } else {
                // Normaler Cache (60 Minuten gültig)
                if ($now - $updatedAt < 3600) {
                    return $payload;
                }
            }
        }

        $freshData = $this->fetchAndCacheForecast();
        
        if (!$freshData) {
            if ($row && !isset(json_decode($row['payload'], true)['error_limit'])) {
                // Fallback auf abgelaufene (stale) Daten, API wird für 30 Minuten pausiert
                $this->pdo->query("UPDATE weather_cache SET updated_at = NOW() WHERE data_type = 'open_meteo_forecast'");
                return json_decode($row['payload'], true);
            }
            
            // Keinerlei Daten vorhanden, API ist blockiert -> negativen Cache für 15 Min setzen
            $expires = $now + 900;
            $payloadJson = json_encode(['error_limit' => true, 'expires_at' => $expires]);
            $stmt = $this->pdo->prepare("
                INSERT INTO weather_cache (data_type, payload, updated_at)
                VALUES ('open_meteo_forecast', :payload, NOW())
                ON DUPLICATE KEY UPDATE payload = :payload, updated_at = NOW()
            ");
            $stmt->execute([':payload' => $payloadJson]);
            return null;
        }

        return $freshData;
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
            // Nur beruecksichtigen, wenn juenger als 30 Minuten
            $updatedAt = strtotime($row['updated_at']);
            if (time() - $updatedAt < 1800) {
                return $row;
            }
        }
        return null;
    }
}
