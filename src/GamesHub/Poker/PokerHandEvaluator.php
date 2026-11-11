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

    private const SUIT_LABELS = [
        'hearts' => 'Herz ♥',
        'diamonds' => 'Karo ♦',
        'spades' => 'Pik ♠',
        'clubs' => 'Kreuz ♣',
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
            'label' => $description,
            'best_cards' => $cards,
        ];
    }

    /**
     * Erstellt eine umfassende Live-Spielhilfe für die aktuellen Hand- und Tischkarten.
     *
     * @param array<int, array{suit: string, rank: string, value: int}> $holeCards
     * @param array<int, array{suit: string, rank: string, value: int}> $communityCards
     * @return array{
     *     title: string,
     *     category_name: string,
     *     stars: int,
     *     strength_text: string,
     *     strength_color: string,
     *     possibilities: array<int, string>,
     *     advice: string
     * }|null
     */
    public static function analyzeHandForAssistant(array $holeCards, array $communityCards): ?array
    {
        if (count($holeCards) < 2) {
            return null;
        }

        // 1. PRE-FLOP ANALYSE (nur 2 Handkarten)
        if (empty($communityCards)) {
            $v1 = (int)$holeCards[0]['value'];
            $v2 = (int)$holeCards[1]['value'];
            $high = max($v1, $v2);
            $low = min($v1, $v2);
            $isPair = ($v1 === $v2);
            $isSuited = ($holeCards[0]['suit'] === $holeCards[1]['suit']);
            $gap = $high - $low;
            $suitLabel = self::SUIT_LABELS[$holeCards[0]['suit']] ?? $holeCards[0]['suit'];

            if ($isPair) {
                $plural = self::RANK_PLURALS[$high] ?? (string)$high;
                $single = self::RANK_LABELS[$high] ?? (string)$high;
                $title = "Pocket Paar: Zwei {$plural}";
                $possibilities = [
                    "🎯 Drilling-Chance: Noch eine {$single} auf dem Tisch macht einen starken Drilling (Set)!",
                    "🛡️ Du startest bereits mit einem fertigen Paar auf der Hand.",
                ];

                if ($high >= 11) {
                    $stars = 5;
                    $strengthText = 'Monster-Starthand';
                    $color = '#22c55e';
                    $advice = 'Top-Blatt! Erhöhen (Raise) oder kräftig mitgehen empfohlen.';
                } elseif ($high >= 7) {
                    $stars = 4;
                    $strengthText = 'Starke Starthand';
                    $color = '#22c55e';
                    $advice = 'Starkes Paar – Sicher mitgehen (Call) oder moderat erhöhen.';
                } else {
                    $stars = 3;
                    $strengthText = 'Kleines Paar';
                    $color = '#38bdf8';
                    $advice = 'Günstig den Flop anschauen und auf einen Drilling hoffen.';
                }

                return [
                    'title' => $title,
                    'category_name' => 'Pocket Paar',
                    'stars' => $stars,
                    'strength_text' => $strengthText,
                    'strength_color' => $color,
                    'possibilities' => $possibilities,
                    'advice' => $advice,
                ];
            }

            $highName = self::RANK_LABELS[$high] ?? (string)$high;
            $lowName = self::RANK_LABELS[$low] ?? (string)$low;
            $suitedSuffix = $isSuited ? " ({$suitLabel})" : '';
            $title = "Starthand: {$highName} & {$lowName}{$suitedSuffix}";

            $possibilities = [
                "🎯 Paar-Chance: Jede {$highName} oder {$lowName} auf dem Flop bringt dir ein Paar.",
            ];
            if ($isSuited) {
                $possibilities[] = "🎨 Gleichfarbig ({$suitLabel}): Erhöhte Chance auf einen Flush (3 weitere nötig).";
            }
            if ($gap <= 4 || ($high === 14 && $low <= 5)) {
                $possibilities[] = "📏 Straßen-Potenzial: Karten liegen nah beieinander (Chance auf Straße).";
            }

            if ($high === 14 && $low >= 12) {
                $stars = 5;
                $strengthText = 'Sehr stark';
                $color = '#22c55e';
                $advice = 'Sehr starke hohe Karten – Erhöhen oder sicher mitgehen.';
            } elseif (($high >= 13 && $low >= 10) || ($high === 14 && $isSuited)) {
                $stars = 4;
                $strengthText = 'Stark';
                $color = '#22c55e';
                $advice = 'Gute Starthand – Flop anschauen lohnt sich.';
            } elseif ($isSuited || $gap <= 2 || $high >= 12) {
                $stars = 3;
                $strengthText = 'Spielbar';
                $color = '#38bdf8';
                $advice = 'Solide Hand – Bei kleinem Einsatz mitgehen (Check / Call).';
            } else {
                $stars = 2;
                $strengthText = 'Schwach';
                $color = '#f59e0b';
                $advice = 'Eher schwache Starthand – Kostenlos schieben (Check) oder bei Erhöhung passen.';
            }

            return [
                'title' => $title,
                'category_name' => 'Starthand',
                'stars' => $stars,
                'strength_text' => $strengthText,
                'strength_color' => $color,
                'possibilities' => $possibilities,
                'advice' => $advice,
            ];
        }

        // 2. POST-FLOP ANALYSE (Flop, Turn, River: 5 bis 7 Karten)
        $allCards = array_merge($holeCards, $communityCards);
        $eval = self::evaluate7Cards($allCards);
        $cat = $eval['category'];
        $title = $eval['description'];
        $cardsRemaining = max(0, 5 - count($communityCards));
        $possibilities = [];
        $hasStrongDraw = false;

        if ($cardsRemaining > 0) {
            // A) Flush-Draw prüfen
            if ($cat < self::CATEGORY_FLUSH) {
                $suitCounts = [];
                foreach ($allCards as $c) {
                    $s = $c['suit'];
                    $suitCounts[$s] = ($suitCounts[$s] ?? 0) + 1;
                }
                foreach ($suitCounts as $s => $cnt) {
                    $sLabel = self::SUIT_LABELS[$s] ?? $s;
                    if ($cnt === 4) {
                        $possibilities[] = "🎨 Flush-Draw: 4x {$sLabel} vorhanden – nur noch 1 {$sLabel}-Karte zum Flush!";
                        $hasStrongDraw = true;
                    } elseif ($cnt === 3 && $cardsRemaining === 2) {
                        $possibilities[] = "🎨 Backdoor-Flush: 3x {$sLabel} – noch 2 {$sLabel} auf Turn & River nötig.";
                    }
                }
            }

            // B) Straßen-Draw prüfen (exakte Simulation fehlender Kartenwerte 2..14)
            if ($cat < self::CATEGORY_STRAIGHT) {
                $existingVals = array_values(array_unique(array_map(fn($c) => (int)$c['value'], $allCards)));
                rsort($existingVals);
                if (!self::hasStraightInValues($existingVals)) {
                    $straightOutRanks = [];
                    for ($v = 2; $v <= 14; $v++) {
                        if (in_array($v, $existingVals, true)) {
                            continue;
                        }
                        $testVals = $existingVals;
                        $testVals[] = $v;
                        rsort($testVals);
                        if (self::hasStraightInValues($testVals)) {
                            $straightOutRanks[] = self::RANK_LABELS[$v] ?? (string)$v;
                        }
                    }
                    if (!empty($straightOutRanks)) {
                        $outList = implode(' oder ', $straightOutRanks);
                        $possibilities[] = "📏 Straßen-Draw: Eine {$outList} komplettiert deine Straße!";
                        $hasStrongDraw = true;
                    }
                }
            }

            // C) Paar / Drilling / Full-House Verbesserungen
            if ($cat === self::CATEGORY_HIGH_CARD) {
                $h1 = self::RANK_LABELS[(int)$holeCards[0]['value']] ?? '';
                $h2 = self::RANK_LABELS[(int)$holeCards[1]['value']] ?? '';
                $possibilities[] = "🎯 Paar-Chance: Jede {$h1} oder {$h2} bringt dir ein eigenes Paar.";
            } elseif ($cat === self::CATEGORY_PAIR) {
                $possibilities[] = "🎯 Ausbau-Chance: Gleiche Karte zum Drilling oder zweite Trefferkarte für Zwei Paare.";
            } elseif ($cat === self::CATEGORY_TWO_PAIR) {
                $possibilities[] = "🔥 Full-House-Chance: Jede weitere Karte deiner beiden Paare bildet ein Full House!";
            } elseif ($cat === self::CATEGORY_THREE_OF_A_KIND) {
                $possibilities[] = "🔥 Monster-Chance: Noch 1 gleiche Karte zum Vierling oder ein Board-Paar zum Full House!";
            }
        } else {
            $possibilities[] = "🏁 Alle 5 Tischkarten liegen – dein finales Blatt steht fest.";
        }

        // Bewertung & Tipp anhand Kategorie
        if ($cat >= self::CATEGORY_FULL_HOUSE) {
            $stars = 5;
            $strengthText = 'Monster-Blatt';
            $color = '#22c55e';
            $advice = 'Absolutes Spitzenblatt! Setzen oder erhöhen, um den Pot maximal aufzubauen.';
        } elseif ($cat >= self::CATEGORY_STRAIGHT) {
            $stars = 5;
            $strengthText = 'Sehr stark';
            $color = '#22c55e';
            $advice = 'Starkes Gewinner-Blatt! Selbstbewusst setzen oder erhöhen.';
        } elseif ($cat === self::CATEGORY_THREE_OF_A_KIND || $cat === self::CATEGORY_TWO_PAIR) {
            $stars = 4;
            $strengthText = 'Stark';
            $color = '#22c55e';
            $advice = 'Sehr gutes Blatt! Value-Bet setzen oder normale Einsätze mitgehen.';
        } elseif ($cat === self::CATEGORY_PAIR) {
            $stars = 3;
            $strengthText = 'Solides Paar';
            $color = '#38bdf8';
            $advice = 'Solides Paar – Kostenlos schieben (Check) oder moderate Einsätze mitgehen.';
        } else {
            if ($hasStrongDraw) {
                $stars = 2;
                $strengthText = 'Draw-Chance';
                $color = '#f59e0b';
                $advice = 'Noch kein Paar, aber starke Draw-Chance! Günstig im Spiel bleiben (Check / kleiner Call).';
            } else {
                $stars = 1;
                $strengthText = 'Schwach';
                $color = '#94a3b8';
                $advice = 'Nur höchste Karte – Kostenlos checken oder bei höherem Einsatz lieber passen (Fold).';
            }
        }

        return [
            'title' => $title,
            'category_name' => self::CATEGORY_NAMES[$cat] ?? 'Blatt',
            'stars' => $stars,
            'strength_text' => $strengthText,
            'strength_color' => $color,
            'possibilities' => $possibilities,
            'advice' => $advice,
        ];
    }

    /**
     * Prüft, ob in einer absteigend sortierten Liste eindeutiger Kartenwerte eine 5er-Straße enthalten ist.
     *
     * @param array<int> $uniqueDescValues
     */
    private static function hasStraightInValues(array $uniqueDescValues): bool
    {
        $vals = $uniqueDescValues;
        if (in_array(14, $vals, true)) {
            $vals[] = 1; // Ass kann auch als 1 für A-2-3-4-5 dienen
        }
        $n = count($vals);
        if ($n < 5) {
            return false;
        }
        for ($i = 0; $i <= $n - 5; $i++) {
            if ($vals[$i] - $vals[$i + 4] === 4) {
                return true;
            }
        }
        return false;
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
