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
        $parts = [['text' => $prompt]];

        // Wenn ein Bild/PDF übergeben wurde, hängen wir es an
        if ($mimeType && $base64Data) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data' => $base64Data
                ]
            ];
        }

        $payload = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0.1
            ]
        ];

        if ($jsonMode) {
            $payload['generationConfig']['response_mime_type'] = 'application/json';
        }

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
            $responseText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            
            if ($responseText) {
                return $jsonMode ? json_decode($responseText, true) : ['text' => $responseText];
            }
        }
        
        return null;
    }
}