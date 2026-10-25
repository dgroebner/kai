<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\CardGames\Rules;

use Kai\Tools\GamesHub\CardGames\Engine\DeckFactory;

class UnoExtremeRules implements RuleEngineInterface
{
    public function canPlay(array $card, array $topCard, ?string $activeColor, int $pendingDraws, array $playerHand): bool
    {
        // Wenn Launcher-Hits ausstehen, kann man mit Hit 2 oder Extreme Hit (Farbwünscher mit Buttondrückpflicht) kontern
        if ($pendingDraws > 0) {
            if ($card['type'] === 'hit2' || $card['type'] === 'extreme_hit') {
                return true;
            }
            return false;
        }

        // Farbwunsch aktiv?
        if (!empty($activeColor)) {
            return $card['color'] === $activeColor || $card['color'] === 'wild';
        }

        // Wild & Extreme Hit immer erlaubt
        if ($card['color'] === 'wild') {
            return true;
        }

        // Gleiche Farbe
        if ($card['color'] === $topCard['color']) {
            return true;
        }

        // Gleiche Nummer
        if ($card['type'] === 'number' && $topCard['type'] === 'number' && $card['value'] === $topCard['value']) {
            return true;
        }

        // Gleicher Sonderkartentyp (z.B. Hit2 auf Hit2, Discard All auf Discard All, Reverse auf Reverse)
        if ($card['type'] !== 'number' && $card['type'] === $topCard['type']) {
            return true;
        }

        return false;
    }

    public function applyCard(
        array $card,
        array &$roomState,
        array &$players,
        int $activePlayerIndex,
        ?string $chosenColor = null
    ): array {
        $playerCount = count($players);
        $direction = (int)($roomState['direction'] ?? 1);
        $skipCount = 1;
        $text = "Karte gelegt: {$card['label']}";

        $roomState['active_color'] = null;

        switch ($card['type']) {
            case 'reverse':
                if ($playerCount === 2) {
                    $skipCount = 2;
                    $text = "Richtungswechsel! Der Mitspieler muss aussetzen.";
                } else {
                    $direction *= -1;
                    $roomState['direction'] = $direction;
                    $text = "Richtungswechsel! Die Spielrichtung dreht sich um.";
                }
                break;

            case 'skip':
                $skipCount = 2;
                $text = "Aussetzen! Der nächste Spieler wird übersprungen.";
                break;

            case 'hit2':
                // Launcher Hits erhöhen (2x drücken)
                $roomState['pending_draws'] = ((int)($roomState['pending_draws'] ?? 0)) + 2;
                $skipCount = 1;
                $text = "Hit 2! Der nächste Spieler muss den Launcher 2x betätigen!";
                break;

            case 'discard_all':
                // Alle weiteren Karten dieser Farbe aus der eigenen Hand abwerfen
                $currentColor = $card['color'];
                $activePlayer = &$players[$activePlayerIndex];
                $handCards = $activePlayer['sheet']['cards'] ?? [];
                $keptCards = [];
                $discardedCount = 0;

                foreach ($handCards as $hc) {
                    // Die gespielte Karte wurde bereits entfernt, hier die restlichen gleichen Farben abwerfen
                    if ($hc['color'] === $currentColor) {
                        $discardedCount++;
                        $roomState['discard_pile'][] = $hc;
                    } else {
                        $keptCards[] = $hc;
                    }
                }
                $activePlayer['sheet']['cards'] = $keptCards;
                $totalDiscarded = $discardedCount + 1;
                $text = "Discard All! {$totalDiscarded}x {$currentColor} abgelegt!";
                break;

            case 'wild':
                if ($chosenColor && in_array($chosenColor, DeckFactory::COLORS_UNO, true)) {
                    $roomState['active_color'] = $chosenColor;
                    $colorNames = ['red' => 'Rot', 'yellow' => 'Gelb', 'green' => 'Grün', 'blue' => 'Blau'];
                    $name = $colorNames[$chosenColor] ?? $chosenColor;
                    $text = "Farbenwahl: Es wird {$name} gewünscht.";
                }
                break;

            case 'extreme_hit':
                // Extreme Hit: 1x Extreme Launcher Drücken für den nächsten Spieler + Farbwahl
                $roomState['pending_draws'] = ((int)($roomState['pending_draws'] ?? 0)) + 1;
                if ($chosenColor && in_array($chosenColor, DeckFactory::COLORS_UNO, true)) {
                    $roomState['active_color'] = $chosenColor;
                    $colorNames = ['red' => 'Rot', 'yellow' => 'Gelb', 'green' => 'Grün', 'blue' => 'Blau'];
                    $name = $colorNames[$chosenColor] ?? $chosenColor;
                    $text = "Extreme Hit! Wunschfarbe {$name} und Launcher-Alarm!";
                } else {
                    $text = "Extreme Hit! Nächster Spieler muss den Launcher betätigen!";
                }
                break;
        }

        $nextIndex = ($activePlayerIndex + ($direction * $skipCount)) % $playerCount;
        if ($nextIndex < 0) {
            $nextIndex += $playerCount;
        }

        return [
            'next_player_index' => $nextIndex,
            'message' => $text,
            'event_type' => 'card_played',
        ];
    }

    public function getInitialHandSize(): int
    {
        return 7;
    }

    public function allowsColorChoice(array $card): bool
    {
        return in_array($card['type'], ['wild', 'extreme_hit'], true);
    }

    public function getValidColors(): array
    {
        return DeckFactory::COLORS_UNO;
    }
}
