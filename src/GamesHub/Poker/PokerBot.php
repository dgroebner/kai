<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Poker;

/**
 * Heuristische KI-Entscheidungslogik für Poker-Gegner.
 */
class PokerBot
{
    /**
     * Trifft eine Entscheidung für den aktuellen Bot-Zug.
     *
     * @param array<int, array{suit: string, rank: string, value: int}> $holeCards 2 Handkarten
     * @param array<int, array{suit: string, rank: string, value: int}> $communityCards 0 bis 5 Board-Karten
     * @param int $toCall Wie viele Chips muss der Bot nachlegen, um mitzugehen?
     * @param int $pot Aktuelle Gesamt-Pothöhe
     * @param int $myChips Verbleibende Chips des Bots
     * @param int $minRaise Minimaler Erhöhungsschritt
     * @param string $phase 'preflop' | 'flop' | 'turn' | 'river'
     * @return array{action: 'fold'|'check'|'call'|'bet'|'raise'|'all_in', amount: int}
     */
    public static function decideAction(
        array $holeCards,
        array $communityCards,
        int $toCall,
        int $pot,
        int $myChips,
        int $minRaise,
        string $phase
    ): array {
        // Kann kostenlos gecheckt werden?
        $canCheck = ($toCall === 0);

        if ($phase === 'preflop') {
            return self::decidePreflop($holeCards, $toCall, $pot, $myChips, $minRaise, $canCheck);
        }

        return self::decidePostflop($holeCards, $communityCards, $toCall, $pot, $myChips, $minRaise, $canCheck);
    }

    /**
     * Pre-Flop Entscheidungen nach Hand-Stufen.
     */
    private static function decidePreflop(
        array $holeCards,
        int $toCall,
        int $pot,
        int $myChips,
        int $minRaise,
        bool $canCheck
    ): array {
        if (count($holeCards) < 2) {
            return ['action' => $canCheck ? 'check' : 'fold', 'amount' => 0];
        }

        $v1 = (int)$holeCards[0]['value'];
        $v2 = (int)$holeCards[1]['value'];
        $high = max($v1, $v2);
        $low = min($v1, $v2);
        $isPair = ($v1 === $v2);
        $isSuited = ($holeCards[0]['suit'] === $holeCards[1]['suit']);

        // Tier 1: Monster (AA, KK, QQ, JJ, AK)
        if (($isPair && $high >= 11) || ($high === 14 && $low === 13)) {
            if ($myChips <= $minRaise * 2) {
                return ['action' => 'all_in', 'amount' => $myChips];
            }
            $raiseAmount = min($myChips, max($minRaise, (int)($pot * 0.75)));
            return ['action' => 'raise', 'amount' => $raiseAmount];
        }

        // Tier 2: Starke Hände (10-10..7-7, AQ, AJ, KQ, KJ suited)
        if (($isPair && $high >= 7) || ($high === 14 && $low >= 11) || ($high === 13 && $low >= 11)) {
            if ($toCall > $myChips * 0.4) {
                // Bei riesigen All-In Bets abwägen
                return ['action' => 'call', 'amount' => min($toCall, $myChips)];
            }
            // Gelegentlich raisen (40%)
            if (mt_rand(1, 100) <= 40 && $myChips > $minRaise) {
                $raiseAmount = min($myChips, $minRaise);
                return ['action' => 'raise', 'amount' => $raiseAmount];
            }
            return ['action' => $canCheck ? 'check' : 'call', 'amount' => min($toCall, $myChips)];
        }

        // Tier 3: Spekulative & spielbare Hände (Kleine Paare, Suited Connectors, Ax suited, High Cards >= 10)
        if ($isPair || $isSuited || $high >= 10 || ($high - $low <= 3)) {
            if ($canCheck) {
                return ['action' => 'check', 'amount' => 0];
            }
            // Bis zu 40 Chips oder 20% des Stacks mitgehen
            if ($toCall <= max(40, (int)($myChips * 0.2))) {
                return ['action' => 'call', 'amount' => min($toCall, $myChips)];
            }
            return ['action' => 'fold', 'amount' => 0];
        }

        // Tier 4: Schwächere Hände
        if ($canCheck) {
            return ['action' => 'check', 'amount' => 0];
        }

        // Im Small Blind (nur 10 Chips zu callen) oder bei Mini-Bet: Zu 80% mitgehen
        if ($toCall <= 10 && mt_rand(1, 100) <= 80) {
            return ['action' => 'call', 'amount' => $toCall];
        }
        if ($toCall <= 20 && mt_rand(1, 100) <= 50) {
            return ['action' => 'call', 'amount' => $toCall];
        }

        return ['action' => 'fold', 'amount' => 0];
    }

