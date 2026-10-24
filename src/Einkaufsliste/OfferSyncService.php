<?php

namespace Kai\Tools\Einkaufsliste;

use DateTimeImmutable;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Utils\MerchantNormalizer;
use Throwable;

/**
 * Orchestriert den Abruf, die Filial-Verifikation (REWE Franzosenallee 10, 04289 Leipzig)
 * und die Anreicherung von Einkaufslisten- und Vorschlags-Positionen mit aktuellen Supermarkt-Angeboten.
 */
class OfferSyncService
{
    private MarktguruClient $client;
    private OfferRepository $offerRepo;
    private OfferMatcher $matcher;
    private ProductMasterRepository $productRepo;
    private Logger $logger;

    /** @var array<string, bool> Cache verifizierter Prospekt-IDs (leafletFlightId => gültig für Franzosenallee 10) */
    private array $verifiedFlights = [];
    private bool $flightsCacheDirty = false;

    public function __construct(
        ?MarktguruClient $client = null,
        ?OfferRepository $offerRepo = null,
        ?OfferMatcher $matcher = null,
        ?ProductMasterRepository $productRepo = null,
        ?Logger $logger = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->client = $client ?? new MarktguruClient($this->logger);
        $this->offerRepo = $offerRepo ?? new OfferRepository(null, $this->logger);
        $this->matcher = $matcher ?? new OfferMatcher();
        $this->productRepo = $productRepo ?? new ProductMasterRepository();
        $this->verifiedFlights = $this->offerRepo->getVerifiedFlightsCache();
    }

    /**
     * Synchronisiert Angebote für offene Listen-Artikel sowie bald fällige Vorschlags-Artikel.
     *
     * @param int $maxProducts Maximale Anzahl API-Abfragen pro Lauf (Rate-Limit-Schutz)
     * @param bool $force Ignoriert das 20-Stunden-Cache-Intervall
     * @return array{checked_products: int, offers_found: int, configured: bool}
     */
    public function syncRelevantOffers(int $maxProducts = 25, bool $force = false, ?ShoppingListRepository $listRepo = null): array
    {
        if (!$this->client->isConfigured()) {
            return ['checked_products' => 0, 'offers_found' => 0, 'configured' => false];
        }

        $this->offerRepo->cleanupExpiredOffers();

        $listRepo = $listRepo ?? new ShoppingListRepository();
        $activeIds = $listRepo->getActiveProductIds();
        $predictable = $this->productRepo->getPredictableProducts();

        $candidatesById = [];
        $today = new DateTimeImmutable('today');

        // 1. Aktive Artikel auf der Einkaufsliste zuerst berücksichtigen
        foreach ($activeIds as $pid) {
            $prod = $this->productRepo->findById($pid);
            if ($prod && empty($prod['is_ignored'])) {
                $candidatesById[(int)$prod['id']] = $prod;
            }
        }

        // 2. Artikel aus dem Vorschlags-Horizont (fällig in <= 10 Tagen) ergänzen
        foreach ($predictable as $prod) {
            $pid = (int)$prod['id'];
            if (isset($candidatesById[$pid])) {
                continue;
            }
            if (empty($prod['last_purchased_at']) || empty($prod['avg_interval_days'])) {
                continue;
            }

            try {
                $lastDate = new DateTimeImmutable((string)$prod['last_purchased_at']);
                $daysSinceLast = max(0, (int)$lastDate->diff($today)->format('%r%a'));
                $daysUntilDue = (int)round((float)$prod['avg_interval_days'] - $daysSinceLast);
                if ($daysUntilDue <= 10) {
                    $candidatesById[$pid] = $prod;
                }
            } catch (Throwable) {
                continue;
            }
        }

        // Nach Alter der letzten Prüfung filtern (Standard-TTL: 20 Stunden)
        $toSync = [];
        $now = time();
        foreach ($candidatesById as $pid => $prod) {
            $checkedAt = $prod['offers_checked_at'] ?? null;
            if (!$force && !empty($checkedAt)) {
                $checkedTs = strtotime((string)$checkedAt);
                if ($checkedTs !== false && ($now - $checkedTs) < 72000) {
                    continue;
                }
            }
            $toSync[$pid] = $prod;
            if (count($toSync) >= $maxProducts) {
                break;
            }
        }

        if (empty($toSync)) {
            return ['checked_products' => 0, 'offers_found' => 0, 'configured' => true];
        }

        $ebonDataMap = $this->offerRepo->getKnownEbonDataByProductIds(array_keys($toSync));
        $checkedCount = 0;
        $offersFound = 0;

        foreach ($toSync as $pid => $prod) {
            $ebonNames = $ebonDataMap[$pid]['ebon_names'] ?? [];
            $savedCount = $this->syncOffersForProduct($prod, $ebonNames);
            $checkedCount++;
            $offersFound += $savedCount;
        }

        if ($this->flightsCacheDirty) {
            $this->offerRepo->saveVerifiedFlightsCache($this->verifiedFlights);
            $this->flightsCacheDirty = false;
        }

        return [
            'checked_products' => $checkedCount,
            'offers_found' => $offersFound,
            'configured' => true,
        ];
    }

