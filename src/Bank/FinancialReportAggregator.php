<?php

namespace Kai\Tools\Bank;

use DateTimeImmutable;
use Kai\Tools\Shared\Db\Database;
use PDO;

class FinancialReportAggregator
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
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
            $periodType
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
     * Berechnet die disjunkten Gesamtsummen auf Buchungsebene (jede Buchung exakt 1x gewertet).
     */
    private function calculateCashflowTotals(string $startDate, string $endDate, string $periodType): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END), 0) AS total_expenses,
                COALESCE(SUM(CASE WHEN amount < 0 AND contract_id IS NOT NULL THEN ABS(amount) ELSE 0 END), 0) AS fixed_booked
            FROM bank_giro_transactions
            WHERE booking_date BETWEEN :start AND :end
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalIncome = (float)($row['total_income'] ?? 0.0);
        $totalExpenses = (float)($row['total_expenses'] ?? 0.0);
        $fixedBooked = (float)($row['fixed_booked'] ?? 0.0);

        // Fixkosten ermitteln: Wenn Buchungen mit Verträgen verknüpft sind, diese nutzen.
        // Falls noch keine oder wenige Buchungen verknüpft sind, Soll-Summe aktiver Verträge als Basis heranziehen.
        $contractNominal = $this->calculateNominalFixedExpenses($periodType);
        $fixedExpensesTotal = $fixedBooked > 0 ? $fixedBooked : $contractNominal;

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

        return [
            'total_income' => round($totalIncome, 2),
            'total_expenses' => round($totalExpenses, 2),
            'net_balance' => round($netBalance, 2),
            'savings_rate_percent' => $savingsRate,
            'fixed_expenses_total' => round($fixedExpensesTotal, 2),
            'variable_expenses_total' => round($variableExpensesTotal, 2),
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
        // Tag-Summen im Zielzeitraum (Ausgaben)
        $stmtTarget = $this->pdo->prepare("
            SELECT
                t.id AS tag_id,
                t.name AS tag_name,
                COALESCE(SUM(ABS(bgt.amount)), 0) AS target_sum,
                COUNT(bgt.id) AS tx_count
            FROM bank_tags t
            JOIN bank_transaction_tags btt ON t.id = btt.tag_id
            JOIN bank_giro_transactions bgt ON btt.transaction_id = bgt.id
            WHERE bgt.booking_date BETWEEN :start AND :end
              AND bgt.amount < 0
            GROUP BY t.id, t.name
            ORDER BY target_sum DESC
        ");
        $stmtTarget->execute([':start' => $targetStart, ':end' => $targetEnd]);
        $targetRows = $stmtTarget->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Tag-Summen im Referenzzeitraum
        $stmtRef = $this->pdo->prepare("
            SELECT
                t.id AS tag_id,
                COALESCE(SUM(ABS(bgt.amount)), 0) AS ref_sum
            FROM bank_tags t
            JOIN bank_transaction_tags btt ON t.id = btt.tag_id
            JOIN bank_giro_transactions bgt ON btt.transaction_id = bgt.id
            WHERE bgt.booking_date BETWEEN :start AND :end
              AND bgt.amount < 0
            GROUP BY t.id
        ");
        $stmtRef->execute([':start' => $refStart, ':end' => $refEnd]);
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

            // Prüfen, ob Vertrag im Zeitraum bereits lief
            if (!empty($contract['start_datum']) && $contract['start_datum'] > $targetEnd) {
                continue;
            }
            if (!empty($contract['end_datum']) && $contract['end_datum'] < $targetStart) {
                continue;
            }

            // Erwartetes Datum berechnen (inkl. automatischer Monatsende- & Schaltjahr-Korrektur)
            $expectedDate = null;
            if ($periodType === 'month' && $dueDay !== null) {
                $targetYear = (int)substr($targetStart, 0, 4);
                $targetMonth = (int)substr($targetStart, 5, 2);
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
                // Bei monatlichen Verträgen im Monatsbericht ist das Fehlen einer Zahlung eine relevante Abweichung
                if ($periodType === 'month' && $frequency === 'monatlich') {
                    $details = 'Keine Buchung im Auswertungszeitraum gefunden.';
                    if ($expectedDate !== null) {
                        $details = sprintf(
                            'Keine Buchung gefunden (erwartet zum %d. bzw. %s).',
                            $dueDay,
                            date('d.m.Y', strtotime($expectedDate))
                        );
                    }

                    $deviations[] = [
                        'contract_name' => $contract['name'],
                        'expected_amount' => $expected,
                        'actual_amount' => 0.0,
                        'difference' => -$expected,
                        'due_day' => $dueDay,
                        'expected_date' => $expectedDate,
                        'type' => 'missing_payment',
                        'details' => $details,
                    ];
                }
            } else {
                // Zahlung vorhanden: Betragsabweichung prüfen (sofern nicht als variabel deklariert)
                if (!$isVariable && abs($actualSum - $expected) > 0.05) {
                    $diff = round($actualSum - $expected, 2);
                    $details = sprintf(
                        'Abweichung vom Soll-Betrag (Soll: %.2f €, Ist: %.2f €, Diff: %+.2f €)',
                        $expected,
                        $actualSum,
                        $diff
                    );
                    if ($expectedDate !== null) {
                        $details .= sprintf(' (Fälligkeit: %d. d. M.)', $dueDay);
                    }

                    $deviations[] = [
                        'contract_name' => $contract['name'],
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

        $stmtCC = $this->pdo->prepare("
            SELECT merchant_name AS merchant, SUM(ABS(amount)) AS total, COUNT(*) AS count
            FROM bank_cc_transactions
            WHERE booking_date BETWEEN :start AND :end
              AND amount < 0
              AND merchant_name IS NOT NULL AND merchant_name != ''
            GROUP BY merchant_name
        ");
        $stmtCC->execute([':start' => $targetStart, ':end' => $targetEnd]);
        $ccMerchants = $stmtCC->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $merchantMap = [];
        foreach ($receiptMerchants as $m) {
            $name = trim((string)$m['merchant']);
            if ($name === '') {
                continue;
            }
            $merchantMap[$name] = [
                'merchant' => $name,
                'total' => (float)$m['total'],
                'count' => (int)$m['count'],
            ];
        }
        foreach ($ccMerchants as $m) {
            $name = trim((string)$m['merchant']);
            if ($name === '') {
                continue;
            }
            if (!isset($merchantMap[$name])) {
                $merchantMap[$name] = [
                    'merchant' => $name,
                    'total' => 0.0,
                    'count' => 0,
                ];
            }
            $merchantMap[$name]['total'] += (float)$m['total'];
            $merchantMap[$name]['count'] += (int)$m['count'];
        }

        uasort($merchantMap, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
        $topMerchants = array_slice(array_values($merchantMap), 0, 5);
        foreach ($topMerchants as &$tm) {
            $tm['total'] = round($tm['total'], 2);
        }
        unset($tm);

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
                'store' => $b['store'],
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

        $stmt = $this->pdo->prepare("
            SELECT
                DATE_FORMAT(booking_date, '%Y-%m') AS month_key,
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END), 0) AS total_expenses
            FROM bank_giro_transactions
            WHERE booking_date BETWEEN :start AND :end
            GROUP BY DATE_FORMAT(booking_date, '%Y-%m')
            ORDER BY month_key ASC
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $dataByKey = [];
        foreach ($rows as $row) {
            $dataByKey[$row['month_key']] = [
                'income' => (float)$row['total_income'],
                'expenses' => (float)$row['total_expenses'],
            ];
        }

        $history = [];
        foreach ($monthKeys as $key => $label) {
            $income = $dataByKey[$key]['income'] ?? 0.0;
            $expenses = $dataByKey[$key]['expenses'] ?? 0.0;
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
                'savings_rate_percent' => $savingsRate,
                'is_current' => ($periodType === 'month' && $key === $periodTarget)
            ];
        }

        return $history;
    }
}