    /**
     * Post-Flop Entscheidungen nach evaluierter Handstärke.
     */
    private static function decidePostflop(
        array $holeCards,
        array $communityCards,
        int $toCall,
        int $pot,
        int $myChips,
        int $minRaise,
        bool $canCheck
    ): array {
        $allCards = array_merge($holeCards, $communityCards);
        $eval = PokerHandEvaluator::evaluate7Cards($allCards);
        $category = $eval['category'];

        // 1. Monster-Hände: Straight, Flush, Full House, Vierling, Straight Flush (Category >= 4)
        if ($category >= PokerHandEvaluator::CATEGORY_STRAIGHT) {
            if ($toCall >= $myChips || $myChips <= $minRaise) {
                return ['action' => 'all_in', 'amount' => $myChips];
            }
            // Raise oder Bet (ca. 60-80% Pot)
            $betSize = min($myChips, max($minRaise, (int)($pot * 0.65)));
            return ['action' => $canCheck ? 'bet' : 'raise', 'amount' => $betSize];
        }

        // 2. Sehr gute Hände: Drilling & Zwei Paare (Category 2 oder 3)
        if ($category >= PokerHandEvaluator::CATEGORY_TWO_PAIR) {
            if ($toCall > $myChips * 0.6) {
                return ['action' => 'call', 'amount' => min($toCall, $myChips)];
            }
            if ($canCheck && mt_rand(1, 100) <= 65) {
                $betSize = min($myChips, max($minRaise, (int)($pot * 0.5)));
                return ['action' => 'bet', 'amount' => $betSize];
            }
            return ['action' => $canCheck ? 'check' : 'call', 'amount' => min($toCall, $myChips)];
        }

        // 3. Mittlere Hände: Ein Paar (Category 1)
        if ($category === PokerHandEvaluator::CATEGORY_PAIR) {
            if ($canCheck) {
                // Gelegentlich kleine Value-Bet (40%)
                if (mt_rand(1, 100) <= 40 && $myChips >= $minRaise) {
                    $betSize = min($myChips, max($minRaise, (int)($pot * 0.4)));
                    return ['action' => 'bet', 'amount' => $betSize];
                }
                return ['action' => 'check', 'amount' => 0];
            }
            // Mit einem Paar moderate Einsätze mitgehen (bis zu 75% Pot oder bei kleiner Bet bis 40 Chips)
            if ($toCall <= max(40, (int)($pot * 0.75)) && $toCall <= $myChips * 0.4) {
                return ['action' => 'call', 'amount' => min($toCall, $myChips)];
            }
            return ['action' => 'fold', 'amount' => 0];
        }

        // 4. Nur High Card (Category 0)
        if ($canCheck) {
            // Gelegentlicher Bluff (20%)
            if (mt_rand(1, 100) <= 20 && $myChips >= $minRaise) {
                $bluffSize = min($myChips, max($minRaise, (int)($pot * 0.4)));
                return ['action' => 'bet', 'amount' => $bluffSize];
            }
            return ['action' => 'check', 'amount' => 0];
        }

        // Kleine Einsätze (z. B. bis 30 Chips oder < 30% Pot) zu 45% callen (Float / Chasing Overcards)
        if (($toCall <= 30 || $toCall <= $pot * 0.3) && $toCall <= $myChips * 0.15 && mt_rand(1, 100) <= 45) {
            return ['action' => 'call', 'amount' => min($toCall, $myChips)];
        }

        return ['action' => 'fold', 'amount' => 0];
    }
}
