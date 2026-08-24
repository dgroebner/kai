<?php

namespace Kai\Tools\Car;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Datenbankzugriffe für das Fahrzeug-Dashboard (`public/car/`).
 *
 * Kapselt die Lesezugriffe auf `vehicle_state` und `vehicle_telemetry_log`
 * sowie die manuelle Reichweitenkorrektur aus der Telemetrie-Tabelle.
 * Alle Zeitstempel werden in UTC erwartet und zurückgegeben.
 */
class VehicleDashboardRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Aktuellster Fahrzeugstatus für die Live-Kacheln.
     */
    public function getLatestState(): ?array
    {
        $stmt = $this->pdo->query("
            SELECT *
            FROM vehicle_state
            ORDER BY updated_at DESC
            LIMIT 1
        ");

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Vollständige Zeitreihe eines Zeitraums für den SoC-Verlauf (Chart).
     */
    public function getTelemetryHistory(string $startUtc, string $endUtc): array
    {
        $stmt = $this->pdo->prepare("
            SELECT car_captured_at, soc_percent, range_km, charge_power_kw, outdoor_temp_c
            FROM vehicle_telemetry_log
            WHERE car_captured_at BETWEEN :start AND :end
            ORDER BY car_captured_at
        ");
        $stmt->execute([':start' => $startUtc, ':end' => $endUtc]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Anzahl der Telemetrie-Einträge eines Zeitraums (für die Paginierung).
     */
    public function countTelemetryEntries(string $startUtc, string $endUtc): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM vehicle_telemetry_log
            WHERE car_captured_at BETWEEN :start AND :end
        ");
        $stmt->execute([':start' => $startUtc, ':end' => $endUtc]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Eine Seite der Telemetrie-Tabelle, absteigend nach Erfassungszeitpunkt.
     */
    public function getTelemetryPage(string $startUtc, string $endUtc, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare("
            SELECT car_captured_at, soc_percent, range_km, mileage_km, charge_power_kw, outdoor_temp_c
            FROM vehicle_telemetry_log
            WHERE car_captured_at BETWEEN :start AND :end
            ORDER BY car_captured_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':start', $startUtc);
        $stmt->bindValue(':end', $endUtc);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Korrigiert die Reichweite eines Telemetrie-Eintrags manuell.
     *
     * Der Live-Status wird nur dann mitgezogen, wenn er denselben
     * Erfassungszeitpunkt trägt (also der korrigierte Eintrag der aktuellste ist).
     * `null` löscht den Wert im Log, lässt den Live-Status aber unverändert.
     */
    public function updateRange(string $vin, string $carCapturedAt, ?int $rangeKm): void
    {
        $params = [
            ':range_km' => $rangeKm,
            ':vin' => $vin,
            ':car_captured_at' => $carCapturedAt,
        ];

        $stmtLog = $this->pdo->prepare("
            UPDATE vehicle_telemetry_log
            SET range_km = :range_km
            WHERE vin = :vin AND car_captured_at = :car_captured_at
        ");
        $stmtLog->execute($params);

        $stmtState = $this->pdo->prepare("
            UPDATE vehicle_state
            SET range_km = COALESCE(:range_km, range_km)
            WHERE vin = :vin AND car_captured_at = :car_captured_at
        ");
        $stmtState->execute($params);
    }
}
