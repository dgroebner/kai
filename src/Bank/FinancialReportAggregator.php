<?php

namespace Kai\Tools\Bank;

use DateTimeImmutable;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Utils\MerchantNormalizer;
use PDO;

class FinancialReportAggregator
{
    private PDO $pdo;
    private BankTagRepository $bankTagRepository;
    private BankAccountRepository $bankAccountRepository;

    public function __construct(
        ?PDO $pdo = null,
        ?BankTagRepository $bankTagRepository = null,
        ?BankAccountRepository $bankAccountRepository = null
    ) {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->bankTagRepository = $bankTagRepository ?? new BankTagRepository();
        $this->bankAccountRepository = $bankAccountRepository ?? new BankAccountRepository();
    }

    /**
     * Aggregiert alle relevanten Finanzdaten für Ziel- und Referenzzeitraum deterministisch im Backend.
     *
     * @param string $periodType 'month' oder 'year'
     * @param string $periodTarget z. B. '2026-08' oder '2026'
     * @param string|null $periodReference z. B. '2026-07' oder '2025'
     * @return array Vollständiges Aggregations-Payload
     */
    public function aggregate(string $periodType, string $periodTarget, ?string $periodReference = null): array
    {
        $dates = $this->resolveDateRanges($periodType, $periodTarget, $periodReference);

        $metadata = [
            'period_type' => $periodType,
            'period_target' => $periodTarget,
            'period_reference' => $dates['ref_label'],
            'target_start' => $dates['target_start'],
            'target_end' => $dates['target_end'],
            'ref_start' => $dates['ref_start'],
            'ref_end' => $dates['ref_end'],
        ];

        $cashflowTotals = $this->calculateCashflowTotals(
            $dates['target_start'],
            $dates['target_end'],
            $periodType,
            $periodTarget
        );

        $tagBreakdown = $this->calculateTagBreakdown(
            $dates['target_start'],
            $dates['target_end'],
            $dates['ref_start'],
            $dates['ref_end']
        );

        $contractDeviations = $this->calculateContractDeviations(
            $dates['target_start'],
            $dates['target_end'],
            $periodType
        );

        $receiptInsights = $this->calculateReceiptInsights(
            $dates['target_start'],
            $dates['target_end'],
            $dates['ref_start'],
            $dates['ref_end']
        );

        $cashflowHistory = $this->calculateCashflowHistory(
            $periodType,
            $periodTarget
        );

        return [
            'metadata' => $metadata,
            'cashflow_totals' => $cashflowTotals,
            'cashflow_history' => $cashflowHistory,
            'tag_breakdown' => $tagBreakdown,
            'contract_deviations' => $contractDeviations,
            'receipt_insights' => $receiptInsights,
        ];
    }

    /**
     * Löst Start- und Enddaten für Ziel- und Referenzzeitraum auf.
     */
    public function resolveDateRanges(string $periodType, string $periodTarget, ?string $periodReference = null): array
    {
        if ($periodType === 'year') {
            $year = (int)$periodTarget;
            $targetStart = sprintf('%04d-01-01', $year);
            $targetEnd = sprintf('%04d-12-31', $year);

            $refYear = $periodReference ? (int)$periodReference : ($year - 1);
            $refStart = sprintf('%04d-01-01', $refYear);
            $refEnd = sprintf('%04d-12-31', $refYear);
            $refLabel = (string)$refYear;
        } else {
            // Standard: month (YYYY-MM)
            $targetDt = DateTimeImmutable::createFromFormat('!Y-m', $periodTarget) ?: new DateTimeImmutable('first day of this month');
            $targetStart = $targetDt->format('Y-m-01');
            $targetEnd = $targetDt->format('Y-m-t');

            if ($periodReference) {
                $refDt = DateTimeImmutable::createFromFormat('!Y-m', $periodReference) ?: $targetDt->modify('-1 month');
            } else {
                $refDt = $targetDt->modify('-1 month');
            }
            $refStart = $refDt->format('Y-m-01');
            $refEnd = $refDt->format('Y-m-t');
            $refLabel = $refDt->format('Y-m');
        }

        return [
            'target_start' => $targetStart,
            'target_end' => $targetEnd,
            'ref_start' => $refStart,
            'ref_end' => $refEnd,
            'ref_label' => $refLabel,
        ];
    }

    /**
     * Prüft, ob eine Transaktion als interne Umbuchung / Rücklagenbewegung zu werten ist.
     *
     * @param array $tx Transaktionsdaten
     * @param int[] $transferTagIds IDs der Umbuchungs-/Rücklagen-Tags
     * @param string|null $savingsIban Bereinigte IBAN des Sparkontos (ohne Leerzeichen)
     * @param int|null $savingsAccountId ID des Sparkontos
     * @return bool
     */
    public function isReserveTransaction(
        array $tx,
        array $transferTagIds,
        ?string $savingsIban = null,
        ?int $savingsAccountId = null
    ): bool {
        // 1. Tag-Matching: Transaktion besitzt ein Umbuchungs- oder Rücklagen-Tag
        if (!empty($tx['tag_ids'])) {
            $txTagIds = array_map('intval', explode(',', (string)$tx['tag_ids']));
            if (!empty(array_intersect($txTagIds, $transferTagIds))) {
                return true;
            }
        }

        // 2. Transaktion gehört direkt zum Sparkonto
        if ($savingsAccountId !== null && isset($tx['account_id']) && (int)$tx['account_id'] === $savingsAccountId) {
            return true;
        }

        // 3. IBAN-Abgleich mit dem Sparkonto (Gegenkonto im Verwendungszweck oder Partnerfeld)
        if ($savingsIban !== null && $savingsIban !== '') {
            $combined = strtoupper(str_replace(' ', '',
                ($tx['remittance_info'] ?? '') . ' ' .
                ($tx['remitter'] ?? '') . ' ' .
                ($tx['creditor'] ?? '') . ' ' .
                ($tx['debitor'] ?? '')
            ));
            if (str_contains($combined, $savingsIban)) {
                return true;
            }
        }

        // 4. Eindeutige Schlüsselwörter im Buchungstext (Übertrag/Umbuchung auf/vom Tagesgeld/Sparkonto)
        $text = mb_strtolower(
            ($tx['remittance_info'] ?? '') . ' ' .
            ($tx['remitter'] ?? '') . ' ' .
            ($tx['creditor'] ?? '') . ' ' .
            ($tx['debitor'] ?? '')
        );
        if (
            (str_contains($text, 'umbuchung') || str_contains($text, 'übertrag') || str_contains($text, 'uebertrag')) &&
            (str_contains($text, 'tagesgeld') || str_contains($text, 'sparkonto') || str_contains($text, 'sparbuch') || str_contains($text, 'rücklage') || str_contains($text, 'ruecklage'))
        ) {
            return true;
        }

        return false;
    }

