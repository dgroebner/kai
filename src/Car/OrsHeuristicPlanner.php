<?php

namespace Kai\Tools\Car;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Throwable;

/**
 * Standard-Provider für Routen- und Ladebedarfsberechnung:
 * Ermittelt Distanz & Fahrzeit über OpenRouteService (oder Heuristik-Fallback)
 * und berechnet den Ladebedarf anhand des Verbrauchsprofils des VW ID.Buzz (77 kWh Netto).
 */
class OrsHeuristicPlanner implements RoutePlannerInterface
{
    public const PROVIDER_NAME = 'ORS_HEURISTIC';
    public const BATTERY_CAPACITY_KWH = 77.0; // VW ID.Buzz Pro 77 kWh Netto
    public const BASE_CONSUMPTION_KWH_100KM = 21.0; // Gemischter Durchschnittsverbrauch
    public const HIGHWAY_CONSUMPTION_KWH_100KM = 25.0;
    public const RURAL_CONSUMPTION_KWH_100KM = 18.0;
    public const URBAN_CONSUMPTION_KWH_100KM = 16.0;

    private ?string $apiKey;
    private Logger $logger;

    public function __construct(?string $apiKey = null, ?Logger $logger = null)
    {
        $this->apiKey = $apiKey ?? ($_ENV['ORS_API_KEY'] ?? null);
        $this->logger = $logger ?? new Logger(14);
    }

    public function planRoute(
        float $startLat,
        float $startLon,
        float $destLat,
        float $destLon,
        int $targetSoc = 10,
        int $departureSoc = 100
    ): RoutePlanResult {
        // 1. Distanz und Fahrzeit ermitteln (via ORS oder Haversine-Fallback)
        $routeData = $this->fetchRouteData($startLat, $startLon, $destLat, $destLon);
        $distanceKm = $routeData['distance_km'];
        $durationMin = $routeData['duration_min'];

        // 2. Aktuelle Außentemperatur ermitteln für Temperatur-Korrekturfaktor
        $tempFactor = $this->determineTemperatureFactor();

        // 3. Verbrauch schätzen (unter Berücksichtigung von Distanz und Temperatur)
        // Bei längeren Strecken (> 80 km) steigt der Autobahnanteil
        $baseConsumption = ($distanceKm > 80.0)
            ? self::HIGHWAY_CONSUMPTION_KWH_100KM * 0.7 + self::RURAL_CONSUMPTION_KWH_100KM * 0.3
            : self::BASE_CONSUMPTION_KWH_100KM;

        $estimatedConsumptionKwh = round(($distanceKm / 100.0) * $baseConsumption * $tempFactor, 2);

        // 4. Start-SoC Entscheidung (Fachkonzept):
        // Bedarf <= 54 kWh und Distanz < 200 km => 80% Alltagsladung genügt, sonst 100%
        $recommendedDepartureSoc = ($estimatedConsumptionKwh <= 54.0 && $distanceKm < 200.0) ? 80 : 100;

        // Falls der Nutzer einen spezifischen Abfahrts-SoC vorgibt, diesen berücksichtigen
        $effectiveDepartureSoc = ($departureSoc > 0) ? $departureSoc : $recommendedDepartureSoc;

        // 5. Unterwegs-Ladebedarf (DC-Schnellladen) ermitteln
        $usableBatteryKwh = self::BATTERY_CAPACITY_KWH * (($effectiveDepartureSoc - $targetSoc) / 100.0);
        $usableBatteryKwh = max(0.0, $usableBatteryKwh);

        $enRouteChargeKwh = 0.0;
        $chargingStops = [];

        if ($estimatedConsumptionKwh > $usableBatteryKwh) {
            // Puffer von 5% (ca. 3.8 kWh) für sicheres Ankommen einplanen
            $bufferKwh = self::BATTERY_CAPACITY_KWH * 0.05;
            $enRouteChargeKwh = round(($estimatedConsumptionKwh - $usableBatteryKwh) + $bufferKwh, 1);

            // Typischer Schnellladehub 10% -> 70% ca. 45 kWh
            $numStops = max(1, (int)ceil($enRouteChargeKwh / 45.0));
            $kwhPerStop = round($enRouteChargeKwh / $numStops, 1);

            for ($i = 1; $i <= $numStops; $i++) {
                $chargingStops[] = [
                    'stop_number' => $i,
                    'planned_kwh' => $kwhPerStop,
                    'estimated_min' => (int)round(($kwhPerStop / 100.0) * 60), // Annahme Ø 100 kW Ladeleistung
                    'description' => "Ladestopp {$i} von {$numStops} (ca. {$kwhPerStop} kWh)",
                ];
            }
        }

        // 6. ABRP Deep Link generieren (ohne fixen departure_soc für automatische Live-Telemetrie via Tronity)
        $deepLink = AbrpDeepLinkBuilder::buildDeepLink(
            $startLat,
            $startLon,
            $destLat,
            $destLon,
            $targetSoc
        );

        return new RoutePlanResult(
            totalDistanceKm: round($distanceKm, 2),
            estimatedConsumptionKwh: $estimatedConsumptionKwh,
            recommendedDepartureSoc: $recommendedDepartureSoc,
            enRouteChargeKwh: $enRouteChargeKwh,
            chargingStops: $chargingStops,
            deepLink: $deepLink,
            providerName: self::PROVIDER_NAME,
            rawDetails: [
                'duration_min' => $durationMin,
                'temperature_factor' => $tempFactor,
                'source' => $routeData['source'],
            ]
        );
    }

