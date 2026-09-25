<?php

namespace Kai\Tools\Calendar;

use DateTimeImmutable;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Push\PushSubscriptionRepository;
use Kai\Tools\Shared\Push\WebPushService;
use Kai\Tools\System\PermissionService;
use Kai\Tools\System\UserProfileRepository;
use PDO;

class CalendarService
{
    private Database $db;
    private Logger $logger;
    private CalendarEventRepository $eventRepo;
    private ?WebPushService $webPushService;
    private PermissionService $permissionService;
    private UserProfileRepository $userProfileRepo;

    public const MONTH_NAMES_DE = [
        1 => 'Januar',
        2 => 'Februar',
        3 => 'März',
        4 => 'April',
        5 => 'Mai',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'August',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Dezember',
    ];

    public function __construct(
        ?Database $db = null,
        ?Logger $logger = null,
        ?CalendarEventRepository $eventRepo = null,
        ?WebPushService $webPushService = null,
        ?PermissionService $permissionService = null,
        ?UserProfileRepository $userProfileRepo = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->logger = $logger ?? new Logger();
        $this->eventRepo = $eventRepo ?? new CalendarEventRepository($this->db);
        $this->permissionService = $permissionService ?? new PermissionService($this->db);
        $this->userProfileRepo = $userProfileRepo ?? new UserProfileRepository($this->db);

        if ($webPushService !== null) {
            $this->webPushService = $webPushService;
        } else {
            $subscriptionRepo = new PushSubscriptionRepository($this->db);
            $this->webPushService = new WebPushService($subscriptionRepo, $this->logger);
        }
    }

    public function getEventRepository(): CalendarEventRepository
    {
        return $this->eventRepo;
    }