    /**
     * Stellt beim Hinzufügen oder Bearbeiten eines einzelnen Artikels sicher,
     * dass dessen Angebote aktuell sind (On-Add Lookup).
     */
    public function ensureFreshOffersForProductId(int $productId, bool $force = false): void
    {
        if ($productId <= 0 || !$this->client->isConfigured()) {
            return;
        }

        $product = $this->productRepo->findById($productId);
        if (!$product) {
            return;
        }

        if (!$force && !empty($product['offers_checked_at'])) {
            $checkedTs = strtotime((string)$product['offers_checked_at']);
            if ($checkedTs !== false && (time() - $checkedTs) < 72000) {
                return;
            }
        }

        $ebonData = $this->offerRepo->getKnownEbonDataByProductIds([$productId]);
        $ebonNames = $ebonData[$productId]['ebon_names'] ?? [];
        $this->syncOffersForProduct($product, $ebonNames);

        if ($this->flightsCacheDirty) {
            $this->offerRepo->saveVerifiedFlightsCache($this->verifiedFlights);
            $this->flightsCacheDirty = false;
        }
    }

    /**
     * Führt den Marktguru-Abgleich für einen einzelnen Master-Artikel durch.
     *
     * @param array<string, mixed> $product
     * @param string[] $knownEbonNames
     */
    public function syncOffersForProduct(array $product, array $knownEbonNames = []): int
    {
        $productId = (int)($product['id'] ?? 0);
        if ($productId <= 0) {
            return 0;
        }

        $searchTerm = $this->matcher->extractSearchTerm($product);
        if ($searchTerm === '' || mb_strlen($searchTerm, 'UTF-8') < 2) {
            $this->offerRepo->markProductChecked($productId);
            return 0;
        }

        $rawResults = $this->client->searchOffers($searchTerm, 60);
        if (empty($rawResults)) {
            $this->offerRepo->saveOffersForProduct($productId, [], 'Rewe');
            return 0;
        }

        $todayStr = date('Y-m-d');
        $matchedReweOffers = [];

        foreach ($rawResults as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            // 1. Händler prüfen (aktuell Fokus auf REWE)
            $advertiserName = (string)($raw['advertisers'][0]['name'] ?? '');
            $normalizedMerchant = MerchantNormalizer::normalize($advertiserName);
            if ($normalizedMerchant !== 'REWE') {
                continue;
            }

            // 2. Gültigkeitszeitraum prüfen (validTo >= heute, validFrom <= in 2 Tagen)
            $validTo = isset($raw['validTo']) ? substr((string)$raw['validTo'], 0, 10) : '';
            $validFrom = isset($raw['validFrom']) ? substr((string)$raw['validFrom'], 0, 10) : null;
            if ($validTo === '' || $validTo < $todayStr) {
                continue;
            }
            if ($validFrom !== null && $validFrom !== '' && $validFrom > date('Y-m-d', strtotime('+2 days'))) {
                continue;
            }

            // 3. Preis validieren
            $price = isset($raw['price']) ? (float)$raw['price'] : 0.0;
            if ($price <= 0.0) {
                continue;
            }

            // 4. Regional-Tag & Filial-Verifikation (REWE Franzosenallee 10, 04289 Leipzig)
            if (!$this->matcher->hasValidRegionalTag($raw)) {
                continue;
            }

            $offerId = (string)($raw['id'] ?? '');
            $flightId = (string)($raw['leafletFlightId'] ?? '');
            if (!$this->isOfferValidForLocalRewe($offerId, $flightId)) {
                continue;
            }

            // 5. Fachliche Relevanz & Kopf-Nomen-Prüfung gegen den gesuchten Artikel
            if (!$this->matcher->isRelevantMatch($searchTerm, $raw, $knownEbonNames)) {
                continue;
            }

            $brandName = trim((string)($raw['brand']['name'] ?? ''));
            $productTitle = trim((string)($raw['product']['name'] ?? $searchTerm));
            $rawDesc = (string)($raw['description'] ?? '');
            $apiRefPrice = isset($raw['referencePrice']) && is_numeric($raw['referencePrice']) ? (float)$raw['referencePrice'] : null;
            $apiUnitShort = isset($raw['unit']['shortName']) ? (string)$raw['unit']['shortName'] : null;

            $parsedUnit = $this->matcher->parseUnitAndReferencePrice($rawDesc, $apiRefPrice, $apiUnitShort);
            $oldPrice = isset($raw['oldPrice']) && (float)$raw['oldPrice'] > $price ? (float)$raw['oldPrice'] : null;

            $matchedReweOffers[] = [
                'external_offer_id' => $offerId,
                'leaflet_flight_id' => $flightId !== '' ? $flightId : null,
                'market' => 'Rewe',
                'brand_name' => $brandName !== '' ? $brandName : null,
                'product_title' => $productTitle,
                'description' => $parsedUnit['clean_description'] !== '' ? $parsedUnit['clean_description'] : null,
                'unit_size' => $parsedUnit['unit_size'],
                'price' => $price,
                'old_price' => $oldPrice,
                'reference_price' => $parsedUnit['reference_price'],
                'normalized_ref_price' => $parsedUnit['normalized_ref_price'],
                'is_preferred_brand' => $this->matcher->isPreferredBrand($brandName, $knownEbonNames),
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
            ];
        }

        return $this->offerRepo->saveOffersForProduct($productId, $matchedReweOffers, 'Rewe');
    }

