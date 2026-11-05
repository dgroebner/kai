<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Db\Database;
use PDO;
use Throwable;

class ContractRuleMatcher
{
    /** Spaltenliste für SELECTs auf bank_giro_transactions beim Vertrags-Matching. */
    public const string TX_COLUMNS = 'id, remittance_info, remitter, debitor, creditor, dc_mandate_id, dc_creditor_id';

    private PDO $pdo;
    private ?array $cachedActiveRules = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        (new BankContractRepository())->ensureCompoundRuleColumns();
    }

    /**
     * Setzt den internen Regel-Cache zurück (z. B. nach dem Anlegen neuer Regeln).
     */
    public function clearRuleCache(): void
    {
        $this->cachedActiveRules = null;
    }

    /**
     * Wendet alle aktiven Vertragsregeln auf sämtliche Giro-Transaktionen an,
     * die aktuell noch keinem Vertrag zugeordnet sind (`contract_id IS NULL`).
     *
     * @return int Anzahl der neu zugeordneten Transaktionen
     */
    public function applyAllRulesToUnmatchedTransactions(): int
    {
        $rules = $this->getActiveRules();
        if (empty($rules)) {
            return 0;
        }

        $stmtTx = $this->pdo->query(
            "SELECT " . self::TX_COLUMNS . " FROM bank_giro_transactions WHERE contract_id IS NULL"
        );
        $unmatched = $stmtTx ? $stmtTx->fetchAll(PDO::FETCH_ASSOC) : [];
        if (empty($unmatched)) {
            return 0;
        }

        $txRepo = new BankTransactionRepository();
        $matchedCount = 0;

        foreach ($unmatched as $txRow) {
            $matchedContractId = $this->matchGiroTransaction($txRow, $rules);
            if ($matchedContractId !== null) {
                $txRepo->assignContract((int)$txRow['id'], $matchedContractId);
                $matchedCount++;
            }
        }

        return $matchedCount;
    }

    /**
     * Prüft eine Giro-Transaktion gegen alle aktiven Vertragsregeln.
     *
     * @param array $transaction Giro-Transaktion (remittance_info, remitter, debitor, creditor, dc_mandate_id, dc_creditor_id)
     * @param array|null $rules Optionale vorab geladene Regelliste
     * @return int|null Vertrags-ID bei Treffer, sonst null
     */
    public function matchGiroTransaction(array $transaction, ?array $rules = null): ?int
    {
        $rules ??= $this->getActiveRules();

        foreach ($rules as $rule) {
            if ($this->evaluateGiroRule($rule, $transaction)) {
                return (int)$rule['contract_id'];
            }
        }

        return null;
    }

    /**
     * Zählt alle Giro-Transaktionen, auf die die übergebenen Kriterien (UND-verknüpft) zutreffen.
     * Nutzt exakt dieselbe Auswertungslogik wie das echte Regel-Matching.
     */
    public function countMatchingTransactions(
        ?string $mandatePattern = null,
        ?string $creditorIdPattern = null,
        ?string $payeePattern = null,
        ?string $textPattern = null
    ): int
    {
        $rule = [
            'mandate_pattern' => $mandatePattern !== null ? trim($mandatePattern) : null,
            'creditor_id_pattern' => $creditorIdPattern !== null ? trim($creditorIdPattern) : null,
            'payee_pattern' => $payeePattern !== null ? trim($payeePattern) : null,
            'text_pattern' => $textPattern !== null ? trim($textPattern) : null,
            'pattern_type' => null,
            'pattern_value' => null,
        ];

        if (!$this->hasAnyCompoundCriterion($rule)) {
            return 0;
        }

        $stmt = $this->pdo->query("SELECT " . self::TX_COLUMNS . " FROM bank_giro_transactions");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $count = 0;
        foreach ($rows as $tx) {
            if ($this->evaluateGiroRule($rule, $tx)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Lädt alle aktiven Regeln über Verknüpfung mit aktiven Verträgen (gecached pro Instanz).
     */
    public function getActiveRules(): array
    {
        if ($this->cachedActiveRules !== null) {
            return $this->cachedActiveRules;
        }

        $stmt = $this->pdo->query("
            SELECT r.*, c.status as contract_status
            FROM bank_contract_rules r
            JOIN bank_contracts c ON r.contract_id = c.id
            WHERE c.status = 'aktiv'
            ORDER BY r.priority DESC, r.id ASC
        ");
        $this->cachedActiveRules = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return $this->cachedActiveRules;
    }

    /**
     * Prüft, ob eine Regel mindestens ein gesetztes Kriterium im neuen UND-Format besitzt.
     */
    private function hasAnyCompoundCriterion(array $rule): bool
    {
        return trim((string)($rule['mandate_pattern'] ?? '')) !== ''
            || trim((string)($rule['creditor_id_pattern'] ?? '')) !== ''
            || trim((string)($rule['payee_pattern'] ?? '')) !== ''
            || trim((string)($rule['text_pattern'] ?? '')) !== '';
    }

    /**
     * Wertet eine Regel gegen eine Giro-Transaktion aus.
     * Unterstützt sowohl kombinierte UND-Regeln als auch Legacy-Einzelregeln.
     */
    public function evaluateGiroRule(array $rule, array $transaction): bool
    {
        if ($this->hasAnyCompoundCriterion($rule)) {
            return $this->evaluateCompoundGiroRule($rule, $transaction);
        }

        // Fallback für unmigrierte Legacy-Einzelregeln (pattern_type + pattern_value)
        $patternValue = trim((string)($rule['pattern_value'] ?? ''));
        $patternType = (string)($rule['pattern_type'] ?? '');
        if ($patternValue === '' || $patternType === '') {
            return false;
        }

        $subjectText = (string)($transaction['remittance_info'] ?? '');
        $payees = [
            (string)($transaction['remitter'] ?? ''),
            (string)($transaction['debitor'] ?? ''),
            (string)($transaction['creditor'] ?? ''),
            (string)($transaction['dc_mandate_id'] ?? ''),
            (string)($transaction['dc_creditor_id'] ?? ''),
        ];

        if ($this->evaluateSinglePattern($patternType, $patternValue, $subjectText)) {
            return true;
        }

        return array_any($payees, fn($payee) => $payee !== '' && $this->evaluateSinglePattern($patternType, $patternValue, $payee));
    }

    /**
     * Wertet eine kombinierte UND-Regel gegen eine Giro-Transaktion aus.
     * Alle nicht-leeren Kriterien der Regel müssen gleichzeitig erfüllt sein.
     */
    private function evaluateCompoundGiroRule(array $rule, array $transaction): bool
    {
        $mandatePattern = trim((string)($rule['mandate_pattern'] ?? ''));
        $creditorIdPattern = trim((string)($rule['creditor_id_pattern'] ?? ''));
        $payeePattern = trim((string)($rule['payee_pattern'] ?? ''));
        $textPattern = trim((string)($rule['text_pattern'] ?? ''));

        // 1. SEPA-Mandatsnummer prüfen (exakter Vergleich ohne Leerzeichen oder im Buchungstext enthalten)
        if ($mandatePattern !== '') {
            $txMandate = trim((string)($transaction['dc_mandate_id'] ?? ''));
            $mandateClean = preg_replace('/\s+/', '', $mandatePattern);
            $txMandateClean = preg_replace('/\s+/', '', $txMandate);

            $matchesMandateField = $txMandateClean !== '' && strcasecmp($mandateClean, $txMandateClean) === 0;
            $matchesRemittance = !$matchesMandateField
                && mb_stripos((string)($transaction['remittance_info'] ?? ''), $mandatePattern) !== false;

            if (!$matchesMandateField && !$matchesRemittance) {
                return false;
            }
        }

        // 2. Gläubiger-ID prüfen (exakter Vergleich ohne Leerzeichen oder im Buchungstext enthalten)
        if ($creditorIdPattern !== '') {
            $txCreditorId = trim((string)($transaction['dc_creditor_id'] ?? ''));
            $credClean = preg_replace('/\s+/', '', $creditorIdPattern);
            $txCredClean = preg_replace('/\s+/', '', $txCreditorId);

            $matchesCreditorField = $txCredClean !== '' && strcasecmp($credClean, $txCredClean) === 0;
            $matchesRemittance = !$matchesCreditorField
                && mb_stripos((string)($transaction['remittance_info'] ?? ''), $creditorIdPattern) !== false;

            if (!$matchesCreditorField && !$matchesRemittance) {
                return false;
            }
        }

        // 3. Auftraggeber / Empfänger / Zahlungspflichtiger prüfen (Substring, case-insensitive)
        if ($payeePattern !== '') {
            $payeeFields = [
                (string)($transaction['remitter'] ?? ''),
                (string)($transaction['creditor'] ?? ''),
                (string)($transaction['debitor'] ?? ''),
            ];
            $matchedPayee = array_any(
                $payeeFields,
                fn(string $fieldVal): bool => trim($fieldVal) !== '' && mb_stripos($fieldVal, $payeePattern) !== false
            );
            if (!$matchedPayee) {
                return false;
            }
        }

        // 4. Buchungstext-Muster prüfen (Regex)
        if ($textPattern !== '') {
            $remittance = (string)($transaction['remittance_info'] ?? '');
            if (!$this->evalRegex($textPattern, $remittance)) {
                return false;
            }
        }

        return true;
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
    public function evalRegex(string $pattern, string $subject): bool
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

        $merchantName = trim((string)($ccTransaction['merchant_name'] ?? ''));
        if ($merchantName === '') {
            return null;
        }

        foreach ($rules as $rule) {
            if ($this->evaluateCcRule($rule, $merchantName)) {
                return (int)$rule['contract_id'];
            }
        }

        return null;
    }

    /**
     * Prüft eine Regel gegen einen Kreditkarten-Händlernamen.
     * Bei kombinierten Regeln dürfen keine Giro-exklusiven Kriterien (Mandat/Gläubiger-ID) gefordert sein,
     * und alle angegebenen Text-/Payee-Kriterien müssen auf den Händlernamen passen.
     */
    public function evaluateCcRule(array $rule, string $merchantName): bool
    {
        $merchantName = trim($merchantName);
        if ($merchantName === '') {
            return false;
        }

        if ($this->hasAnyCompoundCriterion($rule)) {
            $mandatePattern = trim((string)($rule['mandate_pattern'] ?? ''));
            $creditorIdPattern = trim((string)($rule['creditor_id_pattern'] ?? ''));
            $payeePattern = trim((string)($rule['payee_pattern'] ?? ''));
            $textPattern = trim((string)($rule['text_pattern'] ?? ''));

            // Hat die Regel Mandatsnummer oder Gläubiger-ID als Pflichtkriterium, kann eine KK-Buchung nicht matchen
            if ($mandatePattern !== '' || $creditorIdPattern !== '') {
                return false;
            }

            if ($payeePattern !== '' && mb_stripos($merchantName, $payeePattern) === false) {
                return false;
            }

            if ($textPattern !== '' && !$this->evalRegex($textPattern, $merchantName)) {
                return false;
            }

            return ($payeePattern !== '' || $textPattern !== '');
        }

        $type = (string)($rule['pattern_type'] ?? '');
        $val = (string)($rule['pattern_value'] ?? '');
        return $this->evaluateSinglePattern($type, $val, $merchantName);
    }
}