<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\ShutTheBox;

class ShutTheBoxBotService
{
    /**
     * Wählt die taktisch beste Klappen-Kombination für die gewürfelte Augensumme.
     *
     * Strategie:
     * 1. Höchste Einzelklappen priorisieren (z.B. 10, 9, 8 eliminieren), um das Strafpunkte-Risiko zu minimieren.
     * 2. Bei gleicher Höchstklappe: Kombinationen mit weniger Klappen bevorzugen, um kleine Zahlen für spätere Würfe aufzusparen.
     *
     * @param array<int, bool> $flaps
     * @param int $targetSum
     * @return int[]|null Gewählte Klappen (z.B. [7] oder [5, 2])
     */
    public static function pickBestCombination(array $flaps, int $targetSum): ?array
    {
        $combos = ShutTheBoxService::findCombinationsForSum($flaps, $targetSum);
        if (empty($combos)) {
            return null;
        }

        // Taktische Bewertung jeder Kombination
        usort($combos, function (array $a, array $b) {
            $maxA = !empty($a) ? max($a) : 0;
            $maxB = !empty($b) ? max($b) : 0;

            // 1. Höchste Zahl zuerst abräumen
            if ($maxA !== $maxB) {
                return $maxB <=> $maxA;
            }

            // 2. Kürzere Kombinationen bevorzugen (kleine Zahlen schonen)
            return count($a) <=> count($b);
        });

        return $combos[0];
    }
}
