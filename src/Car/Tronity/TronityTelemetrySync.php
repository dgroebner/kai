<?php

namespace Kai\Tools\Car\Tronity;

use Kai\Tools\Car\VehicleChargeRepository;
use Kai\Tools\Car\VehicleTripRepository;
use Kai\Tools\Shared\Log\Logger;
use Exception;
use DateTime;
use DateTimeZone;

class TronityTelemetrySync
{
    private TronityClient $client;
    private VehicleTripRepository $tripRepo;
    private VehicleChargeRepository $chargeRepo;
    private GeofenceService $geofenceService;
    private HomeChargeAnalyzer $chargeAnalyzer;
    private Logger $logger;

    public function __construct(
        ?TronityClient $client = null,
        ?VehicleTripRepository $tripRepo = null,
        ?VehicleChargeRepository $chargeRepo = null,
        ?GeofenceService $geofenceService = null,
        ?HomeChargeAnalyzer $chargeAnalyzer = null,
        ?Logger $logger = null
    ) {
        $this->client = $client ?? new TronityClient();
        $this->tripRepo = $tripRepo ?? new VehicleTripRepository();
        $this->chargeRepo = $chargeRepo ?? new VehicleChargeRepository();
        $this->geofenceService = $geofenceService ?? new GeofenceService();
        $this->chargeAnalyzer = $chargeAnalyzer ?? new HomeChargeAnalyzer();
        $this->logger = $logger ?? new Logger(14);
    }

    public function syncTripsAndCharges(?string $targetVehicleId = null): void
    {
        if (!$this->client->isConfigured()) {
            return;
        }

        $vehicleId = $targetVehicleId ?? ($_ENV['TRONITY_VEHICLE_ID'] ?? '');
        if (empty($vehicleId)) {
            $vehicles = $this->client->getVehicles();
            if (!empty($vehicles)) {
                $vehicleId = (string)($vehicles[0]['id'] ?? '');
            }
        }

        if (empty($vehicleId)) {
            $this->logger->warn("TronityTelemetrySync: Konnte keine Fahrzeug-ID für Trips/Charges Sync ermitteln.");
            return;
        }

        try {
            $this->syncTrips($vehicleId);
        } catch (Exception $e) {
            $this->logger->error("TronityTelemetrySync: Fehler beim Sync der Fahrten.", ['error' => $e->getMessage()]);
        }

        try {
            $this->syncCharges($vehicleId);
        } catch (Exception $e) {
            $this->logger->error("TronityTelemetrySync: Fehler beim Sync der Ladevorgänge.", ['error' => $e->getMessage()]);
        }
    }

    private function syncTrips(string $vehicleId): void
    {
        $tripsData = $this->client->getTrips($vehicleId);
        $trips = $tripsData['data'] ?? (is_array($tripsData) ? $tripsData : []);

        $synced = 0;
        foreach ($trips as $trip) {
            if (empty($trip['id']) || empty($trip['record_date']) || empty($trip['start_time'])) {
                continue;
            }

            $startTimeUtc = $this->parseToUtc($trip['start_time']);
            $endTimeUtc = $this->parseToUtc($trip['end_time'] ?? $trip['record_date']);
            
            $startLat = $trip['start_location']['latitude'] ?? null;
            $startLon = $trip['start_location']['longitude'] ?? null;
            $endLat = $trip['end_location']['latitude'] ?? null;
            $endLon = $trip['end_location']['longitude'] ?? null;

            $startLocStr = $this->geofenceService->getLocationType($startLat, $startLon) === 'HOME' ? 'Zuhause' : ($trip['start_location']['address'] ?? 'Unterwegs');
            $endLocStr = $this->geofenceService->getLocationType($endLat, $endLon) === 'HOME' ? 'Zuhause' : ($trip['end_location']['address'] ?? 'Unterwegs');

            $data = [
                'tronity_trip_id' => (string)$trip['id'],
                'start_time' => $startTimeUtc,
                'end_time' => $endTimeUtc,
                'duration_min' => (int)($trip['duration'] ?? 0),
                'mileage_start_km' => (int)($trip['odometer_start'] ?? 0),
                'mileage_end_km' => (int)($trip['odometer_end'] ?? 0),
                'distance_km' => round((float)($trip['distance'] ?? 0), 1),
                'avg_speed_kmh' => round((float)($trip['average_speed'] ?? 0), 1),
                'soc_start_pct' => (int)($trip['soc_start'] ?? 0),
                'soc_end_pct' => (int)($trip['soc_end'] ?? 0),
                'delta_soc_pct' => (int)(abs(($trip['soc_start'] ?? 0) - ($trip['soc_end'] ?? 0))),
                'consumed_kwh' => round((float)($trip['consumption'] ?? 0), 2),
                'avg_consumption_kwh_100km' => round((float)($trip['consumption_100km'] ?? 0), 2),
                'temperature_c' => isset($trip['outside_temp']) ? round((float)$trip['outside_temp'], 1) : null,
                'start_lat' => $startLat,
                'start_lon' => $startLon,
                'end_lat' => $endLat,
                'end_lon' => $endLon,
                'start_location' => $startLocStr,
                'end_location' => $endLocStr,
            ];

            $this->tripRepo->saveTrip($data);
            $synced++;
        }

        if ($synced > 0) {
            $this->logger->info("TronityTelemetrySync: {$synced} Fahrten synchronisiert.");
        }
    }

