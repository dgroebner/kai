<?php

namespace Kai\Tools\Car;

use DateTime;
use DateTimeZone;
use Throwable;

/**
 * Robuster Parser für iCalendar (.ics / RFC 5545) Termineinladungen.
 * Extrahiert UID, SUMMARY, LOCATION, DTSTART, DTEND und STATUS.
 */
class IcsTripParser
{
    /**
     * Parst einen .ics-Textinhalt und liefert alle enthaltenen Termine.
     *
     * @param string $icsContent Roher Dateiinhalt des iCalendar-Formats
     * @return array<int, array{
     *     uid: string,
     *     summary: string,
     *     location: string,
     *     start_datetime: string,
     *     end_datetime: ?string,
     *     status: string,
     *     is_all_day: bool
     * }>
     */
    public function parse(string $icsContent): array
    {
        // Zeilenumbrüche normalisieren und Zeilenfaltungen (RFC 5545 Line Unfolding) auflösen
        $normalized = str_replace(["\r\n", "\r"], "\n", $icsContent);
        $unfolded = preg_replace("/\n[ \t]/", '', $normalized);

        $lines = explode("\n", $unfolded);
        $events = [];
        $currentEvent = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($line === 'BEGIN:VEVENT') {
                $currentEvent = [
                    'uid' => '',
                    'summary' => '',
                    'location' => '',
                    'start_datetime' => '',
                    'end_datetime' => null,
                    'raw_dtstart' => '',
                    'raw_dtend' => null,
                    'status' => 'CONFIRMED',
                    'sequence' => 0,
                    'organizer_email' => null,
                    'organizer_name' => null,
                    'is_all_day' => false,
                ];
                continue;
            }

            if ($line === 'END:VEVENT' && $currentEvent !== null) {
                if (!empty($currentEvent['uid']) || !empty($currentEvent['summary'])) {
                    $events[] = $currentEvent;
                }
                $currentEvent = null;
                continue;
            }

            if ($currentEvent === null) {
                continue;
            }

            // Key-Value Trennung am ersten Doppelpunkt
            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }

            $rawKey = substr($line, 0, $colonPos);
            $val = substr($line, $colonPos + 1);

            // Escapte Zeichen in ICS demaskieren
            $val = str_replace(['\,', '\;', '\n', '\N', '\\\\'], [',', ';', "\n", "\n", '\\'], $val);

            $keyParts = explode(';', $rawKey);
            $keyName = strtoupper(trim($keyParts[0]));
            $keyParams = array_slice($keyParts, 1);

            switch ($keyName) {
                case 'UID':
                    $currentEvent['uid'] = trim($val);
                    break;

                case 'SUMMARY':
                    $currentEvent['summary'] = trim($val);
                    break;

                case 'LOCATION':
                    $currentEvent['location'] = trim($val);
                    break;

                case 'STATUS':
                    $currentEvent['status'] = strtoupper(trim($val));
                    break;

                case 'SEQUENCE':
                    $currentEvent['sequence'] = (int)trim($val);
                    break;

                case 'ORGANIZER':
                    $cleanEmail = preg_replace('/^mailto:/i', '', trim($val));
                    $currentEvent['organizer_email'] = $cleanEmail;
                    foreach ($keyParams as $param) {
                        if (str_starts_with(strtoupper($param), 'CN=')) {
                            $currentEvent['organizer_name'] = trim(substr($param, 3), ' "\'');
                        }
                    }
                    break;

                case 'DTSTART':
                    $currentEvent['raw_dtstart'] = trim($val);
                    $dtInfo = $this->parseIcsDate($val, $keyParams);
                    if ($dtInfo !== null) {
                        $currentEvent['start_datetime'] = $dtInfo['datetime'];
                        $currentEvent['is_all_day'] = $dtInfo['is_all_day'];
                    }
                    break;

                case 'DTEND':
                    $currentEvent['raw_dtend'] = trim($val);
                    $dtInfo = $this->parseIcsDate($val, $keyParams);
                    if ($dtInfo !== null) {
                        $currentEvent['end_datetime'] = $dtInfo['datetime'];
                    }
                    break;
            }

        }

        return $events;
    }

    /**
     * Konvertiert ein ICS-Datums-/Zeitformat in ein MySQL-kompatibles DATETIME (Europe/Berlin Ortszeit).
     *
     * @return array{datetime: string, is_all_day: bool}|null
     */
    private function parseIcsDate(string $val, array $params): ?array
    {
        $val = trim($val);
        if ($val === '') {
            return null;
        }

        $tzid = null;
        $isDateOnly = false;

        foreach ($params as $param) {
            $param = strtoupper(trim($param));
            if (str_starts_with($param, 'TZID=')) {
                $tzid = substr($param, 5);
            } elseif ($param === 'VALUE=DATE') {
                $isDateOnly = true;
            }
        }

        try {
            $berlinTz = new DateTimeZone('Europe/Berlin');

            // 1. Reines Tagesdatum (z.B. 20261015)
            if ($isDateOnly || preg_match('/^\d{8}$/', $val)) {
                $dt = DateTime::createFromFormat('Ymd', substr($val, 0, 8), $berlinTz);
                if ($dt) {
                    $dt->setTime(9, 0, 0); // All-day Event startet standardmäßig um 09:00 Uhr
                    return [
                        'datetime' => $dt->format('Y-m-d H:i:s'),
                        'is_all_day' => true,
                    ];
                }
            }

            // 2. UTC-Zeitstempel mit 'Z' am Ende (z.B. 20261015T083000Z)
            if (str_ends_with($val, 'Z')) {
                $cleanVal = rtrim($val, 'Z');
                $dt = DateTime::createFromFormat('Ymd\THis', $cleanVal, new DateTimeZone('UTC'));
                if ($dt) {
                    $dt->setTimezone($berlinTz);
                    return [
                        'datetime' => $dt->format('Y-m-d H:i:s'),
                        'is_all_day' => false,
                    ];
                }
            }

            // 3. Mit Zeitzone oder lokale Zeit (z.B. 20261015T103000)
            $sourceTz = $berlinTz;
            if ($tzid) {
                try {
                    $sourceTz = new DateTimeZone($tzid);
                } catch (Throwable) {
                    $sourceTz = $berlinTz;
                }
            }

            $dt = DateTime::createFromFormat('Ymd\THis', $val, $sourceTz);
            if ($dt) {
                $dt->setTimezone($berlinTz);
                return [
                    'datetime' => $dt->format('Y-m-d H:i:s'),
                    'is_all_day' => false,
                ];
            }
        } catch (Throwable) {
            // Konvertierung fehlgeschlagen
        }

        return null;
    }
}
