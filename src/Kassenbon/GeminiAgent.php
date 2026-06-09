<?php
namespace Kai\Tools\Kassenbon;

class GeminiAgent {
    private string $apiKey;
    private string $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';

    public function __construct() {
        $this->apiKey = $_ENV['GEMINI_API_KEY'];
    }

    /**
     * Sendet das PDF oder Bild an Gemini und erwartet strukturiertes JSON zurück
     */
    public function analyzeReceipt(string $mimeType, string $base64Data): ?array {
        // Der strikte System-Prompt
        $prompt = "Du bist ein präziser Datenextraktions-Assistent. Analysiere diesen Kassenbon. " .
                  "Gib AUSSCHLIESSLICH valides JSON zurück. Formatiere es NICHT als Markdown (kein ```json). " .
                  "Das JSON muss exakt dieses Format haben: " .
                  "{ \"store\": \"Name des Händlers\", \"date\": \"YYYY-MM-DD\", \"total\": 0.00, " .
                  "\"items\": [ { \"name\": \"Artikelname\", \"quantity\": 1, \"price\": 0.00, \"category\": \"Kategorie-Vorschlag\" } ] }";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        ['inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $base64Data
                        ]]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1, // Sehr niedrig, um Halluzinationen zu vermeiden (deterministischer)
                'response_mime_type' => 'application/json' // Zwingt Gemini serverseitig in den JSON-Modus
            ]
        ];

        // cURL Request an die REST API
        $ch = curl_init($this->apiUrl . '?key=' . $this->apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            $jsonText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            
            if ($jsonText) {
                return json_decode($jsonText, true);
            }
        }
        
        return null;
    }
}