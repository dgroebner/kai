<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub;

class KniffelService
{
    public const CATEGORIES_UPPER = [
        'ones' => 'Einer',
        'twos' => 'Zweier',
        'threes' => 'Dreier',
        'fours' => 'Vierer',
        'fives' => 'Fünfer',
        'sixes' => 'Sechser',
    ];

    public const CATEGORIES_LOWER = [
        'three_of_a_kind' => 'Dreierpasch',
        'four_of_a_kind' => 'Viererpasch',
        'full_house' => 'Full House',
        'small_straight' => 'Kleine Straße',
        'large_straight' => 'Große Straße',
        'kniffel' => 'Kniffel',
        'chance' => 'Chance',
    ];

    public const ALL_CATEGORIES = [
        'ones', 'twos', 'threes', 'fours', 'fives', 'sixes',
        'three_of_a_kind', 'four_of_a_kind', 'full_house',
        'small_straight', 'large_straight', 'kniffel', 'chance'
    ];

    /**
     * Erzeugt einen leeren Standard-Spielzettel für Kniffel.
     */
    public function getInitialSheet(): array
    {
        return [
            'scores' => [
                'ones' => null,
                'twos' => null,
                'threes' => null,
                'fours' => null,
                'fives' => null,
                'sixes' => null,
                'three_of_a_kind' => null,
                'four_of_a_kind' => null,
                'full_house' => null,
                'small_straight' => null,
                'large_straight' => null,
                'kniffel' => null,
                'chance' => null,
            ],
            'rounds_completed' => 0,
        ];
    }

    /**
     * Erzeugt den initialen Zustand für ein neues Kniffel-Spiel.
     */
    public function getInitialState(string $gameMode = 'digital_dice'): array
    {
        return [
            'active_player_id' => null,
            'active_player_index' => 0,
            'round_number' => 1,
            'roll_count' => 0, // 0 = noch nicht gewürfelt, 1, 2, 3 = max
            'dice' => [1, 1, 1, 1, 1],
            'held' => [false, false, false, false, false],
            'last_roll_time' => null,
        ];
    }

    /**
     * Führt einen Wurf für alle nicht gehaltenen Würfel aus.
     *
     * @param int[] $currentDice
     * @param bool[] $held
     * @return int[]
     */
    public function rollDice(array $currentDice, array $held): array
    {
        $newDice = [];
        for ($i = 0; $i < 5; $i++) {
            if (!empty($held[$i]) && isset($currentDice[$i]) && $currentDice[$i] >= 1 && $currentDice[$i] <= 6) {
                $newDice[$i] = (int)$currentDice[$i];
            } else {
                $newDice[$i] = random_int(1, 6);
            }
        }
        return $newDice;
    }

    /**
     * Berechnet die Punkte-Vorschau für alle 13 Kategorien basierend auf den aktuellen 5 Würfeln.
     *
     * @param int[] $dice
     * @return array<string, int>
     */
    public function calculatePreviews(array $dice): array
    {
        $dice = array_map('intval', array_values($dice));
        if (count($dice) !== 5) {
            $dice = [1, 1, 1, 1, 1];
        }

        $sumAll = array_sum($dice);
        $counts = array_count_values($dice);
        $maxFreq = !empty($counts) ? max($counts) : 0;
        $uniqueValues = array_keys($counts);
        sort($uniqueValues);

        $previews = [];

        // Oberer Teil: Summe der jeweiligen Augenzahlen
        $previews['ones'] = ($counts[1] ?? 0) * 1;
        $previews['twos'] = ($counts[2] ?? 0) * 2;
        $previews['threes'] = ($counts[3] ?? 0) * 3;
        $previews['fours'] = ($counts[4] ?? 0) * 4;
        $previews['fives'] = ($counts[5] ?? 0) * 5;
        $previews['sixes'] = ($counts[6] ?? 0) * 6;

        // Dreierpasch: mindestens 3 gleiche -> Summe aller 5 Würfel
        $previews['three_of_a_kind'] = ($maxFreq >= 3) ? $sumAll : 0;

        // Viererpasch: mindestens 4 gleiche -> Summe aller 5 Würfel
        $previews['four_of_a_kind'] = ($maxFreq >= 4) ? $sumAll : 0;

        // Full House: 3 gleiche + 2 gleiche (oder 5 gleiche) -> 25 Punkte
        $isFullHouse = false;
        if ($maxFreq === 5) {
            $isFullHouse = true;
        } elseif ($maxFreq === 3 && in_array(2, $counts, true)) {
            $isFullHouse = true;
        }
        $previews['full_house'] = $isFullHouse ? 25 : 0;

        // Kleine Straße: 4 aufeinanderfolgende Zahlen (1-2-3-4, 2-3-4-5, 3-4-5-6) -> 30 Punkte
        $uniqueStr = implode('', $uniqueValues);
        $isSmallStraight = (
            str_contains($uniqueStr, '1234') ||
            str_contains($uniqueStr, '2345') ||
            str_contains($uniqueStr, '3456')
        );
        $previews['small_straight'] = $isSmallStraight ? 30 : 0;

        // Große Straße: 5 aufeinanderfolgende Zahlen (1-2-3-4-5, 2-3-4-5-6) -> 40 Punkte
        $isLargeStraight = (
            $uniqueStr === '12345' ||
            $uniqueStr === '23456'
        );
        $previews['large_straight'] = $isLargeStraight ? 40 : 0;

        // Kniffel: 5 gleiche Zahlen -> 50 Punkte
        $previews['kniffel'] = ($maxFreq === 5) ? 50 : 0;

        // Chance: Summe aller 5 Würfel
        $previews['chance'] = $sumAll;

        return $previews;
    }