    /**
     * Berechnet die dynamischen Datums-, Jubiläums- und Countdown-Details eines Ereignisses.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function calculateEventDetails(array $event, ?DateTimeImmutable $referenceDate = null): array
    {
        $today = $referenceDate ?? new DateTimeImmutable('today');
        $currentYear = (int)$today->format('Y');

        $month = (int)$event['event_month'];
        $day = (int)$event['event_day'];
        $year = !empty($event['event_year']) ? (int)$event['event_year'] : null;

        // Schalttag-Handling (29. Februar)
        $actualDay = $day;
        if ($month === 2 && $day === 29 && !checkdate(2, 29, $currentYear)) {
            $actualDay = 28;
        }

        $candidateDate = (new DateTimeImmutable())->setDate($currentYear, $month, $actualDay)->setTime(0, 0, 0);

        if ($candidateDate < $today) {
            $targetYear = $currentYear + 1;
            $nextActualDay = $day;
            if ($month === 2 && $day === 29 && !checkdate(2, 29, $targetYear)) {
                $nextActualDay = 28;
            }
            $nextDate = (new DateTimeImmutable())->setDate($targetYear, $month, $nextActualDay)->setTime(0, 0, 0);
        } else {
            $targetYear = $currentYear;
            $nextDate = $candidateDate;
        }

        $diff = $today->diff($nextDate);
        $daysRemaining = (int)$diff->format('%r%a');

        // Alter / Jubiläums-Jahre berechnen
        $nextAge = null;
        $ageText = null;
        if ($year !== null) {
            $nextAge = $targetYear - $year;
            $type = $event['event_type'] ?? 'birthday';
            if ($type === 'birthday') {
                $ageText = $daysRemaining === 0 ? "wird heute {$nextAge} Jahre" : "wird {$nextAge} Jahre";
            } elseif ($type === 'anniversary') {
                $ageText = "{$nextAge}. Jubiläum ({$nextAge} Jahre)";
            } elseif ($type === 'memorial') {
                $ageText = "{$nextAge}. Gedenkjahr";
            } else {
                $ageText = "{$nextAge} Jahre";
            }
        }

        // Countdown-Badge-Text
        $badgeText = match ($daysRemaining) {
            0 => 'Heute! 🎉',
            1 => 'Morgen',
            2 => 'Übermorgen',
            default => "in {$daysRemaining} Tagen",
        };

        // Vorlauftage als Array von Integern
        $rawAdvance = !empty($event['notify_days_advance']) ? explode(',', (string)$event['notify_days_advance']) : ['0', '1', '3'];
        $advanceDaysList = array_map('intval', array_filter(array_map('trim', $rawAdvance), 'is_numeric'));

        // Sternzeichen ermitteln
        $westernZodiac = $this->getWesternZodiac($day, $month);
        $chineseZodiac = $this->getChineseZodiac($year, $month, $day);

        $event['next_date'] = $nextDate->format('Y-m-d');
        $event['target_year'] = $targetYear;
        $event['days_remaining'] = $daysRemaining;
        $event['badge_text'] = $badgeText;
        $event['next_age'] = $nextAge;
        $event['age_text'] = $ageText;
        $event['advance_days_list'] = $advanceDaysList;
        $event['zodiac'] = $westernZodiac;
        $event['western_zodiac'] = $westernZodiac;
        $event['chinese_zodiac'] = $chineseZodiac;
        $event['formatted_day_month'] = sprintf('%02d.%02d.', $day, $month);

        return $event;
    }

    /**
     * Ermittelt anstehende Ereignisse innerhalb der nächsten $daysAhead Tage.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUpcomingEvents(int $daysAhead = 30, ?string $forUserEmail = null): array
    {
        $allEvents = $this->eventRepo->getAll($forUserEmail);
        $upcoming = [];

        foreach ($allEvents as $event) {
            $calculated = $this->calculateEventDetails($event);
            if ($calculated['days_remaining'] <= $daysAhead) {
                $upcoming[] = $calculated;
            }
        }

        usort($upcoming, function ($a, $b) {
            return $a['days_remaining'] <=> $b['days_remaining'];
        });

        return $upcoming;
    }

    /**
     * Gruppiert alle Ereignisse nach Monaten (1 bis 12).
     *
     * @return array<int, array{month: int, month_name: string, events: array<int, array<string, mixed>>}>
     */
    public function getEventsGroupedByMonth(
        ?string $forUserEmail = null,
        ?string $category = null,
        ?string $eventType = null,
        ?string $search = null
    ): array {
        $events = $this->eventRepo->getAll($forUserEmail, $category, $eventType, $search);

        $grouped = [];
        for ($m = 1; $m <= 12; $m++) {
            $grouped[$m] = [
                'month' => $m,
                'month_name' => self::MONTH_NAMES_DE[$m],
                'events' => [],
            ];
        }

        foreach ($events as $event) {
            $calculated = $this->calculateEventDetails($event);
            $month = (int)$event['event_month'];
            if (isset($grouped[$month])) {
                $grouped[$month]['events'][] = $calculated;
            }
        }

        // Innerhalb der Monate nach Tag sortieren
        foreach ($grouped as &$monthData) {
            usort($monthData['events'], function ($a, $b) {
                return $a['event_day'] <=> $b['event_day'];
            });
        }
        unset($monthData);

        return $grouped;
    }

