<?php

namespace Kai\Tools\Car\Tronity;

use Exception;
use Kai\Tools\Shared\Log\Logger;

/**
 * HTTP-Client für die REST-API der TRONITY Platform (https://api.tronity.tech).
 * Verwaltet den OAuth 2.0-Handshake und cached das Access-Token transient.
 */
class TronityClient
{
    private const BASE_URL = 'https://api.tronity.tech';

    private string $clientId;
    private string $clientSecret;
    private Logger $logger;
    private ?string $cachedToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(?string $clientId = null, ?string $clientSecret = null, ?Logger $logger = null)
    {
        $this->clientId = $clientId ?? ($_ENV['TRONITY_CLIENT_ID'] ?? '');
        $this->clientSecret = $clientSecret ?? ($_ENV['TRONITY_CLIENT_SECRET'] ?? '');
        $this->logger = $logger ?? new Logger(14);
    }

    /**
     * Prüft, ob gültige Zugangsdaten konfiguriert sind.
     */
    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    /**
     * Ruft ein gültiges Bearer-Access-Token ab (inkl. lokalem Dateicache).
     */
    public function getAccessToken(): string
    {
        $now = time();

        // 1. In-Memory Cache prüfen
        if ($this->cachedToken !== null && $this->tokenExpiresAt > ($now + 60)) {
            return $this->cachedToken;
        }

        // 2. Transienten File-Cache prüfen
        $cacheFile = sys_get_temp_dir() . '/tronity_token_' . md5($this->clientId) . '.json';
        if (file_exists($cacheFile)) {
            $cachedData = json_decode(@file_get_contents($cacheFile) ?: '', true);
            if (is_array($cachedData) && !empty($cachedData['token']) && ($cachedData['expires_at'] ?? 0) > ($now + 60)) {
                $this->cachedToken = (string)$cachedData['token'];
                $this->tokenExpiresAt = (int)$cachedData['expires_at'];
                return $this->cachedToken;
            }
        }

        if (!$this->isConfigured()) {
            throw new Exception("TRONITY API: TRONITY_CLIENT_ID oder TRONITY_CLIENT_SECRET nicht konfiguriert.");
        }

        // 3. Neues Token per OAuth 2.0 anfordern
        $authUrl = self::BASE_URL . '/oauth/authentication';
        $payload = json_encode([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'app',
        ]);

        $ch = curl_init($authUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->logger->error("TRONITY API: Netzwerkfehler beim Abrufen des OAuth-Tokens.", ['error' => $curlError]);
            throw new Exception("TRONITY API: Verbindungsfehler zum Authentifizierungsserver: {$curlError}");
        }

        $data = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300 || empty($data['access_token'])) {
            $msg = $data['message'] ?? ($data['error'] ?? "HTTP {$httpCode}");
            $this->logger->error("TRONITY API: Authentifizierung fehlgeschlagen.", [
                'http_code' => $httpCode,
                'response' => substr($response, 0, 300)
            ]);
            throw new Exception("TRONITY API: Authentifizierung fehlgeschlagen: {$msg}");
        }

        $this->cachedToken = (string)$data['access_token'];

        // expiresIn kann ein Integer (Sekunden) oder ein String wie "1h", "60m" sein
        $rawExpires = $data['expiresIn'] ?? ($data['expires_in'] ?? 3600);
        $expiresIn = 3600;
        if (is_numeric($rawExpires)) {
            $expiresIn = (int)$rawExpires;
        } elseif (is_string($rawExpires)) {
            $rawTrimmed = trim($rawExpires);
            if (preg_match('/^(\d+)\s*h$/i', $rawTrimmed, $m)) {
                $expiresIn = (int)$m[1] * 3600;
            } elseif (preg_match('/^(\d+)\s*m$/i', $rawTrimmed, $m)) {
                $expiresIn = (int)$m[1] * 60;
            } elseif (preg_match('/^(\d+)\s*d$/i', $rawTrimmed, $m)) {
                $expiresIn = (int)$m[1] * 86400;
            } elseif (is_numeric($rawTrimmed)) {
                $expiresIn = (int)$rawTrimmed;
            }
        }

