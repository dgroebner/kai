<?php

namespace Kai\Tools\Car;

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\System\SystemSettingsService;
use Throwable;

/**
 * Geocodierungs-Service zur Auflösung von Adressangaben oder Freitext-Orten in Koordinaten.
 */
class GeocodingService
{
    private SystemSettingsService $settingsService;
    private ?string $orsApiKey;
    private Logger $logger;

    public function __construct(
        ?SystemSettingsService $settingsService = null,
        ?string $orsApiKey = null,
        ?Logger $logger = null
    ) {
        $this->settingsService = $settingsService ?? new SystemSettingsService();
        $this->orsApiKey = $orsApiKey ?? ($_ENV['ORS_API_KEY'] ?? null);
        $this->logger = $logger ?? new Logger(14);
    }

    /**
     * Löst eine Adresse oder einen Ortsnamen in Breiten- und Längengrad auf.
     *
     * @return array{lat: float, lon: float, display_name: string}|null
     */
    public function geocode(string $address): ?array
    {
        $trimmed = trim($address);
        if ($trimmed === '') {
            return null;
        }

        // 1. Spezieller Match auf Heimatadresse
        $lower = strtolower($trimmed);
        if (in_array($lower, ['heim', 'heimat', 'zuhause', 'home', 'daheim'], true)) {
            return [
                'lat' => $this->settingsService->getHomeLatitude(),
                'lon' => $this->settingsService->getHomeLongitude(),
                'display_name' => 'Zuhause',
            ];
        }

        // 2. OpenRouteService Geocoding (sofern API-Key vorliegt)
        if (!empty($this->orsApiKey)) {
            $orsResult = $this->geocodeViaOrs($trimmed);
            if ($orsResult !== null) {
                return $orsResult;
            }
        }

        // 3. Fallback: OpenStreetMap Nominatim
        return $this->geocodeViaNominatim($trimmed);
    }

    private function geocodeViaOrs(string $address): ?array
    {
        try {
            $url = 'https://api.openrouteservice.org/geocode/search?' . http_build_query([
                'api_key' => $this->orsApiKey,
                'text' => $address,
                'size' => 1,
            ]);

            $authHeader = str_starts_with($this->orsApiKey, 'Bearer ')
                ? $this->orsApiKey
                : (str_starts_with($this->orsApiKey, 'ey') ? 'Bearer ' . $this->orsApiKey : $this->orsApiKey);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $authHeader,
                    'User-Agent: Kai-TripPlanner/1.0',
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($response)) {
                $data = json_decode($response, true);
                $feature = $data['features'][0] ?? null;
                if ($feature && isset($feature['geometry']['coordinates'])) {
                    $coords = $feature['geometry']['coordinates']; // [lon, lat]
                    return [
                        'lat' => (float)$coords[1],
                        'lon' => (float)$coords[0],
                        'display_name' => (string)($feature['properties']['label'] ?? $address),
                    ];
                }
            }
        } catch (Throwable $e) {
            $this->logger->warn("GeocodingService: ORS-Fehler für '{$address}': " . $e->getMessage());
        }

        return null;
    }

    private function geocodeViaNominatim(string $address): ?array
    {
        try {
            $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
                'q' => $address,
                'format' => 'json',
                'limit' => 1,
                'addressdetails' => 0,
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: Kai-TripPlanner/1.0 (Home-Automation-Suite)',
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($response)) {
                $data = json_decode($response, true);
                if (is_array($data) && !empty($data[0])) {
                    $item = $data[0];
                    return [
                        'lat' => (float)$item['lat'],
                        'lon' => (float)$item['lon'],
                        'display_name' => (string)($item['display_name'] ?? $address),
                    ];
                }
            }
        } catch (Throwable $e) {
            $this->logger->warn("GeocodingService: Nominatim-Fehler für '{$address}': " . $e->getMessage());
        }

        return null;
    }
}
