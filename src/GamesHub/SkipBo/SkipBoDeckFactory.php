<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\SkipBo;

/**
 * Erzeugt das offizielle Skip-Bo Kartendeck mit 162 Karten.
 *
 * Zusammensetzung:
 * - 144 Zahlenkarten: 12 Sätze mit jeweils den Zahlen 1 bis 12
 *   * 1 bis 4: Blaues Theme (Blue)
 *   * 5 bis 8: Grünes Theme (Green)
 *   * 9 bis 12: Oranges/Rotes Theme (Orange)
 * - 18 Skip-Bo Wildcard-Karten (Multicolor / Vermilion)
 */
class SkipBoDeckFactory
{
    /**
     * @return SkipBoCard[]
     */
    public static function createDeck(): array
    {
        $cards = [];

        // 1. 144 Zahlenkarten erzeugen (12 Durchläufe von 1 bis 12)
        for ($set = 1; $set <= 12; $set++) {
            for ($num = 1; $num <= 12; $num++) {
                $colorGroup = self::determineColorGroup($num);
                $id = sprintf('sb_num_%02d_%02d_%s', $num, $set, bin2hex(random_bytes(2)));
                $cards[] = new SkipBoCard($id, SkipBoCard::TYPE_NUMBER, $num, $colorGroup);
            }
        }

        // 2. 18 Skip-Bo Wildcard-Karten erzeugen
        for ($w = 1; $w <= 18; $w++) {
            $id = sprintf('sb_wild_%02d_%s', $w, bin2hex(random_bytes(2)));
            $cards[] = new SkipBoCard($id, SkipBoCard::TYPE_SKIPBO, 0, 'wild');
        }

        // 3. Gründlich mischen (Kryptografisch sicher)
        self::shuffleDeck($cards);

        return $cards;
    }

    private static function determineColorGroup(int $value): string
    {
        if ($value >= 1 && $value <= 4) {
            return 'blue';
        }
        if ($value >= 5 && $value <= 8) {
            return 'green';
        }
        return 'orange';
    }

    /**
     * Fisher-Yates Shuffle mit random_int
     *
     * @param array &$cards
     */
    public static function shuffleDeck(array &$cards): void
    {
        $count = count($cards);
        for ($i = $count - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $temp = $cards[$i];
            $cards[$i] = $cards[$j];
            $cards[$j] = $temp;
        }
    }
}