        // Puffer von 60s abziehen, damit der Token vor tatsächlichem Ablauf erneuert wird
        $this->tokenExpiresAt = $now + max(60, $expiresIn - 60);

        // In transienten File-Cache schreiben
        @file_put_contents($cacheFile, json_encode([
            'token' => $this->cachedToken,
            'expires_at' => $this->tokenExpiresAt,
        ]));

        return $this->cachedToken;
    }

    /**
     * Ruft die Liste aller verknüpften Fahrzeuge ab.
     */
    public function getVehicles(): array
    {
        $response = $this->request('GET', '/v1/vehicles');
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }
        return is_array($response) ? $response : [];
    }

    /**
     * Extrahiert den Kilometerstand flexibel aus beliebigen TRONITY-Datenstrukturen
     * (Zahlen, verschachtelte Objekte, data-Wrapper oder alternative Schlüssel).
     */
    public static function extractOdometer(mixed $data): ?int
    {
        if ($data === null) {
            return null;
        }

        // Falls $data selbst eine gültige positive Zahl ist
        if (is_numeric($data)) {
            $val = (int)round((float)$data);
            return $val > 0 ? $val : null;
        }

        if (!is_array($data)) {
            return null;
        }

        // data-Wrapper auspacken
        if (isset($data['data']) && (is_array($data['data']) || is_numeric($data['data']))) {
            $nested = self::extractOdometer($data['data']);
            if ($nested !== null) {
                return $nested;
            }
        }

        // Mögliche Schlüssel für Kilometerstand in TRONITY
        $candidateKeys = [
            'end_odometer',
            'endOdometer',
            'odometer',
            'mileage',
            'start_odometer',
            'startOdometer',
            'distance',
            'total_distance',
            'mileage_km',
            'odometer_km',
            'value',
        ];

        foreach ($candidateKeys as $key) {
            if (isset($data[$key])) {
                if (is_numeric($data[$key])) {
                    $val = (int)round((float)$data[$key]);
                    if ($val > 0) {
                        return $val;
                    }
                } elseif (is_array($data[$key])) {
                    $nested = self::extractOdometer($data[$key]);
                    if ($nested !== null) {
                        return $nested;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Ermittelt den aktuellsten Kilometerstand über alle verfügbaren TRONITY-Endpunkte:
     * 1. /tronity/vehicles/{id}/last_record (evcc-Endpunkt)
     * 2. /v1/vehicles/{id}/trips & /tronity/vehicles/{id}/trips (End-Stand der letzten Fahrt)
     * 3. /v1/vehicles/{id}/charges & /tronity/vehicles/{id}/charges (Stand der letzten Ladung)
     * 4. /v1/vehicles/{id} (Fahrzeugdetails)
     * 5. /v1/vehicles/{id}/odometer (Dedizierter Endpunkt)
     */
    public function resolveLatestOdometer(string $vehicleId, ?int $existingOdo = null): ?int
    {
        $cleanId = urlencode($vehicleId);
        $highest = $existingOdo ?? 0;

        // 1. evcc-Endpunkt: /tronity/vehicles/{id}/last_record
        try {
            $lastRec = $this->request('GET', "/tronity/vehicles/{$cleanId}/last_record", null, true);
            $val = self::extractOdometer($lastRec);
            if ($val !== null && $val > $highest) {
                $highest = $val;
            }
        } catch (\Throwable) {}

        // 2. Letzte Fahrten (Trips)
        foreach (["/v1/vehicles/{$cleanId}/trips", "/tronity/vehicles/{$cleanId}/trips"] as $path) {
            try {
                $trips = $this->request('GET', $path, null, true);
                $tripList = isset($trips['data']) && is_array($trips['data']) ? $trips['data'] : (is_array($trips) ? $trips : []);
                if (!empty($tripList)) {
                    foreach ($tripList as $trip) {
                        if (!is_array($trip)) continue;
                        $val = self::extractOdometer($trip);
                        if ($val !== null && $val > $highest) {
                            $highest = $val;
                        }
                    }
                    if ($highest > 0) break;
                }
            } catch (\Throwable) {}
        }

        // 3. Letzte Ladungen (Charges)
        foreach (["/v1/vehicles/{$cleanId}/charges", "/tronity/vehicles/{$cleanId}/charges"] as $path) {
            try {
                $charges = $this->request('GET', $path, null, true);
                $chargeList = isset($charges['data']) && is_array($charges['data']) ? $charges['data'] : (is_array($charges) ? $charges : []);
                if (!empty($chargeList)) {
                    foreach ($chargeList as $ch) {
                        if (!is_array($ch)) continue;
                        $val = self::extractOdometer($ch);
                        if ($val !== null && $val > $highest) {
                            $highest = $val;
                        }
                    }
                    if ($highest > 0) break;
                }
            } catch (\Throwable) {}
        }

        // 4. Fahrzeug-Stammdaten: /v1/vehicles/{id}
        try {
            $veh = $this->request('GET', "/v1/vehicles/{$cleanId}", null, true);
            $val = self::extractOdometer($veh);
            if ($val !== null && $val > $highest) {
                $highest = $val;
            }
        } catch (\Throwable) {}

        // 5. Dedizierter Endpunkt: /v1/vehicles/{id}/odometer (oft 403 Forbidden)
        try {
            $odoResp = $this->request('GET', "/v1/vehicles/{$cleanId}/odometer", null, true);
            $val = self::extractOdometer($odoResp);
            if ($val !== null && $val > $highest) {
                $highest = $val;
            }
        } catch (\Throwable) {}

        return $highest > 0 ? $highest : null;
    }

    /**
     * Ruft gezielt den Kilometerstand eines Fahrzeugs ab.
     */
    public function getOdometer(string $vehicleId): ?int
    {
        return $this->resolveLatestOdometer($vehicleId);
    }

    /**
     * Ruft den aktuellsten Telemetrie-Snapshot eines Fahrzeugs ab.
     * Prüft primär den konsolidierten /bulk Endpunkt der TRONITY API.
     * Sollten essenzielle Metriken wie Odometer, Batterie oder Standort in /bulk fehlen
     * oder verschachtelt sein, werden alternative Endpunkte gezielt ergänzend abgefragt.
     */
    public function getLastRecord(string $vehicleId): ?array
    {
        $cleanId = urlencode($vehicleId);
        $merged = [];

        // 1. Primär: /bulk abrufen (enthält konsolidierte Snapshot-Daten)
        try {
            $response = $this->request('GET', "/v1/vehicles/{$cleanId}/bulk", null, true);
            $record = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
            if (!empty($record) && is_array($record)) {
                $merged = $record;
            }
        } catch (\Throwable $e) {
            $this->logger->info("TRONITY API: /bulk nicht verfügbar ({$e->getMessage()}), teste Einzel-Endpunkte...");
        }

        // Falls /bulk leer ist: evcc-Endpunkt /tronity/vehicles/{id}/last_record testen
        if (empty($merged)) {
            try {
                $lastRec = $this->request('GET', "/tronity/vehicles/{$cleanId}/last_record", null, true);
                $record = isset($lastRec['data']) && is_array($lastRec['data']) ? $lastRec['data'] : $lastRec;
                if (!empty($record) && is_array($record)) {
                    $merged = $record;
                }
            } catch (\Throwable) {}
        }

        // 2. Kilometerstand über alle verfügbaren Quellen auflösen (Bulk, Trips, Charges, etc.)
        $bulkOdo = self::extractOdometer($merged);
        $resolvedOdo = $this->resolveLatestOdometer($vehicleId, $bulkOdo);
        if ($resolvedOdo !== null) {
            $merged['odometer'] = $resolvedOdo;
        }

        // 3. Falls Batteriedaten in $merged fehlen: /battery abrufen
        $hasBattery = isset($merged['level']) || isset($merged['soc']) || isset($merged['batteryLevel']) || isset($merged['battery']);
        if (!$hasBattery) {
            try {
                $battery = $this->request('GET', "/v1/vehicles/{$cleanId}/battery", null, true);
                if (is_array($battery)) {
                    $merged = array_merge($merged, $battery['data'] ?? $battery);
                }
            } catch (\Throwable) {}
        }

        // 4. Falls Standort in $merged fehlt: /location abrufen
        $hasLocation = isset($merged['latitude']) || isset($merged['lat']) || isset($merged['location']);
        if (!$hasLocation) {
            try {
                $location = $this->request('GET', "/v1/vehicles/{$cleanId}/location", null, true);
                if (is_array($location)) {
                    $merged = array_merge($merged, $location['data'] ?? $location);
                }
            } catch (\Throwable) {}
        }

        if (!empty($merged)) {
            return $merged;
        }

        // 5. Fallback: /last_record
        try {
            $response = $this->request('GET', "/v1/vehicles/{$cleanId}/last_record", null, true);
            $record = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
            if (!empty($record) && is_array($record)) {
                return $record;
            }
        } catch (\Throwable) {}

        // 6. Fallback: Basis-Fahrzeugdetails
        try {
            $veh = $this->request('GET', "/v1/vehicles/{$cleanId}", null, true);
            if (is_array($veh) && !empty($veh)) {
                return $veh['data'] ?? $veh;
            }
        } catch (\Throwable) {}

        return null;
    }

    /**
     * Ruft die Ladehistorie eines Fahrzeugs ab.
     * Endpunkt: GET /v1/vehicles/{vehicleId}/charges
     */
    public function getCharges(string $vehicleId): array
    {
        $cleanId = urlencode($vehicleId);
        $response = $this->request('GET', "/v1/vehicles/{$cleanId}/charges");
        return is_array($response) ? $response : [];
    }

    /**
     * Ruft die Fahrtenhistorie eines Fahrzeugs ab.
     * Endpunkt: GET /v1/vehicles/{vehicleId}/trips
     */
    public function getTrips(string $vehicleId): array
    {
        $cleanId = urlencode($vehicleId);
        $response = $this->request('GET', "/v1/vehicles/{$cleanId}/trips");
        return is_array($response) ? $response : [];
    }

    /**
     * Führt eine autorisierte HTTP-Anfrage an die TRONITY API aus.
     */
    private function request(string $method, string $path, ?array $body = null, bool $silent = false): mixed
    {
        $token = $this->getAccessToken();
        $url = self::BASE_URL . $path;

        $headers = [
            "Authorization: Bearer {$token}",
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);

        if ($body !== null) {
            $json = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            if (!$silent) {
                $this->logger->error("TRONITY API: Request fehlgeschlagen für {$path}.", ['error' => $curlError]);
            }
            throw new Exception("TRONITY API: Fehler bei Anfrage an {$path}: {$curlError}");
        }

        // Token abgelaufen -> Cache verwerfen und einmalig neu versuchen
        if ($httpCode === 401) {
            $this->cachedToken = null;
            $this->tokenExpiresAt = 0;
            $cacheFile = sys_get_temp_dir() . '/tronity_token_' . md5($this->clientId) . '.json';
            @unlink($cacheFile);

            $this->logger->warn("TRONITY API: 401 Unauthorized empfangen. Erneuere Token...");
            $newToken = $this->getAccessToken();
            $headers[0] = "Authorization: Bearer {$newToken}";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        $data = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            if (!$silent) {
                $this->logger->error("TRONITY API: Unerwarteter HTTP-Status {$httpCode} für {$path}.", [
                    'response' => substr($response, 0, 300)
                ]);
            }
            throw new Exception("TRONITY API Fehler ({$httpCode}): " . ($data['message'] ?? 'Unbekannter Fehler'));
        }

        return $data;
    }
}
