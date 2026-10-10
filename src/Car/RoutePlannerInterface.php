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
     * @return RoutePlanResult
     */
    public function planRoute(
        float $startLat,
        float $startLon,
        float $destLat,
        float $destLon,
        int $targetSoc = 10,
        int $departureSoc = 80
    ): RoutePlanResult;
}
