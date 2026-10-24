<?php

namespace Kai\Tools\Einkaufsliste;

use Kai\Tools\Shared\Log\Logger;

/**
 * HTTP-Client für die Marktguru REST-API (api.marktguru.de)
 * zum Abruf regionaler Supermarkt-Angebote inkl. filialgenauer Verifikation
 * für REWE Franzosenallee 10 (04289 Leipzig).
 */
class MarktguruClient
{
    private const string BASE_URL = 'https://api.marktguru.de/api/v1';

    private string $apiKey;
    private string $clientKey;
    private string $zipCode;
    private float $storeLat;
    private float $storeLon;
    private string $addressFilter;
    private Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
        $this->apiKey = trim((string)($_ENV['MARKTGURU_API_KEY'] ?? ''), " \t\n\r\0\x0B\"'");
        $this->clientKey = trim((string)($_ENV['MARKTGURU_CLIENT_KEY'] ?? ''), " \t\n\r\0\x0B\"'");
        $this->zipCode = trim((string)($_ENV['MARKTGURU_ZIP_CODE'] ?? '04289'), " \t\n\r\0\x0B\"'") ?: '04289';
        $this->storeLat = (float)($_ENV['MARKTGURU_STORE_LAT'] ?? 51.3095);
        $this->storeLon = (float)($_ENV['MARKTGURU_STORE_LON'] ?? 12.4385);
        $this->addressFilter = trim((string)($_ENV['MARKTGURU_STORE_ADDRESS_FILTER'] ?? 'Franzosenallee'), " \t\n\r\0\x0B\"'") ?: 'Franzosenallee';
    }

    /**
     * Prüft, ob API- und Client-Key in der Umgebung hinterlegt sind.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->clientKey !== '';
    }

    /**
     * Sucht nach aktuellen Angeboten für einen Suchbegriff im konfigurierten PLZ-Gebiet.
     *
     * @param string $searchTerm Bereinigter Suchbegriff (z. B. "Butter", "Kaffee")
     * @param int $limit Maximale Anzahl Treffer
     * @return array<int, array<string, mixed>>
     */
    public function searchOffers(string $searchTerm, int $limit = 60): array
    {
        $term = trim($searchTerm);
        if ($term === '' || !$this->isConfigured()) {
            return [];
        }

        $query = http_build_query([
            'as' => 'web',
            'limit' => max(1, min(100, $limit)),
            'offset' => 0,
            'q' => $term,
            'zipCode' => $this->zipCode,
        ]);

        $data = $this->requestJson(self::BASE_URL . '/offers/search?' . $query);
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            return [];
        }

        return $data['results'];
    }

    /**
     * Prüft anhand der Angebots-ID und der lokalen GPS-Koordinaten, ob das Prospekt/Angebot
     * tatsächlich in der lokalen REWE-Filiale (Franzosenallee 10, 04289 Leipzig) gültig ist.
     */
    public function verifyOfferForLocalStore(int|string $offerId): bool
    {
        $cleanId = preg_replace('/[^0-9]/', '', (string)$offerId);
        if ($cleanId === '' || !$this->isConfigured()) {
            return false;
        }

        $query = http_build_query([
            'as' => 'mobile',
            'limit' => 5,
            'zipCode' => $this->zipCode,
            'latitude' => $this->storeLat,
            'longitude' => $this->storeLon,
        ]);

        $data = $this->requestJson(self::BASE_URL . '/offers/' . $cleanId . '/stores?' . $query);
        $stores = isset($data['results']) && is_array($data['results'])
            ? $data['results']
            : (is_array($data) ? $data : []);

        if (empty($stores)) {
            return false;
        }

        foreach ($stores as $store) {
            if (!is_array($store)) {
                continue;
            }
            $loc = isset($store['location']) && is_array($store['location']) ? $store['location'] : $store;
            $address = (string)($loc['address'] ?? '');
            $rawZips = $loc['zipCodes'] ?? '';
            $zipStr = is_array($rawZips) ? implode(',', $rawZips) : (string)$rawZips;

            $matchesAddress = $this->addressFilter === '' || mb_stripos($address, $this->addressFilter, 0, 'UTF-8') !== false;
            $matchesZip = $zipStr === '' || str_contains($zipStr, $this->zipCode);

            if ($matchesAddress && $matchesZip) {
                return true;
            }
        }

        return false;
    }

    /**
     * Führt einen authentifizierten GET-Request gegen die Marktguru-API aus.
     *
     * @return array<mixed>|null
     */
    private function requestJson(string $url): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $disableSsl = ($_ENV['GEMINI_DISABLE_SSL'] ?? 'false') === 'true';

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => !$disableSsl,
            CURLOPT_SSL_VERIFYHOST => $disableSsl ? 0 : 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'x-apikey: ' . $this->apiKey,
                'x-clientkey: ' . $this->clientKey,
                'User-Agent: Mozilla/5.0 (compatible; KaiShoppingAssistant/1.0)',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        // Automatischer Fallback bei fehlendem lokalen CA-Zertifikatsbundle (z. B. lokale Windows-Umgebung)
        if (($curlErrno === 60 || str_contains($curlError, 'certificate')) && !$disableSsl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
        }

        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $this->logger->warn('MarktguruClient: API-Abruf fehlgeschlagen.', [
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $curlError,
            ]);
            return null;
        }

        $decoded = json_decode((string)$response, true);
        return is_array($decoded) ? $decoded : null;
    }
}
