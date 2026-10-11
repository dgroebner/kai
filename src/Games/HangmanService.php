<?php

namespace Kai\Tools\Games;

use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Log\Logger;
use Throwable;

/**
 * Service für das Galgenraten-Wortspiel (Hangman).
 *
 * Verwaltet kindgerechte Begriffserzeugung (ab 11 Jahren, 4-12 Buchstaben)
 * und thematische Hinweise via Gemini KI sowie sanitäre Wortbereinigung.
 */
class HangmanService
{
    private GeminiClient $geminiClient;
    private Logger $logger;

    /**
     * Erlaubte kindgerechte Themenkategorien
     */
    public const CATEGORIES = [
        'Tiere & Natur',
        'Sport & Freizeit',
        'Essen & Trinken',
        'Geografie & Welt',
        'Schule & Wissenschaft',
        'Unterhaltung & Medien',
        'Alltag & Zuhause',
    ];

    /**
     * Fallback-Wortliste (für den Fall, dass die Gemini-API offline oder nicht erreichbar ist)
     */
    private const FALLBACK_WORDS = [
        ['word' => 'DELFIN', 'category' => 'Tiere & Natur', 'hint1' => 'Ein sehr kluges Meeressäugetier, das oft fröhlich springt.', 'hint2' => 'Es stößt Klicklaute zur Echoortung aus und hat eine Rückenflosse.'],
        ['word' => 'VULKAN', 'category' => 'Geografie & Welt', 'hint1' => 'Ein feuriger Berg, der geschmolzenes Gestein spucken kann.', 'hint2' => 'Wenn er ausbricht, fließt glühend heiße Lava herab.'],
        ['word' => 'SKATEBOARD', 'category' => 'Sport & Freizeit', 'hint1' => 'Ein rollendes Brett für coole Tricks auf der Straße oder im Park.', 'hint2' => 'Man steht seitlich darauf und stößt sich mit einem Fuß ab.'],
        ['word' => 'SPAGHETTI', 'category' => 'Essen & Trinken', 'hint1' => 'Lange, dünne italienische Nudeln, oft mit Tomatensauce serviert.', 'hint2' => 'Man dreht sie am besten mit einer Gabel auf.'],
        ['word' => 'TELESKOP', 'category' => 'Schule & Wissenschaft', 'hint1' => 'Ein optisches Gerät, um weit entfernte Sterne und Planeten zu beobachten.', 'hint2' => 'Astronomen schauen nachts hindurch in den Himmel.'],
        ['word' => 'GECKO', 'category' => 'Tiere & Natur', 'hint1' => 'Eine kleine Echse, die dank spezieller Lamellen an Fensterscheiben klettern kann.', 'hint2' => 'Sie ist nachtaktiv und fängt blitzschnell Insekten.'],
        ['word' => 'KOMPASS', 'category' => 'Geografie & Welt', 'hint1' => 'Ein Navigationsinstrument, dessen Nadel immer nach Norden zeigt.', 'hint2' => 'Es nutzt das Magnetfeld der Erde zur Orientierung.'],
        ['word' => 'ROBOTER', 'category' => 'Schule & Wissenschaft', 'hint1' => 'Eine programmierbare Maschine, die oft Aufgaben für Menschen übernimmt.', 'hint2' => 'Besteht aus Elektronik, Sensoren und Motoren.'],
        ['word' => 'TRAMPOLIN', 'category' => 'Sport & Freizeit', 'hint1' => 'Ein federndes Sportgerät für hohe Luftsprünge und Saltos.', 'hint2' => 'Steht häufig im Garten mit einem Sicherheitsnetz drumherum.'],
        ['word' => 'PANCAKE', 'category' => 'Essen & Trinken', 'hint1' => 'Ein dicker, fluffiger Pfannkuchen, der oft mit Ahornsirup gegessen wird.', 'hint2' => 'Beliebtes amerikanisches Frühstücksgericht.'],
    ];

