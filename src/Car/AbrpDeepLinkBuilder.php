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
     * @param string|null $destAddress Zieladresse im Klartext
     * @param string|null $destName Name/Titel des Zielorts
     * @param string|null $startAddress Startadresse im Klartext
     * @return string Vollständige Deep-Link-URL
     */
    public static function buildDeepLink(
        float $startLat,
        float $startLon,
        float $destLat,
        float $destLon,
        int $targetSoc = 10,
        ?int $departureSoc = null,
        string $vehicleModel = self::DEFAULT_VEHICLE_MODEL,
        ?string $destAddress = null,
        ?string $destName = null,
        ?string $startAddress = null
    ): string {
        $start = [];
        if ($startLat != 0.0 && $startLon != 0.0) {
            $start['lat'] = round($startLat, 6);
            $start['lon'] = round($startLon, 6);
        }
        if (!empty($startAddress) && strtolower(trim($startAddress)) !== 'zuhause') {
            $start['address'] = trim($startAddress);
        } elseif (!empty($startAddress)) {
            $start['name'] = trim($startAddress);
        }

        $dest = [];
        if ($destLat != 0.0 && $destLon != 0.0) {
            $dest['lat'] = round($destLat, 6);
            $dest['lon'] = round($destLon, 6);
        }
        if (!empty($destName)) {
            $dest['name'] = trim($destName);
        }
        if (!empty($destAddress)) {
            $dest['address'] = trim($destAddress);
        }

        // Fallback für den unwahrscheinlichen Fall, dass weder Coords noch Adressdaten vorliegen
        if (empty($dest)) {
            $dest = ['lat' => 0.0, 'lon' => 0.0];
        }

        $destinations = [$start, $dest];

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
