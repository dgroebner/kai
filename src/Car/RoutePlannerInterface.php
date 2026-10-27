<?php

namespace Kai\Tools\Car;

/**
 * Interface für austauschbare Routen- und Ladeplanungs-Algorithmen (Strategy Pattern).
 */
interface RoutePlannerInterface
{
    /**
     * Berechnet die Route, den voraussichtlichen Verbrauch und Ladeanforderungen.
     *
     * @param float $startLat Startkoordinate Breitengrad
     * @param float $startLon Startkoordinate Längengrad
     * @param float $destLat Zielkoordinate Breitengrad
     * @param float $destLon Zielkoordinate Längengrad
     * @param int $targetSoc Gewünschter Mindest-SoC bei Ankunft am Ziel (Standard: 10%)
     * @param int $departureSoc Geplanter Start-SoC (Standard: 80%)
     * @param string|null $destAddress Zieladresse im Klartext
     * @param string|null $destTitle Titel/Name des Reiseziels
     * @param string|null $startAddress Startadresse im Klartext
     * @return RoutePlanResult
     */
    public function planRoute(
        float $startLat,
        float $startLon,
        float $destLat,
        float $destLon,
        int $targetSoc = 10,
        int $departureSoc = 80,
        ?string $destAddress = null,
        ?string $destTitle = null,
        ?string $startAddress = null
    ): RoutePlanResult;
}
