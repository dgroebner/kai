<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Log\ActivityLogger
use Kai\Tools\Bank\Parser\VisaPdfParser;
use Kai\Tools\Shared\Db\Database;
use RuntimeException;
use PDO;

class CreditCardService
{
    private Database $db;
    private VisaPdfParser $parser;

    private array $defaultCategories = [
        'Supermarkt', 'Tankstelle', 'Online-Shopping', 'Gastronomie',
        'Baumarkt', 'Drogerie', 'Tierarzt', 'Freizeit',
        'Bekleidung', 'Elektronik', 'Parken', 'Einzelhandel', 'Sonstiges'
    ];

    public function __construct(Database $db, VisaPdfParser $parser)
    {
        $this->db = $db;
        $this->parser = $parser;
    }

    public function importStatementPdf(string $pdfFilePath, string $savedFileName): int
    {
        // 1. Kategorien aus bank_categories laden
        $dbCategoryMap = $this->getCategoryMapFromDb();

        // 2. Zusammenführen mit Default-Kategorien für den Gemini-Prompt
        $promptCategories = array_unique(array_merge(array_keys($dbCategoryMap), $this->defaultCategories));

        // 3. PDF parsen
        $parsedData = $this->parser->parsePdf($pdfFilePath, $promptCategories);
        $info = $parsedData['statement_info'];
        $transactions = $parsedData['transactions'];

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();

        try {
            $accountId = $this->ensureCreditCardAccount($pdo, "ADAC Visa Card", "Solaris SE");

            $stmt = $pdo->prepare("
                INSERT INTO bank_cc_statements 
                (account_id, statement_date, due_date, total_amount, pdf_filename, reference_iban_suffix) 
                VALUES (:account_id, :statement_date, :due_date, :total_amount, :pdf_filename, :reference_iban_suffix)
            ");

            $stmt->execute([
                ':account_id' => $accountId,
                ':statement_date' => $info['statement_date'],
                ':due_date' => $info['due_date'] ?? null,
                ':total_amount' => $info['total_amount'],
                ':pdf_filename' => $savedFileName,
                ':reference_iban_suffix' => $info['reference_iban_suffix'] ?? null,
            ]);

            $statementId = (int)$pdo->lastInsertId();

            $stmtTx = $pdo->prepare("
                INSERT INTO bank_cc_transactions 
                (statement_id, booking_date, valuta_date, card_number_suffix, merchant_name, amount, category_id) 
                VALUES (:statement_id, :booking_date, :valuta_date, :card_number_suffix, :merchant_name, :amount, :category_id)
            ");

            foreach ($transactions as $tx) {
                $categoryName = !empty($tx['category']) ? $tx['category'] : 'Sonstiges';
                $categoryId = $this->ensureCategoryId($pdo, $categoryName, $dbCategoryMap);

                $stmtTx->execute([
                    ':statement_id' => $statementId,
                    ':booking_date' => $tx['booking_date'],
                    ':valuta_date' => $tx['valuta_date'] ?? null,
                    ':card_number_suffix' => $tx['card_number_suffix'] ?? null,
                    ':merchant_name' => $tx['merchant_name'],
                    ':amount' => $tx['amount'],
                    ':category_id' => $categoryId,
                ]);
            }
			
			$activityLogger = new ActivityLogger($this->db);
			$activityLogger->logCreditCardStatement($statementId, '08/2026');

            $pdo->commit();
            return $statementId;

        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Fehler beim Speichern der Kreditkartenabrechnung: " . $e->getMessage(), 0, $e);
        }
    }
	
	public function getKnownCategoriesForMerchants(array $merchantNames): array
	{
		if (empty($merchantNames)) {
			return [];
		}

		$pdo = $this->db->getConnection();
		$placeholders = implode(',', array_fill(0, count($merchantNames), '?'));
		
		$stmt = $pdo->prepare("
			SELECT t.merchant_name, t.category_id, c.name AS category_name
			FROM bank_cc_transactions t
			JOIN bank_categories c ON t.category_id = c.id
			WHERE t.merchant_name IN ($placeholders)
			  AND t.category_id IS NOT NULL
			ORDER BY t.id DESC
		");
		$stmt->execute($merchantNames);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		// Nur den aktuellsten (neuesten) Treffer pro Händler behalten
		$result = [];
		foreach ($rows as $row) {
			if (!isset($result[$row['merchant_name']])) {
				$result[$row['merchant_name']] = [
					'id' => (int)$row['category_id'],
					'name' => $row['category_name']
				];
			}
		}
		return $result;
	}

    private function ensureCreditCardAccount(PDO $pdo, string $accountName, string $bankName): int
    {
        $stmt = $pdo->prepare("SELECT id FROM bank_accounts WHERE account_name = :name AND account_type = 'credit_card' LIMIT 1");
        $stmt->execute([':name' => $accountName]);
        $accountId = $stmt->fetchColumn();

        if ($accountId) {
            return (int)$accountId;
        }

        $insert = $pdo->prepare("
            INSERT INTO bank_accounts (account_name, bank_name, account_type) 
            VALUES (:name, :bank, 'credit_card')
        ");
        $insert->execute([
            ':name' => $accountName,
            ':bank' => $bankName
        ]);

        return (int)$pdo->lastInsertId();
    }

    private function getCategoryMapFromDb(): array
    {
        $pdo = $this->db->getConnection();
        // Liest aus der separaten Tabelle bank_categories
        $stmt = $pdo->query("SELECT id, name FROM bank_categories");
        $categories = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categories[$row['name']] = (int)$row['id'];
        }
        return $categories;
    }

    private function ensureCategoryId(PDO $pdo, string $categoryName, array &$cache): int
    {
        if (isset($cache[$categoryName])) {
            return $cache[$categoryName];
        }

        $stmt = $pdo->prepare("INSERT INTO bank_categories (name) VALUES (:name)");
        $stmt->execute([':name' => $categoryName]);
        $newId = (int)$pdo->lastInsertId();

        $cache[$categoryName] = $newId;
        return $newId;
    }
	
	public function applyHistoricalCategories(int $statementId): void
	{
		$pdo = $this->db->getConnection();

		// 1. Händler dieser neuen Abrechnung laden
		$stmt = $pdo->prepare("SELECT id, merchant_name FROM bank_cc_transactions WHERE statement_id = :stmt_id");
		$stmt->execute([':stmt_id' => $statementId]);
		$currentTxList = $stmt->fetchAll(PDO::FETCH_ASSOC);

		if (empty($currentTxList)) {
			return;
		}

		$merchantNames = array_column($currentTxList, 'merchant_name');
		
		// 2. Historische Kategorien für diese Händler aus ÄLTEREN Abrechnungen suchen
		$placeholders = implode(',', array_fill(0, count($merchantNames), '?'));
		$sql = "
			SELECT t.merchant_name, t.category_id
			FROM bank_cc_transactions t
			WHERE t.merchant_name IN ($placeholders)
			  AND t.statement_id != ?
			  AND t.category_id IS NOT NULL
			ORDER BY t.id DESC
		";
		
		$params = array_merge($merchantNames, [$statementId]);
		$stmtHist = $pdo->prepare($sql);
		$stmtHist->execute($params);
		$historyRows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

		$historyMap = [];
		foreach ($historyRows as $row) {
			if (!isset($historyMap[$row['merchant_name']])) {
				$historyMap[$row['merchant_name']] = (int)$row['category_id'];
			}
		}

		// 3. Wenn Treffer vorhanden sind, Kategorie-IDs in der neuen Abrechnung aktualisieren
		$updateStmt = $pdo->prepare("UPDATE bank_cc_transactions SET category_id = :cat_id WHERE id = :tx_id");

		foreach ($currentTxList as $tx) {
			$mName = $tx['merchant_name'];
			if (isset($historyMap[$mName])) {
				$updateStmt->execute([
					':cat_id' => $historyMap[$mName],
					':tx_id'  => $tx['id']
				]);
			}
		}
	}
}