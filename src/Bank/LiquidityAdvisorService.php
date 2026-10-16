<?php

namespace Kai\Tools\Bank;

use DateTimeImmutable;
use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Intelligenter Liquiditäts- & Rücklagen-Assistent für Girokonto und Sparkonto.
 *
 * Unterstützt zwei Kernprozesse ohne unnötiges Hin- und Herbuchen:
 * 1. Monatlicher Rücklagen-Sweep (Trigger: Import der aktuellen Visa-Abrechnung ab dem 25.):
 *    Berechnet den echten Mehrbetrag über dem festen 4.000-€-Sicherheitspuffer
 *    (bereinigt um ein ggf. bereits gebuchtes Monatsend-Gehalt sowie offene Fixkosten).
 * 2. Not-Rückbuchungs-Wächter (laufend aktiv, 500-€-Untergrenze inkl. 5-Tage-Vorschau):
 *    Warnt rechtzeitig, falls der Giro-Saldo aktuell oder durch anstehende Abbuchungen
 *    in den nächsten 5 Tagen unter 500 € fällt, und empfiehlt einen Einmal-Rückholbetrag.
 */
class LiquidityAdvisorService
{
    public const float TARGET_BUFFER = 4000.00;
    public const float MIN_EMERGENCY_BALANCE = 500.00;
    public const int EMERGENCY_LOOKAHEAD_DAYS = 5;
    public const int VISA_REMINDER_DAY = 25;
    public const string SETTING_DONE_MONTH = 'bank_reserve_check_done_month';

    private PDO $pdo;
    private BankAccountRepository $accountRepo;
    private BankContractRepository $contractRepo;
    private BankTagRepository $tagRepo;

    public function __construct(
        ?BankAccountRepository $accountRepo = null,
        ?BankContractRepository $contractRepo = null,
        ?BankTagRepository $tagRepo = null
    ) {
        $this->pdo = Database::getInstance()->getConnection();
        $this->accountRepo = $accountRepo ?? new BankAccountRepository();
        $this->contractRepo = $contractRepo ?? new BankContractRepository();
        $this->tagRepo = $tagRepo ?? new BankTagRepository();
    }

