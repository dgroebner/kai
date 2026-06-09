<?php
namespace Kai\Tools\Shared\AI;

class GeminiClient {
    private string $apiKey;
    private string $apiUrl;

    public function __construct(string $model = 'gemini-1.5-flash') {
        $this->apiKey = $_ENV['GEMINI_API_KEY'];
        $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    }

    /**
     * Sendet einen Prompt (und optional eine Datei) an Gemini
     */
    public function generate(string $prompt, ?string $mimeType = null, ?string $base64Data = null, bool $jsonMode = false): ?array {
        echo "<br>-> [DEBUG] generate() gestartet...<br>";
        
        // Teste, ob der API Key überhaupt geladen wurde (zeigt nur die ersten 5 Zeichen)
        $maskedKey = substr($this->apiKey, 0, 5) . '...';
        echo "-> [DEBUG] API-Key geladen: " . $maskedKey . "<br>";

        $parts = [['text' => $prompt]];

        if ($mimeType && $base64Data) {
            echo "-> [DEBUG] Bilddaten erkannt (Mime: $mimeType, Länge: " . strlen($base64Data) . " Bytes)<br>";
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data' => $base64Data
                ]
            ];
        }

        $payload = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => ['temperature' => 0.1]
        ];

        if ($jsonMode) {
            $payload['generationConfig']['response_mime_type'] = 'application/json';
        }

        echo "-> [DEBUG] Payload gebaut. Initialisiere cURL...<br>";
        
        $ch = curl_init($this->apiUrl . '?key=' . $this->apiKey);
        if ($ch === false) {
             die("-> [CRITICAL] cURL konnte nicht initialisiert werden! Fehlt die Extension?");
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        // json_encode kann bei sehr großen Base64-Strings am Memory-Limit scheitern!
        echo "-> [DEBUG] Kodiere JSON...<br>";
        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
             die("-> [CRITICAL] json_encode fehlgeschlagen: " . json_last_error_msg());
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

        echo "-> [DEBUG] Führe curl_exec() aus (jetzt warten wir auf Google)...<br>";
        
        // HIER passiert oft der Timeout oder Absturz
        $response = curl_exec($ch);
        
        echo "-> [DEBUG] curl_exec() beendet!<br>";

        if (curl_errno($ch)) {
            echo "<br><b style='color:red;'>cURL System-Fehler:</b> " . curl_error($ch) . "<br>";
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            $responseText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            
            if ($responseText) {
                return $jsonMode ? json_decode($responseText, true) : ['text' => $responseText];
            }
        }
        
        return null;
    }
}