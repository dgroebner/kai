<?php

namespace Kai\Tools\Bank\Parser;

use Exception;
use Kai\Tools\Shared\AI\GeminiClient;
use RuntimeException;

class VisaPdfParser
{
    private GeminiClient $geminiClient;

    public function __construct(GeminiClient $geminiClient)
    {
        $this->geminiClient = $geminiClient;
    }

    /**
     * @throws Exception
     */
    public function parsePdf(string $pdfFilePath, array $availableCategories = []): array
    {
        if (!file_exists($pdfFilePath)) {
            throw new RuntimeException("PDF-Datei nicht gefunden: $pdfFilePath");
        }

        $fileBytes = file_get_contents($pdfFilePath);
        if ($fileBytes === false) {
            throw new RuntimeException("Konnte PDF-Datei nicht lesen: $pdfFilePath");
        }
        $base64Data = base64_encode($fileBytes);

        $prompt = $this->getSystemPrompt($availableCategories);

        $responseArray = $this->geminiClient->generate(
            prompt: $prompt,
            mimeType: 'application/pdf',
            base64Data: $base64Data,
            jsonMode: true
        );

        if (empty($responseArray)) {
            throw new RuntimeException("Keine gültige Antwort von der Gemini API erhalten.");
        }

        if (!isset($responseArray['statement_info']) || !isset($responseArray['transactions'])) {
            throw new RuntimeException("Unvollständiges JSON-Schema in der KI-Antwort.");
        }

        return $responseArray;
    }

    private function getSystemPrompt(array $categories = []): string
    {
        if (!empty($categories)) {
            $catList = implode(', ', $categories);
            $categoryInstruction = "- category: Ordne dem Händler die am besten passende Kategorie zu. Nutze AUSSCHLIESSLICH eine dieser vorgegebenen Kategorien: [$catList].";
        } else {
            $categoryInstruction = "- category: Ordne dem Händler eine allgemeine, passende Kategorie zu (z.B. Supermarkt, Tankstelle, Gastronomie, Online-Shopping, Drogerie, Unterhaltung).";
        }

        return <<<PROMPT
Analysiere die angehängte Kreditkartenabrechnung (PDF) und extrahiere alle Kopfdaten sowie einzelnen Transaktionen als valides JSON.

WICHTIGE REGELN:
1. Extrahiere die Kopfdaten der Abrechnung:
   - statement_date: Rechnungsdatum (Format YYYY-MM-DD)
   - due_date: Geplantes Einzugsdatum / Fälligkeitsdatum vom Referenzkonto (Format YYYY-MM-DD)
   - total_amount: Neuer Kontostand / Einzugsbetrag als positive Zahl (z.B. 3709.15)
   - reference_iban_suffix: Die letzten 4 Ziffern der Referenz-IBAN (z.B. "7700")

2. Extrahiere ALLE einzelnen Käufe/Umsätze in das Array "transactions":
   - booking_date: Kaufdatum (Format YYYY-MM-DD)
   - valuta_date: Buchungsdatum / Wertstellung (Format YYYY-MM-DD)
   - merchant_name: Name des Händlers inkl. Ort (z.B. "REWE Lucas Musculu, Leipzig")
   - amount: Betrag in Euro als Zahl. WICHTIG: Ausgaben MÜSSEN NEGATIV sein (z.B. -26.80). Gutschriften/Rückerstattungen MÜSSEN POSITIV sein (z.B. 14.97).
   - card_number_suffix: Die letzten Ziffern der verwendeten Karte (z.B. "9024" oder "9016")
   - card_holder: Name des Karteninhabers, falls dem Kartenblock zugeordnet (z.B. "ANJA GROBNER")
   {$categoryInstruction}

3. WICHTIGE AUSNAHMEN & FILTER:
   - IGNORIERE alle Bonuszahlen-Zeilen (z.B. "+26 ADAC BONI", "Tankrabatt").
   - IGNORIERE die Ausgleichs-Zeile der vorherigen Abrechnung (z.B. "Ausgleich Kartenabrechnung vom...").

GIB AUSSCHLIESSLICH REINES JSON ZURÜCK:

{
  "statement_info": {
    "statement_date": "2026-05-26",
    "due_date": "2026-06-02",
    "total_amount": 3709.15,
    "reference_iban_suffix": "7700"
  },
  "transactions": [
    {
      "booking_date": "2026-05-22",
      "valuta_date": "2026-05-24",
      "merchant_name": "Sachsen-Therme GmbH & Co., Leipzig",
      "amount": -26.80,
      "card_number_suffix": "9024",
      "card_holder": "ANJA GROBNER",
      "category": "Freizeit"
    }
  ]
}
PROMPT;
    }
}