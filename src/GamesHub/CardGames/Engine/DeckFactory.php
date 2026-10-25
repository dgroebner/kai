<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\CardGames\Engine;

use Kai\Tools\GamesHub\CardGames\Models\Card;

class DeckFactory
{
    public const COLORS_UNO = ['red', 'yellow', 'green', 'blue'];
    public const COLORS_MAUMAU = ['hearts', 'diamonds', 'spades', 'clubs'];

    /**
     * Erstellt ein gemischtes Deck für den jeweiligen Spielmodus.
     *
     * @return Card[]
     */
    public static function createDeck(string $gameMode): array
    {
        $cards = match ($gameMode) {
            'mau_mau' => self::createMauMauDeck(),
            'uno_extreme' => self::createUnoExtremeDeck(),
            default => self::createUnoClassicDeck(),
        };

        return self::shuffleDeck($cards);
    }

    /**
     * Klassisches Uno Deck (108 Karten)
     *
     * @return Card[]
     */
    public static function createUnoClassicDeck(): array
    {
        $cards = [];
        $seq = 0;

        foreach (self::COLORS_UNO as $color) {
            // 1x 0 pro Farbe
            $cards[] = new Card("{$color}_0_{$seq}", $color, 'number', 0, '0', 0);
            $seq++;

            // 2x 1..9 pro Farbe
            for ($num = 1; $num <= 9; $num++) {
                $cards[] = new Card("{$color}_{$num}_a_{$seq}", $color, 'number', $num, (string)$num, $num);
                $seq++;
                $cards[] = new Card("{$color}_{$num}_b_{$seq}", $color, 'number', $num, (string)$num, $num);
                $seq++;
            }

            // 2x Draw 2 (+2)
            $cards[] = new Card("{$color}_draw2_a_{$seq}", $color, 'draw2', null, '+2', 20);
            $seq++;
            $cards[] = new Card("{$color}_draw2_b_{$seq}", $color, 'draw2', null, '+2', 20);
            $seq++;

            // 2x Reverse
            $cards[] = new Card("{$color}_reverse_a_{$seq}", $color, 'reverse', null, '⇄', 20);
            $seq++;
            $cards[] = new Card("{$color}_reverse_b_{$seq}", $color, 'reverse', null, '⇄', 20);
            $seq++;

            // 2x Skip
            $cards[] = new Card("{$color}_skip_a_{$seq}", $color, 'skip', null, '⊘', 20);
            $seq++;
            $cards[] = new Card("{$color}_skip_b_{$seq}", $color, 'skip', null, '⊘', 20);
            $seq++;
        }

        // 4x Wild (Farbwahl)
        for ($i = 1; $i <= 4; $i++) {
            $cards[] = new Card("wild_{$i}_{$seq}", 'wild', 'wild', null, '★', 50);
            $seq++;
        }

        // 4x Wild Draw 4 (+4 Farbwahl)
        for ($i = 1; $i <= 4; $i++) {
            $cards[] = new Card("wild_draw4_{$i}_{$seq}", 'wild', 'wild_draw4', null, '+4', 50);
            $seq++;
        }

        return $cards;
    }

    /**
     * Uno Extreme Deck mit Hit 2, Extreme Hit und Discard All
     *
     * @return Card[]
     */
    public static function createUnoExtremeDeck(): array
    {
        $cards = [];
        $seq = 0;

        foreach (self::COLORS_UNO as $color) {
            // 1x 0
            $cards[] = new Card("{$color}_0_{$seq}", $color, 'number', 0, '0', 0);
            $seq++;

            // 2x 1..9
            for ($num = 1; $num <= 9; $num++) {
                $cards[] = new Card("{$color}_{$num}_a_{$seq}", $color, 'number', $num, (string)$num, $num);
                $seq++;
                $cards[] = new Card("{$color}_{$num}_b_{$seq}", $color, 'number', $num, (string)$num, $num);
                $seq++;
            }

            // 2x Reverse
            $cards[] = new Card("{$color}_reverse_a_{$seq}", $color, 'reverse', null, '⇄', 20);
            $seq++;
            $cards[] = new Card("{$color}_reverse_b_{$seq}", $color, 'reverse', null, '⇄', 20);
            $seq++;

            // 2x Skip
            $cards[] = new Card("{$color}_skip_a_{$seq}", $color, 'skip', null, '⊘', 20);
            $seq++;
            $cards[] = new Card("{$color}_skip_b_{$seq}", $color, 'skip', null, '⊘', 20);
            $seq++;

            // 2x Hit 2 (Extreme Launcher 2x betätigen)
            $cards[] = new Card("{$color}_hit2_a_{$seq}", $color, 'hit2', null, '⚡2', 20);
            $seq++;
            $cards[] = new Card("{$color}_hit2_b_{$seq}", $color, 'hit2', null, '⚡2', 20);
            $seq++;

            // 1x Discard All (Alle Handkarten dieser Farbe abwerfen)
            $cards[] = new Card("{$color}_discard_all_{$seq}", $color, 'discard_all', null, '🗂️', 30);
            $seq++;
        }

        // 4x Wild
        for ($i = 1; $i <= 4; $i++) {
            $cards[] = new Card("wild_{$i}_{$seq}", 'wild', 'wild', null, '★', 50);
            $seq++;
        }

        // 4x Extreme Hit (Wild Extreme: Nächster Spieler muss Launcher drücken, bis Karten fliegen)
        for ($i = 1; $i <= 4; $i++) {
            $cards[] = new Card("extreme_hit_{$i}_{$seq}", 'wild', 'extreme_hit', null, '⚡★', 50);
            $seq++;
        }

        return $cards;
    }

    /**
     * Mau-Mau Skat-Deck (32 Karten)
     *
     * @return Card[]
     */
    public static function createMauMauDeck(): array
    {
        $cards = [];
        $seq = 0;

        $values = [
            ['val' => 7, 'type' => 'draw2', 'label' => '7 (+2)', 'score' => 7],
            ['val' => 8, 'type' => 'skip', 'label' => '8 (⊘)', 'score' => 8],
            ['val' => 9, 'type' => 'number', 'label' => '9', 'score' => 9],
            ['val' => 10, 'type' => 'number', 'label' => '10', 'score' => 10],
            ['val' => 11, 'type' => 'jack', 'label' => 'Bube (★)', 'score' => 20], // Bube = Farbwunsch
            ['val' => 12, 'type' => 'number', 'label' => 'Dame', 'score' => 10],
            ['val' => 13, 'type' => 'number', 'label' => 'König', 'score' => 10],
            ['val' => 14, 'type' => 'ace', 'label' => 'Ass (↺)', 'score' => 11], // Ass = Nochmal legen
        ];

        foreach (self::COLORS_MAUMAU as $color) {
            foreach ($values as $item) {
                $cards[] = new Card(
                    "{$color}_{$item['val']}_{$seq}",
                    $color,
                    $item['type'],
                    $item['val'],
                    $item['label'],
                    $item['score']
                );
                $seq++;
            }
        }

        return $cards;
    }

    /**
     * Kryptografisch sicheres Mischen mit random_int (Fisher-Yates)
     *
     * @param Card[] $deck
     * @return Card[]
     */
    public static function shuffleDeck(array $deck): array
    {
        $count = count($deck);
        for ($i = $count - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $temp = $deck[$i];
            $deck[$i] = $deck[$j];
            $deck[$j] = $temp;
        }
        return array_values($deck);
    }
}
