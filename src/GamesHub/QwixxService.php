<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub;

class QwixxService
{
    public const ROW_RED = 'red';
    public const ROW_GREEN = 'green';
    public const ROW_YELLOW = 'yellow';
    public const ROW_BLUE = 'blue';

    public const ROWS = [self::ROW_RED, self::ROW_GREEN, self::ROW_YELLOW, self::ROW_BLUE];

    // Punktetabelle nach Dreieckszahlen n*(n+1)/2:
    // 1=1, 2=3, 3=6, 4=10, 5=15, 6=21, 7=28, 8=36, 9=45, 10=55, 11=66, 12=78
    public const SCORE_TABLE = [
        0 => 0, 1 => 1, 2 => 3, 3 => 6, 4 => 10, 5 => 15,
        6 => 21, 7 => 28, 8 => 36, 9 => 45, 10 => 55, 11 => 66, 12 => 78,
    ];

    /**
     * Erzeugt einen leeren Standard-Spielzettel für einen Spieler.
     */
    public function getInitialSheet(): array
    {
        return [
            'rows' => [
                self::ROW_RED => ['crossed' => [], 'locked' => false],
                self::ROW_GREEN => ['crossed' => [], 'locked' => false],
                self::ROW_YELLOW => ['crossed' => [], 'locked' => false],
                self::ROW_BLUE => ['crossed' => [], 'locked' => false],
            ],
            'missed' => 0, // Fehlwürfe (max. 4, je -5 Punkte)
            'turn_confirmed' => false,
            'chosen_action' => null, // 'white', 'color', 'both', 'pass'
        ];
    }

    /**
     * Erzeugt den initialen Zustand für ein neues Qwixx-Spiel.
     */
    public function getInitialState(string $gameMode = 'digital_dice'): array
    {
        return [
            'active_player_index' => 0,
            'turn_number' => 1,
            'turn_phase' => 'roll', // 'roll' -> 'action' -> 'next'
            'closed_rows' => [],    // z.B. ['red', 'green'] (max. 2 bis Spielende)
            'dice' => [
                'white1' => 1,
                'white2' => 1,
                'red' => 1,
                'yellow' => 1,
                'green' => 1,
                'blue' => 1,
            ],
            'last_roll_time' => null,
            'winner_player_id' => null,
            'game_end_reason' => null, // 'two_rows_closed' | 'four_missed'
        ];
    }

    /**
     * Berechnet die Gesamtpunktzahl eines Spielzettels.
     */
    public function calculateTotalScore(array $sheet): array
    {
        $details = [
            'rows' => [],
            'subtotal_rows' => 0,
            'missed_penalty' => 0,
            'total' => 0,
        ];

        foreach (self::ROWS as $color) {
            $crossed = $sheet['rows'][$color]['crossed'] ?? [];
            $count = count($crossed);
            // Schloss zählt als zusätzliches Kreuz
            if (!empty($sheet['rows'][$color]['locked'])) {
                $count++;
            }
            $countClamped = min(12, max(0, $count));
            $rowScore = self::SCORE_TABLE[$countClamped] ?? 78;

            $details['rows'][$color] = [
                'count' => $count,
                'score' => $rowScore,
            ];
            $details['subtotal_rows'] += $rowScore;
        }

        $missedCount = min(4, max(0, (int)($sheet['missed'] ?? 0)));
        $details['missed_penalty'] = $missedCount * 5;
        $details['total'] = $details['subtotal_rows'] - $details['missed_penalty'];

        return $details;
    }

