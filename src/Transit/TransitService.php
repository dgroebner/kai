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

            // Richtungsfilter (Grobe Plausibilitätsprüfung für Leipzig)
            if ($isReturn) {
                // Auf dem Rückweg nach Meusdorf
                if (!empty($profile['origin_station_name']) && stripos($profile['origin_station_name'], 'Meusdorf') !== false) {
                    if (stripos($dep['direction'], 'Meusdorf') === false && stripos($dep['direction'], 'Probstheida') === false) {
                        // Bei fest vorgegebener Linie wie 15 nur Richtungen nach Meusdorf akzeptieren
                        if (in_array($dep['line'], ['15', 'Str 15'], true)) {
                            continue;
                        }
                    }
                }
            } else {
                // Auf dem Hinweg ab Meusdorf
                if (!empty($profile['origin_station_name']) && stripos($profile['origin_station_name'], 'Meusdorf') !== false) {
                    // Von Meusdorf Richtung Stadtzentrum (Miltitz, Hauptbahnhof etc.)
                    if (stripos($dep['direction'], 'Meusdorf') !== false) {
                        continue;
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
        if (!empty($profile['via_station_id']) && !$isReturn && count($effectiveDepartures) > 0) {
            $transferStatus = $this->evaluateTransferLeg(
                $profile,
                $effectiveDepartures[0],
                $now
            );
        }

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
            'departures' => array_slice($effectiveDepartures, 0, 5),
            'transfer_status' => $transferStatus,
        ];
    }

    /**
     * Prüft die Anschlussverbindung an einer Umstiegshaltestelle.
     */
    private function evaluateTransferLeg(array $profile, array $firstLegDep, DateTimeImmutable $now): ?array
    {
        $viaStationId = (string)$profile['via_station_id'];
        $viaStationName = (string)($profile['via_station_name'] ?? 'Umstieg');
        $minBufferMinutes = (int)($profile['min_transfer_minutes'] ?? 4);

        // Fahrzeit von Meusdorf zum Umstieg (ca. 18 Min von Meusdorf zum Augustusplatz/Wilhelm-Leuschner-Platz)
        $estimatedTravelMinutes = 18;
        $arrivalEstimated = $now->modify("+{$estimatedTravelMinutes} minutes")
            ->modify(($firstLegDep['delay_minutes'] >= 0 ? "+{$firstLegDep['delay_minutes']}" : "{$firstLegDep['delay_minutes']}") . ' minutes');

        // Abfahrten an der Umstiegshaltestelle abrufen
        $viaData = $this->getCachedDepartures($viaStationId, $viaStationName, $arrivalEstimated);
        $viaDepartures = $viaData['departures'] ?? [];

        // Nächste Abfahrt nach geschätzter Ankunft + Mindestpuffer suchen
        $earliestConnectingTime = $arrivalEstimated->modify("+{$minBufferMinutes} minutes");

        $connectingDep = null;
        $actualBufferMinutes = null;

        foreach ($viaDepartures as $dep) {
            if ($dep['mode'] === 'bus' && ($profile['transport_modes'] ?? '') === 'tram') {
                continue;
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
