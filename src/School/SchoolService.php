<?php

namespace Kai\Tools\School;

use DateTimeImmutable;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Log\Logger;
use Throwable;

/**
 * Zentraler Service für Vertretungspläne, Schulschluss-Berechnung und Sprachzusammenfassungen.
 */
class SchoolService
{
    private VPPlanClient $client;
    private VPPlanParser $parser;
    private SchoolPlanRepository $planRepo;
    private SchoolStudentRepository $studentRepo;
    private ActivityLogger $activityLogger;
    private Logger $logger;

    public function __construct(
        ?VPPlanClient $client = null,
        ?VPPlanParser $parser = null,
        ?SchoolPlanRepository $planRepo = null,
        ?SchoolStudentRepository $studentRepo = null,
        ?ActivityLogger $activityLogger = null,
        ?Logger $logger = null
    ) {
        $this->client = $client ?? new VPPlanClient();
        $this->parser = $parser ?? new VPPlanParser();
        $this->planRepo = $planRepo ?? new SchoolPlanRepository();
        $this->studentRepo = $studentRepo ?? new SchoolStudentRepository();
        $this->activityLogger = $activityLogger ?? new ActivityLogger(Database::getInstance());
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Ermittelt den Ziel-Schultag nach der 14:00-Uhr- und Wochenend-Logik.
     *
     * - Mo–Do vor 14:00 Uhr: heute
     * - Mo–Do ab 14:00 Uhr: morgen (nächster Tag)
     * - Fr vor 14:00 Uhr: heute
     * - Fr ab 14:00 Uhr, Sa & So: darauffolgender Montag
     */
    public function determineEffectiveDate(?string $requestedDate = null, ?int $timestamp = null): string
    {
        if ($requestedDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
            return $requestedDate;
        }

        $now = $timestamp !== null ? (new DateTimeImmutable())->setTimestamp($timestamp) : new DateTimeImmutable();
        $hour = (int)$now->format('G');
        $dayOfWeek = (int)$now->format('N'); // 1 = Mo, ..., 5 = Fr, 6 = Sa, 7 = So

        // Am Wochenende immer der nächste Montag
        if ($dayOfWeek === 6) { // Samstag
            return $now->modify('+2 days')->format('Y-m-d');
        }
        if ($dayOfWeek === 7) { // Sonntag
            return $now->modify('+1 day')->format('Y-m-d');
        }

        // Freitag: ab 14:00 Uhr gilt Montag als nächster Schultag
        if ($dayOfWeek === 5) {
            if ($hour >= 14) {
                return $now->modify('+3 days')->format('Y-m-d');
            }
            return $now->format('Y-m-d');
        }

        // Montag bis Donnerstag: ab 14:00 Uhr gilt der Folgetag
        if ($hour >= 14) {
            return $now->modify('+1 day')->format('Y-m-d');
        }

        return $now->format('Y-m-d');
    }

    /**
     * Liefert den nächsten Schultag (ohne Samstag/Sonntag) ausgehend von einem Datum.
     */
    public function getNextSchoolDay(string $date): string
    {
        $dt = new DateTimeImmutable($date);
        $next = $dt->modify('+1 day');
        while ((int)$next->format('N') >= 6) {
            $next = $next->modify('+1 day');
        }

        return $next->format('Y-m-d');
    }

    /**
     * Synchronisiert den Plan für ein bestimmtes Datum von stundenplan24.de.
     *
     * @return array{success: bool, date: string, status: int, message: string, changed: bool}
     */
    public function syncDate(string $date): array
    {
        try {
            $fetch = $this->client->fetch($date);
            if ($fetch['status'] === 404 || empty($fetch['content'])) {
                return [
                    'success' => false,
                    'date' => $date,
                    'status' => $fetch['status'],
                    'message' => $fetch['error'] ?? 'Kein Plan vorhanden.',
                    'changed' => false,
                ];
            }

            $parsed = $this->parser->parse($fetch['content']);
            if ($parsed === null) {
                return [
                    'success' => false,
                    'date' => $date,
                    'status' => 500,
                    'message' => 'XML konnte nicht geparst werden.',
                    'changed' => false,
                ];
            }

            // Prüfen, ob sich der Plan verändert hat
            $existing = $this->planRepo->getPlanMetadata($date);
            $isChanged = ($existing === null || ($existing['raw_hash'] ?? '') !== $parsed['raw_hash']);

            $this->planRepo->savePlan(
                $date,
                $parsed['plan_timestamp'],
                $parsed['school_week'],
                $parsed['raw_hash']
            );
            $this->planRepo->savePlanItems($date, $parsed['items']);
            $this->planRepo->saveGlobalNotes($date, $parsed['global_notes']);

            if ($isChanged) {
                $itemCount = count($parsed['items']);
                $ts = $parsed['plan_timestamp'] ?? 'unbekannt';
                $this->activityLogger->log(
                    'school_plan_updated',
                    "Vertretungsplan für {$date} aktualisiert ({$itemCount} Einträge, Stand: {$ts})",
                    '/school/index.php?date=' . $date
                );
            }

            return [
                'success' => true,
                'date' => $date,
                'status' => 200,
                'message' => 'Plan erfolgreich aktualisiert.',
                'changed' => $isChanged,
            ];
        } catch (Throwable $e) {
            $this->logger->error('SchoolService: Fehler bei syncDate.', [
                'date' => $date,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'date' => $date,
                'status' => 500,
                'message' => 'Interner Fehler beim Abruf des Plans.',
                'changed' => false,
            ];
        }
    }

    /**
     * Importiert einen rohen XML-String direkt in die Datenbank (z. B. aus Testdateien).
     */
    public function importXmlContent(string $xmlContent): ?string
    {
        $parsed = $this->parser->parse($xmlContent);
        if ($parsed === null) {
            return null;
        }

        $date = $parsed['plan_date'];
        $this->planRepo->savePlan(
            $date,
            $parsed['plan_timestamp'],
            $parsed['school_week'],
            $parsed['raw_hash']
        );
        $this->planRepo->savePlanItems($date, $parsed['items']);
        $this->planRepo->saveGlobalNotes($date, $parsed['global_notes']);

        return $date;
    }

    /**
     * Synchronisiert heute und den nächsten Schultag (z. B. morgen oder Montag).
     *
     * @return array<string, array<string, mixed>>
     */
    public function syncTodayAndNext(): array
    {
        $today = date('Y-m-d');
        $next = $this->determineEffectiveDate(null);

        $results = [];
        $results[$today] = $this->syncDate($today);

        if ($next !== $today) {
            $results[$next] = $this->syncDate($next);
        }

        return $results;
    }

    /**
     * Ermittelt den detaillierten Stundenplan und die Ausfall-Auswertung für ein Kind.
     *
     * @return array<string, mixed>
     */
    public function getStudentSchedule(int|string $studentIdOrName, string $date): array
    {
        $student = is_numeric($studentIdOrName)
            ? $this->studentRepo->getById((int)$studentIdOrName)
            : $this->studentRepo->getByName((string)$studentIdOrName);

        if ($student === null) {
            return [
                'student' => null,
                'has_plan' => false,
                'date' => $date,
                'summary_sentence' => 'Schüler nicht gefunden.',
                'items' => [],
            ];
        }

        $className = (string)$student['class_name'];
        $items = $this->planRepo->getPlanItemsForClass($date, $className);
        $meta = $this->planRepo->getPlanMetadata($date);

        // Fach-Filter: Abgewählte Fächer für diesen Schüler herausfiltern
        $excludedRaw = (string)($student['excluded_subjects'] ?? '');
        $excludedList = [];
        if ($excludedRaw !== '') {
            $excludedList = array_values(array_filter(array_map('trim', explode(',', strtoupper($excludedRaw)))));
        }

        if (!empty($excludedList)) {
            $items = array_values(array_filter($items, static function (array $item) use ($excludedList): bool {
                $subj = strtoupper(trim((string)($item['subject'] ?? '')));
                return !in_array($subj, $excludedList, true);
            }));
        }

        if (empty($items)) {
            $dayLabel = $this->formatDateLabel($date);
            return [
                'student' => $student,
                'has_plan' => false,
                'date' => $date,
                'meta' => $meta,
                'summary_sentence' => "Für {$student['name']} ({$className}) liegt für {$dayLabel} noch kein Plan vor.",
                'items' => [],
                'deviations' => [],
                'start_time' => null,
                'end_time' => null,
                'excluded_subjects' => $excludedList,
            ];
        }

        // Unterrichtszeiten & Abweichungen analysieren
        $nonCancelledItems = array_values(array_filter($items, static fn($i): bool => empty($i['is_cancelled'])));
        $cancelledItems = array_values(array_filter($items, static fn($i): bool => !empty($i['is_cancelled'])));
        $substitutionItems = array_values(array_filter($items, static fn($i): bool => empty($i['is_cancelled']) && (!empty($i['is_substitution']) || !empty($i['is_room_change']))));

        $startTime = null;
        $endTime = null;
        $firstLessonNum = null;
        $lastLessonNum = null;

        if (!empty($nonCancelledItems)) {
            $first = $nonCancelledItems[0];
            $last = $nonCancelledItems[count($nonCancelledItems) - 1];

            $startTime = $first['start_time'];
            $firstLessonNum = (int)$first['lesson_number'];
            $endTime = $last['end_time'];
            $lastLessonNum = (int)$last['lesson_number'];
        }

        // Natürliche Sprach-Zusammenfassung generieren
        $dayLabel = $this->formatDateLabel($date);
        $summary = $this->buildSummarySentence($student['name'], $dayLabel, $startTime, $endTime, $firstLessonNum, $lastLessonNum, $cancelledItems);
        $deviations = $this->buildDeviationTexts($items);

        return [
            'student' => $student,
            'has_plan' => true,
            'date' => $date,
            'meta' => $meta,
            'class_name' => $className,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'first_lesson' => $firstLessonNum,
            'last_lesson' => $lastLessonNum,
            'total_lessons' => count($items),
            'cancelled_count' => count($cancelledItems),
            'substitution_count' => count($substitutionItems),
            'summary_sentence' => $summary,
            'deviations' => $deviations,
            'items' => $items,
            'excluded_subjects' => $excludedList,
        ];
    }

    /**
     * Erzeugt eine Übersicht für alle aktiven Schüler an einem bestimmten Datum.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllStudentsOverview(string $date): array
    {
        $students = $this->studentRepo->getActive();
        $overview = [];

        foreach ($students as $student) {
            $overview[] = $this->getStudentSchedule((int)$student['id'], $date);
        }

        return $overview;
    }

    /**
     * Liefert eine flüssige Sprachausgabe für den Google Assistant.
     */
    public function getNaturalLanguageSummary(string $studentName, ?string $date = null): string
    {
        $effectiveDate = $date ?? $this->determineEffectiveDate(null);
        $sched = $this->getStudentSchedule($studentName, $effectiveDate);

        if (!$sched['has_plan']) {
            $dayLabel = $this->formatDateLabel($effectiveDate);
            return "Für {$studentName} liegt für {$dayLabel} leider noch kein Stundenplan vor.";
        }

        $speech = $sched['summary_sentence'];

        // Detail-Abweichungen anfügen falls vorhanden
        if (!empty($sched['deviations'])) {
            $details = implode('. ', array_slice($sched['deviations'], 0, 3));
            $speech .= ' ' . $details . '.';
        }

        return trim($speech);
    }

    /**
     * Baut den zwingend vorgegebenen Kernsatz: "[Name] hat [heute|morgen] bis [Zeit] Schule."
     */
    private function buildSummarySentence(
        string $name,
        string $dayLabel,
        ?string $startTime,
        ?string $endTime,
        ?int $firstLesson,
        ?int $lastLesson,
        array $cancelled
    ): string {
        if ($endTime === null) {
            return "{$name} hat {$dayLabel} keinen Unterricht (schulfrei).";
        }

        $sentence = "{$name} hat {$dayLabel} bis {$endTime} Uhr Schule.";

        // Unterrichtsbeginn erwähnen, falls nicht zur 1. Stunde
        if ($firstLesson !== null && $firstLesson > 1 && $startTime !== null) {
            $sentence .= " Der Unterricht beginnt erst zur {$firstLesson}. Stunde um {$startTime} Uhr.";
        }

        // Früher Schluss durch Ausfall?
        if (!empty($cancelled)) {
            $lastCancelled = $cancelled[count($cancelled) - 1];
            if ($lastLesson !== null && (int)$lastCancelled['lesson_number'] > $lastLesson) {
                $sentence .= " Nach der {$lastLesson}. Stunde ist Schluss.";
            }
        }

        return $sentence;
    }

    /**
     * Formatiert Abweichungen als gut lesbare Aufzählung.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function buildDeviationTexts(array $items): array
    {
        $devs = [];

        foreach ($items as $item) {
            $st = (int)$item['lesson_number'];
            $subj = (string)$item['subject'];
            $info = (string)($item['info'] ?? '');

            if (!empty($item['is_cancelled'])) {
                $text = "{$st}. Stunde fällt aus";
                if ($info !== '') {
                    $text .= " ({$info})";
                }
                $devs[] = $text;
            } elseif (!empty($item['is_substitution']) || !empty($item['is_room_change']) || !empty($item['is_moved'])) {
                $parts = [];
                if (!empty($item['teacher'])) {
                    $parts[] = "Lehrer: {$item['teacher']}";
                }
                if (!empty($item['room'])) {
                    $parts[] = "Raum: {$item['room']}";
                }
                $desc = !empty($parts) ? ' [' . implode(', ', $parts) . ']' : '';
                $text = "{$st}. Stunde {$subj}{$desc}";
                if ($info !== '') {
                    $text .= " — {$info}";
                }
                $devs[] = $text;
            }
        }

        return $devs;
    }

    /**
     * Erzeugt eine umgangssprachliche Datumsbezeichnung (heute, morgen, Montag, etc.).
     */
    public function formatDateLabel(string $date): string
    {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        if ($date === $today) {
            return 'heute';
        }
        if ($date === $tomorrow) {
            return 'morgen';
        }

        $dt = new DateTimeImmutable($date);
        $wDays = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        $wDayName = $wDays[(int)$dt->format('w')];

        return "am {$wDayName} ({$dt->format('d.m.')})";
    }

    public function getPlanRepository(): SchoolPlanRepository
    {
        return $this->planRepo;
    }

    public function getStudentRepository(): SchoolStudentRepository
    {
        return $this->studentRepo;
    }
}
