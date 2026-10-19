<?php

namespace Kai\Tools\Transit;

use DateTimeImmutable;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Utils\HolidayService;
use PDO;
use Throwable;

/**
 * Zentraler Service für ÖPNV-Abfahrtszeiten, Pendler-Logik und Anschlussüberwachung.
 */
class TransitService
{
    private const int CACHE_TTL_SECONDS = 60;

    private InsaClient $client;
    private TransitProfileRepository $profileRepo;
    private HolidayService $holidayService;
    private Database $db;
    private Logger $logger;

    public function __construct(
        ?InsaClient $client = null,
        ?TransitProfileRepository $profileRepo = null,
        ?HolidayService $holidayService = null,
        ?Database $db = null,
        ?Logger $logger = null
    ) {
        $this->client = $client ?? new InsaClient();
        $this->profileRepo = $profileRepo ?? new TransitProfileRepository();
        $this->holidayService = $holidayService ?? new HolidayService();
        $this->db = $db ?? Database::getInstance();
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Ermittelt den aktuellen Status eines Profils (Abfahrten, Pünktlichkeit, Richtung).
     *
     * @param array $profile Pendlerprofil-Datensatz
     * @param string|null $directionOverride 'outbound' (Hinfahrt), 'return' (Rückfahrt) oder null (automatisch)
     * @param int $offsetMinutes Manuelle Zeitverschiebung in Minuten (z. B. +45 Min bei Unterrichtsausfall)
     * @return array<string, mixed>
     */
    public function getProfileStatus(array $profile, ?string $directionOverride = null, int $offsetMinutes = 0): array
    {
        $now = (new DateTimeImmutable())->modify(($offsetMinutes >= 0 ? "+{$offsetMinutes}" : "{$offsetMinutes}") . ' minutes');
        $dayOfWeek = (int)$now->format('N'); // 1 = Mo, 7 = So
        $currentTime = $now->format('H:i:s');

        // Prüfen, ob das Profil heute aktiv ist
        $activeDays = $profile['active_days'] ?? [1, 2, 3, 4, 5];
        $isScheduledDay = in_array($dayOfWeek, $activeDays, true);

        // Ferien- und Feiertagsprüfung für Schulprofile
        $isHolidayOrVacation = false;
        $holidayName = null;
        if (!empty($profile['only_school_days'])) {
            $currentHoliday = $this->holidayService->getCurrentHoliday($now->format('Y-m-d'));
            $publicHoliday = $this->holidayService->getPublicHoliday($now->format('Y-m-d'));
            if ($currentHoliday !== null || $publicHoliday !== null) {
                $isHolidayOrVacation = true;
                $holidayName = $currentHoliday['name'] ?? ($publicHoliday['name'] ?? 'Schulfreier Tag');
            }
        }

        // Richtung bestimmen: Bis 13:00 Uhr standardmäßig Hinfahrt, danach Rückfahrt
        $isReturn = false;
        if ($directionOverride === 'return') {
            $isReturn = true;
        } elseif ($directionOverride === 'outbound') {
            $isReturn = false;
        } else {
            $morningStart = ($profile['morning_window_start'] ?? '06:30') . ':00';
            $morningEnd = ($profile['morning_window_end'] ?? '09:00') . ':00';
            $eveningStart = ($profile['evening_window_start'] ?? '15:00') . ':00';
            
            // Wenn nach 13:00 Uhr oder im Abendfenster -> Rückfahrt
            if ($currentTime >= '13:00:00' || $currentTime >= $eveningStart) {
                $isReturn = true;
            }
        }

        $fromStationId = $isReturn ? $profile['destination_station_id'] : $profile['origin_station_id'];
        $fromStationName = $isReturn ? $profile['destination_station_name'] : $profile['origin_station_name'];
        $toStationId = $isReturn ? $profile['origin_station_id'] : $profile['destination_station_id'];
        $toStationName = $isReturn ? $profile['origin_station_name'] : $profile['destination_station_name'];

        // Gecachte Routen- und Richtungsprofile abrufen (24h Cache)
        $routeDirections = $this->getProfileRouteDirections($profile);
        $allowedDirsForLeg = $isReturn
            ? ($routeDirections['return']['allowed_directions'] ?? [])
            : ($routeDirections['outbound']['allowed_directions'] ?? []);
        $viaAllowedDirsForLeg = $isReturn
            ? ($routeDirections['return']['via_allowed_directions'] ?? [])
            : ($routeDirections['outbound']['via_allowed_directions'] ?? []);

        // Abfahrten an der Abfahrtshaltestelle abrufen
        $departuresData = $this->getCachedDepartures($fromStationId, $fromStationName, $now);
        $rawDepartures = $departuresData['departures'] ?? [];

        // Nach Linie und Richtung filtern
        $preferredLines = $this->parseLines($profile['preferred_lines'] ?? null);
        $transportMode = $profile['transport_modes'] ?? 'all';

        $filteredDepartures = [];
        foreach ($rawDepartures as $dep) {
            // Verkehrsmittel-Filter (z. B. nur Tram)
            if ($transportMode === 'tram' && $dep['mode'] !== 'tram') {
                continue;
            }
            if ($transportMode === 'bus' && $dep['mode'] !== 'bus') {
                continue;
            }

            // Linien-Filter
            if (!empty($preferredLines) && !in_array($dep['line'], $preferredLines, true)) {
                continue;
            }

            // Richtungsfilterung basierend auf dem gelernten/gecachten Linienverlauf
            $lineKey = $dep['line'];
            if (!empty($allowedDirsForLeg[$lineKey])) {
                $matches = false;
                foreach ($allowedDirsForLeg[$lineKey] as $allowedDir) {
                    if (self::matchDirection($dep['direction'], $allowedDir)) {
                        $matches = true;
                        break;
                    }
                }
                if (!$matches) {
                    continue; // Gegenrichtung verwerfen!
                }
            } else {
                // Heuristischer Fallback für ungelernte Linien
                if ($isReturn) {
                    if (!empty($profile['origin_station_name']) && stripos($profile['origin_station_name'], 'Meusdorf') !== false) {
                        if (in_array($dep['line'], ['15', 'Str 15'], true)) {
                            if (stripos($dep['direction'], 'Meusdorf') === false && stripos($dep['direction'], 'Probstheida') === false) {
                                continue;
                            }
                        }
                    }
                } else {
                    if (!empty($profile['origin_station_name']) && stripos($profile['origin_station_name'], 'Meusdorf') !== false) {
                        if (stripos($dep['direction'], 'Meusdorf') !== false) {
                            continue;
                        }
                    }
                }
            }

            $filteredDepartures[] = $dep;
        }

        // Falls Filter alles verworfen hat, die ungefilterten Linien der Station verwenden (Vermeidung von Leeranzeigen)
        $effectiveDepartures = !empty($filteredDepartures) ? $filteredDepartures : $rawDepartures;

        // Verspätungsstatus der nächsten Fahrt ermitteln
        $nextDeparture = $effectiveDepartures[0] ?? null;
        $statusSeverity = 'success'; // success, warning, danger
        $statusText = 'Pünktlich';
        $maxDelay = 0;

        if ($nextDeparture !== null) {
            if ($nextDeparture['is_cancelled']) {
                $statusSeverity = 'danger';
                $statusText = 'Fahrt fällt aus!';
            } elseif ($nextDeparture['delay_minutes'] >= 5) {
                $statusSeverity = 'danger';
                $statusText = "+{$nextDeparture['delay_minutes']} Min Verspätung";
                $maxDelay = $nextDeparture['delay_minutes'];
            } elseif ($nextDeparture['delay_minutes'] >= 2) {
                $statusSeverity = 'warning';
                $statusText = "+{$nextDeparture['delay_minutes']} Min Verzögerung";
                $maxDelay = $nextDeparture['delay_minutes'];
            }
        }

        // Umstiegs- / Anschlussprüfung (falls via_station konfiguriert)
        $transferStatus = null;
        if (!empty($profile['via_station_id']) && count($effectiveDepartures) > 0) {
            $transferStatus = $this->evaluateTransferLeg(
                $profile,
                $effectiveDepartures[0],
                $now,
                $viaAllowedDirsForLeg
            );
        }

        // Tür-zu-Tür Verbindungsempfehlungen abrufen (inkl. Schnell-Alternativen)
        $fromStationDesc = ['id' => $fromStationId, 'name' => $fromStationName];
        $toStationDesc = ['id' => $toStationId, 'name' => $toStationName];
        $viaStationDesc = !empty($profile['via_station_name']) ? ['id' => $profile['via_station_id'], 'name' => $profile['via_station_name']] : null;

        $tripRecommendations = $this->getCachedTripRecommendations($fromStationDesc, $toStationDesc, $viaStationDesc, $now);

        return [
            'profile_id' => $profile['id'],
            'profile_name' => $profile['profile_name'],
            'direction' => $isReturn ? 'return' : 'outbound',
            'direction_label' => $isReturn ? 'Rückfahrt' : 'Hinfahrt',
            'from_station' => $fromStationName,
            'from_station_id' => $fromStationId,
            'to_station' => $toStationName,
            'to_station_id' => $toStationId,
            'is_scheduled_day' => $isScheduledDay,
            'is_holiday_or_vacation' => $isHolidayOrVacation,
            'holiday_name' => $holidayName,
            'status_severity' => $statusSeverity,
            'status_text' => $statusText,
            'max_delay' => $maxDelay,
            'offset_minutes' => $offsetMinutes,
            'query_time' => $now->format('H:i'),
            'next_departure' => $nextDeparture,
            'departures' => array_slice($effectiveDepartures, 1, 5),
            'transfer_status' => $transferStatus,
            'trip_recommendations' => $tripRecommendations,
        ];
    }

    /**
     * Prüft die Anschlussverbindung an einer Umstiegshaltestelle.
     *
     * @param array $profile
     * @param array $firstLegDep
     * @param DateTimeImmutable $now
     * @param array<string, string[]> $viaAllowedDirs
     * @return array<string, mixed>|null
     */
    private function evaluateTransferLeg(
        array $profile,
        array $firstLegDep,
        DateTimeImmutable $now,
        array $viaAllowedDirs = []
    ): ?array {
        $viaStationId = (string)$profile['via_station_id'];
        $viaStationName = (string)($profile['via_station_name'] ?? 'Umstieg');
        $minBufferMinutes = (int)($profile['min_transfer_minutes'] ?? 4);

        // Fahrzeit zum Umstieg (ca. 15 Min)
        $estimatedTravelMinutes = 15;
        $arrivalEstimated = $now->modify("+{$estimatedTravelMinutes} minutes")
            ->modify(($firstLegDep['delay_minutes'] >= 0 ? "+{$firstLegDep['delay_minutes']}" : "{$firstLegDep['delay_minutes']}") . ' minutes');

        // Abfahrten an der Umstiegshaltestelle abrufen
        $viaData = $this->getCachedDepartures($viaStationId, $viaStationName, $arrivalEstimated);
        $viaDepartures = $viaData['departures'] ?? [];

        $connectingDep = null;
        $actualBufferMinutes = null;

        foreach ($viaDepartures as $dep) {
            if ($dep['mode'] === 'bus' && ($profile['transport_modes'] ?? '') === 'tram') {
                continue;
            }

            // Richtungsfilter am Umstieg (falls im Cache gelernt)
            if (!empty($viaAllowedDirs[$dep['line']])) {
                $matches = false;
                foreach ($viaAllowedDirs[$dep['line']] as $allowedDir) {
                    if (self::matchDirection($dep['direction'], $allowedDir)) {
                        $matches = true;
                        break;
                    }
                }
                if (!$matches) {
                    continue;
                }
            }

            if ($dep['countdown_minutes'] !== null && $dep['countdown_minutes'] >= $minBufferMinutes) {
                $connectingDep = $dep;
                $actualBufferMinutes = $dep['countdown_minutes'];
                break;
            }
        }

        if ($connectingDep === null && !empty($viaDepartures)) {
            $connectingDep = $viaDepartures[0];
            $actualBufferMinutes = $connectingDep['countdown_minutes'] ?? 0;
        }

        $connectionSeverity = 'success';
        $connectionText = 'Anschluss gesichert';

        if ($firstLegDep['delay_minutes'] >= 5) {
            $connectionSeverity = 'danger';
            $connectionText = 'Anschluss gefährdet wegen Verspätung der 1. Bahn!';
        } elseif ($actualBufferMinutes !== null && $actualBufferMinutes < $minBufferMinutes) {
            $connectionSeverity = 'warning';
            $connectionText = 'Knapper Umstieg (' . $actualBufferMinutes . ' Min Puffer)';
        }

        $connectingLine = $connectingDep['line'] ?? 'Tram';
        $connectingMode = $connectingDep['mode'] ?? 'tram';
        $connectingBadge = $connectingDep['badge'] ?? InsaClient::getLvbBadgeInfo($connectingLine, $connectingMode);

        return [
            'via_station' => $viaStationName,
            'via_station_id' => $viaStationId,
            'connecting_line' => $connectingLine,
            'connecting_mode' => $connectingMode,
            'connecting_badge' => $connectingBadge,
            'connecting_direction' => $connectingDep['direction'] ?? '',
            'connecting_time' => $connectingDep['real_time'] ?? '',
            'connecting_delay' => $connectingDep['delay_minutes'] ?? 0,
            'buffer_minutes' => $actualBufferMinutes,
            'severity' => $connectionSeverity,
            'message' => $connectionText,
        ];
    }

    /**
     * Ermittelt und cacht die gültigen Linien und Zielrichtungen (Hinweg und Rückweg) für ein Profil.
     * Nutzt HAFAS-TripSearch, um exakte Endstationen (dirTxt) pro Teilstrecke zu lernen.
     * Gültigkeit: 24 Stunden.
     *
     * @param array $profile
     * @param bool $forceRefresh
     * @return array{outbound: array{allowed_directions: array<string, string[]>, via_allowed_directions: array<string, string[]>}, return: array{allowed_directions: array<string, string[]>, via_allowed_directions: array<string, string[]>}, cached_at: string}
     */
    public function getProfileRouteDirections(array $profile, bool $forceRefresh = false): array
    {
        $profileId = (int)($profile['id'] ?? 0);
        $cacheKey = "transit_routes_p{$profileId}";
        $conn = $this->db->getConnection();
        $currentTime = date('Y-m-d H:i:s');

        if (!$forceRefresh) {
            try {
                $stmt = $conn->prepare("
                    SELECT payload FROM transit_cache 
                    WHERE cache_key = :key AND expires_at > :now 
                    LIMIT 1
                ");
                $stmt->execute(['key' => $cacheKey, 'now' => $currentTime]);
                $cachedPayload = $stmt->fetchColumn();

                if ($cachedPayload !== false && $cachedPayload !== null) {
                    $decoded = json_decode((string)$cachedPayload, true);
                    if (
                        is_array($decoded)
                        && isset($decoded['outbound'], $decoded['return'])
                        && (!empty($decoded['outbound']['allowed_directions']) || !empty($decoded['return']['allowed_directions']))
                    ) {
                        return $decoded;
                    }
                }
            } catch (Throwable $e) {
                $this->logger->warn('TransitService: Fehler beim Lesen des Routen-Caches', ['error' => $e->getMessage()]);
            }
        }

        // Routen via HAFAS TripSearch analysieren
        $originDesc = ['id' => $profile['origin_station_id'], 'name' => $profile['origin_station_name']];
        $destDesc = ['id' => $profile['destination_station_id'], 'name' => $profile['destination_station_name']];
        $viaDesc = !empty($profile['via_station_name']) ? ['id' => $profile['via_station_id'], 'name' => $profile['via_station_name']] : null;

        // 1. Hinfahrt (Origin -> Destination)
        $outboundTrips = $this->client->searchTrips($originDesc, $destDesc, $viaDesc, 3);
        $outboundAltTrips = ($viaDesc !== null) ? $this->client->searchTrips($originDesc, $destDesc, null, 2) : ['connections' => []];
        $allOutbound = array_merge($outboundTrips['connections'] ?? [], $outboundAltTrips['connections'] ?? []);

        // 2. Rückfahrt (Destination -> Origin)
        $returnTrips = $this->client->searchTrips($destDesc, $originDesc, $viaDesc, 3);
        $returnAltTrips = ($viaDesc !== null) ? $this->client->searchTrips($destDesc, $originDesc, null, 2) : ['connections' => []];
        $allReturn = array_merge($returnTrips['connections'] ?? [], $returnAltTrips['connections'] ?? []);

        $outboundAnalysis = $this->extractAllowedDirectionsFromConnections($allOutbound, $profile['origin_station_name'], $profile['via_station_name'] ?? null);
        $returnAnalysis = $this->extractAllowedDirectionsFromConnections($allReturn, $profile['destination_station_name'], $profile['via_station_name'] ?? null);

        $result = [
            'outbound' => $outboundAnalysis,
            'return' => $returnAnalysis,
            'cached_at' => date('c'),
        ];

        // Im Cache ablegen (24 Stunden TTL) - nur wenn Richtungen ermittelt werden konnten
        if (!empty($outboundAnalysis['allowed_directions']) || !empty($returnAnalysis['allowed_directions'])) {
            try {
                $expiresAt = date('Y-m-d H:i:s', time() + 86400);
                $stmt = $conn->prepare("
                    INSERT INTO transit_cache (cache_key, payload, expires_at)
                    VALUES (:key, :payload, :expires)
                    ON DUPLICATE KEY UPDATE payload = :payload2, expires_at = :expires2
                ");
                $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
                $stmt->execute([
                    'key' => $cacheKey,
                    'payload' => $encoded,
                    'expires' => $expiresAt,
                    'payload2' => $encoded,
                    'expires2' => $expiresAt,
                ]);
            } catch (Throwable $e) {
                $this->logger->warn('TransitService: Fehler beim Schreiben des Routen-Caches', ['error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /**
     * Löscht den gecachten Routen- und Richtungsverlauf für ein Pendlerprofil.
     */
    public function clearProfileCache(int $profileId): void
    {
        try {
            $conn = $this->db->getConnection();
            $stmt = $conn->prepare("DELETE FROM transit_cache WHERE cache_key = :key");
            $stmt->execute(['key' => "transit_routes_p{$profileId}"]);
        } catch (Throwable $e) {
            $this->logger->warn('TransitService: Fehler beim Löschen des Profil-Caches', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Prüft flexibel und fehlertolerant, ob zwei Zielrichtungsangaben übereinstimmen.
     * Berücksichtigt "über"-Zusätze, "Leipzig, "-Präfixe und Teilstrings.
     */
    public static function matchDirection(string $dir1, string $dir2): bool
    {
        $normalize = static function(string $s): string {
            $s = mb_strtolower(trim($s));
            // "Leipzig, " oder "L. " am Anfang entfernen
            $s = preg_replace('/^(?:leipzig,\s*|l\.\s*)/i', '', $s);
            return trim((string)$s);
        };

        $n1 = $normalize($dir1);
        $n2 = $normalize($dir2);

        if ($n1 === '' || $n2 === '') {
            return false;
        }

        if (str_contains($n1, $n2) || str_contains($n2, $n1)) {
            return true;
        }

        // Hauptziel vor "über" vergleichen (z.B. "Böhlitz-Ehrenberg über Hauptbahnhof" -> "Böhlitz-Ehrenberg")
        $main1 = trim(explode(' über ', $n1)[0]);
        $main2 = trim(explode(' über ', $n2)[0]);
        if ($main1 !== '' && $main2 !== '' && ($main1 === $main2 || str_contains($main1, $main2) || str_contains($main2, $main1))) {
            return true;
        }

        return false;
    }

    /**
     * Extrahiert erlaubte Linienrichtungen für Abfahrtshaltestelle und optionale Umstiegshaltestelle.
     *
     * @param array<int, array<string, mixed>> $connections
     * @param string $startStationName
     * @param string|null $viaStationName
     * @return array{allowed_directions: array<string, string[]>, via_allowed_directions: array<string, string[]>}
     */
    private function extractAllowedDirectionsFromConnections(array $connections, string $startStationName, ?string $viaStationName): array
    {
        $startAllowed = [];
        $viaAllowed = [];

        foreach ($connections as $con) {
            $legs = $con['legs'] ?? [];
            $transportLegs = array_values(array_filter($legs, static fn($l) => ($l['type'] ?? '') === 'transport'));
            if (empty($transportLegs)) {
                continue;
            }

            // Erstes Transport-Leg (Start ab Abfahrtshaltestelle)
            $firstLeg = $transportLegs[0];
            $line = $firstLeg['line'] ?? '';
            $dir = trim((string)($firstLeg['direction'] ?? ''));
            if ($line !== '' && $dir !== '') {
                $startAllowed[$line][] = $dir;
            }

            // Folgende Transport-Legs (z. B. Umstieg an Via-Haltestelle)
            if ($viaStationName !== null && $viaStationName !== '' && count($transportLegs) > 1) {
                for ($i = 1; $i < count($transportLegs); $i++) {
                    $leg = $transportLegs[$i];
                    $line = $leg['line'] ?? '';
                    $dir = trim((string)($leg['direction'] ?? ''));
                    if ($line !== '' && $dir !== '') {
                        $viaAllowed[$line][] = $dir;
                    }
                }
            }
        }

        foreach ($startAllowed as $line => $dirs) {
            $startAllowed[$line] = array_values(array_unique($dirs));
        }
        foreach ($viaAllowed as $line => $dirs) {
            $viaAllowed[$line] = array_values(array_unique($dirs));
        }

        return [
            'allowed_directions' => $startAllowed,
            'via_allowed_directions' => $viaAllowed,
        ];
    }

    /**
     * Ruft aktuelle Verbindungsempfehlungen (Tür-zu-Tür) ab und cacht diese für 120 Sekunden.
     *
     * @param array{id: string, name: string} $fromDesc
     * @param array{id: string, name: string} $toDesc
     * @param array{id?: string, name?: string}|null $viaDesc
     * @param DateTimeImmutable $when
     * @return array<int, array<string, mixed>>
     */
    public function getCachedTripRecommendations(
        array $fromDesc,
        array $toDesc,
        ?array $viaDesc,
        DateTimeImmutable $when
    ): array {
        $viaName = $viaDesc['name'] ?? '';
        $cacheKey = 'transit_trips_' . md5($fromDesc['name'] . '_' . $toDesc['name'] . '_' . $viaName) . '_' . $when->format('YmdHi');
        $conn = $this->db->getConnection();
        $currentTime = date('Y-m-d H:i:s');

        try {
            $stmt = $conn->prepare("
                SELECT payload FROM transit_cache 
                WHERE cache_key = :key AND expires_at > :now 
                LIMIT 1
            ");
            $stmt->execute(['key' => $cacheKey, 'now' => $currentTime]);
            $cachedPayload = $stmt->fetchColumn();

            if ($cachedPayload !== false && $cachedPayload !== null) {
                $decoded = json_decode((string)$cachedPayload, true);
                if (is_array($decoded) && !empty($decoded)) {
                    return $decoded;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warn('TransitService: Cache-Lesefehler bei Trips', ['error' => $e->getMessage()]);
        }

        // Live über InsaClient abfragen (sowohl mit Via als auch ohne Via für Alternativen)
        $trips1 = $this->client->searchTrips($fromDesc, $toDesc, $viaDesc, 3, $when);
        $trips2 = ($viaDesc !== null) ? $this->client->searchTrips($fromDesc, $toDesc, null, 2, $when) : ['connections' => []];

        $merged = array_merge($trips1['connections'] ?? [], $trips2['connections'] ?? []);

        // Deduplizieren anhand Abfahrtszeit und Linien-Kette
        $unique = [];
        $seen = [];
        foreach ($merged as $con) {
            $legSignature = implode('>', array_map(static fn($l) => ($l['line'] ?? ($l['type'] ?? '')), $con['legs'] ?? []));
            $sig = ($con['departure_time'] ?? '') . '_' . ($con['arrival_time'] ?? '') . '_' . $legSignature;
            if (!isset($seen[$sig])) {
                $seen[$sig] = true;
                $unique[] = $con;
            }
        }

        // Sortieren nach Abfahrtszeit
        usort($unique, static function ($a, $b) {
            return strcmp((string)($a['departure_time'] ?? ''), (string)($b['departure_time'] ?? ''));
        });

        $topConnections = array_slice($unique, 0, 4);

        // Im Cache speichern (TTL 120 Sekunden, nur wenn Verbindungen gefunden)
        if (!empty($topConnections)) {
            try {
                $expiresAt = date('Y-m-d H:i:s', time() + 120);
                $stmt = $conn->prepare("
                    INSERT INTO transit_cache (cache_key, payload, expires_at)
                    VALUES (:key, :payload, :expires)
                    ON DUPLICATE KEY UPDATE payload = :payload2, expires_at = :expires2
                ");
                $encoded = json_encode($topConnections, JSON_UNESCAPED_UNICODE);
                $stmt->execute([
                    'key' => $cacheKey,
                    'payload' => $encoded,
                    'expires' => $expiresAt,
                    'payload2' => $encoded,
                    'expires2' => $expiresAt,
                ]);
            } catch (Throwable $e) {
                $this->logger->warn('TransitService: Cache-Schreibfehler bei Trips', ['error' => $e->getMessage()]);
            }
        }

        return $topConnections;
    }

    /**
     * Aktualisiert den Routencache aller aktiven Pendlerprofile.
     * Vorgesehen für den täglichen Aufruf via Cronjob (public/shared/mail.php).
     *
     * @return int Anzahl der aktualisierten Profile
     */
    public function refreshAllProfileRoutes(): int
    {
        $profiles = $this->profileRepo->getAll();
        $count = 0;
        foreach ($profiles as $p) {
            if (empty($p['is_active'])) {
                continue;
            }
            try {
                $this->getProfileRouteDirections($p, true);
                $count++;
            } catch (Throwable $e) {
                $this->logger->warn("TransitService: Fehler beim Routenabgleich für Profil {$p['id']}", [
                    'profile' => $p['profile_name'] ?? $p['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    /**
     * Ruft Abfahrten mit Datenbank-Caching (60s TTL) ab, um die API zu schonen.
     */
    public function getCachedDepartures(string $stationId, ?string $stationName = null, ?DateTimeImmutable $when = null): array
    {
        $now = $when ?? new DateTimeImmutable();
        $cacheKey = "transit_stb_{$stationId}_" . $now->format('YmdH'); // Stunden-Cache-Bucket

        $conn = $this->db->getConnection();
        $currentTime = date('Y-m-d H:i:s');

        // Cache prüfen
        try {
            $stmt = $conn->prepare("
                SELECT payload FROM transit_cache 
                WHERE cache_key = :key AND expires_at > :now 
                LIMIT 1
            ");
            $stmt->execute(['key' => $cacheKey, 'now' => $currentTime]);
            $cachedPayload = $stmt->fetchColumn();

            if ($cachedPayload !== false && $cachedPayload !== null) {
                $decoded = json_decode((string)$cachedPayload, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warn('TransitService: Cache-Lesefehler', ['error' => $e->getMessage()]);
        }

        // Live von INSA abrufen
        $data = $this->client->getStationDepartures($stationId, $stationName, 30, $now);

        // Im Cache ablegen
        try {
            $expiresAt = date('Y-m-d H:i:s', time() + self::CACHE_TTL_SECONDS);
            $stmt = $conn->prepare("
                INSERT INTO transit_cache (cache_key, payload, expires_at)
                VALUES (:key, :payload, :expires)
                ON DUPLICATE KEY UPDATE payload = :payload2, expires_at = :expires2
            ");
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
            $stmt->execute([
                'key' => $cacheKey,
                'payload' => $encoded,
                'expires' => $expiresAt,
                'payload2' => $encoded,
                'expires2' => $expiresAt,
            ]);
        } catch (Throwable $e) {
            $this->logger->warn('TransitService: Cache-Schreibfehler', ['error' => $e->getMessage()]);
        }

        return $data;
    }

    /**
     * Sucht Haltestellen anhand einer Zeichenkette.
     */
    public function searchStations(string $query): array
    {
        return $this->client->searchStations($query, 6);
    }

    /**
     * Parst kommagetrennte Liniennummern.
     *
     * @return string[]
     */
    private function parseLines(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $parts = explode(',', $raw);
        return array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));
    }
}
