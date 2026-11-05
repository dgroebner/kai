<?php

namespace Kai\Tools\Weather;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use Throwable;

class AstronomyService
{
    private const LEIPZIG_LAT = 51.3397;
    private const LEIPZIG_LON = 12.3731;

    /**
     * Kanonische Definitionen bekannter Meteorschauer für Aktivitäts- und Intensitätsberechnung.
     *
     * @var array<string, array<string, mixed>>
     */
    public const KNOWN_METEOR_SHOWERS = [
        'quadrantids' => [
            'key' => 'quadrantids',
            'tag' => 'quadrantid',
            'name' => 'Quadrantiden',
            'title' => 'Quadrantiden (Winter-Meteorschauer)',
            'description' => 'Kurzer, aber intensiver Schauer zum Jahresbeginn mit vielen hellen Feuerkugeln.',
            'start' => [1, 1],
            'peak' => [1, 3],
            'end' => [1, 10],
            'activity_period' => '01.01. – 10.01.',
            'peak_date' => '03.01.',
            'zhr' => 80,
            'radiant' => 'Bärenhüter',
        ],
        'lyrids' => [
            'key' => 'lyrids',
            'tag' => 'lyrid',
            'name' => 'Lyriden',
            'title' => 'Lyriden (Frühlings-Meteorschauer)',
            'description' => 'Schnelle und helle Meteore aus dem Sternbild Leier.',
            'start' => [4, 16],
            'peak' => [4, 22],
            'end' => [4, 25],
            'activity_period' => '16.04. – 25.04.',
            'peak_date' => '22.04.',
            'zhr' => 18,
            'radiant' => 'Leier',
        ],
        'eta_aquariids' => [
            'key' => 'eta_aquariids',
            'tag' => 'aquariid',
            'name' => 'Eta-Aquariiden',
            'title' => 'Eta-Aquariiden (Halley-Meteorschauer)',
            'description' => 'Schnelle Meteore in den frühen Morgenstunden, abstammend vom Halleyschen Kometen.',
            'start' => [4, 19],
            'peak' => [5, 6],
            'end' => [5, 28],
            'activity_period' => '19.04. – 28.05.',
            'peak_date' => '06.05.',
            'zhr' => 50,
            'radiant' => 'Wassermann',
        ],
        'perseids' => [
            'key' => 'perseids',
            'tag' => 'perseid',
            'name' => 'Perseiden',
            'title' => 'Perseiden (Die Laurentiustränen)',
            'description' => 'Der beliebteste Sommer-Meteorschauer mit bis zu 100 schnellen Sternschnuppen pro Stunde bei milden Sommernächten.',
            'start' => [7, 17],
            'peak' => [8, 12],
            'end' => [8, 24],
            'activity_period' => '17.07. – 24.08.',
            'peak_date' => '12.08.',
            'zhr' => 100,
            'radiant' => 'Perseus',
        ],
        'orionids' => [
            'key' => 'orionids',
            'tag' => 'orionid',
            'name' => 'Orioniden',
            'title' => 'Orioniden (Meteorschauer)',
            'description' => 'Sternschnuppenstrom aus Staubspuren des Kometen Halley. Ca. 15–20 Meteore pro Stunde.',
            'start' => [10, 2],
            'peak' => [10, 21],
            'end' => [11, 7],
            'activity_period' => '02.10. – 07.11.',
            'peak_date' => '21.10.',
            'zhr' => 20,
            'radiant' => 'Orion',
        ],
        'leonids' => [
            'key' => 'leonids',
            'tag' => 'leonid',
            'name' => 'Leoniden',
            'title' => 'Leoniden-Maximum',
            'description' => 'Sehr schnelle Sternschnuppen mit feinen Rauchschweifen.',
            'start' => [11, 6],
            'peak' => [11, 17],
            'end' => [11, 30],
            'activity_period' => '06.11. – 30.11.',
            'peak_date' => '17.11.',
            'zhr' => 15,
            'radiant' => 'Löwe',
        ],
        'geminids' => [
            'key' => 'geminids',
            'tag' => 'geminid',
            'name' => 'Geminiden',
            'title' => 'Geminiden (Stärkster Meteorschauer des Jahres)',
            'description' => 'Reichster Sternschnuppenstrom des Jahres mit bis zu 120 oft bunten und hellen Sternschnuppen pro Stunde!',
            'start' => [12, 4],
            'peak' => [12, 14],
            'end' => [12, 17],
            'activity_period' => '04.12. – 17.12.',
            'peak_date' => '14.12.',
            'zhr' => 120,
            'radiant' => 'Zwillinge',
        ],
        'ursids' => [
            'key' => 'ursids',
            'tag' => 'ursid',
            'name' => 'Ursiden',
            'title' => 'Ursiden (Weihnachts-Meteorschauer)',
            'description' => 'Kleinerer Schauer kurz vor Weihnachten rund um den Kleinen Wagen.',
            'start' => [12, 17],
            'peak' => [12, 22],
            'end' => [12, 26],
            'activity_period' => '17.12. – 26.12.',
            'peak_date' => '22.12.',
            'zhr' => 10,
            'radiant' => 'Kleiner Bär',
        ],
    ];

    private ?PDO $pdo;
    private Logger $logger;
    private ?WeatherService $weatherService;

