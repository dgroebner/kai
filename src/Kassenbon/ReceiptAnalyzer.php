<?php
namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Log\Logger;
use Exception;

class ReceiptAnalyzer {
    private GeminiClient $aiClient;
    private Logger $logger;

    public function __construct() {
        $this->aiClient = new GeminiClient();
        $this->logger = new Logger(14);
    }

    public function analyze(string $mimeType, string $base64Data): ?array {
        $this->logger->info("ReceiptAnalyzer: Fordere Datenextraktion bei Gemini an...");
        
        $prompt = "Du bist ein präziser Datenextraktions-Assistent. Analysiere diesen Kassenbon. " .
                  "Gib AUSSCHLIESSLICH valides JSON zurück. Formatiere es NICHT als Markdown (kein ```json). " .
                  "Das JSON muss exakt dieses Format haben: " .
                  "{ \"store\": \"Name des Händlers\", \"date\": \"YYYY-MM-DD\", \"total\": 0.00, " .
                  "\"items\": [ { \"name\": \"Artikelname\", \"quantity\": 1, \"price\": 0.00, \"category\": \"Kategorie-Vorschlag\" } ] }";

        try {
            $result = $this->aiClient->generate($prompt, $mimeType, $base64Data, true);
            
            if ($result) {
                $this->logger->info("ReceiptAnalyzer: JSON-Daten erfolgreich extrahiert.");
                return $result;
            }
            
            $this->logger->error("ReceiptAnalyzer: KI lieferte leeres oder ungültiges Ergebnis.");
            return null;
            
        } catch (Exception $e) {
            $this->logger->error("ReceiptAnalyzer: Abbruch bei der KI-Analyse.", ['error' => $e->getMessage()]);
            // Wir werfen den Fehler weiter, damit die ScannerTask weiß, dass dieser spezielle Bon fehlgeschlagen ist
            throw new Exception("Fehler bei der Receipt-Analyse: " . $e->getMessage());
        }
    }
}