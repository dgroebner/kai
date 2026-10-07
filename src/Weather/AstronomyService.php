<?php

namespace Kai\Tools\Weather;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use Throwable;

class AstronomyService
{
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
                return [
                    'kp_current' => (float)($row['kp_current'] ?? 0.0),
                    'kp_max_next_24h' => (float)($row['kp_max_next_24h'] ?? 0.0),
                    'kp_forecast' => !empty($row['kp_forecast_json']) ? json_decode($row['kp_forecast_json'], true) : [],
                    'aurora_chance' => (string)($row['aurora_chance'] ?? 'none'),
                    'visible_planets' => $this->enrichPlanetsWithVisibility($planets),
                    'active_meteor_showers' => !empty($row['active_meteor_showers_json']) ? json_decode($row['active_meteor_showers_json'], true) : [],
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
            'active_meteor_showers' => [],
            'moon_phase_name' => 'Halbmond',
            'moon_illumination' => 0.5,
            'summary_text' => 'Geringe Sonnenaktivität, normale Bedingungen.',
            'updated_at' => null,
        ];
    }

    /**
     * Liefert die anstehenden astronomischen Ereignisse ab heute für Leipzig.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getUpcomingEvents(int $limit = 12): array
    {
        if ($this->pdo) {
            try {
                // Abfrage mit Puffer, um nachfolgende Duplikate wiederkehrender Ereignisse filtern zu können
                $fetchLimit = max(24, $limit * 2);
                $stmt = $this->pdo->prepare("
                    SELECT * FROM weather_astronomy_events
                    WHERE event_date >= CURDATE()
                    ORDER BY event_date ASC, peak_time ASC
                    LIMIT :limit
                ");
                $stmt->bindValue(':limit', $fetchLimit, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($rows)) {
                    $events = [];
                    $seenShowers = [];
                    $seenTitles = [];

                    $showerNames = [
                        'quadrantid', 'lyrid', 'aquariid', 'perseid',
                        'orionid', 'leonid', 'geminid', 'ursid'
                    ];

                    foreach ($rows as $r) {
                        $r['details'] = !empty($r['details_json']) ? json_decode($r['details_json'], true) : [];
                        unset($r['details_json']);

                        // Basis-Schlüssel ohne Jahreszahl ermitteln (z. B. "orionids-2026" -> "orionids")
                        $eventKey = (string)($r['event_key'] ?? '');
                        $baseKey = preg_replace('/-\d{4}$/', '', $eventKey);
                        if (empty($baseKey)) {
                            $baseKey = explode('-', $eventKey)[0] ?? $eventKey;
                        }

                        $rawTitle = mb_strtolower(trim((string)($r['title'] ?? '')));
                        $normTitle = trim(preg_replace('/\b20\d{2}\b/', '', $rawTitle));
                        $isMeteorShower = (($r['event_type'] ?? '') === 'meteor_shower');

                        // Erkennen von Meteorschauer-Gruppen (z. B. Orioniden 2026 vs. 2027)
                        $showerTag = null;
                        if ($isMeteorShower) {
                            $keyOrTitle = mb_strtolower($baseKey . ' ' . $rawTitle);
                            foreach ($showerNames as $sName) {
                                if (str_contains($keyOrTitle, $sName)) {
                                    $showerTag = $sName;
                                    break;
                                }
                            }
                            if ($showerTag === null) {
                                $showerTag = $baseKey;
                            }
                        }

                        // Ist dieser Schauer bereits als nächste bevorstehende Instanz in der Liste?
                        if ($showerTag !== null && isset($seenShowers[$showerTag])) {
                            continue;
                        }

                        // Identischer normalisierter Titel bereits erfasst?
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
                        if (count($events) >= $limit) {
                            break;
                        }
                    }

                    return $events;
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
        $now = time();
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

        // Sichtbare Planeten
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
     * Standard-Planeten für die Anzeige, falls noch keine Ingest-Daten vorliegen.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Liefert strukturierte Mond-Informationen inklusive Sichtbarkeitszeitraum für die Anzeige.
     *
     * @param array<string, mixed>|null $forecast
     * @param float|null $overridePhase Optionale Testphase (0.0 bis 1.0)
     * @return array<string, mixed>
     */
    public function getMoonInfo(?array $forecast = null, ?float $overridePhase = null): array
    {
        if ($forecast === null) {
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

        // Dynamische Beschreibung
        if ($isNewMoon) {
            $description = 'Neumond steht tagsüber nahe der Sonne und bleibt nachts unsichtbar.';
        } elseif ($isFullMoon) {
            $description = "Vollmond leuchtet die gesamte Nacht mit maximaler Helligkeit ({$illumPct}%).";
        } elseif (mb_stripos($moonPhaseName, 'zunehm') !== false) {
            $description = "Zunehmender Mond ({$illumPct}% beleuchtet) am Abend- und Nachthimmel zu sehen.";
        } elseif (mb_stripos($moonPhaseName, 'abnehm') !== false) {
            $description = "Abnehmender Mond ({$illumPct}% beleuchtet), vor allem ab Mitternacht bis in die Morgenstunden.";
        } else {
            $description = "{$moonPhaseName} mit {$illumPct}% Beleuchtung am Nachthimmel.";
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
                // Geht heute vor Mitternacht unter
                $setTime = date('H:i', $tSet) . ' Uhr';
                $visibilityWindow = date('H:i', $tRise) . ' – ' . date('H:i', $tSet) . ' Uhr';
            } elseif ($tTomSet) {
                // Geht morgen früh unter
                $setTime = date('H:i', $tTomSet) . ' Uhr';
                $visibilityWindow = date('H:i', $tRise) . ' – ' . date('H:i', $tTomSet) . ' Uhr';
            } elseif ($tSet) {
                $setTime = date('H:i', $tSet) . ' Uhr';
                $visibilityWindow = date('H:i', $tRise) . ' – ' . date('H:i', $tSet) . ' Uhr';
            } else {
                $visibilityWindow = 'ab ' . date('H:i', $tRise) . ' Uhr';
            }
        } elseif ($tomorrowRiseStr) {
            // Geht erst in der zweiten Nachthälfte auf
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
            // Geht heute unter (Stand schon am Himmel)
            $tSet = strtotime(is_array($todaySetStr) ? (string)$todaySetStr[0] : (string)$todaySetStr);
            $setTime = date('H:i', $tSet) . ' Uhr';
            $visibilityWindow = 'bis ' . date('H:i', $tSet) . ' Uhr';
        }

        // Falls keine Messdaten vorliegen: harmonische astronomische Schätzung anhand der Phase
        if (!$visibilityWindow && !$isNewMoon) {
            if ($isFullMoon) {
                $riseTime = '18:45 Uhr';
                $setTime = '06:30 Uhr';
                $visibilityWindow = '18:45 – 06:30 Uhr';
            } elseif ($rawPhase !== null && $rawPhase < 0.5) {
                $riseTime = '13:15 Uhr';
                $setTime = '23:45 Uhr';
                $visibilityWindow = '19:30 – 23:45 Uhr';
            } else {
                $riseTime = '00:30 Uhr';
                $setTime = '13:00 Uhr';
                $visibilityWindow = '00:30 – 06:15 Uhr';
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
     * Ergänzt Planeten-Einträge um einen formatierten Sichtbarkeitszeitraum.
     *
     * @param array<int, array<string, mixed>> $planets
     * @return array<int, array<string, mixed>>
     */
    private function enrichPlanetsWithVisibility(array $planets): array
    {
        foreach ($planets as &$p) {
            if (empty($p['visibility_window'])) {
                if (!empty($p['visibility_time'])) {
                    $p['visibility_window'] = (string)$p['visibility_time'];
                } elseif (!empty($p['visible_from']) && !empty($p['visible_to'])) {
                    $p['visibility_window'] = $p['visible_from'] . ' – ' . $p['visible_to'] . (str_contains((string)$p['visible_to'], 'Uhr') ? '' : ' Uhr');
                } elseif (!empty($p['rise_time']) && !empty($p['set_time'])) {
                    $p['visibility_window'] = $p['rise_time'] . ' – ' . $p['set_time'] . ' Uhr';
                } elseif (!empty($p['rise_time'])) {
                    $p['visibility_window'] = 'ab ' . $p['rise_time'] . ' Uhr';
                } elseif (!empty($p['set_time'])) {
                    $p['visibility_window'] = 'bis ' . $p['set_time'] . ' Uhr';
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
        $year = (int)date('Y');
        $perseidsYear = (date('m-d') > '08-12') ? ($year + 1) : $year;
        return [
            [
                'event_key' => "orionids-{$year}",
                'event_type' => 'meteor_shower',
                'title' => 'Orioniden (Meteorschauer)',
                'description' => 'Sternschnuppenstrom aus Staubspuren des Kometen Halley. Ca. 15–20 Meteore pro Stunde.',
                'event_date' => "{$year}-10-21",
                'peak_time' => "{$year}-10-21 02:00:00",
                'magnitude' => null,
                'visibility_rating' => 'good',
                'details' => [
                    'zhr' => 20,
                    'radiant' => 'Orion',
                    'activity_period' => '02.10. – 07.11.',
                    'peak_date' => '21.10.',
                ],
            ],
            [
                'event_key' => "leonids-{$year}",
                'event_type' => 'meteor_shower',
                'title' => 'Leoniden-Maximum',
                'description' => 'Sehr schnelle Sternschnuppen mit feinen Rauchschweifen.',
                'event_date' => "{$year}-11-17",
                'peak_time' => "{$year}-11-17 03:00:00",
                'magnitude' => null,
                'visibility_rating' => 'good',
                'details' => [
                    'zhr' => 15,
                    'radiant' => 'Löwe',
                    'activity_period' => '06.11. – 30.11.',
                    'peak_date' => '17.11.',
                ],
            ],
            [
                'event_key' => "geminids-{$year}",
                'event_type' => 'meteor_shower',
                'title' => 'Geminiden (Stärkster Meteorschauer des Jahres)',
                'description' => 'Reichster Sternschnuppenstrom des Jahres mit bis zu 120 oft bunten und hellen Sternschnuppen pro Stunde!',
                'event_date' => "{$year}-12-14",
                'peak_time' => "{$year}-12-14 01:00:00",
                'magnitude' => null,
                'visibility_rating' => 'great',
                'details' => [
                    'zhr' => 120,
                    'radiant' => 'Zwillinge',
                    'activity_period' => '04.12. – 17.12.',
                    'peak_date' => '14.12.',
                ],
            ],
            [
                'event_key' => "quadrantids-" . ($year + 1),
                'event_type' => 'meteor_shower',
                'title' => 'Quadrantiden (Winter-Meteorschauer)',
                'description' => 'Kurzer, aber intensiver Schauer zum Jahresbeginn mit vielen hellen Feuerkugeln.',
                'event_date' => ($year + 1) . "-01-03",
                'peak_time' => ($year + 1) . "-01-03 23:00:00",
                'magnitude' => null,
                'visibility_rating' => 'good',
                'details' => [
                    'zhr' => 80,
                    'radiant' => 'Bärenhüter',
                    'activity_period' => '01.01. – 10.01.',
                    'peak_date' => '03.01.',
                ],
            ],
            [
                'event_key' => "perseids-{$perseidsYear}",
                'event_type' => 'meteor_shower',
                'title' => 'Perseiden (Die Laurentiustränen)',
                'description' => 'Der beliebteste Sommer-Meteorschauer mit bis zu 100 schnellen Sternschnuppen pro Stunde bei milden Sommernächten.',
                'event_date' => "{$perseidsYear}-08-12",
                'peak_time' => "{$perseidsYear}-08-12 23:00:00",
                'magnitude' => null,
                'visibility_rating' => 'great',
                'details' => [
                    'zhr' => 100,
                    'radiant' => 'Perseus',
                    'activity_period' => '17.07. – 24.08.',
                    'peak_date' => '12.08.',
                ],
            ],
            [
                'event_key' => "solar-eclipse-2026",
                'event_type' => 'eclipse',
                'title' => 'Große Partielle Sonnenfinsternis über Leipzig',
                'description' => 'In Leipzig wird die Sonne am Spätnachmittag zu rund 87% vom Neumond bedeckt – ein spektakuläres Himmelsereignis!',
                'event_date' => '2026-08-12',
                'peak_time' => '2026-08-12 19:15:00',
                'magnitude' => 0.87,
                'visibility_rating' => 'great',
                'details' => ['coverage_pct' => 87, 'type' => 'Partielle Sonnenfinsternis'],
            ],
        ];

        $events = array_values(array_filter($events, static fn($e) => ($e['event_date'] ?? '') >= date('Y-m-d')));
        usort($events, static fn($a, $b) => strcmp($a['event_date'], $b['event_date']));
        return $events;
    }
}