    /**
     * Ruft Distanzdaten von OpenRouteService ab oder nutzt einen präzisen Haversine-Fallback.
     *
     * @return array{distance_km: float, duration_min: int, source: string}
     */
    private function fetchRouteData(float $startLat, float $startLon, float $destLat, float $destLon): array
    {
        if (!empty($this->apiKey)) {
            try {
                $url = 'https://api.openrouteservice.org/v2/directions/driving-car';
                $payload = json_encode([
                    'coordinates' => [
                        [$startLon, $startLat],
                        [$destLon, $destLat]
                    ],
                ]);

                $authHeader = str_starts_with($this->apiKey, 'Bearer ')
                    ? $this->apiKey
                    : (str_starts_with($this->apiKey, 'ey') ? 'Bearer ' . $this->apiKey : $this->apiKey);

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: ' . $authHeader,
                        'Content-Type: application/json',
                        'User-Agent: Kai-RoutePlanner/1.0',
                    ],
                    CURLOPT_TIMEOUT => 8,
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && is_string($response)) {
                    $json = json_decode($response, true);
                    $summary = $json['routes'][0]['summary'] ?? null;
                    if ($summary && isset($summary['distance'])) {
                        $distanceKm = (float)$summary['distance'] / 1000.0;
                        $durationMin = (int)round(((float)($summary['duration'] ?? 0)) / 60.0);

                        return [
                            'distance_km' => $distanceKm,
                            'duration_min' => $durationMin,
                            'source' => 'ORS_API',
                        ];
                    }
                }

                $this->logger->warn("OrsHeuristicPlanner: ORS API lieferte HTTP {$httpCode}, verwende Fallback.", [
                    'response' => is_string($response) ? substr($response, 0, 200) : null
                ]);
            } catch (Throwable $e) {
                $this->logger->warn("OrsHeuristicPlanner: Fehler bei ORS-Anfrage: " . $e->getMessage());
            }
        }

        // Haversine-Luftlinie mit Straßenwicklungsfaktor (1.28 im europäischen Straßennetz)
        $airDistanceKm = $this->calculateHaversineDistance($startLat, $startLon, $destLat, $destLon);
        $roadDistanceKm = $airDistanceKm * 1.28;
        $durationMin = (int)round(($roadDistanceKm / 80.0) * 60.0); // Durchschnitt 80 km/h

        return [
            'distance_km' => $roadDistanceKm,
            'duration_min' => $durationMin,
            'source' => 'HAVERSINE_ESTIMATE',
        ];
    }

    /**
     * Berechnet die Großkreisentfernung (Haversine) zwischen zwei Koordinaten in km.
     */
    private function calculateHaversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Ermittelt einen witterungsbedingten Verbrauchsfaktor (Heizung im Winter / Kühlung im Hochsommer).
     */
    private function determineTemperatureFactor(): float
    {
        try {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->query("SELECT outdoor_temp_c FROM vehicle_state LIMIT 1");
            $temp = $stmt->fetchColumn();

            if ($temp !== false && $temp !== null) {
                $t = (float)$temp;
                if ($t < 0.0) return 1.20; // Strenger Frost (+20%)
                if ($t < 8.0) return 1.12; // Kaltes Wetter (+12%)
                if ($t < 15.0) return 1.05; // Kühles Wetter (+5%)
                if ($t > 30.0) return 1.07; // Hohe Hitze / AC (+7%)
            }
        } catch (Throwable) {
            // Ignorieren und Standardfaktor nutzen
        }

        return 1.0;
    }
}
