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

        // Tier 3: Spekulative Hände (Kleine Paare, Suited Connectors wie 98s, A-x suited)
        if ($isPair || ($isSuited && ($high === 14 || $high - $low <= 2))) {
            if ($canCheck) {
                return ['action' => 'check', 'amount' => 0];
            }
            // Nur kleine Bets mitgehen (bis 15% des Stacks)
            if ($toCall <= $myChips * 0.15) {
                return ['action' => 'call', 'amount' => $toCall];
            }
            return ['action' => 'fold', 'amount' => 0];
        }

        // Tier 4: Trash-Hände
        if ($canCheck) {
            return ['action' => 'check', 'amount' => 0];
        }

        // Sehr billiges Callen (z. B. 10 oder 20 Chips) zu 25% erlauben
        if ($toCall <= 20 && mt_rand(1, 100) <= 25) {
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
                // Gelegentlich kleine Value-Bet
                if (mt_rand(1, 100) <= 30 && $myChips >= $minRaise) {
                    return ['action' => 'bet', 'amount' => min($myChips, $minRaise)];
                }
                return ['action' => 'check', 'amount' => 0];
            }
            // Bis zu 25% Pot mitgehen
            if ($toCall <= $pot * 0.35 && $toCall <= $myChips * 0.25) {
                return ['action' => 'call', 'amount' => min($toCall, $myChips)];
            }
            return ['action' => 'fold', 'amount' => 0];
        }

        // 4. Nur High Card (Category 0)
        if ($canCheck) {
            // Gelegentlicher Bluff (12%)
            if (mt_rand(1, 100) <= 12 && $myChips >= $minRaise) {
                return ['action' => 'bet', 'amount' => min($myChips, $minRaise)];
            }
            return ['action' => 'check', 'amount' => 0];
        }

        // Sehr kleine Einsätze manchmal callen
        if ($toCall <= 20 && mt_rand(1, 100) <= 15) {
            return ['action' => 'call', 'amount' => $toCall];
        }

        return ['action' => 'fold', 'amount' => 0];
    }
}
