<?php

namespace Kai\Tools\Einkaufsliste;

use DateTimeImmutable;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Utils\HolidayService as SharedHolidayService;
use PDO;

/**
 * Prüft sächsische Schulferien und passt Verbrauchs- sowie Vorschlagsintervalle an.
 * Basiert auf dem zentralen Shared HolidayService.
 */
class HolidayService extends SharedHolidayService
{
    public function __construct(?PDO $pdo = null, ?Logger $logger = null)
    {
        parent::__construct($pdo, $logger);
    }

    /**
     * Liefert eine kompakte Kontext-Übersicht für das UI und die Vorschlagsgenerierung der Einkaufsliste.
     *
     * @return array{
     *     is_holiday: bool,
     *     holiday_name: ?string,
     *     holiday_end: ?string,
     *     next_holiday_name: ?string,
     *     next_holiday_start: ?string,
     *     days_until_next: ?int,
     *     days_until_holiday_end: ?int,
     *     school_snack_state: string,
     *     status_badge: string
     * }
     */
    public function getHolidayContext(?string $date = null): array
    {
        $current = $this->getCurrentHoliday($date);
        $next = $this->getNextHoliday($date);
        $today = new DateTimeImmutable($date ?? 'today');

        if ($current) {
            $endDate = new DateTimeImmutable($current['end_date']);
            $daysUntilEnd = (int)$today->diff($endDate)->format('%r%a');
            $formattedEnd = date('d.m.Y', strtotime($current['end_date']));

            // Brotbüchsen-Logik: In den letzten 5 Tagen der Ferien vor Schulbeginn wieder aktivieren
            $isBackToSchoolPrep = ($daysUntilEnd <= 5);
            $schoolSnackState = $isBackToSchoolPrep ? 'back_to_school_prep' : 'holiday_pause';

            if ($isBackToSchoolPrep) {
                $statusBadge = "🎒 Noch {$daysUntilEnd} Tag(e) {$current['name']} in Sachsen (bis {$formattedEnd}) – Brotbüchsen-Einkauf für Schulstart aktiv!";
            } else {
                $statusBadge = "🏖️ Aktuell {$current['name']} in Sachsen (bis {$formattedEnd}) – Ferienmodus (Brotbüchsen pausiert)";
            }

            return [
                'is_holiday' => true,
                'holiday_name' => $current['name'],
                'holiday_end' => $current['end_date'],
                'next_holiday_name' => null,
                'next_holiday_start' => null,
                'days_until_next' => null,
                'days_until_holiday_end' => $daysUntilEnd,
                'school_snack_state' => $schoolSnackState,
                'status_badge' => $statusBadge,
            ];
        }

        if ($next && $next['days_until'] <= 14) {
            $formattedStart = date('d.m.Y', strtotime($next['start_date']));
            // Beim Einkauf 1 bis 5 Tage vor Ferienbeginn bereits pausieren
            $isPreHolidayPause = ($next['days_until'] <= 5);
            $schoolSnackState = $isPreHolidayPause ? 'pre_holiday_pause' : 'normal';

            if ($isPreHolidayPause) {
                $statusBadge = "📅 In {$next['days_until']} Tag(en) {$next['name']} in Sachsen (ab {$formattedStart}) – Brotbüchsen pausieren für die Ferien";
            } else {
                $statusBadge = "📅 In {$next['days_until']} Tag(en) {$next['name']} in Sachsen (ab {$formattedStart})";
            }

            return [
                'is_holiday' => false,
                'holiday_name' => null,
                'holiday_end' => null,
                'next_holiday_name' => $next['name'],
                'next_holiday_start' => $next['start_date'],
                'days_until_next' => $next['days_until'],
                'days_until_holiday_end' => null,
                'school_snack_state' => $schoolSnackState,
                'status_badge' => $statusBadge,
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
            'days_until_holiday_end' => null,
            'school_snack_state' => 'normal',
            'status_badge' => "🏫 Regulärer Schulbetrieb ({$nextText})",
        ];
    }
}