    /**
     * Berechnet die Gesamtpunktzahl, Zwischensummen und den 35er-Bonus.
     */
    public function calculateTotalScore(array $sheet): array
    {
        $scores = $sheet['scores'] ?? [];

        // Oberer Teil
        $upperSum = 0;
        $upperFilled = 0;
        foreach (self::CATEGORIES_UPPER as $cat => $name) {
            if (isset($scores[$cat]) && $scores[$cat] !== null) {
                $upperSum += (int)$scores[$cat];
                $upperFilled++;
            }
        }

        $upperBonus = ($upperSum >= 63) ? 35 : 0;
        $upperTotal = $upperSum + $upperBonus;
        $bonusNeeded = max(0, 63 - $upperSum);

        // Unterer Teil
        $lowerSum = 0;
        $lowerFilled = 0;
        foreach (self::CATEGORIES_LOWER as $cat => $name) {
            if (isset($scores[$cat]) && $scores[$cat] !== null) {
                $lowerSum += (int)$scores[$cat];
                $lowerFilled++;
            }
        }

        $grandTotal = $upperTotal + $lowerSum;
        $filledCount = $upperFilled + $lowerFilled;

        return [
            'upper_sum' => $upperSum,
            'upper_bonus' => $upperBonus,
            'upper_total' => $upperTotal,
            'bonus_needed' => $bonusNeeded,
            'bonus_reached' => ($upperSum >= 63),
            'lower_sum' => $lowerSum,
            'total' => $grandTotal,
            'filled_count' => $filledCount,
            'is_complete' => ($filledCount >= 13),
        ];
    }

    /**
     * Trägt die Punkte für eine Kategorie ein.
     */
    public function applyScore(array &$sheet, string $category, array $dice, string $gameMode = 'digital_dice', ?int $manualScore = null): bool
    {
        if (!in_array($category, self::ALL_CATEGORIES, true)) {
            return false;
        }

        // Feld darf noch nicht belegt sein
        if (isset($sheet['scores'][$category]) && $sheet['scores'][$category] !== null) {
            return false;
        }

        if ($gameMode === 'digital_dice') {
            $previews = $this->calculatePreviews($dice);
            $points = $previews[$category] ?? 0;
        } else {
            $points = max(0, (int)$manualScore);
        }

        $sheet['scores'][$category] = $points;
        $sheet['rounds_completed'] = count(array_filter($sheet['scores'], fn($val) => $val !== null));

        return true;
    }

    /**
     * Prüft, ob das Spiel beendet ist (alle Mitspieler haben 13 Runden ausgefüllt).
     */
    public function checkGameEnd(array $players): bool
    {
        if (empty($players)) return false;

        foreach ($players as $p) {
            $sheet = $p['sheet'] ?? [];
            $calc = $this->calculateTotalScore($sheet);
            if (!$calc['is_complete']) {
                return false;
            }
        }
        return true;
    }
}
