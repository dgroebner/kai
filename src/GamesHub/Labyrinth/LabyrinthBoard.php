<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\Labyrinth;

/**
 * Reine Brettlogik für "Das verrückte Labyrinth" (ohne Datenbankzugriff):
 * Brett-Generierung, Kachel-Öffnungen, Einschieben und Wegfindung (BFS).
 *
 * Koordinaten sind immer [Zeile, Spalte] (0..6). Richtungen: 0=N, 1=E, 2=S, 3=W.
 * Rotation einer Kachel wird in Grad (0/90/180/270) im Uhrzeigersinn gespeichert.
 */
final class LabyrinthBoard
{
    public const SIZE = 7;

    public const SIDES = ['TOP', 'BOTTOM', 'LEFT', 'RIGHT'];
    public const SHIFT_INDEXES = [1, 3, 5];

    /** Schatz-ID => [Emoji, deutscher Name]. */
    public const TREASURES = [
        'ghost' => ['👻', 'Gespenst'],
        'dragon' => ['🐉', 'Drache'],
        'crown' => ['👑', 'Krone'],
        'key' => ['🗝️', 'Schlüssel'],
        'gem' => ['💎', 'Edelstein'],
        'owl' => ['🦉', 'Eule'],
        'potion' => ['🧪', 'Zaubertrank'],
        'scroll' => ['📜', 'Schriftrolle'],
        'swords' => ['⚔️', 'Schwerter'],
        'ring' => ['💍', 'Ring'],
        'skull' => ['💀', 'Totenkopf'],
        'bat' => ['🦇', 'Fledermaus'],
        'spider' => ['🕷️', 'Spinne'],
        'mouse' => ['🐭', 'Maus'],
        'butterfly' => ['🦋', 'Schmetterling'],
        'candle' => ['🕯️', 'Kerze'],
        'book' => ['📖', 'Zauberbuch'],
        'map' => ['🗺️', 'Schatzkarte'],
        'gold' => ['💰', 'Goldsack'],
        'unicorn' => ['🦄', 'Einhorn'],
        'snake' => ['🐍', 'Schlange'],
        'lizard' => ['🦎', 'Echse'],
        'fairy' => ['🧚', 'Fee'],
        'moon' => ['🌙', 'Mond'],
    ];

    /** Offene Seiten je Kacheltyp bei Rotation 0. */
    private const BASE_OPENINGS = [
        'I' => [0, 2],
        'L' => [0, 1],
        'T' => [1, 2, 3],
    ];

    /** Startfelder in Sitzreihenfolge (bei 2 Spielern gegenüberliegend). */
    public const START_CORNERS = [
        'yellow' => [0, 0],
        'green' => [6, 6],
        'red' => [0, 6],
        'blue' => [6, 0],
    ];

    private const CORNER_ROTATIONS = ['yellow' => 90, 'red' => 180, 'green' => 270, 'blue' => 0];

    /** Feste T-Kacheln: "r,c" => Rotation. */
    private const FIXED_T_ROTATIONS = [
        '0,2' => 0, '0,4' => 0,
        '2,6' => 90, '4,6' => 90,
        '6,2' => 180, '6,4' => 180,
        '2,0' => 270, '4,0' => 270,
        '2,2' => 270, '2,4' => 0, '4,2' => 180, '4,4' => 90,
    ];

    /**
     * Erzeugt Brett und freie Kachel: 16 feste + 33 gemischte Kacheln, 1 Reservekachel.
     *
     * @return array{board: array, spare: array}
     */
    public static function generate(): array
    {
        $treasureIds = array_keys(self::TREASURES);
        shuffle($treasureIds);

        $board = array_fill(0, self::SIZE, array_fill(0, self::SIZE, null));

        foreach (self::START_CORNERS as $color => [$r, $c]) {
            $board[$r][$c] = self::tile('L', self::CORNER_ROTATIONS[$color], null, true, $color);
        }
        foreach (self::FIXED_T_ROTATIONS as $key => $rotation) {
            [$r, $c] = array_map('intval', explode(',', $key));
            $board[$r][$c] = self::tile('T', $rotation, array_pop($treasureIds), true);
        }

        // 34 bewegliche Kacheln: 13 gerade, 15 Kurven (6 mit Schatz), 6 T-Stücke (alle mit Schatz)
        $movable = [];
        for ($i = 0; $i < 13; $i++) {
            $movable[] = ['I', null];
        }
        for ($i = 0; $i < 9; $i++) {
            $movable[] = ['L', null];
        }
        for ($i = 0; $i < 6; $i++) {
            $movable[] = ['L', array_pop($treasureIds)];
            $movable[] = ['T', array_pop($treasureIds)];
        }
        shuffle($movable);

        $spare = null;
        foreach ($movable as [$type, $treasure]) {
            $tile = self::tile($type, random_int(0, 3) * 90, $treasure, false);
            $placed = false;
            for ($r = 0; $r < self::SIZE && !$placed; $r++) {
                for ($c = 0; $c < self::SIZE && !$placed; $c++) {
                    if ($board[$r][$c] === null) {
                        $board[$r][$c] = $tile;
                        $placed = true;
                    }
                }
            }
            if (!$placed) {
                $spare = $tile;
            }
        }

        return ['board' => $board, 'spare' => $spare];
    }

