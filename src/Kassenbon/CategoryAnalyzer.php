<?php
namespace Kai\Tools\Kassenbon;

class CategoryAnalyzer
{
    /**
     * Analysiert die Positionen eines Kassenbons und gruppiert sie nach Kategorien.
     *
     * @param array $items Array von Kassenbon-Positionen (muss 'category' und 'total_price' enthalten)
     * @return array Array mit Kategorien als Schlüssel und deren Summen/Prozentanteilen
     */
    public function analyze(array $items): array
    {
        $categoryTotals = [];
        $grandTotal = 0;

        foreach ($items as $item) {
            $category = $item['category'] ?? 'Sonstiges';
            $price = (float)($item['total_price'] ?? 0);

            if (!isset($categoryTotals[$category])) {
                $categoryTotals[$category] = 0;
            }
            $categoryTotals[$category] += $price;
            $grandTotal += $price;
        }

        $positiveTotal = 0;
        foreach ($categoryTotals as $total) {
            if ($total > 0) {
                $positiveTotal += $total;
            }
        }

        $analysis = [];
        foreach ($categoryTotals as $category => $total) {
            $percentage = ($positiveTotal > 0) ? ($total / $positiveTotal) * 100 : 0;
            $analysis[$category] = [
                'total' => $total,
                'percentage' => $percentage
            ];
        }

        // Nach Summe absteigend sortieren
        uasort($analysis, fn($a, $b) => $b['total'] <=> $a['total']);

        return [
            'categories' => $analysis,
            'grand_total' => $grandTotal
        ];
    }
}
