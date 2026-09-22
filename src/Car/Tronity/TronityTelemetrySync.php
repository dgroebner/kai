<?php

namespace Kai\Tools\Car\Tronity;

use Kai\Tools\Car\VehicleChargeRepository;
use Kai\Tools\Shared\Log\Logger;
use DateTime;
use DateTimeZone;

/**
 * Synchronisiert die Ladehistorie des Fahrzeugs von TRONITY.
 */
class TronityTelemetrySync
{
    private TronityClient $client;
    private VehicleChargeRepository $chargeRepo;
    private GeofenceService $geofenceService;
    private HomeChargeAnalyzer $chargeAnalyzer;
    private Logger $logger;

    public function __construct(
        ?TronityClient $client = null,
        ?VehicleChargeRepository $chargeRepo = null,
        ?GeofenceService $geofenceService = null,
        ?HomeChargeAnalyzer $chargeAnalyzer = null,
        ?Logger $logger = null
    ) {
        $this->client = $client ?? new TronityClient();
        $this->chargeRepo = $chargeRepo ?? new VehicleChargeRepository();
        $this->geofenceService = $geofenceService ?? new GeofenceService();
        $this->chargeAnalyzer = $chargeAnalyzer ?? new HomeChargeAnalyzer();
        $this->logger = $logger ?? new Logger(14);
    }

    /**
     * Alias für Abwärtskompatibilität
     */
    public function syncTripsAndCharges(?string $targetVehicleId = null): void
    {
        $this->syncCharges($targetVehicleId);
    }

    /**
     * Synchronisiert Ladevorgänge von TRONITY.
     */
    public function syncCharges(?string $targetVehicleId = null): void
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
            $this->logger->warn("TronityTelemetrySync: Konnte keine Fahrzeug-ID für Lade-Sync ermitteln.");
            return;
        }

        try {
            $chargesData = $this->client->getCharges($vehicleId);
            $charges = $chargesData['data'] ?? (is_array($chargesData) ? $chargesData : []);

            $synced = 0;
            foreach ($charges as $charge) {
                if (empty($charge['id']) || empty($charge['record_date'])) {
                    continue;
                }

                $startTimeUtc = $this->parseToUtc($charge['start_time'] ?? $charge['record_date']);
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
        } catch (\Throwable $e) {
            $this->logger->info("TronityTelemetrySync: Ladevorgänge-Sync übersprungen ({$e->getMessage()}).");
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
