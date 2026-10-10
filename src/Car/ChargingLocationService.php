<?php

namespace Kai\Tools\Car;

use Kai\Tools\Shared\Log\Logger;
use Throwable;

/**
 * Ermittelt Ladestationen, Betreiber und Karten-Links (OSM, Google Maps)
 * anhand von GPS-Koordinaten über OpenStreetMap (Overpass API) und ORS-Fallback.
 */
class ChargingLocationService
{
    private Logger $logger;
    private ?string $orsApiKey;

    public function __construct(?Logger $logger = null, ?string $orsApiKey = null)
    {
        $this->logger = $logger ?? new Logger(14);
        $this->orsApiKey = $orsApiKey ?? ($_ENV['ORS_API_KEY'] ?? null);
    }

    /**
     * Ermittelt Name und Betreiber einer Ladestation an den gegebenen Koordinaten.
     *
     * @return array{station_name: string|null, station_operator: string|null, raw_operator: string|null, osm_url: string, gmaps_url: string}|null
     */
    public function resolveStation(float $lat, float $lon, ?string $chargeMode = null): ?array
    {
        // 1. Overpass API nach amenity=charging_station abfragen (Umkreis 250m)
        $station = $this->queryOverpass($lat, $lon, $chargeMode);

        if ($station !== null) {
            $stationName = $station['name'] ?? null;
            $rawOperator = $station['operator'] ?? null;
            $normalizedOperator = $this->normalizeOperator($rawOperator);

            // Falls kein konkreter Stationsname vorhanden ist, Betreiber oder "Ladestation" nutzen
            if (empty($stationName)) {
                $stationName = $normalizedOperator ? "Ladestation ({$normalizedOperator})" : 'Öffentliche Ladestation';
            }

            return [
                'station_name' => $stationName,
                'station_operator' => $normalizedOperator ?: $rawOperator,
                'raw_operator' => $rawOperator,
                'osm_url' => $this->getOsmUrl($lat, $lon),
                'gmaps_url' => $this->getGoogleMapsUrl($lat, $lon, $stationName),
            ];
        }

        // 2. Fallback: Reverse Geocoding via ORS für Adresse / Ort
        $address = $this->reverseGeocodeOrs($lat, $lon);
        if ($address !== null) {
            return [
                'station_name' => $address,
                'station_operator' => null,
                'raw_operator' => null,
                'osm_url' => $this->getOsmUrl($lat, $lon),
                'gmaps_url' => $this->getGoogleMapsUrl($lat, $lon, $address),
            ];
        }

        return [
            'station_name' => null,
            'station_operator' => null,
            'raw_operator' => null,
            'osm_url' => $this->getOsmUrl($lat, $lon),
            'gmaps_url' => $this->getGoogleMapsUrl($lat, $lon),
        ];
    }

