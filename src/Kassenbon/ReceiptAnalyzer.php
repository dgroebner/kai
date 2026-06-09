<?php
namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\AI\GeminiClient;

class ReceiptAnalyzer {
    private GeminiClient $aiClient;

    public function __construct() {
        // Hier instanziieren wir den Shared Service
        $this->aiClient = new GeminiClient();
    }

    public function analyze(string $mimeType, string $base64Data): ?array {
        $prompt = "Du bist ein präziser Datenextraktions-Assistent. Analysiere diesen Kassenbon. " .
                  "Gib AUSSCHLIESSLICH valides JSON zurück. Formatiere es NICHT als Markdown (kein ```json). " .
                  "Das JSON muss exakt dieses Format haben: " .
                  "{ \"store\": \"Name des Händlers\", \"date\": \"YYYY-MM-DD\", \"total\": 0.00, " .
                  "\"items\": [ { \"name\": \"Artikelname\", \"quantity\": 1, \"price\": 0.00, \"category\": \"Kategorie-Vorschlag\" } ] }";

        // Wir rufen die generische Funktion auf und erzwingen den JSON-Modus (true)
        return $this->aiClient->generate($prompt, $mimeType, $base64Data, true);
    }
}