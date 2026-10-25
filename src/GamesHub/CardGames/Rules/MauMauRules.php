<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\CardGames\Rules;

use Kai\Tools\GamesHub\CardGames\Engine\DeckFactory;

class MauMauRules implements RuleEngineInterface
{
    public function canPlay(array $card, array $topCard, ?string $activeColor, int $pendingDraws, array $playerHand): bool
    {
        // Wenn Strafkarten (durch 7er) anstehen, kann man nur mit einer weiteren 7 kontern
        if ($pendingDraws > 0) {
            return $card['value'] === 7;
        }

        // Farbwunsch durch Bube aktiv?
        if (!empty($activeColor)) {
            // Auf Farbwunsch darf nur die gewünschte Farbe gelegt werden (Bube auf Bube nicht erlaubt)
            return $card['color'] === $activeColor && $card['type'] !== 'jack';
        }

        // Bube (Jack) darf auf fast alles gelegt werden (außer Bube auf Bube)
        if ($card['type'] === 'jack') {
            return $topCard['type'] !== 'jack';
        }

        // Gleiche Farbe
        if ($card['color'] === $topCard['color']) {
            return true;
        }

        // Gleicher Wert (z.B. 9 auf 9, Ass auf Ass, Dame auf Dame)
        if ($card['value'] === $topCard['value']) {
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
        $repeatTurn = false;
        $text = "Karte gelegt: {$card['label']}";

        $roomState['active_color'] = null;

        // 7 = 2 Ziehen
        if ($card['value'] === 7) {
            $roomState['pending_draws'] = ((int)($roomState['pending_draws'] ?? 0)) + 2;
            $text = "7 gelegt! Der nächste Spieler muss 2 Karten ziehen oder mit einer 7 kontern.";
        }
        // Ass = Aussetzen
        elseif ($card['value'] === 14 || $card['type'] === 'skip') {
            $skipCount = 2;
            $text = "Ass gelegt! Der nächste Spieler muss aussetzen.";
        }
        // Bube = Farbwunsch
        elseif ($card['type'] === 'jack') {
            if ($chosenColor && in_array($chosenColor, DeckFactory::COLORS_MAUMAU, true)) {
                $roomState['active_color'] = $chosenColor;
                $colorNames = [
                    'hearts' => 'Herz ♥',
                    'diamonds' => 'Karo ♦',
                    'spades' => 'Pik ♠',
                    'clubs' => 'Kreuz ♣'
                ];
                $name = $colorNames[$chosenColor] ?? $chosenColor;
                $text = "Bube gelegt! Farbwunsch: {$name}.";
            }
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
        return 5;
    }

    public function allowsColorChoice(array $card): bool
    {
        return $card['type'] === 'jack';
    }

    public function getValidColors(): array
    {
        return DeckFactory::COLORS_MAUMAU;
    }
}
