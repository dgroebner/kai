<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Schnauzer;

class SchnauzerBot
{
    /**
     * Trifft die Geber-Entscheidung (erstes 3er-Päckchen behalten oder mit dem zweiten tauschen).
     *
     * @param array<int, array<string, mixed>> $handA
     * @param array<int, array<string, mixed>> $handB
     */
    public static function decideDealerChoice(array $handA, array $handB, bool $gelenauerRegeln = false): string
    {
        $scoreA = SchnauzerScoreCalculator::calculateScore($handA, $gelenauerRegeln)['score'];
        $scoreB = SchnauzerScoreCalculator::calculateScore($handB, $gelenauerRegeln)['score'];

        return ($scoreB > $scoreA) ? 'swap' : 'keep';
    }

    /**
     * Trifft die taktische Entscheidung für einen regulären Spielzug.
     *
     * @param array<int, array<string, mixed>> $hand
     * @param array<int, array<string, mixed>> $table
     * @param bool $isKnocked Ist die Schlussrunde bereits eingeläutet?
     * @param bool $canKnock Darf in diesem Zug geklopft / zugemacht werden?
     * @param int $roundTurnCount Anzahl der bisher absolvierten Züge in dieser Runde
     * @param bool $gelenauerRegeln Gelenauer Regeln aktiv (3 Asse = Schnauzer, kein Schieben)
     * @return array{action: 'swap_one'|'swap_all'|'knock'|'pass', hand_index?: int, table_index?: int}
     */
    public static function decideTurn(
        array $hand,
        array $table,
        bool $isKnocked,
        bool $canKnock = true,
        int $roundTurnCount = 0,
        bool $gelenauerRegeln = false
    ): array {
        $curr = SchnauzerScoreCalculator::calculateScore($hand, $gelenauerRegeln);
        $currScore = $curr['score'];

        // 1. Wenn der Bot bereits 31 oder 33 Punkte auf der Hand hat
        if ($currScore >= 31.0) {
            return ['action' => $gelenauerRegeln ? 'knock' : 'pass'];
        }

        // 2. Alle 9 Einzelkarten-Tausche simulieren
        $bestSingleScore = -1.0;
        $bestH = 0;
        $bestT = 0;

        for ($h = 0; $h < count($hand); $h++) {
            for ($t = 0; $t < count($table); $t++) {
                $simulatedHand = $hand;
                $simulatedHand[$h] = $table[$t];
                $simScore = SchnauzerScoreCalculator::calculateScore($simulatedHand, $gelenauerRegeln)['score'];
                if ($simScore > $bestSingleScore) {
                    $bestSingleScore = $simScore;
                    $bestH = $h;
                    $bestT = $t;
                }
            }
        }

        // 3. Tausch aller 3 Karten simulieren
        $swapAllScore = SchnauzerScoreCalculator::calculateScore($table, $gelenauerRegeln)['score'];

        // 4. Prüfen, ob Tausch aller 3 Karten besser ist als Einzeltausch und aktuelle Hand
        if ($swapAllScore > $currScore && $swapAllScore > $bestSingleScore) {
            return ['action' => 'swap_all'];
        }

        // 5. Prüfen, ob Einzeltausch die Hand verbessert
        if ($bestSingleScore > $currScore) {
            return [
                'action' => 'swap_one',
                'hand_index' => $bestH,
                'table_index' => $bestT,
            ];
        }

        // 6. Kein Tausch verbessert die Hand:
        if ($gelenauerRegeln) {
            // Nach Gelenauer Regeln gibt es kein Schieben!
            // Wenn bereits zugemacht wurde (Schlussrunde), behält der Bot seine Hand ("ebenfalls zumachen").
            if ($isKnocked) {
                return ['action' => 'knock'];
            }

            // Noch niemand hat zugemacht: Bei gutem Blatt (>= 26 oder Drilling) oder nach ein paar Zügen (>= 24) zumachen
            if (!empty($curr['is_drilling']) || $currScore >= 26.0 || ($currScore >= 24.0 && $roundTurnCount >= 2)) {
                return ['action' => 'knock'];
            }

            // Bei schwachem Blatt lieber tauschen als mit wenig Punkten zuzumachen
            if ($swapAllScore > $bestSingleScore) {
                return ['action' => 'swap_all'];
            }
            return [
                'action' => 'swap_one',
                'hand_index' => $bestH,
                'table_index' => $bestT,
            ];
        }

        // Standard-Regeln: Klopfen oder Schieben
        if (!$isKnocked && $canKnock) {
            // Drilling (30.5) oder sehr hohe Hand (>= 29): Klopfen!
            if (!empty($curr['is_drilling']) || $currScore >= 29.0) {
                return ['action' => 'knock'];
            }
            // Bei 28 Punkten: Erst nach etwas Spielverlauf klopfen
            if ($currScore >= 28.0 && $roundTurnCount >= 3) {
                return ['action' => 'knock'];
            }
        }

        // 7. Schieben (nur Standard-Regeln)
        return ['action' => 'pass'];
    }
}
