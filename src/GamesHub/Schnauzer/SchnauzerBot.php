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
    public static function decideDealerChoice(array $handA, array $handB, bool $threeAces33 = false): string
    {
        $scoreA = SchnauzerScoreCalculator::calculateScore($handA, $threeAces33)['score'];
        $scoreB = SchnauzerScoreCalculator::calculateScore($handB, $threeAces33)['score'];

        return ($scoreB > $scoreA) ? 'swap' : 'keep';
    }

    /**
     * Trifft die taktische Entscheidung für einen regulären Spielzug.
     *
     * @param array<int, array<string, mixed>> $hand
     * @param array<int, array<string, mixed>> $table
     * @param bool $isKnocked Ist die Schlussrunde bereits eingeläutet?
     * @param bool $canKnock Darf in diesem Zug geklopft werden (Umlaufschutz)?
     * @param int $roundTurnCount Anzahl der bisher absolvierten Züge in dieser Runde
     * @param bool $threeAces33 Hausregel: 3 Asse = 33 Punkte aktiv
     * @return array{action: 'swap_one'|'swap_all'|'knock'|'pass', hand_index?: int, table_index?: int}
     */
    public static function decideTurn(
        array $hand,
        array $table,
        bool $isKnocked,
        bool $canKnock = true,
        int $roundTurnCount = 0,
        bool $threeAces33 = false
    ): array {
        $curr = SchnauzerScoreCalculator::calculateScore($hand, $threeAces33);
        $currScore = $curr['score'];

        // 1. Wenn der Bot bereits 31 oder 33 Punkte auf der Hand hat
        if ($currScore >= 31.0) {
            return ['action' => 'pass'];
        }

        // 2. Alle 9 Einzelkarten-Tausche simulieren
        $bestSingleScore = -1.0;
        $bestH = 0;
        $bestT = 0;

        for ($h = 0; $h < count($hand); $h++) {
            for ($t = 0; $t < count($table); $t++) {
                $simulatedHand = $hand;
                $simulatedHand[$h] = $table[$t];
                $simScore = SchnauzerScoreCalculator::calculateScore($simulatedHand, $threeAces33)['score'];
                if ($simScore > $bestSingleScore) {
                    $bestSingleScore = $simScore;
                    $bestH = $h;
                    $bestT = $t;
                }
            }
        }

        // 3. Tausch aller 3 Karten simulieren
        $swapAllScore = SchnauzerScoreCalculator::calculateScore($table, $threeAces33)['score'];

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

        // 6. Kein Tausch verbessert die Hand -> Klopfen oder Schieben
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

        // 7. Schieben
        return ['action' => 'pass'];
    }
}