    public function __construct(?GeminiClient $geminiClient = null, ?Logger $logger = null)
    {
        $this->geminiClient = $geminiClient ?? new GeminiClient();
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Bereinigt ein Wort für das Galgenraten:
     * - Wandelt in Großbuchstaben um
     * - Ersetzt Umlaute und ß (Ä -> AE, Ö -> OE, Ü -> UE, ß -> SS) für einheitliches 26-Buchstaben-Raten
     * - Filtert alle Nicht-Buchstaben (Leerzeichen, Bindestriche etc.)
     */
    public static function sanitizeWord(string $rawWord): string
    {
        $word = mb_strtoupper(trim($rawWord), 'UTF-8');
        $word = str_replace(
            ['Ä', 'Ö', 'Ü', 'ß'],
            ['AE', 'OE', 'UE', 'SS'],
            $word
        );
        // Nur A-Z beibehalten
        return preg_replace('/[^A-Z]/', '', $word);
    }

    /**
     * Generiert via Gemini ein frisches, kindgerechtes Wort samt Kategorie und 2 Hinweisen.
     *
     * @param string|null $preferredCategory Optionale Kategorie-Vorgabe
     * @return array{word: string, category: string, hint1: string, hint2: string, source: string}
     */
    public function generateWord(?string $preferredCategory = null): array
    {
        $catPrompt = $preferredCategory && in_array($preferredCategory, self::CATEGORIES, true)
            ? "Kategorie muss sein: \"$preferredCategory\"."
            : "Wähle zufällig eine dieser Kategorien: " . implode(', ', self::CATEGORIES) . ".";

        $systemInstruction = <<<TEXT
Du bist der Spielleiter für das Wortratespiel Galgenraten (Hangman) für Kinder und Jugendliche ab 11 Jahren.
Deine Aufgabe ist es, genau ein passendes deutsches Substantiv (Hauptwort) im Nominativ Singular zu erfinden, das sich hervorragend zum Raten eignet.

Regeln:
1. Das Wort muss für 11- bis 14-Jährige verständlich, spannend und kindgerecht sein.
2. Keine Kraftausdrücke, keine Gewalt, keine Erwachsenen-Themen, keine extrem seltenen Fremdwörter.
3. Wortlänge: Mindestens 4, maximal 12 Buchstaben.
4. Es muss ein reales deutsches Substantiv sein (keine Eigennamen von Personen).
5. $catPrompt
6. Gib zusätzlich zwei kindgerechte Hinweise (Hints) an:
   - hint1: Umschreibung der Wortbedeutung (für Rateversuche ab 3 Fehlern).
   - hint2: Ein noch konkreterer, anschaulicher Hinweis (für Rateversuche ab 5 Fehlern).
TEXT;

        $prompt = "Generiere jetzt ein einzelnes Wort für das Galgenraten als JSON.";

        $responseSchema = [
            'type' => 'OBJECT',
            'properties' => [
                'word' => [
                    'type' => 'STRING',
                    'description' => 'Das deutsche Substantiv in Großbuchstaben (ohne Artikel, 4 bis 12 Buchstaben)'
                ],
                'category' => [
                    'type' => 'STRING',
                    'description' => 'Die passende Kategorie'
                ],
                'hint1' => [
                    'type' => 'STRING',
                    'description' => 'Tipp 1: Kindgerechte Umschreibung'
                ],
                'hint2' => [
                    'type' => 'STRING',
                    'description' => 'Tipp 2: Konkreterer Hinweis'
                ],
            ],
            'required' => ['word', 'category', 'hint1', 'hint2']
        ];

        try {
            $result = $this->geminiClient->generate(
                prompt: $prompt,
                jsonMode: true,
                responseSchema: $responseSchema,
                systemInstruction: $systemInstruction,
                temperature: 0.8,
                timeout: 15
            );

            if ($result && !empty($result['word'])) {
                $cleanWord = self::sanitizeWord((string)$result['word']);
                $len = strlen($cleanWord);

                if ($len >= 4 && $len <= 14) {
                    return [
                        'word' => $cleanWord,
                        'category' => trim((string)($result['category'] ?? 'Allgemein')),
                        'hint1' => trim((string)($result['hint1'] ?? 'Kein Hinweis verfügbar.')),
                        'hint2' => trim((string)($result['hint2'] ?? 'Kein weiterer Hinweis verfügbar.')),
                        'source' => 'gemini',
                    ];
                }
            }
        } catch (Throwable $e) {
            $this->logger->warn('HangmanService: Fehler beim Gemini-Aufruf, nutze Fallback-Wort.', [
                'error' => $e->getMessage()
            ]);
        }

        // Lokaler Fallback
        return $this->getRandomFallbackWord($preferredCategory);
    }

    /**
     * Liefert ein zufälliges lokales Fallback-Wort.
     *
     * @return array{word: string, category: string, hint1: string, hint2: string, source: string}
     */
    public function getRandomFallbackWord(?string $preferredCategory = null): array
    {
        $pool = self::FALLBACK_WORDS;
        if ($preferredCategory) {
            $filtered = array_values(array_filter($pool, fn($item) => $item['category'] === $preferredCategory));
            if (!empty($filtered)) {
                $pool = $filtered;
            }
        }

        $pick = $pool[array_rand($pool)];
        return [
            'word' => self::sanitizeWord($pick['word']),
            'category' => $pick['category'],
            'hint1' => $pick['hint1'],
            'hint2' => $pick['hint2'],
            'source' => 'fallback',
        ];
    }
}
