<?php

namespace Kai\Tools\Projects;

use DateTimeImmutable;
use Kai\Tools\Shared\Utils\HolidayService;

/**
 * Kern-Berechnungs-Engine für das Projects-Modul.
 *
 * REGELN:
 * 1. Kein Überstunden-Saldo: Ein regulärer Arbeitstag hat exakt 8,0h Soll.
 * 2. Feiertage für Sachsen (SN) oder Hamburg (HH) setzen das Tagessoll auf 0,0h.
 * 3. Abwesenheit (Urlaub/Krankheit) mindert das Tagessoll (z. B. 8,0h oder halber Tag 4,0h).
 * 4. Summe aus Ausnahmen + Abwesenheit darf 8,0h niemals übersteigen.
 * 5. Restzeit auf dem Hauptprojekt = 8,0h - Abwesenheit - Summe(Ausnahmen).
 */
class ProjectsEngine
{
    public const float DAILY_TARGET_HOURS = 8.0;

    private ProjectsRepository $repo;
    private HolidayService $holidayService;

    public function __construct(?ProjectsRepository $repo = null, ?HolidayService $holidayService = null)
    {
        $this->repo = $repo ?? new ProjectsRepository();
        $this->holidayService = $holidayService ?? new HolidayService();
    }

    /**
     * Ermittelt alle Berechnungsdetails für einen einzelnen Tag.
     *
     * @return array{
     *     date: string,
     *     day_of_week: int,
     *     is_weekend: bool,
     *     is_holiday: bool,
     *     holiday_name: ?string,
     *     target_hours: float,
     *     absence: ?array,
     *     absence_hours: float,
     *     exceptions: array,
     *     exceptions_hours: float,
     *     default_project: ?array,
     *     default_project_hours: float,
     *     actual_hours: float,
     *     remaining_capacity: float
     * }
     */
    public function getDayDetails(int $userId, string $date, string $stateCode = 'SN'): array
    {
        $dt = new DateTimeImmutable($date);
        $dayOfWeek = (int)$dt->format('N'); // 1 = Mo, 7 = So
        $isWeekend = ($dayOfWeek >= 6);

        $holidayName = $this->holidayService->getPublicHoliday($date, $stateCode);
        $isHoliday = ($holidayName !== null);

        // Reguläres Tagessoll: 8.0h für Mo-Fr ohne Feiertag, sonst 0.0h
        $targetHours = ($isWeekend || $isHoliday) ? 0.0 : self::DAILY_TARGET_HOURS;

        // Abwesenheit prüfen (Urlaub, Krankheit etc.)
        $absence = $this->repo->getAbsenceForDate($userId, $date);
        $absenceHours = $absence ? (float)$absence['hours'] : 0.0;
        if ($isWeekend || $isHoliday) {
            $absenceHours = 0.0;
        }

        // Gebuchte Ausnahmen abrufen
        $exceptions = $this->repo->getExceptionsForDate($userId, $date);
        $exceptionsHours = 0.0;
        foreach ($exceptions as $ex) {
            $exceptionsHours += (float)$ex['hours'];
        }

        // Hauptprojekt für dieses Datum ermitteln
        $defaultProject = $this->repo->getDefaultProjectForDate($userId, $date);

        // Restzeit auf Hauptprojekt berechnen (streng auf maximal 8,0h begrenzt, nicht negativ)
        if ($isWeekend || $isHoliday) {
            $defaultProjectHours = 0.0;
        } else {
            $usedHours = $absenceHours + $exceptionsHours;
            $defaultProjectHours = max(0.0, round(self::DAILY_TARGET_HOURS - $usedHours, 1));
        }

        $actualHours = $absenceHours + $exceptionsHours + $defaultProjectHours;
        $remainingCapacity = max(0.0, round(self::DAILY_TARGET_HOURS - ($absenceHours + $exceptionsHours), 1));

        return [
            'date' => $date,
            'day_of_week' => $dayOfWeek,
            'is_weekend' => $isWeekend,
            'is_holiday' => $isHoliday,
            'holiday_name' => $holidayName,
            'target_hours' => $targetHours,
            'absence' => $absence,
            'absence_hours' => $absenceHours,
            'exceptions' => $exceptions,
            'exceptions_hours' => $exceptionsHours,
            'default_project' => $defaultProject,
            'default_project_hours' => $defaultProjectHours,
            'actual_hours' => $actualHours,
            'remaining_capacity' => $remainingCapacity,
        ];
    }

