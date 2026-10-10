<?php

namespace Kai\Tools\Car;

use DateTime;
use DateTimeZone;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use Throwable;

/**
 * Vergleicht aktive und kürzlich durchgeführte Reisen mit realen Fahrzeugtelemetriedaten von TRONITY.
 *
 * Ermittelt Geofence-Ereignisse (Verlassen von Startort, Erreichen von Ziel- und Rückkehrort),
 * berechnet den tatsächlichen Verbrauch (kWh) via SoC-Differenz inkl. Schnellladungen unterwegs
 * sowie die tatsächlich gefahrene Strecke (km) über Odometer-Deltas.
 */
class TripTelemetryTracker
{
    public const BATTERY_USABLE_KWH = 77.0; // VW ID.Buzz 77 kWh Netto-Batteriekapazität
    public const DEFAULT_GEOFENCE_RADIUS_M = 500.0; // 500 Meter Umkreis für Start- und Rückkehrort
    public const DEFAULT_DESTINATION_GEOFENCE_RADIUS_M = 1500.0; // 1,5 km Umkreis für Zielort (erhöhte Toleranz für Parkplätze & ländliche Adressen)

    private PDO $pdo;
    private Logger $logger;

    public function __construct(?PDO $pdo = null, ?Logger $logger = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->logger = $logger ?? new Logger(14);
    }

    /**
     * Führt die Telemetrie-Auswertung für alle aktiven oder kürzlich beendeten Reisen durch.
     * Wird direkt im TRONITY-Sync bzw. Webhook aufgerufen.
     *
     * @return int Anzahl aktualisierter Reisen
     */
    public function trackTrips(): int
    {
        // Alle Reisen prüfen, die 'aktiv' oder 'geplant' sind (oder innerhalb der letzten 48 Stunden lagen)
        $stmt = $this->pdo->query("
            SELECT *
            FROM car_trips
            WHERE status IN ('aktiv', 'geplant', 'abgeschlossen')
              AND departure_time <= NOW()
              AND departure_time >= DATE_SUB(NOW(), INTERVAL 5 DAY)
            ORDER BY departure_time ASC
        ");

        $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($trips)) {
            return 0;
        }

        $updatedCount = 0;
        foreach ($trips as $trip) {
            try {
                if ($this->processTripTelemetry($trip)) {
                    $updatedCount++;
                }
            } catch (Throwable $e) {
                $this->logger->warn("TripTelemetryTracker: Fehler bei Reise #{$trip['id']}: " . $e->getMessage());
            }
        }

        return $updatedCount;
    }