    private function syncCharges(string $vehicleId): void
    {
        $chargesData = $this->client->getCharges($vehicleId);
        $charges = $chargesData['data'] ?? (is_array($chargesData) ? $chargesData : []);

        $synced = 0;
        foreach ($charges as $charge) {
            if (empty($charge['id']) || empty($charge['record_date'])) {
                continue;
            }

            $startTimeUtc = $this->parseToUtc($charge['start_time'] ?? $charge['record_date']); // Tronity might not always have start_time in older API
            $endTimeUtc = $this->parseToUtc($charge['end_time'] ?? $charge['record_date']);
            
            $lat = $charge['location']['latitude'] ?? null;
            $lon = $charge['location']['longitude'] ?? null;
            $locationType = $this->geofenceService->getLocationType($lat, $lon);

            $chargedNetKwh = round((float)($charge['charge_energy'] ?? 0), 2);
            
            $data = [
                'tronity_charge_id' => (string)$charge['id'],
                'start_time' => $startTimeUtc,
                'end_time' => $endTimeUtc,
                'duration_min' => (int)($charge['duration'] ?? 0),
                'soc_start_pct' => (int)($charge['soc_start'] ?? 0),
                'soc_end_pct' => (int)($charge['soc_end'] ?? 0),
                'delta_soc_pct' => (int)(abs(($charge['soc_end'] ?? 0) - ($charge['soc_start'] ?? 0))),
                'charged_net_kwh' => $chargedNetKwh,
                'avg_charge_power_kw' => round((float)($charge['average_power'] ?? 0), 2),
                'charge_mode' => $charge['charge_mode'] ?? null,
                'lat' => $lat,
                'lon' => $lon,
                'location_type' => $locationType,
                'tariff_category' => null,
            ];

            if ($locationType === 'HOME' && $chargedNetKwh > 0.1) {
                $analysis = $this->chargeAnalyzer->analyze($startTimeUtc, $endTimeUtc, $chargedNetKwh);
                $data = array_merge($data, $analysis);
            }

            $this->chargeRepo->saveCharge($data);
            $synced++;
        }

        if ($synced > 0) {
            $this->logger->info("TronityTelemetrySync: {$synced} Ladevorgänge synchronisiert.");
        }
    }

    private function parseToUtc(mixed $rawTime): string
    {
        if (empty($rawTime)) {
            return gmdate('Y-m-d H:i:s');
        }
        try {
            if (is_numeric($rawTime)) {
                $sec = (int)($rawTime > 100000000000 ? $rawTime / 1000 : $rawTime);
                return gmdate('Y-m-d H:i:s', $sec);
            }
            $dt = new DateTime((string)$rawTime);
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return gmdate('Y-m-d H:i:s');
        }
    }
}
