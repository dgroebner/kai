<?php

namespace Kai\Tools\PVCharge;

use DateTime;
use Kai\Tools\Shared\Db\Database;
use PDO;
use Throwable;

/**
 * Datenbankzugriffe auf die Ertragsprognosen der PV-Anlage
 * (`pv_forecast_daily`, `pv_forecast_hourly`).
 */
class PvForecastRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Lädt die Tagesprognosen der letzten drei Tage sowie der kommenden Tage.
     */
    public function getDailyForecasts(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT forecast_date, watt_hours_day, real_watt_hours_day
            FROM pv_forecast_daily
            WHERE forecast_date >= CURDATE() - INTERVAL 3 DAY
            ORDER BY forecast_date
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lädt die Stundenprognose für den heutigen Tag.
     */
    public function getTodayHourlyForecasts(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT forecast_time, watts
            FROM pv_forecast_hourly
            WHERE DATE(forecast_time) = CURDATE()
            ORDER BY forecast_time ASC
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ermittelt die durchschnittliche Abweichung der realen Erträge von der Prognose
     * in Prozent. Gibt null zurück, solange keine Realwerte erfasst sind.
     */
    public function getSystemBiasPercent(): ?float
    {
        $stmt = $this->pdo->query("
            SELECT (SUM(real_watt_hours_day) / SUM(watt_hours_day) - 1) * 100
            FROM pv_forecast_daily
            WHERE real_watt_hours_day IS NOT NULL
        ");
        $bias = $stmt->fetchColumn();

        return ($bias === false || $bias === null) ? null : (float)$bias;
    }

    /**
     * Zeitpunkt der letzten Prognose-Aktualisierung.
     */
    public function getLastForecastUpdate(): ?string
    {
        $stmt = $this->pdo->query("SELECT MAX(updated_at) FROM pv_forecast_daily");
        $lastUpdate = $stmt->fetchColumn();

        return ($lastUpdate === false || $lastUpdate === null) ? null : (string)$lastUpdate;
    }

    /**
     * Speichert die manuell erfassten Tageserträge (Eingabe in kWh, Ablage in Wh).
     * Ungültige Datumsangaben werden übersprungen, leere Werte auf NULL gesetzt.
     *
     * @param array<string, mixed> $yieldsKwh Zuordnung Datum (Y-m-d) => Ertrag in kWh
     * @return int Anzahl der verarbeiteten Datensätze
     * @throws Throwable
     */
    public function saveRealYields(array $yieldsKwh): int
    {
        $stmt = $this->pdo->prepare("
            UPDATE pv_forecast_daily
            SET real_watt_hours_day = :real_wh
            WHERE forecast_date = :date
        ");

        $this->pdo->beginTransaction();

        try {
            $saved = 0;

            foreach ($yieldsKwh as $date => $kwhValue) {
                if (!$this->isValidDate($date)) {
                    continue;
                }

                $stmt->execute([
                    ':real_wh' => $this->toWattHours($kwhValue),
                    ':date' => $date,
                ]);
                $saved++;
            }

            $this->pdo->commit();

            return $saved;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Prüft, ob ein String ein gültiges Datum im Format Y-m-d ist.
     */
    private function isValidDate(string $date): bool
    {
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);

        return $dateObj !== false && $dateObj->format('Y-m-d') === $date;
    }

    /**
     * Rechnet eine kWh-Eingabe in ganze Wattstunden um; leere Eingaben ergeben null.
     */
    private function toWattHours(mixed $kwhValue): ?int
    {
        if (!is_scalar($kwhValue) || trim((string)$kwhValue) === '') {
            return null;
        }

        $kwh = (float)str_replace(',', '.', (string)$kwhValue);

        return (int)round(max(0.0, $kwh) * 1000);
    }
}