    /**
     * Reichert eine Liste von Einkaufslisten-Positionen (shopping_list_items) in einem Batch-Lookup
     * um aktive Angebote an.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function enrichShoppingItemsWithOffers(array $items): array
    {
        if (empty($items)) {
            return $items;
        }

        $productIds = [];
        foreach ($items as $it) {
            if (!empty($it['product_id'])) {
                $productIds[] = (int)$it['product_id'];
            }
        }

        $offersByProduct = $this->offerRepo->getActiveOffersByProductIds($productIds);

        foreach ($items as &$item) {
            $pid = !empty($item['product_id']) ? (int)$item['product_id'] : 0;
            $productOffers = $pid > 0 ? ($offersByProduct[$pid] ?? []) : [];

            $reweOffers = array_values(array_filter($productOffers, fn(array $o) => $o['market'] === 'Rewe'));
            $globusOffers = array_values(array_filter($productOffers, fn(array $o) => $o['market'] === 'Globus'));

            $bestRewe = $reweOffers[0] ?? null;
            $bestGlobus = $globusOffers[0] ?? null;

            $itemMarket = (string)($item['market'] ?? 'Rewe');
            $activeMarketOffer = null;
            $otherMarketOffer = null;

            if ($itemMarket === 'Rewe') {
                $activeMarketOffer = $bestRewe;
                if ($bestGlobus && (!$bestRewe || $this->isClearlyCheaper($bestGlobus, $bestRewe))) {
                    $otherMarketOffer = $bestGlobus;
                }
            } elseif ($itemMarket === 'Globus') {
                $activeMarketOffer = $bestGlobus;
                if ($bestRewe && (!$bestGlobus || $this->isClearlyCheaper($bestRewe, $bestGlobus))) {
                    $otherMarketOffer = $bestRewe;
                }
            } else {
                // Übergreifend: bestes verfügbares Angebot zeigen
                $activeMarketOffer = $bestRewe ?? $bestGlobus;
            }

            $item['offers'] = $productOffers;
            $item['rewe_offer'] = $bestRewe;
            $item['rewe_offers_count'] = count($reweOffers);
            $item['active_market_offer'] = $activeMarketOffer;
            $item['other_market_offer'] = $otherMarketOffer;
        }
        unset($item);

        return $items;
    }

    /**
     * Vergleicht zwei Angebote fair (bevorzugt über den normierten Grundpreis, sonst Verkaufspreis).
     *
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $current
     */
    private function isClearlyCheaper(array $candidate, array $current): bool
    {
        if (!empty($candidate['normalized_ref_price']) && !empty($current['normalized_ref_price'])) {
            return (float)$candidate['normalized_ref_price'] < (float)$current['normalized_ref_price'];
        }
        return (float)$candidate['price'] < (float)$current['price'];
    }

    /**
     * Prüft über den Prospekt-ID-Cache (leafletFlightId) bzw. den Marktguru-Stores-Endpunkt,
     * ob das Angebot in der Filiale REWE Franzosenallee 10 (04289 Leipzig) gültig ist.
     */
    private function isOfferValidForLocalRewe(string $offerId, string $flightId): bool
    {
        if ($flightId !== '' && array_key_exists($flightId, $this->verifiedFlights)) {
            return (bool)$this->verifiedFlights[$flightId];
        }

        if ($offerId === '') {
            return false;
        }

        $isValid = $this->client->verifyOfferForLocalStore($offerId);
        if ($flightId !== '') {
            $this->verifiedFlights[$flightId] = $isValid;
            $this->flightsCacheDirty = true;
        }

        return $isValid;
    }
}
