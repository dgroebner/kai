<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Schnauzer;

class SchnauzerScoreCalculator
{
    /**
     * Berechnet die Punktzahl und Kombination einer Schnauzer-Hand (3 Karten).
     *
     * @param array<int, array<string, mixed>> $cards
     * @param bool $gelenauerRegeln Gelenauer Regeln: 3 Asse = Schnauzer (33 Punkte), 31 Punkte = reguläre 31 (sofort auslegen)
     * @return array{score: float, is_31: bool, is_feuer: bool, is_drilling: bool, best_suit: string, label: string}
     */
    public static function calculateScore(array $cards, bool $gelenauerRegeln = false): array
    {
        if (empty($cards)) {
            return [
                'score' => 0.0,
                'is_31' => false,
                'is_feuer' => false,
                'is_drilling' => false,
                'best_suit' => '',
                'label' => '0 Punkte',
            ];
        }

        // 1. Prüfung auf Drilling (Drei Gleiche gleichen Rangs)
        if (count($cards) === 3) {
            $r0 = (string)($cards[0]['rank'] ?? '');
            $r1 = (string)($cards[1]['rank'] ?? '');
            $r2 = (string)($cards[2]['rank'] ?? '');

            if ($r0 !== '' && $r0 === $r1 && $r1 === $r2) {
                // Drei Asse / Daus: Prüfen, ob Gelenauer Regeln (Schnauzer = 33 Punkte) oder Standard (30,5 Punkte) gilt
                if ($r0 === 'A') {
                    if ($gelenauerRegeln) {
                        return [
                            'score' => 33.0,
                            'is_31' => false,
                            'is_feuer' => true,
                            'is_drilling' => true,
                            'best_suit' => 'all',
                            'label' => 'SCHNAUZER! (Drei Asse)',
                        ];
                    }

                    return [
                        'score' => 30.5,
                        'is_31' => false,
                        'is_feuer' => false,
                        'is_drilling' => true,
                        'best_suit' => 'all',
                        'label' => '30,5 Punkte (Drilling Asse)',
                    ];
                }

                // Jeder andere Drilling (z.B. drei 7er, drei Könige) = 30,5 Punkte
                $rankName = match ($r0) {
                    'K' => 'Könige',
                    'Q' => 'Ober / Damen',
                    'J' => 'Unter / Buben',
                    default => "{$r0}er",
                };

                return [
                    'score' => 30.5,
                    'is_31' => false,
                    'is_feuer' => false,
                    'is_drilling' => true,
                    'best_suit' => 'all',
                    'label' => "30,5 Punkte (Drilling {$rankName})",
                ];
            }
        }

        // 2. Summenbildung nach Farben
        $suitSums = [
            'clubs' => 0,
            'spades' => 0,
            'hearts' => 0,
            'diamonds' => 0,
        ];

        foreach ($cards as $c) {
            $suit = (string)($c['suit'] ?? '');
            $val = (int)($c['value'] ?? 0);
            if (isset($suitSums[$suit])) {
                $suitSums[$suit] += $val;
            }
        }

        $bestSuit = 'clubs';
        $bestScore = 0;

        foreach ($suitSums as $suit => $sum) {
            if ($sum > $bestScore) {
                $bestScore = $sum;
                $bestSuit = $suit;
            }
        }

        $suitGerman = match ($bestSuit) {
            'clubs' => 'Eichel / Kreuz',
            'spades' => 'Blatt / Pik',
            'hearts' => 'Herz',
            'diamonds' => 'Schellen / Karo',
            default => '',
        };

        $is31 = ($bestScore === 31);
        if ($is31) {
            $label = $gelenauerRegeln
                ? "31 Punkte ({$suitGerman})"
                : "31 Punkte ({$suitGerman}) - SCHNAUZER!";
        } else {
            $label = "{$bestScore} Punkte ({$suitGerman})";
        }

        return [
            'score' => (float)$bestScore,
            'is_31' => $is31,
            'is_feuer' => false,
            'is_drilling' => false,
            'best_suit' => $bestSuit,
            'label' => $label,
        ];
    }
}
