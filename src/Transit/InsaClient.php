<?php

namespace Kai\Tools\Transit;

use DateTimeImmutable;
use DateTimeZone;
use Kai\Tools\Shared\Log\Logger;
use Throwable;

/**
 * HTTP-Client für die HAFAS-Schnittstelle von INSA (Nahverkehrsservice Sachsen-Anhalt / MDV).
 *
 * Ruft den öffentlichen JSON-Endpunkt ab und liefert formatierte Haltestellen-
 * und Abfahrtsdaten inklusive sekundengenauer Echtzeit-Verspätungen.
 */
class InsaClient
{
    private const string ENDPOINT = 'https://reiseauskunft.insa.de/bin/mgate.exe';
    private const string PROTOCOL_VERSION = '1.44';
    private const string AUTH_AID = 'nasa-apps';
    private const string CLIENT_NAME = 'nasaPROD';
    private const string CLIENT_TYPE = 'IPH';
    private const string CLIENT_ID = 'NASA';
    private const string CLIENT_VERSION = '4000200';

    private Logger $logger;
    private int $timeoutSeconds;

    public function __construct(?Logger $logger = null, int $timeoutSeconds = 6)
    {
        $this->logger = $logger ?? new Logger();
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * Sucht Haltestellen anhand eines Suchbegriffs (LocMatch).
     *
     * @return array<int, array{id: string, name: string, type: string}>
     */
    public function searchStations(string $query, int $maxResults = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $payload = [
            'auth' => ['type' => 'AID', 'aid' => self::AUTH_AID],
            'client' => [
                'type' => self::CLIENT_TYPE,
                'id' => self::CLIENT_ID,
                'v' => self::CLIENT_VERSION,
                'name' => self::CLIENT_NAME,
            ],
            'ver' => self::PROTOCOL_VERSION,
            'lang' => 'deu',
            'svcReqL' => [
                [
                    'meth' => 'LocMatch',
                    'req' => [
                        'input' => [
                            'loc' => [
                                'type' => 'S',
                                'name' => $query,
                            ],
                            'maxLoc' => max(1, min($maxResults, 15)),
                            'field' => 'S',
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->sendRequest($payload);
        if ($response === null) {
            return [];
        }

        $svcResItem = $response['svcResL'][0] ?? null;
        if (!is_array($svcResItem) || (($svcResItem['err'] ?? 'OK') !== 'OK')) {
            return [];
        }

        $matchList = $svcResItem['res']['match']['locL'] ?? [];
        $results = [];

        foreach ($matchList as $loc) {
            $extId = (string)($loc['extId'] ?? '');
            $name = (string)($loc['name'] ?? '');
            if ($extId !== '' && $name !== '') {
                $results[] = [
                    'id' => $extId,
                    'name' => $name,
                    'type' => (string)($loc['type'] ?? 'S'),
                ];
            }
        }

        return $results;
    }

    /**
     * Ruft die nächsten Abfahrten an einer Haltestelle ab (StationBoard).
     *
     * @param string $stationId IBNR- oder HAFAS-Stations-ID (z. B. "11559" für Meusdorf)
     * @param string|null $stationName Name der Haltestelle
     * @param int $maxJourneys Maximale Anzahl an Abfahrten
     * @param DateTimeImmutable|null $when Abfragezeitpunkt (Standard: jetzt)
     * @return array{station_name: string, station_id: string, query_time: string, departures: array<int, array<string, mixed>>}
     */
    public function getStationDepartures(
        string $stationId,
        ?string $stationName = null,
        int $maxJourneys = 30,
        ?DateTimeImmutable $when = null
    ): array {
        $now = $when ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        $dateStr = $now->format('Ymd');
        $timeStr = $now->format('His');

        $stbLoc = [
            'type' => 'S',
            'extId' => $stationId,
        ];
        if ($stationName !== null && $stationName !== '') {
            $stbLoc['name'] = $stationName;
        }

        $payload = [
            'auth' => ['type' => 'AID', 'aid' => self::AUTH_AID],
            'client' => [
                'type' => self::CLIENT_TYPE,
                'id' => self::CLIENT_ID,
                'v' => self::CLIENT_VERSION,
                'name' => self::CLIENT_NAME,
            ],
            'ver' => self::PROTOCOL_VERSION,
            'lang' => 'deu',
            'svcReqL' => [
                [
                    'meth' => 'StationBoard',
                    'req' => [
                        'type' => 'DEP',
                        'date' => $dateStr,
                        'time' => $timeStr,
                        'stbLoc' => $stbLoc,
                        'maxJny' => max(1, min($maxJourneys, 30)),
                    ],
                ],
            ],
        ];

        $response = $this->sendRequest($payload);
        if ($response === null) {
            return [
                'station_name' => $stationName ?? $stationId,
                'station_id' => $stationId,
                'query_time' => $now->format('c'),
                'departures' => [],
            ];
        }

        $svcResItem = $response['svcResL'][0] ?? null;
        if (!is_array($svcResItem) || (($svcResItem['err'] ?? 'OK') !== 'OK')) {
            if ($svcResItem && ($svcResItem['err'] ?? 'OK') !== 'OK') {
                $this->logger->warn('InsaClient: StationBoard Fehler in svcRes.', [
                    'err' => $svcResItem['err'] ?? '',
                    'errTxt' => $svcResItem['errTxt'] ?? '',
                ]);
            }
            return [
                'station_name' => $stationName ?? $stationId,
                'station_id' => $stationId,
                'query_time' => $now->format('c'),
                'departures' => [],
            ];
        }

        $svcRes = $svcResItem['res'] ?? [];
        $prodList = $svcRes['common']['prodL'] ?? [];
        $rawJourneys = $svcRes['jnyL'] ?? [];

        $departures = [];
        $referenceTimestamp = $now->getTimestamp();

        foreach ($rawJourneys as $jny) {
            $stbStop = $jny['stbStop'] ?? [];
            $prodIndex = $stbStop['dProdX'] ?? null;
            $prod = ($prodIndex !== null && isset($prodList[$prodIndex])) ? $prodList[$prodIndex] : [];

            $lineRaw = (string)($prod['name'] ?? $prod['line'] ?? '');
            $lineClean = $this->cleanLineName($lineRaw);
            $transportMode = $this->detectTransportMode($lineRaw, $prod);

            $direction = (string)($jny['dirTxt'] ?? '');
            $platform = (string)($stbStop['dPlatfR'] ?? $stbStop['dPlatfS'] ?? '');

            $schedTimeRaw = (string)($stbStop['dTimeS'] ?? '');
            $realTimeRaw = (string)($stbStop['dTimeR'] ?? '');
            $isCancelled = !empty($stbStop['dCncl']) || !empty($jny['isCncl']);

            // Zeitberechnung
            $schedDateTime = $this->parseHafasTime($jny['date'] ?? $dateStr, $schedTimeRaw);
            $realDateTime = ($realTimeRaw !== '') ? $this->parseHafasTime($jny['date'] ?? $dateStr, $realTimeRaw) : $schedDateTime;

            $delayMinutes = 0;
            $hasRealtime = ($realTimeRaw !== '');
            if ($schedDateTime !== null && $realDateTime !== null) {
                $delayMinutes = (int)round(($realDateTime->getTimestamp() - $schedDateTime->getTimestamp()) / 60);
            }

            // Countdown in Minuten ab aktuellem Zeitpunkt
            $effectiveDateTime = $realDateTime ?? $schedDateTime;
            $countdownMinutes = null;
            if ($effectiveDateTime !== null) {
                $countdownMinutes = (int)floor(($effectiveDateTime->getTimestamp() - $referenceTimestamp) / 60);
            }

            $badge = self::getLvbBadgeInfo($lineClean, $transportMode);

            $departures[] = [
                'journey_id' => (string)($jny['jid'] ?? ''),
                'line' => $lineClean,
                'line_raw' => $lineRaw,
                'mode' => $transportMode,
                'badge' => $badge,
                'direction' => $direction,
                'platform' => $platform,
                'scheduled_time' => $schedDateTime?->format('H:i') ?? substr($schedTimeRaw, 0, 4),
                'scheduled_iso' => $schedDateTime?->format('c'),
                'real_time' => $realDateTime?->format('H:i') ?? ($schedDateTime?->format('H:i') ?? ''),
                'real_iso' => $realDateTime?->format('c') ?? $schedDateTime?->format('c'),
                'has_realtime' => $hasRealtime,
                'delay_minutes' => $delayMinutes,
                'countdown_minutes' => $countdownMinutes,
                'is_cancelled' => $isCancelled,
            ];
        }

        return [
            'station_name' => (string)($svcRes['common']['locL'][0]['name'] ?? ($stationName ?? $stationId)),
            'station_id' => $stationId,
            'query_time' => $now->format('c'),
            'departures' => $departures,
        ];
    }

    /**
     * Sucht Verbindungen (Fahrtketten / Routen) zwischen Start und Ziel (TripSearch).
     *
     * @param string|array{name?: string, id?: string} $from
     * @param string|array{name?: string, id?: string} $to
     * @param string|array{name?: string, id?: string}|null $via
     * @param int $numTrips
     * @param DateTimeImmutable|null $when
     * @return array{connections: array<int, array<string, mixed>>, query_time: string}
     */
    public function searchTrips(
        string|array $from,
        string|array $to,
        string|array|null $via = null,
        int $numTrips = 3,
        ?DateTimeImmutable $when = null
    ): array {
        $now = $when ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        $dateStr = $now->format('Ymd');
        $timeStr = $now->format('His');

        $depLoc = $this->buildLocDescriptor($from);
        $arrLoc = $this->buildLocDescriptor($to);

        $req = [
            'depLocL' => [$depLoc],
            'arrLocL' => [$arrLoc],
            'outDate' => $dateStr,
            'outTime' => $timeStr,
            'numF' => max(1, min($numTrips, 5)),
            'minChgTime' => 2,
        ];

        if ($via !== null && $via !== '' && $via !== []) {
            $viaLoc = $this->buildLocDescriptor($via);
            $req['viaLocL'] = [['loc' => $viaLoc]];
        }

        $payload = [
            'auth' => ['type' => 'AID', 'aid' => self::AUTH_AID],
            'client' => [
                'type' => self::CLIENT_TYPE,
                'id' => self::CLIENT_ID,
                'v' => self::CLIENT_VERSION,
                'name' => self::CLIENT_NAME,
            ],
            'ver' => self::PROTOCOL_VERSION,
            'lang' => 'deu',
            'svcReqL' => [
                [
                    'meth' => 'TripSearch',
                    'req' => $req,
                ],
            ],
        ];

        $response = $this->sendRequest($payload);
        if ($response === null) {
            return ['connections' => [], 'query_time' => $now->format('c')];
        }

        $svcResItem = $response['svcResL'][0] ?? null;
        if (!is_array($svcResItem) || (($svcResItem['err'] ?? 'OK') !== 'OK')) {
            return ['connections' => [], 'query_time' => $now->format('c')];
        }

        $res = $svcResItem['res'] ?? [];
        $locList = $res['common']['locL'] ?? [];
        $prodList = $res['common']['prodL'] ?? [];
        $outConL = $res['outConL'] ?? [];

        $connections = [];
        foreach ($outConL as $con) {
            $conDep = $con['dep'] ?? [];
            $conArr = $con['arr'] ?? [];

            $schedDepTimeRaw = (string)($conDep['dTimeS'] ?? '');
            $realDepTimeRaw = (string)($conDep['dTimeR'] ?? '');
            $schedArrTimeRaw = (string)($conArr['aTimeS'] ?? '');
            $realArrTimeRaw = (string)($conArr['aTimeR'] ?? '');

            $depDate = (string)($con['date'] ?? $dateStr);
            $schedDepDt = $this->parseHafasTime($depDate, $schedDepTimeRaw);
            $realDepDt = ($realDepTimeRaw !== '') ? $this->parseHafasTime($depDate, $realDepTimeRaw) : $schedDepDt;

            $arrDate = (string)($con['date'] ?? $dateStr);
            $schedArrDt = $this->parseHafasTime($arrDate, $schedArrTimeRaw);
            $realArrDt = ($realArrTimeRaw !== '') ? $this->parseHafasTime($arrDate, $realArrTimeRaw) : $schedArrDt;

            // Dauer in Minuten aus "dur" (z. B. "002200" -> 22 Min)
            $durRaw = (string)($con['dur'] ?? '000000');
            $durHours = (int)substr($durRaw, 0, 2);
            $durMinutes = (int)substr($durRaw, 2, 2);
            $totalDurationMinutes = ($durHours * 60) + $durMinutes;

            $depDelay = 0;
            if ($schedDepDt !== null && $realDepDt !== null) {
                $depDelay = (int)round(($realDepDt->getTimestamp() - $schedDepDt->getTimestamp()) / 60);
            }

            // Countdown ab aktuellem Zeitpunkt
            $effectiveDepDt = $realDepDt ?? $schedDepDt;
            $countdownMinutes = null;
            if ($effectiveDepDt !== null) {
                $countdownMinutes = (int)floor(($effectiveDepDt->getTimestamp() - $now->getTimestamp()) / 60);
            }

            $transfers = (int)($con['chg'] ?? 0);
            $isCancelled = !empty($con['isCncl']) || !empty($conDep['dCncl']) || !empty($conArr['aCncl']);

            // Einzelne Abschnitte (Legs) parsen
            $legs = [];
            foreach ($con['secL'] ?? [] as $sec) {
                $secType = $sec['type'] ?? 'JNY';
                $secDep = $sec['dep'] ?? [];
                $secArr = $sec['arr'] ?? [];

                $fromLocName = (string)($locList[$secDep['locX'] ?? -1]['name'] ?? '');
                $toLocName = (string)($locList[$secArr['locX'] ?? -1]['name'] ?? '');

                if ($secType === 'JNY') {
                    $jny = $sec['jny'] ?? [];
                    $prodIdx = $jny['prodX'] ?? null;
                    $prod = ($prodIdx !== null && isset($prodList[$prodIdx])) ? $prodList[$prodIdx] : [];

                    $lineRaw = (string)($prod['name'] ?? '');
                    $lineClean = self::cleanLineName($lineRaw);
                    $mode = self::detectTransportMode($lineRaw, $prod);
                    $badge = self::getLvbBadgeInfo($lineClean, $mode);

                    $secDepSchedRaw = (string)($secDep['dTimeS'] ?? '');
                    $secDepRealRaw = (string)($secDep['dTimeR'] ?? '');
                    $secArrSchedRaw = (string)($secArr['aTimeS'] ?? '');
                    $secArrRealRaw = (string)($secArr['aTimeR'] ?? '');

                    $secDepDt = $this->parseHafasTime($depDate, $secDepRealRaw !== '' ? $secDepRealRaw : $secDepSchedRaw);
                    $secArrDt = $this->parseHafasTime($arrDate, $secArrRealRaw !== '' ? $secArrRealRaw : $secArrSchedRaw);

                    $secDelay = 0;
                    if ($secDepSchedRaw !== '' && $secDepRealRaw !== '') {
                        $sDt = $this->parseHafasTime($depDate, $secDepSchedRaw);
                        $rDt = $this->parseHafasTime($depDate, $secDepRealRaw);
                        if ($sDt && $rDt) {
                            $secDelay = (int)round(($rDt->getTimestamp() - $sDt->getTimestamp()) / 60);
                        }
                    }

                    $legs[] = [
                        'type' => 'transport',
                        'line' => $lineClean,
                        'line_raw' => $lineRaw,
                        'mode' => $mode,
                        'badge' => $badge,
                        'direction' => (string)($jny['dirTxt'] ?? ''),
                        'from_name' => $fromLocName,
                        'to_name' => $toLocName,
                        'departure_time' => $secDepDt?->format('H:i') ?? substr($secDepSchedRaw, 0, 4),
                        'arrival_time' => $secArrDt?->format('H:i') ?? substr($secArrSchedRaw, 0, 4),
                        'delay_minutes' => $secDelay,
                    ];
                } elseif ($secType === 'WALK' || $secType === 'GIS') {
                    $gis = $sec['gis'] ?? [];
                    $walkDurRaw = (string)($gis['durS'] ?? '000000');
                    $walkMinutes = ((int)substr($walkDurRaw, 0, 2) * 60) + (int)substr($walkDurRaw, 2, 2);

                    if ($walkMinutes >= 1 || ($fromLocName !== '' && $toLocName !== '' && $fromLocName !== $toLocName)) {
                        $legs[] = [
                            'type' => 'walk',
                            'duration_minutes' => max(1, $walkMinutes),
                            'from_name' => $fromLocName,
                            'to_name' => $toLocName,
                        ];
                    }
                }
            }

            $connections[] = [
                'departure_time' => $realDepDt?->format('H:i') ?? ($schedDepDt?->format('H:i') ?? substr($schedDepTimeRaw, 0, 4)),
                'arrival_time' => $realArrDt?->format('H:i') ?? ($schedArrDt?->format('H:i') ?? substr($schedArrTimeRaw, 0, 4)),
                'scheduled_departure' => $schedDepDt?->format('H:i') ?? substr($schedDepTimeRaw, 0, 4),
                'scheduled_arrival' => $schedArrDt?->format('H:i') ?? substr($schedArrTimeRaw, 0, 4),
                'duration_minutes' => $totalDurationMinutes,
                'transfers' => $transfers,
                'delay_minutes' => $depDelay,
                'countdown_minutes' => $countdownMinutes,
                'is_cancelled' => $isCancelled,
                'legs' => $legs,
            ];
        }

        return [
            'connections' => $connections,
            'query_time' => $now->format('c'),
        ];
    }

    /**
     * Erstellt den HAFAS-Stations-Deskriptor für Anfragen.
     *
     * @param string|array{name?: string, id?: string} $loc
     * @return array<string, string>
     */
    private function buildLocDescriptor(string|array $loc): array
    {
        if (is_array($loc)) {
            $desc = ['type' => 'S'];
            if (!empty($loc['id'])) {
                $desc['extId'] = (string)$loc['id'];
            }
            if (!empty($loc['name'])) {
                $desc['name'] = (string)$loc['name'];
            }
            return $desc;
        }

        $loc = trim($loc);
        if (ctype_digit($loc) || (strlen($loc) >= 5 && is_numeric($loc))) {
            return ['type' => 'S', 'extId' => $loc];
        }

        return ['type' => 'S', 'name' => $loc];
    }

    /**
     * Sendet den HTTPS-POST an das INSA-Gateway.
     */
    private function sendRequest(array $payload): ?array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->logger->error('InsaClient: JSON-Encoding fehlgeschlagen.');
            return null;
        }

        $ch = curl_init(self::ENDPOINT);
        if ($ch === false) {
            $this->logger->error('InsaClient: curl_init fehlgeschlagen.');
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'User-Agent: Kai-Toolset/1.19 (Transit-Module)',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false || $httpCode !== 200) {
            $this->logger->error('InsaClient: HTTP-Fehler bei Anfrage.', [
                'http_code' => $httpCode,
                'curl_error' => $curlError,
            ]);
            return null;
        }

        try {
            $data = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || (isset($data['err']) && $data['err'] !== 'OK')) {
                $this->logger->warn('InsaClient: Fehlermeldung im HAFAS-Response.', [
                    'err' => $data['err'] ?? 'unbekannt',
                    'errTxt' => $data['errTxt'] ?? '',
                ]);
                return null;
            }
            return $data;
        } catch (Throwable $e) {
            $this->logger->error('InsaClient: Antwort konnte nicht dekodiert werden.', [
                'error' => $e->getMessage(),
                'sample' => substr($rawResponse, 0, 300),
            ]);
            return null;
        }
    }

    /**
     * Bereinigt Liniennamen für die kompakte Anzeige (z. B. "Str 15" -> "15", "Str N10" -> "N10", "Bus 75" -> "75").
     */
    public static function cleanLineName(string $raw): string
    {
        $clean = trim($raw);
        // "Str 15" -> "15", "Tram 15" -> "15", "Str N10" -> "N10"
        if (preg_match('/^(?:Str|Tram)\s*([a-zA-Z0-9]+)$/i', $clean, $m)) {
            return $m[1];
        }
        // "Bus 75" -> "75", "Bus N1" -> "N1", "Bus 690" -> "690"
        if (preg_match('/^Bus\s*([a-zA-Z0-9]+)$/i', $clean, $m)) {
            return $m[1];
        }
        return $clean;
    }

    /**
     * Erkennt die Verkehrsart (tram, bus, train, suburban).
     */
    public static function detectTransportMode(string $lineRaw, array $prod = []): string
    {
        $lineUpper = strtoupper(trim($lineRaw));
        $cls = (int)($prod['cls'] ?? 0);
        $clean = strtoupper(self::cleanLineName($lineRaw));

        // Nachtstraßenbahnen N10 und N17 sind Straßenbahnen!
        if (in_array($clean, ['N10', 'N17'], true)) {
            return 'tram';
        }

        if (str_starts_with($lineUpper, 'STR') || str_starts_with($lineUpper, 'TRAM') || ($cls & 16)) {
            return 'tram';
        }
        if (str_starts_with($lineUpper, 'BUS') || ($cls & 32) || ($cls & 64) || preg_match('/^N[1-9]$/i', $clean)) {
            return 'bus';
        }
        if (str_starts_with($lineUpper, 'S ') || str_starts_with($lineUpper, 'S-BAHN') || ($cls & 8) || preg_match('/^S\d/i', $clean)) {
            return 'suburban';
        }
        if (str_starts_with($lineUpper, 'RE') || str_starts_with($lineUpper, 'RB') || ($cls & 4)) {
            return 'train';
        }

        // Fallback anhand Liniennummer: 1 bis 49 sind in Leipzig Straßenbahnen
        $num = (int)preg_replace('/\D/', '', $clean);
        if ($num >= 1 && $num <= 49 && !str_starts_with($clean, 'N')) {
            return 'tram';
        }
        if ($num >= 60 && $num <= 999) {
            return 'bus';
        }

        return 'other';
    }

    /**
     * Ermittelt die exakten Badge-Eigenschaften nach LVB-Corporate-Design.
     *
     * - Straßenbahnen: quadratisch
     *   - Linie 2, 8: Gelb (#ffd200), schwarzer Text
     *   - Linie 1, 3, N17: Grün (#50af28), weißer Text
     *   - Linie 4, 7, 12, 15: Dunkelblau (#003882), weißer Text
     *   - Linie 10, 11, 14, 16, N10: Rot (#e2001a), weißer Text
     *   - Linie 30 bis 49 (Baustellenlinien, z.B. 38): Orange (#f37021), weißer Text
     *   - Sonstige Straßenbahnen: Dunkelblau (#003882)
     * - Busse: rund
     *   - Nightliner N1 bis N9: Dunkelblau (#003882), weißer Text
     *   - Reguläre Busse: Lila (#8e126e), weißer Text
     * - S-Bahn: S-Bahn Grün (#008638), rund
     * - Regionalzug: DB Rot (#ba0c2f), quadratisch
     *
     * @return array{shape: 'square'|'circle', color_name: string, bg: string, text_color: string, css_class: string}
     */
    public static function getLvbBadgeInfo(string $line, string $mode): array
    {
        $lineClean = strtoupper(trim(self::cleanLineName($line)));
        $lineNum = (int)preg_replace('/\D/', '', $lineClean);

        // 1. S-Bahn & Regionalzug
        if ($mode === 'suburban' || str_starts_with($lineClean, 'S')) {
            return [
                'shape' => 'circle',
                'color_name' => 'sbahn',
                'bg' => '#008638',
                'text_color' => '#ffffff',
                'css_class' => 'transit-shape-circle transit-lvb-sbahn',
            ];
        }
        if ($mode === 'train' || str_starts_with($lineClean, 'RE') || str_starts_with($lineClean, 'RB')) {
            return [
                'shape' => 'square',
                'color_name' => 'train',
                'bg' => '#ba0c2f',
                'text_color' => '#ffffff',
                'css_class' => 'transit-shape-square transit-lvb-train',
            ];
        }

        // 2. Straßenbahn-Prüfung
        $isTram = ($mode === 'tram') || in_array($lineClean, ['N10', 'N17'], true) || ($lineNum >= 1 && $lineNum <= 49 && !str_starts_with($lineClean, 'N'));

        if ($isTram) {
            // Gelb: 2, 8
            if (in_array($lineClean, ['2', '8'], true)) {
                return [
                    'shape' => 'square',
                    'color_name' => 'yellow',
                    'bg' => '#ffd200',
                    'text_color' => '#000000',
                    'css_class' => 'transit-shape-square transit-lvb-yellow',
                ];
            }

            // Grün: 1, 3, N17
            if (in_array($lineClean, ['1', '3', 'N17'], true)) {
                return [
                    'shape' => 'square',
                    'color_name' => 'green',
                    'bg' => '#50af28',
                    'text_color' => '#ffffff',
                    'css_class' => 'transit-shape-square transit-lvb-green',
                ];
            }

            // Dunkelblau: 4, 7, 12, 15
            if (in_array($lineClean, ['4', '7', '12', '15'], true)) {
                return [
                    'shape' => 'square',
                    'color_name' => 'blue',
                    'bg' => '#003882',
                    'text_color' => '#ffffff',
                    'css_class' => 'transit-shape-square transit-lvb-blue',
                ];
            }

            // Rot: 10, 11, 14, 16, N10
            if (in_array($lineClean, ['10', '11', '14', '16', 'N10'], true)) {
                return [
                    'shape' => 'square',
                    'color_name' => 'red',
                    'bg' => '#e2001a',
                    'text_color' => '#ffffff',
                    'css_class' => 'transit-shape-square transit-lvb-red',
                ];
            }

            // Baustellenlinien: 30 bis 49 (z. B. 38)
            if ($lineNum >= 30 && $lineNum <= 49) {
                return [
                    'shape' => 'square',
                    'color_name' => 'orange',
                    'bg' => '#f37021',
                    'text_color' => '#ffffff',
                    'css_class' => 'transit-shape-square transit-lvb-orange',
                ];
            }

            // Fallback Tram (z.B. Linie 9)
            return [
                'shape' => 'square',
                'color_name' => 'blue',
                'bg' => '#003882',
                'text_color' => '#ffffff',
                'css_class' => 'transit-shape-square transit-lvb-blue',
            ];
        }

        // 3. Busse (rund)
        // Nightliner Busse: N1 bis N9
        if (preg_match('/^N[1-9]$/i', $lineClean)) {
            return [
                'shape' => 'circle',
                'color_name' => 'nightblue',
                'bg' => '#003882',
                'text_color' => '#ffffff',
                'css_class' => 'transit-shape-circle transit-lvb-nightblue',
            ];
        }

        // Reguläre Busse (60, 62, 65, 70, 72, 73, 74, 75, 79, 87, 88, 89, 131, 173, 175 etc.): Lila
        return [
            'shape' => 'circle',
            'color_name' => 'purple',
            'bg' => '#8e126e',
            'text_color' => '#ffffff',
            'css_class' => 'transit-shape-circle transit-lvb-purple',
        ];
    }

    /**
     * Parst HAFAS-Datum (YYYYMMDD) und Zeit (HHMMSS oder HHMM) in ein DateTimeImmutable.
     */
    private function parseHafasTime(string $datePart, string $timePart): ?DateTimeImmutable
    {
        if (strlen($datePart) !== 8 || strlen($timePart) < 4) {
            return null;
        }

        $timePadded = str_pad($timePart, 6, '0', STR_PAD_RIGHT);
        $full = $datePart . $timePadded;

        $dt = DateTimeImmutable::createFromFormat('YmdHis', $full, new DateTimeZone('Europe/Berlin'));
        return ($dt !== false) ? $dt : null;
    }
}
