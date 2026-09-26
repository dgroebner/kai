<?php

namespace Kai\Tools\Kassenbon;

/**
 * Erkennt Packungsgrößen und Mengenangaben aus Artikelbezeichnungen
 * und berechnet normierte Grundpreise (z. B. je kg, je 100g, je Liter).
 */
class ProductQuantityParser
{
    /**
     * Extrahiert Menge und Einheit aus einem Artikelnamen und errechnet den normierten Grundpreis.
     *
     * @param string $productName Roher Artikelname vom Kassenbon
     * @param float $unitPrice Bezahlter Stückpreis in Euro
     * @param float $quantity Gekaufte Menge (z. B. Gewichtsfaktor bei Waageartikeln)
     * @return array{
     *     has_base_price: bool,
     *     package_amount: float|null,
     *     package_unit: string|null,
     *     base_unit: string|null,
     *     base_price: float|null,
     *     formatted_base_price: string|null,
     *     formatted_package: string|null
     * }
     */
    public static function parse(string $productName, float $unitPrice, float $quantity = 1.0): array
    {
        $emptyResult = [
            'has_base_price' => false,
            'package_amount' => null,
            'package_unit' => null,
            'base_unit' => null,
            'base_price' => null,
            'formatted_base_price' => null,
            'formatted_package' => null,
        ];

        if ($unitPrice <= 0) {
            return $emptyResult;
        }

        $name = ' ' . mb_strtolower(trim($productName), 'UTF-8') . ' ';

        // 1. Multipack mit Volumen oder Gewicht: z. B. "6x0,5l", "4x125g", "6 x 0,33 l"
        if (preg_match('/\b(\d+)\s*[x*]\s*(\d+(?:[.,]\d+)?)\s*(l|liter|ltr|ml|kg|g|gramm)\b/i', $name, $m)) {
            $packCount = (int)$m[1];
            $singleAmount = (float)str_replace(',', '.', $m[2]);
            $unit = strtolower($m[3]);
            $totalAmount = $packCount * $singleAmount;

            return self::calculateNormalizedPrice($unitPrice, $totalAmount, $unit, "{$packCount}x{$singleAmount}{$unit}");
        }

        // 2. Kiloware / Waageartikel (z. B. "Bananen kg", "Äpfel /kg", "Tomaten lose kg")
        // Wenn im Namen explizit "kg" steht oder quantity ein Kommawert ist (z.B. 0.742)
        if (preg_match('/(\/|\b)(kg|kilo)\b/i', $name)) {
            $basePrice = round($unitPrice, 2);
            return [
                'has_base_price' => true,
                'package_amount' => 1.0,
                'package_unit' => 'kg',
                'base_unit' => 'kg',
                'base_price' => $basePrice,
                'formatted_base_price' => number_format($basePrice, 2, ',', '.') . ' € / kg',
                'formatted_package' => '1 kg',
            ];
        }

        // 3. Volumen: Liter (L, Liter, Ltr)
        if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*(l|liter|ltr)\b/i', $name, $m)) {
            $amount = (float)str_replace(',', '.', $m[1]);
            if ($amount > 0) {
                return self::calculateNormalizedPrice($unitPrice, $amount, 'l', "{$amount} L");
            }
        }

