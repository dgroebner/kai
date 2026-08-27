<?php

namespace Kai\Tools\Bank;

use PDO;
use Throwable;

class ContractRuleMatcher
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Prüft eine Giro-Transaktion gegen alle aktiven Vertragsregeln.
     *
     * @param array $transaction Giro-Transaktion (remittance_info, remitter, debitor, creditor, dc_mandate_id, dc_creditor_id)
     * @return int|null Vertrags-ID bei Treffer, sonst null
     */
    public function matchGiroTransaction(array $transaction): ?int
    {
        $rules = $this->getActiveRules();

        $subjectText = (string)($transaction['remittance_info'] ?? '');

        // Alle relevanten Text- und Identifikationsfelder zusammenfassen
        $payees = [
            (string)($transaction['remitter'] ?? ''),
            (string)($transaction['debitor'] ?? ''),
            (string)($transaction['creditor'] ?? ''),
            (string)($transaction['dc_mandate_id'] ?? ''),  // Mandatsnummer einbeziehen
            (string)($transaction['dc_creditor_id'] ?? '')   // Gläubiger-ID einbeziehen
        ];

        foreach ($rules as $rule) {
            if ($this->evaluateRule($rule, $subjectText, $payees)) {
                return (int)$rule['contract_id'];
            }
        }

        return null;
    }

    /**
     * Alias für konsistente Namensgebung.
     */
    private function getActiveRules(): array
    {
        return $this->getAllActiveRules();
    }

    /**
     * Lädt alle aktiven Regeln über Verknüpfung mit aktiven Verträgen.
     */
    private function getAllActiveRules(): array
    {
        $stmt = $this->pdo->query("
            SELECT r.*, c.status as contract_status
            FROM bank_contract_rules r
            JOIN bank_contracts c ON r.contract_id = c.id
            WHERE c.status = 'aktiv'
            ORDER BY r.priority DESC, r.id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Wertet eine Regel gegen Text- und Empfängerdaten aus.
     */
    private function evaluateRule(array $rule, string $subjectText, array $payees): bool
    {
        $type = $rule['pattern_type'];
        $pattern = $rule['pattern_value'];

        // 1. Prüfen ob der Text passt
        if ($this->evaluateSinglePattern($type, $pattern, $subjectText)) {
            return true;
        }

        // 2. Prüfen ob einer der Beteiligten passt
        return array_any($payees, fn($payee) => $payee !== '' && $this->evaluateSinglePattern($type, $pattern, $payee));

    }

    /**
     * Wendet den spezifischen Mustertyp auf einen String an.
     */
    private function evaluateSinglePattern(string $type, string $pattern, string $subject): bool
    {
        if (trim($pattern) === '' || trim($subject) === '') {
            return false;
        }

        return match ($type) {
            'exact_match' => strcasecmp(trim($pattern), trim($subject)) === 0,
            'substring' => mb_stripos($subject, $pattern) !== false,
            'regex' => $this->evalRegex($pattern, $subject),
            default => false,
        };
    }

    /**
     * Führt den Regex sicher aus (angelehnt an den RuleMatcher).
     */
    private function evalRegex(string $pattern, string $subject): bool
    {
        $delimiterPattern = $this->normalizePattern($pattern);

        try {
            $result = @preg_match($delimiterPattern, $subject);
            if ($result === false || $result === null) {
                return false;
            }
            return $result === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizePattern(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return '//i';
        }

        if (preg_match('%^([/#~]).+\1[a-z]*$%i', $pattern)) {
            return $pattern;
        }

        return '/' . str_replace('/', '\/', $pattern) . '/i';
    }

    /**
     * Prüft eine Kreditkarten-Position gegen alle aktiven Vertragsregeln.
     *
     * @param array $ccTransaction CC-Transaktion (merchant_name)
     * @return int|null Vertrags-ID bei Treffer, sonst null
     */
    public function matchCcTransaction(array $ccTransaction): ?int
    {
        $rules = $this->getActiveRules();

        $merchantName = (string)($ccTransaction['merchant_name'] ?? '');

        foreach ($rules as $rule) {
            // Bei Kreditkarten prüfen wir den Händlernamen gegen das Pattern
            if ($this->evaluateSinglePattern($rule['pattern_type'], $rule['pattern_value'], $merchantName)) {
                return (int)$rule['contract_id'];
            }
        }

        return null;
    }
}