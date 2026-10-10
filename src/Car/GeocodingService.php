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

        // 2. OpenStreetMap Nominatim als primärer Geocoder (höchste Genauigkeit für Adressen & POIs in DACH)
        // Probieren wir zuerst die volle Adresse und falls diese fehlschlägt bereinigte Varianten (ohne POI-Vorsatz)
        $candidates = $this->buildAddressCandidates($trimmed);
        foreach ($candidates as $candidate) {
            $nominatimResult = $this->geocodeViaNominatim($candidate);
            if ($nominatimResult !== null) {
                return $nominatimResult;
            }
        }

        // 3. Fallback: OpenRouteService Geocoding (sofern API-Key vorliegt) mit Fokus auf Heimatkoordinaten
        if (!empty($this->orsApiKey)) {
            foreach ($candidates as $candidate) {
                $orsResult = $this->geocodeViaOrs($candidate);
                if ($orsResult !== null) {
                    return $orsResult;
                }
            }
        }

        return null;
    }

    /**
     * Erzeugt sinnvolle Suchvarianten für Geocoder.
     * Wenn z. B. "Karls Erlebnis-Dorf - Döbeln, Erdbeerstraße 1, 04720 Döbeln, Deutschland" übergeben wird,
     * können auch "Erdbeerstraße 1, 04720 Döbeln, Deutschland" und "Erdbeerstraße 1, 04720 Döbeln" versucht werden.
     *
     * @return string[]
     */
    private function buildAddressCandidates(string $address): array
    {
        $candidates = [$address];

        // Falls die Adresse durch Kommas getrennt ist (z. B. "POI Name, Straße Hausnummer, PLZ Ort, Land")
        if (str_contains($address, ',')) {
            $parts = array_map('trim', explode(',', $address));
            // Wenn der erste Teil ein Name ist und mindestens 2 weitere Teile folgen (Straße, Ort)
            if (count($parts) >= 3) {
                $withoutPoi = implode(', ', array_slice($parts, 1));
                if (!in_array($withoutPoi, $candidates, true)) {
                    $candidates[] = $withoutPoi;
                }
            }
        }

        // Falls Bindestrich-Trenner wie "Karls Erlebnis-Dorf - Döbeln" vorkommt
        if (str_contains($address, ' - ')) {
            $afterDash = trim((string)substr($address, strpos($address, ' - ') + 3));
            if ($afterDash !== '' && !in_array($afterDash, $candidates, true)) {
                $candidates[] = $afterDash;
            }
        }

        return $candidates;
    }

    private function geocodeViaOrs(string $address): ?array
    {
        try {
            $homeLat = $this->settingsService->getHomeLatitude();
            $homeLon = $this->settingsService->getHomeLongitude();

            $queryParams = [
                'api_key' => $this->orsApiKey,
                'text' => $address,
                'size' => 1,
                'boundary.country' => 'DE',
            ];

            if ($homeLat != 0.0 && $homeLon != 0.0) {
                $queryParams['focus.point.lat'] = $homeLat;
                $queryParams['focus.point.lon'] = $homeLon;
            }

            $url = 'https://api.openrouteservice.org/geocode/search?' . http_build_query($queryParams);

            $authHeader = str_starts_with($this->orsApiKey, 'Bearer ')
                ? $this->orsApiKey
                : (str_starts_with($this->orsApiKey, 'ey') ? 'Bearer ' . $this->orsApiKey : $this->orsApiKey);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $authHeader,
                    'User-Agent: Kai-TripPlanner/1.0 (https://kai.agent-smith.de)',
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
            } else {
                $this->logger->warn("GeocodingService: ORS HTTP {$httpCode} für '{$address}'");
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
                'email' => 'kai@agent-smith.de',
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: Kai-TripPlanner/1.0 (https://kai.agent-smith.de; contact: kai@agent-smith.de)',
                    'Accept: application/json',
                    'Accept-Language: de,en;q=0.8',
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
            } else {
                $this->logger->warn("GeocodingService: Nominatim HTTP {$httpCode} für '{$address}'");
            }
        } catch (Throwable $e) {
            $this->logger->warn("GeocodingService: Nominatim-Fehler für '{$address}': " . $e->getMessage());
        }

        return null;
    }
}