    /**
     * Analysiert die aktuelle Liquiditätslage und liefert eine strukturierte Empfehlung.
     *
     * Mögliche Status-Werte (`status`):
     * - `'emergency'`:        Not-Rückbuchung vom Sparkonto erforderlich (< 500 € aktuell oder in 5 Tagen)
     * - `'reserve_ready'`:    Visa-Abrechnung liegt vor & Mehrbetrag > 4.000 € kann aufs Sparkonto
     * - `'reserve_no_excess'`:Visa-Abrechnung liegt vor, Saldo <= 4.000 € (keine Umbuchung nötig)
     * - `'visa_reminder'`:    Ab 25. des Monats: Visa-Abrechnung für Rücklagen-Check noch ausstehend
     * - `'reserve_done'`:     Rücklage für diesen Monat bereits gebucht oder als erledigt markiert
     * - `'optimal'`:          Regulärer Betrieb im Monatsverlauf (Liquidität stabil >= 500 €)
     */
    public function analyze(?int $checkingAccountId = null): array
    {
        $accounts = $this->accountRepo->getAllAccounts();
        $checkingAccount = null;
        $savingsAccount = null;

        foreach ($accounts as $acc) {
            if ($checkingAccountId !== null && (int)$acc['id'] === $checkingAccountId) {
                $checkingAccount = $acc;
            } elseif ($checkingAccount === null && ($acc['account_type'] ?? '') === 'checking') {
                $checkingAccount = $acc;
            }

            if (($acc['account_type'] ?? '') === 'savings') {
                $savingsAccount = $acc;
            }
        }

        $accountId = $checkingAccount ? (int)$checkingAccount['id'] : ($checkingAccountId ?? 1);
        $currentBalance = (float)($checkingAccount['current_balance'] ?? 0.0);
        $savingsBalance = (float)($savingsAccount['current_balance'] ?? 0.0);
        $savingsIban = $savingsAccount['iban'] ?? null;

        $today = new DateTimeImmutable('today');
        $currentYearMonth = $today->format('Y-m');
        $dayOfMonth = (int)$today->format('j');

        // 1. Monatsbezogene Daten & bisherige Umbuchungen laden
        $allReserveTransfersThisMonth = $this->getMonthlyReserveTransfers($accountId, $currentYearMonth, $savingsIban);
        $monthlyReserveTransferred = $allReserveTransfersThisMonth['inflow_total'];

        $markedDoneMonth = $this->getSetting(self::SETTING_DONE_MONTH);
        $isManuallyDone = ($markedDoneMonth === $currentYearMonth);

        // Aktuelle Visa-Abrechnung des laufenden Monats suchen
        $currentVisaStatement = $this->getVisaStatementForMonth($currentYearMonth);

        // 2. 5-Tage-Vorschau & Not-Rückbuchungs-Wächter (< 500 €)
        $forecast5d = $this->calculateShortTermForecast($accountId, $currentBalance, self::EMERGENCY_LOOKAHEAD_DAYS);
        $projectedBalance5d = $forecast5d['projected_balance'];

        if ($currentBalance < self::MIN_EMERGENCY_BALANCE || $projectedBalance5d < self::MIN_EMERGENCY_BALANCE) {
            return $this->buildEmergencyAdvice(
                $accountId,
                $currentBalance,
                $savingsBalance,
                $forecast5d,
                $monthlyReserveTransferred
            );
        }

        // 3. Prüfen, ob nach Eingang der Visa-Abrechnung bereits eine Rücklage getätigt wurde
        $hasPostVisaTransfer = false;
        $postVisaTransferAmount = 0.0;
        if ($currentVisaStatement !== null && !empty($currentVisaStatement['statement_date'])) {
            $postVisaTransfers = $this->getMonthlyReserveTransfers(
                $accountId,
                $currentYearMonth,
                $savingsIban,
                $currentVisaStatement['statement_date']
            );
            if ($postVisaTransfers['inflow_total'] > 0) {
                $hasPostVisaTransfer = true;
                $postVisaTransferAmount = $postVisaTransfers['inflow_total'];
            }
        }

        // Als erledigt gilt es NUR wenn:
        // a) der Nutzer den Monat manuell als erledigt markiert hat, ODER
        // b) die Visa-Abrechnung vorliegt UND danach eine Umbuchung aufs Sparkonto stattfand
        if ($isManuallyDone || $hasPostVisaTransfer) {
            $inflowFormatted = number_format($postVisaTransferAmount > 0 ? $postVisaTransferAmount : $monthlyReserveTransferred, 2, ',', '.') . ' €';
            $headline = ($postVisaTransferAmount > 0 || $monthlyReserveTransferred > 0)
                ? "Rücklage für diesen Monat erledigt (+{$inflowFormatted})"
                : 'Rücklagen-Check für diesen Monat abgeschlossen';

            $subtitle = $postVisaTransferAmount > 0
                ? "Umbuchung nach Visa-Abrechnung auf das Sparkonto wurde erfasst."
                : ($monthlyReserveTransferred > 0
                    ? "Rücklagen-Umbuchung auf das Sparkonto wurde erfasst."
                    : "Der monatliche Rücklagen-Check wurde für {$today->format('m/Y')} als erledigt markiert.");

            return [
                'status' => 'reserve_done',
                'is_actionable' => false,
                'can_dismiss' => false,
                'badge_text' => 'Erledigt',
                'badge_type' => 'success',
                'headline' => $headline,
                'subtitle' => $subtitle,
                'recommended_amount' => 0.0,
                'direction' => 'none',
                'current_balance' => $currentBalance,
                'savings_balance' => $savingsBalance,
                'target_buffer' => self::TARGET_BUFFER,
                'min_emergency_balance' => self::MIN_EMERGENCY_BALANCE,
                'projected_balance_5d' => $projectedBalance5d,
                'forecast_5d' => $forecast5d,
                'visa_statement' => $currentVisaStatement,
                'monthly_reserve_transferred' => $monthlyReserveTransferred,
                'details' => [],
            ];
        }

        // 4. Wenn aktuelle Visa-Abrechnung vorliegt -> Monatlichen Rücklagen-Check ausführen
        if ($currentVisaStatement !== null) {
            return $this->buildVisaTriggeredReserveAdvice(
                $accountId,
                $currentBalance,
                $savingsBalance,
                $projectedBalance5d,
                $forecast5d,
                $currentVisaStatement,
                $today,
                $monthlyReserveTransferred
            );
        }

        // 5. Ab dem 25. des Monats ohne Visa-Abrechnung -> Dezente Erinnerung
        if ($dayOfMonth >= self::VISA_REMINDER_DAY) {
            return [
                'status' => 'visa_reminder',
                'is_actionable' => true,
                'can_dismiss' => true,
                'badge_text' => 'Visa-Abrechnung offen',
                'badge_type' => 'warning',
                'headline' => 'Warten auf aktuelle Visa-Abrechnung',
                'subtitle' => 'Sobald die Visa-Abrechnung (ab ca. 25.) importiert ist, berechnet Kai deinen optimalen Rücklagen-Betrag über dem 4.000-€-Puffer.',
                'recommended_amount' => 0.0,
                'direction' => 'none',
                'current_balance' => $currentBalance,
                'savings_balance' => $savingsBalance,
                'target_buffer' => self::TARGET_BUFFER,
                'min_emergency_balance' => self::MIN_EMERGENCY_BALANCE,
                'projected_balance_5d' => $projectedBalance5d,
                'forecast_5d' => $forecast5d,
                'visa_statement' => null,
                'monthly_reserve_transferred' => $monthlyReserveTransferred,
                'details' => [
                    'Aktueller Giro-Saldo' => number_format($currentBalance, 2, ',', '.') . ' €',
                    '5-Tage-Prognose' => number_format($projectedBalance5d, 2, ',', '.') . ' €',
                ],
            ];
        }

        // 6. Standard-Status unter dem Monat (vor dem 25.)
        $bufferDiff = $currentBalance - self::TARGET_BUFFER;
        $projFormatted = number_format($projectedBalance5d, 2, ',', '.') . ' €';

        return [
            'status' => 'optimal',
            'is_actionable' => false,
            'can_dismiss' => false,
            'badge_text' => 'Optimal',
            'badge_type' => 'info',
            'headline' => 'Girokonto im grünen Bereich',
            'subtitle' => "Regulärer Kontobetrieb (5-Tage-Vorschau: {$projFormatted}). Der monatliche Rücklagen-Check startet mit Eingang der Visa-Abrechnung (ca. 25. " . $today->format('m.') . ").",
            'recommended_amount' => 0.0,
            'direction' => 'none',
            'current_balance' => $currentBalance,
            'savings_balance' => $savingsBalance,
            'target_buffer' => self::TARGET_BUFFER,
            'min_emergency_balance' => self::MIN_EMERGENCY_BALANCE,
            'projected_balance_5d' => $projectedBalance5d,
            'buffer_diff' => $bufferDiff,
            'forecast_5d' => $forecast5d,
            'visa_statement' => null,
            'monthly_reserve_transferred' => $monthlyReserveTransferred,
            'details' => [],
        ];
    }