    /**
     * Prüft, ob eine Zahl in einer Farbreihe regelkonform angekreuzt werden darf.
     */
    public function canCrossNumber(array $sheet, string $color, int $number, array $closedRows): array
    {
        if (!in_array($color, self::ROWS, true)) {
            return ['allowed' => false, 'reason' => 'Ungültige Reihe'];
        }

        // Ist die Reihe global bereits geschlossen?
        if (in_array($color, $closedRows, true)) {
            return ['allowed' => false, 'reason' => 'Diese Reihe ist bereits abgeschlossen / gesperrt'];
        }

        $rowState = $sheet['rows'][$color] ?? ['crossed' => [], 'locked' => false];
        if (!empty($rowState['locked'])) {
            return ['allowed' => false, 'reason' => 'Reihe auf deinem Zettel bereits abgeschlossen'];
        }

        $crossed = $rowState['crossed'];
        if (in_array($number, $crossed, true)) {
            return ['allowed' => false, 'reason' => 'Zahl bereits angekreuzt'];
        }

        // Rot / Grün steigen auf (2 bis 12)
        // Gelb / Blau steigen ab (12 bis 2)
        $isAscending = ($color === self::ROW_RED || $color === self::ROW_GREEN);

        if ($number < 2 || $number > 12) {
            return ['allowed' => false, 'reason' => 'Zahl außerhalb des gültigen Bereichs (2–12)'];
        }

        // Richtungsprüfung: Es darf nur rechts vom bisher am weitesten rechts stehenden Kreuz gesetzt werden!
        if (!empty($crossed)) {
            if ($isAscending) {
                $maxCrossed = max($crossed);
                if ($number <= $maxCrossed) {
                    return ['allowed' => false, 'reason' => 'Zahlen dürfen nur von links nach rechts angekreuzt werden'];
                }
            } else {
                $minCrossed = min($crossed);
                if ($number >= $minCrossed) {
                    return ['allowed' => false, 'reason' => 'Zahlen dürfen nur von links nach rechts (12 bis 2) angekreuzt werden'];
                }
            }
        }

        // Abschlussprüfung (12 bei Rot/Grün bzw. 2 bei Gelb/Blau):
        // Das letzte Feld darf nur angekreuzt werden, wenn mindestens 5 Kreuze in der Reihe sind!
        $isLastNumber = ($isAscending && $number === 12) || (!$isAscending && $number === 2);
        if ($isLastNumber && count($crossed) < 5) {
            return ['allowed' => false, 'reason' => 'Die letzte Zahl darf erst ab mindestens 5 Kreuzen in der Reihe geschlossen werden'];
        }

        return ['allowed' => true, 'is_last' => $isLastNumber];
    }

    /**
     * Führt das Ankreuzen auf dem Spielzettel aus.
     */
    public function applyCross(array &$sheet, string $color, int $number, array &$closedRows): bool
    {
        $check = $this->canCrossNumber($sheet, $color, $number, $closedRows);
        if (!$check['allowed']) {
            return false;
        }

        $sheet['rows'][$color]['crossed'][] = $number;
        sort($sheet['rows'][$color]['crossed']);

        // Wenn die letzte Zahl (12 bzw. 2) angekreuzt wurde: Reihe abschließen & Schloss vergeben
        if (!empty($check['is_last'])) {
            $sheet['rows'][$color]['locked'] = true;
            if (!in_array($color, $closedRows, true)) {
                $closedRows[] = $color;
            }
        }

        return true;
    }

    /**
     * Prüft, ob das Spiel beendet ist (2 Reihen geschlossen oder ein Spieler hat 4 Fehlwürfe).
     */
    public function checkGameEnd(array $closedRows, array $players): ?string
    {
        if (count($closedRows) >= 2) {
            return 'two_rows_closed';
        }

        foreach ($players as $p) {
            $missed = (int)($p['sheet']['missed'] ?? 0);
            if ($missed >= 4) {
                return 'four_missed';
            }
        }

        return null;
    }

    /**
     * Würfelt die 6 Würfel neu (gesperrte Farbwürfel bleiben unberührt oder auf 0).
     */
    public function rollDice(array $currentDice, array $closedRows): array
    {
        $newDice = [
            'white1' => random_int(1, 6),
            'white2' => random_int(1, 6),
        ];

        foreach (self::ROWS as $color) {
            if (in_array($color, $closedRows, true)) {
                // Reihe ist zu: Farbwürfel ist aus dem Spiel
                $newDice[$color] = $currentDice[$color] ?? 0;
            } else {
                $newDice[$color] = random_int(1, 6);
            }
        }

        return $newDice;
    }
}