    /**
     * Prüft alle Ereignisse und versendet fällige Benachrichtigungen (für Cronjob).
     *
     * @return int Anzahl der versendeten Benachrichtigungen
     */
    public function processDueReminders(): int
    {
        $allEvents = $this->eventRepo->getAll();
        $today = new DateTimeImmutable('today');
        $sentCount = 0;

        foreach ($allEvents as $event) {
            $details = $this->calculateEventDetails($event, $today);
            $daysRemaining = $details['days_remaining'];
            $advanceDaysList = $details['advance_days_list'];
            $targetYear = $details['target_year'];
            $eventId = (int)$event['id'];

            // Prüfen, ob für heute ein Vorlauftag fällig ist
            if (!in_array($daysRemaining, $advanceDaysList, true)) {
                continue;
            }

            // Empfänger für dieses Ereignis ermitteln
            $recipientEmails = $this->eventRepo->getRecipients($eventId);
            if (empty($recipientEmails)) {
                continue;
            }

            $notificationContent = $this->buildNotificationMessage($details, $daysRemaining);

            foreach ($recipientEmails as $userEmail) {
                $userEmail = strtolower(trim($userEmail));
                if (empty($userEmail)) {
                    continue;
                }

                // 1. Hat der Nutzer calendar_read?
                if (!$this->permissionService->userHasPermission($userEmail, 'calendar_read')) {
                    continue;
                }

                // 2. Hat der Nutzer Benachrichtigungen für calendar_reminder aktiv?
                $prefs = $this->userProfileRepo->getPreferences($userEmail);
                if (isset($prefs['calendar_reminder']) && !$prefs['calendar_reminder']) {
                    continue;
                }

                // 3. Wurde für dieses Event, Zieljahr und diesen Vorlauftag schon versendet?
                if ($this->eventRepo->hasNotificationBeenSent($eventId, $userEmail, $targetYear, $daysRemaining)) {
                    continue;
                }

                // 4. Web-Push versenden
                try {
                    $this->webPushService->sendToUser(
                        $userEmail,
                        $notificationContent['title'],
                        $notificationContent['body'],
                        '/calendar/index.php'
                    );

                    $this->eventRepo->recordNotificationSent($eventId, $userEmail, $targetYear, $daysRemaining);
                    $sentCount++;

                    $this->logger->info("CalendarService: Benachrichtigung versendet.", [
                        'event_id' => $eventId,
                        'title' => $event['title'],
                        'recipient' => $userEmail,
                        'days_advance' => $daysRemaining,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error("CalendarService: Fehler beim Senden des Push-Reminders.", [
                        'event_id' => $eventId,
                        'recipient' => $userEmail,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($sentCount > 0) {
                $this->logToActivityStream($event, $notificationContent['body'], $eventId);
            }
        }

        return $sentCount;
    }

    /**
     * Sendet eine Test-Benachrichtigung an den aktuellen Benutzer.
     *
     * @return array{success: bool, message: string}
     */
    public function sendTestNotification(int $eventId, string $userEmail): array
    {
        $event = $this->eventRepo->getById($eventId);
        if (!$event) {
            return ['success' => false, 'message' => 'Ereignis nicht gefunden.'];
        }

        $details = $this->calculateEventDetails($event);
        $content = $this->buildNotificationMessage($details, $details['days_remaining']);

        try {
            $this->webPushService->sendToUser(
                $userEmail,
                '[TEST] ' . $content['title'],
                $content['body'],
                '/calendar/index.php'
            );
            return ['success' => true, 'message' => 'Test-Benachrichtigung erfolgreich abgesendet.'];
        } catch (\Throwable $e) {
            $this->logger->error("CalendarService: Test-Benachrichtigung fehlgeschlagen.", ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Fehler beim Senden der Benachrichtigung: ' . $e->getMessage()];
        }
    }

    /**
     * Baut Titel und Text für die Benachrichtigung zusammen.
     *
     * @param array<string, mixed> $details
     * @return array{title: string, body: string}
     */
    public function buildNotificationMessage(array $details, int $daysRemaining): array
    {
        $type = $details['event_type'] ?? 'birthday';
        $titleName = $details['title'];
        $ageText = $details['age_text'] ? " ({$details['age_text']})" : '';

        if ($type === 'birthday') {
            if ($daysRemaining === 0) {
                $title = "🎂 Geburtstag heute!";
                $body = "{$titleName} hat heute Geburtstag!{$ageText} 🎉";
            } elseif ($daysRemaining === 1) {
                $title = "🎂 Geburtstag morgen";
                $body = "Morgen hat {$titleName} Geburtstag!{$ageText}";
            } else {
                $title = "🎂 Erinnerung an Geburtstag";
                $body = "In {$daysRemaining} Tagen: Geburtstag von {$titleName}{$ageText}";
            }
        } elseif ($type === 'anniversary') {
            if ($daysRemaining === 0) {
                $title = "💍 Jahrestag heute!";
                $body = "Heute: {$titleName}!{$ageText} 🥂";
            } elseif ($daysRemaining === 1) {
                $title = "💍 Jahrestag morgen";
                $body = "Morgen: {$titleName}{$ageText}";
            } else {
                $title = "💍 Jahrestags-Erinnerung";
                $body = "In {$daysRemaining} Tagen: {$titleName}{$ageText}";
            }
        } elseif ($type === 'memorial') {
            if ($daysRemaining === 0) {
                $title = "🕯️ Gedenktag heute";
                $body = "Heute: Gedenktag an {$titleName}{$ageText}";
            } else {
                $title = "🕯️ Gedenktags-Erinnerung";
                $body = "In {$daysRemaining} Tagen: Gedenktag an {$titleName}{$ageText}";
            }
        } else {
            if ($daysRemaining === 0) {
                $title = "📅 Ereignis heute";
                $body = "Heute: {$titleName}{$ageText}";
            } else {
                $title = "📅 Kalender-Erinnerung";
                $body = "In {$daysRemaining} Tagen: {$titleName}{$ageText}";
            }
        }

        return ['title' => $title, 'body' => $body];
    }

    /**
     * Schreibt einen Eintrag in die Tabelle activity_log.
     */
    private function logToActivityStream(array $event, string $message, int $eventId): void
    {
        try {
            $stmt = $this->db->getConnection()->prepare("
                INSERT INTO activity_log (event_type, message, link_url, entity_id, created_at)
                VALUES (:event_type, :message, :link_url, :entity_id, NOW())
            ");
            $stmt->execute([
                'event_type' => 'calendar_reminder',
                'message' => $message,
                'link_url' => '/calendar/index.php',
                'entity_id' => $eventId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warn("CalendarService: Konnte Activity-Log nicht schreiben.", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Liefert ausführliche Details zum westlichen Tierkreiszeichen (Sternzeichen).
     *
     * @return array{name: string, symbol: string, date_range: string, element: string, traits: string[], description: string}
     */
    public function getWesternZodiac(int $day, int $month): array
    {
        $zodiacs = [
            'widder' => [
                'name' => 'Widder', 'symbol' => '♈', 'date_range' => '21.03. – 20.04.',
                'element' => 'Feuer 🔥', 'traits' => ['Mutig', 'Dynamisch', 'Direkt', 'Tatkräftig', 'Begeisternd'],
                'description' => 'Widder-Geborene sprühen vor Energie, Tatendrang und Pioniergeist. Sie gehen Herausforderungen mutig an und inspirieren andere durch ihren Optimismus.'
            ],
            'stier' => [
                'name' => 'Stier', 'symbol' => '♉', 'date_range' => '21.04. – 20.05.',
                'element' => 'Erde 🌍', 'traits' => ['Geduldig', 'Zuverlässig', 'Treu', 'Bodenständig', 'Genussvoll'],
                'description' => 'Stiere schätzen Beständigkeit, Treue und die schönen Dinge des Lebens. Sie stehen mit beiden Beinen fest auf dem Boden und sind ein verlässlicher Fels in der Brandung.'
            ],
            'zwillinge' => [
                'name' => 'Zwillinge', 'symbol' => '♊', 'date_range' => '21.05. – 21.06.',
                'element' => 'Luft 💨', 'traits' => ['Neugierig', 'Kommunikativ', 'Vielseitig', 'Geistreich', 'Anpassungsfähig'],
                'description' => 'Zwillinge sind wissbegierig, gesellig und voller Ideen. Sie lieben den Austausch, sind blitzgescheit und können sich mühelos auf neue Situationen einstellen.'
            ],
            'krebs' => [
                'name' => 'Krebs', 'symbol' => '♋', 'date_range' => '22.06. – 22.07.',
                'element' => 'Wasser 💧', 'traits' => ['Fürsorglich', 'Empathisch', 'Intuitiv', 'Familienverbunden', 'Gefühlvoll'],
                'description' => 'Krebse zeichnen sich durch tiefe Empathie und Beschützerinstinkt aus. Für Familie und Freunde schaffen sie stets einen sicheren, liebevollen Hafen.'
            ],
            'loewe' => [
                'name' => 'Löwe', 'symbol' => '♌', 'date_range' => '23.07. – 23.08.',
                'element' => 'Feuer 🔥', 'traits' => ['Großzügig', 'Herzlich', 'Selbstbewusst', 'Loyal', 'Optimistisch'],
                'description' => 'Löwen besitzen ein großes, warmes Herz und natürliche Strahlkraft. Sie beschützen ihre Lieben mit Hingabe und begegnen dem Leben mit Lebensfreude.'
            ],
            'jungfrau' => [
                'name' => 'Jungfrau', 'symbol' => '♍', 'date_range' => '24.08. – 23.09.',
                'element' => 'Erde 🌍', 'traits' => ['Sorgfältig', 'Analytisch', 'Hilfsbereit', 'Strukturiert', 'Zuverlässig'],
                'description' => 'Jungfrauen haben einen scharfen Blick fürs Detail und einen ausgeprägten Ordnungssinn. Sie sind jederzeit hilfsbereit, aufrichtig und praktisch veranlagt.'
            ],
            'waage' => [
                'name' => 'Waage', 'symbol' => '♎', 'date_range' => '24.09. – 23.10.',
                'element' => 'Luft 💨', 'traits' => ['Ausgleichend', 'Diplomatisch', 'Gerecht', 'Harmoniebedürftig', 'Ästhetisch'],
                'description' => 'Waagen streben nach Harmonie, Ausgleich und Gerechtigkeit. Sie besitzen ein feines Gespür für Ästhetik und schaffen überall eine friedvolle Atmosphäre.'
            ],
            'skorpion' => [
                'name' => 'Skorpion', 'symbol' => '♏', 'date_range' => '24.10. – 22.11.',
                'element' => 'Wasser 💧', 'traits' => ['Leidenschaftlich', 'Willensstark', 'Tiefgründig', 'Loyal', 'Scharfsinnig'],
                'description' => 'Skorpione sind willensstark, tiefgründig und unerschütterlich loyal. Wenn sie jemanden ins Herz geschlossen haben, stehen sie felsenfest zu ihm.'
            ],
            'schuetze' => [
                'name' => 'Schütze', 'symbol' => '♐', 'date_range' => '23.11. – 21.12.',
                'element' => 'Feuer 🔥', 'traits' => ['Optimistisch', 'Weltoffen', 'Humorvoll', 'Freiheitsliebend', 'Inspirierend'],
                'description' => 'Schützen lieben das Abenteuer, horizonterweiternde Erlebnisse und geistige Freiheit. Mit ihrem sonnigen Gemüt zaubern sie anderen schnell ein Lächeln ins Gesicht.'
            ],
            'steinbock' => [
                'name' => 'Steinbock', 'symbol' => '♑', 'date_range' => '22.12. – 20.01.',
                'element' => 'Erde 🌍', 'traits' => ['Diszipliniert', 'Pflichtbewusst', 'Ausdauernd', 'Zuverlässig', 'Bodenständig'],
                'description' => 'Steinböcke sind zielstrebig, realistisch und verlässlich wie kein anderer. Mit Geduld und Disziplin meistern sie jeden noch so steilen Weg.'
            ],
            'wassermann' => [
                'name' => 'Wassermann', 'symbol' => '♒', 'date_range' => '21.01. – 19.02.',
                'element' => 'Luft 💨', 'traits' => ['Originell', 'Freiheitsliebend', 'Erfinderisch', 'Tolerant', 'Zukunftsgewandt'],
                'description' => 'Wassermänner denken unkonventionell und vorausschauend. Sie schätzen Unabhängigkeit, Individualität und setzen sich leidenschaftlich für humanitäre Werte ein.'
            ],
            'fische' => [
                'name' => 'Fische', 'symbol' => '♓', 'date_range' => '20.02. – 20.03.',
                'element' => 'Wasser 💧', 'traits' => ['Einfühlsam', 'Fantasievoll', 'Mitfühlend', 'Intuitionstark', 'Hilfsbereit'],
                'description' => 'Fische-Geborene sind feinfühlig, träumerisch und haben ein grenzenloses Mitgefühl. Sie verstehen Gefühle oft ganz ohne Worte.'
            ]
        ];

        $key = match ($month) {
            1 => ($day <= 20) ? 'steinbock' : 'wassermann',
            2 => ($day <= 19) ? 'wassermann' : 'fische',
            3 => ($day <= 20) ? 'fische' : 'widder',
            4 => ($day <= 20) ? 'widder' : 'stier',
            5 => ($day <= 21) ? 'stier' : 'zwillinge',
            6 => ($day <= 21) ? 'zwillinge' : 'krebs',
            7 => ($day <= 22) ? 'krebs' : 'loewe',
            8 => ($day <= 23) ? 'loewe' : 'jungfrau',
            9 => ($day <= 23) ? 'jungfrau' : 'waage',
            10 => ($day <= 23) ? 'waage' : 'skorpion',
            11 => ($day <= 22) ? 'skorpion' : 'schuetze',
            12 => ($day <= 21) ? 'schuetze' : 'steinbock',
            default => 'widder',
        };

        return $zodiacs[$key];
    }

    /**
     * Berechnet das chinesische Tierkreiszeichen inklusive Element (Wu Xing) und Polarität (Yin/Yang).
     *
     * Berücksichtigt das exakte Datum des chinesischen Neujahrs (1940 bis 2035).
     *
     * @return array{animal: string, symbol: string, element: string, polarity: string, full_name: string, lunar_year: int, lucky_numbers: string, lucky_colors: string, traits: string[], description: string}|null
     */
    public function getChineseZodiac(?int $birthYear, int $month, int $day): ?array
    {
        if ($birthYear === null || $birthYear <= 0) {
            return null;
        }

        // Exakte Termine des chinesischen Neujahrs für 1940 - 2035 (Monat, Tag)
        static $cnyDates = [
            1940 => [2, 8], 1941 => [1, 27], 1942 => [2, 15], 1943 => [2, 5], 1944 => [1, 25],
            1945 => [2, 13], 1946 => [2, 2], 1947 => [1, 22], 1948 => [2, 10], 1949 => [1, 29],
            1950 => [2, 17], 1951 => [2, 6], 1952 => [1, 27], 1953 => [2, 14], 1954 => [2, 3],
            1955 => [1, 24], 1956 => [2, 12], 1957 => [1, 31], 1958 => [2, 18], 1959 => [2, 8],
            1960 => [1, 28], 1961 => [2, 15], 1962 => [2, 5], 1963 => [1, 25], 1964 => [2, 13],
            1965 => [2, 2], 1966 => [1, 21], 1967 => [2, 9], 1968 => [1, 30], 1969 => [2, 17],
            1970 => [2, 6], 1971 => [1, 27], 1972 => [2, 15], 1973 => [2, 3], 1974 => [1, 23],
            1975 => [2, 11], 1976 => [1, 31], 1977 => [2, 18], 1978 => [2, 7], 1979 => [1, 28],
            1980 => [2, 16], 1981 => [2, 5], 1982 => [1, 25], 1983 => [2, 13], 1984 => [2, 2],
            1985 => [2, 20], 1986 => [2, 9], 1987 => [1, 29], 1988 => [2, 17], 1989 => [2, 6],
            1990 => [1, 27], 1991 => [2, 15], 1992 => [2, 4], 1993 => [1, 23], 1994 => [2, 10],
            1995 => [1, 31], 1996 => [2, 19], 1997 => [2, 7], 1998 => [1, 28], 1999 => [2, 16],
            2000 => [2, 5], 2001 => [1, 24], 2002 => [2, 12], 2003 => [2, 1], 2004 => [1, 22],
            2005 => [2, 9], 2006 => [1, 29], 2007 => [2, 18], 2008 => [2, 7], 2009 => [1, 26],
            2010 => [2, 14], 2011 => [2, 3], 2012 => [1, 23], 2013 => [2, 10], 2014 => [1, 31],
            2015 => [2, 19], 2016 => [2, 8], 2017 => [1, 28], 2018 => [2, 16], 2019 => [2, 5],
            2020 => [1, 25], 2021 => [2, 12], 2022 => [2, 1], 2023 => [1, 22], 2024 => [2, 10],
            2025 => [1, 29], 2026 => [2, 17], 2027 => [2, 6], 2028 => [1, 26], 2029 => [2, 13],
            2030 => [2, 3], 2031 => [1, 23], 2032 => [2, 11], 2033 => [1, 31], 2034 => [2, 19],
            2035 => [2, 8]
        ];

        // Bestimme das lunare Jahr (Vor dem Neujahrsfest zählt die Person noch zum Vorjahr)
        $lunarYear = $birthYear;
        if (isset($cnyDates[$birthYear])) {
            [$cnyMonth, $cnyDay] = $cnyDates[$birthYear];
            if ($month < $cnyMonth || ($month === $cnyMonth && $day < $cnyDay)) {
                $lunarYear = $birthYear - 1;
            }
        } elseif ($month === 1) {
            $lunarYear = $birthYear - 1;
        }

        // 12 Tierzeichen (Zyklus ab 4 n. Chr. = Ratte)
        $animalIndex = (($lunarYear - 4) % 12 + 12) % 12;

        $animals = [
            0 => [
                'animal' => 'Ratte', 'symbol' => '🐀',
                'lucky_numbers' => '2, 3', 'lucky_colors' => 'Blau, Gold, Grün',
                'traits' => ['Scharfsinnig', 'Anpassungsfähig', 'Charmant', 'Einfallsreich', 'Gesellig'],
                'description' => 'Die Ratte ist clever, wendig und findet in jeder Lebenslage die passende Lösung. Sie besitzt ein feines Gespür für Gelegenheiten und schätzt die Gemeinschaft.'
            ],
            1 => [
                'animal' => 'Büffel', 'symbol' => '🐂',
                'lucky_numbers' => '1, 4', 'lucky_colors' => 'Weiß, Gelb, Grün',
                'traits' => ['Ausdauernd', 'Zuverlässig', 'Geduldig', 'Ehrlich', 'Fleißig'],
                'description' => 'Der Büffel verkörpert Beständigkeit, Verlässlichkeit und methodischen Fleiß. Mit eiserner Disziplin und Treue erreicht er jedes gesetzte Ziel.'
            ],
            2 => [
                'animal' => 'Tiger', 'symbol' => '🐅',
                'lucky_numbers' => '1, 3, 4', 'lucky_colors' => 'Blau, Grau, Orange',
                'traits' => ['Mutig', 'Leidenschaftlich', 'Führungsstark', 'Charismatisch', 'Rechtsoffen'],
                'description' => 'Der Tiger ist ein mutiger Beschützer voller Energie und Leidenschaft. Er scheut kein Abenteuer und tritt stets beherzt für seine Freunde ein.'
            ],
            3 => [
                'animal' => 'Hase', 'symbol' => '🐇',
                'lucky_numbers' => '3, 4, 6', 'lucky_colors' => 'Rot, Pink, Lila, Blau',
                'traits' => ['Feinsinnig', 'Friedliebend', 'Diplomatisch', 'Empathisch', 'Elegant'],
                'description' => 'Der Hase zeichnet sich durch Sanftmut, Höflichkeit und feinen Geschmack aus. Er meidet unnötigen Streit und pflegt liebevolle, harmonische Beziehungen.'
            ],
            4 => [
                'animal' => 'Drache', 'symbol' => '🐉',
                'lucky_numbers' => '1, 6, 7', 'lucky_colors' => 'Gold, Silber, Gelb',
                'traits' => ['Kraftvoll', 'Selbstbewusst', 'Großmütig', 'Begeisternd', 'Glücksbringend'],
                'description' => 'Der Drache ist das stärkste Glückssymbol im Tierkreis. Voller Vitalität, Charisma und Selbstvertrauen zieht er Menschen in seinen Bann.'
            ],
            5 => [
                'animal' => 'Schlange', 'symbol' => '🐍',
                'lucky_numbers' => '2, 8, 9', 'lucky_colors' => 'Schwarz, Rot, Gelb',
                'traits' => ['Weise', 'Intuitiv', 'Tiefgründig', 'Elegant', 'Besonnen'],
                'description' => 'Die Schlange ist klug, intuitiv und ein Meister der Beobachtung. Sie trifft Entscheidungen stets mit Ruhe, Weitsicht und Scharfsinn.'
            ],
            6 => [
                'animal' => 'Pferd', 'symbol' => '🐎',
                'lucky_numbers' => '2, 3, 7', 'lucky_colors' => 'Gelb, Grün',
                'traits' => ['Freiheitsliebend', 'Lebhaft', 'Optimistisch', 'Unabhängig', 'Warmherzig'],
                'description' => 'Das Pferd liebt Bewegung, Freiheit und Unabhängigkeit. Mit seiner offenen Art und ansteckenden Fröhlichkeit gewinnt es schnell alle Herzen.'
            ],
            7 => [
                'animal' => 'Ziege', 'symbol' => '🐐',
                'lucky_numbers' => '2, 7', 'lucky_colors' => 'Braun, Rot, Lila',
                'traits' => ['Sanftmütig', 'Kreativ', 'Friedfertig', 'Harmoniebedürftig', 'Hilfsbereit'],
                'description' => 'Die Ziege (oder das Schaf) ist künstlerisch veranlagt, einfühlsam und voller Herzensgüte. Sie schätzt Geborgenheit und bringt Wärme in jede Familie.'
            ],
            8 => [
                'animal' => 'Affe', 'symbol' => '🐒',
                'lucky_numbers' => '4, 9', 'lucky_colors' => 'Weiß, Blau, Gold',
                'traits' => ['Clever', 'Humorvoll', 'Erfinderisch', 'Neugierig', 'Gewitzt'],
                'description' => 'Der Affe ist ein echter Problemlöser mit schelmischem Witz und schnellem Verstand. Er meistert selbst vertrackte Situationen spielend leicht.'
            ],
            9 => [
                'animal' => 'Hahn', 'symbol' => '🐓',
                'lucky_numbers' => '5, 7, 8', 'lucky_colors' => 'Gold, Braun, Gelb',
                'traits' => ['Pünktlich', 'Gewissenhaft', 'Aufrichtig', 'Beobachtungsstark', 'Organisiert'],
                'description' => 'Der Hahn ist stolz, aufmerksam und liebt Struktur und Pünktlichkeit. Auf sein Wort und seine Loyalität kann man sich blind verlassen.'
            ],
            10 => [
                'animal' => 'Hund', 'symbol' => '🐕',
                'lucky_numbers' => '3, 4, 9', 'lucky_colors' => 'Rot, Grün, Lila',
                'traits' => ['Loyal', 'Ehrlich', 'Beschützend', 'Gerechtigkeitsliebend', 'Treuhuldig'],
                'description' => 'Der Hund ist der treueste Begleiter im Tierkreis. Er zeichnet sich durch bedingungslose Ehrlichkeit, Pflichtgefühl und Fürsorge aus.'
            ],
            11 => [
                'animal' => 'Schwein', 'symbol' => '🐖',
                'lucky_numbers' => '2, 5, 8', 'lucky_colors' => 'Gelb, Grau, Braun, Gold',
                'traits' => ['Herzlich', 'Gutmütig', 'Tolerant', 'Lebensfroh', 'Freigiebig'],
                'description' => 'Das Schwein schätzt Genuss, Ehrlichkeit und Gemütlichkeit. Es ist warmherzig, sieht immer das Gute im Menschen und schenkt Freude.'
            ]
        ];

        $animalData = $animals[$animalIndex] ?? $animals[0];

        // 5 Elemente (Wu Xing) & Polarität anhand der Endziffer des lunaren Jahres
        $lastDigit = abs($lunarYear) % 10;
        $elementData = match ($lastDigit) {
            0 => ['element' => 'Metall ⚔️', 'polarity' => 'Yang ⚪', 'element_name' => 'Metall'],
            1 => ['element' => 'Metall ⚔️', 'polarity' => 'Yin ⚫', 'element_name' => 'Metall'],
            2 => ['element' => 'Wasser 💧', 'polarity' => 'Yang ⚪', 'element_name' => 'Wasser'],
            3 => ['element' => 'Wasser 💧', 'polarity' => 'Yin ⚫', 'element_name' => 'Wasser'],
            4 => ['element' => 'Holz 🌲', 'polarity' => 'Yang ⚪', 'element_name' => 'Holz'],
            5 => ['element' => 'Holz 🌲', 'polarity' => 'Yin ⚫', 'element_name' => 'Holz'],
            6 => ['element' => 'Feuer 🔥', 'polarity' => 'Yang ⚪', 'element_name' => 'Feuer'],
            7 => ['element' => 'Feuer 🔥', 'polarity' => 'Yin ⚫', 'element_name' => 'Feuer'],
            8 => ['element' => 'Erde 🌍', 'polarity' => 'Yang ⚪', 'element_name' => 'Erde'],
            9 => ['element' => 'Erde 🌍', 'polarity' => 'Yin ⚫', 'element_name' => 'Erde'],
            default => ['element' => 'Holz 🌲', 'polarity' => 'Yang ⚪', 'element_name' => 'Holz'],
        };

        return [
            'animal' => $animalData['animal'],
            'symbol' => $animalData['symbol'],
            'element' => $elementData['element'],
            'polarity' => $elementData['polarity'],
            'full_name' => $elementData['element_name'] . '-' . $animalData['animal'] . ' (' . $elementData['polarity'] . ')',
            'lunar_year' => $lunarYear,
            'lucky_numbers' => $animalData['lucky_numbers'],
            'lucky_colors' => $animalData['lucky_colors'],
            'traits' => $animalData['traits'],
            'description' => $animalData['description'],
        ];
    }
}
