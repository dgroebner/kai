<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Labyrinth;

/**
 * KI-Logik für Labyrinth-Bots: simuliert alle erlaubten Einschübe und Drehungen.
 */
final class LabyrinthBot
{
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
     * @return array{side: string, index: int, rotation: int, move_to: array}
     */
    public static function decide(
        array $board,
        array $spare,
        ?array $forbidden,
        array $pawns,
        int $botId,
        ?string $treasureId,
        array $homeCell
    ): array {
        $best = null;
        $bestScore = PHP_INT_MAX;

        foreach (LabyrinthBoard::SIDES as $side) {
            foreach (LabyrinthBoard::SHIFT_INDEXES as $index) {
                if ($forbidden && $forbidden['side'] === $side && (int)$forbidden['index'] === $index) {
                    continue;
                }
                foreach ([0, 90, 180, 270] as $rotation) {
                    $rotatedSpare = $spare;
                    $rotatedSpare['rot'] = $rotation;
                    $shifted = LabyrinthBoard::shift($board, $rotatedSpare, $side, $index, $pawns);

                    $target = $treasureId !== null
                        ? LabyrinthBoard::findTreasure($shifted['board'], $treasureId)
                        : $homeCell;

                    [$moveTo, $score] = self::bestMove(
                        $shifted['board'],
                        $shifted['pawns'][$botId],
                        $target
                    );

                    // Kleiner Zufallsanteil bricht Gleichstände und macht Bots weniger vorhersehbar
                    $score = $score * 10 + random_int(0, 9);
                    if ($score < $bestScore) {
                        $bestScore = $score;
                        $best = ['side' => $side, 'index' => $index, 'rotation' => $rotation, 'move_to' => $moveTo];
                    }
                }
            }
        }

        return $best;
    }

    /**
     * Wählt das erreichbare Feld mit der geringsten Entfernung zum Ziel.
     *
     * @return array{0: array, 1: int} Zielfeld und Distanz (0 = Ziel erreicht)
     */
    private static function bestMove(array $board, array $from, ?array $target): array
    {
        $reachable = LabyrinthBoard::reachable($board, $from[0], $from[1]);
        if ($target === null) {
            return [$from, 99];
        }

        $bestCell = $from;
        $bestDistance = PHP_INT_MAX;
        foreach ($reachable as $cell) {
            $distance = abs($cell[0] - $target[0]) + abs($cell[1] - $target[1]);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestCell = $cell;
            }
        }

        return [$bestCell, $bestDistance];
    }
}
