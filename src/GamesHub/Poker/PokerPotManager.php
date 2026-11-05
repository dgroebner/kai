<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Poker;

/**
 * Verwaltet Haupt- und Nebenpots (Side Pots) bei All-Ins und verteilt Gewinne.
 */
class PokerPotManager
{
    /**
     * Berechnet die Auszahlungen am Showdown.
     *
     * @param array<int, array{id: int, player_name: string, is_folded: bool, total_bet: int}> $players
     * @param array<int, array{score: int, description: string}> $evaluations Map von playerId => Hand-Evaluation
     * @return array{
     *     payouts: array<int, int>,
     *     pot_resolutions: array<int, array{pot_name: string, amount: int, winner_ids: array<int>, winner_names: array<string>, hand_desc: string}>
     * }
     */
    public static function resolveShowdown(array $players, array $evaluations): array
    {
        // Normalisiere Spieler-Eigenschaften aus sheet oder root
        $normalizedPlayers = [];
        foreach ($players as $p) {
            $sheet = $p['sheet'] ?? [];
            $normalizedPlayers[] = [
                'id' => (int)$p['id'],
                'player_name' => (string)($p['player_name'] ?? 'Spieler'),
                'is_folded' => !empty($p['is_folded']) || !empty($sheet['is_folded']),
                'total_bet' => (int)($p['total_bet'] ?? $sheet['total_bet'] ?? 0),
            ];
        }
        $players = $normalizedPlayers;

        $payouts = [];
        foreach ($players as $p) {
            $payouts[(int)$p['id']] = 0;
        }

        // Ungefoldete Spieler
        $activePlayers = array_filter($players, fn($p) => empty($p['is_folded']));

        // Wenn nur ein Spieler nicht gefoldet hat: Er bekommt alle Chips
        if (count($activePlayers) === 1) {
            $soleWinner = reset($activePlayers);
            $totalPot = array_sum(array_column($players, 'total_bet'));
            $payouts[(int)$soleWinner['id']] = $totalPot;

            return [
                'payouts' => $payouts,
                'pot_resolutions' => [
                    [
                        'pot_name' => 'Haupttopf',
                        'amount' => $totalPot,
                        'winner_ids' => [(int)$soleWinner['id']],
                        'winner_names' => [(string)$soleWinner['player_name']],
                        'hand_desc' => 'Alle anderen haben gefoldet',
                    ],
                ],
            ];
        }

        // Alle individuellen Einsatzhöhen ermitteln
        $contributions = [];
        foreach ($players as $p) {
            $pid = (int)$p['id'];
            $bet = (int)($p['total_bet'] ?? 0);
            if ($bet > 0) {
                $contributions[$pid] = $bet;
            }
        }

        $potResolutions = [];
        $potIndex = 1;

        // Pot-Schichten iterativ von unten nach oben abarbeiten
        while (!empty($contributions)) {
            // Niedrigster positiver Einsatz unter noch teilnehmenden (auch gefoldeten) Spielern
            // Aber nur Schwellenwerte von Spielern betrachten, die noch im Pot sind
            $minContribution = min($contributions);
            if ($minContribution <= 0) {
                break;
            }

            // Pot-Höhe dieser Schicht berechnen
            $layerAmount = 0;
            $eligibleWinnerIds = [];

            foreach ($contributions as $pid => $contrib) {
                $take = min($contrib, $minContribution);
                $layerAmount += $take;
                $contributions[$pid] -= $take;

                // Ist dieser Spieler noch im Spiel (nicht gefoldet) und hat diese Schicht bezahlt?
                $pData = null;
                foreach ($players as $pl) {
                    if ((int)$pl['id'] === $pid) {
                        $pData = $pl;
                        break;
                    }
                }

                if ($pData && empty($pData['is_folded'])) {
                    $eligibleWinnerIds[] = $pid;
                }
            }

            // Abgearbeitete Spieler entfernen
            $contributions = array_filter($contributions, fn($amt) => $amt > 0);

            if ($layerAmount <= 0) {
                continue;
            }

            // Wenn niemand eligible ist (z. B. alle haben gefoldet): an den letzten verbliebenen
            if (empty($eligibleWinnerIds)) {
                $eligibleWinnerIds = array_map(fn($p) => (int)$p['id'], array_values($activePlayers));
            }

            // Besten Score unter den berechtigten Spielern ermitteln
            $bestScore = -1;
            $winnersForLayer = [];

            foreach ($eligibleWinnerIds as $pid) {
                $score = $evaluations[$pid]['score'] ?? 0;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $winnersForLayer = [$pid];
                } elseif ($score === $bestScore) {
                    $winnersForLayer[] = $pid;
                }
            }

            // Pot-Schicht auf Gewinner aufteilen (Split Pot bei Gleichstand)
            $winnerCount = count($winnersForLayer);
            $share = intdiv($layerAmount, $winnerCount);
            $remainder = $layerAmount % $winnerCount;

            $winnerNames = [];
            foreach ($winnersForLayer as $idx => $wId) {
                $amountForWinner = $share + ($idx < $remainder ? 1 : 0);
                $payouts[$wId] += $amountForWinner;

                foreach ($players as $pl) {
                    if ((int)$pl['id'] === $wId) {
                        $winnerNames[] = (string)$pl['player_name'];
                        break;
                    }
                }
            }

            $potName = ($potIndex === 1) ? 'Haupttopf' : "Nebentopf " . ($potIndex - 1);
            $bestDesc = $evaluations[$winnersForLayer[0]]['description'] ?? 'Höchste Hand';

            $potResolutions[] = [
                'pot_name' => $potName,
                'amount' => $layerAmount,
                'winner_ids' => $winnersForLayer,
                'winner_names' => $winnerNames,
                'hand_desc' => $bestDesc,
            ];

            $potIndex++;
        }

        return [
            'payouts' => $payouts,
            'pot_resolutions' => $potResolutions,
        ];
    }
}
