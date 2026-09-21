<?php

namespace Kai\Tools\Car\Tronity;

use DateTime;
use DateTimeZone;
use Exception;
use Kai\Tools\Car\TelemetryRepository;
use Kai\Tools\Shared\Log\Logger;

/**
 * Synchronisations-Dienst für Fahrzeugtelemetriedaten von TRONITY.
 * Liest den aktuellsten Status des Fahrzeugs und führt die Normalisierung in
 * die interne Telemetrie- und State-Tabelle von Kai durch.
 */
class TronitySyncService
{
    private TronityClient $client;
    private TelemetryRepository $telemetryRepo;
    private Logger $logger;

    public function __construct(
        ?TronityClient $client = null,
        ?TelemetryRepository $telemetryRepo = null,
        ?Logger $logger = null
    ) {
        $this->client = $client ?? new TronityClient();
        $this->telemetryRepo = $telemetryRepo ?? new TelemetryRepository();
        $this->logger = $logger ?? new Logger(14);
    }

    /**
     * Prüft, ob TRONITY konfiguriert ist.
     */
    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Führt eine Synchronisation des aktuellen Fahrzeugstatus durch.
     *
     * @param string|null $targetVehicleId Optionale TRONITY Vehicle ID (sonst aus ENV oder erstem Auto)
     * @param array|null $incomingPayload Optionaler Payload aus einem TRONITY Webhook
     * @return array Ergebnis-Metadaten für API-Antwort oder Logs
     */
    public function sync(?string $targetVehicleId = null, ?array $incomingPayload = null): array
    {
        if (!$this->isConfigured()) {
            throw new Exception("TRONITY ist nicht konfiguriert. Bitte TRONITY_CLIENT_ID und TRONITY_CLIENT_SECRET in der .env pflegen.");
        }

        // 1. Vehicle ID ermitteln
        $vehicleId = $targetVehicleId ?? ($_ENV['TRONITY_VEHICLE_ID'] ?? '');
        $vin = $_ENV['CAR_VIN'] ?? '';

        if (empty($vehicleId) && !empty($incomingPayload)) {
            $vehicleId = (string)($incomingPayload['vehicleId'] ?? $incomingPayload['vehicle_id'] ?? '');
            if (empty($vin) && !empty($incomingPayload['vin'])) {
                $vin = (string)$incomingPayload['vin'];
            }
        }

        if (empty($vehicleId)) {
            $vehicles = $this->client->getVehicles();
            if (empty($vehicles)) {
                throw new Exception("TRONITY: Keine Fahrzeuge im Konto hinterlegt.");
            }

            // Fallback: Passendes Fahrzeug nach VIN suchen oder erstes Fahrzeug wählen
            $selectedVehicle = null;
            if (!empty($vin)) {
                foreach ($vehicles as $veh) {
                    if (!empty($veh['vin']) && strtoupper(trim($veh['vin'])) === strtoupper(trim($vin))) {
                        $selectedVehicle = $veh;
                        break;
                    }
                }
            }
            if ($selectedVehicle === null) {
                $selectedVehicle = $vehicles[0];
            }

            $vehicleId = (string)($selectedVehicle['id'] ?? '');
            if (empty($vin) && !empty($selectedVehicle['vin'])) {
                $vin = (string)$selectedVehicle['vin'];
            }
        }

        if (empty($vehicleId)) {
            throw new Exception("TRONITY: Konnte keine gültige Fahrzeug-ID ermitteln.");
        }

        // 2. Aktuellsten Telemetrie-Snapshot abrufen oder aus Payload entnehmen
        $record = null;
        if (!empty($incomingPayload)) {
            if (isset($incomingPayload['level']) || isset($incomingPayload['odometer'])) {
                $record = $incomingPayload;
            } elseif (isset($incomingPayload['data']) && is_array($incomingPayload['data']) && (isset($incomingPayload['data']['level']) || isset($incomingPayload['data']['odometer']))) {
                $record = $incomingPayload['data'];
            }
        }

        // Falls kein vollständiger Record im Payload enthalten war (z. B. Event-Trigger), via API abrufen
        if (empty($record) || !is_array($record)) {
            $record = $this->client->getLastRecord($vehicleId);
        }

        if (empty($record) || !is_array($record)) {
            throw new Exception("TRONITY: Kein aktueller Telemetrie-Datensatz für Fahrzeug '{$vehicleId}' gefunden.");
        }

        // 3. Normalisierung der Felder
        $soc = isset($record['level']) && is_numeric($record['level']) ? (int)round((float)$record['level']) : null;
        $range = isset($record['range']) && is_numeric($record['range']) ? (int)round((float)$record['range']) : 0;
        $odometer = isset($record['odometer']) && is_numeric($record['odometer']) ? (int)round((float)$record['odometer']) : null;
        
        // Ladeleistung (kW)
        $chargePower = null;
        if (isset($record['chargerPower']) && is_numeric($record['chargerPower'])) {
            $chargePower = round((float)$record['chargerPower'], 2);
        } elseif (isset($record['chargePower']) && is_numeric($record['chargePower'])) {
            $chargePower = round((float)$record['chargePower'], 2);
        }

        // Ladezustand normalisieren
        $chargingRaw = $record['charging'] ?? null;
        $chargingState = $this->normalizeChargingState($chargingRaw, $chargePower);

        // Steckerstatus
        $plugged = false;
        if (isset($record['plugged'])) {
            $plugged = (bool)$record['plugged'];
        }
        if (str_contains($chargingState, 'CHARGING') && !str_contains($chargingState, 'NOT_READY')) {
            $plugged = true;
        }

        // GPS-Koordinaten
        $latitude = isset($record['latitude']) && is_numeric($record['latitude']) ? (float)$record['latitude'] : null;
        $longitude = isset($record['longitude']) && is_numeric($record['longitude']) ? (float)$record['longitude'] : null;

        // Erfassungszeitpunkt in UTC
        $capturedAtUtc = $this->parseTimestampToUtc($record['timestamp'] ?? ($record['lastUpdate'] ?? null));

        // Restladezeit & voraussichtliche Fertigstellung
        $estimatedFinishAt = null;
        $remTimeRaw = $record['chargeRemainingTime'] ?? null;
        if ($remTimeRaw !== null && is_numeric($remTimeRaw) && (float)$remTimeRaw > 0) {
            // TRONITY liefert chargeRemainingTime meist in Minuten
            $remMinutes = (int)round((float)$remTimeRaw);
            if ($remMinutes > 0) {
                $capDt = new DateTime($capturedAtUtc, new DateTimeZone('UTC'));
                $capDt->modify("+{$remMinutes} minutes");
                $estimatedFinishAt = $capDt->format('Y-m-d H:i:s');
            }
        }

        // VIN ermitteln falls noch unbekannt
        if (empty($vin) && !empty($record['vin'])) {
            $vin = (string)$record['vin'];
        }
        if (empty($vin)) {
            $vin = 'WV2ZZZEBXVH003011'; // Fallback auf bekannten ID.Buzz
        }

        // 4. Payload für TelemetryRepository aufbauen
        $payload = [
            'vin' => $vin,
            'captured_at' => $capturedAtUtc,
            'battery' => [
                'soc' => $soc,
                'target_soc' => 80,
                'charge_power_kw' => $chargePower,
                'max_temp_c' => null,
                'min_temp_c' => null,
                'estimated_finish_at' => $estimatedFinishAt,
            ],
            'status' => [
                'charging_state' => $chargingState,
                'plug_connected' => $plugged,
                'is_locked' => true,
                'parking_brake' => true,
                'mileage_km' => $odometer,
                'range_km' => $range,
                'outdoor_temp_c' => null,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            'raw_payload' => [
                'source' => 'tronity',
                'vehicle_id' => $vehicleId,
                'record' => $record,
            ]
        ];

        // 5. In Datenbank speichern
        $this->telemetryRepo->saveState($payload);

        if ($soc !== null && $soc > 0) {
            $this->telemetryRepo->saveLog($payload);
        }

        $this->logger->info("TRONITY: Telemetriedaten erfolgreich synchronisiert.", [
            'vin' => $vin,
            'soc' => $soc,
            'range_km' => $range,
            'charging_state' => $chargingState,
            'charge_power_kw' => $chargePower,
            'captured_at' => $capturedAtUtc,
        ]);

        return [
            'success' => true,
            'vin' => $vin,
            'soc' => $soc,
            'range_km' => $range,
            'charging_state' => $chargingState,
            'charge_power_kw' => $chargePower,
            'plug_connected' => $plugged,
            'mileage_km' => $odometer,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'captured_at' => $capturedAtUtc,
        ];
    }

    /**
     * Mappt den rohen TRONITY-Ladestatus in das Kai-Standard-Enum.
     */
    private function normalizeChargingState(mixed $charging, ?float $chargePower): string
    {
        if ($chargePower !== null && $chargePower > 0.05) {
            return 'CHARGE_STATE_CHARGING_HV_BATTERY';
        }

        if (is_bool($charging)) {
            return $charging ? 'CHARGE_STATE_CHARGING_HV_BATTERY' : 'CHARGE_STATE_NOT_READY_FOR_CHARGING';
        }

        $st = strtoupper(trim((string)$charging));
        if (str_contains($st, 'CHARG') && !str_contains($st, 'NO') && !str_contains($st, 'NOT')) {
            return 'CHARGE_STATE_CHARGING_HV_BATTERY';
        }
        if (str_contains($st, 'COMPLETE') || str_contains($st, 'FINISHED')) {
            return 'CHARGE_STATE_CHARGE_PURPOSE_REACHED_AND_NOT_CONSERVATION_CHARGING';
        }
        if (str_contains($st, 'READY')) {
            return 'CHARGE_STATE_READY_FOR_CHARGING';
        }

        return 'CHARGE_STATE_NOT_READY_FOR_CHARGING';
    }

    /**
     * Konvertiert einen Zeitstempel (Millisekunden oder ISO-String) in UTC 'Y-m-d H:i:s'.
     */
    private function parseTimestampToUtc(mixed $rawTime): string
    {
        if (empty($rawTime)) {
            return gmdate('Y-m-d H:i:s');
        }

        try {
            // Falls Millisekunden-Timestamp (z. B. 1726935600000)
            if (is_numeric($rawTime)) {
                $sec = (int)($rawTime > 100000000000 ? $rawTime / 1000 : $rawTime);
                return gmdate('Y-m-d H:i:s', $sec);
            }

            // Falls ISO-Datumsstring
            $dt = new DateTime((string)$rawTime);
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return gmdate('Y-m-d H:i:s');
        }
    }
}