    /**
     * Markiert den Rücklagen-Check für den angegebenen Monat (Standard: aktueller Monat) als erledigt.
     */
    public function markMonthDone(?string $yearMonth = null): void
    {
        $ym = $yearMonth !== null && preg_match('/^\d{4}-\d{2}$/', $yearMonth)
            ? $yearMonth
            : date('Y-m');

        $stmt = $this->pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, label)
            VALUES (:key, :val, :label)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([
            ':key' => self::SETTING_DONE_MONTH,
            ':val' => $ym,
            ':label' => 'Monat des zuletzt abgeschlossenen Bank-Rücklagen-Checks (YYYY-MM)',
        ]);
    }

    /**
     * Berechnet den 5-Tage-Liquiditätsausblick inkl. offener Verträge und anstehender Visa-Abbuchung.
     */
    private function calculateShortTermForecast(int $accountId, float $currentBalance, int $daysAhead): array
    {
        $upcomingContracts = $this->contractRepo->getUpcomingExpectedTransactions($daysAhead, $accountId);

        $pendingExpenses = 0.0;
        $pendingIncome = 0.0;
        $items = [];
        $hasVisaContractInWindow = false;

        foreach ($upcomingContracts as $tx) {
            if (!empty($tx['is_booked'])) {
                continue;
            }

            $amount = abs((float)($tx['betrag'] ?? 0.0));
            $direction = $tx['direction'] ?? 'expense';
            $name = (string)($tx['contract_name'] ?? 'Vertrag');

            if (stripos($name, 'visa') !== false || stripos($name, 'kreditkarte') !== false) {
                $hasVisaContractInWindow = true;
            }

            if ($direction === 'expense') {
                $pendingExpenses += $amount;
            } else {
                $pendingIncome += $amount;
            }

            $items[] = [
                'name' => $name,
                'expected_date' => $tx['expected_date'],
                'relative_label' => $tx['relative_label'] ?? '',
                'amount' => $amount,
                'direction' => $direction,
            ];
        }

        // Prüfen, ob eine noch nicht vom Girokonto abgebuchte Visa-Abrechnung in den nächsten X Tagen fällig wird
        $openVisa = $this->getUpcomingUnpaidVisaStatement($daysAhead);
        if ($openVisa !== null && !$hasVisaContractInWindow) {
            $visaAmt = abs((float)$openVisa['total_amount']);
            if ($visaAmt > 0) {
                $pendingExpenses += $visaAmt;
                $items[] = [
                    'name' => 'Visa-Abrechnung (' . date('d.m.', strtotime($openVisa['statement_date'])) . ')',
                    'expected_date' => $openVisa['effective_due_date'],
                    'relative_label' => 'Visa-Einzug',
                    'amount' => $visaAmt,
                    'direction' => 'expense',
                ];
            }
        }

        $projectedBalance = round($currentBalance + $pendingIncome - $pendingExpenses, 2);

        return [
            'days_ahead' => $daysAhead,
            'pending_expenses' => round($pendingExpenses, 2),
            'pending_income' => round($pendingIncome, 2),
            'projected_balance' => $projectedBalance,
            'items' => $items,
        ];
    }

    /**
     * Erstellt die Warnung & Empfehlung für eine Not-Rückbuchung vom Sparkonto (< 500 €).
     */
    private function buildEmergencyAdvice(
        int $accountId,
        float $currentBalance,
        float $savingsBalance,
        array $forecast5d,
        float $monthlyReserveTransferred = 0.0
    ): array {
        // Alle offenen Ausgaben & Einnahmen für die nächsten 14 Tage (bzw. bis zum nächsten Gehaltseingang) prüfen,
        // damit eine einzige Rückbuchung vom Sparkonto ausreicht.
        $upcoming14d = $this->contractRepo->getUpcomingExpectedTransactions(14, $accountId);
        $netOutflowUntilSalary = 0.0;

        foreach ($upcoming14d as $tx) {
            if (!empty($tx['is_booked'])) {
                continue;
            }
            $amt = abs((float)($tx['betrag'] ?? 0.0));
            $dir = $tx['direction'] ?? 'expense';
            $type = $tx['type'] ?? '';

            // Sobald das nächste Gehalt (Arbeitsvertrag) ansteht, endet der Überbrückungszeitraum
            if ($dir === 'income' && ($type === 'arbeitsvertrag' || $amt >= 1500.0)) {
                break;
            }

            if ($dir === 'expense') {
                $netOutflowUntilSalary += $amt;
            } else {
                $netOutflowUntilSalary -= $amt;
            }
        }

        // Auch offene Visa-Abrechnung in den nächsten 14 Tagen berücksichtigen
        $openVisa14d = $this->getUpcomingUnpaidVisaStatement(14);
        if ($openVisa14d !== null) {
            $netOutflowUntilSalary += abs((float)$openVisa14d['total_amount']);
        }

        $effectiveOutflow = max(
            $forecast5d['pending_expenses'] - $forecast5d['pending_income'],
            $netOutflowUntilSalary,
            0.0
        );

        $lowestBalance = min($currentBalance, $currentBalance - $effectiveOutflow);

        // Ziel: Nach allen anstehenden Abbuchungen wieder mindestens 500 € (bzw. auf 50 € aufgerundet) auf dem Girokonto haben
        $rawDeficit = (self::MIN_EMERGENCY_BALANCE + 100.0) - $lowestBalance;
        $recommendedWithdrawal = max(100.0, ceil($rawDeficit / 50.0) * 50.0);

        if ($savingsBalance > 0 && $recommendedWithdrawal > $savingsBalance) {
            $recommendedWithdrawal = $savingsBalance;
        }

        $projFormatted = number_format($forecast5d['projected_balance'], 2, ',', '.') . ' €';
        $recFormatted = number_format($recommendedWithdrawal, 2, ',', '.') . ' €';
        $curFormatted = number_format($currentBalance, 2, ',', '.') . ' €';

        $reasonText = $currentBalance < self::MIN_EMERGENCY_BALANCE
            ? "Der aktuelle Giro-Saldo ({$curFormatted}) liegt unter der 500-€-Untergrenze."
            : "Durch anstehende Abbuchungen sinkt der Giro-Saldo in den nächsten 5 Tagen voraussichtlich auf {$projFormatted} (unter 500 €).";

        return [
            'status' => 'emergency',
            'is_actionable' => true,
            'can_dismiss' => false,
            'badge_text' => '⚠️ Not-Rückbuchung',
            'badge_type' => 'danger',
            'headline' => "Not-Rückbuchung empfohlen: {$recFormatted} vom Sparkonto",
            'subtitle' => $reasonText . ' Einmalige Rücküberweisung sichert alle anstehenden Fixkosten bis zum nächsten Geldeingang ab.',
            'recommended_amount' => round($recommendedWithdrawal, 2),
            'direction' => 'from_savings',
            'current_balance' => $currentBalance,
            'savings_balance' => $savingsBalance,
            'target_buffer' => self::TARGET_BUFFER,
            'min_emergency_balance' => self::MIN_EMERGENCY_BALANCE,
            'projected_balance_5d' => $forecast5d['projected_balance'],
            'forecast_5d' => $forecast5d,
            'visa_statement' => $openVisa14d,
            'monthly_reserve_transferred' => $monthlyReserveTransferred,
            'details' => [
                'Aktueller Giro-Saldo' => $curFormatted,
                'Anstehende Ausgaben (5 Tage)' => '-' . number_format($forecast5d['pending_expenses'], 2, ',', '.') . ' €',
                'Prognose in 5 Tagen' => $projFormatted,
                'Empfohlene Rückholung' => '+' . $recFormatted,
            ],
        ];
    }

    /**
     * Berechnet die monatliche Rücklagen-Empfehlung, sobald die Visa-Abrechnung vorliegt.
     *
     * Berücksichtigt insbesondere:
     * - Ob das Monatsend-Gehalt bereits auf dem Girokonto eingegangen ist (Gehalts-Bereinigung),
     *   damit bei spätem Visa-Upload (z. B. am 29./30.) nicht versehentlich das neue Gehalt
     *   als „Überschuss" auf das Sparkonto überwiesen wird.
     * - Ob noch ungebuchte Fixkosten im restlichen Monat offen sind.
     * - Ob die Visa-Abrechnung (Einzug am ~5. des Folgemonats) höher als das Monatsend-Gehalt ausfällt.
     */
    private function buildVisaTriggeredReserveAdvice(
        int $accountId,
        float $currentBalance,
        float $savingsBalance,
        float $projectedBalance5d,
        array $forecast5d,
        array $visaStatement,
        DateTimeImmutable $today,
        float $monthlyReserveTransferred = 0.0
    ): array {
        $visaAmount = abs((float)($visaStatement['total_amount'] ?? 0.0));
        $isVisaAlreadyDebited = !empty($visaStatement['bank_transaction_id']);

        // 1. Gehalts-Check: Wurde das Monatsend-Gehalt in diesem Monat (ab dem 20.) bereits gebucht?
        $salaryInfo = $this->analyzeMonthlySalary($accountId, $today);
        $bookedSalary = $salaryInfo['booked_amount'];
        $expectedSalary = $salaryInfo['expected_amount'];

        // 2. Noch offene Fixkosten (Ausgaben) bis zum Monatsende ermitteln
        $daysLeftInMonth = max(0, (int)$today->format('t') - (int)$today->format('j'));
        $remainingMonthTx = $this->contractRepo->getUpcomingExpectedTransactions($daysLeftInMonth, $accountId);
        $openMonthEndExpenses = 0.0;
        $openMonthEndIncomeExclSalary = 0.0;

        foreach ($remainingMonthTx as $tx) {
            if (!empty($tx['is_booked'])) {
                continue;
            }
            $amt = abs((float)($tx['betrag'] ?? 0.0));
            $dir = $tx['direction'] ?? 'expense';
            $type = $tx['type'] ?? '';

            if ($dir === 'expense') {
                $openMonthEndExpenses += $amt;
            } elseif ($type !== 'arbeitsvertrag' && (int)($tx['faelligkeitstag'] ?? 1) < 25) {
                // Mitmonatliche Einnahmen (z. B. Kindergeld), falls noch nicht gebucht
                $openMonthEndIncomeExclSalary += $amt;
            }
        }

        // 3. Bereinigter Kontostand (vor neuem Monatsend-Gehalt)
        $adjustedBalance = $currentBalance - $bookedSalary - $openMonthEndExpenses + $openMonthEndIncomeExclSalary;

        // 4. Sicherheitscheck Visa-Abrechnung vs. Gehaltspuffer:
        //    Das Gehalt am Monatsende puffert die Visa-Abbuchung am 5. des Folgemonats.
        //    Falls die Visa-Abrechnung das Gehalt übersteigt (oder kein Gehaltsvertrag hinterlegt ist),
        //    muss die Differenz auf dem Girokonto verbleiben, damit der 4.000-€-Puffer nach dem 5. hält.
        $visaExtraReserveNeeded = 0.0;
        if (!$isVisaAlreadyDebited && $visaAmount > 0 && $expectedSalary > 0 && $visaAmount > $expectedSalary) {
            $visaExtraReserveNeeded = $visaAmount - $expectedSalary;
        }

        $effectiveBalanceForSweep = round($adjustedBalance - $visaExtraReserveNeeded, 2);
        $rawExcess = round($effectiveBalanceForSweep - self::TARGET_BUFFER, 2);
        $recommendedTransfer = max(0.0, $rawExcess);

        $visaFormatted = number_format($visaAmount, 2, ',', '.') . ' €';
        $curFormatted = number_format($currentBalance, 2, ',', '.') . ' €';
        $effFormatted = number_format($effectiveBalanceForSweep, 2, ',', '.') . ' €';

        $details = [
            'Aktueller Giro-Saldo' => $curFormatted,
        ];
        if ($bookedSalary > 0) {
            $details['Bereits gebuchtes Gehalt (Puffer Folgemonat)'] = '-' . number_format($bookedSalary, 2, ',', '.') . ' €';
        }
        if ($openMonthEndExpenses > 0) {
            $details['Offene Fixkosten bis Monatsende'] = '-' . number_format($openMonthEndExpenses, 2, ',', '.') . ' €';
        }
        if ($visaExtraReserveNeeded > 0) {
            $details['Zusatzpuffer für hohe Visa-Abrechnung'] = '-' . number_format($visaExtraReserveNeeded, 2, ',', '.') . ' €';
        }
        $details['Bereinigter Saldo für Rücklagen-Check'] = $effFormatted;
        $details['Fester Giro-Sicherheitspuffer'] = '4.000,00 €';
        $details['Aktuelle Visa-Abrechnung (Info)'] = $visaFormatted . ($isVisaAlreadyDebited ? ' (bereits abgebucht)' : ' (Einzug ~5.)');

        if ($recommendedTransfer > 0) {
            $recFormatted = number_format($recommendedTransfer, 2, ',', '.') . ' €';
            $salaryHint = $bookedSalary > 0
                ? ' (bereits eingegangenes Gehalt wurde herausgerechnet, damit es als Puffer auf dem Giro bleibt)'
                : '';

            return [
                'status' => 'reserve_ready',
                'is_actionable' => true,
                'can_dismiss' => true,
                'badge_text' => '💡 Rücklage möglich',
                'badge_type' => 'success',
                'headline' => "Rücklagen-Empfehlung: {$recFormatted} auf das Sparkonto",
                'subtitle' => "Visa-Abrechnung ({$visaFormatted}) liegt vor. Dein bereinigter Giro-Saldo liegt bei {$effFormatted}{$salaryHint} — der Mehrbetrag über 4.000,00 € kann auf das Sparkonto.",
                'recommended_amount' => $recommendedTransfer,
                'direction' => 'to_savings',
                'current_balance' => $currentBalance,
                'adjusted_balance' => $effectiveBalanceForSweep,
                'savings_balance' => $savingsBalance,
                'target_buffer' => self::TARGET_BUFFER,
                'min_emergency_balance' => self::MIN_EMERGENCY_BALANCE,
                'projected_balance_5d' => $projectedBalance5d,
                'forecast_5d' => $forecast5d,
                'visa_statement' => $visaStatement,
                'monthly_reserve_transferred' => $monthlyReserveTransferred,
                'details' => $details,
            ];
        }

        return [
            'status' => 'reserve_no_excess',
            'is_actionable' => false,
            'can_dismiss' => true,
            'badge_text' => '✓ Keine Umbuchung nötig',
            'badge_type' => 'info',
            'headline' => 'Rücklagen-Check: Alles bleibt wie es ist',
            'subtitle' => "Visa-Abrechnung ({$visaFormatted}) ist da. Der bereinigte Giro-Saldo ({$effFormatted}) liegt nicht über dem 4.000-€-Puffer — diesen Monat keine Umbuchung erforderlich.",
            'recommended_amount' => 0.0,
            'direction' => 'none',
            'current_balance' => $currentBalance,
            'adjusted_balance' => $effectiveBalanceForSweep,
            'savings_balance' => $savingsBalance,
            'target_buffer' => self::TARGET_BUFFER,
            'min_emergency_balance' => self::MIN_EMERGENCY_BALANCE,
            'projected_balance_5d' => $projectedBalance5d,
            'forecast_5d' => $forecast5d,
            'visa_statement' => $visaStatement,
            'monthly_reserve_transferred' => $monthlyReserveTransferred,
            'details' => $details,
        ];
    }

    /**
     * Prüft, ob das Monatsend-Gehalt im aktuellen Monat (ab dem 20.) bereits gebucht wurde,
     * und ermittelt die erwartete Gehaltshöhe.
     */
    private function analyzeMonthlySalary(int $accountId, DateTimeImmutable $today): array
    {
        $stmt = $this->pdo->query("
            SELECT id, name, betrag, faelligkeitstag
            FROM bank_contracts
            WHERE status = 'aktiv'
              AND direction = 'income'
              AND (type = 'arbeitsvertrag' OR faelligkeitstag >= 24)
        ");
        $salaryContracts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $expectedSalary = 0.0;
        $contractIds = [];
        foreach ($salaryContracts as $sc) {
            $expectedSalary += abs((float)($sc['betrag'] ?? 0.0));
            $contractIds[] = (int)$sc['id'];
        }

        $bookedSalary = 0.0;
        $monthStart20 = $today->format('Y-m-20');
        $monthEnd = $today->format('Y-m-t');

        if (!empty($contractIds)) {
            $placeholders = implode(',', array_fill(0, count($contractIds), '?'));
            $sql = "
                SELECT COALESCE(SUM(amount), 0)
                FROM bank_giro_transactions
                WHERE account_id = ?
                  AND amount > 0
                  AND booking_date BETWEEN ? AND ?
                  AND contract_id IN ({$placeholders})
            ";
            $stmtTx = $this->pdo->prepare($sql);
            $stmtTx->execute(array_merge([$accountId, $monthStart20, $monthEnd], $contractIds));
            $bookedSalary = (float)$stmtTx->fetchColumn();
        }

        // Fallback: Falls noch keine Vertragsverknüpfung gegriffen hat, nach Gehaltsbuchung ab dem 23. suchen
        if ($bookedSalary <= 0.0) {
            $monthStart23 = $today->format('Y-m-23');
            $stmtFallback = $this->pdo->prepare("
                SELECT COALESCE(SUM(amount), 0)
                FROM bank_giro_transactions
                WHERE account_id = :acc_id
                  AND amount >= 1500
                  AND booking_date BETWEEN :d_start AND :d_end
                  AND (
                      LOWER(COALESCE(remittance_info, '')) LIKE '%gehalt%'
                      OR LOWER(COALESCE(remittance_info, '')) LIKE '%lohn%'
                      OR LOWER(COALESCE(remittance_info, '')) LIKE '%bezuege%'
                      OR LOWER(COALESCE(remittance_info, '')) LIKE '%bezüge%'
                  )
            ");
            $stmtFallback->execute([
                ':acc_id' => $accountId,
                ':d_start' => $monthStart23,
                ':d_end' => $monthEnd,
            ]);
            $bookedSalary = (float)$stmtFallback->fetchColumn();
        }

        if ($expectedSalary <= 0.0 && $bookedSalary > 0.0) {
            $expectedSalary = $bookedSalary;
        }

        return [
            'expected_amount' => round($expectedSalary, 2),
            'booked_amount' => round($bookedSalary, 2),
        ];
    }

    /**
     * Lädt die Visa-Abrechnung des angegebenen Monats (`YYYY-MM`), sofern bereits importiert.
     */
    private function getVisaStatementForMonth(string $yearMonth): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, account_id, statement_date, due_date, total_amount, bank_transaction_id
            FROM bank_cc_statements
            WHERE DATE_FORMAT(statement_date, '%Y-%m') = :ym
            ORDER BY statement_date DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([':ym' => $yearMonth]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Sucht nach einer noch nicht vom Girokonto abgebuchten Visa-Abrechnung (`bank_transaction_id IS NULL`),
     * deren Fälligkeitsdatum (oder der 5. des Folgemonats nach Abrechnungsdatum) in den nächsten X Tagen liegt.
     */
    private function getUpcomingUnpaidVisaStatement(int $daysAhead): ?array
    {
        $today = new DateTimeImmutable('today');
        $todayStr = $today->format('Y-m-d');
        $endWindowStr = $today->modify("+{$daysAhead} days")->format('Y-m-d');
        $minStatementDate = $today->modify('-45 days')->format('Y-m-d');

        $stmt = $this->pdo->prepare("
            SELECT id, account_id, statement_date, due_date, total_amount, bank_transaction_id
            FROM bank_cc_statements
            WHERE bank_transaction_id IS NULL
              AND statement_date >= :min_stmt_date
            ORDER BY statement_date DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([':min_stmt_date' => $minStatementDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Effektives Einzugsdatum bestimmen: due_date oder 5. des Folgemonats
        $effectiveDueDate = $row['due_date'] ?? null;
        if (empty($effectiveDueDate)) {
            $stmtDt = new DateTimeImmutable($row['statement_date']);
            $effectiveDueDate = $stmtDt->modify('first day of next month')->format('Y-m-05');
        }

        if ($effectiveDueDate >= $todayStr && $effectiveDueDate <= $endWindowStr) {
            $row['effective_due_date'] = $effectiveDueDate;
            return $row;
        }

        return null;
    }

    /**
     * Ermittelt alle bereits gebuchten Rücklagen-Umbuchungen im angegebenen Monat (`YYYY-MM`),
     * optional erst ab einem bestimmten Buchungsdatum (`YYYY-MM-DD`).
     */
    private function getMonthlyReserveTransfers(int $accountId, string $yearMonth, ?string $savingsIban, ?string $minDate = null): array
    {
        $startDate = $minDate ?: ($yearMonth . '-01');
        $endDate = date('Y-m-t', strtotime($yearMonth . '-01'));
        $transferTagIds = $this->tagRepo->getTransferTagIds();

        $stmt = $this->pdo->prepare("
            SELECT t.id, t.amount, t.remittance_info, t.remitter, t.debitor, t.creditor,
                   GROUP_CONCAT(tt.tag_id) AS tag_ids
            FROM bank_giro_transactions t
            LEFT JOIN bank_transaction_tags tt ON t.id = tt.transaction_id
            WHERE t.account_id = :acc_id
              AND t.booking_date BETWEEN :start_date AND :end_date
            GROUP BY t.id
        ");
        $stmt->execute([
            ':acc_id' => $accountId,
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $inflowTotal = 0.0;
        $outflowTotal = 0.0;
        $normalizedSavingsIban = $savingsIban ? strtoupper(str_replace(' ', '', $savingsIban)) : null;

        foreach ($rows as $row) {
            $isReserve = false;

            if (!empty($row['tag_ids'])) {
                $txTagIds = array_map('intval', explode(',', (string)$row['tag_ids']));
                if (!empty(array_intersect($txTagIds, $transferTagIds))) {
                    $isReserve = true;
                }
            }

            if (!$isReserve && $normalizedSavingsIban !== null && $normalizedSavingsIban !== '') {
                $combined = strtoupper(str_replace(' ', '',
                    ($row['remittance_info'] ?? '') . ' ' .
                    ($row['remitter'] ?? '') . ' ' .
                    ($row['creditor'] ?? '') . ' ' .
                    ($row['debitor'] ?? '')
                ));
                if (str_contains($combined, $normalizedSavingsIban)) {
                    $isReserve = true;
                }
            }

            if (!$isReserve) {
                $info = mb_strtolower(
                    ($row['remittance_info'] ?? '') . ' ' .
                    ($row['remitter'] ?? '') . ' ' .
                    ($row['creditor'] ?? '') . ' ' .
                    ($row['debitor'] ?? ''),
                    'UTF-8'
                );
                if (
                    (str_contains($info, 'umbuchung') || str_contains($info, 'übertrag') || str_contains($info, 'uebertrag'))
                    && (str_contains($info, 'rücklage') || str_contains($info, 'ruecklage') || str_contains($info, 'tagesgeld') || str_contains($info, 'sparkonto'))
                ) {
                    $isReserve = true;
                }
            }

            if ($isReserve) {
                $amt = (float)$row['amount'];
                if ($amt < 0) {
                    $inflowTotal += abs($amt);
                } elseif ($amt > 0) {
                    $outflowTotal += $amt;
                }
            }
        }

        return [
            'inflow_total' => round($inflowTotal, 2),
            'outflow_total' => round($outflowTotal, 2),
        ];
    }

    /**
     * Liest einen Wert aus `system_settings` ohne Domain-Kopplung zu `Kai\Tools\System`.
     */
    private function getSetting(string $key): ?string
    {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1");
            $stmt->execute([':key' => $key]);
            $val = $stmt->fetchColumn();
            return $val !== false && $val !== null ? (string)$val : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
