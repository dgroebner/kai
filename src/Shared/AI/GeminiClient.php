<?php
namespace Kai\Tools\Shared\AI;

use Kai\Tools\Shared\Log\Logger;
use Exception;

class GeminiClient {
    private string $apiKey;
    private string $apiUrl;
    private Logger $logger;

    public function __construct(?string $model = null) {
		$this->apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
		$resolvedModel = $model ?? $_ENV['GEMINI_MODEL'] ?? 'gemini-3.1-flash-lite';
		$this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$resolvedModel}:generateContent"; 
		$this->logger = new Logger();
	}

    public function generate(
        string $prompt, 
        ?string $mimeType = null, 
        ?string $base64Data = null, 
        bool $jsonMode = false,
        ?array $responseSchema = null,
        ?string $systemInstruction = null
    ): ?array {
        $parts = [['text' => $prompt]];

        if ($mimeType && $base64Data) {
            $this->logger->info("GeminiClient: Füge Dokument/Bild zum Payload hinzu", [
                'mime_type' => $mimeType, 
                'size_bytes' => strlen($base64Data)
            ]);
            
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

		if ($jsonMode || $responseSchema !== null) {
			$payload['generationConfig']['response_mime_type'] = 'application/json';
		}

        if ($responseSchema !== null) {
            $payload['generationConfig']['response_schema'] = $responseSchema;
        }

        if ($systemInstruction !== null) {
            $payload['system_instruction'] = [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ];
        }

        $ch = curl_init($this->apiUrl);
        if ($ch === false) {
            throw new Exception("cURL konnte nicht initialisiert werden.");
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->apiKey
        ]);
        
        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            throw new Exception("Payload konnte nicht in JSON encodiert werden: " . json_last_error_msg());
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        
        // Hinweis: Für reine lokale Windows-Tests ohne SSL-Zertifikate diese Zeile einkommentieren:
        if (($_ENV['GEMINI_DISABLE_SSL'] ?? 'false') === 'true') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $this->logger->info("GeminiClient: Sende Request an Google API...");
        $response = curl_exec($ch);

        // Fehlerbehandlung: System-Ebene (cURL)
        if (curl_errno($ch)) {
            $errorMsg = curl_error($ch);
            curl_close($ch);
            throw new Exception("Netzwerk/cURL-Fehler bei API-Anfrage: " . $errorMsg);
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Fehlerbehandlung: API-Ebene (Google)
        if ($httpCode !== 200) {
            $this->logger->error("GeminiClient: Google API lehnte Anfrage ab.", [
                'http_code' => $httpCode, 
                'response' => $response
            ]);
            return null;
        }

        if ($response) {
            $data = json_decode($response, true);
            $responseText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            
            if ($responseText) {
                return $jsonMode ? json_decode($responseText, true) : ['text' => $responseText];
            }
        }
        
        // Fallback, falls die Antwort nicht das erwartete Format hat
        $this->logger->error("GeminiClient: Unerwartete oder leere API-Antwort", ['raw_response' => $response]);
        return null;
    }
}
