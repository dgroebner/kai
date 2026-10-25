<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\ShutTheBox;

class ShutTheBoxBotService
{
    /**
     * Wählt die taktisch beste Klappen-Kombination für den Wurf (d1, d2).
     *
     * Erlaubt laut Spielregeln:
     * - Addition: [d1 + d2]
     * - Subtraktion: [|d1 - d2|]
     * - Beide einzeln: [d1, d2]
     * - Einzelner Würfel: [d1] oder [d2]
     *
     * Strategie:
     * 1. Höchste Summe an abgeräumten Strafpunkten bevorzugen (z.B. Addition 10 oder beide einzeln [4, 6] = 10 Punkte).
     * 2. Bei gleicher Punktereduktion: Die höchste Einzelklappe eliminieren (10, 9, 8), um das Restrisiko zu minimieren.
     *
     * @param array<int, bool> $flaps
     * @param int $d1
     * @param int $d2
     * @return int[]|null Gewählte Klappen (z.B. [10] oder [2] oder [4, 6] oder [6])
     */
    public static function pickBestMove(array $flaps, int $d1, int $d2): ?array
    {
        $moves = ShutTheBoxService::getValidMovesForDice($flaps, $d1, $d2);
        if (empty($moves)) {
            return null;
        }

        usort($moves, function (array $mA, array $mB) {
            $sumA = array_sum($mA['flaps']);
            $sumB = array_sum($mB['flaps']);
            if ($sumA !== $sumB) {
                return $sumB <=> $sumA; // Höchste Strafpunkte-Reduktion zuerst
            }

            $maxA = max($mA['flaps']);
            $maxB = max($mB['flaps']);
            return $maxB <=> $maxA; // Höhere Einzelklappe abräumen
        });

        return $moves[0]['flaps'];
    }

    /**
     * Alias für Abwärtskompatibilität.
     */
    public static function pickBestCombination(array $flaps, int $targetSum): ?array
    {
        $d1 = min(6, (int)ceil($targetSum / 2));
        $d2 = $targetSum - $d1;
        return self::pickBestMove($flaps, $d1, $d2);
    }
}
