<?php

namespace Kai\Tools\System;

use DateTimeImmutable;
use Kai\Tools\Bank\BankAccountRepository;
use Kai\Tools\Bank\BankContractRepository;
use Kai\Tools\Calendar\CalendarService;
use Kai\Tools\Car\VehicleDashboardRepository;
use Kai\Tools\Einkaufsliste\ShoppingListRepository;
use Kai\Tools\PVCharge\PvForecastRepository;
use Kai\Tools\School\BesteSchuleRepository;
use Kai\Tools\School\SchoolService;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Weather\WeatherEvaluator;
use Kai\Tools\Weather\WeatherService;
use Throwable;

/**
 * Zentraler Aggregator für das personalisierte Daily-Briefing-Popup beim Start des Toolsets.
 *
 * Fragt die Daten der angebundenen Domänen sequenziell ab, filtert Ruhezeiten und Leerzustände
 * und liefert ein bereinigtes Array der handlungsrelevanten Widgets.
 */
class BriefingService
{
    private Database $db;
    private Logger $logger;
    private UserProfileRepository $userProfileRepo;
    private PermissionService $permissionService;
    private WeatherService $weatherService;
    private WeatherEvaluator $weatherEvaluator;
    private SchoolService $schoolService;
    private BesteSchuleRepository $besteSchuleRepo;
    private PvForecastRepository $pvForecastRepo;
    private VehicleDashboardRepository $vehicleDashboardRepo;
    private ShoppingListRepository $shoppingListRepo;
    private CalendarService $calendarService;
    private BankAccountRepository $bankAccountRepo;
    private BankContractRepository $bankContractRepo;

    public function __construct(
        ?Database $db = null,
        ?Logger $logger = null,
        ?UserProfileRepository $userProfileRepo = null,
        ?PermissionService $permissionService = null,
        ?WeatherService $weatherService = null,
        ?WeatherEvaluator $weatherEvaluator = null,
        ?SchoolService $schoolService = null,
        ?BesteSchuleRepository $besteSchuleRepo = null,
        ?PvForecastRepository $pvForecastRepo = null,
        ?VehicleDashboardRepository $vehicleDashboardRepo = null,
        ?ShoppingListRepository $shoppingListRepo = null,
        ?CalendarService $calendarService = null,
        ?BankAccountRepository $bankAccountRepo = null,
        ?BankContractRepository $bankContractRepo = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->logger = $logger ?? new Logger();
        $this->userProfileRepo = $userProfileRepo ?? new UserProfileRepository($this->db);
        $this->permissionService = $permissionService ?? new PermissionService($this->db);
        $this->weatherService = $weatherService ?? new WeatherService();
        $this->weatherEvaluator = $weatherEvaluator ?? new WeatherEvaluator();
        $this->schoolService = $schoolService ?? new SchoolService();
        $this->besteSchuleRepo = $besteSchuleRepo ?? new BesteSchuleRepository();
        $this->pvForecastRepo = $pvForecastRepo ?? new PvForecastRepository();
        $this->vehicleDashboardRepo = $vehicleDashboardRepo ?? new VehicleDashboardRepository();
        $this->shoppingListRepo = $shoppingListRepo ?? new ShoppingListRepository();
        $this->calendarService = $calendarService ?? new CalendarService($this->db);
        $this->bankAccountRepo = $bankAccountRepo ?? new BankAccountRepository();
        $this->bankContractRepo = $bankContractRepo ?? new BankContractRepository();
    }