    /**
     * Erstellt die vollständige Monatsmatrix.
     *
     * @return array{
     *     year: int,
     *     month: int,
     *     days_in_month: int,
     *     month_name: string,
     *     state_code: string,
     *     total_target_hours: float,
     *     total_actual_hours: float,
     *     days: array<int, array>,
     *     rows: array<int, array{
     *         type: string,
     *         project_id: ?int,
     *         name: string,
     *         short_code: ?string,
     *         external_code: ?string,
     *         color: string,
     *         daily_hours: array<int, float>,
     *         total_hours: float
     *     }>,
     *     daily_totals: array<int, float>
     * }
     */
    public function getMonthMatrix(int $userId, int $year, int $month, string $stateCode = 'SN'): array
    {
        $startDateStr = sprintf('%04d-%02d-01', $year, $month);
        $daysInMonth = (int)date('t', strtotime($startDateStr));

        $monthNames = [
            1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
            7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
        ];

        $days = [];
        $totalTargetHours = 0.0;
        $totalActualHours = 0.0;

        // Alle Projekte des Nutzers laden
        $allProjects = $this->repo->getProjects($userId, true);
        $projectsMap = [];
        foreach ($allProjects as $p) {
            $projectsMap[(int)$p['id']] = $p;
        }

        // Ausnahmen & Abwesenheiten für den gesamten Monat laden
        $monthExceptions = $this->repo->getExceptionsForMonth($userId, $year, $month);
        $monthAbsences = $this->repo->getAbsencesForMonth($userId, $year, $month);

        $absencesByDay = [];
        foreach ($monthAbsences as $ab) {
            $d = (int)date('j', strtotime($ab['date']));
            $absencesByDay[$d] = $ab;
        }

        $exceptionsByDay = [];
        foreach ($monthExceptions as $ex) {
            $d = (int)date('j', strtotime($ex['date']));
            $pid = (int)$ex['project_id'];
            $exceptionsByDay[$d][$pid] = ($exceptionsByDay[$d][$pid] ?? 0.0) + (float)$ex['hours'];
        }

        // Zeilen initialisieren: Für jedes Projekt sowie Zeilen für Abwesenheiten
        $projectRows = [];
        foreach ($allProjects as $p) {
            $pid = (int)$p['id'];
            $projectRows[$pid] = [
                'type' => 'project',
                'project_id' => $pid,
                'name' => $p['name'],
                'short_code' => $p['short_code'],
                'external_code' => $p['external_code'] ?? '',
                'color' => $p['color'] ?? '#0284c7',
                'daily_hours' => array_fill(1, $daysInMonth, 0.0),
                'total_hours' => 0.0,
            ];
        }

        $absenceRows = [
            'vacation' => [
                'type' => 'absence',
                'project_id' => null,
                'name' => 'Urlaub',
                'short_code' => 'URL',
                'external_code' => 'URLAUB',
                'color' => '#10b981',
                'daily_hours' => array_fill(1, $daysInMonth, 0.0),
                'total_hours' => 0.0,
            ],
            'sick' => [
                'type' => 'absence',
                'project_id' => null,
                'name' => 'Krankheit',
                'short_code' => 'KRK',
                'external_code' => 'KRANK',
                'color' => '#ef4444',
                'daily_hours' => array_fill(1, $daysInMonth, 0.0),
                'total_hours' => 0.0,
            ],
            'special_leave' => [
                'type' => 'absence',
                'project_id' => null,
                'name' => 'Sonderurlaub',
                'short_code' => 'SON',
                'external_code' => 'SONDERURLAUB',
                'color' => '#8b5cf6',
                'daily_hours' => array_fill(1, $daysInMonth, 0.0),
                'total_hours' => 0.0,
            ],
        ];

        $dailyTotals = array_fill(1, $daysInMonth, 0.0);

        // Jeden Tag des Monats berechnen
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $curDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $dt = new DateTimeImmutable($curDate);
            $dayOfWeek = (int)$dt->format('N');
            $isWeekend = ($dayOfWeek >= 6);

            $holidayName = $this->holidayService->getPublicHoliday($curDate, $stateCode);
            $isHoliday = ($holidayName !== null);
            $targetHours = ($isWeekend || $isHoliday) ? 0.0 : self::DAILY_TARGET_HOURS;
            $totalTargetHours += $targetHours;

            $absence = $absencesByDay[$day] ?? null;
            $absenceHours = ($absence && !$isWeekend && !$isHoliday) ? (float)$absence['hours'] : 0.0;
            if ($absence && !$isWeekend && !$isHoliday) {
                $aType = $absence['type'];
                if (isset($absenceRows[$aType])) {
                    $absenceRows[$aType]['daily_hours'][$day] = $absenceHours;
                    $absenceRows[$aType]['total_hours'] += $absenceHours;
                }
            }

            // Ausnahmen eintragen
            $dayExceptionSum = 0.0;
            if (isset($exceptionsByDay[$day])) {
                foreach ($exceptionsByDay[$day] as $pid => $hrs) {
                    if (isset($projectRows[$pid])) {
                        $projectRows[$pid]['daily_hours'][$day] = $hrs;
                        $projectRows[$pid]['total_hours'] += $hrs;
                        $dayExceptionSum += $hrs;
                    }
                }
            }

            // Hauptprojekt-Restzeit berechnen
            $defaultProject = $this->repo->getDefaultProjectForDate($userId, $curDate);
            $defaultHours = 0.0;
            if (!$isWeekend && !$isHoliday && $defaultProject) {
                $defPid = (int)$defaultProject['project_id'];
                $defaultHours = max(0.0, round(self::DAILY_TARGET_HOURS - ($absenceHours + $dayExceptionSum), 1));
                if (isset($projectRows[$defPid])) {
                    $projectRows[$defPid]['daily_hours'][$day] += $defaultHours;
                    $projectRows[$defPid]['total_hours'] += $defaultHours;
                }
            }

            $daySum = $absenceHours + $dayExceptionSum + $defaultHours;
            $dailyTotals[$day] = $daySum;
            $totalActualHours += $daySum;

            $days[$day] = [
                'day' => $day,
                'date' => $curDate,
                'day_of_week' => $dayOfWeek,
                'weekday_short' => ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][$dayOfWeek - 1],
                'is_weekend' => $isWeekend,
                'is_holiday' => $isHoliday,
                'holiday_name' => $holidayName,
                'target_hours' => $targetHours,
                'actual_hours' => $daySum,
            ];
        }

        // Zeilen zusammenführen (Projektzeilen mit 0 Stunden ausfiltern, es sei denn es gibt keine anderen)
        $activeProjectRows = array_filter($projectRows, fn($r) => $r['total_hours'] > 0);
        if (empty($activeProjectRows) && !empty($projectRows)) {
            // Mindestens die ersten Projekte anzeigen
            $activeProjectRows = array_slice($projectRows, 0, 3);
        }

        $activeAbsenceRows = array_filter($absenceRows, fn($r) => $r['total_hours'] > 0);

        $finalRows = array_merge(array_values($activeProjectRows), array_values($activeAbsenceRows));

        return [
            'year' => $year,
            'month' => $month,
            'days_in_month' => $daysInMonth,
            'month_name' => $monthNames[$month] ?? '',
            'state_code' => $stateCode,
            'total_target_hours' => round($totalTargetHours, 1),
            'total_actual_hours' => round($totalActualHours, 1),
            'days' => $days,
            'rows' => $finalRows,
            'daily_totals' => $dailyTotals,
        ];
    }
}
