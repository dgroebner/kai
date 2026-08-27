<?php

namespace Kai\Tools\PVCharge;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Lesende Datenbankzugriffe auf die Live- und Telemetriedaten der PV-Anlage
 * (`pv_live`, `pv_telemetry`).
 */
class PvTelemetryRepository
{
    /** Standard-Zeitfilter für die Telemetrie-Historie. */
    public const string DEFAULT_FILTER = 'tag';

    /**
     * Whitelist der erlaubten Zeitfilter. Die Bedingungen sind fest im Code
     * hinterlegt; Benutzereingaben werden ausschließlich als Schlüssel verwendet.
     */
    private const array FILTER_CONDITIONS = [
        'tag' => 'last_update >= CURDATE()',
        'woche' => 'last_update >= NOW() - INTERVAL 7 DAY',
        'monat' => 'last_update >= NOW() - INTERVAL 30 DAY',
    ];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * @return string[] Alle auswählbaren Zeitfilter
     */
    public static function availableFilters(): array
    {
        return array_keys(self::FILTER_CONDITIONS);
    }

    /**
     * Liefert den letzten Live-Datensatz der Anlage.
     */
    public function getLatestLiveData(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM pv_live ORDER BY id DESC LIMIT 1");

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Höchste heute gemessene PV-Leistung in Watt.
     */
    public function getTodayPeakPowerW(): int
    {
        $stmt = $this->pdo->query("SELECT MAX(pv_power_w) FROM pv_telemetry WHERE last_update >= CURDATE()");

        return (int)$stmt->fetchColumn();
    }

    /**
     * Summiert die heutigen Netzbezugs- und Einspeise-Messwerte.
     *
     * @return array{sum_import_w: float, sum_export_w: float}
     */
    public function getTodayGridTotals(): array
    {
        $stmt = $this->pdo->query("
            SELECT
                SUM(CASE WHEN grid_total_w > 0 THEN grid_total_w ELSE 0 END) AS sum_import_w,
                SUM(CASE WHEN grid_total_w < 0 THEN ABS(grid_total_w) ELSE 0 END) AS sum_export_w
            FROM pv_telemetry
            WHERE last_update >= CURDATE()
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'sum_import_w' => (float)($row['sum_import_w'] ?? 0),
            'sum_export_w' => (float)($row['sum_export_w'] ?? 0),
        ];
    }

    /**
     * Heutiger PV-Leistungsverlauf für den Prognose-Ist-Vergleich.
     */
    public function getTodayPowerCurve(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT last_update, pv_power_w
            FROM pv_telemetry
            WHERE DATE(last_update) = CURDATE()
            ORDER BY last_update ASC
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Zählt die Telemetrie-Datensätze im gewählten Zeitfenster.
     */
    public function countRecords(string $filter): int
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM pv_telemetry WHERE " . $this->conditionFor($filter)
        );

        return (int)$stmt->fetchColumn();
    }

    /**
     * Löst einen Filterschlüssel in die fest hinterlegte SQL-Bedingung auf.
     * Unbekannte Schlüssel fallen auf den Standardfilter zurück.
     */
    private function conditionFor(string $filter): string
    {
        return self::FILTER_CONDITIONS[self::normalizeFilter($filter)];
    }

    /**
     * Reduziert eine beliebige Benutzereingabe auf einen erlaubten Zeitfilter.
     */
    public static function normalizeFilter(mixed $filter): string
    {
        $filter = is_string($filter) ? $filter : '';

        return isset(self::FILTER_CONDITIONS[$filter]) ? $filter : self::DEFAULT_FILTER;
    }

    /**
     * Lädt eine Seite der Telemetrie-Historie (neueste zuerst).
     */
    public function getRecords(string $filter, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM pv_telemetry
            WHERE " . $this->conditionFor($filter) . "
            ORDER BY last_update DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lädt die Verlaufsdaten für das Telemetrie-Chart (chronologisch aufsteigend).
     */
    public function getChartRows(string $filter): array
    {
        $stmt = $this->pdo->prepare("
            SELECT last_update, pv_power_w, house_load_w, grid_total_w, battery_soc_pct, battery_power_w
            FROM pv_telemetry
            WHERE " . $this->conditionFor($filter) . "
            ORDER BY last_update ASC
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
