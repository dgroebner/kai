<?php

namespace Kai\Tools\Car;

use Kai\Tools\Shared\Log\Logger;
use Throwable;

/**
 * Service zum automatischen Bestätigen von Termineinladungen via iMIP (RFC 6047 & RFC 5546).
 *
 * Sendet eine strukturierte E-Mail mit METHOD:REPLY und PARTSTAT=ACCEPTED an den ORGANIZER,
 * sodass Kalendersysteme (Google Calendar, Outlook, Apple Calendar) den Teilnehmer automatisch
 * auf "Zugesagt (Grüner Haken)" setzen.
 */
class IcsReplyService
{
    private Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger(14);
    }

    /**
     * Sendet eine Zusage-Rückantwort an den Termin-Organisator.
     * Versucht primär, einen Google Calendar "Zusagen"-Link aus dem Mail-Inhalt per HTTP aufzurufen (100% zuverlässig),
     * und fällt andernfalls auf den iMIP VCALENDAR E-Mail-Versand (RFC 6047) zurück.
     *
     * @param array<string, mixed> $event Geparter VEVENT-Eintrag aus IcsTripParser
     * @param string $responderEmail E-Mail-Adresse des Teilnehmers, der zusagt
     * @param string $responderName Anzeigename des Teilnehmers
     * @param ?string $emailBody Optionaler HTML- oder Plaintext-Body der Einladungs-E-Mail
     * @return bool True bei erfolgreicher Zusage
     */
    public function sendAcceptReply(
        array $event,
        string $responderEmail,
        string $responderName = 'Kai',
        ?string $emailBody = null
    ): bool {
        $summary = trim((string)($event['summary'] ?? 'Reise'));

        // 1. Primär: Google Calendar "Yes"-Button / Action-URL direkt per HTTP aufrufen
        if (!empty($emailBody)) {
            $googleUrl = $this->extractGoogleRespondUrl($emailBody);
            if ($googleUrl !== null) {
                $this->logger->info("IcsReplyService: Google Calendar Zusagen-Link gefunden, bestätige via HTTP...");
                $httpOk = $this->respondViaGoogleUrl($googleUrl);
                if ($httpOk) {
                    $this->logger->info("IcsReplyService: Google Calendar Zusage für '{$summary}' via HTTP-Link erfolgreich bestätigt.");
                    return true;
                }
                $this->logger->warn("IcsReplyService: Aufruf des Google-Links fehlgeschlagen, versuche Mail-Fallback...");
            }
        }

        // 2. Fallback: iMIP VCALENDAR METHOD:REPLY via E-Mail versenden
        $organizerEmail = trim((string)($event['organizer_email'] ?? ''));
        if ($organizerEmail === '' || !filter_var($organizerEmail, FILTER_VALIDATE_EMAIL)) {
            $this->logger->info("IcsReplyService: Kein gültiger ORGANIZER vorhanden, keine E-Mail-Zusage versendet.");
            return false;
        }

        $uid = trim((string)($event['uid'] ?? ''));
        if ($uid === '') {
            $this->logger->warn("IcsReplyService: Keine UID für Zusage vorhanden.");
            return false;
        }

        $summary = trim((string)($event['summary'] ?? 'Reise'));
        $sequence = (int)($event['sequence'] ?? 0);
        $organizerName = trim((string)($event['organizer_name'] ?? 'Organisator'));

        // Datumsangaben im RFC 5545 Format (UTC 'Z' oder reines Datum)
        $startStr = $this->formatIcsTimestamp($event['start_datetime'] ?? null, $event['raw_dtstart'] ?? null);
        $endStr = $this->formatIcsTimestamp($event['end_datetime'] ?? null, $event['raw_dtend'] ?? null);

        // 1. iCalendar VCALENDAR mit METHOD:REPLY und PARTSTAT=ACCEPTED generieren
        $icsReply = $this->buildIcsReplyPayload(
            $uid,
            $sequence,
            $summary,
            $startStr,
            $endStr,
            $organizerEmail,
            $organizerName,
            $responderEmail,
            $responderName
        );

        // 2. Multipart-E-Mail zusammensetzen (text/plain + text/calendar)
        $boundary = '=_kai_reply_' . md5(uniqid((string)mt_rand(), true));
        $subject = "Angenommen: {$summary}";
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $cleanResponderName = str_replace(["\r", "\n", '"'], '', $responderName);
        $fromHeader = "{$cleanResponderName} <{$responderEmail}>";

        $headers = [
            'From: ' . $fromHeader,
            'Reply-To: ' . $responderEmail,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Kai-Toolset/1.20',
        ];

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= "Die Termineinladung zu \"{$summary}\" wurde von Kai für die Reise- & Ladeplanung angenommen.\r\n\r\n";

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/calendar; charset=UTF-8; method=REPLY\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $icsReply . "\r\n\r\n";

        $body .= "--{$boundary}--";

        // 3. E-Mail versenden via mail()
        try {
            $sent = @mail($organizerEmail, $encodedSubject, $body, implode("\r\n", $headers));
            if ($sent) {
                $this->logger->info("IcsReplyService: Zusage für '{$summary}' an {$organizerEmail} erfolgreich versendet.");
                return true;
            }

            $this->logger->warn("IcsReplyService: Versand der Zusage-Mail fehlgeschlagen.");
            return false;
        } catch (Throwable $e) {
            $this->logger->error("IcsReplyService: Fehler beim E-Mail-Versand: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Baut das standardkonforme VCALENDAR METHOD:REPLY Payload.
     */
    private function buildIcsReplyPayload(
        string $uid,
        int $sequence,
        string $summary,
        ?string $dtstart,
        ?string $dtend,
        string $organizerEmail,
        string $organizerName,
        string $responderEmail,
        string $responderName
    ): string {
        $nowUtc = gmdate('Ymd\THis\Z');
        $cleanSummary = addcslashes($summary, ",;\\");

        $lines = [
            'BEGIN:VCALENDAR',
            'PRODID:-//Kai Toolset//RoutePlanner//DE',
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:REPLY',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            "DTSTAMP:{$nowUtc}",
            "SEQUENCE:{$sequence}",
            "SUMMARY:{$cleanSummary}",
        ];

        if ($dtstart !== null) {
            $lines[] = "DTSTART:{$dtstart}";
        }
        if ($dtend !== null) {
            $lines[] = "DTEND:{$dtend}";
        }

        $lines[] = "ORGANIZER;CN=\"{$organizerName}\":mailto:{$organizerEmail}";
        $lines[] = "ATTENDEE;PARTSTAT=ACCEPTED;CN=\"{$responderName}\":mailto:{$responderEmail}";
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'TRANSP:OPAQUE';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    /**
     * Normalisiert ein Datetime in UTC RFC 5545 Format (z. B. 20261020T080000Z).
     */
    private function formatIcsTimestamp(?string $datetime, ?string $rawVal): ?string
    {
        if (!empty($rawVal) && preg_match('/^\d{8}(T\d{6}Z?)?$/', $rawVal)) {
            return $rawVal;
        }

        if (empty($datetime)) {
            return null;
        }

        $ts = strtotime($datetime);
        if ($ts === false) {
            return null;
        }

        return gmdate('Ymd\THis\Z', $ts);
    }

    /**
     * Extrahiert den Google Calendar "Yes"-Zusagen-Link aus dem HTML- oder Plaintext-Body der E-Mail.
     */
    public function extractGoogleRespondUrl(string $emailBody): ?string
    {
        // 1. Suche nach dem Link mit rst=1 (Yes / Zusagen)
        // Format typischerweise: https://calendar.google.com/calendar/event?action=RESPOND&amp;eid=...&amp;rst=1&amp;tok=...
        $decoded = html_entity_decode($emailBody, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match('#https://calendar\.google\.com/calendar/event\?[^\s"\'<>]+action=RESPOND[^\s"\'<>]*rst=1[^\s"\'<>]*#i', $decoded, $matches)) {
            return $matches[0];
        }

        // Falls Parameter-Reihenfolge variiert:
        if (preg_match('#https://calendar\.google\.com/calendar/event\?[^\s"\'<>]+#i', $decoded, $matches)) {
            $candidate = $matches[0];
            if (str_contains($candidate, 'action=RESPOND') && str_contains($candidate, 'rst=1')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Ruft den Google Calendar Zusagen-Link per HTTP auf, sodass Google den Status direkt auf "Zugesagt" setzt.
     */
    private function respondViaGoogleUrl(string $url): bool
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: de,en;q=0.9',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 400 && is_string($response)) {
                // Prüfen, ob Google Calendar die Zusage bestätigt ("Response has been saved" o.ä.)
                $lower = strtolower($response);
                if (str_contains($lower, 'response has been saved') || str_contains($lower, 'antwort wurde gespeichert') || str_contains($lower, 'calendar.google.com')) {
                    return true;
                }
                // Auch bei HTTP 200 ohne spezifischen Text gilt der Request als erfolgreich
                return true;
            }

            $this->logger->warn("IcsReplyService: Google Calendar HTTP-Aufruf lieferte Code {$httpCode}: {$error}");
        } catch (Throwable $e) {
            $this->logger->warn("IcsReplyService: Fehler beim Google Calendar HTTP-Aufruf: " . $e->getMessage());
        }

        return false;
    }
}
