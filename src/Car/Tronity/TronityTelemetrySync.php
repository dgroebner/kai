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
                if (empty($charge['id']) || empty($charge['startTime'])) {
                    continue;
                }

                $startTimeUtc = $this->parseToUtc($charge['startTime']);
                $endTimeUtc = $this->parseToUtc($charge['endTime'] ?? $charge['createdAt'] ?? $charge['startTime']);

                // Tronity returns startTime in ms
                $durationMin = isset($charge['endTime']) && isset($charge['startTime']) ? round(($charge['endTime'] - $charge['startTime']) / 60000) : 0;

                $lat = $charge['location']['latitude'] ?? $charge['latitude'] ?? null;
                $lon = $charge['location']['longitude'] ?? $charge['longitude'] ?? null;
                
                // If location is missing from charge API, try to fetch from recent telemetry...
                if ($lat === null || $lon === null) {
                    $stmt = \Kai\Tools\Shared\Db\Database::getInstance()->getConnection()->prepare("
                        SELECT latitude, longitude 
                        FROM vehicle_telemetry_log 
                        WHERE latitude IS NOT NULL 
                        AND car_captured_at BETWEEN :start - INTERVAL 2 HOUR AND :end + INTERVAL 2 HOUR
                        ORDER BY ABS(TIMESTAMPDIFF(SECOND, car_captured_at, :start)) ASC 
                        LIMIT 1
                    ");
                    $stmt->execute([':start' => $startTimeUtc, ':end' => $endTimeUtc]);
                    $locRow = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if ($locRow) {
                        $lat = (float)$locRow['latitude'];
                        $lon = (float)$locRow['longitude'];
                    }
                }

                $locationType = $this->geofenceService->getLocationType($lat, $lon);

                $chargedNetKwh = round((float)($charge['kWh'] ?? $charge['charge_energy'] ?? 0), 2);

                $data = [
                    'tronity_charge_id' => (string)$charge['id'],
                    'start_time' => $startTimeUtc,
                    'end_time' => $endTimeUtc,
                    'duration_min' => (int)$durationMin,
                    'soc_start_pct' => (int)($charge['startLevel'] ?? $charge['soc_start'] ?? 0),
                    'soc_end_pct' => (int)($charge['endLevel'] ?? $charge['soc_end'] ?? 0),
                    'delta_soc_pct' => (int)(abs(($charge['endLevel'] ?? $charge['soc_end'] ?? 0) - ($charge['startLevel'] ?? $charge['soc_start'] ?? 0))),
                    'charged_net_kwh' => $chargedNetKwh,
                    'avg_charge_power_kw' => round((float)($charge['max'] ?? $charge['average_power'] ?? 0), 2),
                    'charge_mode' => isset($charge['ac']) ? ($charge['ac'] ? 'AC' : 'DC') : null,
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