    /**
     * Ruft die Overpass API ab, um Ladesäulen im Umkreis von 250m zu finden.
     */
    private function queryOverpass(float $lat, float $lon, ?string $chargeMode = null): ?array
    {
        $overpassUrl = 'https://overpass-api.de/api/interpreter';
        $ql = sprintf(
            '[out:json][timeout:5];(node["amenity"="charging_station"](around:250,%.6f,%.6f);way["amenity"="charging_station"](around:250,%.6f,%.6f););out tags center 5;',
            $lat,
            $lon,
            $lat,
            $lon
        );

        try {
            $ch = curl_init($overpassUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => 'data=' . urlencode($ql),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: Kai-ChargingLocation/1.0 (https://kai.agent-smith.de; contact: kai@agent-smith.de)',
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($response)) {
                $data = json_decode($response, true);
                $elements = $data['elements'] ?? [];

                if (!empty($elements)) {
                    // Falls mehrere Ladesäulen vorhanden sind (z.B. DC vs AC), filtern wir passend zum Lademodus
                    $bestElement = null;

                    foreach ($elements as $el) {
                        $tags = $el['tags'] ?? [];
                        if (empty($tags)) {
                            continue;
                        }

                        // Wenn wir DC geladen haben, bevorzugen wir Säulen mit CCS / High Power
                        if ($chargeMode === 'DC') {
                            $isDc = isset($tags['socket:type2_combo']) 
                                || isset($tags['socket:type2_combo:output'])
                                || isset($tags['socket:chademo'])
                                || (isset($tags['capacity']) && (int)$tags['capacity'] >= 4);

                            if ($isDc) {
                                $bestElement = $el;
                                break;
                            }
                        }
                    }

                    if ($bestElement === null) {
                        $bestElement = $elements[0];
                    }

                    $tags = $bestElement['tags'] ?? [];
                    $operator = $tags['operator'] ?? $tags['brand'] ?? $tags['network'] ?? null;
                    $name = $tags['name'] ?? null;

                    return [
                        'name' => $name,
                        'operator' => $operator,
                        'tags' => $tags,
                    ];
                }
            }
        } catch (Throwable $e) {
            $this->logger->warn("ChargingLocationService: Overpass Fehler ({$e->getMessage()})");
        }

        return null;
    }

    /**
     * Fallback: Reverse Geocoding über OpenRouteService.
     */
    private function reverseGeocodeOrs(float $lat, float $lon): ?string
    {
        if (empty($this->orsApiKey)) {
            return null;
        }

        try {
            $url = 'https://api.openrouteservice.org/geocode/reverse?' . http_build_query([
                'api_key' => $this->orsApiKey,
                'point.lat' => $lat,
                'point.lon' => $lon,
                'size' => 1,
            ]);

            $authHeader = str_starts_with($this->orsApiKey, 'Bearer ')
                ? $this->orsApiKey
                : (str_starts_with($this->orsApiKey, 'ey') ? 'Bearer ' . $this->orsApiKey : $this->orsApiKey);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $authHeader,
                    'User-Agent: Kai-ChargingLocation/1.0 (https://kai.agent-smith.de)',
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($response)) {
                $data = json_decode($response, true);
                $label = $data['features'][0]['properties']['label'] ?? null;
                if ($label) {
                    return (string)$label;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warn("ChargingLocationService: ORS reverse Fehler ({$e->getMessage()})");
        }

        return null;
    }

    /**
     * Normalisiert Betreibernamen auf kanonische Markennamen für Ladekarten & Abrechnungen.
     */
    public function normalizeOperator(?string $raw): ?string
    {
        if (empty($raw)) {
            return null;
        }

        if (str_contains($lower, 'vattenfall') || str_contains($lower, 'incharge')) {
            return 'Vattenfall InCharge';
        }
        if (str_contains($lower, 'leipzig') || str_contains($lower, 'l-charge') || str_contains($lower, 'leipziger stadtwerke')) {
            return 'Stadtwerke Leipzig';
        }
        if (str_contains($lower, 'ionity')) {
            return 'Ionity';
        }
        if (str_contains($lower, 'ewe')) {
            return 'EWE Go';
        }
        if (str_contains($lower, 'enbw')) {
            return 'EnBW mobility+';
        }
        if (str_contains($lower, 'aral')) {
            return 'Aral pulse';
        }
        if (str_contains($lower, 'tesla')) {
            return 'Tesla Supercharger';
        }
        if (str_contains($lower, 'allego')) {
            return 'Allego';
        }
        if (str_contains($lower, 'fastned')) {
            return 'Fastned';
        }
        if (str_contains($lower, 'shell')) {
            return 'Shell Recharge';
        }
        if (str_contains($lower, 'total')) {
            return 'TotalEnergies';
        }
        if (str_contains($lower, 'e.on') || str_contains($lower, 'eon')) {
            return 'E.ON Drive';
        }
        if (str_contains($lower, 'maingau')) {
            return 'Maingau';
        }
        if (str_contains($lower, 'ladenetz')) {
            return 'Ladenetz.de';
        }

        return trim($raw);
    }

    /**
     * Erzeugt einen OpenStreetMap-Link mit Marker und Zoom.
     */
    public function getOsmUrl(float $lat, float $lon, int $zoom = 18): string
    {
        return sprintf(
            'https://www.openstreetmap.org/?mlat=%.6f&mlon=%.6f#map=%d/%.6f/%.6f',
            $lat,
            $lon,
            $zoom,
            $lat,
            $lon
        );
    }

    /**
     * Erzeugt einen Google Maps Link.
     */
    public function getGoogleMapsUrl(float $lat, float $lon, ?string $label = null): string
    {
        if (!empty($label)) {
            return 'https://www.google.com/maps/search/?api=1&query=' . urlencode("{$label}, {$lat}, {$lon}");
        }
        return sprintf('https://www.google.com/maps/search/?api=1&query=%.6f,%.6f', $lat, $lon);
    }
}
