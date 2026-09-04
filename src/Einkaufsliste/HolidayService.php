<?php

namespace Kai\Tools\Einkaufsliste;

use DateTimeImmutable;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;

/**
 * Prüft sächsische Schulferien und passt Verbrauchs- sowie Vorschlagsintervalle an.
 */
class HolidayService
{
    private PDO $pdo;
    private Logger $logger;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->logger = new Logger();
    }

    /**
     * Prüft, ob ein gegebenes Datum in sächsische Schulferien fällt.
     */
    public function isHoliday(?string $date = null): bool
    {
        return $this->getCurrentHoliday($date) !== null;
    }

    /**
     * Liefert die aktuellen Ferien für ein Datum (Standard: heute).
     *
     * @param string|null $date Format 'Y-m-d'
     * @return array{name: string, start_date: string, end_date: string, year: int}|null
     */
    public function getCurrentHoliday(?string $date = null): ?array
    {
        $checkDate = $date ?? date('Y-m-d');

        $stmt = $this->pdo->prepare("
            SELECT name, start_date, end_date, year
            FROM school_holidays
            WHERE state_code = 'SN'
              AND :check_date BETWEEN start_date AND end_date
            ORDER BY start_date ASC
            LIMIT 1
        ");
        $stmt->execute([':check_date' => $checkDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Ermittelt die nächsten bevorstehenden Ferien in Sachsen.
     *
     * @param string|null $date Format 'Y-m-d'
     * @return array{name: string, start_date: string, end_date: string, days_until: int}|null
     */
    public function getNextHoliday(?string $date = null): ?array
    {
        $checkDate = $date ?? date('Y-m-d');

        $stmt = $this->pdo->prepare("
            SELECT name, start_date, end_date, year
            FROM school_holidays
            WHERE state_code = 'SN'
              AND start_date > :check_date
            ORDER BY start_date ASC
            LIMIT 1
        ");
        $stmt->execute([':check_date' => $checkDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $start = new DateTimeImmutable($row['start_date']);
        $check = new DateTimeImmutable($checkDate);
        $row['days_until'] = (int)$check->diff($start)->format('%r%a');
        return $row;
    }

    /**
     * Liefert eine kompakte Kontext-Übersicht für das UI und die Vorschlagsgenerierung.
     *
     * @return array{
     *     is_holiday: bool,
     *     holiday_name: ?string,
     *     holiday_end: ?string,
     *     next_holiday_name: ?string,
     *     next_holiday_start: ?string,
     *     days_until_next: ?int,
     *     status_badge: string
     * }
     */
    public function getHolidayContext(?string $date = null): array
    {
        $current = $this->getCurrentHoliday($date);
        $next = $this->getNextHoliday($date);

        if ($current) {
            $formattedEnd = date('d.m.Y', strtotime($current['end_date']));
            return [
                'is_holiday' => true,
                'holiday_name' => $current['name'],
                'holiday_end' => $current['end_date'],
                'next_holiday_name' => null,
                'next_holiday_start' => null,
                'days_until_next' => null,
                'status_badge' => "🏖️ Aktuell {$current['name']} in Sachsen (bis {$formattedEnd}) – Ferienfaktor aktiv",
            ];
        }

        if ($next && $next['days_until'] <= 14) {
            $formattedStart = date('d.m.Y', strtotime($next['start_date']));
            return [
                'is_holiday' => false,
                'holiday_name' => null,
                'holiday_end' => null,
                'next_holiday_name' => $next['name'],
                'next_holiday_start' => $next['start_date'],
                'days_until_next' => $next['days_until'],
                'status_badge' => "📅 In {$next['days_until']} Tag(en) {$next['name']} in Sachsen (ab {$formattedStart})",
            ];
        }

        $nextText = $next ? "Nächste Ferien: {$next['name']} ab " . date('d.m.Y', strtotime($next['start_date'])) : 'Keine Ferien eingetragen';
        return [
            'is_holiday' => false,
            'holiday_name' => null,
            'holiday_end' => null,
            'next_holiday_name' => $next['name'] ?? null,
            'next_holiday_start' => $next['start_date'] ?? null,
            'days_until_next' => $next['days_until'] ?? null,
            'status_badge' => "🏫 Regulärer Schulbetrieb ({$nextText})",
        ];
    }
}
