<?php

namespace Kai\Tools\Car;

/**
 * Datentransferobjekt (DTO) für das Ergebnis einer Routen- und Ladebedarfsberechnung.
 */
class RoutePlanResult
{
    public function __construct(
        public readonly float $totalDistanceKm,
        public readonly float $estimatedConsumptionKwh,
        public readonly int $recommendedDepartureSoc,
        public readonly float $enRouteChargeKwh,
        public readonly array $chargingStops,
        public readonly string $deepLink,
        public readonly string $providerName,
        public readonly array $rawDetails = []
    ) {
    }

    /**
     * Serialisiert das Objekt in ein Array für DB-Speicherung oder JSON-APIs.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_distance_km' => $this->totalDistanceKm,
            'estimated_consumption_kwh' => $this->estimatedConsumptionKwh,
            'recommended_departure_soc' => $this->recommendedDepartureSoc,
            'en_route_charge_kwh' => $this->enRouteChargeKwh,
            'charging_stops' => $this->chargingStops,
            'deep_link' => $this->deepLink,
            'provider_name' => $this->providerName,
            'raw_details' => $this->rawDetails,
        ];
    }
}
