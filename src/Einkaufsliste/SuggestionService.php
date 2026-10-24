<?php

namespace Kai\Tools\Einkaufsliste;

use DateTimeImmutable;
use Kai\Tools\Shared\Log\Logger;
use Throwable;

/**
 * Erzeugt intelligente Vorschläge für den Wocheneinkauf basierend auf
 * Verbrauchszyklen, sächsischen Schulferien, vorhandenen Listeneinträgen
 * und aktuellen Supermarkt-Angeboten (inkl. Vorrats-Deals & Markt-Optimierung).
 */
class SuggestionService
{
    private const int STOCKUP_MAX_DAYS_UNTIL_DUE = 7;
    private const float STOCKUP_MIN_INTERVAL_DAYS = 6.0;

    private ProductMasterRepository $productRepo;
    private ShoppingListRepository $listRepo;
    private HolidayService $holidayService;
    private MarketCategoryRepository $categoryRepo;
    private OfferRepository $offerRepo;
    private Logger $logger;

    public function __construct(
        ?ProductMasterRepository $productRepo = null,
        ?ShoppingListRepository $listRepo = null,
        ?HolidayService $holidayService = null,
        ?MarketCategoryRepository $categoryRepo = null,
        ?OfferRepository $offerRepo = null
    ) {
        $this->logger = new Logger();
        $this->productRepo = $productRepo ?? new ProductMasterRepository();
        $this->listRepo = $listRepo ?? new ShoppingListRepository();
        $this->holidayService = $holidayService ?? new HolidayService();
        $this->categoryRepo = $categoryRepo ?? new MarketCategoryRepository();
        $this->offerRepo = $offerRepo ?? new OfferRepository(null, $this->logger);
    }

    /**
     * Ermittelt alle Artikel, die für den nächsten Wocheneinkauf fällig sind
     * oder als Vorrats-Deal wegen eines aktuellen Wochenangebots vorgezogen werden sollten.
     *
     * @param int $forecastWindowDays Vorausschau in Tagen (z. B. 3 Tage vor Fälligkeit)
     * @return array<int, array<string, mixed>>
     */
    public function generateSuggestions(int $forecastWindowDays = 3): array
    {
        $predictableProducts = $this->productRepo->getPredictableProducts();
        if (empty($predictableProducts)) {
            return [];
        }

        $activeItemNames = $this->listRepo->getActiveItemNames();
        $activeProductIds = $this->listRepo->getActiveProductIds();

        $allPredictableIds = array_map(fn(array $p) => (int)$p['id'], $predictableProducts);
        $offersByProduct = [];
        $ebonDataByProduct = [];
        try {
            $offersByProduct = $this->offerRepo->getActiveOffersByProductIds($allPredictableIds);
            $ebonDataByProduct = $this->offerRepo->getKnownEbonDataByProductIds($allPredictableIds);
        } catch (Throwable $e) {
            $this->logger->warn('SuggestionService: Fehler beim Laden der Angebotsdaten.', ['error' => $e->getMessage()]);
        }

        $holidayContext = $this->holidayService->getHolidayContext();
        $isInHoliday = $holidayContext['is_holiday'];
        $schoolSnackState = $holidayContext['school_snack_state'] ?? 'normal';
        $applyHolidayBoost = $isInHoliday;

        $today = new DateTimeImmutable('today');
        $suggestions = [];

        foreach ($predictableProducts as $product) {
            $productId = (int)$product['id'];
            // Bereits offene Artikel nach ID überspringen
            if (in_array($productId, $activeProductIds, true)) {
                continue;
            }

            $displayName = !empty($product['custom_label']) ? trim($product['custom_label']) : $product['name'];
            $normName = mb_strtolower(trim($product['name']), 'UTF-8');
            $normLabel = !empty($product['custom_label']) ? mb_strtolower(trim($product['custom_label']), 'UTF-8') : null;

            // Bereits offene Artikel in der Einkaufsliste überspringen (nach Originalname oder Label)
            if (in_array($normName, $activeItemNames, true) || ($normLabel !== null && in_array($normLabel, $activeItemNames, true))) {
                continue;
            }

            $holidayFactor = (float)($product['holiday_factor'] ?? 1.00);
            $isSchoolSnack = ($holidayFactor < 0.05);

            // 🥪 Brotbüchsen- / Schulzeit-Logik:
            // Wenn vor den Ferien (letzter Einkauf) oder mitten in den Ferien -> pausieren!
            if ($isSchoolSnack) {
                if ($schoolSnackState === 'pre_holiday_pause' || $schoolSnackState === 'holiday_pause') {
                    continue;
                }
            }

            $lastDate = new DateTimeImmutable($product['last_purchased_at']);
            $baseInterval = (float)$product['avg_interval_days'];

            // In den Ferien bei Mehrbedarf-Artikeln das Intervall verkürzen
            $effectiveInterval = $baseInterval;
            $holidayAdapted = false;
            $holidayBadge = null;

            if ($isSchoolSnack) {
                if ($schoolSnackState === 'back_to_school_prep') {
                    $holidayAdapted = true;
                    $holidayBadge = '🎒 Schulstart-Vorbereitung';
                } else {
                    $holidayBadge = '🥪 Brotbüchse';
                }
            } elseif ($applyHolidayBoost && $holidayFactor > 1.0) {
                $effectiveInterval = max(1.0, round($baseInterval / $holidayFactor, 1));
                $holidayAdapted = true;
                $holidayBadge = '🏖️ Ferien-Mehrbedarf (+' . (int)round(($holidayFactor - 1) * 100) . '%)';
            }

            // Zeit seit letztem Kauf berechnen
            $daysSinceLast = max(0, (int)$lastDate->diff($today)->format('%r%a'));
            $daysUntilDue = (int)round($effectiveInterval - $daysSinceLast);

            // Für Schulstart-Vorbereitung: Brotbüchsenartikel fällig stellen
            if ($isSchoolSnack && $schoolSnackState === 'back_to_school_prep') {
                $daysUntilDue = min($daysUntilDue, 0);
            }

            $rawCat = $product['default_category'] ?? null;
            $canonicalCat = $this->categoryRepo->canonicalizeCategory($rawCat) ?? 'Sonstiges';

            $productOffers = $offersByProduct[$productId] ?? [];
            $bestOffer = $productOffers[0] ?? null;
            $hasOffer = $bestOffer !== null;

            $isRegularDue = ($daysUntilDue <= $forecastWindowDays);
            $isStockupDeal = !$isRegularDue
                && $hasOffer
                && $daysUntilDue <= self::STOCKUP_MAX_DAYS_UNTIL_DUE
                && $effectiveInterval >= self::STOCKUP_MIN_INTERVAL_DAYS
                && $canonicalCat !== 'Brot & Backwaren';

            if ($isRegularDue || $isStockupDeal) {
                $urgencyPercent = round(($daysSinceLast / max(1.0, $effectiveInterval)) * 100);
                $preferredMarket = $product['preferred_market'] ?? 'Rewe';
                $recommendedMarket = $preferredMarket;
                $marketSwitchedByOffer = false;

                if ($bestOffer !== null) {
                    $offerMarket = (string)($bestOffer['market'] ?? 'Rewe');
                    if ($preferredMarket !== $offerMarket && $preferredMarket !== 'Übergreifend') {
                        $recommendedMarket = $offerMarket;
                        $marketSwitchedByOffer = true;
                    }
                }

                $avgHistoricalPrice = $ebonDataByProduct[$productId]['avg_price'] ?? null;

                $suggestions[] = [
                    'product_id' => $productId,
                    'name' => $displayName,
                    'original_name' => $product['name'],
                    'custom_label' => $product['custom_label'] ?? null,
                    'preferred_market' => $preferredMarket,
                    'recommended_market' => $recommendedMarket,
                    'market_switched_by_offer' => $marketSwitchedByOffer,
                    'default_category' => $canonicalCat,
                    'default_unit' => $product['default_unit'] ?? 'Stück',
                    'suggested_quantity' => 1.00,
                    'avg_interval_days' => $baseInterval,
                    'effective_interval' => $effectiveInterval,
                    'last_purchased_at' => $product['last_purchased_at'],
                    'days_since_last' => $daysSinceLast,
                    'days_until_due' => $daysUntilDue,
                    'urgency_percent' => max(0, min(200, (int)$urgencyPercent)),
                    'is_overdue' => $daysUntilDue < 0,
                    'is_stockup_deal' => $isStockupDeal,
                    'holiday_adapted' => $holidayAdapted,
                    'holiday_badge' => $holidayBadge,
                    'offers' => $productOffers,
                    'best_offer' => $bestOffer,
                    'avg_historical_price' => $avgHistoricalPrice,
                ];
            }
        }

        // Regulär fällige Artikel zuerst (mit leichtem Boost für aktive Angebote), danach Vorrats-Deals
        usort($suggestions, function (array $a, array $b): int {
            if ($a['is_stockup_deal'] !== $b['is_stockup_deal']) {
                return $a['is_stockup_deal'] ? 1 : -1;
            }
            $scoreA = (int)$a['urgency_percent'] + ($a['best_offer'] !== null ? 8 : 0);
            $scoreB = (int)$b['urgency_percent'] + ($b['best_offer'] !== null ? 8 : 0);
            return $scoreB <=> $scoreA;
        });

        return $suggestions;
    }

