<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Loch6;

class Loch6BotService
{
    /**
     * Entscheidet, ob der Bot noch einmal würfelt oder seinen Zug sichert und weitergibt.
     *
     * @param int $occupiedPits Anzahl aktuell belegter Mulden (0 bis 5)
     * @param int $stonesInHand Anzahl Steine, die der Bot noch im Vorrat hat
     * @param int $rollsInTurn Anzahl Würfe im aktuellen Zug
     */
    public static function shouldRollAgain(int $occupiedPits, int $stonesInHand, int $rollsInTurn): bool
    {
        // 1. All-in Siegchance: Hat der Bot nur noch 1 Stein?
        // Jeder Wurf auf eine freie Mulde oder die 6 beendet das Spiel sofort als Sieger!
        if ($stonesInHand === 1) {
            return $occupiedPits <= 3;
        }

        // 2. Kaum Risiko: 0 oder 1 Mulde belegt (Fehlerrisiko max. 1/6 = 16,7%)
        if ($occupiedPits <= 1) {
            return true;
        }

        // 3. Mittleres Risiko: 2 Mulden belegt (Fehlerrisiko 33,3%)
        if ($occupiedPits === 2) {
            return $rollsInTurn <= 2;
        }

        // 4. Hohes Risiko: 3 Mulden belegt (Fehlerrisiko 50%)
        if ($occupiedPits === 3) {
            // Nur im Rückstand (viele Steine) noch einen Wurf wagen
            return ($stonesInHand >= 5 && $rollsInTurn <= 1);
        }

        // 5. Kritisches Risiko: 4 oder 5 Mulden belegt (Fehlerrisiko 66,7% bis 83,3%)
        return false;
    }
}
