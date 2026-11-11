<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Poker;

/**
 * Erzeugt gemischte 52-Karten-Pokerdecks (Standard französisches Blatt).
 */
class PokerDeckFactory
{
    public const SUITS = ['hearts', 'diamonds', 'clubs', 'spades'];
    public const RANKS = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];

    public const RANK_VALUES = [
        '2' => 2,
        '3' => 3,
        '4' => 4,
        '5' => 5,
        '6' => 6,
        '7' => 7,
        '8' => 8,
        '9' => 9,
        '10' => 10,
        'J' => 11,
        'Q' => 12,
        'K' => 13,
        'A' => 14,
    ];

    /**
     * Erzeugt ein vollständig gemischtes 52er Pokerdeck.
     *
     * @return array<int, array{suit: string, rank: string, id: string, value: int}>
     */
    public static function createShuffledDeck(): array
    {
        $deck = [];
        foreach (self::SUITS as $suit) {
            foreach (self::RANKS as $rank) {
                $deck[] = [
                    'suit' => $suit,
                    'rank' => $rank,
                    'id' => "{$suit}_{$rank}",
                    'value' => self::RANK_VALUES[$rank],
                ];
            }
        }

        shuffle($deck);
        return $deck;
    }
}
