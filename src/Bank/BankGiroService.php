<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Bank\Parser\BankCsvParser;
use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Log\Logger;
use Exception;

class BankGiroService
{
    private BankCsvParser $parser;
    private BankTransactionRepository $repository;
    private CategoryMatcher $matcher;
    private AiTagClassifier $aiClassifier;
    private Logger $logger;

    public function __construct(
        BankCsvParser $parser,
        BankTransactionRepository $repository,
        CategoryMatcher $matcher,
        AiTagClassifier $aiClassifier
    ) {
        $this->parser = $parser;
        $this->repository = $repository;
        $this->matcher = $matcher;
        $this->aiClassifier = $aiClassifier;
        $this->logger = new Logger(14);
    }

    /**
     * Importiert eine CSV-Datei, wendet lokale Regeln an und klassifiziert den Rest via Gemini KI.
     *
     * @param string $csvFilePath
     * @return array Import-Statistik
     */
    public function importCsv(string $csvFilePath): array
    {
        // 1. CSV-Datei parsen & Hashes generieren
        $parsedTransactions = $this->parser->parse($csvFilePath);
        if (empty($parsedTransactions)) {
            $this->logger->info("BankGiroService: Keine gültigen Transaktionen in CSV gefunden.");
            return ['imported' => 0, 'ignored' => 0, 'tagged' => 0];
        }

        // 2. Transaktionen in DB schreiben (INSERT IGNORE)
        $stats = $this->repository->importTransactions($parsedTransactions);
        $this->logger->info("BankGiroService: CSV eingelesen ({$stats['imported']} neu, {$stats['ignored']} Dubletten).");

        if ($stats['imported'] === 0) {
            return $stats; // Keine neuen Transaktionen zum Taggen vorhanden
        }

        // 3. Nur die neu importierten Transaktionen für die Tag-Zuordnung laden
        $unprocessedTxs = $this->repository->getUntaggedTransactions();
        
        $unmatchedForAi = [];
        $taggedCount = 0;

        // 4. Phase 1: Lokales Regel-Gedächtnis prüfen
        foreach ($unprocessedTxs as $tx) {
            $matchedTagIds = $this->matcher->match($tx['merchant_raw']);
            
            if (!empty($matchedTagIds)) {
                $this->repository->assignTagsToTransaction($tx['id'], $matchedTagIds);
                $taggedCount++;
            } else {
                // Für KI-Analyse vormerken
                $unmatchedForAi[] = [
                    'id'   => $tx['id'],
                    'text' => $tx['merchant_raw']
                ];
            }
        }

        // 5. Phase 2: verbleibende unkategorisierte Umsätze im Bulk an Gemini schicken
        if (!empty($unmatchedForAi)) {
            $availableTags = $this->repository->getAllTagNames();
            
            if (!empty($availableTags)) {
                $aiSuggestions = $this->aiClassifier->classifyBatch($unmatchedForAi, $availableTags);

                foreach ($aiSuggestions as $txId => $suggestedTagNames) {
                    if (empty($suggestedTagNames)) continue;

                    $tagIds = $this->repository->getTagIdsByNames($suggestedTagNames);
                    if (!empty($tagIds)) {
                        $this->repository->assignTagsToTransaction($txId, $tagIds);
                        $taggedCount++;
                    }
                }
            }
        }

        $stats['tagged'] = $taggedCount;
        return $stats;
    }
}