    public function __construct(
        ?PDO $pdo = null,
        ?Logger $logger = null,
        ?WeatherService $weatherService = null
    ) {
        $this->logger = $logger ?? new Logger();
        try {
            $this->weatherService = $weatherService ?? new WeatherService();
        } catch (Throwable $e) {
            $this->weatherService = null;
        }
        try {
            $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        } catch (Throwable $e) {
            $this->pdo = null;
            $this->logger->warn('AstronomyService: DB-Verbindung nicht verfügbar', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Liefert den aktuellen Astronomie- und Himmelsstatus (Kp-Index, Planeten, Aurora).
     *
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        if ($this->pdo) {
            try {
                $stmt = $this->pdo->query("SELECT * FROM weather_astronomy_state WHERE id = 1");
                $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

                if ($row) {
                    $planets = !empty($row['visible_planets_json']) ? json_decode($row['visible_planets_json'], true) : $this->getDefaultPlanets();
                    $rawShowers = !empty($row['active_meteor_showers_json']) ? json_decode($row['active_meteor_showers_json'], true) : [];
                    return [
                        'kp_current' => (float)($row['kp_current'] ?? 0.0),
                        'kp_max_next_24h' => (float)($row['kp_max_next_24h'] ?? 0.0),
                        'kp_forecast' => !empty($row['kp_forecast_json']) ? json_decode($row['kp_forecast_json'], true) : [],
                        'aurora_chance' => (string)($row['aurora_chance'] ?? 'none'),
                        'visible_planets' => $this->enrichPlanetsWithVisibility(is_array($planets) ? $planets : $this->getDefaultPlanets()),
                        'active_meteor_showers' => $this->enrichActiveMeteorShowers(is_array($rawShowers) ? $rawShowers : []),
                        'moon_phase_name' => (string)($row['moon_phase_name'] ?? 'Mondphase'),
                        'moon_illumination' => isset($row['moon_illumination']) ? (float)$row['moon_illumination'] : 0.5,
                        'summary_text' => $row['summary_text'] ?? null,
                        'updated_at' => $row['updated_at'] ?? null,
                    ];
                }
            } catch (Throwable $e) {
                $this->logger->warn('AstronomyService: Fehler beim Laden des Status', ['error' => $e->getMessage()]);
            }
        }

        return [
            'kp_current' => 2.0,
            'kp_max_next_24h' => 2.5,
            'kp_forecast' => [],
            'aurora_chance' => 'none',
            'visible_planets' => $this->enrichPlanetsWithVisibility($this->getDefaultPlanets()),
            'active_meteor_showers' => $this->enrichActiveMeteorShowers([]),
            'moon_phase_name' => 'Halbmond',
            'moon_illumination' => 0.5,
            'summary_text' => 'Geringe Sonnenaktivität, normale Bedingungen.',
            'updated_at' => null,
        ];
    }

    /**
     * Berechnet für einen Meteorschauer das relevante Zeitfenster (Start, Peak, Ende),
     * dessen Ende heute oder in der Zukunft liegt, inkl. Aktivitätsstatus und Intensitätskurve.
     *
     * @return array{
     *     start_ts: int,
     *     peak_ts: int,
     *     end_ts: int,
     *     peak_date_iso: string,
     *     end_date_iso: string,
     *     end_date_formatted: string,
     *     activity_period: string,
     *     peak_date: string,
     *     is_active: bool,
     *     is_peak: bool,
     *     is_post_peak: bool,
     *     days_until_peak: int,
     *     days_until_end: int,
     *     intensity: float,
     *     intensity_pct: int,
     *     trend: string
     * }
     */
    public function resolveShowerWindow(
        int $startM,
        int $startD,
        int $peakM,
        int $peakD,
        int $endM,
        int $endD,
        ?int $todayTs = null
    ): array {
        $todayTs = $todayTs ?? strtotime('today');
        $curYear = (int)date('Y', $todayTs);

        $chosenStartTs = 0;
        $chosenPeakTs = 0;
        $chosenEndTs = 0;

        foreach ([$curYear - 1, $curYear, $curYear + 1] as $y) {
            $peakTs = strtotime(sprintf('%04d-%02d-%02d', $y, $peakM, $peakD));
            $sYear = ($startM <= $peakM) ? $y : $y - 1;
            $startTs = strtotime(sprintf('%04d-%02d-%02d', $sYear, $startM, $startD));
            $eYear = ($endM >= $peakM) ? $y : $y + 1;
            $endTs = strtotime(sprintf('%04d-%02d-%02d', $eYear, $endM, $endD));

            if ($endTs >= $todayTs) {
                $chosenStartTs = $startTs;
                $chosenPeakTs = $peakTs;
                $chosenEndTs = $endTs;
                break;
            }
        }

        $isActive = ($todayTs >= $chosenStartTs && $todayTs <= $chosenEndTs);
        $daysUntilPeak = (int)round(($chosenPeakTs - $todayTs) / 86400);
        $daysUntilEnd = (int)round(($chosenEndTs - $todayTs) / 86400);
        $isPeak = ($daysUntilPeak === 0);
        $isPostPeak = ($isActive && $daysUntilPeak < 0);

        if (!$isActive) {
            $intensity = 0.0;
            $trend = 'rising';
        } elseif ($isPeak) {
            $intensity = 1.0;
            $trend = 'peak';
        } elseif ($todayTs < $chosenPeakTs) {
            $riseDays = max(1, ($chosenPeakTs - $chosenStartTs) / 86400);
            $prog = min(1.0, max(0.0, ($todayTs - $chosenStartTs) / ($riseDays * 86400)));
            $intensity = round(0.15 + 0.85 * $prog, 2);
            $trend = 'rising';
        } else {
            $fallDays = max(1, ($chosenEndTs - $chosenPeakTs) / 86400);
            $prog = min(1.0, max(0.0, ($todayTs - $chosenPeakTs) / ($fallDays * 86400)));
            $intensity = round(max(0.15, 1.0 - 0.85 * $prog), 2);
            $trend = 'falling';
        }

        return [
            'start_ts' => $chosenStartTs,
            'peak_ts' => $chosenPeakTs,
            'end_ts' => $chosenEndTs,
            'peak_date_iso' => date('Y-m-d', $chosenPeakTs),
            'end_date_iso' => date('Y-m-d', $chosenEndTs),
            'end_date_formatted' => date('d.m.', $chosenEndTs),
            'activity_period' => sprintf('%02d.%02d. – %02d.%02d.', $startD, $startM, $endD, $endM),
            'peak_date' => sprintf('%02d.%02d.', $peakD, $peakM),
            'is_active' => $isActive,
            'is_peak' => $isPeak,
            'is_post_peak' => $isPostPeak,
            'days_until_peak' => $daysUntilPeak,
            'days_until_end' => $daysUntilEnd,
            'intensity' => $intensity,
            'intensity_pct' => (int)round($intensity * 100),
            'trend' => $trend,
        ];
    }

    /**
     * Findet die passende bekannte Meteorschauer-Definition anhand von Schlüssel oder Titel.
     *
     * @return array<string, mixed>|null
     */
    private function matchKnownShower(string $keyOrTitle): ?array
    {
        $haystack = mb_strtolower($keyOrTitle, 'UTF-8');
        foreach (self::KNOWN_METEOR_SHOWERS as $def) {
            if (
                str_contains($haystack, $def['key']) ||
                str_contains($haystack, $def['tag']) ||
                str_contains($haystack, mb_strtolower($def['name'], 'UTF-8'))
            ) {
                return $def;
            }
        }
        return null;
    }

    /**
     * Ergänzt und validiert die Liste der aktuell aktiven Meteorschauer (auch nach Überschreiten des Maximums bis zum Enddatum).
     *
     * @param array<int, array<string, mixed>> $showers
     * @return array<int, array<string, mixed>>
     */
    private function enrichActiveMeteorShowers(array $showers): array
    {
        $todayTs = strtotime('today');
        $resultByKey = [];

        foreach ($showers as $shower) {
            if (!is_array($shower)) {
                continue;
            }
            $sKey = (string)($shower['key'] ?? '');
            $sName = (string)($shower['name'] ?? '');
            $known = $this->matchKnownShower($sKey . ' ' . $sName);

            if ($known !== null) {
                $win = $this->resolveShowerWindow(
                    $known['start'][0],
                    $known['start'][1],
                    $known['peak'][0],
                    $known['peak'][1],
                    $known['end'][0],
                    $known['end'][1],
                    $todayTs
                );
                if (!$win['is_active']) {
                    continue;
                }
                $canonicalKey = $known['key'];
                $resultByKey[$canonicalKey] = array_merge($shower, [
                    'key' => $canonicalKey,
                    'name' => $shower['name'] ?? $known['name'],
                    'zhr' => $shower['zhr'] ?? $known['zhr'],
                    'radiant' => $shower['radiant'] ?? $known['radiant'],
                    'activity_period' => $win['activity_period'],
                    'peak_date' => $win['peak_date'],
                    'peak_day_formatted' => $win['peak_date'],
                    'end_date_formatted' => $win['end_date_formatted'],
                    'is_peak' => $win['is_peak'],
                    'is_post_peak' => $win['is_post_peak'],
                    'intensity' => $win['intensity'],
                    'intensity_pct' => $win['intensity_pct'],
                    'trend' => $win['trend'],
                ]);
            } else {
                $fallbackKey = $sKey !== '' ? $sKey : mb_strtolower($sName, 'UTF-8');
                if ($fallbackKey !== '') {
                    $resultByKey[$fallbackKey] = $shower;
                }
            }
        }

        // Sicherstellen, dass alle heute aktiven bekannten Schauer enthalten sind (auch nach dem Maximum bis zum Enddatum)
        foreach (self::KNOWN_METEOR_SHOWERS as $canonicalKey => $known) {
            if (isset($resultByKey[$canonicalKey])) {
                continue;
            }
            $win = $this->resolveShowerWindow(
                $known['start'][0],
                $known['start'][1],
                $known['peak'][0],
                $known['peak'][1],
                $known['end'][0],
                $known['end'][1],
                $todayTs
            );
            if ($win['is_active']) {
                $resultByKey[$canonicalKey] = [
                    'key' => $canonicalKey,
                    'name' => $known['name'],
                    'zhr' => $known['zhr'],
                    'radiant' => $known['radiant'],
                    'activity_period' => $win['activity_period'],
                    'peak_date' => $win['peak_date'],
                    'peak_day_formatted' => $win['peak_date'],
                    'end_date_formatted' => $win['end_date_formatted'],
                    'is_peak' => $win['is_peak'],
                    'is_post_peak' => $win['is_post_peak'],
                    'intensity' => $win['intensity'],
                    'intensity_pct' => $win['intensity_pct'],
                    'trend' => $win['trend'],
                ];
            }
        }

        return array_values($resultByKey);
    }

    /**
     * Liefert die anstehenden (sowie aktuell noch aktiven) astronomischen Ereignisse für Leipzig.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getUpcomingEvents(int $limit = 12): array
    {
        if ($this->pdo) {
            try {
                // Abfrage inkl. der letzten 45 Tage, damit Meteorschauer, deren Maximum überschritten ist,
                // die aber noch bis zu ihrem Enddatum aktiv sind, nicht herausfallen.
                $fetchLimit = max(36, $limit * 3);
                $stmt = $this->pdo->prepare("
                    SELECT * FROM weather_astronomy_events
                    WHERE event_date >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
                    ORDER BY event_date ASC, peak_time ASC
                    LIMIT :limit
                ");
                $stmt->bindValue(':limit', $fetchLimit, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($rows)) {
                    $todayTs = strtotime('today');
                    $todayIso = date('Y-m-d', $todayTs);
                    $events = [];
                    $seenShowers = [];
                    $seenTitles = [];

                    foreach ($rows as $r) {
                        $r['details'] = !empty($r['details_json']) ? json_decode($r['details_json'], true) : [];
                        if (!is_array($r['details'])) {
                            $r['details'] = [];
                        }
                        unset($r['details_json']);

                        $eventKey = (string)($r['event_key'] ?? '');
                        $baseKey = preg_replace('/-\d{4}$/', '', $eventKey);
                        if (empty($baseKey)) {
                            $baseKey = explode('-', $eventKey)[0] ?? $eventKey;
                        }

                        $rawTitle = mb_strtolower(trim((string)($r['title'] ?? '')), 'UTF-8');
                        $normTitle = trim((string)preg_replace('/\b20\d{2}\b/', '', $rawTitle));
                        $isMeteorShower = (($r['event_type'] ?? '') === 'meteor_shower');

                        $showerTag = null;
                        $knownShower = null;
                        if ($isMeteorShower) {
                            $knownShower = $this->matchKnownShower($baseKey . ' ' . $rawTitle);
                            $showerTag = $knownShower['tag'] ?? $baseKey;
                        }

                        // Für bekannte Meteorschauer das aktuelle/nächste Zeitfenster prüfen
                        if ($isMeteorShower && $knownShower !== null) {
                            $win = $this->resolveShowerWindow(
                                $knownShower['start'][0],
                                $knownShower['start'][1],
                                $knownShower['peak'][0],
                                $knownShower['peak'][1],
                                $knownShower['end'][0],
                                $knownShower['end'][1],
                                $todayTs
                            );

                            if ($win['is_active']) {
                                // Solange der Schauer aktiv ist (auch nach dem Maximum bis zum Enddatum!),
                                // auf den aktuellen Zyklus normieren, damit nicht schon das Folgejahr angezeigt wird.
                                $r['event_date'] = $win['peak_date_iso'];
                                $r['end_date'] = $win['end_date_iso'];
                                $r['details']['activity_period'] = $r['details']['activity_period'] ?? $win['activity_period'];
                                $r['details']['peak_date'] = $r['details']['peak_date'] ?? $win['peak_date'];
                                $r['details']['end_date_formatted'] = $win['end_date_formatted'];
                                $r['details']['is_active'] = true;
                                $r['details']['is_peak'] = $win['is_peak'];
                                $r['details']['is_post_peak'] = $win['is_post_peak'];
                                $r['details']['intensity'] = $win['intensity'];
                                $r['details']['intensity_pct'] = $win['intensity_pct'];
                                $r['details']['trend'] = $win['trend'];
                            } else {
                                // Nicht mehr aktiv -> vergangene Instanzen überspringen
                                if (($r['event_date'] ?? '') < $todayIso) {
                                    continue;
                                }
                                $r['details']['is_active'] = false;
                            }
                        } else {
                            // Sonstige Events oder unbekannte Schauer: prüfen ob Enddatum oder Eventdatum noch aktuell ist
                            $effectiveEnd = !empty($r['end_date']) ? (string)$r['end_date'] : (string)($r['event_date'] ?? '');
                            if ($effectiveEnd < $todayIso) {
                                continue;
                            }
                        }

                        if ($showerTag !== null && isset($seenShowers[$showerTag])) {
                            continue;
                        }
                        if (!empty($normTitle) && isset($seenTitles[$normTitle])) {
                            continue;
                        }

                        if ($showerTag !== null) {
                            $seenShowers[$showerTag] = true;
                        }
                        if (!empty($normTitle)) {
                            $seenTitles[$normTitle] = true;
                        }

                        $events[] = $r;
                    }

                    if (!empty($events)) {
                        usort($events, static fn($a, $b) => strcmp((string)($a['event_date'] ?? ''), (string)($b['event_date'] ?? '')));
                        return array_slice($events, 0, $limit);
                    }
                }
            } catch (Throwable $e) {
                $this->logger->warn('AstronomyService: Fehler beim Laden der Events', ['error' => $e->getMessage()]);
            }
        }

        // Falls noch keine Ingest-Daten in der DB sind: Solide Default-Highlights
        return $this->getDefaultEvents();
    }

    /**
     * Ermittelt die Beobachtungsbedingungen für die kommende Nacht in Leipzig.
     * Kombiniert Wetterprognose (Bewölkung) mit Himmelsstatus (Kp-Index, Mond, Planeten).
     *
     * @return array{
     *     score: int,
     *     rating: string,
     *     rating_label: string,
     *     avg_cloud_cover: int,
     *     aurora_alert: bool,
     *     aurora_text: string,
     *     moon_text: string,
     *     highlights: array<int, string>,
     *     best_time_window: string
     * }
     */
    public function getNightViewingConditions(): array
    {
        $forecast = $this->weatherService ? $this->weatherService->getForecastFromDb() : null;
        $state = $this->getState();

        $hourlyTime = $forecast['hourly']['time'] ?? [];
        $hourlyClouds = $forecast['hourly']['cloud_cover'] ?? [];

        $nightClouds = [];
        $tonightStart = strtotime('today 21:00');
        $tonightEnd = strtotime('tomorrow 05:00');

        for ($i = 0; $i < count($hourlyTime); $i++) {
            $t = strtotime((string)$hourlyTime[$i]);
            if ($t >= $tonightStart && $t <= $tonightEnd) {
                if (isset($hourlyClouds[$i])) {
                    $nightClouds[] = (float)$hourlyClouds[$i];
                }
            }
        }

        $avgCloud = !empty($nightClouds) ? (int)round(array_sum($nightClouds) / count($nightClouds)) : 50;

        // Basis-Score anhand Bewölkung
        $score = max(0, min(100, 100 - $avgCloud));

        // Rating
        if ($score >= 75) {
            $rating = 'great';
            $ratingLabel = 'Exzellent';
        } elseif ($score >= 50) {
            $rating = 'good';
            $ratingLabel = 'Gut';
        } elseif ($score >= 25) {
            $rating = 'moderate';
            $ratingLabel = 'Mäßig';
        } else {
            $rating = 'poor';
            $ratingLabel = 'Schlecht';
        }

        // Highlights sammeln
        $highlights = [];
        $auroraAlert = false;
        $auroraText = 'Keine Polarlichter erwartet.';

        // Aurora Check für Leipzig: Kp >= 6.5
        $maxKp = max($state['kp_current'], $state['kp_max_next_24h']);
        if ($maxKp >= 7.0) {
            $auroraAlert = true;
            $auroraText = "🚨 Polarlicht-Alarm für Leipzig! Erwarteter Kp-Index von {$maxKp}.";
            $highlights[] = "Starker Sonnensturm (Kp {$maxKp}) – Polarlichter am Nordhorizont möglich!";
        } elseif ($maxKp >= 5.5) {
            $auroraText = "Erhöhte Sonnenaktivität (Kp {$maxKp}), fotografisch eventuell schwaches Polarlicht.";
        }

        // Aktive Meteorschauer
        if (!empty($state['active_meteor_showers'])) {
            foreach ($state['active_meteor_showers'] as $shower) {
                $name = $shower['name'] ?? 'Meteorschauer';
                $rate = $shower['zhr'] ?? 'mehrere';
                $isPeak = !empty($shower['is_peak']);
                $intensityPct = isset($shower['intensity_pct']) ? (int)$shower['intensity_pct'] : ($isPeak ? 100 : null);
                $trend = $shower['trend'] ?? ($isPeak ? 'peak' : 'rising');
                $trendText = $trend === 'peak' ? 'Maximum' : ($trend === 'rising' ? 'steigend' : 'abnehmend');
                $period = !empty($shower['activity_period']) ? "Aktiv: {$shower['activity_period']}" : '';
                $peakFormatted = $shower['peak_day_formatted'] ?? ($shower['peak_date'] ?? '');
                $peakStr = !empty($peakFormatted) ? " · Peak: {$peakFormatted}" : '';
                $metaParts = [];
                if ($intensityPct !== null) {
                    $metaParts[] = "Intensität: {$intensityPct}% {$trendText}";
                }
                if (!empty($period)) {
                    $metaParts[] = "{$period}{$peakStr}";
                }
                $metaSuffix = !empty($metaParts) ? ' (' . implode(' · ', $metaParts) . ')' : '';

                if ($isPeak) {
                    $highlights[] = "🔥 Höhepunkt des Meteorschauers {$name} (bis zu {$rate} Sternschnuppen/h · 100% Intensität)!";
                } else {
                    $highlights[] = "Aktiver Sternschnuppenstrom: {$name}{$metaSuffix}";
                }
            }
        }

        // Sichtbare Planeten (nur tatsächlich bei Dunkelheit sichtbare Planeten)
        $visiblePlanets = [];
        if (!empty($state['visible_planets'])) {
            foreach ($state['visible_planets'] as $p) {
                if (!empty($p['is_visible'])) {
                    $visiblePlanets[] = $p['name_de'] ?? $p['name'] ?? 'Planet';
                }
            }
        }
        if (!empty($visiblePlanets)) {
            $highlights[] = 'Sichtbare Planeten heute Nacht: ' . implode(', ', array_slice($visiblePlanets, 0, 4));
        }

        // Mond
        $moonPhase = $state['moon_phase_name'] ?? 'Mond';
        $moonIllum = (int)round(($state['moon_illumination'] ?? 0.5) * 100);
        $moonText = "{$moonPhase} ({$moonIllum}% beleuchtet)";

        return [
            'score' => $score,
            'rating' => $rating,
            'rating_label' => $ratingLabel,
            'avg_cloud_cover' => $avgCloud,
            'aurora_alert' => $auroraAlert,
            'aurora_text' => $auroraText,
            'moon_text' => $moonText,
            'highlights' => $highlights,
            'best_time_window' => '22:30 – 03:30 Uhr',
        ];
    }

    /**
     * Liefert strukturierte Mond-Informationen inklusive Sichtbarkeitszeitraum für die Anzeige.
     *
     * @param array<string, mixed>|null $forecast
     * @param float|null $overridePhase Optionale Testphase (0.0 bis 1.0)
     * @return array<string, mixed>
     */
    public function getMoonInfo(?array $forecast = null, ?float $overridePhase = null): array
    {
        if ($forecast === null && $this->weatherService !== null) {
            try {
                $forecast = $this->weatherService->getForecastFromDb();
            } catch (Throwable $e) {
                $forecast = null;
            }
        }

        $state = $this->getState();
        $moonPhaseName = (string)($state['moon_phase_name'] ?? 'Mond');
        $moonIllum = isset($state['moon_illumination']) ? (float)$state['moon_illumination'] : 0.5;

        // Fallback: Phase aus Wetterprognose heranziehen
        $rawPhase = null;
        if ($overridePhase !== null) {
            $rawPhase = $overridePhase;
            $moonIllum = 0.5 * (1.0 - cos($rawPhase * 2.0 * M_PI));
            $moonPhaseName = $this->getMoonPhaseNameFromRaw($rawPhase);
        } elseif (isset($forecast['daily']['moon_phase'][0])) {
            $rawPhase = (float)$forecast['daily']['moon_phase'][0];
        } elseif (isset($forecast['current']['moon_phase'])) {
            $rawPhase = (float)$forecast['current']['moon_phase'];
        }

        if ($overridePhase === null && $rawPhase !== null && ($moonPhaseName === 'Mond' || $moonPhaseName === 'Mondphase' || empty($moonPhaseName))) {
            $moonPhaseName = $this->getMoonPhaseNameFromRaw($rawPhase);
        }

        // Neumond-Prüfung
        $isNewMoon = ($moonIllum < 0.03 || mb_stripos($moonPhaseName, 'neumond') !== false);
        if ($rawPhase !== null && ($rawPhase < 0.03 || $rawPhase > 0.97)) {
            $isNewMoon = true;
        }

        $isFullMoon = ($moonIllum >= 0.95 || ($rawPhase !== null && $rawPhase >= 0.47 && $rawPhase <= 0.53) || mb_stripos($moonPhaseName, 'vollmond') !== false);
        $illumPct = (int)round($moonIllum * 100);

        // Dynamische Beschreibung (Mond ist im Gegensatz zu Planeten auch tagsüber bei Sonnenschein sichtbar)
        if ($isNewMoon) {
            $description = 'Neumond steht tagsüber direkt nahe der Sonne und bleibt unsichtbar.';
        } elseif ($isFullMoon) {
            $description = "Vollmond leuchtet die gesamte Nacht mit maximaler Helligkeit ({$illumPct}%).";
        } elseif (mb_stripos($moonPhaseName, 'zunehm') !== false) {
            $description = "Zunehmender Mond ({$illumPct}% beleuchtet) – bereits tagsüber am Nachmittag sowie am Abend- und Nachthimmel sichtbar.";
        } elseif (mb_stripos($moonPhaseName, 'abnehm') !== false) {
            $description = "Abnehmender Mond ({$illumPct}% beleuchtet) – ab der zweiten Nachthälfte und tagsüber bis in den Vormittag/Mittag sichtbar.";
        } else {
            $description = "{$moonPhaseName} mit {$illumPct}% Beleuchtung (auch tagsüber über dem Horizont sichtbar).";
        }

        // Auf- und Untergangszeiten ermitteln
        $todayRiseStr = $forecast['daily']['moonrise'][0] ?? null;
        $todaySetStr = $forecast['daily']['moonset'][0] ?? null;
        $tomorrowRiseStr = $forecast['daily']['moonrise'][1] ?? null;
        $tomorrowSetStr = $forecast['daily']['moonset'][1] ?? null;

        $riseTime = null;
        $setTime = null;
        $visibilityWindow = null;

        if ($todayRiseStr) {
            $tRise = strtotime(is_array($todayRiseStr) ? (string)$todayRiseStr[0] : (string)$todayRiseStr);
            $tSet = $todaySetStr ? strtotime(is_array($todaySetStr) ? (string)$todaySetStr[0] : (string)$todaySetStr) : null;
            $tTomSet = $tomorrowSetStr ? strtotime(is_array($tomorrowSetStr) ? (string)$tomorrowSetStr[0] : (string)$tomorrowSetStr) : null;

            $riseTime = date('H:i', $tRise) . ' Uhr';

            if ($tSet && $tSet > $tRise) {
                $setTime = date('H:i', $tSet) . ' Uhr';
                $visibilityWindow = date('H:i', $tRise) . ' – ' . date('H:i', $tSet) . ' Uhr';
            } elseif ($tTomSet) {
                $setTime = date('H:i', $tTomSet) . ' Uhr';
                $visibilityWindow = date('H:i', $tRise) . ' – ' . date('H:i', $tTomSet) . ' Uhr';
            } elseif ($tSet) {
                $setTime = date('H:i', $tSet) . ' Uhr';
                $visibilityWindow = date('H:i', $tRise) . ' – ' . date('H:i', $tSet) . ' Uhr';
            } else {
                $visibilityWindow = 'ab ' . date('H:i', $tRise) . ' Uhr';
            }
        } elseif ($tomorrowRiseStr) {
            $tTomRise = strtotime(is_array($tomorrowRiseStr) ? (string)$tomorrowRiseStr[0] : (string)$tomorrowRiseStr);
            $riseTime = date('H:i', $tTomRise) . ' Uhr';
            if ($tomorrowSetStr) {
                $tTomSet = strtotime(is_array($tomorrowSetStr) ? (string)$tomorrowSetStr[0] : (string)$tomorrowSetStr);
                $setTime = date('H:i', $tTomSet) . ' Uhr';
                $visibilityWindow = date('H:i', $tTomRise) . ' – ' . date('H:i', $tTomSet) . ' Uhr';
            } else {
                $visibilityWindow = 'ab ' . date('H:i', $tTomRise) . ' Uhr';
            }
        } elseif ($todaySetStr) {
            $tSet = strtotime(is_array($todaySetStr) ? (string)$todaySetStr[0] : (string)$todaySetStr);
            $setTime = date('H:i', $tSet) . ' Uhr';
            $visibilityWindow = 'bis ' . date('H:i', $tSet) . ' Uhr';
        }

        // Falls keine Messdaten vorliegen: harmonische astronomische Schätzung anhand der Phase.
        // Da der Mond auch bei Sonnenschein sichtbar ist, umfasst der Sichtbarkeitszeitraum
        // die gesamte Zeit über dem Horizont (Aufgang bis Untergang, inkl. Tageslicht).
        if (!$visibilityWindow && !$isNewMoon) {
            $isWaning = (mb_stripos($moonPhaseName, 'abnehm') !== false || mb_stripos($moonPhaseName, 'letztes') !== false || ($rawPhase !== null && $rawPhase > 0.5));
            if ($isFullMoon) {
                $riseTime = '18:15 Uhr';
                $setTime = '07:30 Uhr';
                $visibilityWindow = '18:15 – 07:30 Uhr';
            } elseif (!$isWaning) {
                $riseTime = '13:15 Uhr';
                $setTime = '23:45 Uhr';
                $visibilityWindow = '13:15 – 23:45 Uhr';
            } else {
                $riseTime = '00:30 Uhr';
                $setTime = '13:00 Uhr';
                $visibilityWindow = '00:30 – 13:00 Uhr';
            }
        }

        $bestTime = $isFullMoon ? 'Ganze Nacht' : (mb_stripos($moonPhaseName, 'zunehm') !== false ? 'Abend / Vormitternacht' : (mb_stripos($moonPhaseName, 'abnehm') !== false ? 'Zweite Nachthälfte' : 'Nacht'));
        $magnitude = $isFullMoon ? -12.7 : ($illumPct > 50 ? -11.5 : -9.5);

        return [
            'name' => 'Moon',
            'name_de' => 'Mond',
            'phase_name' => $moonPhaseName,
            'illumination' => $moonIllum,
            'illumination_pct' => $illumPct,
            'is_new_moon' => $isNewMoon,
            'is_visible' => !$isNewMoon,
            'color' => '#f8fafc',
            'description' => $description,
            'rise_time' => $riseTime,
            'set_time' => $setTime,
            'visibility_window' => $visibilityWindow,
            'magnitude' => $magnitude,
            'best_time' => $bestTime,
        ];
    }

    private function getMoonPhaseNameFromRaw(float $phase): string
    {
        if ($phase < 0.03 || $phase > 0.97) return 'Neumond';
        if ($phase < 0.22) return 'Zunehmende Sichel';
        if ($phase <= 0.28) return 'Erstes Viertel';
        if ($phase < 0.47) return 'Zunehmender Dreiviertelmond';
        if ($phase <= 0.53) return 'Vollmond';
        if ($phase < 0.72) return 'Abnehmender Dreiviertelmond';
        if ($phase <= 0.78) return 'Letztes Viertel';
        return 'Abnehmende Sichel';
    }

    /**
     * Extrahiert eine Uhrzeit (HH:MM) als Minuten seit 00:00 (0..1439).
     */
    private function extractTimeMinutes(?string $str): ?int
    {
        if ($str === null || trim($str) === '') {
            return null;
        }
        if (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $str, $m)) {
            return ((int)$m[1]) * 60 + ((int)$m[2]);
        }
        return null;
    }

    /**
     * Formatiert Minuten seit 00:00 (auch > 1440) als HH:MM.
     */
    private function formatMinutesToTime(int $minutes): string
    {
        $norm = (($minutes % 1440) + 1440) % 1440;
        return sprintf('%02d:%02d', intdiv($norm, 60), $norm % 60);
    }

    /**
     * Ermittelt Sonnenuntergang, bürgerliche Dämmerung und Sonnenaufgang am Folgetag für Leipzig
     * als Minuten relativ zu heute 00:00 (sodass der Morgen des Folgetages im Bereich 1440..2880 liegt).
     *
     * @return array{sunset_min: int, civil_dusk_min: int, civil_dawn_next_min: int, sunrise_next_min: int}
     */
    private function getNightDarknessBounds(): array
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $todayNoon = (new \DateTimeImmutable('today 12:00:00', $tz))->getTimestamp();
        $tomorrowNoon = (new \DateTimeImmutable('tomorrow 12:00:00', $tz))->getTimestamp();

        $sunToday = date_sun_info($todayNoon, self::LEIPZIG_LAT, self::LEIPZIG_LON);
        $sunTomorrow = date_sun_info($tomorrowNoon, self::LEIPZIG_LAT, self::LEIPZIG_LON);

        $sunsetTs = isset($sunToday['sunset']) && is_int($sunToday['sunset'])
            ? $sunToday['sunset']
            : (new \DateTimeImmutable('today 18:30:00', $tz))->getTimestamp();
        $civilDuskTs = isset($sunToday['civil_twilight_end']) && is_int($sunToday['civil_twilight_end'])
            ? $sunToday['civil_twilight_end']
            : ($sunsetTs + 35 * 60);

        $sunriseNextTs = isset($sunTomorrow['sunrise']) && is_int($sunTomorrow['sunrise'])
            ? $sunTomorrow['sunrise']
            : (new \DateTimeImmutable('tomorrow 07:00:00', $tz))->getTimestamp();
        $civilDawnNextTs = isset($sunTomorrow['civil_twilight_begin']) && is_int($sunTomorrow['civil_twilight_begin'])
            ? $sunTomorrow['civil_twilight_begin']
            : ($sunriseNextTs - 35 * 60);

        $toClockMin = static function (int $ts) use ($tz): int {
            $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
            return ((int)$dt->format('G')) * 60 + ((int)$dt->format('i'));
        };

        return [
            'sunset_min' => $toClockMin($sunsetTs),
            'civil_dusk_min' => $toClockMin($civilDuskTs),
            'civil_dawn_next_min' => 1440 + $toClockMin($civilDawnNextTs),
            'sunrise_next_min' => 1440 + $toClockMin($sunriseNextTs),
        ];
    }