    /**
     * Aggregiert alle aktiven und nicht gefilterten Widgets für einen bestimmten Benutzer.
     *
     * @return array{user_email: string, generated_at: string, widgets: array<int, array<string, mixed>>}
     */
    public function getBriefingForUser(string $userEmail): array
    {
        $this->userProfileRepo->ensureProfileExists($userEmail);
        $preferences = $this->userProfileRepo->getBriefingPreferences($userEmail);

        $widgets = [];

        foreach ($preferences as $widgetKey => $config) {
            if (empty($config['enabled'])) {
                continue;
            }

            $widgetMeta = UserProfileRepository::BRIEFING_WIDGET_CONFIG[$widgetKey] ?? null;
            if (!$widgetMeta) {
                continue;
            }

            // Berechtigung des Benutzers prüfen
            if (!empty($widgetMeta['permission']) && !$this->permissionService->userHasPermission($userEmail, $widgetMeta['permission'])) {
                continue;
            }

            try {
                $widgetData = match ($widgetKey) {
                    'weather' => $this->buildWeatherWidget(),
                    'school' => $this->buildSchoolWidget($userEmail),
                    'pv_car' => $this->buildPvCarWidget(),
                    'shopping' => $this->buildShoppingWidget(),
                    'calendar' => $this->buildCalendarWidget($userEmail),
                    'finance' => $this->buildFinanceWidget($userEmail),
                    default => null,
                };

                if ($widgetData !== null) {
                    $widgetData['order'] = $config['order'] ?? 99;
                    $widgets[] = $widgetData;
                }
            } catch (Throwable $e) {
                // Teilausfall eines Widgets isolieren
                $this->logger->error("BriefingService: Fehler beim Erzeugen des Widgets '{$widgetKey}'.", [
                    'user' => $userEmail,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Nach konfigurierter Reihenfolge sortieren
        usort($widgets, static fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        $popupEnabled = $this->userProfileRepo->isBriefingPopupEnabled($userEmail);
        $cooldownHours = $this->userProfileRepo->getBriefingCooldownHours($userEmail);

        return [
            'user_email' => $userEmail,
            'generated_at' => date('c'),
            'popup_enabled' => $popupEnabled,
            'cooldown_hours' => $cooldownHours,
            'widgets' => array_values($widgets),
        ];
    }

    /**
     * Speichert aktualisierte Briefing-Einstellungen für einen Benutzer.
     */
    public function savePreferencesForUser(
        string $userEmail,
        array $preferences,
        ?bool $popupEnabled = null,
        ?int $cooldownHours = null
    ): void {
        $this->userProfileRepo->updateBriefingPreferences($userEmail, $preferences, $popupEnabled, $cooldownHours);
    }

    // =========================================================================
    // WIDGET-GENERATOREN
    // =========================================================================

    /**
     * 2.1. Wetter & Bekleidung
     * - Betrachtung der kommenden 6 Stunden ab Aufruf
     * - Piktogramme: Schirm bei Regen, Jacke/Mütze bei Kälte/Wind, T-Shirt/Sonnenbrille bei Wärme
     * - Temperaturspanne (Min / Max)
     */
    private function buildWeatherWidget(): ?array
    {
        $forecast = $this->weatherService->getForecastFromDb();
        if ($forecast === null) {
            return null;
        }

        $sensorData = $this->weatherService->getLatestSensorData();
        $currentTemp = $forecast['current']['temperature_2m'] ?? null;
        if ($sensorData && isset($sensorData['temperature_c'])) {
            $currentTemp = (float)$sensorData['temperature_c'];
        }

        $hourlyTime = $forecast['hourly']['time'] ?? [];
        $hourlyTemp = $forecast['hourly']['temperature_2m'] ?? [];
        $hourlyPrecipProb = $forecast['hourly']['precipitation_probability'] ?? [];
        $hourlyPrecip = $forecast['hourly']['precipitation'] ?? [];
        $hourlyWind = $forecast['hourly']['wind_speed_10m'] ?? [];

        $now = time();
        $windowEnd = $now + (6 * 3600); // 6 Stunden Betrachtungsfenster

        $minTemp = null;
        $maxTemp = null;
        $maxPrecipProb = 0;
        $maxPrecipSum = 0.0;
        $maxWind = 0.0;

        for ($i = 0; $i < count($hourlyTime); $i++) {
            $t = strtotime($hourlyTime[$i]);
            if ($t >= $now && $t <= $windowEnd) {
                $temp = isset($hourlyTemp[$i]) ? (float)$hourlyTemp[$i] : null;
                if ($temp !== null) {
                    $minTemp = ($minTemp === null) ? $temp : min($minTemp, $temp);
                    $maxTemp = ($maxTemp === null) ? $temp : max($maxTemp, $temp);
                }
                if (isset($hourlyPrecipProb[$i])) {
                    $maxPrecipProb = max($maxPrecipProb, (int)$hourlyPrecipProb[$i]);
                }
                if (isset($hourlyPrecip[$i])) {
                    $maxPrecipSum = max($maxPrecipSum, (float)$hourlyPrecip[$i]);
                }
                if (isset($hourlyWind[$i])) {
                    $maxWind = max($maxWind, (float)$hourlyWind[$i]);
                }
            }
        }

        if ($minTemp === null) {
            $minTemp = $currentTemp ?? 15.0;
            $maxTemp = $currentTemp ?? 15.0;
        }

        // Piktogramm & Bekleidungsempfehlung
        $hasRain = ($maxPrecipProb > 40 || $maxPrecipSum > 0.5);
        $windchill = $minTemp;
        if ($maxWind > 20) {
            $windchill -= 2;
        }

        $clothingIcon = '👕';
        $clothingText = 'T-Shirt Wetter';

        if ($windchill < 5) {
            $clothingIcon = '🧣';
            $clothingText = 'Mütze & Schal nötig';
        } elseif ($windchill < 15) {
            $clothingIcon = '🧥';
            $clothingText = 'Jacke empfohlen';
        } elseif ($maxTemp >= 22) {
            $clothingIcon = '🕶️';
            $clothingText = 'Heiter & sonnig';
        }

        $minFormatted = round($minTemp);
        $maxFormatted = round($maxTemp);
        $curFormatted = $currentTemp !== null ? round($currentTemp) . '°C' : "{$minFormatted}°–{$maxFormatted}°C";

        $pills = [];
        if ($hasRain) {
            $pills[] = [
                'icon' => '☂️',
                'label' => "Regen ({$maxPrecipProb}%)",
                'type' => 'warning',
            ];
        } else {
            $pills[] = [
                'icon' => '☀️',
                'label' => 'Trocken',
                'type' => 'success',
            ];
        }

        $pills[] = [
            'icon' => $clothingIcon,
            'label' => $clothingText,
            'type' => 'neutral',
        ];

        return [
            'key' => 'weather',
            'title' => 'Wetter & Bekleidung',
            'icon' => '🌤️',
            'url' => '/weather/index.php',
            'headline' => "{$curFormatted} (6h: {$minFormatted}° bis {$maxFormatted}°C)",
            'subtitle' => $hasRain ? "Regenschirm empfohlen ({$maxPrecipProb}% Regenwahrscheinlichkeit)" : "In den nächsten 6h kein Regen erwartet",
            'pills' => $pills,
            'highlight' => $hasRain,
            'badge' => $hasRain ? ['text' => 'Regen', 'type' => 'warning'] : null,
        ];
    }

    /**
     * 2.2. Schule & Aufgaben
     * - Ruhezeiten: Fr ab 13:00 bis So 12:00 Uhr sowie Feiertage & Schulferien in SN
     * - Vormittagsmodus (bis 15:00 Uhr): Unterrichtsende heute, Fächerzahl, Ausfälle/Vertretungen
     * - Nachmittagsmodus (ab 15:00 Uhr): Offene Hausaufgaben & Leistungskontrollen für den Folgetag
     */
    private function buildSchoolWidget(string $userEmail): ?array
    {
        $now = new DateTimeImmutable();
        $dayOfWeek = (int)$now->format('N'); // 1 = Mo, 7 = So
        $hour = (int)$now->format('G');

        // Ruhezeiten: Fr ab 13:00 Uhr bis Sonntag 12:00 Uhr
        if ($dayOfWeek === 5 && $hour >= 13) {
            return null;
        }
        if ($dayOfWeek === 6) {
            return null;
        }
        if ($dayOfWeek === 7 && $hour < 12) {
            return null;
        }

        // Ganztägig an Feiertagen und während der sächsischen Schulferien
        if ($this->isSaxonyHoliday($now)) {
            return null;
        }
        if ($this->isSchoolHoliday($now->format('Y-m-d'))) {
            return null;
        }

        $studentRepo = $this->schoolService->getStudentRepository();
        $matchedStudent = $studentRepo->getByEmail($userEmail);
        $allStudents = $studentRepo->getActive();

        if (empty($allStudents)) {
            return null;
        }

        $relevantStudents = $matchedStudent ? [$matchedStudent] : $allStudents;
        $studentIds = array_map(static fn($s) => (int)$s['id'], $relevantStudents);

        $isAfternoonMode = ($hour >= 15);

        if (!$isAfternoonMode) {
            // VORMITTAGSMODUS: Aktueller Tag (Unterrichtsende, Ausfälle, Vertretungen)
            $today = $now->format('Y-m-d');
            $schedules = [];
            $totalLessons = 0;
            $cancelledTotal = 0;
            $substitutionTotal = 0;
            $endTimes = [];

            foreach ($relevantStudents as $st) {
                $sched = $this->schoolService->getStudentSchedule((int)$st['id'], $today);
                if ($sched['has_plan']) {
                    $schedules[] = $sched;
                    $totalLessons += (int)($sched['total_lessons'] ?? 0);
                    $cancelledTotal += (int)($sched['cancelled_count'] ?? 0);
                    $substitutionTotal += (int)($sched['substitution_count'] ?? 0);
                    if (!empty($sched['end_time'])) {
                        $endTimes[] = "{$st['name']}: {$sched['end_time']} Uhr";
                    }
                }
            }

            if (empty($schedules)) {
                return null;
            }

            $hasDeviations = ($cancelledTotal > 0 || $substitutionTotal > 0);
            $headline = !empty($endTimes) ? implode(', ', $endTimes) : 'Schulschluss planmäßig';

            $subtitleParts = [];
            if ($cancelledTotal > 0) {
                $subtitleParts[] = "{$cancelledTotal} Ausfall";
            }
            if ($substitutionTotal > 0) {
                $subtitleParts[] = "{$substitutionTotal} Änderung(en)";
            }
            if (empty($subtitleParts)) {
                $subtitleParts[] = 'Planmäßiger Unterricht';
            }

            $pills = [];
            if ($cancelledTotal > 0) {
                $pills[] = ['icon' => '❌', 'label' => "{$cancelledTotal} Ausfall", 'type' => 'danger'];
            }
            if ($substitutionTotal > 0) {
                $pills[] = ['icon' => '🔄', 'label' => "{$substitutionTotal} Vertretung", 'type' => 'warning'];
            }
            if (!$hasDeviations) {
                $pills[] = ['icon' => '✅', 'label' => 'Alles nach Plan', 'type' => 'success'];
            }

            return [
                'key' => 'school',
                'title' => 'Schule & Stundenplan',
                'icon' => '🎒',
                'url' => '/school/index.php',
                'headline' => $headline,
                'subtitle' => implode(' • ', $subtitleParts),
                'pills' => $pills,
                'highlight' => $hasDeviations,
                'badge' => $cancelledTotal > 0 ? ['text' => 'Ausfall', 'type' => 'danger'] : ($substitutionTotal > 0 ? ['text' => 'Änderung', 'type' => 'warning'] : null),
            ];
        }

        // NACHMITTAGSMODUS: Folgetag (Hausaufgaben & anstehende Leistungskontrollen)
        $nextDate = $this->schoolService->determineEffectiveDate(null);
        $upcomingNotes = $this->besteSchuleRepo->getUpcomingNotes($studentIds, $nextDate);

        // Filtern nach Notizen am Folgetag
        $targetNotes = array_filter($upcomingNotes, static fn($n) => ($n['lesson_date'] ?? '') === $nextDate);

        $homeworkCount = 0;
        $exams = [];

        foreach ($targetNotes as $note) {
            $typeName = mb_strtolower((string)($note['type_name'] ?? ''));
            if (str_contains($typeName, 'hausaufgabe') || str_contains($typeName, 'ha')) {
                $homeworkCount++;
            } elseif (
                str_contains($typeName, 'klassenarbeit')
                || str_contains($typeName, 'klausur')
                || str_contains($typeName, 'test')
                || str_contains($typeName, 'kontrolle')
                || str_contains($typeName, 'leistung')
            ) {
                $subj = $note['subject'] ?? 'Fach';
                $exams[] = "{$note['type_name']} in {$subj}";
            }
        }

        $hasExams = !empty($exams);
        $nextDayLabel = $this->schoolService->formatDateLabel($nextDate);

        $headline = "Morgen ({$nextDayLabel}): ";
        if ($hasExams) {
            $headline .= implode(', ', array_slice($exams, 0, 2));
        } elseif ($homeworkCount > 0) {
            $headline .= "{$homeworkCount} Hausaufgabe(n) auf";
        } else {
            $headline .= "Keine Tests oder Aufgaben gemeldet";
        }

        $pills = [];
        if ($hasExams) {
            $pills[] = ['icon' => '⚠️', 'label' => count($exams) . ' Test/Arbeit', 'type' => 'danger'];
        }
        if ($homeworkCount > 0) {
            $pills[] = ['icon' => '📝', 'label' => "{$homeworkCount} Hausaufgaben", 'type' => 'warning'];
        }
        if (!$hasExams && $homeworkCount === 0) {
            $pills[] = ['icon' => '✨', 'label' => 'Alles erledigt', 'type' => 'success'];
        }

        return [
            'key' => 'school',
            'title' => 'Schule: Morgen (' . $nextDayLabel . ')',
            'icon' => '🎒',
            'url' => '/school/index.php?view=homework',
            'headline' => $headline,
            'subtitle' => $hasExams ? "Achtung: Leistungsnachweis steht an!" : ($homeworkCount > 0 ? "Offene Hausaufgaben vor dem Schultag prüfen" : "Keine anstehenden Tests"),
            'pills' => $pills,
            'highlight' => $hasExams,
            'badge' => $hasExams ? ['text' => 'Test anstehend', 'type' => 'danger'] : null,
        ];
    }

    /**
     * 2.3. Energie & Fahrzeug (PV-Anlage & ID.Buzz kombiniert)
     * - Verschneidung von Solarprognose und SoC des Fahrzeugs
     * - Lade-Empfehlung bei hohem Ertrag & unvollständiger Ladung
     * - Rotes Warnsymbol bei unverschlossenem Fahrzeug
     */
    private function buildPvCarWidget(): ?array
    {
        $vehicleState = $this->vehicleDashboardRepo->getLatestState();
        $systemBias = $this->pvForecastRepo->getSystemBiasPercent();
        $biasFactor = ($systemBias !== null) ? (1 + $systemBias / 100) : 1.0;

        $todayStr = date('Y-m-d');
        $tomorrowStr = date('Y-m-d', strtotime('+1 day'));

        $todayWh = $this->pvForecastRepo->getForecastForDate($todayStr);
        $tomorrowWh = $this->pvForecastRepo->getForecastForDate($tomorrowStr);

        $pvYieldTodayKwh = $todayWh !== null ? round((($todayWh * $biasFactor) / 1000), 1) : 0.0;
        $pvYieldTomorrowKwh = $tomorrowWh !== null ? round((($tomorrowWh * $biasFactor) / 1000), 1) : 0.0;

        // Prüfen, ob für heute noch Sonnenertrag ansteht (nach Sonnenuntergang / abends = 0)
        $remainingWattsToday = $this->pvForecastRepo->getRemainingTodayWatts();
        $isEveningMode = ($remainingWattsToday <= 0);

        $relevantPvKwh = $isEveningMode ? $pvYieldTomorrowKwh : $pvYieldTodayKwh;

        $soc = isset($vehicleState['soc_percent']) ? (int)$vehicleState['soc_percent'] : 0;
        $rangeKm = isset($vehicleState['range_km']) ? (int)$vehicleState['range_km'] : 0;
        $isLocked = isset($vehicleState['is_locked']) ? (int)$vehicleState['is_locked'] : 1;
        $plugConnected = !empty($vehicleState['plug_connected']);

        $isUnlockedAlert = ($isLocked === 0);
        // Lade-Empfehlung: Hoher PV-Ertrag (>= 12 kWh) und Auto nicht voll (< 80% SoC)
        $chargeRecommendation = ($relevantPvKwh >= 12.0 && $soc < 80);

        $pills = [];
        if ($isUnlockedAlert) {
            $pills[] = ['icon' => '🚨', 'label' => 'Unverschlossen!', 'type' => 'danger'];
        }
        if ($chargeRecommendation) {
            $pills[] = [
                'icon' => '⚡',
                'label' => $isEveningMode ? 'Morgen PV-Laden' : 'Lade-Empfehlung (PV)',
                'type' => 'success',
            ];
        }

        $pvPillLabel = $isEveningMode ? "Morgen: {$pvYieldTomorrowKwh} kWh" : "PV: {$pvYieldTodayKwh} kWh";
        $pills[] = ['icon' => '☀️', 'label' => $pvPillLabel, 'type' => 'neutral'];
        $pills[] = ['icon' => '🚐', 'label' => "SoC: {$soc}% ({$rangeKm} km)", 'type' => 'neutral'];

        $headline = "ID.Buzz: {$soc} % • {$rangeKm} km";

        if ($isUnlockedAlert) {
            $forecastNote = $isEveningMode ? "PV-Prognose morgen: {$pvYieldTomorrowKwh} kWh" : "PV-Prognose heute: {$pvYieldTodayKwh} kWh";
            $subtitle = "⚠️ Achtung: Fahrzeug ist unverschlossen! • {$forecastNote}";
        } elseif ($chargeRecommendation) {
            $subtitle = $isEveningMode
                ? "☀️ Starker PV-Ertrag morgen ({$pvYieldTomorrowKwh} kWh): ID.Buzz tagsüber laden!"
                : "☀️ Starker PV-Ertrag erwartet: Fahrzeug anstecken & Sonnenstrom laden!";
        } else {
            $subtitle = $isEveningMode
                ? "PV-Ertragsprognose morgen: {$pvYieldTomorrowKwh} kWh"
                : "PV-Ertragsprognose heute: {$pvYieldTodayKwh} kWh";
        }

        $badge = null;
        if ($isUnlockedAlert) {
            $badge = ['text' => 'Unverschlossen', 'type' => 'danger'];
        } elseif ($chargeRecommendation) {
            $badge = ['text' => $isEveningMode ? 'Lade-Tipp morgen' : 'Lade-Tipp', 'type' => 'success'];
        }

        return [
            'key' => 'pv_car',
            'title' => 'Energie & ID.Buzz',
            'icon' => '⚡',
            'url' => $isUnlockedAlert ? '/car/index.php' : '/pvcharge/index.php',
            'headline' => $headline,
            'subtitle' => $subtitle,
            'pills' => $pills,
            'highlight' => $isUnlockedAlert || $chargeRecommendation,
            'badge' => $badge,
        ];
    }

    /**
     * 2.4. Einkaufsliste (Dringlichkeits-Fokus)
     * - Nur sichtbar, wenn offene Artikel vorliegen (bleibt bei 0 Posten komplett verborgen)
     * - Prominente Sofortbedarfe (Ad-hoc)
     * - Gesamtzahl Wocheneinkauf nach Rewe & Globus
     */
    private function buildShoppingWidget(): ?array
    {
        $counts = $this->shoppingListRepo->getItemCountsByMarket();
        $totalOpen = (int)($counts['all']['open'] ?? 0);

        if ($totalOpen === 0) {
            return null; // Bei 0 offenen Posten komplett verbergen
        }

        // Offene Sofortbedarfe
        $spontaneousItems = $this->shoppingListRepo->getItems(null, false, 1);
        $spontaneousCount = count($spontaneousItems);

        $reweOpen = (int)($counts['Rewe']['open'] ?? 0);
        $globusOpen = (int)($counts['Globus']['open'] ?? 0);

        $pills = [];
        if ($spontaneousCount > 0) {
            $pills[] = ['icon' => '🔥', 'label' => "{$spontaneousCount} Sofortbedarf", 'type' => 'danger'];
        }
        if ($reweOpen > 0) {
            $pills[] = ['icon' => '🔴', 'label' => "{$reweOpen} Rewe", 'type' => 'neutral'];
        }
        if ($globusOpen > 0) {
            $pills[] = ['icon' => '🟠', 'label' => "{$globusOpen} Globus", 'type' => 'neutral'];
        }

        $headline = "{$totalOpen} offene Position(en)";
        if ($spontaneousCount > 0) {
            $sampleNames = array_slice(array_map(static fn($i) => $i['name'], $spontaneousItems), 0, 2);
            $sampleStr = implode(', ', $sampleNames);
            $headline = "⚡ Sofortbedarf: {$sampleStr}" . ($spontaneousCount > 2 ? ' ...' : '');
        }

        return [
            'key' => 'shopping',
            'title' => 'Einkaufsliste',
            'icon' => '🛒',
            'url' => '/einkaufsliste/index.php',
            'headline' => $headline,
            'subtitle' => "Insgesamt {$totalOpen} Artikel offen (Rewe: {$reweOpen}, Globus: {$globusOpen})",
            'pills' => $pills,
            'highlight' => $spontaneousCount > 0,
            'badge' => $spontaneousCount > 0 ? ['text' => 'Sofortbedarf', 'type' => 'danger'] : ['text' => "{$totalOpen} offen", 'type' => 'info'],
        ];
    }

    /**
     * 2.5. Jubiläen & Geburtstage
     * - Nur aktiv, wenn calendar_reminder aktiviert ist
     * - Nur sichtbar bei Ereignissen in den nächsten 3 Tagen (heute, morgen, übermorgen)
     * - Besondere Hervorhebung bei Ereignissen am heutigen Kalendertag
     */
    private function buildCalendarWidget(string $userEmail): ?array
    {
        $prefs = $this->userProfileRepo->getPreferences($userEmail);
        if (isset($prefs['calendar_reminder']) && !$prefs['calendar_reminder']) {
            return null;
        }

        // Ereignisse innerhalb der nächsten 2 Tage ab heute (0=heute, 1=morgen, 2=übermorgen)
        $upcoming = $this->calendarService->getUpcomingEvents(2, $userEmail);
        if (empty($upcoming)) {
            return null; // Strikt bedarfsgesteuert: keine Ereignisse -> ausblenden
        }

        $todayEvents = array_filter($upcoming, static fn($e) => ($e['days_remaining'] ?? null) === 0);
        $hasToday = !empty($todayEvents);

        $titles = [];
        foreach (array_slice($upcoming, 0, 3) as $ev) {
            $prefix = match ($ev['days_remaining']) {
                0 => 'Heute 🎉: ',
                1 => 'Morgen: ',
                default => 'In 2 Tagen: ',
            };
            $ageStr = !empty($ev['age_text']) ? " ({$ev['age_text']})" : '';
            $titles[] = "{$prefix}{$ev['title']}{$ageStr}";
        }

        $pills = [];
        if ($hasToday) {
            $pills[] = ['icon' => '🎉', 'label' => 'Heute Jubiläum!', 'type' => 'success'];
        }
        foreach ($upcoming as $ev) {
            $pills[] = [
                'icon' => $ev['event_type'] === 'anniversary' ? '💍' : '🎂',
                'label' => $ev['title'],
                'type' => $ev['days_remaining'] === 0 ? 'success' : 'neutral',
            ];
        }

        return [
            'key' => 'calendar',
            'title' => 'Geburtstage & Jubiläen',
            'icon' => '🎉',
            'url' => '/calendar/index.php',
            'headline' => implode(' • ', $titles),
            'subtitle' => $hasToday ? 'Besonderes Ereignis am heutigen Tag! 🥂' : count($upcoming) . ' anstehende(s) Ereignis(se) in den nächsten 3 Tagen',
            'pills' => $pills,
            'highlight' => $hasToday,
            'badge' => $hasToday ? ['text' => 'Heute! 🎉', 'type' => 'success'] : ['text' => 'In Kürze', 'type' => 'warning'],
        ];
    }

    /**
     * 2.6. Finanzen
     * - Berechtigungsabhängig für Profile mit finance_read
     * - Gesamtsaldo des Girokontos
     * - Kumulierte Summe der anstehenden Ein- und Ausgänge der nächsten 3 Tage
     */
    private function buildFinanceWidget(string $userEmail): ?array
    {
        if (!$this->permissionService->userHasPermission($userEmail, 'finance_read')) {
            return null;
        }

        $account = $this->bankAccountRepo->getAccountByType('checking');
        $currentBalance = (float)($account['current_balance'] ?? 0.0);

        // Anstehende Buchungen der nächsten 3 Tage
        $upcomingTx = $this->bankContractRepo->getUpcomingExpectedTransactions(3);

        $pendingExpenses = 0.0;
        $pendingIncome = 0.0;
        $pendingCount = 0;

        foreach ($upcomingTx as $tx) {
            if (empty($tx['is_booked'])) {
                $pendingCount++;
                $amount = (float)($tx['betrag'] ?? 0.0);
                if (($tx['direction'] ?? 'expense') === 'expense') {
                    $pendingExpenses += $amount;
                } else {
                    $pendingIncome += $amount;
                }
            }
        }

        $balanceFormatted = number_format($currentBalance, 2, ',', '.') . ' €';
        $expFormatted = number_format($pendingExpenses, 2, ',', '.') . ' €';
        $incFormatted = number_format($pendingIncome, 2, ',', '.') . ' €';

        $pills = [
            ['icon' => '🏦', 'label' => "Saldo: {$balanceFormatted}", 'type' => 'neutral'],
        ];
        if ($pendingExpenses > 0) {
            $pills[] = ['icon' => '📉', 'label' => "Fixkosten 3 Tage: -{$expFormatted}", 'type' => 'warning'];
        }
        if ($pendingIncome > 0) {
            $pills[] = ['icon' => '📈', 'label' => "Eingänge 3 Tage: +{$incFormatted}", 'type' => 'success'];
        }

        $subtitle = ($pendingCount > 0)
            ? "{$pendingCount} Fixkosten/Verträge in den nächsten 3 Tagen erwartet (-{$expFormatted})"
            : "Keine offenen Vertragskosten in den nächsten 3 Tagen";

        return [
            'key' => 'finance',
            'title' => 'Finanzen',
            'icon' => '🏦',
            'url' => '/bank/index.php',
            'headline' => "Girokonto-Saldo: {$balanceFormatted}",
            'subtitle' => $subtitle,
            'pills' => $pills,
            'highlight' => false,
            'badge' => $pendingCount > 0 ? ['text' => "{$pendingCount} anstehend", 'type' => 'info'] : null,
        ];
    }

    // =========================================================================
    // HILFSMETHODEN (Feiertage Sachsen & Ferien)
    // =========================================================================

    /**
     * Prüft, ob ein gegebenes Datum ein gesetzlicher Feiertag im Freistaat Sachsen ist.
     */
    public function isSaxonyHoliday(DateTimeImmutable $date): bool
    {
        $year = (int)$date->format('Y');
        $dateStr = $date->format('Y-m-d');

        $holidays = [
            sprintf('%04d-01-01', $year), // Neujahr
            sprintf('%04d-05-01', $year), // Tag der Arbeit
            sprintf('%04d-10-03', $year), // Tag der Deutschen Einheit
            sprintf('%04d-10-31', $year), // Reformationstag (Sachsen)
            sprintf('%04d-12-25', $year), // 1. Weihnachtstag
            sprintf('%04d-12-26', $year), // 2. Weihnachtstag
        ];

        // Bewegliche Osterfeiertage
        $easterTimestamp = easter_date($year);
        $easter = (new DateTimeImmutable())->setTimestamp($easterTimestamp);

        $holidays[] = $easter->modify('-2 days')->format('Y-m-d');  // Karfreitag
        $holidays[] = $easter->modify('+1 day')->format('Y-m-d');   // Ostermontag
        $holidays[] = $easter->modify('+39 days')->format('Y-m-d'); // Christi Himmelfahrt
        $holidays[] = $easter->modify('+50 days')->format('Y-m-d'); // Pfingstmontag

        // Buß- und Bettag: Letzter Mittwoch vor dem 23. November (nur in Sachsen gesetzlicher Feiertag)
        $nov23 = new DateTimeImmutable(sprintf('%04d-11-23', $year));
        $bussUndBettag = $nov23->modify('previous wednesday');
        $holidays[] = $bussUndBettag->format('Y-m-d');

        return in_array($dateStr, $holidays, true);
    }

    /**
     * Prüft, ob ein gegebenes Datum in die sächsischen Schulferien fällt.
     */
    public function isSchoolHoliday(string $dateStr): bool
    {
        try {
            $stmt = $this->db->getConnection()->prepare("
                SELECT 1 FROM school_holidays 
                WHERE state_code = 'SN' AND :date BETWEEN start_date AND end_date 
                LIMIT 1
            ");
            $stmt->execute([':date' => $dateStr]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
