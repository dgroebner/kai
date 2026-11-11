<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Labyrinth;

/**
 * KI-Logik für Labyrinth-Bots mit drei wählbaren Schwierigkeitsgraden:
 * - easy:   🌱 Leicht (Gelegenheitsspieler - prüft weniger Pfeile/Drehungen, übersieht weite Pfade)
 * - normal: ⚔️ Normal (Ausgewogen - menschliche Spielstärke, anspruchsvoll aber schlagbar)
 * - master: 🧠 Meister (Gnadenlos - prüft alle 44 Kombinationen per BFS wie bisher)
 */
final class LabyrinthBot
{
    public const DIFFICULTY_EASY = 'easy';
    public const DIFFICULTY_NORMAL = 'normal';
    public const DIFFICULTY_MASTER = 'master';

    public const DIFFICULTIES = [
        self::DIFFICULTY_EASY => '🌱 Leicht (Gelegenheitsspieler)',
        self::DIFFICULTY_NORMAL => '⚔️ Normal (Ausgewogen)',
        self::DIFFICULTY_MASTER => '🧠 Meister (Gnadenlos)',
    ];

    /**
     * Wählt Einschub, Drehung und Zielfeld für den aktiven Bot.
     *
     * @param array $board        Aktuelles Brett
     * @param array $spare        Freie Kachel
     * @param array|null $forbidden ['side' => ..., 'index' => ...] oder null
     * @param array $pawns        Map Spieler-ID => [r, c] aller Figuren
     * @param int $botId          Spieler-ID des Bots
     * @param string|null $treasureId Gesuchter Schatz (null = Rückweg zum Startfeld)
     * @param array $homeCell     Startfeld des Bots
     * @param string $difficulty  'easy' | 'normal' | 'master'
     * @return array{side: string, index: int, rotation: int, move_to: array}
     */
    public static function decide(
        array $board,
        array $spare,
        ?array $forbidden,
        array $pawns,
        int $botId,
        ?string $treasureId,
        array $homeCell,
        string $difficulty = self::DIFFICULTY_NORMAL
    ): array {
        $allCandidates = [];
        foreach (LabyrinthBoard::SIDES as $s) {
            foreach (LabyrinthBoard::SHIFT_INDEXES as $idx) {
                if ($forbidden && $forbidden['side'] === $s && (int)$forbidden['index'] === $idx) {
                    continue;
                }
                $allCandidates[] = [$s, $idx];
            }
        }

        $candidates = $allCandidates;
        $rotations = [0, 90, 180, 270];

        // Menschliche Beschränkung des Suchraums je nach Stufe:
        if ($difficulty === self::DIFFICULTY_EASY) {
            // Gelegenheitsspieler: prüft nur 3 bis 4 zufällige Pfeile und 2 Drehungen
            shuffle($candidates);
            $candidates = array_slice($candidates, 0, random_int(3, 4));
            $rotations = random_int(0, 1) === 0 ? [0, 90] : [180, 270];
        } elseif ($difficulty === self::DIFFICULTY_NORMAL) {
            // Normal / Ausgewogen: prüft 6 bis 7 Pfeile und 3 Drehungen
            shuffle($candidates);
            $candidates = array_slice($candidates, 0, random_int(6, 7));
            array_splice($rotations, random_int(0, 3), 1);
        }

        $best = null;
        $bestScore = PHP_INT_MAX;

        foreach ($candidates as [$side, $index]) {
            foreach ($rotations as $rotation) {
                $rotatedSpare = $spare;
                $rotatedSpare['rot'] = $rotation;
                $shifted = LabyrinthBoard::shift($board, $rotatedSpare, $side, $index, $pawns);

                $target = $treasureId !== null
                    ? LabyrinthBoard::findTreasure($shifted['board'], $treasureId)
                    : $homeCell;

                [$moveTo, $score] = self::evaluateMove(
                    $shifted['board'],
                    $shifted['pawns'][$botId],
                    $target,
                    $difficulty
                );

                $score = $score * 10 + random_int(0, 9);
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $best = ['side' => $side, 'index' => $index, 'rotation' => $rotation, 'move_to' => $moveTo];
                }
            }
        }

        // Fallback-Sicherheit falls alle Kandidaten durch Filter leer sein sollten
        if ($best === null) {
            $fallbackSide = $allCandidates[0][0];
            $fallbackIndex = $allCandidates[0][1];
            $shifted = LabyrinthBoard::shift($board, $spare, $fallbackSide, $fallbackIndex, $pawns);
            $best = [
                'side' => $fallbackSide,
                'index' => $fallbackIndex,
                'rotation' => (int)($spare['rot'] ?? 0),
                'move_to' => $shifted['pawns'][$botId],
            ];
        }

        return $best;
    }

    /**
     * Bewertet Zielfeld und Distanz unter Einbeziehung menschlicher Wahrnehmungsschwellen.
     *
     * @return array{0: array, 1: int} Zielfeld und Score-Distanz (0 = Ziel erreicht)
     */
    private static function evaluateMove(
        array $board,
        array $from,
        ?array $target,
        string $difficulty
    ): array {
        if ($target === null) {
            return [$from, 99];
        }

        $distMap = LabyrinthBoard::reachableDistances($board, $from[0], $from[1]);
        $targetKey = $target[0] . ',' . $target[1];

        // 1. Ziel direkt über offene Gänge erreichbar?
        if (isset($distMap[$targetKey])) {
            $pathLen = $distMap[$targetKey];
            $seesIt = true;

            // Menschlicher Wahrnehmungsfaktor:
            if ($difficulty === self::DIFFICULTY_EASY) {
                // Bei "Leicht" werden weite Zickzack-Wege häufig übersehen
                $seesIt = $pathLen <= 2 ? (random_int(1, 100) <= 60) : (random_int(1, 100) <= 20);
            } elseif ($difficulty === self::DIFFICULTY_NORMAL) {
                // Bei "Normal" werden extrem lange Schlangenwege über 3 Kacheln manchmal übersehen
                $seesIt = $pathLen <= 3 ? (random_int(1, 100) <= 85) : (random_int(1, 100) <= 45);
            }

            if ($seesIt) {
                return [$target, 0];
            }
        }

        // 2. Ziel nicht direkt erreichbar (oder übersehen): Feld mit minimalem Abstand zum Ziel ansteuern
        $bestCell = $from;
        $bestDistance = PHP_INT_MAX;

        foreach ($distMap as $key => $stepDist) {
            [$r, $c] = explode(',', $key);
            $r = (int)$r;
            $c = (int)$c;
            $manhattan = abs($r - $target[0]) + abs($c - $target[1]);

            if ($manhattan < $bestDistance) {
                $bestDistance = $manhattan;
                $bestCell = [$r, $c];
            }
        }

        return [$bestCell, $bestDistance];
    }
}
