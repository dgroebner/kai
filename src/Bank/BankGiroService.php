<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Bank\ComdirectClient;
use Kai\Tools\Bank\Parser\BankCsvParser;
use Kai\Tools\Bank\RuleMatcher;
use Kai\Tools\Bank\StatementMatcher;
use Kai\Tools\Kassenbon\ReceiptMatcher;
use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Db\Database;
use Exception;

class BankGiroService
{
    private BankCsvParser $parser;
    private BankTransactionRepository $repository;
    private CategoryMatcher $matcher;
    private AiTagClassifier $aiClassifier;
    private Logger $logger;
    private \PDO $pdo;
    private Database $db;

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
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getConnection();
    }

    /**
     * Synchronisiert Salden und Umsätze von Giro- und Tagesgeldkonto über die comdirect API.
     *
     * @param array $apiTokens Die aktuellen OAuth-Tokens
     * @return array Sync-Statistik
     */
    public function syncWithComdirectApi(array $apiTokens): array
    {
        $client = new ComdirectClient();
        $repoAcc = new BankAccountRepository();

        $stats = [
            'imported' => 0,
            'ignored' => 0,
            'tagged' => 0
        ];

        try {
            // 1. Konten und Salden abrufen
            $accountsRes = $client->getAccounts($apiTokens['access_token']);
            $apiAccounts = $accountsRes['values'] ?? [];

            if (empty($apiAccounts)) {
                $this->logger->info("BankGiroService: Keine Kontodaten von comdirect empfangen.");
                return $stats;
            }

            $importedTransactions = [];

            foreach ($apiAccounts as $item) {
                $apiAcc = $item['account'] ?? [];
                $apiBal = $item['balance'] ?? [];

                $accountId = $apiAcc['accountId'] ?? '';
                $iban = $apiAcc['iban'] ?? '';
                $typeKey = $apiAcc['accountType']['key'] ?? '';
                $balanceVal = (float)($apiBal['balance']['value'] ?? 0.0);

                if (empty($accountId)) {
                    continue;
                }

                // 2. Passendes lokales Konto in DB suchen (nach IBAN oder Typ)
                $dbAccount = null;
                if (!empty($iban)) {
                    $dbAccount = $repoAcc->getAccountByIban($iban);
                }
                if (!$dbAccount && !empty($typeKey)) {
                    $dbAccount = $repoAcc->getAccountByType($typeKey);
                }

                if (!$dbAccount) {
                    $this->logger->info("BankGiroService: Kein passendes lokales Konto für IBAN '$iban' / Typ '$typeKey' gefunden.");
                    continue;
                }

                $dbAccountId = (int)$dbAccount['id'];

                // 3. Saldo in DB aktualisieren (Echtzeitsaldo)
                $repoAcc->updateBalance($dbAccountId, $balanceVal);

                // 4. Transaktionen für dieses Konto abrufen (nur nach dem 15.08.2026)
                $txRes = $client->getTransactions($apiTokens['access_token'], $accountId, '2026-08-15');
                $apiTxList = $txRes['values'] ?? [];

                $transformed = [];
                foreach ($apiTxList as $apiTx) {
                    $bookingStatus = $apiTx['bookingStatus'] ?? 'BOOKED';
                    
                    // Aktuell sollen nur BOOKED-Buchungen berücksichtigt werden
                    if ($bookingStatus !== 'BOOKED') {
                        continue;
                    }

                    $bookingDate = $apiTx['bookingDate'] ?? '';
                    
                    // Dublettenvermeidung: Buchungen bis einschließlich 15.08.2026 ignorieren
                    if (empty($bookingDate) || $bookingDate <= '2026-08-15') {
                        continue;
                    }

                    // Eindeutige Referenz als ID-Ersatz nutzen (z.B. "6F2C29CH2F7RCCZ0/12729")
                    $reference = $apiTx['reference'] ?? '';
                    if (empty($reference)) {
                        continue;
                    }

                    // Partner-Namen ermitteln (Remitter oder Creditor)
                    $partnerName = $apiTx['remitter']['holderName'] 
                        ?? $apiTx['creditor']['holderName'] 
                        ?? '';

                    // In Blöcke à 37 Zeichen zerlegen
                    $chunks = str_split($rawRemittance, 37);
                    if ($chunks) {
                        foreach ($chunks as $chunk) {
                            // Prüfen ob der Block mit einer zweistelligen Nummer beginnt
                            if (preg_match('/^\d{2}(.*)$/', $chunk, $matches)) {
                                $cleanedLine = trim($matches[1]);
                                if (!empty($cleanedLine)) {
                                    $lines[] = $cleanedLine;
                                }
                            }
                        }
                    }
                    $cleanedRemittance = implode(' ', $lines);

                    $txTypeText = $apiTx['transactionType']['text'] ?? '';

                    // Kompatiblen raw_text aufbauen
                    $rawTextParts = [];
                    if (!empty($partnerName)) {
                        $rawTextParts[] = "Auftraggeber: {$partnerName}";
                    }
                    if (!empty($txTypeText)) {
                        $rawTextParts[] = "Buchungstext: {$cleanedRemittance}";
                    }
                    if (!empty($reference)) {
                        $rawTextParts[] = "Ref. {$reference}";
                    }
                    $rawText = implode(' ', $rawTextParts);

                    $transformed[] = [
                        'account_id'     => $dbAccountId,
                        'tx_hash'        => hash('sha256', 'comdirect_' . $reference),
                        'transaction_id' => $reference,
                        'booking_date'   => $bookingDate,
                        'valuta_date'    => $apiTx['valutaDate'] ?? $bookingDate,
                        'type'           => $txTypeText,
                        'raw_text'       => $rawText,
                        'amount'         => (float)($apiTx['amount']['value'] ?? 0.0),
                    ];
                }

                if (!empty($transformed)) {
                    // 5. Transaktionen in DB importieren
                    $importRes = $this->repository->importTransactions($transformed);
                    $stats['imported'] += $importRes['imported'];
                    $stats['ignored']  += $importRes['ignored'];
                    
                    foreach ($transformed as $t) {
                        $importedTransactions[] = $t;
                    }
                }
            }

            $this->logger->info("BankGiroService: API Sync abgeschlossen ({$stats['imported']} neu, {$stats['ignored']} Dubletten).");

            // 6. Nachgelagerte Services (Regel-Gedächtnis, KI, CC, E-Bons)
            $unprocessedTxs = $this->repository->getUntaggedTransactions();
            if (empty($unprocessedTxs)) {
                $stats['tagged'] = 0;
            } else {
                $ruleMatcher = new RuleMatcher($this->pdo);
                $taggedCount = $ruleMatcher->applyAllRulesToUntagged();

                $unprocessedTxs = $this->repository->getUntaggedTransactions();
                $unmatchedForAi = [];
                foreach ($unprocessedTxs as $tx) {
                    $unmatchedForAi[] = [
                        'id'   => $tx['id'],
                        'text' => $tx['merchant_raw']
                    ];
                }

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
            }

            // Kreditkartenabrechnungen verknüpfen
            $statementMatcher = new StatementMatcher($this->pdo);
            $linkedStatementsCount = $statementMatcher->syncUnlinkedStatements();
            if ($linkedStatementsCount > 0) {
                $this->logger->info("BankGiroService: {$linkedStatementsCount} Kreditkartenabrechnung(en) verknüpft.");
            }

            // E-Bons verknüpfen
            $receiptMatcher = new ReceiptMatcher($this->pdo);
            $receiptMatcher->syncUnlinkedReceipts();

            // Im Aktivitäts-Log festhalten
            $activityLogger = new ActivityLogger($this->db);
            $activityLogger->logBankDataImport(count($importedTransactions));

        } catch (Exception $e) {
            $this->logger->error("BankGiroService API-Sync fehlgeschlagen: " . $e->getMessage());
            throw $e;
        }

        return $stats;
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
		
        // 6. Phase 3: Kreditkartenabrechnungen mit neuen Girokonto-Umsätzen verknüpfen
		$statementMatcher = new StatementMatcher(Database::getInstance()->getConnection());
		$linkedStatementsCount = $statementMatcher->syncUnlinkedStatements();
		if ($linkedStatementsCount > 0) {
			$this->logger->info("BankGiroService: {$linkedStatementsCount} Kreditkartenabrechnung(en) automatisch mit Giro-Umsatz verknüpft.");
		}
		
		// 7. Phase 4: E-Bons mit neuen Girokonto-Umsätzen verknüpfen
		$receiptMatcher = new ReceiptMatcher($this->pdo);
        $receiptMatcher->syncUnlinkedReceipts();

		$activityLogger = new ActivityLogger($this->db);
		$activityLogger->logBankDataImport(count($parsedTransactions));

		$stats['tagged'] = $taggedCount;
		return $stats;
	}
}