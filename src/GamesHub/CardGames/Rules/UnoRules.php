<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\CardGames\Rules;

use Kai\Tools\GamesHub\CardGames\Engine\DeckFactory;

class UnoRules implements RuleEngineInterface
{
    public function canPlay(array $card, array $topCard, ?string $activeColor, int $pendingDraws, array $playerHand): bool
    {
        // Wenn Strafkarten ausstehen, kann man mit passender Karte kontern (Stacking: Draw 2 auf Draw 2, Wild 4 auf Wild 4)
        if ($pendingDraws > 0) {
            if ($topCard['type'] === 'draw2' && $card['type'] === 'draw2') {
                return true;
            }
            if ($topCard['type'] === 'wild_draw4' && $card['type'] === 'wild_draw4') {
                return true;
            }
            return false;
        }

        // Farbwunsch aktiv?
        if (!empty($activeColor)) {
            return $card['color'] === $activeColor || $card['color'] === 'wild';
        }

        // Wild ist immer erlaubt
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

        // Gleicher Sonderkartentyp (z.B. Skip auf Skip oder Reverse auf Reverse)
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
        $skipCount = 1; // Normalerweise rückt der Zug um 1 Spieler vor
        $text = "Karte gelegt: {$card['label']}";

        // Farbwunsch zurücksetzen oder neu setzen
        $roomState['active_color'] = null;

        switch ($card['type']) {
            case 'reverse':
                if ($playerCount === 2) {
                    // Bei 2 Spielern wirkt Richtungswechsel wie Aussetzen
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

            case 'draw2':
                $roomState['pending_draws'] = ((int)($roomState['pending_draws'] ?? 0)) + 2;
                $skipCount = 1;
                $text = "+2 gelegt! Nächster Spieler muss 2 ziehen oder weitergeben.";
                break;

            case 'wild':
                if ($chosenColor && in_array($chosenColor, DeckFactory::COLORS_UNO, true)) {
                    $roomState['active_color'] = $chosenColor;
                    $colorNames = ['red' => 'Rot', 'yellow' => 'Gelb', 'green' => 'Grün', 'blue' => 'Blau'];
                    $name = $colorNames[$chosenColor] ?? $chosenColor;
                    $text = "Farbenwahl: Es wird {$name} gewünscht.";
                }
                break;

            case 'wild_draw4':
                $roomState['pending_draws'] = ((int)($roomState['pending_draws'] ?? 0)) + 4;
                if ($chosenColor && in_array($chosenColor, DeckFactory::COLORS_UNO, true)) {
                    $roomState['active_color'] = $chosenColor;
                    $colorNames = ['red' => 'Rot', 'yellow' => 'Gelb', 'green' => 'Grün', 'blue' => 'Blau'];
                    $name = $colorNames[$chosenColor] ?? $chosenColor;
                    $text = "+4 gelegt! Wunschfarbe ist {$name}.";
                } else {
                    $text = "+4 gelegt! Nächster Spieler muss 4 Karten ziehen.";
                }
                break;
        }

        // Nächsten aktiven Spieler ermitteln
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
        return in_array($card['type'], ['wild', 'wild_draw4'], true);
    }

    public function getValidColors(): array
    {
        return DeckFactory::COLORS_UNO;
    }
}
