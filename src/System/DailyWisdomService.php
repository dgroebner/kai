<?php

namespace Kai\Tools\System;

use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Log\Logger;
use Throwable;

class DailyWisdomService
{
    /**
     * Statischer Notfall-Puffer hochkarätiger Weisheiten, falls KI oder DB nicht erreichbar sind.
     */
    public const FALLBACK_WISDOMS = [
        'Konfuzius sagt: Wer morgens zerknittert aufsteht, hat tagsüber die besten Entfaltungsmöglichkeiten.',
        'Konfuzius sagt: Hausaufgaben aufzuschieben löst zwar keine Probleme, schafft aber erst einmal herrlich viel Freizeit.',
        'Konfuzius sagt: Schlaf wird völlig überbewertet – bis der Wecker um 6:30 Uhr klingelt.',
        'Konfuzius sagt: Wahre Freunde teilen alles – außer den letzten Keks und das 2-Meter-Ladekabel.',
        'Konfuzius sagt: Ein voller Kühlschrank ist gut, aber noch besser ist, wenn man nach 30 Sekunden Starren plötzlich Pizza findet.',
        'Konfuzius sagt: Wenn Plan A nicht klappt, keine Panik: Das Alphabet hat noch 25 andere Buchstaben.',
        'Konfuzius sagt: Wer in Schule oder Arbeit fünf Minuten unauffällig aus dem Fenster starrt, rettet oft den ganzen Tag.',
        'Konfuzius sagt: Manchmal muss man Dinge einfach tun, nur um herauszufinden, warum man es besser hätte bleiben lassen.',
        'Konfuzius sagt: Ein aufgeräumtes Zimmer ist schön, aber ein unaufgeräumtes Zimmer hat Charakter und Überraschungen.',
        'Konfuzius sagt: Die drei schönsten Worte der deutschen Sprache lauten nicht „Ich liebe dich“, sondern „Entfällt die Stunde?“.',
        'Konfuzius sagt: Wer den Tag mit einem Lächeln beginnt, hat den Ernst der ersten Stunde noch nicht begriffen.',
        'Konfuzius sagt: Wenn du denkst, es geht nicht mehr, iss erst mal einen Snack und leg dich wieder hin.',
    ];

    private DailyWisdomRepository $repository;
    private GeminiClient $geminiClient;
    private Logger $logger;

    public function __construct(
        ?DailyWisdomRepository $repository = null,
        ?GeminiClient $geminiClient = null,
        ?Logger $logger = null
    ) {
        $this->repository = $repository ?? new DailyWisdomRepository();
        $this->geminiClient = $geminiClient ?? new GeminiClient();
        $this->logger = $logger ?? new Logger(14);
    }

