<?php

namespace Kai\Tools\Shared\Utils;

use DateTimeImmutable;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use Throwable;

/**
 * Zentraler Service zur Erkennung sächsischer Schulferien und gesetzlicher Feiertage.
 * Bietet dynamische Countdowns, Stimmungs-Smileys und Ermittlung des nächsten Schultags.
 */
class HolidayService
{
    /**
     * Offizielle sächsische Schulferien (Fallback bei fehlender DB-Verbindung oder leeren Tabellen).
     * Quelle: Sächsisches Staatsministerium für Kultus.
     */
    private const array BUILTIN_SAXONY_HOLIDAYS = [
        ['name' => 'Winterferien', 'start_date' => '2025-02-17', 'end_date' => '2025-03-01', 'year' => 2025],
        ['name' => 'Osterferien', 'start_date' => '2025-04-18', 'end_date' => '2025-04-25', 'year' => 2025],
        ['name' => 'Unterrichtsfreier Tag', 'start_date' => '2025-05-30', 'end_date' => '2025-05-30', 'year' => 2025],
        ['name' => 'Sommerferien', 'start_date' => '2025-06-28', 'end_date' => '2025-08-08', 'year' => 2025],
        ['name' => 'Herbstferien', 'start_date' => '2025-10-06', 'end_date' => '2025-10-18', 'year' => 2025],
        ['name' => 'Weihnachtsferien', 'start_date' => '2025-12-22', 'end_date' => '2026-01-02', 'year' => 2025],

        ['name' => 'Winterferien', 'start_date' => '2026-02-09', 'end_date' => '2026-02-21', 'year' => 2026],
        ['name' => 'Osterferien', 'start_date' => '2026-04-03', 'end_date' => '2026-04-10', 'year' => 2026],
        ['name' => 'Unterrichtsfreier Tag', 'start_date' => '2026-05-15', 'end_date' => '2026-05-15', 'year' => 2026],
        ['name' => 'Sommerferien', 'start_date' => '2026-07-04', 'end_date' => '2026-08-14', 'year' => 2026],
        ['name' => 'Herbstferien', 'start_date' => '2026-10-12', 'end_date' => '2026-10-24', 'year' => 2026],
        ['name' => 'Weihnachtsferien', 'start_date' => '2026-12-23', 'end_date' => '2027-01-02', 'year' => 2026],

        ['name' => 'Winterferien', 'start_date' => '2027-02-08', 'end_date' => '2027-02-19', 'year' => 2027],
        ['name' => 'Osterferien', 'start_date' => '2027-03-26', 'end_date' => '2027-04-02', 'year' => 2027],
        ['name' => 'Unterrichtsfreier Tag', 'start_date' => '2027-05-07', 'end_date' => '2027-05-07', 'year' => 2027],
        ['name' => 'Pfingstferien', 'start_date' => '2027-05-15', 'end_date' => '2027-05-18', 'year' => 2027],
        ['name' => 'Sommerferien', 'start_date' => '2027-07-10', 'end_date' => '2027-08-20', 'year' => 2027],
        ['name' => 'Herbstferien', 'start_date' => '2027-10-11', 'end_date' => '2027-10-23', 'year' => 2027],
        ['name' => 'Weihnachtsferien', 'start_date' => '2027-12-23', 'end_date' => '2028-01-01', 'year' => 2027],

        ['name' => 'Winterferien', 'start_date' => '2028-02-14', 'end_date' => '2028-02-26', 'year' => 2028],
        ['name' => 'Osterferien', 'start_date' => '2028-04-14', 'end_date' => '2028-04-22', 'year' => 2028],
        ['name' => 'Unterrichtsfreier Tag', 'start_date' => '2028-05-26', 'end_date' => '2028-05-26', 'year' => 2028],
        ['name' => 'Sommerferien', 'start_date' => '2028-07-22', 'end_date' => '2028-09-01', 'year' => 2028],
        ['name' => 'Herbstferien', 'start_date' => '2028-10-23', 'end_date' => '2028-11-03', 'year' => 2028],
        ['name' => 'Weihnachtsferien', 'start_date' => '2028-12-23', 'end_date' => '2029-01-02', 'year' => 2028],
    ];

    protected ?PDO $pdo;
    protected Logger $logger;