    /**
     * Übernimmt einen Vorschlag in die aktive Einkaufsliste.
     */
    public function acceptSuggestion(int $productId, ?string $market = null, ?float $quantity = null, ?string $note = null): int
    {
        $product = $this->productRepo->findById($productId);
        if (!$product) {
            return 0;
        }

        $displayName = !empty($product['custom_label']) ? trim($product['custom_label']) : $product['name'];
        $rawCat = $product['default_category'] ?? null;
        $category = $this->categoryRepo->canonicalizeCategory($rawCat) ?? 'Sonstiges';

        // Falls im Artikelstamm noch eine unkanonische Kategorie stand, direkt im Master heilen
        if ($rawCat !== null && $rawCat !== '' && $rawCat !== $category) {
            $this->productRepo->updateMaster($productId, ['default_category' => $category]);
        }

        $validMarket = ($market !== null && in_array($market, ['Rewe', 'Globus', 'Übergreifend'], true))
            ? $market
            : ($product['preferred_market'] ?? 'Rewe');

        return $this->listRepo->addItem([
            'product_id' => $product['id'],
            'name' => $displayName,
            'quantity' => $quantity ?? 1.00,
            'unit' => $product['default_unit'] ?? 'Stück',
            'market' => $validMarket,
            'category' => $category,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'is_spontaneous' => 0,
            'source' => 'suggestion',
        ]);
    }

    /**
     * Übernimmt mehrere Vorschläge auf einmal.
     *
     * @param int[] $productIds
     * @return int Anzahl hinzugefügter Artikel
     */
    public function acceptMultipleSuggestions(array $productIds): int
    {
        $added = 0;
        foreach ($productIds as $id) {
            if ($this->acceptSuggestion((int)$id) > 0) {
                $added++;
            }
        }
        return $added;
    }
}
