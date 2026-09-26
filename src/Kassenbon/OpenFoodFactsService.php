<?php

namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\Log\Logger;

/**
 * Service für den Abruf von Lebensmittel-Stammdaten, EAN-Codes,
 * Marken und Füllmengen aus der offenen Open Food Facts Datenbank.
 */
class OpenFoodFactsService
{
    private Logger $logger;

    public function __construct()
    {
        $this->logger = new Logger(14);
    }

    /**
     * Sucht nach einem Produkt in Open Food Facts anhand des Artikelnamens.
     *
     * @param string $searchTerm Artikelname oder Suchbegriff
     * @return array<string, mixed>|null Gefundene Produktinformationen oder null
     */
    public function searchProduct(string $searchTerm): ?array
    {
        $cleanTerm = trim($searchTerm);
        if ($cleanTerm === '') {
            return null;
        }

        // Bereinige Händler-Zusätze und Mengenangaben für bessere Trefferquote
        $searchQuery = preg_replace('/\b(rewe|globus|edeka|lidl|aldi|netto|penny|kaufland)\b/i', '', $cleanTerm);
        $searchQuery = trim((string)preg_replace('/\s+/', ' ', $searchQuery));
        if ($searchQuery === '') {
            $searchQuery = $cleanTerm;
        }

        $url = 'https://de.openfoodfacts.org/cgi/search.pl?search_terms='
            . urlencode($searchQuery)
            . '&search_simple=1&action=process&json=1&page_size=3';

        $this->logger->info("OpenFoodFactsService: Suche nach Produkt...", ['query' => $searchQuery]);

        $ch = curl_init($url);
        if ($ch === false) {
            $this->logger->error("OpenFoodFactsService: cURL konnte nicht initialisiert werden.");
            return null;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'KaiPrivateApp - Version 1.0 (contact: https://kai.agent-smith.de)');
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

        if (($_ENV['DISABLE_EXTERNAL_SSL_VERIFY'] ?? 'false') === 'true') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($response === false || $httpCode !== 200) {
            $this->logger->warn("OpenFoodFactsService: API-Anfrage fehlgeschlagen.", [
                'http_code' => $httpCode,
                'error' => $error,
            ]);
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['products'])) {
            $this->logger->info("OpenFoodFactsService: Kein Produkt gefunden.", ['query' => $searchQuery]);
            return null;
        }

        $product = $data['products'][0];

        $result = [
            'found' => true,
            'code' => (string)($product['code'] ?? ''),
            'product_name' => (string)($product['product_name_de'] ?? $product['product_name'] ?? ''),
            'brands' => (string)($product['brands'] ?? ''),
            'quantity' => (string)($product['quantity'] ?? ''),
            'nutriscore_grade' => strtoupper((string)($product['nutriscore_grade'] ?? '')),
            'image_url' => (string)($product['image_front_small_url'] ?? $product['image_url'] ?? ''),
            'categories' => (string)($product['categories'] ?? ''),
        ];

        $this->logger->info("OpenFoodFactsService: Treffer gefunden.", [
            'name' => $result['product_name'],
            'ean' => $result['code'],
            'brand' => $result['brands']
        ]);

        return $result;
    }
}
