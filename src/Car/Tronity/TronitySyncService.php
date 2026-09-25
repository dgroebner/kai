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
     * @param bool $force Wenn true, wird der Live-State in der DB forciert aktualisiert
     * @return array Ergebnis-Metadaten für API-Antwort oder Logs
     */
    public function sync(?string $targetVehicleId = null, ?array $incomingPayload = null, bool $force = false): array
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
        $soc = null;
        if (isset($record['level']) && is_numeric($record['level'])) {
            $soc = (int)round((float)$record['level']);
        } elseif (isset($record['soc']) && is_numeric($record['soc'])) {
            $soc = (int)round((float)$record['soc']);
        } elseif (isset($record['batteryLevel']) && is_numeric($record['batteryLevel'])) {
            $soc = (int)round((float)$record['batteryLevel']);
        } elseif (isset($record['battery']['level']) && is_numeric($record['battery']['level'])) {
            $soc = (int)round((float)$record['battery']['level']);
        } elseif (isset($record['battery']['soc']) && is_numeric($record['battery']['soc'])) {
            $soc = (int)round((float)$record['battery']['soc']);
        } elseif (isset($record['data']['level']) && is_numeric($record['data']['level'])) {
            $soc = (int)round((float)$record['data']['level']);
        }

        $range = 0;
        if (isset($record['range']) && is_numeric($record['range'])) {
            $range = (int)round((float)$record['range']);
        } elseif (isset($record['remainingRange']) && is_numeric($record['remainingRange'])) {
            $range = (int)round((float)$record['remainingRange']);
        } elseif (isset($record['range_km']) && is_numeric($record['range_km'])) {
            $range = (int)round((float)$record['range_km']);
        } elseif (isset($record['battery']['range']) && is_numeric($record['battery']['range'])) {
            $range = (int)round((float)$record['battery']['range']);
        } elseif (isset($record['battery']['remainingRange']) && is_numeric($record['battery']['remainingRange'])) {
            $range = (int)round((float)$record['battery']['remainingRange']);
        } elseif (isset($record['data']['range']) && is_numeric($record['data']['range'])) {
            $range = (int)round((float)$record['data']['range']);
        }

        // Kilometerstand (unterstützt Zahlen, verschachtelte Objekte und alternative Keys)
        $odometer = TronityClient::extractOdometer($record);
        if ($odometer === null) {
            $odometer = $this->client->resolveLatestOdometer($vehicleId);
        }
        
        // Ladeleistung (kW)
        $chargePower = null;
        if (isset($record['chargerPower']) && is_numeric($record['chargerPower'])) {
            $chargePower = round((float)$record['chargerPower'], 2);
        } elseif (isset($record['chargePower']) && is_numeric($record['chargePower'])) {
            $chargePower = round((float)$record['chargePower'], 2);
        } elseif (isset($record['power']) && is_numeric($record['power'])) {
            $chargePower = round((float)$record['power'], 2);
        } elseif (isset($record['charging']['chargerPower']) && is_numeric($record['charging']['chargerPower'])) {
            $chargePower = round((float)$record['charging']['chargerPower'], 2);
        }

        // Ladezustand normalisieren
        $chargingRaw = $record['charging'] ?? ($record['chargingState'] ?? ($record['chargeState'] ?? null));
        if (is_array($chargingRaw)) {
            $chargingRaw = $chargingRaw['charging'] ?? ($chargingRaw['status'] ?? ($chargingRaw['state'] ?? null));
        }
        $chargingState = $this->normalizeChargingState($chargingRaw, $chargePower);

        // Steckerstatus
        $plugged = null;
        if (isset($record['plugged'])) {
            $plugged = (bool)$record['plugged'];
        } elseif (isset($record['isPluggedIn'])) {
            $plugged = (bool)$record['isPluggedIn'];
        } elseif (isset($record['charging']['plugged'])) {
            $plugged = (bool)$record['charging']['plugged'];
        }
        if (str_contains($chargingState, 'CHARGING') && !str_contains($chargingState, 'NOT_READY')) {
            $plugged = true;
        }

        // GPS-Koordinaten
        $latitude = null;
        if (isset($record['latitude']) && is_numeric($record['latitude'])) {
            $latitude = (float)$record['latitude'];
        } elseif (isset($record['lat']) && is_numeric($record['lat'])) {
            $latitude = (float)$record['lat'];
        } elseif (isset($record['location']['latitude']) && is_numeric($record['location']['latitude'])) {
            $latitude = (float)$record['location']['latitude'];
        }

        $longitude = null;
        if (isset($record['longitude']) && is_numeric($record['longitude'])) {
            $longitude = (float)$record['longitude'];
        } elseif (isset($record['lng']) && is_numeric($record['lng'])) {
            $longitude = (float)$record['lng'];
        } elseif (isset($record['lon']) && is_numeric($record['lon'])) {
            $longitude = (float)$record['lon'];
        } elseif (isset($record['location']['longitude']) && is_numeric($record['location']['longitude'])) {
            $longitude = (float)$record['location']['longitude'];
        }

        // 4. Bisherigen Fahrzeugstatus für Differenzprüfung abrufen
        $dashboardRepo = new \Kai\Tools\Car\VehicleDashboardRepository();
        $currentState = $dashboardRepo->getLatestState();

        // 5. Erfassungszeitpunkt aus TRONITY-Record ermitteln
        $rawTimestamp = $this->extractTimestampFromRecord($record);
        $this->logger->info("TRONITY: Snapshot Details empfangen.", [
            'raw_timestamp' => $rawTimestamp,
            'extracted_odometer' => $odometer,
            'record_keys' => array_keys($record),
        ]);

        if (!empty($rawTimestamp)) {
            $capturedAtUtc = $this->parseTimestampToUtc($rawTimestamp);
        } else {
            $capturedAtUtc = gmdate('Y-m-d H:i:s');
        }

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

        // 6. Prüfen, ob sich relevante Fahrzeugdaten tatsächlich geändert haben
        $hasMetricsChanged = false;
        if (!$currentState) {
            $hasMetricsChanged = true;
        } else {
            if ($soc !== null && (int)$currentState['soc_percent'] !== (int)$soc) {
                $hasMetricsChanged = true;
            }
            if ($odometer !== null && (int)$currentState['mileage_km'] !== (int)$odometer) {
                $hasMetricsChanged = true;
            }
            if ($range > 0 && abs((int)$currentState['range_km'] - (int)$range) >= 2) {
                $hasMetricsChanged = true;
            }
            if ($chargingState !== null && $currentState['charging_state'] !== $chargingState) {
                $hasMetricsChanged = true;
            }
            if ($chargePower !== null && abs((float)$currentState['charge_power_kw'] - (float)$chargePower) > 0.2) {
                $hasMetricsChanged = true;
            }
            if ($plugged !== null && (int)$currentState['plug_connected'] !== (int)$plugged) {
                $hasMetricsChanged = true;
            }
            if ($latitude !== null && $currentState['latitude'] !== null && abs((float)$currentState['latitude'] - (float)$latitude) > 0.005) {
                $hasMetricsChanged = true;
            }
            if ($longitude !== null && $currentState['longitude'] !== null && abs((float)$currentState['longitude'] - (float)$longitude) > 0.005) {
                $hasMetricsChanged = true;
            }
        }

        // Zeitstempel-Differenz prüfen
        $hasNewTimestamp = false;
        if ($currentState && !empty($currentState['car_captured_at'])) {
            $currentTs = strtotime((string)$currentState['car_captured_at']);
            $newTs = strtotime($capturedAtUtc);
            if ($newTs > $currentTs) {
                $hasNewTimestamp = true;
            }
            // Falls in der DB bereits ein neuerer Zeitstempel steht (z. B. durch früheren Server-Fallback),
            // aber Messwerte sich geändert haben (z. B. Kilometerstand 1482 > 1307) oder force aktiv ist:
            // Mindestens den bisherigen DB-Stand beibehalten, damit saveState den Datensatz nicht als "veraltet" verwirft.
            if (($hasMetricsChanged || $force) && $newTs < $currentTs) {
                $capturedAtUtc = (string)$currentState['car_captured_at'];
            }
        }

        // Wenn sich die Messwerte NICHT geändert haben und kein force anliegt, Erfassungszeitpunkt beim alten Stand belassen
        if (!$hasMetricsChanged && !$force && $currentState && !empty($currentState['car_captured_at'])) {
            $capturedAtUtc = (string)$currentState['car_captured_at'];
        }

        // Neuer Verlaufs-Eintrag nur, wenn sich die Messwerte wirklich geändert haben (force erzwingt nur State-Update, kein neues Log!)
        $isNewData = $hasMetricsChanged;

        // Outdoor temp from weather module
        $outdoorTemp = null;
        try {
            $weatherService = new \Kai\Tools\Weather\WeatherService();
            $weatherForecast = $weatherService->getForecastFromDb();
            if ($weatherForecast && isset($weatherForecast['current']['temperature_2m'])) {
                $outdoorTemp = (float)$weatherForecast['current']['temperature_2m'];
            }
        } catch (\Throwable $e) {
            $this->logger->warn("TronitySyncService: Konnte Außentemperatur nicht aus WeatherService laden.", ['error' => $e->getMessage()]);
        }

        // 7. Payload für TelemetryRepository aufbauen
        $payload = [
            'vin' => $vin,
            'captured_at' => $capturedAtUtc,
            'battery' => [
                'soc' => $soc,
                'target_soc' => 80,
                'charge_power_kw' => $chargePower,
                'estimated_finish_at' => $estimatedFinishAt,
            ],
            'status' => [
                'charging_state' => $chargingState,
                'plug_connected' => $plugged,
                'is_locked' => true,
                'parking_brake' => true,
                'mileage_km' => $odometer,
                'range_km' => $range,
                'outdoor_temp_c' => $outdoorTemp,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            'raw_payload' => [
                'source' => 'tronity',
                'vehicle_id' => $vehicleId,
                'record' => $record,
            ]
        ];

        // 8. In Datenbank speichern
        // Live-State wird forciert aktualisiert, falls force anliegt oder Messwerte sich geändert haben
        $this->telemetryRepo->saveState($payload, force: $force || $hasMetricsChanged);

        // Verlaufs-Log wird bei geänderten Fahrzeugwerten oder force geschrieben
        if ($isNewData && $soc !== null && $soc > 0) {
            $this->telemetryRepo->saveLog($payload);
        }

        if ($isNewData) {
            $this->logger->info("TRONITY: Neue Telemetriedaten erfolgreich gespeichert.", [
                'vin' => $vin,
                'soc' => $soc,
                'range_km' => $range,
                'charging_state' => $chargingState,
                'charge_power_kw' => $chargePower,
                'captured_at' => $capturedAtUtc,
            ]);
        } else {
            $this->logger->info("TRONITY: Fahrzeugdaten sind unverändert ({$capturedAtUtc}), Historien-Log übersprungen.");
        }

        return [
            'success' => true,
            'is_new' => $isNewData,
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
     * Sucht rekursiv nach Zeitstempel-Feldern im gelieferten TRONITY-Record.
     */
    private function extractTimestampFromRecord(array $record): mixed
    {
        $candidateKeys = [
            'timestamp', 'lastUpdate', 'last_update', 'updatedAt', 'updated_at',
            'car_captured_time', 'car_captured_at', 'time', 'date', 'lastRecord',
            'last_record', 'recordDate', 'record_date'
        ];

        // 1. Direkt auf oberster Ebene
        foreach ($candidateKeys as $key) {
            if (!empty($record[$key])) {
                return $record[$key];
            }
        }

        // 2. In verschachtelten Objekten (z. B. battery, odometer, location, data)
        foreach (['battery', 'odometer', 'location', 'data', 'record'] as $sub) {
            if (isset($record[$sub]) && is_array($record[$sub])) {
                foreach ($candidateKeys as $key) {
                    if (!empty($record[$sub][$key])) {
                        return $record[$sub][$key];
                    }
                }
            }
        }

        return null;
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
