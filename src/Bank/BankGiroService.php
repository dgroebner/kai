<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Bank\ComdirectClient;
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
    private BankTransactionRepository $repository;
	private BankAccountRepository $bankAccountRepository;
    private CategoryMatcher $matcher;
    private AiTagClassifier $aiClassifier;
    private Logger $logger;
    private \PDO $pdo;
    private Database $db;

    public function __construct(
        BankTransactionRepository $repository,
		BankAccountRepository $bankAccountRepository,
        CategoryMatcher $matcher,
        AiTagClassifier $aiClassifier
    ) {
        $this->repository = $repository;
		$this->bankAccountRepository = $bankAccountRepository;
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

                // 4. Transaktionen für dieses Konto abrufen
				$lastSync = $this->bankAccountRepository->getLastSyncDate($accountId);
                $txRes = $client->getTransactions($apiTokens['access_token'], $accountId, $lastSync);
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

					// Rohen Verwendungszweck aus den API-Daten holen (prüfe das genaue Feld in deiner API-Antwort, z.B. remittanceInfo oder text)
                    $rawRemittance = $apiTx['remittanceInfo'] ?? '';
                    
                    $lines = [];
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
                    $cleanedRemittance = !empty($lines) ? implode(' ', $lines) : $rawRemittance;

                    $apiTxType = $apiTx['transactionType']['text'] ?? '';
					$typeMapping = [
						'Saving Plan'            => 'Sparplan',
						'Securities'             => 'Wertpapier',
						'Investment Saving'      => 'Geldanlage',
						'Bank fees'              => 'Bankgebühren',
						'Miscellaneous'          => 'Sonstiges',
						'Cash'                   => 'Bar',
						'Interest / Dividends'   => 'Zinsen / Dividenden',
						'Currency Exchange'      => 'Devisen',
						'Cancellation'           => 'Storno',
						'Cheque'                 => 'Scheck',
						'Direct Debit'           => 'Lastschrift',
						'Transfer'               => 'Überweisung',
						'Card transaction'       => 'Kartenverfügung',
						'Foreign Currency exchange' => 'Sorten (Kasse)',
						'ATM Withdrawal'         => 'Geldautomat',
						'Savings'                => 'Geldanlage',
						'Standing Order'         => 'Dauerauftrag',
					];

                    $transformed[] = [
                        'account_id'            => $dbAccountId,
                        'transaction_id'        => $reference,
                        'booking_date'          => $bookingDate,
                        'valuta_date'           => $apiTx['valutaDate'] ?? $bookingDate,
                        'type'                  => $typeMapping,
                        'remittance_info'       => $cleanedRemittance,
                        'amount'                => (float)($apiTx['amount']['value'] ?? 0.0),
						'remitter'              => $apiTx['remitter']['holderName'],
						'debitor'               => $apiTx['deptor']['holderName'],
						'creditor'              => $apiTx['creditor']['holderName'],
						'end_to_end_reference'  => $apiTx['endToEndReference'],
						'dc_creditor_id'        => $apiTx['directDebitCreditorId'],
						'dc_mandate_id'         => $apiTx['directDebitMandateId'],
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
                        'text' => $tx['remittance_info']
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
}