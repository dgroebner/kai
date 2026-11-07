<?php

namespace Kai\Tools\Recipes;

use Exception;
use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\System\SystemSettingsService;

/**
 * KI-Service für die Domäne "Recipes":
 * - Multimodaler Rezept-Import (Foto, PDF, Web-URL, Freitext)
 * - Übersetzung von Küchen-Fachbegriffen in klare, jugendfreundliche Sprache (11+ Jahre)
 * - Konkrete Herd-Stufen (z. B. 12-Stufen-Ceranfeld) & Backofen-Einstellungen
 * - Automatische Schalotten-Präferenz, individuelle Haushalts-Vorlieben, 2-Phasen Mise en Place & Multi-Topf-Zerlegung
 * - KI-gestützter Zutatentausch ("Alternative suchen") & Varianten-Generierung
 */
class RecipeAiService
{
    private GeminiClient $gemini;
    private SystemSettingsService $settings;
    private Logger $logger;

    public function __construct(
        ?GeminiClient $gemini = null,
        ?SystemSettingsService $settings = null,
        ?Logger $logger = null
    ) {
        $this->gemini = $gemini ?? new GeminiClient();
        $this->settings = $settings ?? new SystemSettingsService();
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Extrahiert und strukturiert ein komplettes Rezept aus einer URL, einem Foto, PDF oder Text.
     *
     * @param string $sourceType 'url' | 'photo' | 'pdf' | 'text'
     * @param string $textOrUrl Rohtext oder Ziel-URL
     * @param string|null $mimeType MIME-Type bei Datei-Upload (image/jpeg, image/png, image/webp, application/pdf)
     * @param string|null $base64Data Base64-kodierte Binärdaten
     * @return array<string, mixed>
     * @throws Exception
     */
    public function importRecipe(
        string $sourceType,
        string $textOrUrl = '',
        ?string $mimeType = null,
        ?string $base64Data = null
    ): array {
        $stoveType = $this->settings->getStoveType();
        $stoveLevels = $this->settings->getStoveLevels();
        $ovenMode = $this->settings->getOvenDefaultMode();
        $preferShallots = $this->settings->isRecipePreferShallotsEnabled();
        $defaultAdults = $this->settings->getRecipeDefaultAdults();
        $defaultKids = $this->settings->getRecipeDefaultKids();
        $kidPortionPct = $this->settings->getRecipeKidPortionPct();
        $defaultServings = round($defaultAdults + ($defaultKids * ($kidPortionPct / 100.0)), 2);
        $householdPrefs = $this->settings->getRecipeHouseholdPreferences();

        $sourceContent = trim($textOrUrl);
        if ($sourceType === 'url') {
            $sourceContent = $this->fetchRecipeWebPage($sourceContent);
        }

        $shallotRule = $preferShallots
            ? "WICHTIGE HAUSHALTS-PRÄFERENZ: Ersetze reguläre Zwiebeln/Speisezwiebeln/Gemüsezwiebeln automatisch durch 'Schalotten' (passe die Stückzahl sinnvoll an, z. B. 1 große Zwiebel = 2 Schalotten) und erwähne diesen Hinweis kurz in den Varianten-Notizen."
            : "Behalte Zwiebeln wie im Originalrezept bei.";

        $customPrefBlock = $householdPrefs !== ''
            ? "WEITERE HAUSHALTS-VORLIEBEN & REGELN (unbedingt beim Import berücksichtigen!): {$householdPrefs}"
            : "";

        $midLow = max(1, (int)round($stoveLevels * 0.2));
        $simmer = max(2, (int)round($stoveLevels * 0.38));
        $medium = max(3, (int)round($stoveLevels * 0.55));
        $fry = max(4, (int)round($stoveLevels * 0.72));
        $sear = max(5, (int)round($stoveLevels * 0.88));

        $prompt = <<<PROMPT
Du bist ein präziser, familienfreundlicher Koch-Assistent für das Smart-Home-System "Kai".
Analysiere das übergebene Rezept (aus Text, Webseite, Foto oder PDF) und bereite es für unseren Haushalt ({$defaultAdults} Erwachsene + {$defaultKids} Kinder/Jugendliche ab 11 Jahren mit {$kidPortionPct}% Portionsgröße = {$defaultServings} Vollportionen) optimal auf.

VERBINDLICHE REGELN FÜR DIE AUFBEREITUNG:
1. KEIN KÜCHEN-CHINESISCH (Jugendgerecht 11+ Jahre):
   - Verzichte auf unerklärte Fachbegriffe wie "blanchieren", "anschwitzen", "ablöschen", "reduzieren", "legieren", "parieren".
   - Beschreibe stattdessen konkret die Handlung und woran man erkennt, dass der Schritt fertig ist (z. B. statt "anschwitzen": "3-4 Minuten anbraten, bis die Schalotten weich und glasig sind, aber noch nicht braun").
2. KONKRETE HERDSTUFEN STATT "MITTLERE HITZE":
   - Unser Herd ist ein {$stoveType} mit {$stoveLevels} Stufen (Stufe 1 bis {$stoveLevels}).
   - Übersetze vage Hitzeangaben IMMER in konkrete Stufen:
     * Warmhalten / Schmelzen: Stufe 1-{$midLow}
     * Sanft köcheln / Ziehen lassen mit Deckel (Reis, Kartoffeln, Schmoren): Stufe {$simmer}
     * Mittlere Hitze / Glasig dünsten / Rührei: Stufe {$medium}
     * Normales Anbraten (Gemüse, Geschnetzeltes, Hackfleisch, Pfannkuchen): Stufe {$fry}
     * Scharfes Anbraten (Steak, Braten ankrusten): Stufe {$sear}
     * Wasser im Topf aufkochen: Stufe {$stoveLevels}
   - Backofen: Gib konkrete Gradzahlen mit Beheizungsart an (Standard: {$ovenMode}, z. B. "180 °C {$ovenMode}").
3. INLINE-MENGEN IN JEDES SCHRITT-TEXTFELD:
   - Nenne in JEDEM Kochschritt-Text (`instruction`) die exakte Menge der jeweiligen Zutat direkt im Satz (z. B. "Jetzt **2 TL Currypulver** und **200 ml Sahne** einrühren"), damit man beim Kochen niemals nach oben zur Zutatenliste scrollen muss!
   - Befülle zusätzlich `ingredients_inline` für jeden Schritt als Array der in diesem Schritt verwendeten Zutaten.
4. {$shallotRule}
   {$customPrefBlock}
5. 2-PHASEN MISE EN PLACE (VORBEREITUNG) & MULTI-TOPF-TIMING:
   - Zerlege das Gericht in logische Komponenten (`components`), z. B. "Vorbereitung", "Hauptgericht / Fleisch", "Kartoffeln / Beilage", "Gemüse / Soße".
   - Vorbereitung vor dem Einschalten des Herdes (`phase = "mise_en_place_pre"`): Nur das, was bereitstehen muss, bevor der erste Topf heiß wird.
   - Parallele Vorbereitung (`phase = "mise_en_place_parallel"`): Wenn eine Komponente längere Zeit passiv kocht/schmort/backt (>= 15 Min, z. B. Fleisch schmort 60 Min), lege das Schälen/Schneiden späterer Beilagen (z. B. Kartoffeln schälen) gezielt in diese Wartezeit!
   - Berücksichtige bei Kochtöpfen (z. B. Kartoffeln, Nudeln, Reis) explizit einen Aufheiz-Schritt (`phase = "preheat"`, z. B. "Topf mit Salzwasser auf Stufe {$stoveLevels} zum Kochen bringen", `duration_minutes = 8`), damit die Synchronisation mehrerer Töpfe exakt aufgeht!
6. VORRATSSCHRANK-FILTER & PACKUNGSGRÖSSEN:
   - Setze `is_pantry_staple = 1` bei typischen Vorratsartikeln, die man fast immer im Schrank hat (Salz, Pfeffer, Gewürze, Speiseöl, Olivenöl, Essig, Mehl, Zucker, Brühe, Senf, Schalotten, Knoblauch). Bei frischen Einkaufszutaten `is_pantry_staple = 0`.
   - Gib bei verpackten Zutaten (z. B. Schmand, Sahne, Dosentomaten, Kokosmilch, Frischkäse, Quark) die übliche Supermarkt-Packungsgröße (`package_size`, `package_unit`) und einen kurzen `leftover_tip` zur Resteverwertung an.
   - Ordne `market` ('Rewe' oder 'Globus') und `shopping_category` (z. B. 'Obst & Gemüse', 'Frischetheke (Fleisch & Wurst, Käse)', 'Molkereiprodukte & Eier', 'Nudeln, Reis & Konserven', 'Gewürze, Öle & Fertiggerichte', 'Tiefkühlkost', 'Brot & Backwaren', 'Sonstiges') zu.

Antworte AUSSCHLIESSLICH mit einem validen JSON-Objekt in exakt dieser Struktur:
{
  "title": "Rezepttitel",
  "description": "Kurze, appetitliche Beschreibung (1-2 Sätze)",
  "category": "Hauptgericht",
  "prep_time_minutes": 20,
  "cook_time_minutes": 35,
  "total_time_minutes": 55,
  "default_adults": {$defaultAdults},
  "default_kids": {$defaultKids},
  "default_servings": {$defaultServings},
  "calories_per_serving": 540,
  "protein_per_serving": 32.0,
  "carbs_per_serving": 48.0,
  "fat_per_serving": 22.0,
  "equipment_list": ["Große Pfanne", "Kochtopf mit Deckel", "Schneidebrett", "Kochmesser"],
  "variant_name": "Standard (Familien-Variante)",
  "variant_notes": "Angepasst auf {$stoveLevels}-Stufen-{$stoveType} und Familien-Präferenzen.",
  "ingredients": [
    {
      "ingredient_name": "Schalotten",
      "quantity": 2,
      "unit": "Stück",
      "package_size": null,
      "package_unit": null,
      "leftover_tip": null,
      "market": "Rewe",
      "shopping_category": "Obst & Gemüse",
      "is_pantry_staple": 1,
      "prep_note": "fein gewürfelt",
      "component_name": "Hauptgericht"
    }
  ],
  "components": [
    {
      "component_name": "Vorbereitung (Mise en Place)",
      "pot_or_appliance": "Arbeitsfläche",
      "preheat_minutes": 0,
      "active_cook_minutes": 10,
      "passive_cook_minutes": 0,
      "rest_minutes": 0,
      "steps": [
        {
          "step_number": 1,
          "phase": "mise_en_place_pre",
          "title": "Schalotten & Zutaten bereitstellen",
          "instruction": "Vor dem Einschalten des Herdes: **2 Schalotten** schälen und in feine Würfel schneiden.",
          "ingredients_inline": [{"name": "Schalotten", "quantity": 2, "unit": "Stück"}],
          "stove_level": null,
          "oven_setting": null,
          "duration_minutes": 5,
          "is_passive_wait": 0,
          "parallel_hint": null
        }
      ]
    }
  ]
}
PROMPT;

        if ($sourceContent !== '') {
            $prompt .= "\n\nQUELLE / REZEPTTEXT:\n\"\"\"\n" . mb_substr($sourceContent, 0, 28000) . "\n\"\"\"";
        }

        $this->logger->info('RecipeAiService: Starte Rezept-Analyse mit Gemini...', [
            'source_type' => $sourceType,
            'has_media' => $mimeType !== null && $base64Data !== null,
        ]);

        $response = $this->gemini->generate(
            $prompt,
            $mimeType,
            $base64Data,
            true,
            null,
            null,
            0.15,
            120
        );

        if (!is_array($response) || empty($response['title'])) {
            throw new Exception('Das Rezept konnte von der KI nicht vollständig erkannt werden.');
        }

        return $this->normalizeParsedRecipe($response, $sourceType, $sourceType === 'url' ? trim($textOrUrl) : null);
    }

    /**
     * Schlägt 3 passende Alternativen für eine Zutat im Kontext des aktuellen Rezepts vor.
     *
     * @param array<string, mixed> $recipe
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    public function suggestIngredientAlternatives(array $recipe, string $ingredientName, string $reason = ''): array
    {
        $stoveLevels = $this->settings->getStoveLevels();
        $householdPrefs = $this->settings->getRecipeHouseholdPreferences();
        $title = (string)($recipe['title'] ?? 'Gericht');
        $reasonHint = trim($reason) !== '' ? "Grund/Wunsch des Nutzers: {$reason}" : "Der Nutzer möchte eine geschmacklich passende Abwechslung.";
        $prefHint = $householdPrefs !== '' ? "Haushalts-Vorlieben beachten: {$householdPrefs}" : "";

        $prompt = <<<PROMPT
Du bist ein kreativer Familien-Kochassistent.
Im Rezept "{$title}" möchte die Familie die Zutat "{$ingredientName}" durch eine Alternative ersetzen.
{$reasonHint}
{$prefHint}

Schlage 3 konkret passende Alternativen vor, die im Supermarkt (Rewe/Globus) leicht erhältlich sind und Kindern/Jugendlichen (11+ Jahre) sowie Erwachsenen gut schmecken.
Berücksichtige unseren Herd mit {$stoveLevels} Stufen.

Antworte AUSSCHLIESSLICH mit einem JSON-Array aus 3 Objekten im Format:
[
  {
    "alternative_name": "Name der Ersatz-Zutat",
    "quantity": 250,
    "unit": "g",
    "taste_note": "Wie verändert sich das Gericht geschmacklich/optisch?",
    "cooking_adjustment": "Konkrete Anpassung beim Kochen (z. B. Garzeit 5 Min kürzer auf Stufe 7)",
    "is_pantry_staple": 0,
    "shopping_category": "Obst & Gemüse"
  }
]
PROMPT;

        $response = $this->gemini->generate($prompt, null, null, true, null, null, 0.3, 60);
        if (!is_array($response)) {
            return [];
        }

        $items = isset($response['alternatives']) && is_array($response['alternatives'])
            ? $response['alternatives']
            : $response;

        $result = [];
        foreach ($items as $alt) {
            if (!is_array($alt) || empty($alt['alternative_name'])) {
                continue;
            }
            $result[] = [
                'alternative_name' => trim((string)$alt['alternative_name']),
                'quantity' => max(0.1, (float)($alt['quantity'] ?? 1.0)),
                'unit' => trim((string)($alt['unit'] ?? 'Stück')),
                'taste_note' => trim((string)($alt['taste_note'] ?? '')),
                'cooking_adjustment' => trim((string)($alt['cooking_adjustment'] ?? '')),
                'is_pantry_staple' => !empty($alt['is_pantry_staple']) ? 1 : 0,
                'shopping_category' => trim((string)($alt['shopping_category'] ?? 'Sonstiges')),
            ];
        }

        return $result;
    }

    /**
     * Erzeugt aus einer bestehenden Rezept-Variante eine abgewandelte Variante mit getauschter Zutat
     * inklusive angepasster Schritte und Inline-Mengen.
     *
     * @param array<string, mixed> $recipe
     * @param array<string, mixed> $replacement
     * @return array{variant_name: string, notes: string, ingredients: array<int, array<string, mixed>>, components: array<int, array<string, mixed>>}
     */
    public function buildSubstitutedVariant(
        array $recipe,
        string $oldIngredientName,
        array $replacement,
        ?string $customVariantName = null
    ): array {
        $newName = trim((string)($replacement['alternative_name'] ?? ''));
        $newQty = max(0.01, (float)($replacement['quantity'] ?? 1.0));
        $newUnit = trim((string)($replacement['unit'] ?? 'Stück'));
        $cookAdjustment = trim((string)($replacement['cooking_adjustment'] ?? ''));

        $variantName = $customVariantName !== null && trim($customVariantName) !== ''
            ? trim($customVariantName)
            : 'Mit ' . $newName . ' (statt ' . $oldIngredientName . ')';

        $notes = sprintf(
            'Abwandlung: %s ersetzt durch %s %s %s.%s',
            $oldIngredientName,
            str_replace('.', ',', (string)$newQty),
            $newUnit,
            $newName,
            $cookAdjustment !== '' ? ' Hinweis: ' . $cookAdjustment : ''
        );

        $newIngredients = [];
        foreach ($recipe['ingredients'] ?? [] as $ing) {
            if (mb_strtolower(trim((string)$ing['ingredient_name'])) === mb_strtolower(trim($oldIngredientName))) {
                $ing['ingredient_name'] = $newName;
                $ing['quantity'] = $newQty;
                $ing['unit'] = $newUnit;
                $ing['is_pantry_staple'] = !empty($replacement['is_pantry_staple']) ? 1 : 0;
                $ing['shopping_category'] = !empty($replacement['shopping_category']) ? (string)$replacement['shopping_category'] : ($ing['shopping_category'] ?? 'Sonstiges');
                $ing['package_size'] = null;
                $ing['leftover_tip'] = null;
            }
            $newIngredients[] = $ing;
        }

        $newComponents = [];
        foreach ($recipe['components'] ?? [] as $comp) {
            $steps = [];
            foreach ($comp['steps'] ?? [] as $step) {
                $instr = (string)($step['instruction'] ?? '');
                $replacedInStep = false;

                if (mb_stripos($instr, $oldIngredientName) !== false) {
                    $instr = str_ireplace($oldIngredientName, $newName, $instr);
                    $replacedInStep = true;
                }

                $inline = $step['ingredients_inline_parsed'] ?? [];
                foreach ($inline as &$inItem) {
                    if (mb_strtolower(trim((string)($inItem['name'] ?? ''))) === mb_strtolower(trim($oldIngredientName))) {
                        $inItem['name'] = $newName;
                        $inItem['quantity'] = $newQty;
                        $inItem['unit'] = $newUnit;
                        $replacedInStep = true;
                    }
                }
                unset($inItem);

                if ($replacedInStep && $cookAdjustment !== '' && mb_stripos($instr, $cookAdjustment) === false) {
                    $instr .= ' (' . $cookAdjustment . ')';
                }

                $step['instruction'] = $instr;
                $step['ingredients_inline'] = $inline;
                $steps[] = $step;
            }
            $comp['steps'] = $steps;
            $newComponents[] = $comp;
        }

        return [
            'variant_name' => $variantName,
            'notes' => $notes,
            'ingredients' => $newIngredients,
            'components' => $newComponents,
        ];
    }

    /**
     * Lädt den Text- und JSON-LD-Inhalt einer Rezept-Webseite sicher per cURL.
     * Verhindert SSRF durch Protokoll- und IP-Prüfung.
     *
     * @throws Exception
     */
    private function fetchRecipeWebPage(string $url): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception('Ungültige Rezept-URL.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new Exception('Nur HTTP/HTTPS-Links sind erlaubt.');
        }

        // SSRF-Schutz: Keine lokalen oder privaten IP-Bereiche aufrufen
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new Exception('Zugriff auf lokale Netzwerkadressen ist nicht gestattet.');
            }
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception('Netzwerk-Client konnte nicht initialisiert werden.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: de-DE,de;q=0.9,en;q=0.7',
            ],
        ]);

        if (($_ENV['GEMINI_DISABLE_SSL'] ?? 'false') === 'true') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $html = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($html) || $html === '' || $httpCode >= 400) {
            throw new Exception('Die Rezept-Webseite konnte nicht geladen werden (HTTP ' . $httpCode . ').');
        }

        $extractedBlocks = [];
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $jsonBlock) {
                $trimmed = trim($jsonBlock);
                if (stripos($trimmed, 'Recipe') !== false || stripos($trimmed, 'recipeIngredient') !== false) {
                    $extractedBlocks[] = "JSON-LD REZEPTDATEN:\n" . $trimmed;
                }
            }
        }

        $cleanHtml = preg_replace('/<(script|style|svg|noscript|footer|nav|iframe)[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $plainText = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($cleanHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        $combined = implode("\n\n", $extractedBlocks) . "\n\nSEITENTEXT:\n" . mb_substr($plainText, 0, 20000);
        return trim($combined);
    }

    /**
     * Validiert und normalisiert die KI-Antwort für das Speichern über RecipeRepository.
     *
     * @param array<string, mixed> $raw
     * @return array{recipe: array<string, mixed>, variant: array<string, mixed>}
     */
    private function normalizeParsedRecipe(array $raw, string $sourceType, ?string $sourceUrl): array
    {
        $adults = max(1, (int)($raw['default_adults'] ?? $this->settings->getRecipeDefaultAdults()));
        $kids = max(0, (int)($raw['default_kids'] ?? $this->settings->getRecipeDefaultKids()));
        $kidFactor = $this->settings->getRecipeKidPortionPct() / 100.0;
        $servings = isset($raw['default_servings']) && (float)$raw['default_servings'] > 0
            ? (float)$raw['default_servings']
            : round($adults + ($kids * $kidFactor), 2);

        $recipeData = [
            'title' => trim((string)($raw['title'] ?? 'Importiertes Rezept')),
            'description' => trim((string)($raw['description'] ?? '')),
            'category' => trim((string)($raw['category'] ?? 'Hauptgericht')),
            'source_type' => $sourceType,
            'source_url' => $sourceUrl,
            'prep_time_minutes' => max(0, (int)($raw['prep_time_minutes'] ?? 15)),
            'cook_time_minutes' => max(0, (int)($raw['cook_time_minutes'] ?? 25)),
            'total_time_minutes' => max(0, (int)($raw['total_time_minutes'] ?? 40)),
            'default_adults' => $adults,
            'default_kids' => $kids,
            'default_servings' => $servings,
            'calories_per_serving' => !empty($raw['calories_per_serving']) ? (int)$raw['calories_per_serving'] : null,
            'protein_per_serving' => isset($raw['protein_per_serving']) ? (float)$raw['protein_per_serving'] : null,
            'carbs_per_serving' => isset($raw['carbs_per_serving']) ? (float)$raw['carbs_per_serving'] : null,
            'fat_per_serving' => isset($raw['fat_per_serving']) ? (float)$raw['fat_per_serving'] : null,
            'equipment_list' => is_array($raw['equipment_list'] ?? null) ? $raw['equipment_list'] : [],
        ];

        $variantData = [
            'variant_name' => trim((string)($raw['variant_name'] ?? 'Standard (Familien-Variante)')),
            'notes' => trim((string)($raw['variant_notes'] ?? $raw['notes'] ?? '')),
            'ingredients' => is_array($raw['ingredients'] ?? null) ? $raw['ingredients'] : [],
            'components' => is_array($raw['components'] ?? null) ? $raw['components'] : [],
        ];

        return [
            'recipe' => $recipeData,
            'variant' => $variantData,
        ];
    }
}