    /**
     * Liefert die Weisheit für heute:
     * 1. Liegt bereits in der DB vor -> direkt zurückgeben.
     * 2. Fehlt noch -> On-the-Fly per KI generieren & persistieren (Lazy Evaluation).
     * 3. Schlägt die Generierung fehl -> Statischer Fallback ohne Blockieren der UI.
     */
    public function getWisdomForToday(bool $allowGenerateOnDemand = true): string
    {
        $today = date('Y-m-d');

        try {
            $existing = $this->repository->getByDate($today);
            if ($existing !== null && !empty($existing['content'])) {
                return (string)$existing['content'];
            }

            if ($allowGenerateOnDemand) {
                // Kurzer Timeout (8s) für synchrones Rendering im Browser
                $generated = $this->generateAndSaveWisdom($today, false, 8);
                if ($generated !== null && $generated !== '') {
                    return $generated;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warn('DailyWisdomService: Fehler bei getWisdomForToday, nutze Notfall-Puffer.', [
                'error' => $e->getMessage()
            ]);
        }

        return $this->getFallbackWisdom($today);
    }

    /**
     * Prüft, ob für heute bereits eine Weisheit existiert, und generiert sie bei Bedarf (für Cronjobs).
     */
    public function ensureTodayWisdom(): ?string
    {
        $today = date('Y-m-d');
        $existing = $this->repository->getByDate($today);
        if ($existing !== null && !empty($existing['content'])) {
            return null;
        }

        return $this->generateAndSaveWisdom($today, false, 30);
    }

    /**
     * Erzeugt eine neue Weisheit via Gemini API unter Berücksichtigung der letzten N Tage
     * und persistiert sie in der Datenbank.
     */
    public function generateAndSaveWisdom(?string $date = null, bool $force = false, int $timeout = 30): ?string
    {
        $targetDate = $date ?? date('Y-m-d');

        if (!$force) {
            $existing = $this->repository->getByDate($targetDate);
            if ($existing !== null && !empty($existing['content'])) {
                return (string)$existing['content'];
            }
        }

        try {
            // Historie der letzten 30-60 Sprüche auslesen, um Redundanzen zu unterbinden
            $recentWisdoms = $this->repository->getRecentWisdomTexts(60);

            $systemInstruction = <<<SYS
Du bist ein gewitzter, herrlich humorvoller und selbstironischer moderner "Konfuzius".
Deine Aufgabe ist es, für den heutigen Tag einen lustigen, lockeren und unterhaltsamen "Konfuzius sagt"-Spruch zu verfassen.

TONFALL & CHARAKTER:
- Deutlich weniger getragene Philosophie oder ernste Lebensratschläge – stattdessen Witz, Humor, Situationskomik und Selbstironie!
- Gern auch amüsante Sinnlosigkeiten, verquere Logik oder herrlicher Alltagsquatsch ("Konfuzius sagt: Wenn du denkst, es geht nicht mehr, iss erst mal eine Scheibe Käse.").
- Stark an der Lebenswelt von Jugend, Schülern und jungen Menschen orientiert (nicht mehr hauptsächlich getragene Sinnsprüche für Eltern; ein humorvoller Seitenhieb auf Eltern oder Arbeitsalltag ist im Wechsel gern gesehen).
- Niemals belehrend, moralisierend oder kitschig. Kein getragenes Pathos.
- Familiengerecht und pointiert: Lustig, frech und treffend, aber ohne Fäkalsprache oder Vulgäres.

THEMEN (abwechslungsreich rotieren):
- Schule & Lernen: Hausaufgaben aufschieben, Lehrersprüche, 1. Stunde Sport, Wecker am Montagmorgen, Noten retten.
- Freunde & Social Life: Beste Freunde, Gruppenchats, Geheimnisse, verlegte Ladekabel, peinliche Momente, Snacks teilen.
- Familie & Zuhause: Kühlschrank-Scannen, Zimmer aufräumen, elterliche Weisheiten („Zieh dir was Warmes an!“), Geschwister-Deals.
- Arbeit & Alltag: Montage, Feierabendsehnsucht, Kollegengespräche, Kaffeekonsum, Meetings.
- Dinge des täglichen Lebens: Ewige Müdigkeit, verschwundene Socken, Bus verpasst, Snackhunger, Akku auf 2%, Prokrastination.

STRIKTE REGELN:
1. Format: Deine Antwort MUSS zwingend und ausnahmslos mit "Konfuzius sagt:" beginnen, gefolgt von der Weisheit (1 bis maximal 2 Sätze).
2. Strikte Vermeidung von Wiederholungen: Du darfst KEINESFALLS Themen, Metaphern, Redewendungen oder Pointen verwenden, die den unten im Prompt aufgeführten vergangenen Sprüchen ähneln. Sei innovativ und erfinde jeden Tag eine frische Perspektive.
3. Ausgabeformat: Gib AUSSCHLIESSLICH den fertigen Text ohne zusätzliche Anführungszeichen, ohne Markdown-Fences, ohne Einleitungen und ohne Erklärungen aus.
SYS;

            $pastListText = '';
            if (!empty($recentWisdoms)) {
                $pastListText = "Bisherige Weisheiten der letzten Wochen (unbedingt thematische Überschneidungen und ähnliche Pointen vermeiden!):\n";
                foreach ($recentWisdoms as $idx => $past) {
                    $cleanPast = trim(str_replace(["\r", "\n"], ' ', $past));
                    $pastListText .= "- " . $cleanPast . "\n";
                }
            } else {
                $pastListText = "Es sind noch keine vergangenen Sprüche im Archiv vorhanden.\n";
            }

            $userPrompt = <<<PROMPT
{$pastListText}
Formuliere nun einen neuen, herrlich witzigen und treffsicheren Spruch für den heutigen Tag ({$targetDate}), der mit Humor, jugendlichem Charme oder amüsanter Sinnlosigkeit zum Lachen oder Schmunzeln bringt.
Denke daran: Die Antwort MUSS mit "Konfuzius sagt:" beginnen.
PROMPT;

            $response = $this->geminiClient->generate(
                prompt: $userPrompt,
                systemInstruction: $systemInstruction,
                temperature: 0.7,
                timeout: $timeout
            );

            $rawText = trim($response['text'] ?? '');
            if ($rawText === '') {
                $this->logger->warn('DailyWisdomService: Gemini lieferte eine leere Antwort.');
                return null;
            }

            $cleanedWisdom = $this->normalizeWisdomText($rawText);
            if ($cleanedWisdom === null) {
                $this->logger->warn('DailyWisdomService: Bereinigter Weisheitstext ungültig.', ['raw' => $rawText]);
                return null;
            }

            $saved = $this->repository->save($targetDate, $cleanedWisdom);
            if (!$saved) {
                $this->logger->error('DailyWisdomService: Konnte Weisheit nicht in DB speichern.', [
                    'date' => $targetDate,
                    'wisdom' => $cleanedWisdom
                ]);
                return null;
            }

            $this->logger->info('DailyWisdomService: Neue Weisheit erfolgreich generiert und gespeichert.', [
                'date' => $targetDate,
                'wisdom' => $cleanedWisdom
            ]);

            return $cleanedWisdom;
        } catch (Throwable $e) {
            $this->logger->error('DailyWisdomService: Fehler bei der KI-Generierung.', [
                'error' => $e->getMessage(),
                'date' => $targetDate
            ]);
            return null;
        }
    }

    /**
     * Normalisiert den Antworttext der KI:
     * - Entfernt umschließende Anführungszeichen und Markdown
     * - Stellt das Pflicht-Präfix "Konfuzius sagt: " sicher
     * - Prüft minimale Plausibilität
     */
    public function normalizeWisdomText(string $raw): ?string
    {
        $text = trim($raw);

        // Markdown-Code-Blöcke oder Sternchen entfernen
        $text = preg_replace('/^```[a-z]*\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = str_replace(['**', '__'], '', $text);

        // Anführungszeichen am Anfang/Ende entfernen
        $text = trim($text, " \t\n\r\0\x0B\"'„“»«");

        // Präfix standardisieren
        if (preg_match('/^konfuzius\s+sagt\s*:?\s*/iu', $text, $matches)) {
            $body = ltrim(substr($text, strlen($matches[0])));
            $body = trim($body, " \t\n\r\0\x0B\"'„“»«");
            $text = 'Konfuzius sagt: ' . $body;
        } else {
            $text = 'Konfuzius sagt: ' . $text;
        }

        // Mehrfache Leerzeichen bereinigen
        $text = preg_replace('/\s+/', ' ', $text);

        if (mb_strlen($text) < 20 || mb_strlen($text) > 500) {
            return null;
        }

        return $text;
    }

    /**
     * Deterministische Auswahl eines Spruchs aus dem Notfall-Puffer basierend auf dem Datum.
     */
    public function getFallbackWisdom(?string $date = null): string
    {
        $targetDate = $date ?? date('Y-m-d');
        $timestamp = strtotime($targetDate);
        if ($timestamp === false) {
            $timestamp = time();
        }

        $dayOfYear = (int)date('z', $timestamp);
        $count = count(self::FALLBACK_WISDOMS);

        return self::FALLBACK_WISDOMS[$dayOfYear % $count];
    }
}
