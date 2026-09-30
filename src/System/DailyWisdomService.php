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
        'Konfuzius sagt: Wer den Tag mit einem Lächeln beginnt, hat der Hektik des Alltags schon den ersten Schritt voraus.',
        'Konfuzius sagt: Auch die längste Reise beginnt mit dem ersten Schritt – und manchmal mit einer frischen Tasse Kaffee.',
        'Konfuzius sagt: Wer die Geduld bewahrt, wenn die Technik streikt, hat die wahre Gelassenheit des digitalen Zeitalters gemeistert.',
        'Konfuzius sagt: Es ist besser, ein kleines Licht anzuzünden, als über die Dunkelheit zu klagen.',
        'Konfuzius sagt: Wer fragt, ist ein Narr für fünf Minuten. Wer nicht fragt, bleibt es ein Leben lang.',
        'Konfuzius sagt: Das Geheimnis des Erfolgs liegt darin, den Anfang zu wagen und dranzubleiben.',
        'Konfuzius sagt: Nicht der Wind bestimmt den Kurs, sondern die Segel, die wir setzen.',
        'Konfuzius sagt: Ein voller Terminkalender ist noch lange kein Beweis für einen erfüllten Tag.',
        'Konfuzius sagt: Wer am Morgen innehält und durchatmet, schenkt sich selbst den Schlüssel zur Gelassenheit.',
        'Konfuzius sagt: Wer Freude sät, erntet Zufriedenheit noch vor dem Abendrot.',
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
Du bist ein geistreicher, philosophischer Denker im Stile von Konfuzius mit feinsinnigem, hintergründigem Humor und Sinn für das moderne Leben, Familie, Alltag, Technologie und Weisheit.
Deine Aufgabe ist es, für den heutigen Tag eine prägnante, originelle Weisheit des Tages zu verfassen.

STRIKTE REGELN:
1. Format: Deine Antwort MUSS zwingend und ausnahmslos mit "Konfuzius sagt:" beginnen, gefolgt von der Weisheit (1 bis maximal 2 Sätze).
2. Tonfall: Geistreich, mit einem leichten Augenzwinkern, lebensklug, motivierend oder zum Schmunzeln anregend – weder kitschig noch belehrend oder abgedroschen.
3. Strikte Vermeidung von Wiederholungen: Du darfst KEINESFALLS Themen, Metaphern, Redewendungen oder Pointen verwenden, die den unten im Prompt aufgeführten vergangenen Sprüchen ähneln. Sei innovativ und erfinde jeden Tag eine frische Perspektive.
4. Ausgabeformat: Gib AUSSCHLIESSLICH den fertigen Text ohne zusätzliche Anführungszeichen, ohne Markdown-Fences, ohne Einleitungen und ohne Erklärungen aus.
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
Formuliere nun eine völlig neue, unverbrauchte Weisheit des Tages für das Datum {$targetDate}.
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
