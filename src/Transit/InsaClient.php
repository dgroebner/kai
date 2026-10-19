<?php

namespace Kai\Tools\Transit;

use DateTimeImmutable;
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

        $matchList = $response['svcResL'][0]['res']['match']['locL'] ?? [];
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
        int $maxJourneys = 15,
        ?DateTimeImmutable $when = null
    ): array {
        $now = $when ?? new DateTimeImmutable();
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

        $svcRes = $response['svcResL'][0]['res'] ?? [];
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

            $departures[] = [
                'journey_id' => (string)($jny['jid'] ?? ''),
                'line' => $lineClean,
                'line_raw' => $lineRaw,
                'mode' => $transportMode,
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
            if (!is_array($data) || isset($data['err'])) {
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
     * Bereinigt Liniennamen für die kompakte Anzeige (z. B. "Str 15" -> "15", "Bus 75" -> "75").
     */
    private function cleanLineName(string $raw): string
    {
        $clean = trim($raw);
        // "Str 15" -> "15", "Tram 15" -> "15"
        if (preg_match('/^(?:Str|Tram)\s*(\d+[a-zA-Z]?)$/i', $clean, $m)) {
            return $m[1];
        }
        // "Bus 75" -> "75", "Bus 690" -> "690"
        if (preg_match('/^Bus\s*(\d+[a-zA-Z]?)$/i', $clean, $m)) {
            return $m[1];
        }
        return $clean;
    }

    /**
     * Erkennt die Verkehrsart (tram, bus, train, suburban).
     */
    private function detectTransportMode(string $lineRaw, array $prod): string
    {
        $lineUpper = strtoupper(trim($lineRaw));
        $cls = (int)($prod['cls'] ?? 0);

        if (str_starts_with($lineUpper, 'STR') || str_starts_with($lineUpper, 'TRAM') || ($cls & 16)) {
            return 'tram';
        }
        if (str_starts_with($lineUpper, 'BUS') || ($cls & 32) || ($cls & 64)) {
            return 'bus';
        }
        if (str_starts_with($lineUpper, 'S ') || str_starts_with($lineUpper, 'S-BAHN') || ($cls & 8)) {
            return 'suburban';
        }
        if (str_starts_with($lineUpper, 'RE') || str_starts_with($lineUpper, 'RB') || ($cls & 4)) {
            return 'train';
        }
        return 'other';
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

        $dt = DateTimeImmutable::createFromFormat('YmdHis', $full);
        return ($dt !== false) ? $dt : null;
    }
}
