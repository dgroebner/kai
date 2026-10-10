<?php

namespace Kai\Tools\Car;

use Kai\Tools\Shared\Log\Logger;
use Throwable;

/**
 * Provider für Routen- und Ladebedarfsberechnung über die offizielle
 * Iternio / A Better Routeplanner (ABRP) Planning API v2 (POST https://api.iternio.com/2/plan).
 *
 * Fällt bei fehlendem API-Key oder Netzwerkfehlern transparent auf den OrsHeuristicPlanner zurück.
 */
class AbrpPlanner implements RoutePlannerInterface
{
    public const PROVIDER_NAME = 'ABRP_V2';
    public const API_URL = 'https://api.iternio.com/2/plan';

    private ?string $apiKey;
    private Logger $logger;
    private OrsHeuristicPlanner $fallbackPlanner;

    public function __construct(
        ?string $apiKey = null,
        ?Logger $logger = null,
        ?OrsHeuristicPlanner $fallbackPlanner = null
    ) {
        $this->apiKey = $apiKey ?? ($_ENV['ABRP_API_KEY'] ?? null);
        $this->logger = $logger ?? new Logger(14);
        $this->fallbackPlanner = $fallbackPlanner ?? new OrsHeuristicPlanner(logger: $this->logger);
    }

    public function planRoute(
        float $startLat,
        float $startLon,
        float $destLat,
        float $destLon,
        int $targetSoc = 10,
        int $departureSoc = 80
    ): RoutePlanResult {
        if (empty($this->apiKey)) {
            $this->logger->info("AbrpPlanner: Kein ABRP_API_KEY vorhanden, delegiere an Heuristik-Planner.");
            return $this->fallbackPlanner->planRoute($startLat, $startLon, $destLat, $destLon, $targetSoc, $departureSoc);
        }

        try {
            $effectiveDepartureSoc = ($departureSoc > 0) ? $departureSoc : 80;
            $requestBody = [
                'origin' => [
                    'lat' => $startLat,
                    'lon' => $startLon,
                ],
                'destination' => [
                    'lat' => $destLat,
                    'lon' => $destLon,
                ],
                'vehicle' => [
                    'typecode' => AbrpDeepLinkBuilder::DEFAULT_VEHICLE_MODEL,
                ],
                'departure_soc' => round($effectiveDepartureSoc / 100.0, 2),
                'arrival_soc' => round($targetSoc / 100.0, 2),
            ];

            $ch = curl_init(self::API_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($requestBody),
                CURLOPT_HTTPHEADER => [
                    'X-API-KEY: ' . $this->apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'User-Agent: Kai-TripPlanner/1.0',
                ],
                CURLOPT_TIMEOUT => 12,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300 && is_string($response)) {
                $data = json_decode($response, true);
                return $this->mapAbrpResponse(
                    $data,
                    $startLat,
                    $startLon,
                    $destLat,
                    $destLon,
                    $targetSoc,
                    $departureSoc
                );
            }

            $this->logger->warn("AbrpPlanner: API-Antwortfehler (HTTP {$httpCode}), wechsle zu Fallback.", [
                'response' => is_string($response) ? substr($response, 0, 300) : null,
            ]);
        } catch (Throwable $e) {
            $this->logger->warn("AbrpPlanner: Ausnahmefehler bei API-Aufruf: " . $e->getMessage());
        }

        return $this->fallbackPlanner->planRoute($startLat, $startLon, $destLat, $destLon, $targetSoc, $departureSoc);
    }

    /**
     * Mappt die JSON-Rückgabe von ABRP v2 in ein standardisiertes RoutePlanResult.
     */
    private function mapAbrpResponse(
        array $data,
        float $startLat,
        float $startLon,
        float $destLat,
        float $destLon,
        int $targetSoc,
        int $departureSoc
    ): RoutePlanResult {
        $totalDistanceKm = (float)($data['total_distance_km'] ?? ($data['distance_km'] ?? 0.0));
        $consumptionKwh = (float)($data['consumption_kwh'] ?? ($data['total_consumption_kwh'] ?? 0.0));
        $enRouteChargeKwh = (float)($data['charge_kwh'] ?? ($data['total_charge_kwh'] ?? 0.0));

        $chargingStops = [];
        $rawStops = $data['charging_stops'] ?? ($data['charges'] ?? []);
        if (is_array($rawStops)) {
            $stopNum = 1;
            foreach ($rawStops as $stop) {
                $chargingStops[] = [
                    'stop_number' => $stopNum++,
                    'name' => (string)($stop['name'] ?? "Ladestopp {$stopNum}"),
                    'planned_kwh' => (float)($stop['charged_kwh'] ?? ($stop['kwh'] ?? 0.0)),
                    'estimated_min' => (int)($stop['duration_min'] ?? ($stop['charge_duration'] ?? 25)),
                    'power_kw' => (float)($stop['power_kw'] ?? 150.0),
                    'soc_in' => (int)(($stop['soc_arrival'] ?? 0.1) * 100),
                    'soc_out' => (int)(($stop['soc_departure'] ?? 0.8) * 100),
                ];
            }
        }

        $recommendedDepartureSoc = ($consumptionKwh <= 54.0 && $totalDistanceKm < 200.0) ? 80 : 100;
        $deepLink = AbrpDeepLinkBuilder::buildDeepLink($startLat, $startLon, $destLat, $destLon, $targetSoc);

        return new RoutePlanResult(
            totalDistanceKm: round($totalDistanceKm, 2),
            estimatedConsumptionKwh: round($consumptionKwh, 2),
            recommendedDepartureSoc: $recommendedDepartureSoc,
            enRouteChargeKwh: round($enRouteChargeKwh, 2),
            chargingStops: $chargingStops,
            deepLink: $deepLink,
            providerName: self::PROVIDER_NAME,
            rawDetails: $data
        );
    }
}
