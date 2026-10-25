<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\CardGames\Rules;

interface RuleEngineInterface
{
    /**
     * Prüft, ob eine Karte auf die aktuelle Tischkarte gelegt werden darf.
     */
    public function canPlay(array $card, array $topCard, ?string $activeColor, int $pendingDraws, array $playerHand): bool;

    /**
     * Führt die Effekte einer gespielten Karte auf den Spielzustand aus.
     *
     * @param array $card Die gespielte Karte
     * @param array &$roomState Referenz auf den Tisch-Zustand
     * @param array &$players Referenz auf die Spielerliste
     * @param int $activePlayerIndex Index des Spielers am Zug
     * @param string|null $chosenColor Gewählte Farbe bei Farbwunschkarten
     * @return array Ereignis-Zusammenfassung für den Event-Ticker
     */
    public function applyCard(
        array $card,
        array &$roomState,
        array &$players,
        int $activePlayerIndex,
        ?string $chosenColor = null
    ): array;

    /**
     * Anzahl der Startkarten pro Spieler.
     */
    public function getInitialHandSize(): int;

    /**
     * Ob diese Karte einen Farbwunsch erfordert.
     */
    public function allowsColorChoice(array $card): bool;

    /**
     * Erlaubte Farbwünsche für dieses Spiel.
     */
    public function getValidColors(): array;
}
