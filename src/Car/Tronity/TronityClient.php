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
        if ($httpCode !== 200 || empty($data['access_token'])) {
            $msg = $data['message'] ?? ($data['error'] ?? "HTTP {$httpCode}");
            $this->logger->error("TRONITY API: Authentifizierung fehlgeschlagen.", [
                'http_code' => $httpCode,
                'response' => substr($response, 0, 300)
            ]);
            throw new Exception("TRONITY API: Authentifizierung fehlgeschlagen: {$msg}");
        }

        $this->cachedToken = (string)$data['access_token'];
        $expiresIn = (int)($data['expires_in'] ?? 3600);
        $this->tokenExpiresAt = $now + $expiresIn;

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
        return is_array($response) ? $response : [];
    }

    /**
     * Ruft den aktuellsten Telemetrie-Snapshot eines Fahrzeugs ab.
     * Endpunkt: GET /v1/vehicles/{vehicleId}/last_record
     */
    public function getLastRecord(string $vehicleId): ?array
    {
        $cleanId = urlencode($vehicleId);
        $response = $this->request('GET', "/v1/vehicles/{$cleanId}/last_record");
        return is_array($response) ? $response : null;
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
    private function request(string $method, string $path, ?array $body = null): mixed
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
            $this->logger->error("TRONITY API: Request fehlgeschlagen für {$path}.", ['error' => $curlError]);
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
            $this->logger->error("TRONITY API: Unerwarteter HTTP-Status {$httpCode} für {$path}.", [
                'response' => substr($response, 0, 300)
            ]);
            throw new Exception("TRONITY API Fehler ({$httpCode}): " . ($data['message'] ?? 'Unbekannter Fehler'));
        }

        return $data;
    }
}
