<?php

namespace Kai\Tools\Car;

use Kai\Tools\Shared\Db\Database;
use PDO;

class VehicleTripRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function saveTrip(array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO vehicle_trips (
                tronity_trip_id, start_time, end_time, duration_min,
                mileage_start_km, mileage_end_km, distance_km, avg_speed_kmh,
                soc_start_pct, soc_end_pct, delta_soc_pct, consumed_kwh,
                avg_consumption_kwh_100km, temperature_c,
                start_lat, start_lon, end_lat, end_lon,
                start_location, end_location
            ) VALUES (
                :tronity_trip_id, :start_time, :end_time, :duration_min,
                :mileage_start_km, :mileage_end_km, :distance_km, :avg_speed_kmh,
                :soc_start_pct, :soc_end_pct, :delta_soc_pct, :consumed_kwh,
                :avg_consumption_kwh_100km, :temperature_c,
                :start_lat, :start_lon, :end_lat, :end_lon,
                :start_location, :end_location
            ) ON DUPLICATE KEY UPDATE
                start_time = VALUES(start_time), end_time = VALUES(end_time),
                duration_min = VALUES(duration_min), distance_km = VALUES(distance_km),
                avg_speed_kmh = VALUES(avg_speed_kmh), soc_start_pct = VALUES(soc_start_pct),
                soc_end_pct = VALUES(soc_end_pct), delta_soc_pct = VALUES(delta_soc_pct),
                consumed_kwh = VALUES(consumed_kwh), avg_consumption_kwh_100km = VALUES(avg_consumption_kwh_100km),
                temperature_c = VALUES(temperature_c), start_location = VALUES(start_location),
                end_location = VALUES(end_location)
        ");

        $stmt->execute([
            ':tronity_trip_id' => $data['tronity_trip_id'],
            ':start_time' => $data['start_time'],
            ':end_time' => $data['end_time'],
            ':duration_min' => $data['duration_min'],
            ':mileage_start_km' => $data['mileage_start_km'],
            ':mileage_end_km' => $data['mileage_end_km'],
            ':distance_km' => $data['distance_km'],
            ':avg_speed_kmh' => $data['avg_speed_kmh'],
            ':soc_start_pct' => $data['soc_start_pct'],
            ':soc_end_pct' => $data['soc_end_pct'],
            ':delta_soc_pct' => $data['delta_soc_pct'],
            ':consumed_kwh' => $data['consumed_kwh'],
            ':avg_consumption_kwh_100km' => $data['avg_consumption_kwh_100km'],
            ':temperature_c' => $data['temperature_c'] ?? null,
            ':start_lat' => $data['start_lat'] ?? null,
            ':start_lon' => $data['start_lon'] ?? null,
            ':end_lat' => $data['end_lat'] ?? null,
            ':end_lon' => $data['end_lon'] ?? null,
            ':start_location' => $data['start_location'] ?? null,
            ':end_location' => $data['end_location'] ?? null,
        ]);
    }

    public function getTrips(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare("SELECT * FROM vehicle_trips ORDER BY start_time DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTripStats(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                SUM(distance_km) as total_distance,
                SUM(consumed_kwh) as total_consumption,
                AVG(avg_consumption_kwh_100km) as avg_consumption,
                COUNT(*) as trip_count
            FROM vehicle_trips
            WHERE start_time >= :start AND start_time <= :end
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function getLastTripId(): ?string
    {
        $stmt = $this->db->query("SELECT tronity_trip_id FROM vehicle_trips ORDER BY start_time DESC LIMIT 1");
        $res = $stmt->fetchColumn();
        return $res !== false ? (string)$res : null;
    }
}