    /**
     * Trennt bei Planeten die astronomische Horizontzeit (Aufgang bis Untergang) von der
     * tatsächlichen optischen Sichtbarkeit bei Dunkelheit/Dämmerung (ohne Tageslicht-Überstrahlung).
     *
     * @param array<int, array<string, mixed>> $planets
     * @return array<int, array<string, mixed>>
     */
    private function enrichPlanetsWithVisibility(array $planets): array
    {
        $bounds = $this->getNightDarknessBounds();

        foreach ($planets as &$p) {
            $riseMin = $this->extractTimeMinutes(isset($p['rise_time']) ? (string)$p['rise_time'] : null);
            $setMin = $this->extractTimeMinutes(isset($p['set_time']) ? (string)$p['set_time'] : null);

            if ($riseMin === null && !empty($p['visible_from'])) {
                $riseMin = $this->extractTimeMinutes((string)$p['visible_from']);
            }
            if ($setMin === null && !empty($p['visible_to'])) {
                $setMin = $this->extractTimeMinutes((string)$p['visible_to']);
            }

            // Falls nur ein kombinierter String in visibility_window / visibility_time vorliegt
            $rawWinStr = (string)($p['visibility_window'] ?? ($p['visibility_time'] ?? ''));
            if (($riseMin === null || $setMin === null) && $rawWinStr !== '') {
                if (preg_match_all('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $rawWinStr, $matches) && count($matches[0]) >= 2) {
                    $riseMin = $riseMin ?? $this->extractTimeMinutes($matches[0][0]);
                    $setMin = $setMin ?? $this->extractTimeMinutes($matches[0][1]);
                }
            }

            // 1. Astronomisches Horizont-Fenster (Aufgang – Untergang, unabhängig vom Sonnenlicht)
            if ($riseMin !== null && $setMin !== null) {
                $p['rise_time'] = $this->formatMinutesToTime($riseMin);
                $p['set_time'] = $this->formatMinutesToTime($setMin);
                $p['astronomical_window'] = $p['rise_time'] . ' – ' . $p['set_time'] . ' Uhr';
            } elseif ($riseMin !== null) {
                $p['astronomical_window'] = 'ab ' . $this->formatMinutesToTime($riseMin) . ' Uhr';
            } elseif ($setMin !== null) {
                $p['astronomical_window'] = 'bis ' . $this->formatMinutesToTime($setMin) . ' Uhr';
            } else {
                $p['astronomical_window'] = $rawWinStr !== '' ? $rawWinStr : null;
            }

            $wasMarkedUp = !empty($p['is_visible']);

            // Wenn weder Auf- noch Untergangszeit ermittelbar sind, bisherigen Status beibehalten
            if ($riseMin === null || $setMin === null) {
                $p['is_astronomically_up'] = $wasMarkedUp;
                $p['is_daylight_only'] = false;
                if (empty($p['visibility_window']) && !empty($p['astronomical_window'])) {
                    $p['visibility_window'] = $p['astronomical_window'];
                }
                continue;
            }

            // 2. Dunkelheits-/Dämmerungsgrenzen je nach Helligkeit (Magnitude) des Planeten:
            // Sehr helle Planeten (Venus, Jupiter: mag <= -1.5) sind bereits ~20 Min nach Sonnenuntergang sichtbar,
            // schwächere Planeten erst ab Ende der bürgerlichen Dämmerung (~35 Min nach Sonnenuntergang).
            $mag = isset($p['magnitude']) && is_numeric($p['magnitude']) ? (float)$p['magnitude'] : 1.0;
            if ($mag <= -1.5) {
                $darkStart = min($bounds['civil_dusk_min'], $bounds['sunset_min'] + 20);
                $darkEnd = max($bounds['civil_dawn_next_min'], $bounds['sunrise_next_min'] - 20);
            } else {
                $darkStart = $bounds['civil_dusk_min'];
                $darkEnd = $bounds['civil_dawn_next_min'];
            }

            // 3. Über-Horizont-Intervalle auf dem 48h-Zeitstrahl [0..2880] bilden
            $horizonIntervals = [];
            if ($riseMin <= $setMin) {
                $horizonIntervals[] = [$riseMin, $setMin];
                $horizonIntervals[] = [$riseMin + 1440, $setMin + 1440];
            } else {
                // Über Mitternacht (z. B. Aufgang 21:15, Untergang 08:40)
                $horizonIntervals[] = [0, $setMin];
                $horizonIntervals[] = [$riseMin, $setMin + 1440];
                $horizonIntervals[] = [$riseMin + 1440, 2880];
            }

            // Schnittmenge mit dem Nachtfenster [darkStart, darkEnd] berechnen (mind. 10 Minuten Dauer)
            $darkWindows = [];
            foreach ($horizonIntervals as [$hStart, $hEnd]) {
                $oStart = max($hStart, $darkStart);
                $oEnd = min($hEnd, $darkEnd);
                if ($oEnd - $oStart >= 10) {
                    $darkWindows[] = [$oStart, $oEnd];
                }
            }

            $p['is_astronomically_up'] = $wasMarkedUp || ($setMin !== $riseMin);

            // 4. Prüfen ob "best_time" (oft der astronomische Höchststand/Meridiandurchgang) ins Tageslicht fällt
            $rawBestTime = isset($p['best_time']) ? trim((string)$p['best_time']) : '';
            $bestMin = $this->extractTimeMinutes($rawBestTime);
            $isBestInDaylight = false;
            if ($bestMin !== null) {
                // Auf den 48h-Strahl abbilden: Zeiten vor Mittag gehören zum Folgetag (1440+), ab Mittag zu heute
                $bestTimelineMin = ($bestMin < 720) ? ($bestMin + 1440) : $bestMin;
                if ($bestTimelineMin < $darkStart || $bestTimelineMin > $darkEnd) {
                    $isBestInDaylight = true;
                    $p['astronomical_best_time'] = $this->formatMinutesToTime($bestMin) . ' Uhr (Tag)';
                }
            }

            if (!empty($darkWindows) && $wasMarkedUp) {
                // Planet ist nachts/in der Dämmerung tatsächlich beobachtbar
                $p['is_visible'] = true;
                $p['is_daylight_only'] = false;

                $formattedWindows = [];
                foreach ($darkWindows as [$wStart, $wEnd]) {
                    $formattedWindows[] = $this->formatMinutesToTime($wStart) . ' – ' . $this->formatMinutesToTime($wEnd);
                }
                $p['visibility_window'] = implode(' & ', $formattedWindows) . ' Uhr';

                // Wenn die bisherige "Beste Zeit" mitten am Tag lag (z. B. 12:00 Uhr), auf beste Nacht-/Dämmerungszeit korrigieren
                if ($isBestInDaylight) {
                    [$mainStart, $mainEnd] = $darkWindows[0];
                    if ($mainStart === $darkStart && $mainEnd < $darkEnd) {
                        // Am Abendhimmel nach Sonnenuntergang am höchsten, bevor er untergeht
                        $p['best_time'] = $this->formatMinutesToTime($mainStart) . ' Uhr (Abenddämmerung)';
                    } elseif ($mainEnd === $darkEnd && $mainStart > $darkStart) {
                        // Am Morgenhimmel vor Sonnenaufgang am höchsten
                        $p['best_time'] = $this->formatMinutesToTime($mainEnd) . ' Uhr (Morgendämmerung)';
                    } else {
                        $midDark = (int)round(($mainStart + $mainEnd) / 2);
                        $p['best_time'] = $this->formatMinutesToTime($midDark) . ' Uhr';
                    }
                }
            } else {
                // Planet steht nur tagsüber über dem Horizont (vom Sonnenlicht überstrahlt) oder komplett unter dem Horizont
                $p['is_visible'] = false;
                $p['is_daylight_only'] = $wasMarkedUp;
                $p['visibility_window'] = null;
                if ($wasMarkedUp) {
                    $p['best_time'] = 'Tagsüber überstrahlt';
                }
            }
        }
        unset($p);

        return $planets;
    }

    /**
     * Standard-Planeten für die Anzeige, falls noch keine Ingest-Daten vorliegen.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultPlanets(): array
    {
        return [
            [
                'name' => 'Venus',
                'name_de' => 'Venus',
                'color' => '#FFF2A7',
                'is_visible' => true,
                'altitude_deg' => 18,
                'azimuth_deg' => 250,
                'direction' => 'WSW',
                'magnitude' => -4.1,
                'rise_time' => '07:30',
                'set_time' => '20:45',
                'visibility_window' => '19:45 – 20:45 Uhr',
                'best_time' => 'Dämmerung',
                'description' => 'Brillant strahlender Abendstern tief im Westen.',
            ],
            [
                'name' => 'Jupiter',
                'name_de' => 'Jupiter',
                'color' => '#E8C59A',
                'is_visible' => true,
                'altitude_deg' => 52,
                'azimuth_deg' => 180,
                'direction' => 'S',
                'magnitude' => -2.6,
                'rise_time' => '21:15',
                'set_time' => '08:40',
                'visibility_window' => '21:15 – 06:00 Uhr',
                'best_time' => '01:30 Uhr',
                'description' => 'Sehr hell und die ganze Nacht über hoch am Südhimmel sichtbar.',
            ],
            [
                'name' => 'Saturn',
                'name_de' => 'Saturn',
                'color' => '#F4D495',
                'is_visible' => true,
                'altitude_deg' => 32,
                'azimuth_deg' => 165,
                'direction' => 'SSW',
                'magnitude' => 0.6,
                'rise_time' => '18:50',
                'set_time' => '04:10',
                'visibility_window' => '19:30 – 04:10 Uhr',
                'best_time' => '23:00 Uhr',
                'description' => 'Ruhig leuchtender Ringplanet im Sternbild Wassermann.',
            ],
            [
                'name' => 'Mars',
                'name_de' => 'Mars',
                'color' => '#E05A47',
                'is_visible' => true,
                'altitude_deg' => 45,
                'azimuth_deg' => 135,
                'direction' => 'SO',
                'magnitude' => -0.2,
                'rise_time' => '22:40',
                'set_time' => '11:15',
                'visibility_window' => '22:40 – 05:30 Uhr',
                'best_time' => '04:00 Uhr',
                'description' => 'Rötlich funkelnd in der zweiten Nachthälfte.',
            ],
        ];
    }

    /**
     * Standard-Ereignisse als Kalenderbasis für Leipzig.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultEvents(): array
    {
        $todayTs = strtotime('today');
        $todayIso = date('Y-m-d', $todayTs);
        $events = [];

        foreach (['orionids', 'leonids', 'geminids', 'quadrantids', 'perseids'] as $sKey) {
            $known = self::KNOWN_METEOR_SHOWERS[$sKey];
            $win = $this->resolveShowerWindow(
                $known['start'][0],
                $known['start'][1],
                $known['peak'][0],
                $known['peak'][1],
                $known['end'][0],
                $known['end'][1],
                $todayTs
            );
            $peakYear = substr($win['peak_date_iso'], 0, 4);

            $events[] = [
                'event_key' => "{$sKey}-{$peakYear}",
                'event_type' => 'meteor_shower',
                'title' => $known['title'],
                'description' => $known['description'],
                'event_date' => $win['peak_date_iso'],
                'peak_time' => $win['peak_date_iso'] . ' 02:00:00',
                'end_date' => $win['end_date_iso'],
                'magnitude' => null,
                'visibility_rating' => $known['zhr'] >= 80 ? 'great' : 'good',
                'details' => [
                    'zhr' => $known['zhr'],
                    'radiant' => $known['radiant'],
                    'activity_period' => $win['activity_period'],
                    'peak_date' => $win['peak_date'],
                    'end_date_formatted' => $win['end_date_formatted'],
                    'is_active' => $win['is_active'],
                    'is_peak' => $win['is_peak'],
                    'is_post_peak' => $win['is_post_peak'],
                    'intensity' => $win['intensity'],
                    'intensity_pct' => $win['intensity_pct'],
                    'trend' => $win['trend'],
                ],
            ];
        }

        if ('2026-08-12' >= $todayIso) {
            $events[] = [
                'event_key' => 'solar-eclipse-2026',
                'event_type' => 'eclipse',
                'title' => 'Große Partielle Sonnenfinsternis über Leipzig',
                'description' => 'In Leipzig wird die Sonne am Spätnachmittag zu rund 87% vom Neumond bedeckt – ein spektakuläres Himmelsereignis!',
                'event_date' => '2026-08-12',
                'peak_time' => '2026-08-12 19:15:00',
                'end_date' => '2026-08-12',
                'magnitude' => 0.87,
                'visibility_rating' => 'great',
                'details' => ['coverage_pct' => 87, 'type' => 'Partielle Sonnenfinsternis'],
            ];
        }

        usort($events, static fn($a, $b) => strcmp($a['event_date'], $b['event_date']));
        return $events;
    }
}