    public static function tile(string $type, int $rotation, ?string $treasure, bool $fixed, ?string $startColor = null): array
    {
        return [
            'type' => $type,
            'rot' => $rotation,
            'treasure' => $treasure,
            'fixed' => $fixed,
            'start' => $startColor,
        ];
    }

    /**
     * Gibt die offenen Seiten (0=N,1=E,2=S,3=W) einer Kachel zurück.
     */
    public static function openings(array $tile): array
    {
        $steps = intdiv(((int)$tile['rot']) % 360, 90);
        $result = [];
        foreach (self::BASE_OPENINGS[$tile['type']] ?? [] as $dir) {
            $result[] = ($dir + $steps) % 4;
        }
        return $result;
    }

    /**
     * Prüft, ob Seite/Index ein erlaubter Einschub-Punkt ist.
     */
    public static function isValidShift(string $side, int $index): bool
    {
        return in_array($side, self::SIDES, true) && in_array($index, self::SHIFT_INDEXES, true);
    }

    public static function oppositeSide(string $side): string
    {
        return match ($side) {
            'TOP' => 'BOTTOM',
            'BOTTOM' => 'TOP',
            'LEFT' => 'RIGHT',
            default => 'LEFT',
        };
    }

    /**
     * Schiebt die freie Kachel ein. Figuren auf der herausgeschobenen Kachel
     * wandern auf die neu eingeschobene Kachel auf der Gegenseite.
     *
     * @param array $pawnPositions Map Spieler-ID => [r, c]
     * @return array{board: array, spare: array, pawns: array}
     */
    public static function shift(array $board, array $spare, string $side, int $index, array $pawnPositions): array
    {
        $last = self::SIZE - 1;
        $pushedOut = null;

        // Gemeinsamer Ablauf: Linie als Liste von Koordinaten in Schieberichtung
        $line = [];
        for ($i = 0; $i < self::SIZE; $i++) {
            $line[] = match ($side) {
                'TOP' => [$i, $index],
                'BOTTOM' => [$last - $i, $index],
                'LEFT' => [$index, $i],
                default => [$index, $last - $i],
            };
        }

        $pushedOut = $board[$line[$last][0]][$line[$last][1]];
        for ($i = $last; $i > 0; $i--) {
            $board[$line[$i][0]][$line[$i][1]] = $board[$line[$i - 1][0]][$line[$i - 1][1]];
        }
        $board[$line[0][0]][$line[0][1]] = $spare;

        foreach ($pawnPositions as $playerId => [$r, $c]) {
            foreach ($line as $i => [$lr, $lc]) {
                if ($lr === $r && $lc === $c) {
                    $target = $line[($i + 1) % self::SIZE];
                    $pawnPositions[$playerId] = [$target[0], $target[1]];
                    break;
                }
            }
        }

        return ['board' => $board, 'spare' => $pushedOut, 'pawns' => $pawnPositions];
    }

    /**
     * Breitensuche mit Schrittdistanzen: ermittelt alle erreichbaren Felder und deren Pfadlänge.
     *
     * @return array<string, int> Map "r,c" => Schrittanzahl vom Startfeld
     */
    public static function reachableDistances(array $board, int $startRow, int $startCol): array
    {
        $dr = [-1, 0, 1, 0];
        $dc = [0, 1, 0, -1];
        $seen = [$startRow . ',' . $startCol => 0];
        $queue = [[$startRow, $startCol, 0]];

        while ($queue) {
            [$r, $c, $d] = array_shift($queue);
            foreach (self::openings($board[$r][$c]) as $dir) {
                $nr = $r + $dr[$dir];
                $nc = $c + $dc[$dir];
                if ($nr < 0 || $nc < 0 || $nr >= self::SIZE || $nc >= self::SIZE || isset($seen[$nr . ',' . $nc])) {
                    continue;
                }
                if (in_array(($dir + 2) % 4, self::openings($board[$nr][$nc]), true)) {
                    $seen[$nr . ',' . $nc] = $d + 1;
                    $queue[] = [$nr, $nc, $d + 1];
                }
            }
        }

        return $seen;
    }

    /**
     * Breitensuche: alle über offene Gänge erreichbaren Felder (inkl. Startfeld).
     *
     * @return array<int, array{0:int,1:int}>
     */
    public static function reachable(array $board, int $startRow, int $startCol): array
    {
        $distances = self::reachableDistances($board, $startRow, $startCol);
        $result = [];
        foreach ($distances as $key => $_) {
            [$r, $c] = explode(',', $key);
            $result[] = [(int)$r, (int)$c];
        }
        return $result;
    }

    /**
     * Sucht das Feld, auf dem ein Schatz liegt (null, falls er auf der freien Kachel liegt).
     */
    public static function findTreasure(array $board, string $treasureId): ?array
    {
        foreach ($board as $r => $row) {
            foreach ($row as $c => $tile) {
                if (($tile['treasure'] ?? null) === $treasureId) {
                    return [$r, $c];
                }
            }
        }
        return null;
    }
}
