<?php

namespace Kai\Tools\Bank;

use PDO;
use Kai\Tools\Shared\Log\Logger;

class RuleMatcher
{
    private PDO $pdo;
    private Logger $logger;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->logger = new Logger();
    }

    /**
     * Lädt alle aktiven Regeln absteigend nach Priorität.
     *
     * @return array
     */
    public function getAllRules(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, payee_pattern, text_pattern, tag_ids, priority 
            FROM bank_tag_rules 
            ORDER BY priority DESC, id ASC
        ");
        
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rules as &$rule) {
            $rule['tag_ids'] = json_decode($rule['tag_ids'] ?? '[]', true) ?: [];
        }

        return $rules;
    }

    /**
     * Prüft eine einzelne Transaktion gegen eine Liste von Regeln
     * und gibt die erste matchende Regel zurück (First-Match-Wins).
     *
     * @param string $merchantRaw Buchungstext / Empfänger
     * @param array|null $rules Optionale Regel-Liste (sonst lädt die Methode alle aus der DB)
     * @return array|null Gibt die matchende Regel zurück oder null
     */
    public function findMatchingRule(string $merchantRaw, ?array $rules = null): ?array
    {
        if ($rules === null) {
            $rules = $this->getAllRules();
        }

        foreach ($rules as $rule) {
            if ($this->matchesRule($merchantRaw, $rule['text_pattern'] ?? null, $rule['payee_pattern'] ?? null)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Führt den Regex sicher aus (fängt invalide Muster ab).
     */
    public function matchesRule(string $merchantRaw, ?string $textPattern, ?string $payeePattern = null): bool
    {
        // 1. Text-Pattern prüfen
        if (!empty($textPattern)) {
            if (!$this->evalRegex($textPattern, $merchantRaw)) {
                return false;
            }
        }

        // 2. Payee-Pattern prüfen
        if (!empty($payeePattern)) {
            if (!$this->evalRegex($payeePattern, $merchantRaw)) {
                return false;
            }
        }

        return !empty($textPattern) || !empty($payeePattern);
    }

    /**
     * WENNDie Regel neu gespeichert oder verändert wurde:
     * Wende sie retroaktiv auf ALLE bisher ungetaggten Transaktionen an.
     *
     * @param int $ruleId
     * @return int Anzahl der neu getaggten Transaktionen
     */
    public function applyRuleToAllTransactions(int $ruleId): int
    {
        $stmtRule = $this->pdo->prepare("SELECT id, payee_pattern, text_pattern, tag_ids FROM bank_tag_rules WHERE id = :id");
        $stmtRule->execute([':id' => $ruleId]);
        $rule = $stmtRule->fetch(PDO::FETCH_ASSOC);

        if (!$rule) return 0;

        $tagIds = json_decode($rule['tag_ids'] ?? '[]', true) ?: [];
        if (empty($tagIds)) return 0;

        // Alle Transaktionen laden, die noch KEINER Regel zugewiesen sind
        $stmtTx = $this->pdo->query("SELECT id, merchant_raw FROM bank_giro_transactions WHERE matched_rule_id IS NULL");
        $unmatchedTx = $stmtTx->fetchAll(PDO::FETCH_ASSOC);

        $matchedCount = 0;
        $stmtUpdateTx = $this->pdo->prepare("UPDATE bank_giro_transactions SET matched_rule_id = :rule_id WHERE id = :tx_id");
        $stmtInsertTag = $this->pdo->prepare("INSERT IGNORE INTO bank_transaction_tags (transaction_id, tag_id) VALUES (:tx_id, :tag_id)");

        foreach ($unmatchedTx as $tx) {
            if ($this->matchesRule($tx['merchant_raw'], $rule['text_pattern'] ?? null, $rule['payee_pattern'] ?? null)) {
                $stmtUpdateTx->execute([':rule_id' => $ruleId, ':tx_id' => $tx['id']]);

                foreach ($tagIds as $tagId) {
                    $stmtInsertTag->execute([':tx_id' => $tx['id'], ':tag_id' => $tagId]);
                }
                $matchedCount++;
            }
        }

        return $matchedCount;
    }

    /**
     * Läuft über alle ungetaggten Umsätze und wendet alle Regeln absteigend nach Priorität an.
     *
     * @return int Anzahl getaggter Transaktionen
     */
    public function applyAllRulesToUntagged(): int
    {
        $rules = $this->getAllRules();
        if (empty($rules)) return 0;

        $stmtTx = $this->pdo->query("SELECT id, merchant_raw FROM bank_giro_transactions WHERE matched_rule_id IS NULL");
        $unmatchedTx = $stmtTx->fetchAll(PDO::FETCH_ASSOC);

        $matchedCount = 0;
        $stmtUpdateTx = $this->pdo->prepare("UPDATE bank_giro_transactions SET matched_rule_id = :rule_id WHERE id = :tx_id");
        $stmtInsertTag = $this->pdo->prepare("INSERT IGNORE INTO bank_transaction_tags (transaction_id, tag_id) VALUES (:tx_id, :tag_id)");

        foreach ($unmatchedTx as $tx) {
            $matchingRule = $this->findMatchingRule($tx['merchant_raw'], $rules);
            if ($matchingRule) {
                $stmtUpdateTx->execute([':rule_id' => $matchingRule['id'], ':tx_id' => $tx['id']]);

                foreach ($matchingRule['tag_ids'] as $tagId) {
                    $stmtInsertTag->execute([':tx_id' => $tx['id'], ':tag_id' => $tagId]);
                }
                $matchedCount++;
            }
        }

        return $matchedCount;
    }

    /**
     * Führt den Regex sicher aus (fängt invalide/unvollständige Muster beim Tippen ab).
     */
    private function evalRegex(string $pattern, string $subject): bool
    {
        $delimiterPattern = $this->normalizePattern($pattern);

        // Warning-Suppressor @ fängt Regex-Syntaxfehler ab, try-catch fängt PHP 8+ Errors
        try {
            $result = @preg_match($delimiterPattern, $subject);
            if ($result === false || $result === null) {
                return false;
            }
            return $result === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Normalisiert ein Eingabe-Muster zu einem gültigen PCRE Regex-String mit Delimitern.
     */
    public function normalizePattern(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return '//i';
        }
        
        // Prüfen ob bereits Regex-Delimiter wie /.../i vorhanden sind
        if (preg_match('/^(\/|#|~).+\1[a-z]*$/i', $pattern)) {
            return $pattern;
        }

        // Maskiere Slashes, damit der Delimiter nicht gebrochen wird
        return '/' . str_replace('/', '\/', $pattern) . '/i';
    }
}