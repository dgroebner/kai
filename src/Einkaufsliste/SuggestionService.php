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
        $schoolSnackState = $holidayContext['school_snack_state'] ?? 'normal';
        $isUpcomingHoliday = ($holidayContext['days_until_next'] !== null && $holidayContext['days_until_next'] <= 7);
        $applyHolidayBoost = $isInHoliday;

        $today = new DateTimeImmutable('today');
        $suggestions = [];

        foreach ($predictableProducts as $product) {
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

            $daysSinceLast = (int)$today->diff($lastDate)->format('%r%a');
            $daysUntilDue = (int)round($effectiveInterval - $daysSinceLast);

            // Für Schulstart-Vorbereitung (letzter Einkauf in den Ferien):
            // Brotbüchsenartikel auf jeden Fall fällig stellen, damit der erste Schultag vorbereitet ist!
            if ($isSchoolSnack && $schoolSnackState === 'back_to_school_prep') {
                $daysUntilDue = min($daysUntilDue, 0);
            }

            // Wenn fällig oder innerhalb des Prognosefensters
            if ($daysUntilDue <= $forecastWindowDays) {
                $urgencyPercent = round(($daysSinceLast / max(1.0, $effectiveInterval)) * 100);

                $suggestions[] = [
                    'product_id' => (int)$product['id'],
                    'name' => $displayName,
                    'original_name' => $product['name'],
                    'custom_label' => $product['custom_label'] ?? null,
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
                    'holiday_badge' => $holidayBadge,
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

        $displayName = !empty($product['custom_label']) ? trim($product['custom_label']) : $product['name'];

        return $this->listRepo->addItem([
            'product_id' => $product['id'],
            'name' => $displayName,
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