    /**
     * Berechnet die disjunkten Gesamtsummen auf Buchungsebene.
     * Interne Umbuchungen auf das Sparkonto werden als Zuführung zur Rücklage gewertet
     * (und nicht als Konsumausgabe), Umbuchungen vom Sparkonto auf das Girokonto werden
     * als Abschmelzen der Rücklage gewertet (und nicht als reguläres Einkommen).
     */
    private function calculateCashflowTotals(string $startDate, string $endDate, string $periodType, ?string $periodTarget = null): array
    {
        $transferTagIds = $this->bankTagRepository->getTransferTagIds();
        $savingsAccount = $this->bankAccountRepository->getAccountByType('savings');
        $savingsAccountId = $savingsAccount ? (int)$savingsAccount['id'] : null;
        $savingsIban = !empty($savingsAccount['iban']) ? str_replace(' ', '', strtoupper($savingsAccount['iban'])) : null;
        $savingsBalance = (float)($savingsAccount['current_balance'] ?? 0.0);

        // Alle Transaktionen im Zeitraum laden
        $stmt = $this->pdo->prepare("
            SELECT
                bgt.id,
                bgt.account_id,
                bgt.booking_date,
                bgt.amount,
                bgt.contract_id,
                bgt.remittance_info,
                bgt.remitter,
                bgt.debitor,
                bgt.creditor,
                GROUP_CONCAT(btt.tag_id) AS tag_ids
            FROM bank_giro_transactions bgt
            LEFT JOIN bank_transaction_tags btt ON bgt.id = btt.transaction_id
            WHERE bgt.booking_date BETWEEN :start AND :end
            GROUP BY bgt.id
            ORDER BY bgt.booking_date ASC, bgt.id ASC
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $actualIncome = 0.0;
        $actualExpenses = 0.0;
        $fixedBooked = 0.0;

        $reserveInflow = 0.0;
        $reserveOutflow = 0.0;
        $reserveTransactions = [];

        // Transaktionen nach Girokonto und Sparkonto trennen, um Doppelzählung bei Gegenbuchungen zu vermeiden
        $giroRows = [];
        $savingsRows = [];
        foreach ($rows as $row) {
            if ($savingsAccountId !== null && isset($row['account_id']) && (int)$row['account_id'] === $savingsAccountId) {
                $savingsRows[] = $row;
            } else {
                $giroRows[] = $row;
            }
        }

        // 1. Girokonto-Umsätze verarbeiten
        foreach ($giroRows as $row) {
            $amount = (float)$row['amount'];
            $isReserve = $this->isReserveTransaction($row, $transferTagIds, $savingsIban, $savingsAccountId);

            if ($isReserve) {
                $absAmount = abs($amount);
                $partner = !empty($row['creditor']) ? $row['creditor'] : (!empty($row['debitor']) ? $row['debitor'] : ($row['remitter'] ?? ''));

                if ($amount < 0) {
                    // Abfluss vom Girokonto auf Sparkonto = Zuführung zur Rücklage
                    $reserveInflow += $absAmount;
                    $reserveTransactions[] = [
                        'id' => (int)$row['id'],
                        'booking_date' => $row['booking_date'],
                        'amount' => $absAmount,
                        'direction' => 'inflow',
                        'direction_label' => 'Zuführung zur Rücklage (auf Sparkonto)',
                        'partner' => $partner,
                        'remittance_info' => $row['remittance_info'] ?? '',
                    ];
                } else {
                    // Zufluss auf Girokonto vom Sparkonto = Abschmelzen der Rücklage
                    $reserveOutflow += $absAmount;
                    $reserveTransactions[] = [
                        'id' => (int)$row['id'],
                        'booking_date' => $row['booking_date'],
                        'amount' => $absAmount,
                        'direction' => 'outflow',
                        'direction_label' => 'Abschmelzen der Rücklage (vom Sparkonto)',
                        'partner' => $partner,
                        'remittance_info' => $row['remittance_info'] ?? '',
                    ];
                }
            } else {
                // Reguläre Einnahmen und Ausgaben
                if ($amount > 0) {
                    $actualIncome += $amount;
                } elseif ($amount < 0) {
                    $actualExpenses += abs($amount);
                    if (!empty($row['contract_id'])) {
                        $fixedBooked += abs($amount);
                    }
                }
            }
        }

        // 2. Eigenständige Sparkonto-Umsätze (z.B. Zinsgutschriften), die keine Gegenbuchung auf Giro haben
        foreach ($savingsRows as $sRow) {
            $sAmount = (float)$sRow['amount'];
            $sAbs = abs($sAmount);

            // Prüfen, ob bereits als Gegenbuchung auf Girokonto erfasst
            $alreadyCounted = false;
            foreach ($reserveTransactions as $rt) {
                if (abs($rt['amount'] - $sAbs) < 0.01 && abs(strtotime($rt['booking_date']) - strtotime($sRow['booking_date'])) <= 86400 * 3) {
                    $alreadyCounted = true;
                    break;
                }
            }

            if (!$alreadyCounted) {
                $partner = !empty($sRow['creditor']) ? $sRow['creditor'] : (!empty($sRow['debitor']) ? $sRow['debitor'] : ($sRow['remitter'] ?? ''));
                if ($sAmount > 0) {
                    $reserveInflow += $sAbs;
                    $reserveTransactions[] = [
                        'id' => (int)$sRow['id'],
                        'booking_date' => $sRow['booking_date'],
                        'amount' => $sAbs,
                        'direction' => 'inflow',
                        'direction_label' => 'Zuführung zur Rücklage (Sparkonto-Gutschrift)',
                        'partner' => $partner,
                        'remittance_info' => $sRow['remittance_info'] ?? '',
                    ];
                } else {
                    $reserveOutflow += $sAbs;
                    $reserveTransactions[] = [
                        'id' => (int)$sRow['id'],
                        'booking_date' => $sRow['booking_date'],
                        'amount' => $sAbs,
                        'direction' => 'outflow',
                        'direction_label' => 'Abschmelzen der Rücklage (Sparkonto-Belastung)',
                        'partner' => $partner,
                        'remittance_info' => $sRow['remittance_info'] ?? '',
                    ];
                }
            }
        }

        // Chronologische Sortierung der Rücklagen-Transaktionen
        usort($reserveTransactions, fn($a, $b) => strcmp($b['booking_date'], $a['booking_date']));

        $netReserveChange = round($reserveInflow - $reserveOutflow, 2);

        // Prüfen, ob es sich um den aktuell noch laufenden Monat handelt
        $isCurrentMonth = ($periodType === 'month' && $periodTarget === date('Y-m'));

        $pendingData = ['pending_income' => 0.0, 'pending_expenses' => 0.0, 'contracts' => []];
        if ($isCurrentMonth) {
            $pendingData = $this->calculatePendingContracts($startDate, $endDate);
        }

        $pendingIncome = $pendingData['pending_income'];
        $pendingExpenses = $pendingData['pending_expenses'];
        $isProjection = $isCurrentMonth;

        if ($isProjection) {
            // Prognosewerte zum Monatsende: Reale Buchungen + noch ausstehende Verträge
            $totalIncome = $actualIncome + $pendingIncome;
            $totalExpenses = $actualExpenses + $pendingExpenses;
            $fixedExpensesTotal = $fixedBooked + $pendingExpenses;
        } else {
            // Abgeschlossener Zeitraum
            $totalIncome = $actualIncome;
            $totalExpenses = $actualExpenses;
            $contractNominal = $this->calculateNominalFixedExpenses($periodType);
            $fixedExpensesTotal = $fixedBooked > 0 ? $fixedBooked : $contractNominal;
        }

        // Plausibilitäts-Begrenzung: Fixkosten dürfen nicht größer als Gesamtausgaben sein, falls Ausgaben vorliegen
        if ($totalExpenses > 0 && $fixedExpensesTotal > $totalExpenses) {
            $fixedExpensesTotal = min($fixedExpensesTotal, $totalExpenses);
        }

        $variableExpensesTotal = max(0.0, $totalExpenses - $fixedExpensesTotal);
        $netBalance = $totalIncome - $totalExpenses;

        $savingsRate = 0.0;
        if ($totalIncome > 0.01) {
            $savingsRate = round(($netBalance / $totalIncome) * 100, 1);
        } elseif ($totalExpenses > 0.01) {
            $savingsRate = -100.0;
        }

        $actualNetBalance = $actualIncome - $actualExpenses;
        $actualSavingsRate = 0.0;
        if ($actualIncome > 0.01) {
            $actualSavingsRate = round(($actualNetBalance / $actualIncome) * 100, 1);
        } elseif ($actualExpenses > 0.01) {
            $actualSavingsRate = -100.0;
        }

        $giroCashflowBalance = round($netBalance - $reserveInflow + $reserveOutflow, 2);

        return [
            'is_projection' => $isProjection,
            'total_income' => round($totalIncome, 2),
            'total_expenses' => round($totalExpenses, 2),
            'net_balance' => round($netBalance, 2),
            'net_balance_status' => $netBalance >= 0 ? 'surplus' : 'deficit',
            'net_balance_status_de' => $netBalance >= 0
                ? sprintf('Überschuss (+%.2f €): Einnahmen übersteigen Ausgaben (weniger ausgegeben als eingenommen)', $netBalance)
                : sprintf('Defizit (-%.2f €): Ausgaben übersteigen Einnahmen (mehr ausgegeben als eingenommen)', abs($netBalance)),
            'savings_rate_percent' => $savingsRate,
            'fixed_expenses_total' => round($fixedExpensesTotal, 2),
            'variable_expenses_total' => round($variableExpensesTotal, 2),
            // Reale Ist-Werte für Detailansicht & Tooltips
            'actual_income' => round($actualIncome, 2),
            'actual_expenses' => round($actualExpenses, 2),
            'actual_net_balance' => round($actualNetBalance, 2),
            'actual_savings_rate_percent' => $actualSavingsRate,
            'actual_fixed_booked' => round($fixedBooked, 2),
            'pending_income' => $pendingIncome,
            'pending_expenses' => $pendingExpenses,
            'pending_contracts' => $pendingData['contracts'],

            // Umbuchungen & Rücklagen-Kennzahlen
            'reserve_inflow' => round($reserveInflow, 2),
            'reserve_outflow' => round($reserveOutflow, 2),
            'net_reserve_change' => $netReserveChange,
            'reserve_transactions' => $reserveTransactions,
            'savings_account_balance' => round($savingsBalance, 2),
            'giro_cashflow_balance' => $giroCashflowBalance,
        ];
    }

    /**
     * Ermittelt alle noch ausstehenden aktiven Vertragsbuchungen für einen laufenden Monat.
     */
    private function calculatePendingContracts(string $startDate, string $endDate): array
    {
        $targetYear = (int)substr($startDate, 0, 4);
        $targetMonth = (int)substr($startDate, 5, 2);
        $todayStr = date('Y-m-d');

        $stmt = $this->pdo->query("
            SELECT id, name, betrag, frequenz, variabel, start_datum, end_datum, direction, faelligkeitstag
            FROM bank_contracts
            WHERE status = 'aktiv'
            ORDER BY faelligkeitstag ASC, name ASC
        ");
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtTx = $this->pdo->prepare("
            SELECT id, booking_date, amount
            FROM bank_giro_transactions
            WHERE contract_id = :contract_id
              AND booking_date BETWEEN :start AND :end
            LIMIT 1
        ");

        $pendingIncome = 0.0;
        $pendingExpenses = 0.0;
        $pendingList = [];

        foreach ($contracts as $contract) {
            $contractId = (int)$contract['id'];
            $amount = (float)$contract['betrag'];
            $direction = $contract['direction'] ?? 'expense';
            $dueDay = !empty($contract['faelligkeitstag']) ? (int)$contract['faelligkeitstag'] : null;

            // 1. Prüfen, ob der Vertrag in diesem Kalendermonat überhaupt fällig ist (Rhythmus & Gültigkeit)
            if (!BankContractRepository::isContractDueInMonth($contract, $targetYear, $targetMonth, $this->pdo)) {
                continue;
            }

            // 2. Prüfen, ob für diesen Vertrag in diesem Monat bereits eine Buchung vorhanden ist
            $stmtTx->execute([
                ':contract_id' => $contractId,
                ':start' => $startDate,
                ':end' => $endDate,
            ]);
            $existingTx = $stmtTx->fetch(PDO::FETCH_ASSOC);

            // Wenn bereits verbucht, ist die Zahlung nicht mehr ausstehend
            if ($existingTx !== false && !empty($existingTx)) {
                continue;
            }

            // 3. Fälligkeitsdatum berechnen
            $expectedDate = null;
            if ($dueDay !== null) {
                $expectedDate = BankContractRepository::calculateExpectedDate($targetYear, $targetMonth, $dueDay);
            }

            // 4. Nur noch anstehende Buchungen berücksichtigen (heute oder in der Zukunft fällig, bzw. ohne fixes Datum)
            // Liegt das Datum in der Vergangenheit, gilt sie als überfällig/fehlend und wird nicht als Prognose addiert
            $isPending = ($expectedDate === null || $expectedDate >= $todayStr);
            if (!$isPending) {
                continue;
            }

            if ($direction === 'income') {
                $pendingIncome += $amount;
            } else {
                $pendingExpenses += $amount;
            }

            $pendingList[] = [
                'contract_id' => $contractId,
                'name' => $contract['name'],
                'direction' => $direction,
                'amount' => $amount,
                'due_day' => $dueDay,
                'expected_date' => $expectedDate,
                'frequency' => $contract['frequenz'],
            ];
        }

        return [
            'pending_income' => round($pendingIncome, 2),
            'pending_expenses' => round($pendingExpenses, 2),
            'contracts' => $pendingList,
        ];
    }

    /**
     * Ermittelt die nominellen monatlichen bzw. jährlichen Fixkosten aus aktiven Verträgen.
     */
    private function calculateNominalFixedExpenses(string $periodType): float
    {
        $stmt = $this->pdo->query("
            SELECT betrag, frequenz 
            FROM bank_contracts 
            WHERE status = 'aktiv' AND direction = 'expense'
        ");
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $monthlyTotal = 0.0;
        foreach ($contracts as $c) {
            $amount = (float)$c['betrag'];
            $factor = match ($c['frequenz']) {
                'monatlich' => 1.0,
                'vierteljaehrlich' => 1.0 / 3.0,
                'halbjaehrlich' => 1.0 / 6.0,
                'jaehrlich' => 1.0 / 12.0,
                default => 0.0,
            };
            $monthlyTotal += $amount * $factor;
        }

        return $periodType === 'year' ? round($monthlyTotal * 12.0, 2) : round($monthlyTotal, 2);
    }

    /**
     * Berechnet die Tag-Verteilung inklusive Deltas zur Referenzperiode und Co-Tagging (overlap_tags).
     */
    private function calculateTagBreakdown(
        string $targetStart,
        string $targetEnd,
        string $refStart,
        string $refEnd
    ): array {
        $transferTagIds = $this->bankTagRepository->getTransferTagIds();
        $placeholders = !empty($transferTagIds) ? implode(',', array_fill(0, count($transferTagIds), '?')) : '';
        $excludeSql = $placeholders !== '' ? "AND t.id NOT IN ($placeholders)" : '';

        // Tag-Summen im Zielzeitraum (Ausgaben ohne interne Umbuchungen)
        $stmtTarget = $this->pdo->prepare("
            SELECT
                t.id AS tag_id,
                t.name AS tag_name,
                COALESCE(SUM(ABS(bgt.amount)), 0) AS target_sum,
                COUNT(bgt.id) AS tx_count
            FROM bank_tags t
            JOIN bank_transaction_tags btt ON t.id = btt.tag_id
            JOIN bank_giro_transactions bgt ON btt.transaction_id = bgt.id
            WHERE bgt.booking_date BETWEEN ? AND ?
              AND bgt.amount < 0
              $excludeSql
            GROUP BY t.id, t.name
            ORDER BY target_sum DESC
        ");
        $stmtTarget->execute(array_merge([$targetStart, $targetEnd], $transferTagIds));
        $targetRows = $stmtTarget->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Tag-Summen im Referenzzeitraum
        $stmtRef = $this->pdo->prepare("
            SELECT
                t.id AS tag_id,
                COALESCE(SUM(ABS(bgt.amount)), 0) AS ref_sum
            FROM bank_tags t
            JOIN bank_transaction_tags btt ON t.id = btt.tag_id
            JOIN bank_giro_transactions bgt ON btt.transaction_id = bgt.id
            WHERE bgt.booking_date BETWEEN ? AND ?
              AND bgt.amount < 0
              $excludeSql
            GROUP BY t.id
        ");
        $stmtRef->execute(array_merge([$refStart, $refEnd], $transferTagIds));
        $refMap = [];
        foreach ($stmtRef->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $refMap[(int)$r['tag_id']] = (float)$r['ref_sum'];
        }

        // Co-Tags Vorbereitung
        $stmtOverlap = $this->pdo->prepare("
            SELECT t2.name, COUNT(*) AS cnt
            FROM bank_transaction_tags btt1
            JOIN bank_giro_transactions bgt ON btt1.transaction_id = bgt.id
            JOIN bank_transaction_tags btt2 ON btt1.transaction_id = btt2.transaction_id AND btt1.tag_id != btt2.tag_id
            JOIN bank_tags t2 ON btt2.tag_id = t2.id
            WHERE btt1.tag_id = :tag_id
              AND bgt.booking_date BETWEEN :start AND :end
            GROUP BY t2.id, t2.name
            ORDER BY cnt DESC
            LIMIT 3
        ");

        $breakdown = [];
        foreach ($targetRows as $row) {
            $tagId = (int)$row['tag_id'];
            $targetSum = round((float)$row['target_sum'], 2);
            $refSum = round($refMap[$tagId] ?? 0.0, 2);
            $deltaAbs = round($targetSum - $refSum, 2);

            $deltaPct = null;
            if ($refSum > 0.01) {
                $deltaPct = round(($deltaAbs / $refSum) * 100, 1);
            }

            // Overlap Tags ermitteln
            $stmtOverlap->execute([
                ':tag_id' => $tagId,
                ':start' => $targetStart,
                ':end' => $targetEnd,
            ]);
            $overlaps = [];
            foreach ($stmtOverlap->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $overlaps[] = $o['name'] . ' (' . $o['cnt'] . 'x)';
            }

            $breakdown[] = [
                'tag_name' => $row['tag_name'],
                'target_sum' => $targetSum,
                'reference_sum' => $refSum,
                'delta_absolute' => $deltaAbs,
                'delta_percent' => $deltaPct,
                'overlap_tags' => $overlaps,
            ];
        }

        return $breakdown;
    }

    /**
     * Prüft aktive Verträge auf Abweichungen (Soll vs. Ist) und fehlende Zahlungen im Zielzeitraum.
     */
    private function calculateContractDeviations(string $targetStart, string $targetEnd, string $periodType): array
    {
        $stmt = $this->pdo->query("
            SELECT id, name, betrag, frequenz, variabel, start_datum, end_datum, direction, faelligkeitstag
            FROM bank_contracts
            WHERE status = 'aktiv'
            ORDER BY name ASC
        ");
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtTx = $this->pdo->prepare("
            SELECT id, booking_date, amount, remittance_info
            FROM bank_giro_transactions
            WHERE contract_id = :contract_id
              AND booking_date BETWEEN :start AND :end
        ");

        $deviations = [];

        foreach ($contracts as $contract) {
            $contractId = (int)$contract['id'];
            $expected = (float)$contract['betrag'];
            $frequency = $contract['frequenz'];
            $isVariable = (bool)$contract['variabel'];
            $dueDay = !empty($contract['faelligkeitstag']) ? (int)$contract['faelligkeitstag'] : null;

            $direction = $contract['direction'] ?? 'expense';
            $isIncome = ($direction === 'income');

            $targetYear = (int)substr($targetStart, 0, 4);
            $targetMonth = (int)substr($targetStart, 5, 2);

            // Rhythmus & Gültigkeit prüfen
            if ($periodType === 'month') {
                if (!BankContractRepository::isContractDueInMonth($contract, $targetYear, $targetMonth, $this->pdo)) {
                    continue;
                }
            } else {
                if (!empty($contract['start_datum']) && $contract['start_datum'] > $targetEnd) {
                    continue;
                }
                if (!empty($contract['end_datum']) && $contract['end_datum'] < $targetStart) {
                    continue;
                }
            }

            // Erwartetes Datum berechnen (inkl. automatischer Monatsende- & Schaltjahr-Korrektur)
            $expectedDate = null;
            if ($periodType === 'month' && $dueDay !== null) {
                $expectedDate = BankContractRepository::calculateExpectedDate($targetYear, $targetMonth, $dueDay);
            }

            $stmtTx->execute([
                ':contract_id' => $contractId,
                ':start' => $targetStart,
                ':end' => $targetEnd,
            ]);
            $transactions = $stmtTx->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $actualSum = 0.0;
            foreach ($transactions as $tx) {
                $actualSum += abs((float)$tx['amount']);
            }
            $actualSum = round($actualSum, 2);

            if (empty($transactions)) {
                // Bei fälligen Verträgen im Monatsbericht ist das Fehlen/Ausstehen einer Zahlung relevant
                if ($periodType === 'month') {
                    $isCurrentMonth = (substr($targetStart, 0, 7) === date('Y-m'));
                    $isPending = $isCurrentMonth && ($expectedDate === null || $expectedDate >= date('Y-m-d'));

                    if ($isIncome) {
                        $details = 'Kein Zahlungseingang im Auswertungszeitraum gefunden.';
                        if ($expectedDate !== null) {
                            if ($isPending) {
                                $details = sprintf(
                                    'Zahlungseingang für diesen Monat steht noch aus (erwartet zum %d. bzw. %s).',
                                    $dueDay,
                                    date('d.m.Y', strtotime($expectedDate))
                                );
                            } else {
                                $details = sprintf(
                                    'Kein Zahlungseingang gefunden (erwartet zum %d. bzw. %s).',
                                    $dueDay,
                                    date('d.m.Y', strtotime($expectedDate))
                                );
                            }
                        }
                    } else {
                        $details = 'Keine Buchung im Auswertungszeitraum gefunden.';
                        if ($expectedDate !== null) {
                            if ($isPending) {
                                $details = sprintf(
                                    'Zahlung für diesen Monat steht noch aus (erwartet zum %d. bzw. %s).',
                                    $dueDay,
                                    date('d.m.Y', strtotime($expectedDate))
                                );
                            } else {
                                $details = sprintf(
                                    'Keine Buchung gefunden (erwartet zum %d. bzw. %s).',
                                    $dueDay,
                                    date('d.m.Y', strtotime($expectedDate))
                                );
                            }
                        }
                    }

                    $deviations[] = [
                        'contract_name' => $contract['name'],
                        'direction' => $direction,
                        'direction_label' => $isIncome ? 'Einnahme' : 'Ausgabe',
                        'contract_type' => $contract['type'] ?? 'vertrag',
                        'expected_amount' => $expected,
                        'actual_amount' => 0.0,
                        'difference' => -$expected,
                        'due_day' => $dueDay,
                        'expected_date' => $expectedDate,
                        'is_pending' => $isPending,
                        'type' => $isPending ? 'pending_payment' : 'missing_payment',
                        'details' => $details,
                    ];
                }
            } else {
                // Zahlung vorhanden: Betragsabweichung prüfen (sofern nicht als variabel deklariert)
                if (!$isVariable && abs($actualSum - $expected) > 0.05) {
                    $diff = round($actualSum - $expected, 2);
                    if ($isIncome) {
                        if ($diff > 0) {
                            $details = sprintf(
                                'Höherer Zahlungseingang als erwartet (Soll: %.2f €, Erhalten: %.2f €, Diff: +%.2f €)',
                                $expected,
                                $actualSum,
                                $diff
                            );
                        } else {
                            $details = sprintf(
                                'Geringerer Zahlungseingang als erwartet (Soll: %.2f €, Erhalten: %.2f €, Diff: %.2f €)',
                                $expected,
                                $actualSum,
                                $diff
                            );
                        }
                    } else {
                        if ($diff > 0) {
                            $details = sprintf(
                                'Höhere Abbuchung als erwartet (Soll: %.2f €, Ist: %.2f €, Diff: +%.2f €)',
                                $expected,
                                $actualSum,
                                $diff
                            );
                        } else {
                            $details = sprintf(
                                'Geringere Abbuchung als erwartet (Soll: %.2f €, Ist: %.2f €, Diff: %.2f €)',
                                $expected,
                                $actualSum,
                                $diff
                            );
                        }
                    }
                    if ($expectedDate !== null) {
                        $details .= sprintf(' (Fälligkeit: %d. d. M.)', $dueDay);
                    }

                    $deviations[] = [
                        'contract_name' => $contract['name'],
                        'direction' => $direction,
                        'direction_label' => $isIncome ? 'Einnahme' : 'Ausgabe',
                        'contract_type' => $contract['type'] ?? 'vertrag',
                        'expected_amount' => $expected,
                        'actual_amount' => $actualSum,
                        'difference' => $diff,
                        'due_day' => $dueDay,
                        'expected_date' => $expectedDate,
                        'type' => 'amount_mismatch',
                        'details' => $details,
                    ];
                }
            }
        }

        return $deviations;
    }

    /**
     * Berechnet Artikel- und Kassenbon-Insights (Händler, Kleinbuchungen, Preissteigerungen, Basket-Splits).
     */
    private function calculateReceiptInsights(
        string $targetStart,
        string $targetEnd,
        string $refStart,
        string $refEnd
    ): array {
        // 1. Top Merchants (aus kb_receipts und bank_cc_transactions getrennt abgefragt, um Collation-Konflikte zu vermeiden)
        $stmtReceipts = $this->pdo->prepare("
            SELECT store AS merchant, SUM(total) AS total, COUNT(*) AS count
            FROM kb_receipts
            WHERE purchase_date BETWEEN :start AND :end
              AND store IS NOT NULL AND store != ''
            GROUP BY store
        ");
        $stmtReceipts->execute([':start' => $targetStart, ':end' => $targetEnd]);
        $receiptMerchants = $stmtReceipts->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Kreditkarten-Umsätze: Transaktionen ausschließen, die bereits mit einem Kassenbon verknüpft sind
        $stmtCC = $this->pdo->prepare("
            SELECT t.merchant_name AS merchant, SUM(ABS(t.amount)) AS total, COUNT(*) AS count
            FROM bank_cc_transactions t
            WHERE t.booking_date BETWEEN :start AND :end
              AND t.amount < 0
              AND t.merchant_name IS NOT NULL AND t.merchant_name != ''
              AND NOT EXISTS (
                  SELECT 1 FROM kb_receipts rec WHERE rec.bank_cc_transaction_id = t.id
              )
            GROUP BY t.merchant_name
        ");
        $stmtCC->execute([':start' => $targetStart, ':end' => $targetEnd]);
        $ccMerchants = $stmtCC->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $allMerchants = array_merge($receiptMerchants, $ccMerchants);
        $topMerchants = MerchantNormalizer::consolidateTopMerchants($allMerchants, 5);

        // 2. Micro Transactions (< 10 € Ausgaben im Giro- und Kreditkartenbereich)
        $stmtMicroGiro = $this->pdo->prepare("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(ABS(amount)), 0) AS sum_amount
            FROM bank_giro_transactions
            WHERE booking_date BETWEEN :start AND :end
              AND amount < 0 AND amount > -10.00
        ");
        $stmtMicroGiro->execute([':start' => $targetStart, ':end' => $targetEnd]);
        $microGiro = $stmtMicroGiro->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmtMicroCC = $this->pdo->prepare("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(ABS(amount)), 0) AS sum_amount
            FROM bank_cc_transactions
            WHERE booking_date BETWEEN :start AND :end
              AND amount < 0 AND amount > -10.00
        ");
        $stmtMicroCC->execute([':start' => $targetStart, ':end' => $targetEnd]);
        $microCC = $stmtMicroCC->fetch(PDO::FETCH_ASSOC) ?: [];

        $microCount = (int)($microGiro['cnt'] ?? 0) + (int)($microCC['cnt'] ?? 0);
        $microTotal = round((float)($microGiro['sum_amount'] ?? 0) + (float)($microCC['sum_amount'] ?? 0), 2);

        // 3. Top Price Increases (Messbare Preissteigerungen gleicher Artikel in kb_items)
        $stmtPrice = $this->pdo->prepare("
            SELECT
                t_item.name AS item_name,
                ROUND(r_item.avg_ref_price, 2) AS old_price,
                ROUND(t_item.avg_target_price, 2) AS new_price,
                ROUND(t_item.avg_target_price - r_item.avg_ref_price, 2) AS delta_price,
                ROUND(((t_item.avg_target_price - r_item.avg_ref_price) / r_item.avg_ref_price) * 100, 1) AS increase_percent
            FROM (
                SELECT i.name, AVG(i.unit_price) AS avg_target_price
                FROM kb_items i
                JOIN kb_receipts rec ON i.receipt_id = rec.id
                WHERE rec.purchase_date BETWEEN :target_start AND :target_end
                  AND i.unit_price > 0
                GROUP BY i.name
            ) AS t_item
            JOIN (
                SELECT i.name, AVG(i.unit_price) AS avg_ref_price
                FROM kb_items i
                JOIN kb_receipts rec ON i.receipt_id = rec.id
                WHERE rec.purchase_date BETWEEN :ref_start AND :ref_end
                  AND i.unit_price > 0
                GROUP BY i.name
            ) AS r_item ON LOWER(TRIM(t_item.name)) = LOWER(TRIM(r_item.name))
            WHERE t_item.avg_target_price > (r_item.avg_ref_price + 0.05)
            ORDER BY increase_percent DESC
            LIMIT 5
        ");
        $stmtPrice->execute([
            ':target_start' => $targetStart,
            ':target_end' => $targetEnd,
            ':ref_start' => $refStart,
            ':ref_end' => $refEnd,
        ]);
        $topPriceIncreases = $stmtPrice->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 4. Basket Splits (Kassenbons mit mehreren unterschiedlichen Warengruppen)
        $stmtSplits = $this->pdo->prepare("
            SELECT
                rec.id AS receipt_id,
                rec.store,
                rec.purchase_date,
                rec.total,
                GROUP_CONCAT(DISTINCT i.category ORDER BY i.category SEPARATOR ', ') AS categories
            FROM kb_receipts rec
            JOIN kb_items i ON rec.id = i.receipt_id
            WHERE rec.purchase_date BETWEEN :start AND :end
              AND i.category IS NOT NULL AND i.category != ''
            GROUP BY rec.id, rec.store, rec.purchase_date, rec.total
            HAVING COUNT(DISTINCT i.category) >= 2
            ORDER BY rec.purchase_date DESC
            LIMIT 5
        ");
        $stmtSplits->execute([':start' => $targetStart, ':end' => $targetEnd]);
        $basketSplits = [];
        foreach ($stmtSplits->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $categories = array_map('trim', explode(',', $b['categories']));
            $basketSplits[] = [
                'receipt_id' => (int)$b['receipt_id'],
                'store' => MerchantNormalizer::normalize($b['store']),
                'purchase_date' => $b['purchase_date'],
                'total' => (float)$b['total'],
                'categories' => $categories,
            ];
        }

        return [
            'top_merchants' => $topMerchants,
            'micro_transactions' => [
                'count' => $microCount,
                'total_amount' => $microTotal,
            ],
            'top_price_increases' => $topPriceIncreases,
            'basket_splits' => $basketSplits,
        ];
    }

    /**
     * Ermittelt die monatliche Einnahmen-, Ausgaben- und Saldenhistorie.
     * Für Monatsansicht: die letzten 6 Monate bis einschließlich Zielmonat.
     * Für Jahresansicht: alle 12 Monate des Zieljahres.
     *
     * @param string $periodType 'month' oder 'year'
     * @param string $periodTarget 'YYYY-MM' oder 'YYYY'
     * @return array Liste von Monatsdaten mit Einnahmen, Ausgaben, Saldo und Sparquote
     */
    public function calculateCashflowHistory(string $periodType, string $periodTarget): array
    {
        $germanMonths = [
            '01' => 'Jan', '02' => 'Feb', '03' => 'Mär', '04' => 'Apr',
            '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Aug',
            '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Dez'
        ];

        $monthKeys = [];

        if ($periodType === 'year') {
            $year = (int)$periodTarget;
            if ($year < 2000 || $year > 2100) {
                $year = (int)date('Y');
            }
            $startDate = sprintf('%04d-01-01', $year);
            $endDate = sprintf('%04d-12-31', $year);

            for ($m = 1; $m <= 12; $m++) {
                $mStr = sprintf('%02d', $m);
                $key = sprintf('%04d-%s', $year, $mStr);
                $monthKeys[$key] = ($germanMonths[$mStr] ?? $mStr) . ' ' . substr((string)$year, 2);
            }
        } else {
            // Monatsansicht: 6 Monate bis einschließlich Zielmonat
            $dt = DateTimeImmutable::createFromFormat('!Y-m', $periodTarget) ?: new DateTimeImmutable('first day of this month');
            $startDt = $dt->modify('-5 months');
            $startDate = $startDt->format('Y-m-01');
            $endDate = $dt->format('Y-m-t');

            $iter = $startDt;
            for ($i = 0; $i < 6; $i++) {
                $key = $iter->format('Y-m');
                $mNum = $iter->format('m');
                $monthKeys[$key] = ($germanMonths[$mNum] ?? $mNum) . ' ' . $iter->format('y');
                $iter = $iter->modify('+1 month');
            }
        }

        $transferTagIds = $this->bankTagRepository->getTransferTagIds();
        $savingsAccount = $this->bankAccountRepository->getAccountByType('savings');
        $savingsAccountId = $savingsAccount ? (int)$savingsAccount['id'] : null;
        $savingsIban = !empty($savingsAccount['iban']) ? str_replace(' ', '', strtoupper($savingsAccount['iban'])) : null;

        $stmt = $this->pdo->prepare("
            SELECT
                bgt.id,
                bgt.account_id,
                DATE_FORMAT(bgt.booking_date, '%Y-%m') AS month_key,
                bgt.amount,
                bgt.remittance_info,
                bgt.remitter,
                bgt.debitor,
                bgt.creditor,
                GROUP_CONCAT(btt.tag_id) AS tag_ids
            FROM bank_giro_transactions bgt
            LEFT JOIN bank_transaction_tags btt ON bgt.id = btt.transaction_id
            WHERE bgt.booking_date BETWEEN :start AND :end
            GROUP BY bgt.id
            ORDER BY bgt.booking_date ASC
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $dataByKey = [];
        foreach ($rows as $row) {
            $mKey = $row['month_key'];
            if (!isset($dataByKey[$mKey])) {
                $dataByKey[$mKey] = [
                    'income' => 0.0,
                    'expenses' => 0.0,
                    'reserve_inflow' => 0.0,
                    'reserve_outflow' => 0.0,
                ];
            }

            $amount = (float)$row['amount'];
            $isDirectSavings = ($savingsAccountId !== null && isset($row['account_id']) && (int)$row['account_id'] === $savingsAccountId);

            if ($isDirectSavings) {
                if ($amount > 0) {
                    $dataByKey[$mKey]['reserve_inflow'] += $amount;
                } else {
                    $dataByKey[$mKey]['reserve_outflow'] += abs($amount);
                }
                continue;
            }

            $isReserve = $this->isReserveTransaction($row, $transferTagIds, $savingsIban, $savingsAccountId);

            if ($isReserve) {
                if ($amount < 0) {
                    $dataByKey[$mKey]['reserve_inflow'] += abs($amount);
                } else {
                    $dataByKey[$mKey]['reserve_outflow'] += $amount;
                }
            } else {
                if ($amount > 0) {
                    $dataByKey[$mKey]['income'] += $amount;
                } elseif ($amount < 0) {
                    $dataByKey[$mKey]['expenses'] += abs($amount);
                }
            }
        }

        $history = [];
        foreach ($monthKeys as $key => $label) {
            $income = $dataByKey[$key]['income'] ?? 0.0;
            $expenses = $dataByKey[$key]['expenses'] ?? 0.0;
            $resInflow = $dataByKey[$key]['reserve_inflow'] ?? 0.0;
            $resOutflow = $dataByKey[$key]['reserve_outflow'] ?? 0.0;
            $net = $income - $expenses;

            if ($income > 0.01) {
                $savingsRate = round(($net / $income) * 100, 1);
            } elseif ($expenses > 0.01) {
                $savingsRate = -100.0;
            } else {
                $savingsRate = 0.0;
            }

            $history[] = [
                'month_key' => $key,
                'label' => $label,
                'total_income' => round($income, 2),
                'total_expenses' => round($expenses, 2),
                'net_balance' => round($net, 2),
                'reserve_inflow' => round($resInflow, 2),
                'reserve_outflow' => round($resOutflow, 2),
                'savings_rate_percent' => $savingsRate,
                'is_current' => ($periodType === 'month' && $key === $periodTarget)
            ];
        }

        return $history;
    }
}

