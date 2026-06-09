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

	// Die Methode nimmt jetzt optional ein Array der bisherigen Kategorien entgegen
    public function analyze(string $mimeType, string $base64Data, array $existingCategories = []): ?array {
        $this->logger->info("ReceiptAnalyzer: Fordere Datenextraktion bei Gemini an...");
        
        // Dynamischer Kontext für die Kategorien
        $categoryContext = empty($existingCategories) 
            ? "Erstelle passende, generische Kategorien (z.B. Milch & Käse, Obst & Gemüse, Fleisch & Wurst, Fisch, Cerealien, Süßwaren, Brot & Gebäck, Getränke, Haushalt,
			Pflege & Gesundheit, Tierbedarf)."
            : "WICHTIG: Ordne die Artikel zwingend einer dieser bekannten Kategorien zu, falls passend: [" . implode(', ', $existingCategories) . "]. Erfinde nur eine neue Kategorie, wenn wirklich absolut keine der vorhandenen passt.";

        $prompt = "Du bist ein präziser Datenextraktions-Assistent. Analysiere diesen Kassenbon. " .
                  "Gib AUSSCHLIESSLICH valides JSON zurück. Formatiere es NICHT als Markdown (kein ```json). " .
                  $categoryContext . " " .
                  "Das JSON muss exakt dieses Format haben: " .
                  "{ \"store\": \"Name des Händlers\", \"date\": \"YYYY-MM-DD\", \"total\": 0.00, " .
                  "\"items\": [ { \"name\": \"Artikelname\", \"quantity\": 1.0, \"unit_price\": 0.00, \"total_price\": 0.00, \"category\": \"Kategorie-Name\" } ] }";

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