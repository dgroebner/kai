<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Schnauzer;

class SchnauzerDeckFactory
{
    public const SUITS = ['clubs', 'spades', 'hearts', 'diamonds'];
    public const RANKS = [
        '7' => ['value' => 7, 'label' => '7'],
        '8' => ['value' => 8, 'label' => '8'],
        '9' => ['value' => 9, 'label' => '9'],
        '10' => ['value' => 10, 'label' => '10'],
        'J' => ['value' => 10, 'label' => 'Unter / Bube'],
        'Q' => ['value' => 10, 'label' => 'Ober / Dame'],
        'K' => ['value' => 10, 'label' => 'König'],
        'A' => ['value' => 11, 'label' => 'Daus / Ass'],
    ];

    /**
     * Erzeugt ein frisch gemischtes 32er Skatdeck für Schnauzer.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function createShuffledDeck(): array
    {
        $cards = [];
        foreach (self::SUITS as $suit) {
            foreach (self::RANKS as $rank => $info) {
                $cards[] = [
                    'id' => "{$suit}_{$rank}",
                    'suit' => $suit,
                    'rank' => (string)$rank,
                    'value' => (int)$info['value'],
                    'label' => "{$info['label']} " . self::getSuitSymbol($suit),
                ];
            }
        }

        // Fisher-Yates Shuffle mit kryptografischem Zufall
        $count = count($cards);
        for ($i = $count - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $tmp = $cards[$i];
            $cards[$i] = $cards[$j];
            $cards[$j] = $tmp;
        }

        return $cards;
    }

    private static function getSuitSymbol(string $suit): string
    {
        return match ($suit) {
            'clubs' => '♣',
            'spades' => '♠',
            'hearts' => '♥',
            'diamonds' => '♦',
            default => '',
        };
    }
}
