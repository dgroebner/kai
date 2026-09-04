<?php

namespace Kai\Tools\Einkaufsliste;

use Exception;
use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Log\Logger;

/**
 * KI-gestützter Rezept- und Freitext-Assistent zur Erkennung von Artikeln,
 * Mengen, Einheiten und automatischer Zuordnung zu Märkten und Gängen.
 */
class RecipeAiService
{
    private GeminiClient $gemini;
    private Logger $logger;
    private MarketCategoryRepository $categoryRepo;

    public function __construct(
        ?GeminiClient $gemini = null,
        ?MarketCategoryRepository $categoryRepo = null
    ) {
        $this->gemini = $gemini ?? new GeminiClient();
        $this->categoryRepo = $categoryRepo ?? new MarketCategoryRepository();
        $this->logger = new Logger();
    }

    /**
     * Parst ein Rezept oder eine freie Textnotiz und liefert strukturierte Einkaufsartikel.
     *
     * @param string $rawText Rezepttext, Zutatenliste oder formlose Einkaufsnotiz
     * @return array<int, array{name: string, quantity: float, unit: string, market: string, category: string}>
     * @throws Exception
     */
    public function parseRecipeText(string $rawText): array
    {
        $text = trim($rawText);
        if ($text === '') {
            return [];
        }

        $allCategories = $this->categoryRepo->getAllCategoriesGrouped();
        $reweCategories = array_column($allCategories['Rewe'] ?? [], 'category_name');
        $globusCategories = array_column($allCategories['Globus'] ?? [], 'category_name');
        $knownCategories = array_unique(array_merge($reweCategories, $globusCategories));

        $categoryList = !empty($knownCategories)
            ? implode(', ', $knownCategories)
            : 'Obst & Gemüse, Brot & Backwaren, Frischetheke (Fleisch & Wurst, Käse), Molkereiprodukte & Eier, Nudeln, Reis & Konserven, Gewürze, Öle & Fertiggerichte, Süßwaren & Snacks, Tiefkühlkost, Getränke, Drogerie & Haushalt, Sonstiges';

        $prompt = "Du bist ein intelligenter Einkaufslisten-Assistent für ein 2-Märkte-System (Rewe und Globus).\n" .
            "Analysiere den folgenden Text (Rezept, Zutatenliste oder Notiz) und extrahiere alle einzukaufenden Artikel.\n\n" .
            "Regeln für die Zuordnung:\n" .
            "1. 'market': Entweder 'Rewe' oder 'Globus'.\n" .
            "   - 'Rewe': Standard für alltägliche Lebensmittel, frisches Obst & Gemüse, Milchprodukte, gängige Marken.\n" .
            "   - 'Globus': Spezialzutaten, exotische Gewürze/Saucen, Großpackungen, besondere Fleischzuschnitte oder wenn im Text Globus erwähnt wird.\n" .
            "2. 'category': Wähle die am besten passende Kategorie aus dieser Liste: [{$categoryList}].\n" .
            "3. 'quantity': Numerischer Wert als Dezimalzahl (z. B. 0.5, 1.0, 500).\n" .
            "4. 'unit': Gebräuchliche Einheit (z. B. 'Stück', 'g', 'kg', 'ml', 'l', 'Packung', 'Dose', 'Bund', 'Becher').\n" .
            "5. Ignoriere reine Zubereitungsschritte, Gewürzmengen wie 'eine Prise Salz' bitte als 1 Stück/Packung erfassen falls nötig, oder sinnvolle Mengen schätzen.\n\n" .
            "Text:\n\"\"\"\n{$text}\n\"\"\"\n\n" .
            "Antworte AUSSCHLIESSLICH mit einem validen JSON-Array aus Objekten im Format:\n" .
            "[{\"name\": \"Artikelname\", \"quantity\": 1.0, \"unit\": \"Stück\", \"market\": \"Rewe\", \"category\": \"Kategorie-Name\"}]";

        try {
            $this->logger->info("RecipeAiService: Sende Text an Gemini zur Analyse...");
            $response = $this->gemini->generate($prompt, null, null, true);

            if (!is_array($response)) {
                $this->logger->warn("RecipeAiService: Gemini lieferte keine Array-Antwort.");
                return [];
            }

            // Wenn das Ergebnis in einem Schlüssel wie 'items' verschachtelt ist
            $items = isset($response['items']) && is_array($response['items']) ? $response['items'] : $response;

            // Validieren und Bereinigen der Artikel
            $cleanItems = [];
            foreach ($items as $item) {
                if (!is_array($item) || empty($item['name'])) {
                    continue;
                }

                $market = ($item['market'] ?? 'Rewe') === 'Globus' ? 'Globus' : 'Rewe';
                $category = trim((string)($item['category'] ?? 'Sonstiges'));
                if ($category === '') {
                    $category = 'Sonstiges';
                }

                $cleanItems[] = [
                    'name' => trim((string)$item['name']),
                    'quantity' => max(0.01, (float)($item['quantity'] ?? 1.00)),
                    'unit' => trim((string)($item['unit'] ?? 'Stück')),
                    'market' => $market,
                    'category' => $category,
                ];
            }

            $this->logger->info("RecipeAiService: " . count($cleanItems) . " Artikel erfolgreich extrahiert.");
            return $cleanItems;

        } catch (Exception $e) {
            $this->logger->error("RecipeAiService: Fehler bei Gemini-Rezeptanalyse.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
