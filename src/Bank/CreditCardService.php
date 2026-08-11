<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Bank\Parser\VisaPdfParser;
use Kai\Tools\Shared\Db\Database;
use RuntimeException;
use PDO;

class CreditCardService
{
    private Database $db;
    private VisaPdfParser $parser;

    // Standard-Kategorien als Fallback, falls in der DB noch wenig/nichts vorhanden ist
    private array $defaultCategories = [
        'Supermarkt',
        'Tankstelle',
        'Online-Shopping',
        'Gastronomie',
        'Baumarkt',
        'Drogerie',
        'Tierarzt',
        'Freizeit',
        'Bekleidung',
        'Elektronik',
        'Abonnements / Software',
        'Parken / Fahrzeuge',
        'Einzelhandel',
        'Sonstiges'
    ];

    public function __construct(Database $db, VisaPdfParser $parser)
    {
        $this->db = $db;
        $this->parser = $parser;
    }

    /**
     * Parst ein Visa-PDF, sorgt dynamisch für die benötigten Stammdaten (Account, Kategorien)
     * und speichert Abrechnung sowie Einzelpositionen in der Datenbank.
     */
    public function importStatementPdf(string $pdfFilePath, string $savedFileName): int
    {
        // 1. Bereits existierende Kategorien aus der Datenbank laden
        $dbCategoryMap = $this->getCategoryMapFromDb();

        // 2. Schnittmenge/Kombination aus DB-Kategorien und Default-Kategorien für den Prompt bilden
        $promptCategories = array_unique(array_merge(array_keys($dbCategoryMap), $this->defaultCategories));

        // 3. PDF via Parser und Gemini verarbeiten
        $parsedData = $this->parser->parsePdf($pdfFilePath, $promptCategories);
        $info = $parsedData['statement_info'];
        $transactions = $parsedData['transactions'];

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();

        try {
            // 4. Dynamisches "Get or Create" für das Kreditkarten-Konto
            $accountId = $this->ensureCreditCardAccount($pdo, "ADAC Visa Card", "Solaris SE");

            // 5. Header in bank_cc_statements eintragen
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

            // 6. Einzelpositionen einfügen
            $stmtTx = $pdo->prepare("
                INSERT INTO bank_cc_transactions 
                (statement_id, booking_date, valuta_date, card_number_suffix, merchant_name, amount, category_id) 
                VALUES (:statement_id, :booking_date, :valuta_date, :card_number_suffix, :merchant_name, :amount, :category_id)
            ");

            foreach ($transactions as $tx) {
                $categoryName = !empty($tx['category']) ? $tx['category'] : 'Sonstiges';
                
                // Wandelt den Namen in eine ID um. Falls noch nicht in kb_categories, wird er neu angelegt.
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

            $pdo->commit();
            return $statementId;

        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Fehler beim Speichern der Kreditkartenabrechnung: " . $e->getMessage(), 0, $e);
        }
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

    /**
     * Liest die bestehenden Kategorien aus kb_categories ein: ['Supermarkt' => 1, 'Tankstelle' => 2, ...]
     */
    private function getCategoryMapFromDb(): array
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->query("SELECT id, name FROM kb_categories");
        $categories = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categories[$row['name']] = (int)$row['id'];
        }
        return $categories;
    }

    /**
     * Holt die ID einer Kategorie oder fügt sie dynamisch in kb_categories ein.
     */
    private function ensureCategoryId(PDO $pdo, string $categoryName, array &$cache): int
    {
        if (isset($cache[$categoryName])) {
            return $cache[$categoryName];
        }

        $stmt = $pdo->prepare("INSERT INTO kb_categories (name) VALUES (:name)");
        $stmt->execute([':name' => $categoryName]);
        $newId = (int)$pdo->lastInsertId();

        $cache[$categoryName] = $newId;
        return $newId;
    }
}