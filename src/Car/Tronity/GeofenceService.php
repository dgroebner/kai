<?php

namespace Kai\Tools\Car\Tronity;

use Kai\Tools\System\SystemSettingsService;

class GeofenceService
{
    private float $homeLat;
    private float $homeLon;
    private int $homeRadiusM;

    public function __construct(?SystemSettingsService $settings = null)
    {
        $settings = $settings ?? new SystemSettingsService();
        $this->homeLat = $settings->getHomeLatitude();
        $this->homeLon = $settings->getHomeLongitude();
        $this->homeRadiusM = $settings->getHomeGeofenceRadius();
    }

    /**
     * Checks if the given coordinates are within the HOME radius.
     */
    public function isHome(?float $lat, ?float $lon): bool
    {
        if ($lat === null || $lon === null) {
            return false;
        }

        $distance = $this->calculateDistanceMeters($this->homeLat, $this->homeLon, $lat, $lon);
        return $distance <= $this->homeRadiusM;
    }

    /**
     * Identifies the location type based on coordinates.
     */
    public function getLocationType(?float $lat, ?float $lon): string
    {
        if ($lat === null || $lon === null) {
            return 'UNKNOWN';
        }
        return $this->isHome($lat, $lon) ? 'HOME' : 'PUBLIC';
    }

    /**
     * Haversine formula to calculate the distance between two points on the Earth in meters.
     */
    private function calculateDistanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusM = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusM * $c;
    }
}
