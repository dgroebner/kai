<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Bank\Parser\BankCsvParser;
use Kai\Tools\Bank\RuleMatcher;
use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Log\ActivityLogger
use Kai\Tools\Shared\Db\Database;
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

		// 2. Transaktionen in DB schreiben (INSERT IGNORE schützt vor Dubletten)
		$stats = $this->repository->importTransactions($parsedTransactions);
		$this->logger->info("BankGiroService: CSV eingelesen ({$stats['imported']} neu, {$stats['ignored']} Dubletten).");

		// 3. ALLE ungetaggten Transaktionen in der DB laden (nicht nur neu importierte!)
		$unprocessedTxs = $this->repository->getUntaggedTransactions();
		
		if (empty($unprocessedTxs)) {
			$stats['tagged'] = 0;
			return $stats;
		}

		$unmatchedForAi = [];
		$taggedCount = 0;

		// 4. Phase 1: Lokales Regel-Gedächtnis per Regex anwenden
		$ruleMatcher = new RuleMatcher(Database::getInstance()->getConnection());
		$taggedCount = $ruleMatcher->applyAllRulesToUntagged();

		// Verbleibende ungetaggte Umsätze für KI laden
		$unprocessedTxs = $this->repository->getUntaggedTransactions();
		$unmatchedForAi = [];

		foreach ($unprocessedTxs as $tx) {
			$unmatchedForAi[] = [
				'id'   => $tx['id'],
				'text' => $tx['merchant_raw']
			];
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
		
		$activityLogger = new ActivityLogger($this->db);
		$activityLogger->logBankDataImport(count($parsedTransactions));

		$stats['tagged'] = $taggedCount;
		return $stats;
	}
}