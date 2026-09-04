<?php

namespace Kai\Tools\Einkaufsliste;

use DateTimeImmutable;
use Kai\Tools\Shared\Log\Logger;

/**
 * Erzeugt intelligente Vorschläge für den Wocheneinkauf basierend auf
 * Verbrauchszyklen, sächsischen Schulferien und vorhandenen Listeneinträgen.
 */
class SuggestionService
{
    private ProductMasterRepository $productRepo;
    private ShoppingListRepository $listRepo;
    private HolidayService $holidayService;
    private Logger $logger;

    public function __construct(
        ?ProductMasterRepository $productRepo = null,
        ?ShoppingListRepository $listRepo = null,
        ?HolidayService $holidayService = null
    ) {
        $this->productRepo = $productRepo ?? new ProductMasterRepository();
        $this->listRepo = $listRepo ?? new ShoppingListRepository();
        $this->holidayService = $holidayService ?? new HolidayService();
        $this->logger = new Logger();
    }

    /**
     * Ermittelt alle Artikel, die für den nächsten Wocheneinkauf fällig sind.
     *
     * @param int $forecastWindowDays Vorausschau in Tagen (z. B. 3 Tage vor Fälligkeit)
     * @return array<int, array<string, mixed>>
     */
    public function generateSuggestions(int $forecastWindowDays = 3): array
    {
        $predictableProducts = $this->productRepo->getPredictableProducts();
        $activeItemNames = $this->listRepo->getActiveItemNames();

        $holidayContext = $this->holidayService->getHolidayContext();
        $isInHoliday = $holidayContext['is_holiday'];
        $isUpcomingHoliday = ($holidayContext['days_until_next'] !== null && $holidayContext['days_until_next'] <= 7);
        $applyHolidayFactor = $isInHoliday || $isUpcomingHoliday;

        $today = new DateTimeImmutable('today');
        $suggestions = [];

        foreach ($predictableProducts as $product) {
            $normName = mb_strtolower(trim($product['name']), 'UTF-8');

            // Bereits offene Artikel in der Einkaufsliste überspringen
            if (in_array($normName, $activeItemNames, true)) {
                continue;
            }

            $lastDate = new DateTimeImmutable($product['last_purchased_at']);
            $baseInterval = (float)$product['avg_interval_days'];
            $holidayFactor = (float)($product['holiday_factor'] ?? 1.00);

            // In den Ferien (oder kurz davor) bei artikelspezifischem Faktor das Intervall verkürzen
            $effectiveInterval = $baseInterval;
            $holidayAdapted = false;

            if ($applyHolidayFactor && $holidayFactor > 1.0) {
                $effectiveInterval = max(1.0, round($baseInterval / $holidayFactor, 1));
                $holidayAdapted = true;
            }

            $daysSinceLast = (int)$today->diff($lastDate)->format('%r%a');
            $daysUntilDue = (int)round($effectiveInterval - $daysSinceLast);

            // Wenn fällig oder innerhalb des Prognosefensters
            if ($daysUntilDue <= $forecastWindowDays) {
                $urgencyPercent = round(($daysSinceLast / max(1.0, $effectiveInterval)) * 100);

                $suggestions[] = [
                    'product_id' => (int)$product['id'],
                    'name' => $product['name'],
                    'preferred_market' => $product['preferred_market'] ?? 'Rewe',
                    'default_category' => $product['default_category'] ?? 'Sonstiges',
                    'default_unit' => $product['default_unit'] ?? 'Stück',
                    'suggested_quantity' => 1.00,
                    'avg_interval_days' => $baseInterval,
                    'effective_interval' => $effectiveInterval,
                    'last_purchased_at' => $product['last_purchased_at'],
                    'days_since_last' => $daysSinceLast,
                    'days_until_due' => $daysUntilDue,
                    'urgency_percent' => max(0, min(200, (int)$urgencyPercent)),
                    'is_overdue' => $daysUntilDue < 0,
                    'holiday_adapted' => $holidayAdapted,
                    'holiday_badge' => $holidayAdapted ? "Ferienanpassung (+{round(($holidayFactor - 1) * 100)}% Bedarf)" : null,
                ];
            }
        }

        // Nach Dringlichkeit absteigend sortieren
        usort($suggestions, fn($a, $b) => $b['urgency_percent'] <=> $a['urgency_percent']);

        return $suggestions;
    }

    /**
     * Übernimmt einen Vorschlag in die aktive Einkaufsliste.
     */
    public function acceptSuggestion(int $productId, ?string $market = null, ?float $quantity = null): int
    {
        $product = $this->productRepo->findById($productId);
        if (!$product) {
            return 0;
        }

        return $this->listRepo->addItem([
            'product_id' => $product['id'],
            'name' => $product['name'],
            'quantity' => $quantity ?? 1.00,
            'unit' => $product['default_unit'] ?? 'Stück',
            'market' => $market ?? $product['preferred_market'] ?? 'Rewe',
            'category' => $product['default_category'] ?? 'Sonstiges',
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