    /**
     * Wertet Telemetriedaten für eine einzelne Reise aus.
     */
    public function processTripTelemetry(array $trip): bool
    {
        $tripId = (int)$trip['id'];
        $depTime = (string)$trip['departure_time'];
        $retTime = !empty($trip['return_time']) ? (string)$trip['return_time'] : null;
        $isRoundTrip = !empty($trip['is_round_trip']);

        $startLat = (float)$trip['start_lat'];
        $startLon = (float)$trip['start_lon'];
        $destLat = (float)$trip['destination_lat'];
        $destLon = (float)$trip['destination_lon'];

        if ($startLat == 0.0 || $destLat == 0.0) {
            return false;
        }

        // Telemetrie-Messpunkte für das relevante Zeitfenster abrufen
        // Fenster: ab 30 Minuten vor geplanter Abfahrt bis (Rückkehrzeit + 2 Stunden bzw. jetzt)
        $startWindow = date('Y-m-d H:i:s', strtotime($depTime) - 1800);
        $endWindowTs = max($retTime ? strtotime($retTime) + 7200 : 0, time() + 1800);
        $endWindow = date('Y-m-d H:i:s', $endWindowTs);

        // Telemetrielog speichert Zeitstempel in UTC
        $startUtc = $this->toUtcString($startWindow);
        $endUtc = $this->toUtcString($endWindow);

        $stmt = $this->pdo->prepare("
            SELECT car_captured_at, soc_percent, mileage_km, latitude, longitude
            FROM vehicle_telemetry_log
            WHERE car_captured_at BETWEEN :start_utc AND :end_utc
              AND latitude IS NOT NULL AND longitude IS NOT NULL
            ORDER BY car_captured_at ASC
        ");
        $stmt->execute([':start_utc' => $startUtc, ':end_utc' => $endUtc]);
        $points = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Initialen Messpunkt (vor oder zur Abfahrt) ermitteln, falls Fensteranfang keinen erfasst hat
        $stmtPrev = $this->pdo->prepare("
            SELECT car_captured_at, soc_percent, mileage_km, latitude, longitude
            FROM vehicle_telemetry_log
            WHERE car_captured_at <= :dep_utc
              AND latitude IS NOT NULL AND longitude IS NOT NULL
            ORDER BY car_captured_at DESC
            LIMIT 1
        ");
        $stmtPrev->execute([':dep_utc' => $this->toUtcString($depTime)]);
        $prevPoint = $stmtPrev->fetch(PDO::FETCH_ASSOC);
        if ($prevPoint) {
            if (empty($points) || $points[0]['car_captured_at'] !== $prevPoint['car_captured_at']) {
                array_unshift($points, $prevPoint);
            }
        }

        // Aktuellsten Messpunkt anhängen, falls noch nicht enthalten
        $stmtLatest = $this->pdo->prepare("
            SELECT car_captured_at, soc_percent, mileage_km, latitude, longitude
            FROM vehicle_telemetry_log
            WHERE car_captured_at >= :dep_utc
              AND latitude IS NOT NULL AND longitude IS NOT NULL
            ORDER BY car_captured_at DESC
            LIMIT 1
        ");
        $stmtLatest->execute([':dep_utc' => $this->toUtcString($depTime)]);
        $latestPoint = $stmtLatest->fetch(PDO::FETCH_ASSOC);
        if ($latestPoint) {
            $lastInList = end($points);
            if (!$lastInList || $lastInList['car_captured_at'] !== $latestPoint['car_captured_at']) {
                $points[] = $latestPoint;
            }
        }

        if (empty($points)) {
            return false;
        }

        // 1. Startpunkt bestimmen: Letzter Punkt im Start-Geofence vor dem ersten Verlassen
        $startPoint = null;
        $hasLeftStart = false;

        foreach ($points as $p) {
            $distToStart = $this->distanceMeters($startLat, $startLon, (float)$p['latitude'], (float)$p['longitude']);
            if ($distToStart <= self::DEFAULT_GEOFENCE_RADIUS_M && !$hasLeftStart) {
                $startPoint = $p;
            } elseif ($distToStart > self::DEFAULT_GEOFENCE_RADIUS_M) {
                $hasLeftStart = true;
            }
        }

        if (!$startPoint && !empty($points)) {
            $startPoint = $points[0];
        }

        $totalActualDistance = null;
        $totalActualConsumption = null;
        $finalArrivalSoc = null;
        $destPoint = null;
        $destIndex = null;
        $returnPoint = null;

        if ($hasLeftStart && $startPoint !== null) {
            // 2. Zielpunkt bestimmen (erhöhter Ziel-Radius für Parkplätze & ländliche Adressen)
            $minDestDist = PHP_FLOAT_MAX;
            $bestDestIdx = null;

            foreach ($points as $idx => $p) {
                if ($p['car_captured_at'] <= $startPoint['car_captured_at']) {
                    continue;
                }
                $distToDest = $this->distanceMeters($destLat, $destLon, (float)$p['latitude'], (float)$p['longitude']);
                if ($distToDest <= self::DEFAULT_DESTINATION_GEOFENCE_RADIUS_M) {
                    $destPoint = $p;
                    $destIndex = $idx;
                    break;
                }
                if ($distToDest < $minDestDist) {
                    $minDestDist = $distToDest;
                    $bestDestIdx = $idx;
                }
            }

            // Fallback für Rundreisen: Wendepunkt mit geringster Zieldistanz (mind. 1,5 km von Start entfernt)
            if (!$destPoint && $isRoundTrip && $bestDestIdx !== null) {
                $candidate = $points[$bestDestIdx];
                $distStartCandidate = $this->distanceMeters($startLat, $startLon, (float)$candidate['latitude'], (float)$candidate['longitude']);
                if ($distStartCandidate >= 1500.0) {
                    $destPoint = $candidate;
                    $destIndex = $bestDestIdx;
                }
            }

            if ($destPoint) {
                // Ladevorgänge unterwegs zwischen Start und Ziel ermitteln
                $outboundChargesKwh = $this->sumChargesKwhBetween(
                    $startPoint['car_captured_at'],
                    $destPoint['car_captured_at']
                );

                // Hinfahrt berechnen
                $outboundSocDelta = (int)$startPoint['soc_percent'] - (int)$destPoint['soc_percent'];
                $outboundConsumptionKwh = max(0.0, round(($outboundSocDelta / 100.0) * self::BATTERY_USABLE_KWH + $outboundChargesKwh, 2));

                $outboundDistanceKm = null;
                if (!empty($destPoint['mileage_km']) && !empty($startPoint['mileage_km']) && (int)$destPoint['mileage_km'] >= (int)$startPoint['mileage_km']) {
                    $outboundDistanceKm = round((int)$destPoint['mileage_km'] - (int)$startPoint['mileage_km'], 1);
                }

                $totalActualDistance = $outboundDistanceKm;
                $totalActualConsumption = $outboundConsumptionKwh;
                $finalArrivalSoc = (int)$destPoint['soc_percent'];

                // 3. Bei Rundreise: Rückkehrpunkt (wieder am Startort) suchen
                if ($isRoundTrip && $destIndex !== null) {
                    for ($i = $destIndex + 1; $i < count($points); $i++) {
                        $p = $points[$i];
                        $distToStart = $this->distanceMeters($startLat, $startLon, (float)$p['latitude'], (float)$p['longitude']);
                        if ($distToStart <= self::DEFAULT_GEOFENCE_RADIUS_M) {
                            $returnPoint = $p;
                            break;
                        }
                    }

                    if ($returnPoint) {
                        $returnChargesKwh = $this->sumChargesKwhBetween(
                            $destPoint['car_captured_at'],
                            $returnPoint['car_captured_at']
                        );

                        $returnSocDelta = (int)$destPoint['soc_percent'] - (int)$returnPoint['soc_percent'];
                        $returnConsumptionKwh = max(0.0, round(($returnSocDelta / 100.0) * self::BATTERY_USABLE_KWH + $returnChargesKwh, 2));

                        $totalActualConsumption = round($outboundConsumptionKwh + $returnConsumptionKwh, 2);
                        $finalArrivalSoc = (int)$returnPoint['soc_percent'];

                        if (!empty($returnPoint['mileage_km']) && !empty($startPoint['mileage_km']) && (int)$returnPoint['mileage_km'] >= (int)$startPoint['mileage_km']) {
                            $totalActualDistance = round((int)$returnPoint['mileage_km'] - (int)$startPoint['mileage_km'], 1);
                        }
                    }
                }
            }
        } elseif ($isRoundTrip && $startPoint !== null && count($points) >= 2) {
            // Fallback: Keine GPS-Punkte außerhalb des Start-Geofences aufgezeichnet (z. B. nur vor und nach der Fahrt gepollt),
            // aber Kilometerstand ist zwischenzeitlich angestiegen und Fahrzeug steht wieder zuhause.
            $lastPoint = end($points);
            $distLastToStart = $this->distanceMeters($startLat, $startLon, (float)$lastPoint['latitude'], (float)$lastPoint['longitude']);
            $odometerDelta = (!empty($lastPoint['mileage_km']) && !empty($startPoint['mileage_km']))
                ? ((int)$lastPoint['mileage_km'] - (int)$startPoint['mileage_km'])
                : 0;

            if ($distLastToStart <= self::DEFAULT_GEOFENCE_RADIUS_M && $odometerDelta >= 5) {
                $returnPoint = $lastPoint;
                $enRouteChargesKwh = $this->sumChargesKwhBetween(
                    $startPoint['car_captured_at'],
                    $returnPoint['car_captured_at']
                );

                $totalActualDistance = round((float)$odometerDelta, 1);
                $socDelta = (int)$startPoint['soc_percent'] - (int)$returnPoint['soc_percent'];
                $totalActualConsumption = max(0.0, round(($socDelta / 100.0) * self::BATTERY_USABLE_KWH + $enRouteChargesKwh, 2));
                $finalArrivalSoc = (int)$returnPoint['soc_percent'];
            }
        }

        // Status-Ermittlung: Rundreise beendet sobald am Startort zurück, oder Zeit abgelaufen
        $targetStatus = (string)$trip['status'];
        $nowTs = time();

        if ($isRoundTrip && $returnPoint !== null) {
            $targetStatus = 'abgeschlossen';
        } elseif (!$isRoundTrip && $destPoint !== null && ($retTime === null || strtotime($retTime) <= $nowTs)) {
            $targetStatus = 'abgeschlossen';
        } elseif (!empty($retTime) && strtotime($retTime) <= $nowTs && $targetStatus === 'aktiv') {
            $targetStatus = 'abgeschlossen';
        }

        // Falls sich signifikante Änderungen ergeben haben, in car_trips speichern
        $currentDist = $trip['actual_distance_km'] !== null ? (float)$trip['actual_distance_km'] : null;
        $currentKwh = $trip['actual_consumption_kwh'] !== null ? (float)$trip['actual_consumption_kwh'] : null;
        $currentArrivalSoc = $trip['actual_arrival_soc'] !== null ? (int)$trip['actual_arrival_soc'] : null;

        $hasStatusChanged = ($targetStatus !== (string)$trip['status']);
        $hasMetricsChanged = (
            ($totalActualDistance !== null && $currentDist !== $totalActualDistance) ||
            ($totalActualConsumption !== null && $currentKwh !== $totalActualConsumption) ||
            ($finalArrivalSoc !== null && $currentArrivalSoc !== $finalArrivalSoc)
        );

        if ($hasStatusChanged || $hasMetricsChanged) {
            $upd = $this->pdo->prepare("
                UPDATE car_trips
                SET actual_distance_km = COALESCE(:dist, actual_distance_km),
                    actual_consumption_kwh = COALESCE(:kwh, actual_consumption_kwh),
                    actual_arrival_soc = COALESCE(:soc, actual_arrival_soc),
                    status = :status,
                    telemetry_matched_at = NOW()
                WHERE id = :id
            ");

            $upd->execute([
                ':dist' => $totalActualDistance,
                ':kwh' => $totalActualConsumption,
                ':soc' => $finalArrivalSoc,
                ':status' => $targetStatus,
                ':id' => $tripId,
            ]);

            $this->logger->info("TripTelemetryTracker: Reise #{$tripId} '{$trip['title']}' aktualisiert: Status '{$targetStatus}', Ist-Distanz {$totalActualDistance} km, Ist-Verbrauch {$totalActualConsumption} kWh, Ankunfts-SoC {$finalArrivalSoc}%.");
            return true;
        }

        return false;
    }

    /**
     * Summiert Ladevorgänge aus vehicle_charges im gegebenen UTC-Zeitfenster.
     */
    private function sumChargesKwhBetween(string $startUtc, string $endUtc): float
    {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(charged_net_kwh), 0)
            FROM vehicle_charges
            WHERE start_time >= :start_utc AND start_time <= :end_utc
        ");
        $stmt->execute([':start_utc' => $startUtc, ':end_utc' => $endUtc]);

        return (float)$stmt->fetchColumn();
    }

    /**
     * Berechnet die Distanz zwischen zwei GPS-Koordinaten in Metern (Haversine).
     */
    private function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusM = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusM * $c;
    }

    private function toUtcString(string $localTimeStr): string
    {
        try {
            $dt = new DateTime($localTimeStr, new DateTimeZone('Europe/Berlin'));
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return $localTimeStr;
        }
    }
}
