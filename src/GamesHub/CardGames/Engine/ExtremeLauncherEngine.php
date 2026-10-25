<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\CardGames\Engine;

class ExtremeLauncherEngine
{
    /**
     * Berechnet die Auswurfmenge des Uno Extreme Launchers.
     * Gewichte:
     * - 0 Karten: ~55% (Glück gehabt!)
     * - 1 Karte: ~20%
     * - 2 Karten: ~12%
     * - 3-4 Karten: ~10%
     * - 5-8 Karten: ~3% (Jackpot-Alarm!)
     */
    public static function rollDispenseCount(int $hitCount = 1): int
    {
        $totalDispense = 0;

        for ($h = 0; $h < $hitCount; $h++) {
            $rand = random_int(1, 100);

            if ($rand <= 55) {
                $dispense = 0;
            } elseif ($rand <= 75) {
                $dispense = 1;
            } elseif ($rand <= 87) {
                $dispense = 2;
            } elseif ($rand <= 97) {
                $dispense = random_int(3, 4);
            } else {
                $dispense = random_int(5, 8);
            }

            $totalDispense += $dispense;
        }

        return $totalDispense;
    }

    /**
     * Zieht N Karten vom Nachziehstapel mit automatischem Reshuffle des Ablagestapels.
     *
     * @param int $count Gewünschte Kartenanzahl
     * @param array &$drawPile Referenz auf den Ziehstapel
     * @param array &$discardPile Referenz auf den Ablagestapel
     * @return array Gezogene Karten
     */
    public static function drawCards(int $count, array &$drawPile, array &$discardPile): array
    {
        if ($count <= 0) {
            return [];
        }

        $drawn = [];

        for ($i = 0; $i < $count; $i++) {
            if (empty($drawPile)) {
                // Deck ist leer -> Ablagestapel (bis auf oberste Karte) neu mischen!
                if (count($discardPile) > 1) {
                    $topCard = array_pop($discardPile);
                    $newDeck = DeckFactory::shuffleDeck($discardPile);
                    $drawPile = $newDeck;
                    $discardPile = [$topCard];
                } else {
                    // Keine Karten mehr verfügbar
                    break;
                }
            }

            $card = array_pop($drawPile);
            if ($card !== null) {
                $drawn[] = $card;
            }
        }

        return $drawn;
    }
}
