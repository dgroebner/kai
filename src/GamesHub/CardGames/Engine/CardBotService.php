<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\CardGames\Engine;

use Kai\Tools\GamesHub\CardGames\Rules\RuleEngineInterface;

class CardBotService
{
    /**
     * Berechnet die nächste Bot-Aktion.
     *
     * @return array ['action' => 'play', 'card_id' => '...', 'chosen_color' => '...'] oder ['action' => 'draw']
     */
    public static function decideMove(
        array $botHand,
        array $topCard,
        ?string $activeColor,
        int $pendingDraws,
        RuleEngineInterface $ruleEngine
    ): array {
        // Alle regelkonformen Karten filtern
        $playable = [];
        foreach ($botHand as $card) {
            if ($ruleEngine->canPlay($card, $topCard, $activeColor, $pendingDraws, $botHand)) {
                $playable[] = $card;
            }
        }

        // Keine Karte spielbar? -> Ziehen / Launcher drücken
        if (empty($playable)) {
            return ['action' => 'draw'];
        }

        // Taktische Kartenauswahl:
        // 1. Wenn Konter gegen Strafkarten nötig ist: Sofort kontern
        if ($pendingDraws > 0) {
            $chosen = $playable[0];
            $chosenColor = null;
            if ($ruleEngine->allowsColorChoice($chosen)) {
                $validColors = $ruleEngine->getValidColors();
                $colorCounts = self::countColors($botHand);
                $bestColor = $validColors[0];
                $maxCount = -1;
                foreach ($validColors as $col) {
                    $cnt = $colorCounts[$col] ?? 0;
                    if ($cnt > $maxCount) {
                        $maxCount = $cnt;
                        $bestColor = $col;
                    }
                }
                $chosenColor = $bestColor;
            }
            return [
                'action' => 'play',
                'card_id' => $chosen['id'],
                'chosen_color' => $chosenColor,
            ];
        }

        // 2. Farbkarten vor Wildkarten bevorzugen
        $colorCards = array_filter($playable, fn($c) => $c['color'] !== 'wild');
        if (!empty($colorCards)) {
            // Aus den Farbkarten: Bevorzuge die Farbe, von der man am meisten hat
            $colorCounts = self::countColors($botHand);
            usort($colorCards, function ($a, $b) use ($colorCounts) {
                $cntA = $colorCounts[$a['color']] ?? 0;
                $cntB = $colorCounts[$b['color']] ?? 0;
                return $cntB <=> $cntA;
            });
            $selectedCard = $colorCards[0];
        } else {
            // Nur Wildkarten spielbar
            $selectedCard = $playable[0];
        }

        // Falls Farbwahl erforderlich ist: Häufigste eigene Farbe wählen
        $chosenColor = null;
        if ($ruleEngine->allowsColorChoice($selectedCard)) {
            $validColors = $ruleEngine->getValidColors();
            $colorCounts = self::countColors($botHand);
            $bestColor = $validColors[0];
            $maxCount = -1;

            foreach ($validColors as $col) {
                $cnt = $colorCounts[$col] ?? 0;
                if ($cnt > $maxCount) {
                    $maxCount = $cnt;
                    $bestColor = $col;
                }
            }
            $chosenColor = $bestColor;
        }

        return [
            'action' => 'play',
            'card_id' => $selectedCard['id'],
            'chosen_color' => $chosenColor,
        ];
    }

    private static function countColors(array $hand): array
    {
        $counts = [];
        foreach ($hand as $c) {
            $col = $c['color'] ?? '';
            if ($col !== 'wild' && $col !== '') {
                $counts[$col] = ($counts[$col] ?? 0) + 1;
            }
        }
        return $counts;
    }
}
