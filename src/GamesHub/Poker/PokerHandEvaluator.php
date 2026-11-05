<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Poker;

/**
 * Bewertet Pokerhände nach offiziellen Texas Hold'em Regeln.
 * Wählt aus 7 Karten (2 Hole Cards + 5 Community Cards) die beste 5-Karten-Kombination aus.
 */
class PokerHandEvaluator
{
    public const CATEGORY_HIGH_CARD = 0;
    public const CATEGORY_PAIR = 1;
    public const CATEGORY_TWO_PAIR = 2;
    public const CATEGORY_THREE_OF_A_KIND = 3;
    public const CATEGORY_STRAIGHT = 4;
    public const CATEGORY_FLUSH = 5;
    public const CATEGORY_FULL_HOUSE = 6;
    public const CATEGORY_FOUR_OF_A_KIND = 7;
    public const CATEGORY_STRAIGHT_FLUSH = 8;
    public const CATEGORY_ROYAL_FLUSH = 9;

    public const CATEGORY_NAMES = [
        self::CATEGORY_HIGH_CARD => 'Höchste Karte',
        self::CATEGORY_PAIR => 'Ein Paar',
        self::CATEGORY_TWO_PAIR => 'Zwei Paare',
        self::CATEGORY_THREE_OF_A_KIND => 'Drilling',
        self::CATEGORY_STRAIGHT => 'Straße',
        self::CATEGORY_FLUSH => 'Flush',
        self::CATEGORY_FULL_HOUSE => 'Full House',
        self::CATEGORY_FOUR_OF_A_KIND => 'Vierling',
        self::CATEGORY_STRAIGHT_FLUSH => 'Straight Flush',
        self::CATEGORY_ROYAL_FLUSH => 'Royal Flush',
    ];

    private const RANK_LABELS = [
        2 => 'Zwei', 3 => 'Drei', 4 => 'Vier', 5 => 'Fünf', 6 => 'Sechs',
        7 => 'Sieben', 8 => 'Acht', 9 => 'Neun', 10 => 'Zehn',
        11 => 'Bube', 12 => 'Dame', 13 => 'König', 14 => 'Ass',
    ];

    private const RANK_PLURALS = [
        2 => 'Zweien', 3 => 'Dreien', 4 => 'Vieren', 5 => 'Fünfen', 6 => 'Sechsen',
        7 => 'Siebenen', 8 => 'Achten', 9 => 'Neunen', 10 => 'Zehnen',
        11 => 'Buben', 12 => 'Damen', 13 => 'Könige', 14 => 'Asse',
    ];

    /**
     * Ermittelt die stärkste 5-Karten-Hand aus bis zu 7 Karten.
     *
     * @param array<int, array{suit: string, rank: string, value: int, id?: string}> $cards
     * @return array{
     *     score: int,
     *     category: int,
     *     category_name: string,
     *     description: string,
     *     best_cards: array<int, array{suit: string, rank: string, value: int}>
     * }
     */
    public static function evaluate7Cards(array $cards): array
    {
        $n = count($cards);
        if ($n < 5) {
            return [
                'score' => 0,
                'category' => 0,
                'category_name' => 'Ungültig',
                'description' => 'Nicht genug Karten',
                'best_cards' => $cards,
            ];
        }

        // Alle 5er-Kombinationen bilden
        $combinations = self::combinations($cards, 5);
        $bestEvaluation = null;

        foreach ($combinations as $combo) {
            $eval = self::evaluate5Cards($combo);
            if ($bestEvaluation === null || $eval['score'] > $bestEvaluation['score']) {
                $bestEvaluation = $eval;
            }
        }

        return $bestEvaluation;
    }

