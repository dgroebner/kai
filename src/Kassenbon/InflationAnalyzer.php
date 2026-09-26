<?php

namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\Utils\MerchantNormalizer;

/**
 * Analysiert historische Kassenbon-Positionen auf Preisentwicklung,
 * Preissprünge, Werbeangebote und Inflations-Trends.
 *
 * Vergleicht Artikel strikt anhand ihres normalisierten Namens und Händlers,
 * um Verzerrungen durch unterschiedliche Packungsgrößen oder Händlerdifferenzen
 * auszuschließen, und errechnet Grundpreise (je kg/L).
 */
class InflationAnalyzer
{
    /**
     * Schwellenwert in Prozent für die Erkennung eines plötzlichen Preissprungs.
     */
    public const float PRICE_JUMP_THRESHOLD_PERCENT = 15.0;

    /**
     * Schwellenwert in Prozent zur Abgrenzung von Teuerung / Verbilligung vs. stabiler Preis.
     */
    public const float STABLE_THRESHOLD_PERCENT = 2.0;

    /**
     * Schwellenwert in Prozent unter dem Medianpreis, ab dem ein Kauf als Sonderangebot gilt.
     */
    public const float DEAL_THRESHOLD_PERCENT = 15.0;

    /**
     * Analysiert die übergebenen Kassenbon-Positionen.
     *
     * @param array<int, array<string, mixed>> $rawItems Chronologisch sortierte Positionen
     * @param int $minPurchases Mindestanzahl an Käufen (Standard 3, min. 2)
     * @param string|null $filterStore Optionaler Filter nach Händler
     * @param string|null $filterCategory Optionaler Filter nach Kategorie
     * @param bool $excludeDeals Wenn true, werden Angebotspreise bei der Trendberechnung ignoriert
     * @return array<string, mixed> Auswertungsergebnis mit Metriken und Produktliste
     */
    public function analyze(
        array $rawItems,
        int $minPurchases = 3,
        ?string $filterStore = null,
        ?string $filterCategory = null,
        bool $excludeDeals = false
    ): array {
        $minPurchases = max(2, $minPurchases);

        // 1. Gruppierung nach normalisiertem Artikel + Händler
        $grouped = [];
        $availableStores = [];
        $availableCategories = [];

        foreach ($rawItems as $item) {
            $rawName = trim((string)($item['name'] ?? ''));
            if ($rawName === '') {
                continue;
            }

            // Pfand, Leergut und Coupons ignorieren
            if (preg_match('/^(leergut|pfand|rückgabe|pfanddose|pfandflasche|rabatt|coupon|gutschein)/i', $rawName)) {
                continue;
            }

            $unitPrice = (float)($item['unit_price'] ?? 0);
            if ($unitPrice <= 0) {
                continue;
            }

            // Exakte Normalisierung von Mehrfach-Leerzeichen
            $cleanName = (string)preg_replace('/\s+/', ' ', $rawName);
            $normalizedStore = MerchantNormalizer::normalize((string)($item['store'] ?? ''));
            $category = trim((string)($item['category'] ?? 'Sonstiges'));
            if ($category === '') {
                $category = 'Sonstiges';
            }

            $availableStores[$normalizedStore] = true;
            $availableCategories[$category] = true;

            // Optionaler Filter vorab anwenden
            if ($filterStore !== null && $filterStore !== '' && $normalizedStore !== $filterStore) {
                continue;
            }
            if ($filterCategory !== null && $filterCategory !== '' && $category !== $filterCategory) {
                continue;
            }

            // Eindeutiger Schlüssel: Händler + exakter normalisierter Artikelname
            $productKey = $normalizedStore . ':::' . mb_strtolower($cleanName, 'UTF-8');

            if (!isset($grouped[$productKey])) {
                $grouped[$productKey] = [
                    'key' => $productKey,
                    'name' => $cleanName,
                    'store' => $normalizedStore,
                    'category' => $category,
                    'purchases' => [],
                ];
            }

            $grouped[$productKey]['purchases'][] = [
                'id' => (int)$item['id'],
                'receipt_id' => (int)$item['receipt_id'],
                'purchase_date' => (string)$item['purchase_date'],
                'unit_price' => $unitPrice,
                'quantity' => (float)($item['quantity'] ?? 1.0),
                'total_price' => (float)($item['total_price'] ?? 0),
                'store' => $normalizedStore,
            ];
        }

        // 2. Artikel filtern (Mindestanzahl & mind. 2 verschiedene Kaufdaten)
        $analyzedProducts = [];
        $totalSpentAll = 0.0;
        $monthlyAggregates = [];
        $totalDealsCount = 0;

        foreach ($grouped as $group) {
            $purchases = $group['purchases'];
            $count = count($purchases);

            if ($count < $minPurchases) {
                continue;
            }

            $distinctDates = array_unique(array_column($purchases, 'purchase_date'));
            if (count($distinctDates) < 2) {
                continue;
            }

            // Chronologische Sortierung
            usort($purchases, function ($a, $b) {
                $cmp = strcmp($a['purchase_date'], $b['purchase_date']);
                return $cmp !== 0 ? $cmp : ($a['id'] <=> $b['id']);
            });

            // Normalpreis ermitteln (Median aller bezahlten Einzelpreise)
            $allPrices = array_column($purchases, 'unit_price');
            $sortedPrices = $allPrices;
            sort($sortedPrices);
            $pCount = count($sortedPrices);
            $medianPrice = ($pCount % 2 === 0)
                ? round(($sortedPrices[$pCount / 2 - 1] + $sortedPrices[$pCount / 2]) / 2.0, 2)
                : round($sortedPrices[(int)floor($pCount / 2)], 2);

            // Angebotserkennung pro Einkauf (Dip-Erkennung ≥ 15% unter Normalpreis)
            $dealDiscountThreshold = round($medianPrice * (1.0 - (self::DEAL_THRESHOLD_PERCENT / 100.0)), 2);
            $productDealsCount = 0;

            foreach ($purchases as &$p) {
                $uPrice = (float)$p['unit_price'];
                $isDeal = ($medianPrice > 0 && $uPrice <= $dealDiscountThreshold);
                $p['is_deal'] = $isDeal;
                $p['deal_discount_pct'] = $isDeal
                    ? round((($medianPrice - $uPrice) / $medianPrice) * 100, 1)
                    : 0.0;

                if ($isDeal) {
                    $productDealsCount++;
                    $totalDealsCount++;
                }
            }
            unset($p);

            // Ggf. Einkäufe für die Trend-Berechnung filtern, falls "excludeDeals" aktiv ist
            $trendPurchases = $purchases;
            if ($excludeDeals && $productDealsCount > 0) {
                $regularOnly = array_filter($purchases, fn($p) => !$p['is_deal']);
                if (count($regularOnly) >= 2) {
                    $trendPurchases = array_values($regularOnly);
                }
            }

            $firstPurchase = $trendPurchases[0];
            $lastPurchase = $trendPurchases[count($trendPurchases) - 1];

            $firstPrice = (float)$firstPurchase['unit_price'];
            $lastPrice = (float)$lastPurchase['unit_price'];
            $firstDate = $firstPurchase['purchase_date'];
            $lastDate = $lastPurchase['purchase_date'];

            $minPrice = min($allPrices);
            $maxPrice = max($allPrices);
            $avgPrice = round(array_sum($allPrices) / $count, 2);

            $priceChangeAbs = round($lastPrice - $firstPrice, 2);
            $priceChangePct = $firstPrice > 0
                ? round((($lastPrice - $firstPrice) / $firstPrice) * 100, 1)
                : 0.0;

            // Preissprünge ermitteln
            $maxJumpPct = 0.0;
            $maxJumpAbs = 0.0;
            $maxJumpDate = null;
            $maxJumpFrom = null;
            $maxJumpTo = null;
            $prevPrice = null;

            foreach ($trendPurchases as $p) {
                $curPrice = (float)$p['unit_price'];
                if ($prevPrice !== null && $prevPrice > 0) {
                    $jAbs = round($curPrice - $prevPrice, 2);
                    $jPct = round((($curPrice - $prevPrice) / $prevPrice) * 100, 1);
                    if (abs($jPct) > abs($maxJumpPct)) {
                        $maxJumpPct = $jPct;
                        $maxJumpAbs = $jAbs;
                        $maxJumpDate = $p['purchase_date'];
                        $maxJumpFrom = $prevPrice;
                        $maxJumpTo = $curPrice;
                    }
                }
                $prevPrice = $curPrice;

                // Monatliche Indexdaten erfassen
                $monthKey = substr($p['purchase_date'], 0, 7);
                if (!isset($monthlyAggregates[$monthKey])) {
                    $monthlyAggregates[$monthKey] = [
                        'sum_relative_pct' => 0.0,
                        'count' => 0,
                    ];
                }
                if ($firstPrice > 0) {
                    $relPct = (($curPrice - $firstPrice) / $firstPrice) * 100;
                    $monthlyAggregates[$monthKey]['sum_relative_pct'] += $relPct;
                    $monthlyAggregates[$monthKey]['count']++;
                }
            }

            $hasPriceJump = abs($maxJumpPct) >= self::PRICE_JUMP_THRESHOLD_PERCENT;

            // Trend einteilen
            if ($priceChangePct > self::STABLE_THRESHOLD_PERCENT) {
                $trend = 'increased';
            } elseif ($priceChangePct < -self::STABLE_THRESHOLD_PERCENT) {
                $trend = 'decreased';
            } else {
                $trend = 'stable';
            }

            $totalSpent = round(array_sum(array_column($purchases, 'total_price')), 2);
            $totalQuantity = round(array_sum(array_column($purchases, 'quantity')), 3);
            $totalSpentAll += $totalSpent;

            // Grundpreis parsen
            $lastQuantity = (float)($lastPurchase['quantity'] ?? 1.0);
            $quantityInfo = ProductQuantityParser::parse($group['name'], $lastPrice, $lastQuantity);

            $firstQuantityInfo = ProductQuantityParser::parse($group['name'], $firstPrice, (float)($firstPurchase['quantity'] ?? 1.0));
            $basePriceChangePct = 0.0;
            if ($quantityInfo['has_base_price'] && $firstQuantityInfo['has_base_price'] && $firstQuantityInfo['base_price'] > 0) {
                $basePriceChangePct = round((($quantityInfo['base_price'] - $firstQuantityInfo['base_price']) / $firstQuantityInfo['base_price']) * 100, 1);
            }

            $analyzedProducts[] = [
                'key' => $group['key'],
                'name' => $group['name'],
                'store' => $group['store'],
                'category' => $group['category'],
                'purchase_count' => $count,
                'distinct_dates_count' => count($distinctDates),
                'first_date' => $firstDate,
                'first_price' => $firstPrice,
                'last_date' => $lastDate,
                'last_price' => $lastPrice,
                'median_price' => $medianPrice,
                'min_price' => $minPrice,
                'max_price' => $maxPrice,
                'avg_price' => $avgPrice,
                'price_change_abs' => $priceChangeAbs,
                'price_change_pct' => $priceChangePct,
                'max_jump_pct' => $maxJumpPct,
                'max_jump_abs' => $maxJumpAbs,
                'max_jump_date' => $maxJumpDate,
                'max_jump_from' => $maxJumpFrom,
                'max_jump_to' => $maxJumpTo,
                'has_price_jump' => $hasPriceJump,
                'deals_count' => $productDealsCount,
                'has_deals' => $productDealsCount > 0,
                'trend' => $trend,
                'total_spent' => $totalSpent,
                'total_quantity' => $totalQuantity,
                'quantity_info' => $quantityInfo,
                'base_price' => $quantityInfo['base_price'],
                'formatted_base_price' => $quantityInfo['formatted_base_price'],
                'formatted_package' => $quantityInfo['formatted_package'],
                'base_unit' => $quantityInfo['base_unit'],
                'base_price_change_pct' => $basePriceChangePct,
                'purchases' => $purchases,
            ];
        }

        // 3. Makro-Statistiken aggregieren
        $totalProducts = count($analyzedProducts);
        $increasedCount = 0;
        $decreasedCount = 0;
        $stableCount = 0;
        $jumpsCount = 0;
        $productsWithDealsCount = 0;

        $sumPct = 0.0;
        $weightedSumPct = 0.0;

        foreach ($analyzedProducts as $p) {
            if ($p['trend'] === 'increased') {
                $increasedCount++;
            } elseif ($p['trend'] === 'decreased') {
                $decreasedCount++;
            } else {
                $stableCount++;
            }

            if ($p['has_price_jump']) {
                $jumpsCount++;
            }

            if ($p['has_deals']) {
                $productsWithDealsCount++;
            }

            $sumPct += $p['price_change_pct'];
            $weightedSumPct += ($p['price_change_pct'] * $p['total_spent']);
        }

        $avgInflationPct = $totalProducts > 0
            ? round($sumPct / $totalProducts, 1)
            : 0.0;

        $weightedInflationPct = ($totalProducts > 0 && $totalSpentAll > 0)
            ? round($weightedSumPct / $totalSpentAll, 1)
            : 0.0;

        // Top-Listen erstellen
        $topIncreases = array_filter($analyzedProducts, fn($p) => $p['price_change_pct'] > 0);
        usort($topIncreases, fn($a, $b) => $b['price_change_pct'] <=> $a['price_change_pct']);
        $topIncreases = array_slice($topIncreases, 0, 5);

        $topDecreases = array_filter($analyzedProducts, fn($p) => $p['price_change_pct'] < 0);
        usort($topDecreases, fn($a, $b) => $a['price_change_pct'] <=> $b['price_change_pct']);
        $topDecreases = array_slice($topDecreases, 0, 5);

        $topJumps = array_filter($analyzedProducts, fn($p) => $p['has_price_jump']);
        usort($topJumps, fn($a, $b) => abs($b['max_jump_pct']) <=> abs($a['max_jump_pct']));
        $topJumps = array_slice($topJumps, 0, 5);

        // Monats-Trend vorbereiten
        ksort($monthlyAggregates);
        $monthlyTrendLabels = [];
        $monthlyTrendValues = [];
        foreach ($monthlyAggregates as $mKey => $mData) {
            $dt = \DateTime::createFromFormat('Y-m', $mKey);
            $label = $dt ? $dt->format('m/Y') : $mKey;
            $monthlyTrendLabels[] = $label;
            $monthlyTrendValues[] = $mData['count'] > 0
                ? round($mData['sum_relative_pct'] / $mData['count'], 1)
                : 0.0;
        }

        usort($analyzedProducts, fn($a, $b) => $b['price_change_pct'] <=> $a['price_change_pct']);

        $storesList = array_keys($availableStores);
        sort($storesList, SORT_NATURAL | SORT_FLAG_CASE);

        $categoriesList = array_keys($availableCategories);
        sort($categoriesList, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'total_products' => $totalProducts,
            'total_spent_all' => round($totalSpentAll, 2),
            'increased_count' => $increasedCount,
            'decreased_count' => $decreasedCount,
            'stable_count' => $stableCount,
            'jumps_count' => $jumpsCount,
            'total_deals_count' => $totalDealsCount,
            'products_with_deals_count' => $productsWithDealsCount,
            'exclude_deals_active' => $excludeDeals,
            'avg_inflation_pct' => $avgInflationPct,
            'weighted_inflation_pct' => $weightedInflationPct,
            'top_increases' => $topIncreases,
            'top_decreases' => $topDecreases,
            'top_jumps' => $topJumps,
            'monthly_trend' => [
                'labels' => $monthlyTrendLabels,
                'values' => $monthlyTrendValues,
            ],
            'available_stores' => $storesList,
            'available_categories' => $categoriesList,
            'products' => $analyzedProducts,
        ];
    }
}
