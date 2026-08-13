<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Log\Logger;
use Exception;

class AiTagClassifier
{
    private GeminiClient $geminiClient;
    private Logger $logger;

    public function __construct(GeminiClient $geminiClient)
    {
        $this->geminiClient = $geminiClient;
        $this->logger = new Logger(14);
    }

    /**
     * Reicht eine Liste unbekannter Transaktionen an Gemini ein,
     * um aus den bestehenden Tags passende Vorschläge zu erhalten.
     *
     * @param array $unmatchedTransactions Array mit ['id' => 12, 'text' => '...']
     * @param array $availableTags Array der existierenden Tags (z. B. ['Energie', 'Strom', 'Lebensmittel'])
     * @return array Map von transaction_id => array von Tag-Namen
     */
    public function classifyBatch(array $unmatchedTransactions, array $availableTags): array
    {
        if (empty($unmatchedTransactions) || empty($availableTags)) {
            return [];
        }

        $promptText = $this->buildPromptText($unmatchedTransactions, $availableTags);

        try {
            // Nutzt den GeminiClient deines Projekts (generateJson liefert direkt strukturiertes JSON/Array zurück)
            $parsed = $this->geminiClient->generateJson($promptText);
            
            if (!is_array($parsed)) {
                return [];
            }

            $resultMap = [];
            foreach ($parsed as $item) {
                if (isset($item['id'], $item['tags']) && is_array($item['tags'])) {
                    $resultMap[(int)$item['id']] = $item['tags'];
                }
            }

            return $resultMap;
        } catch (Exception $e) {
            $this->logger->error('AiTagClassifier: Fehler bei Gemini-Klassifizierung: ' . $e->getMessage());
            return [];
        }
    }

    private function buildPromptText(array $transactions, array $tags): string
    {
        $tagList = implode(', ', $tags);
        $txJson = json_encode($transactions, JSON_UNESCAPED_UNICODE);

        return <<<TEXT
Du bist ein Finanz-Assistent. Ordne den folgenden Bank-Transaktionen passende Tags aus der Liste der verfügbaren Tags zu.

Verfügbare Tags: [{$tagList}]

Regeln:
1. Wähle pro Transaktion 1 bis max. 3 passende Tags aus der Liste.
2. Wenn kein Tag passt, gib ein leeres Array zurück.
3. Antworte AUSSCHLIESSLICH als valides JSON-Array im folgenden Format:
[
  {"id": 12, "tags": ["Energie", "Strom"]},
  {"id": 13, "tags": ["Lebensmittel"]}
]

Transaktionen:
{$txJson}
TEXT;
    }
}