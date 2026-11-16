<?php

namespace Kai\Tools\Shared\AI;

use Exception;
use Kai\Tools\Shared\Log\Logger;

class GeminiClient
{
    private const DEFAULT_FALLBACK_MODELS = [
        'gemini-4-hydrogen',
        'gemini-3.8-flash',
        'gemini-3.5-flash-lite',
    ];

    private string $apiKey;
    private string $primaryModel;
    /** @var string[] */
    private array $fallbackModels;
    private Logger $logger;
    private int $lastHttpCode = 0;

    public function __construct(?string $model = null)
    {
        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        $this->primaryModel = $model ?? ($_ENV['GEMINI_MODEL'] ?? 'gemini-3.1-flash-lite');
        $this->fallbackModels = $this->resolveFallbackModels($this->primaryModel);
        $this->logger = new Logger();
    }

    public function getLastHttpCode(): int
    {
        return $this->lastHttpCode;
    }

    public function isTemporarilyUnavailable(): bool
    {
        return in_array($this->lastHttpCode, [429, 503], true);
    }

    /**
     * @return string[]
     */
    private function resolveFallbackModels(string $primaryModel): array
    {
        $envRaw = trim((string)($_ENV['GEMINI_FALLBACK_MODELS'] ?? ''));
        $candidates = $envRaw !== ''
            ? array_map('trim', explode(',', $envRaw))
            : self::DEFAULT_FALLBACK_MODELS;

        $fallbacks = [];
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && $candidate !== $primaryModel && !in_array($candidate, $fallbacks, true)) {
                $fallbacks[] = $candidate;
            }
        }

        return $fallbacks;
    }

    private function buildApiUrl(string $model): string
    {
        return "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    }

    /**
     * @throws Exception
     */
    public function generate(
        string  $prompt,
        ?string $mimeType = null,
        ?string $base64Data = null,
        bool    $jsonMode = false,
        ?array  $responseSchema = null,
        ?string $systemInstruction = null,
        ?float  $temperature = null,
        int     $timeout = 90
    ): ?array
    {
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
                'temperature' => $temperature ?? 0.1
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

        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            throw new Exception("Payload konnte nicht in JSON encodiert werden: " . json_last_error_msg());
        }

        $this->lastHttpCode = 0;
        $modelsToTry = ($timeout <= 10)
            ? [$this->primaryModel]
            : array_merge([$this->primaryModel], $this->fallbackModels);

        $response = null;
        $httpCode = 0;
        $usedModel = $this->primaryModel;

        foreach ($modelsToTry as $modelIndex => $currentModel) {
            $usedModel = $currentModel;
            $isPrimary = ($modelIndex === 0);
            $maxAttempts = ($isPrimary && $timeout > 10) ? 3 : 1;
            $apiUrl = $this->buildApiUrl($currentModel);

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $ch = curl_init($apiUrl);
                if ($ch === false) {
                    throw new Exception("cURL konnte nicht initialisiert werden.");
                }

                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $this->apiKey
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);

                // Hinweis: Für reine lokale Windows-Tests ohne SSL-Zertifikate diese Zeile einkommentieren:
                if (($_ENV['GEMINI_DISABLE_SSL'] ?? 'false') === 'true') {
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                }

                $this->logger->info("GeminiClient: Sende Request an Google API (Modell: $currentModel, Versuch $attempt/$maxAttempts)...");
                $response = curl_exec($ch);

                // Fehlerbehandlung: System-Ebene (cURL)
                if (curl_errno($ch)) {
                    $errorMsg = curl_error($ch);
                    curl_close($ch);
                    throw new Exception("Netzwerk/cURL-Fehler bei API-Anfrage: " . $errorMsg);
                }

                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                // Bei temporärer Überlastung (503) oder Rate Limit (429) kurz warten und wiederholen
                if (($httpCode === 503 || $httpCode === 429) && $attempt < $maxAttempts) {
                    $waitSec = $attempt * 2;
                    $this->logger->warn("GeminiClient: Vorübergehende Überlastung (HTTP $httpCode) bei '$currentModel'. Wiederhole Versuch in {$waitSec}s...");
                    sleep($waitSec);
                    continue;
                }

                break;
            }

            if ($httpCode === 200) {
                break;
            }

            // Bei Überlastung/Rate-Limit/nicht verfügbarem Modell auf das nächste Fallback-Modell wechseln
            $hasNextModel = isset($modelsToTry[$modelIndex + 1]);
            if ($hasNextModel && in_array($httpCode, [404, 429, 503], true)) {
                $nextModel = $modelsToTry[$modelIndex + 1];
                $this->logger->warn("GeminiClient: Modell '$currentModel' nicht verfügbar (HTTP $httpCode). Wechsle auf Fallback-Modell '$nextModel'...");
                continue;
            }

            break;
        }

        $this->lastHttpCode = $httpCode;

        // Fehlerbehandlung: API-Ebene (Google)
        if ($httpCode !== 200) {
            $this->logger->error("GeminiClient: Google API lehnte Anfrage ab.", [
                'model' => $usedModel,
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