        // 4. Volumen: Milliliter (ml)
        if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*ml\b/i', $name, $m)) {
            $amount = (float)str_replace(',', '.', $m[1]);
            if ($amount > 0) {
                return self::calculateNormalizedPrice($unitPrice, $amount, 'ml', "{$amount} ml");
            }
        }

        // 5. Gewicht: Kilogramm (kg)
        if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*kg\b/i', $name, $m)) {
            $amount = (float)str_replace(',', '.', $m[1]);
            if ($amount > 0) {
                return self::calculateNormalizedPrice($unitPrice, $amount, 'kg', "{$amount} kg");
            }
        }

        // 6. Gewicht: Gramm (g, gr, gramm)
        if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*(g|gr|gramm)\b/i', $name, $m)) {
            $amount = (float)str_replace(',', '.', $m[1]);
            if ($amount > 0) {
                return self::calculateNormalizedPrice($unitPrice, $amount, 'g', "{$amount} g");
            }
        }

        // 7. Stückzahl-Multipack: z. B. "10er", "6er", "12er", "10 stück", "6 stk"
        if (preg_match('/\b(\d+)\s*(er|stk|stück)\b/i', $name, $m)) {
            $count = (int)$m[1];
            if ($count > 1) {
                $basePrice = round($unitPrice / $count, 2);
                return [
                    'has_base_price' => true,
                    'package_amount' => (float)$count,
                    'package_unit' => 'Stk',
                    'base_unit' => 'Stk',
                    'base_price' => $basePrice,
                    'formatted_base_price' => number_format($basePrice, 2, ',', '.') . ' € / Stk',
                    'formatted_package' => "{$count} Stk",
                ];
            }
        }

        return $emptyResult;
    }

    /**
     * Berechnet den normierten Preis auf 1 kg, 100 g oder 1 Liter.
     */
    private static function calculateNormalizedPrice(
        float $unitPrice,
        float $amount,
        string $unit,
        string $formattedPackage
    ): array {
        $unit = strtolower($unit);

        // Volumen
        if (in_array($unit, ['l', 'liter', 'ltr'], true)) {
            $pricePerLiter = round($unitPrice / $amount, 2);
            return [
                'has_base_price' => true,
                'package_amount' => $amount,
                'package_unit' => 'L',
                'base_unit' => '1 L',
                'base_price' => $pricePerLiter,
                'formatted_base_price' => number_format($pricePerLiter, 2, ',', '.') . ' € / L',
                'formatted_package' => $formattedPackage,
            ];
        }

        if ($unit === 'ml') {
            $amountInLiter = $amount / 1000.0;
            $pricePerLiter = round($unitPrice / $amountInLiter, 2);
            return [
                'has_base_price' => true,
                'package_amount' => $amount,
                'package_unit' => 'ml',
                'base_unit' => '1 L',
                'base_price' => $pricePerLiter,
                'formatted_base_price' => number_format($pricePerLiter, 2, ',', '.') . ' € / L',
                'formatted_package' => $formattedPackage,
            ];
        }

        // Gewicht
        if ($unit === 'kg') {
            $pricePerKg = round($unitPrice / $amount, 2);
            return [
                'has_base_price' => true,
                'package_amount' => $amount,
                'package_unit' => 'kg',
                'base_unit' => '1 kg',
                'base_price' => $pricePerKg,
                'formatted_base_price' => number_format($pricePerKg, 2, ',', '.') . ' € / kg',
                'formatted_package' => $formattedPackage,
            ];
        }

        if (in_array($unit, ['g', 'gr', 'gramm'], true)) {
            if ($amount >= 200) {
                // Bei >= 200g üblicherweise Grundpreis je 1 kg
                $pricePerKg = round(($unitPrice / $amount) * 1000.0, 2);
                return [
                    'has_base_price' => true,
                    'package_amount' => $amount,
                    'package_unit' => 'g',
                    'base_unit' => '1 kg',
                    'base_price' => $pricePerKg,
                    'formatted_base_price' => number_format($pricePerKg, 2, ',', '.') . ' € / kg',
                    'formatted_package' => $formattedPackage,
                ];
            } else {
                // Bei kleineren Mengen (< 200g wie Gewürze, Hefe etc.) Grundpreis je 100 g
                $pricePer100g = round(($unitPrice / $amount) * 100.0, 2);
                return [
                    'has_base_price' => true,
                    'package_amount' => $amount,
                    'package_unit' => 'g',
                    'base_unit' => '100 g',
                    'base_price' => $pricePer100g,
                    'formatted_base_price' => number_format($pricePer100g, 2, ',', '.') . ' € / 100g',
                    'formatted_package' => $formattedPackage,
                ];
            }
        }

        return [
            'has_base_price' => false,
            'package_amount' => null,
            'package_unit' => null,
            'base_unit' => null,
            'base_price' => null,
            'formatted_base_price' => null,
            'formatted_package' => null,
        ];
    }
}