    /**
     * Bewertet exakt 5 Karten.
     *
     * @param array<int, array{suit: string, rank: string, value: int, id?: string}> $hand
     * @return array{
     *     score: int,
     *     category: int,
     *     category_name: string,
     *     description: string,
     *     best_cards: array<int, array{suit: string, rank: string, value: int}>
     * }
     */
    public static function evaluate5Cards(array $hand): array
    {
        // Nach Kartenwert absteigend sortieren
        usort($hand, fn($a, $b) => $b['value'] <=> $a['value']);

        $values = array_map(fn($c) => (int)$c['value'], $hand);
        $suits = array_map(fn($c) => (string)$c['suit'], $hand);

        // Häufigkeiten der Werte zählen
        $counts = array_count_values($values);
        // Nach Häufigkeit absteigend, bei Gleichstand nach Kartenwert absteigend sortieren
        uksort($counts, function ($valA, $valB) use ($counts) {
            $cA = $counts[$valA];
            $cB = $counts[$valB];
            if ($cA !== $cB) return $cB <=> $cA;
            return $valB <=> $valA;
        });

        $isFlush = (count(array_unique($suits)) === 1);

        // Straße (Straight) prüfen
        $straightHigh = self::getStraightHighCard($values);
        $isStraight = ($straightHigh !== null);

        // 1. Royal Flush & Straight Flush
        if ($isFlush && $isStraight) {
            if ($straightHigh === 14) {
                return self::buildResult(self::CATEGORY_ROYAL_FLUSH, [$straightHigh], 'Royal Flush', $hand);
            }
            $label = "Straight Flush, " . self::RANK_LABELS[$straightHigh] . " hoch";
            return self::buildResult(self::CATEGORY_STRAIGHT_FLUSH, [$straightHigh], $label, $hand);
        }

        $frequencies = array_values($counts);
        $uniqueValues = array_keys($counts);

        // 2. Vierling (Four of a Kind)
        if ($frequencies[0] === 4) {
            $fourVal = $uniqueValues[0];
            $kicker = $uniqueValues[1];
            $label = "Vierling " . self::RANK_PLURALS[$fourVal] . " mit " . self::RANK_LABELS[$kicker] . "-Kicker";
            return self::buildResult(self::CATEGORY_FOUR_OF_A_KIND, [$fourVal, $kicker], $label, $hand);
        }

        // 3. Full House (3 + 2)
        if ($frequencies[0] === 3 && $frequencies[1] === 2) {
            $threeVal = $uniqueValues[0];
            $pairVal = $uniqueValues[1];
            $label = "Full House, " . self::RANK_PLURALS[$threeVal] . " über " . self::RANK_PLURALS[$pairVal];
            return self::buildResult(self::CATEGORY_FULL_HOUSE, [$threeVal, $pairVal], $label, $hand);
        }

        // 4. Flush
        if ($isFlush) {
            $highVal = $values[0];
            $label = "Flush, " . self::RANK_LABELS[$highVal] . " hoch";
            return self::buildResult(self::CATEGORY_FLUSH, $values, $label, $hand);
        }

        // 5. Straße (Straight)
        if ($isStraight) {
            $label = "Straße, " . self::RANK_LABELS[$straightHigh] . " hoch";
            return self::buildResult(self::CATEGORY_STRAIGHT, [$straightHigh], $label, $hand);
        }

        // 6. Drilling (Three of a Kind)
        if ($frequencies[0] === 3) {
            $threeVal = $uniqueValues[0];
            $kickers = [$uniqueValues[1], $uniqueValues[2]];
            $label = "Drilling " . self::RANK_PLURALS[$threeVal] . " mit " . self::RANK_LABELS[$kickers[0]] . "-Kicker";
            return self::buildResult(self::CATEGORY_THREE_OF_A_KIND, array_merge([$threeVal], $kickers), $label, $hand);
        }

        // 7. Zwei Paare (Two Pair)
        if ($frequencies[0] === 2 && $frequencies[1] === 2) {
            $highPair = $uniqueValues[0];
            $lowPair = $uniqueValues[1];
            $kicker = $uniqueValues[2];
            $label = "Zwei Paare, " . self::RANK_PLURALS[$highPair] . " und " . self::RANK_PLURALS[$lowPair] . " mit " . self::RANK_LABELS[$kicker] . "-Kicker";
            return self::buildResult(self::CATEGORY_TWO_PAIR, [$highPair, $lowPair, $kicker], $label, $hand);
        }

        // 8. Ein Paar (One Pair)
        if ($frequencies[0] === 2) {
            $pairVal = $uniqueValues[0];
            $kickers = array_slice($uniqueValues, 1);
            $label = "Ein Paar " . self::RANK_PLURALS[$pairVal] . " mit " . self::RANK_LABELS[$kickers[0]] . "-Kicker";
            return self::buildResult(self::CATEGORY_PAIR, array_merge([$pairVal], $kickers), $label, $hand);
        }

        // 9. Höchste Karte (High Card)
        $highVal = $values[0];
        $second = $values[1];
        $label = "Höchste Karte " . self::RANK_LABELS[$highVal] . " mit " . self::RANK_LABELS[$second] . "-Kicker";
        return self::buildResult(self::CATEGORY_HIGH_CARD, $values, $label, $hand);
    }

    /**
     * Prüft, ob 5 absteigend sortierte Werte eine Straße bilden.
     * Liefert den höchsten Wert der Straße oder null.
     * Unterstützt das "Wheel" (A-2-3-4-5 -> High Card ist 5).
     *
     * @param array<int> $values
     */
    private static function getStraightHighCard(array $values): ?int
    {
        // Standard-Straße (z. B. 10-9-8-7-6)
        if (
            $values[0] - $values[1] === 1 &&
            $values[1] - $values[2] === 1 &&
            $values[2] - $values[3] === 1 &&
            $values[3] - $values[4] === 1
        ) {
            return $values[0];
        }

        // Sonderfall: Baby Straight / Wheel: A-5-4-3-2
        if ($values === [14, 5, 4, 3, 2]) {
            return 5;
        }

        return null;
    }

    /**
     * Berechnet den 32-Bit-Vergleichsscore für die Hand.
     *
     * @param int $category 0..9
     * @param array<int> $ranks Relevante Ränge und Kicker
     * @param string $description Lesbare Beschreibung
     * @param array $cards Die 5 Karten
     * @return array{score: int, category: int, category_name: string, description: string, best_cards: array}
     */
    private static function buildResult(int $category, array $ranks, string $description, array $cards): array
    {
        // 5 Kicker-Slots auffüllen
        $padded = array_pad($ranks, 5, 0);

        // Bitweiser Score: 4 Bits pro Slot (Werte 0..14)
        $score = ($category << 20)
            | (($padded[0] & 0xF) << 16)
            | (($padded[1] & 0xF) << 12)
            | (($padded[2] & 0xF) << 8)
            | (($padded[3] & 0xF) << 4)
            | ($padded[4] & 0xF);

        return [
            'score' => $score,
            'category' => $category,
            'category_name' => self::CATEGORY_NAMES[$category],
            'description' => $description,
            'best_cards' => $cards,
        ];
    }

    /**
     * Generiert alle k-Element-Kombinationen einer Menge.
     *
     * @param array $items
     * @param int $k
     * @return array<int, array>
     */
    private static function combinations(array $items, int $k): array
    {
        if ($k === 0) return [[]];
        if (empty($items)) return [];

        $head = array_shift($items);
        $withHead = [];
        foreach (self::combinations($items, $k - 1) as $combo) {
            $withHead[] = array_merge([$head], $combo);
        }
        $withoutHead = self::combinations($items, $k);

        return array_merge($withHead, $withoutHead);
    }
}
