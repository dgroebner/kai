<?php

namespace Kai\Tools\Car;

/**
 * Generator für standardisierte Deep Links zu "A Better Routeplanner" (ABRP).
 *
 * Ermöglicht die nahtlose Übergabe geplanter Routen an die mobile ABRP-App oder Apple CarPlay / Android Auto.
 * Wenn departure_soc nicht explizit gesetzt wird, liest die ABRP-App den aktuellen Live-SoC via TRONITY aus.
 */
class AbrpDeepLinkBuilder
{
    public const DEFAULT_VEHICLE_MODEL = 'volkswagen:id.buzz:22:77:rwd';

    /**
     * Erstellt einen ABRP-Routenlink.
     *
     * @param float $startLat Start-Breitengrad
     * @param float $startLon Start-Längengrad
     * @param float $destLat Ziel-Breitengrad
     * @param float $destLon Ziel-Längengrad
     * @param int $targetSoc Gewünschter Ankunfts-SoC in % (Standard: 10)
     * @param int|null $departureSoc Optionaler fixer Abfahrts-SoC in % (null = Live-Telemetrie aus Tronity)
     * @param string $vehicleModel Fahrzeug-Modellcode in ABRP
     * @return string Vollständige Deep-Link-URL
     */
    public static function buildDeepLink(
        float $startLat,
        float $startLon,
        float $destLat,
        float $destLon,
        int $targetSoc = 10,
        ?int $departureSoc = null,
        string $vehicleModel = self::DEFAULT_VEHICLE_MODEL
    ): string {
        $destinations = [
            ['lat' => round($startLat, 6), 'lon' => round($startLon, 6)],
            ['lat' => round($destLat, 6), 'lon' => round($destLon, 6)],
        ];

        $params = [
            'destinations' => json_encode($destinations),
            'car_model' => $vehicleModel,
            'arrival_soc' => $targetSoc,
        ];

        if ($departureSoc !== null && $departureSoc > 0 && $departureSoc <= 100) {
            $params['departure_soc'] = $departureSoc;
        }

        return 'https://abetterrouteplanner.com/?' . http_build_query($params);
    }
}