    public function __construct(?PDO $pdo = null, ?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            try {
                $this->pdo = Database::getInstance()->getConnection();
            } catch (Throwable) {
                $this->pdo = null;
            }
        }
    }

    /**
     * Prüft, ob ein gegebenes Datum in sächsische Schulferien oder einen gesetzlichen Feiertag fällt.
     */
    public function isHoliday(?string $date = null): bool
    {
        $checkDate = $date ?? date('Y-m-d');
        return $this->getCurrentHoliday($checkDate) !== null || $this->getPublicHoliday($checkDate) !== null;
    }

    /**
     * Liefert die aktuellen Schulferien für ein Datum (Standard: heute).
     *
     * @param string|null $date Format 'Y-m-d'
     * @return array{name: string, start_date: string, end_date: string, year: int}|null
     */
    public function getCurrentHoliday(?string $date = null): ?array
    {
        $checkDate = $date ?? date('Y-m-d');

        if ($this->pdo !== null) {
            try {
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
                if ($row) {
                    return $row;
                }
            } catch (Throwable $e) {
                $this->logger->warn('HolidayService: DB-Abfrage getCurrentHoliday fehlgeschlagen, nutze Fallback', ['error' => $e->getMessage()]);
            }
        }

        // Fallback: Integrierte sächsische Ferientermine
        foreach (self::BUILTIN_SAXONY_HOLIDAYS as $h) {
            if ($checkDate >= $h['start_date'] && $checkDate <= $h['end_date']) {
                return $h;
            }
        }

        return null;
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

        if ($this->pdo !== null) {
            try {
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
                if ($row) {
                    $start = new DateTimeImmutable($row['start_date']);
                    $check = new DateTimeImmutable($checkDate);
                    $row['days_until'] = (int)$check->diff($start)->format('%r%a');
                    return $row;
                }
            } catch (Throwable $e) {
                $this->logger->warn('HolidayService: DB-Abfrage getNextHoliday fehlgeschlagen, nutze Fallback', ['error' => $e->getMessage()]);
            }
        }

        foreach (self::BUILTIN_SAXONY_HOLIDAYS as $h) {
            if ($h['start_date'] > $checkDate) {
                $start = new DateTimeImmutable($h['start_date']);
                $check = new DateTimeImmutable($checkDate);
                $h['days_until'] = (int)$check->diff($start)->format('%r%a');
                return $h;
            }
        }

        return null;
    }

    /**
     * Prüft, ob ein gegebenes Datum ein gesetzlicher Feiertag im Freistaat Sachsen ist.
     */
    public function getPublicHoliday(string $dateStr): ?string
    {
        $dt = new DateTimeImmutable($dateStr);
        $year = (int)$dt->format('Y');

        $fixed = [
            sprintf('%04d-01-01', $year) => 'Neujahr',
            sprintf('%04d-05-01', $year) => 'Tag der Arbeit',
            sprintf('%04d-10-03', $year) => 'Tag der Deutschen Einheit',
            sprintf('%04d-10-31', $year) => 'Reformationstag',
            sprintf('%04d-12-25', $year) => '1. Weihnachtsfeiertag',
            sprintf('%04d-12-26', $year) => '2. Weihnachtsfeiertag',
        ];

        if (isset($fixed[$dateStr])) {
            return $fixed[$dateStr];
        }

        // Bewegliche Osterfeiertage
        $easter = (new DateTimeImmutable())->setTimestamp(easter_date($year));
        $moving = [
            $easter->modify('-2 days')->format('Y-m-d')  => 'Karfreitag',
            $easter->modify('+1 day')->format('Y-m-d')   => 'Ostermontag',
            $easter->modify('+39 days')->format('Y-m-d') => 'Christi Himmelfahrt',
            $easter->modify('+50 days')->format('Y-m-d') => 'Pfingstmontag',
        ];

        if (isset($moving[$dateStr])) {
            return $moving[$dateStr];
        }

        // Buß- und Bettag: Letzter Mittwoch vor dem 23. November (nur in Sachsen gesetzlicher Feiertag)
        $nov23 = new DateTimeImmutable(sprintf('%04d-11-23', $year));
        $bussUndBettag = $nov23->modify('previous wednesday')->format('Y-m-d');
        if ($dateStr === $bussUndBettag) {
            return 'Buß- und Bettag';
        }

        return null;
    }

    /**
     * Liefert detaillierte Informationen, wenn ein Datum ein Ferien- oder Feiertag ist.
     *
     * @return array{
     *     is_holiday: bool,
     *     type: string,
     *     name: string,
     *     start_date: string,
     *     end_date: string,
     *     is_range: bool,
     *     next_school_day: string,
     *     next_school_day_label: string,
     *     next_school_day_short: string
     * }|null
     */
    public function getHolidayDetails(?string $date = null): ?array
    {
        $checkDate = $date ?? date('Y-m-d');
        $schoolHoliday = $this->getCurrentHoliday($checkDate);
        $publicHoliday = $this->getPublicHoliday($checkDate);

        if ($schoolHoliday === null && $publicHoliday === null) {
            return null;
        }

        $isSchool = $schoolHoliday !== null;
        $name = $isSchool ? $schoolHoliday['name'] : $publicHoliday;
        $startDate = $isSchool ? $schoolHoliday['start_date'] : $checkDate;
        $endDate = $isSchool ? $schoolHoliday['end_date'] : $checkDate;

        $nextSchoolDay = $this->getNextSchoolDay($endDate);
        $nextDt = new DateTimeImmutable($nextSchoolDay);
        $dayNames = [
            1 => 'Montag',
            2 => 'Dienstag',
            3 => 'Mittwoch',
            4 => 'Donnerstag',
            5 => 'Freitag',
            6 => 'Samstag',
            7 => 'Sonntag',
        ];
        $nextDayOfWeek = $dayNames[(int)$nextDt->format('N')] ?? '';
        $nextSchoolDayLabel = $nextDayOfWeek . ', ' . $nextDt->format('d.m.');
        $nextSchoolDayShort = $nextDt->format('d.m.');

        return [
            'is_holiday' => true,
            'type' => $isSchool ? 'school_holiday' : 'public_holiday',
            'name' => $name,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_range' => ($startDate !== $endDate),
            'next_school_day' => $nextSchoolDay,
            'next_school_day_label' => $nextSchoolDayLabel,
            'next_school_day_short' => $nextSchoolDayShort,
        ];
    }

    /**
     * Liefert den nächsten regulären Schultag (überspringt Samstage, Sonntage,
     * sächsische Schulferien und Feiertage).
     */
    public function getNextSchoolDay(string $date): string
    {
        $dt = new DateTimeImmutable($date);
        $next = $dt->modify('+1 day');

        while ((int)$next->format('N') >= 6 || $this->isHoliday($next->format('Y-m-d'))) {
            $next = $next->modify('+1 day');
        }

        return $next->format('Y-m-d');
    }

    /**
     * Ermittelt die verbleibenden freien Tage und die zugehörige Smiley-Stufe.
     *
     * @param string|null $targetDate Ausgewähltes Datum (z.B. Montag 12.10.)
     * @param string|null $referenceDate Aktueller Stichtag für den Countdown (Standard: heute)
     * @return array{
     *     has_holiday: bool,
     *     days_left: int,
     *     smiley: string,
     *     headline: string,
     *     subtext: string,
     *     class: string,
     *     holiday_name?: string,
     *     start_date?: string,
     *     end_date?: string,
     *     next_school_day?: string,
     *     next_school_day_label?: string,
     *     next_school_day_short?: string
     * }
     */
    public function getCountdown(?string $targetDate = null, ?string $referenceDate = null): array
    {
        $target = $targetDate ?? date('Y-m-d');
        $today = $referenceDate ?? date('Y-m-d');
        $now = new DateTimeImmutable($today);

        $holiday = $this->getHolidayDetails($target);
        if ($holiday === null) {
            $next = $this->getNextHoliday($target);
            return [
                'has_holiday' => false,
                'days_left' => 0,
                'smiley' => '🎒',
                'headline' => 'Regulärer Schultag',
                'subtext' => 'Keine Ferien an diesem Tag.',
                'class' => 'holiday-mood-school',
                'next_holiday' => $next,
            ];
        }

        $nextSchoolDay = new DateTimeImmutable($holiday['next_school_day']);
        $holidayStart = new DateTimeImmutable($holiday['start_date']);

        // Berechnungsdatum für verbleibende Tage bestimmen
        $calcDate = $now;

        // Wenn heute vor Ferienbeginn liegt (z. B. Freitag vor den Ferien nach Schulschluss):
        if ($now < $holidayStart) {
            $hour = (int)date('G');
            $dayOfWeek = (int)$now->format('N');
            if ($dayOfWeek === 5 && $hour >= 13) {
                // Freitag nach 13 Uhr: freie Tage ab Samstag zählen
                $calcDate = $now->modify('+1 day');
            } elseif ($dayOfWeek >= 6 && $now->modify('+2 days') >= $holidayStart) {
                // Wochenende direkt vor Ferienstart
                $calcDate = $now;
            } else {
                // Bei weit in der Zukunft liegendem Ziel-Datum ab Zieldatum rechnen
                $calcDate = new DateTimeImmutable($target);
            }
        } elseif ($now < $nextSchoolDay) {
            // Während der Ferien und am direkt anschließenden Wochenende vor Schulstart
            $calcDate = $now;
        } else {
            $calcDate = new DateTimeImmutable($target);
        }

        // Verbleibende Tage bis zum Tag vor Schulstart (inklusive $calcDate)
        $daysLeft = (int)$calcDate->diff($nextSchoolDay)->days;
        if ($daysLeft < 0) {
            $daysLeft = 0;
        }

        $isSingleDay = !$holiday['is_range'];
        $mood = self::getHolidayMood($daysLeft, $isSingleDay, $holiday['name']);

        return array_merge($mood, [
            'has_holiday' => true,
            'days_left' => $daysLeft,
            'holiday_name' => $holiday['name'],
            'start_date' => $holiday['start_date'],
            'end_date' => $holiday['end_date'],
            'next_school_day' => $holiday['next_school_day'],
            'next_school_day_label' => $holiday['next_school_day_label'],
            'next_school_day_short' => $holiday['next_school_day_short'],
        ]);
    }

    /**
     * Ordnet den verbleibenden Tagen einen Stimmungs-Smiley und Text zu.
     * Verändert sich von fröhlich (🥳 / 😎) bis traurig (😢 / 😭) mit abnehmenden Tagen.
     */
    public static function getHolidayMood(int $daysLeft, bool $isSingleDay = false, string $holidayName = 'Ferien'): array
    {
        if ($isSingleDay) {
            if ($daysLeft <= 1) {
                return [
                    'smiley' => '🎉',
                    'headline' => "Schulfrei! ({$holidayName})",
                    'subtext' => '1 Tag schulfrei – ausschlafen, erholen und die Seele baumeln lassen! 😴',
                    'class' => 'holiday-mood-celebrate',
                ];
            }
            return [
                'smiley' => '😎',
                'headline' => "Langes Wochenende! Noch {$daysLeft} Tage frei",
                'subtext' => "Schulfrei wegen {$holidayName} – genießt die freie Zeit!",
                'class' => 'holiday-mood-chill',
            ];
        }

        if ($daysLeft >= 14) {
            return [
                'smiley' => '🥳',
                'headline' => "Noch {$daysLeft} Tage frei!",
                'subtext' => 'Ferienstart! Maximale Freiheit – genießt die Zeit in vollen Zügen!',
                'class' => 'holiday-mood-party',
            ];
        }
        if ($daysLeft >= 10) {
            return [
                'smiley' => '😎',
                'headline' => "Noch {$daysLeft} Tage frei!",
                'subtext' => 'Voll im Ferienmodus – absolut tiefenentspannt und ohne Wecker.',
                'class' => 'holiday-mood-chill',
            ];
        }
        if ($daysLeft >= 7) {
            return [
                'smiley' => '😁',
                'headline' => "Noch {$daysLeft} Tage frei!",
                'subtext' => 'Immer noch über eine Woche frei – kein Wecker, kein Stress!',
                'class' => 'holiday-mood-happy',
            ];
        }
        if ($daysLeft >= 5) {
            return [
                'smiley' => '😊',
                'headline' => "Noch {$daysLeft} Tage frei!",
                'subtext' => 'Halbzeit! Noch schön viel freie Tage zum Entspannen vor uns.',
                'class' => 'holiday-mood-content',
            ];
        }
        if ($daysLeft === 4) {
            return [
                'smiley' => '😐',
                'headline' => "Noch 4 Tage frei...",
                'subtext' => 'Langsam geht der Countdown los, aber 4 Tage sind noch drin!',
                'class' => 'holiday-mood-neutral',
            ];
        }
        if ($daysLeft === 3) {
            return [
                'smiley' => '😕',
                'headline' => "Noch 3 Tage frei...",
                'subtext' => 'Die letzten Tage brechen an – jeden einzelnen Moment auskosten!',
                'class' => 'holiday-mood-skeptical',
            ];
        }
        if ($daysLeft === 2) {
            return [
                'smiley' => '😟',
                'headline' => "Nur noch 2 Tage frei...",
                'subtext' => 'Der Countdown tickt... Schon an Ranzen und Federtasche gedacht?',
                'class' => 'holiday-mood-worried',
            ];
        }
        if ($daysLeft === 1) {
            return [
                'smiley' => '😢',
                'headline' => "Letzter Ferientag!",
                'subtext' => 'Morgen startet wieder die Schule – genießt den letzten freien Tag!',
                'class' => 'holiday-mood-sad',
            ];
        }

        return [
            'smiley' => '😭',
            'headline' => "Ferien vorbei!",
            'subtext' => 'Morgen bzw. heute geht die Schule wieder los – guten Neustart!',
            'class' => 'holiday-mood-crying',
        ];
    }
}
