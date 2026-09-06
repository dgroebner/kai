<?php

namespace Kai\Tools\Weather;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;

class WeatherIngestService
{
    private \PDO $pdo;
    private Logger $logger;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->logger = new Logger();
    }

    public function processForecast(array $data): void
    {
        $modelStr = $data['metadata']['model'] ?? '';
        $isIcon = (str_contains(strtolower($modelStr), 'icon') || $modelStr == '23' || $modelStr == '1' || $modelStr == 'best_match');
        $isEcmwf = (str_contains(strtolower($modelStr), 'ecmwf') || $modelStr == '30');

        if (!$isIcon && !$isEcmwf) {
            $this->logger->warn('WeatherIngest: Unbekanntes Modell', ['model' => $modelStr]);
            return;
        }

        $now = time();
        $limit48h = $now + (48 * 3600);

        if ($isIcon && !empty($data['current'])) {
            $row = $data['current'];
        $stmt = $this->pdo->prepare("
            INSERT INTO weather_state (id, temperature_2m, relative_humidity_2m, is_day, apparent_temperature, precipitation, showers, rain, snowfall, weather_code, cloud_cover, surface_pressure, wind_gusts_10m, wind_direction_10m, wind_speed_10m)
            VALUES (1, :temperature_2m, :relative_humidity_2m, :is_day, :apparent_temperature, :precipitation, :showers, :rain, :snowfall, :weather_code, :cloud_cover, :surface_pressure, :wind_gusts_10m, :wind_direction_10m, :wind_speed_10m)
            ON DUPLICATE KEY UPDATE temperature_2m=:temperature_2m2, relative_humidity_2m=:relative_humidity_2m2, is_day=:is_day2, apparent_temperature=:apparent_temperature2, precipitation=:precipitation2, showers=:showers2, rain=:rain2, snowfall=:snowfall2, weather_code=:weather_code2, cloud_cover=:cloud_cover2, surface_pressure=:surface_pressure2, wind_gusts_10m=:wind_gusts_10m2, wind_direction_10m=:wind_direction_10m2, wind_speed_10m=:wind_speed_10m2, updated_at=NOW()
        ");

        $binds = [
            ':temperature_2m' => $row['temperature_2m'] ?? null,
            ':temperature_2m2' => $row['temperature_2m'] ?? null,
            ':relative_humidity_2m' => $row['relative_humidity_2m'] ?? null,
            ':relative_humidity_2m2' => $row['relative_humidity_2m'] ?? null,
            ':is_day' => $row['is_day'] ?? null,
            ':is_day2' => $row['is_day'] ?? null,
            ':apparent_temperature' => $row['apparent_temperature'] ?? null,
            ':apparent_temperature2' => $row['apparent_temperature'] ?? null,
            ':precipitation' => $row['precipitation'] ?? null,
            ':precipitation2' => $row['precipitation'] ?? null,
            ':showers' => $row['showers'] ?? null,
            ':showers2' => $row['showers'] ?? null,
            ':rain' => $row['rain'] ?? null,
            ':rain2' => $row['rain'] ?? null,
            ':snowfall' => $row['snowfall'] ?? null,
            ':snowfall2' => $row['snowfall'] ?? null,
            ':weather_code' => $row['weather_code'] ?? null,
            ':weather_code2' => $row['weather_code'] ?? null,
            ':cloud_cover' => $row['cloud_cover'] ?? null,
            ':cloud_cover2' => $row['cloud_cover'] ?? null,
            ':surface_pressure' => $row['surface_pressure'] ?? null,
            ':surface_pressure2' => $row['surface_pressure'] ?? null,
            ':wind_gusts_10m' => $row['wind_gusts_10m'] ?? null,
            ':wind_gusts_10m2' => $row['wind_gusts_10m'] ?? null,
            ':wind_direction_10m' => $row['wind_direction_10m'] ?? null,
            ':wind_direction_10m2' => $row['wind_direction_10m'] ?? null,
            ':wind_speed_10m' => $row['wind_speed_10m'] ?? null,
            ':wind_speed_10m2' => $row['wind_speed_10m'] ?? null,
        ];
        $stmt->execute($binds);
        }

        if (!empty($data['daily'])) {
            foreach ($data['daily'] as $row) {
                $this->insertDaily($row, $isEcmwf, $limit48h);
            }
        }

        if (!empty($data['hourly'])) {
            foreach ($data['hourly'] as $row) {
                $this->insertHourly($row, $isEcmwf, $limit48h);
            }
        }
    }

    private function insertDaily(array $row): void
    {
        $d = date('Y-m-d', strtotime($row['date']));
        $stmt = $this->pdo->prepare("
            INSERT INTO weather_forecast_daily (forecast_date, weather_code, temperature_2m_max, temperature_2m_min, apparent_temperature_max, apparent_temperature_min, uv_index_max, sunrise, sunset, daylight_duration, sunshine_duration, moonrise, moonset, moon_phase, rain_sum, showers_sum, snowfall_sum, precipitation_sum, precipitation_hours, precipitation_probability_max, wind_speed_10m_max, wind_gusts_10m_max, wind_direction_10m_dominant, shortwave_radiation_sum)
            VALUES (:fd, :weather_code, :temperature_2m_max, :temperature_2m_min, :apparent_temperature_max, :apparent_temperature_min, :uv_index_max, :sunrise, :sunset, :daylight_duration, :sunshine_duration, :moonrise, :moonset, :moon_phase, :rain_sum, :showers_sum, :snowfall_sum, :precipitation_sum, :precipitation_hours, :precipitation_probability_max, :wind_speed_10m_max, :wind_gusts_10m_max, :wind_direction_10m_dominant, :shortwave_radiation_sum)
            ON DUPLICATE KEY UPDATE weather_code=:weather_code2, temperature_2m_max=:temperature_2m_max2, temperature_2m_min=:temperature_2m_min2, apparent_temperature_max=:apparent_temperature_max2, apparent_temperature_min=:apparent_temperature_min2, uv_index_max=:uv_index_max2, sunrise=:sunrise2, sunset=:sunset2, daylight_duration=:daylight_duration2, sunshine_duration=:sunshine_duration2, moonrise=:moonrise2, moonset=:moonset2, moon_phase=:moon_phase2, rain_sum=:rain_sum2, showers_sum=:showers_sum2, snowfall_sum=:snowfall_sum2, precipitation_sum=:precipitation_sum2, precipitation_hours=:precipitation_hours2, precipitation_probability_max=:precipitation_probability_max2, wind_speed_10m_max=:wind_speed_10m_max2, wind_gusts_10m_max=:wind_gusts_10m_max2, wind_direction_10m_dominant=:wind_direction_10m_dominant2, shortwave_radiation_sum=:shortwave_radiation_sum2, updated_at=NOW()
        ");

        $binds = [
            ':fd' => $d,
            ':weather_code' => $row['weather_code'] ?? null,
            ':weather_code2' => $row['weather_code'] ?? null,
            ':temperature_2m_max' => $row['temperature_2m_max'] ?? null,
            ':temperature_2m_max2' => $row['temperature_2m_max'] ?? null,
            ':temperature_2m_min' => $row['temperature_2m_min'] ?? null,
            ':temperature_2m_min2' => $row['temperature_2m_min'] ?? null,
            ':apparent_temperature_max' => $row['apparent_temperature_max'] ?? null,
            ':apparent_temperature_max2' => $row['apparent_temperature_max'] ?? null,
            ':apparent_temperature_min' => $row['apparent_temperature_min'] ?? null,
            ':apparent_temperature_min2' => $row['apparent_temperature_min'] ?? null,
            ':uv_index_max' => $row['uv_index_max'] ?? null,
            ':uv_index_max2' => $row['uv_index_max'] ?? null,
            ':sunrise' => !empty($row['sunrise']) ? date('Y-m-d H:i:s', strtotime($row['sunrise'])) : null,
            ':sunrise2' => !empty($row['sunrise']) ? date('Y-m-d H:i:s', strtotime($row['sunrise'])) : null,
            ':sunset' => !empty($row['sunset']) ? date('Y-m-d H:i:s', strtotime($row['sunset'])) : null,
            ':sunset2' => !empty($row['sunset']) ? date('Y-m-d H:i:s', strtotime($row['sunset'])) : null,
            ':daylight_duration' => $row['daylight_duration'] ?? null,
            ':daylight_duration2' => $row['daylight_duration'] ?? null,
            ':sunshine_duration' => $row['sunshine_duration'] ?? null,
            ':sunshine_duration2' => $row['sunshine_duration'] ?? null,
            ':moonrise' => !empty($row['moonrise']) ? date('Y-m-d H:i:s', strtotime($row['moonrise'])) : null,
            ':moonrise2' => !empty($row['moonrise']) ? date('Y-m-d H:i:s', strtotime($row['moonrise'])) : null,
            ':moonset' => !empty($row['moonset']) ? date('Y-m-d H:i:s', strtotime($row['moonset'])) : null,
            ':moonset2' => !empty($row['moonset']) ? date('Y-m-d H:i:s', strtotime($row['moonset'])) : null,
            ':moon_phase' => $row['moon_phase'] ?? null,
            ':moon_phase2' => $row['moon_phase'] ?? null,
            ':rain_sum' => $row['rain_sum'] ?? null,
            ':rain_sum2' => $row['rain_sum'] ?? null,
            ':showers_sum' => $row['showers_sum'] ?? null,
            ':showers_sum2' => $row['showers_sum'] ?? null,
            ':snowfall_sum' => $row['snowfall_sum'] ?? null,
            ':snowfall_sum2' => $row['snowfall_sum'] ?? null,
            ':precipitation_sum' => $row['precipitation_sum'] ?? null,
            ':precipitation_sum2' => $row['precipitation_sum'] ?? null,
            ':precipitation_hours' => $row['precipitation_hours'] ?? null,
            ':precipitation_hours2' => $row['precipitation_hours'] ?? null,
            ':precipitation_probability_max' => $row['precipitation_probability_max'] ?? null,
            ':precipitation_probability_max2' => $row['precipitation_probability_max'] ?? null,
            ':wind_speed_10m_max' => $row['wind_speed_10m_max'] ?? null,
            ':wind_speed_10m_max2' => $row['wind_speed_10m_max'] ?? null,
            ':wind_gusts_10m_max' => $row['wind_gusts_10m_max'] ?? null,
            ':wind_gusts_10m_max2' => $row['wind_gusts_10m_max'] ?? null,
            ':wind_direction_10m_dominant' => $row['wind_direction_10m_dominant'] ?? null,
            ':wind_direction_10m_dominant2' => $row['wind_direction_10m_dominant'] ?? null,
            ':shortwave_radiation_sum' => $row['shortwave_radiation_sum'] ?? null,
            ':shortwave_radiation_sum2' => $row['shortwave_radiation_sum'] ?? null,
        ];
        $stmt->execute($binds);
    }

    private function insertHourly(array $row, bool $isEcmwf, int $limit48h): void
    {
        $time = strtotime($row['date']);
        
        // ECMWF darf die ersten 48 Stunden NICHT überschreiben (ICON-D2 hat Vorrang)
        if ($isEcmwf && $time < $limit48h) {
            return;
        }
        
        $dt = date('Y-m-d H:i:s', $time);
        $stmt = $this->pdo->prepare("
            INSERT INTO weather_forecast_hourly (forecast_time, temperature_2m, relative_humidity_2m, dew_point_2m, apparent_temperature, precipitation_probability, precipitation, rain, showers, snowfall, snow_depth, weather_code, cloud_cover, surface_pressure, visibility, evapotranspiration, wind_speed_10m, wind_direction_10m, wind_gusts_10m, soil_temperature_0cm, soil_moisture_0_to_1cm, uv_index, sunshine_duration, total_column_integrated_water_vapour, cape, lifted_index, convective_inhibition, freezing_level_height)
            VALUES (:ft, :temperature_2m, :relative_humidity_2m, :dew_point_2m, :apparent_temperature, :precipitation_probability, :precipitation, :rain, :showers, :snowfall, :snow_depth, :weather_code, :cloud_cover, :surface_pressure, :visibility, :evapotranspiration, :wind_speed_10m, :wind_direction_10m, :wind_gusts_10m, :soil_temperature_0cm, :soil_moisture_0_to_1cm, :uv_index, :sunshine_duration, :total_column_integrated_water_vapour, :cape, :lifted_index, :convective_inhibition, :freezing_level_height)
            ON DUPLICATE KEY UPDATE temperature_2m=:temperature_2m2, relative_humidity_2m=:relative_humidity_2m2, dew_point_2m=:dew_point_2m2, apparent_temperature=:apparent_temperature2, precipitation_probability=:precipitation_probability2, precipitation=:precipitation2, rain=:rain2, showers=:showers2, snowfall=:snowfall2, snow_depth=:snow_depth2, weather_code=:weather_code2, cloud_cover=:cloud_cover2, surface_pressure=:surface_pressure2, visibility=:visibility2, evapotranspiration=:evapotranspiration2, wind_speed_10m=:wind_speed_10m2, wind_direction_10m=:wind_direction_10m2, wind_gusts_10m=:wind_gusts_10m2, soil_temperature_0cm=:soil_temperature_0cm2, soil_moisture_0_to_1cm=:soil_moisture_0_to_1cm2, uv_index=:uv_index2, sunshine_duration=:sunshine_duration2, total_column_integrated_water_vapour=:total_column_integrated_water_vapour2, cape=:cape2, lifted_index=:lifted_index2, convective_inhibition=:convective_inhibition2, freezing_level_height=:freezing_level_height2, updated_at=NOW()
        ");

        $binds = [
            ':ft' => $dt,
            ':temperature_2m' => $row['temperature_2m'] ?? null,
            ':temperature_2m2' => $row['temperature_2m'] ?? null,
            ':relative_humidity_2m' => $row['relative_humidity_2m'] ?? null,
            ':relative_humidity_2m2' => $row['relative_humidity_2m'] ?? null,
            ':dew_point_2m' => $row['dew_point_2m'] ?? null,
            ':dew_point_2m2' => $row['dew_point_2m'] ?? null,
            ':apparent_temperature' => $row['apparent_temperature'] ?? null,
            ':apparent_temperature2' => $row['apparent_temperature'] ?? null,
            ':precipitation_probability' => $row['precipitation_probability'] ?? null,
            ':precipitation_probability2' => $row['precipitation_probability'] ?? null,
            ':precipitation' => $row['precipitation'] ?? null,
            ':precipitation2' => $row['precipitation'] ?? null,
            ':rain' => $row['rain'] ?? null,
            ':rain2' => $row['rain'] ?? null,
            ':showers' => $row['showers'] ?? null,
            ':showers2' => $row['showers'] ?? null,
            ':snowfall' => $row['snowfall'] ?? null,
            ':snowfall2' => $row['snowfall'] ?? null,
            ':snow_depth' => $row['snow_depth'] ?? null,
            ':snow_depth2' => $row['snow_depth'] ?? null,
            ':weather_code' => $row['weather_code'] ?? null,
            ':weather_code2' => $row['weather_code'] ?? null,
            ':cloud_cover' => $row['cloud_cover'] ?? null,
            ':cloud_cover2' => $row['cloud_cover'] ?? null,
            ':surface_pressure' => $row['surface_pressure'] ?? null,
            ':surface_pressure2' => $row['surface_pressure'] ?? null,
            ':visibility' => $row['visibility'] ?? null,
            ':visibility2' => $row['visibility'] ?? null,
            ':evapotranspiration' => $row['evapotranspiration'] ?? null,
            ':evapotranspiration2' => $row['evapotranspiration'] ?? null,
            ':wind_speed_10m' => $row['wind_speed_10m'] ?? null,
            ':wind_speed_10m2' => $row['wind_speed_10m'] ?? null,
            ':wind_direction_10m' => $row['wind_direction_10m'] ?? null,
            ':wind_direction_10m2' => $row['wind_direction_10m'] ?? null,
            ':wind_gusts_10m' => $row['wind_gusts_10m'] ?? null,
            ':wind_gusts_10m2' => $row['wind_gusts_10m'] ?? null,
            ':soil_temperature_0cm' => $row['soil_temperature_0cm'] ?? null,
            ':soil_temperature_0cm2' => $row['soil_temperature_0cm'] ?? null,
            ':soil_moisture_0_to_1cm' => $row['soil_moisture_0_to_1cm'] ?? null,
            ':soil_moisture_0_to_1cm2' => $row['soil_moisture_0_to_1cm'] ?? null,
            ':uv_index' => $row['uv_index'] ?? null,
            ':uv_index2' => $row['uv_index'] ?? null,
            ':sunshine_duration' => $row['sunshine_duration'] ?? null,
            ':sunshine_duration2' => $row['sunshine_duration'] ?? null,
            ':total_column_integrated_water_vapour' => $row['total_column_integrated_water_vapour'] ?? null,
            ':total_column_integrated_water_vapour2' => $row['total_column_integrated_water_vapour'] ?? null,
            ':cape' => $row['cape'] ?? null,
            ':cape2' => $row['cape'] ?? null,
            ':lifted_index' => $row['lifted_index'] ?? null,
            ':lifted_index2' => $row['lifted_index'] ?? null,
            ':convective_inhibition' => $row['convective_inhibition'] ?? null,
            ':convective_inhibition2' => $row['convective_inhibition'] ?? null,
            ':freezing_level_height' => $row['freezing_level_height'] ?? null,
            ':freezing_level_height2' => $row['freezing_level_height'] ?? null,
        ];
        $stmt->execute($binds);
    }
}
