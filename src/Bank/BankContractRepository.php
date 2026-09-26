<?php

namespace Kai\Tools\Bank;

use DateTime;
use Kai\Tools\Shared\Db\Database;
use PDO;

class BankContractRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Gibt alle Verträge zurück, optional gefiltert nach Status.
     */
    public function getAllContracts(?string $status = null): array
    {
        $sql = "
            SELECT c.*, cat.name AS category_name 
            FROM bank_contracts c
            LEFT JOIN bank_categories cat ON c.category_id = cat.id
        ";

        if ($status !== null) {
            $sql .= " WHERE c.status = :status";
        }

        $sql .= " ORDER BY c.name ASC";

        $stmt = $this->pdo->prepare($sql);
        if ($status !== null) {
            $stmt->execute([':status' => $status]);
        } else {
            $stmt->execute();
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lädt einen einzelnen Vertrag inklusive seiner Regeln.
     */
    public function getContractById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT c.*, cat.name AS category_name 
            FROM bank_contracts c
            LEFT JOIN bank_categories cat ON c.category_id = cat.id
            WHERE c.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contract) {
            return null;
        }

        // Regeln für diesen Vertrag laden
        $contract['rules'] = $this->getRulesForContract($id);

        return $contract;
    }

    /**
     * Lädt alle Matching-Regeln für einen spezifischen Vertrag.
     */
    public function getRulesForContract(int $contractId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, contract_id, pattern_type, pattern_value, priority
            FROM bank_contract_rules
            WHERE contract_id = :contract_id
            ORDER BY priority DESC, id ASC
        ");
        $stmt->execute([':contract_id' => $contractId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Speichert oder aktualisiert einen Vertrag.
     */
    public function saveContract(array $data, ?int $id = null): int
    {
        // Wenn ein Startdatum und eine Laufzeit (in Monaten) übergeben wurden, Enddatum berechnen falls leer
        if (!empty($data['start_datum']) && empty($data['end_datum']) && !empty($data['laufzeit_monate'])) {
            $startDate = new DateTime($data['start_datum']);
            $startDate->modify('+' . (int)$data['laufzeit_monate'] . ' months');
            $data['end_datum'] = $startDate->format('Y-m-d');
        }

        if ($id !== null && $id > 0) {
            // Update
            $stmt = $this->pdo->prepare("
                UPDATE bank_contracts SET
                    name = :name,
                    direction = :direction,
                    type = :type,
                    status = :status,
                    auftraggeber = :auftraggeber,
                    mandatsnummer = :mandatsnummer,
                    iban = :iban,
                    betrag = :betrag,
                    frequenz = :frequenz,
                    faelligkeitstag = :faelligkeitstag,
                    variabel = :variabel,
                    start_datum = :start_datum,
                    end_datum = :end_datum,
                    category_id = :category_id,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $data['id'] = $id;
            $stmt->execute($this->mapContractParams($data));
            return $id;
        } else {
            // Insert
            $stmt = $this->pdo->prepare("
                INSERT INTO bank_contracts 
                (name, direction, type, status, auftraggeber, mandatsnummer, iban, betrag, frequenz, faelligkeitstag, variabel, start_datum, end_datum, category_id)
                VALUES 
                (:name, :direction, :type, :status, :auftraggeber, :mandatsnummer, :iban, :betrag, :frequenz, :faelligkeitstag, :variabel, :start_datum, :end_datum, :category_id)
            ");
            $stmt->execute($this->mapContractParams($data));
            return (int)$this->pdo->lastInsertId();
        }
    }

    private function mapContractParams(array $data): array
    {
        $faelligkeitstag = null;
        $dueDayRaw = $data['faelligkeitstag'] ?? ($data['due_day'] ?? null);
        if ($dueDayRaw !== null && $dueDayRaw !== '') {
            $dueDayInt = (int)$dueDayRaw;
            if ($dueDayInt >= 1 && $dueDayInt <= 31) {
                $faelligkeitstag = $dueDayInt;
            }
        }

        $params = [
            ':name' => $data['name'] ?? '',
            ':direction' => $data['direction'] ?? 'expense',
            ':type' => $data['type'] ?? 'vertrag',
            ':status' => $data['status'] ?? 'aktiv',
            ':auftraggeber' => $data['auftraggeber'] ?? null,
            ':mandatsnummer' => $data['mandatsnummer'] ?? null,
            ':iban' => $data['iban'] ?? null,
            ':betrag' => (float)($data['betrag'] ?? 0.0),
            ':frequenz' => $data['frequenz'] ?? 'monatlich',
            ':faelligkeitstag' => $faelligkeitstag,
            ':variabel' => isset($data['variabel']) ? (int)$data['variabel'] : 0,
            ':start_datum' => !empty($data['start_datum']) ? $data['start_datum'] : null,
            ':end_datum' => !empty($data['end_datum']) ? $data['end_datum'] : null,
            ':category_id' => !empty($data['category_id']) ? (int)$data['category_id'] : null,
        ];

        if (isset($data['id'])) {
            $params[':id'] = (int)$data['id'];
        }

        return $params;
    }

    /**
     * Fügt eine neue Matching-Regel zu einem Vertrag hinzu.
     */
    public function addRule(int $contractId, string $patternType, string $patternValue, int $priority = 10): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO bank_contract_rules (contract_id, pattern_type, pattern_value, priority)
            VALUES (:contract_id, :pattern_type, :pattern_value, :priority)
        ");
        $stmt->execute([
            ':contract_id' => $contractId,
            ':pattern_type' => $patternType,
            ':pattern_value' => $patternValue,
            ':priority' => $priority
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Löscht eine Matching-Regel.
     */
    public function deleteRule(int $ruleId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM bank_contract_rules WHERE id = :id");
        $stmt->execute([':id' => $ruleId]);
    }

    /**
     * Lädt alle aktiven Regeln inklusive der zugehörigen Vertrags-ID (für den Matcher).
     */
    public function getAllActiveRules(): array
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
     * Lädt eine schlanke Vertragsliste für Auswahlfelder.
     */
    public function getContractOptions(): array
    {
        $stmt = $this->pdo->query("SELECT id, name, direction, type, status FROM bank_contracts ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Löscht einen Vertrag. Die zugehörigen Regeln entfernt der Fremdschlüssel (CASCADE).
     */
    public function deleteContract(int $contractId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM bank_contracts WHERE id = :id");
        $stmt->execute([':id' => $contractId]);
    }

    public function getTransactionsForContract(int $contractId, int $limit): array
    {
        $stmt = $this->pdo->prepare("
            SELECT c.*
            FROM bank_giro_transactions c
            WHERE c.contract_id = :id
            ORDER BY c.booking_date DESC
            LIMIT :limit
        ");
        $stmt->execute([':id' => $contractId, ':limit' => $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Berechnet das erwartete Datum für einen Fälligkeitstag in einem bestimmten Monat/Jahr.
     * Liegt der Fälligkeitstag außerhalb der maximalen Monatstage (z. B. 31. im Februar),
     * wird das Datum automatisch auf den letzten gültigen Tag des Monats umgeleitet (unter Berücksichtigung von Schaltjahren).
     */
    public static function calculateExpectedDate(int $year, int $month, int $dueDay): string
    {
        $dueDay = max(1, min(31, $dueDay));
        $firstDayOfMonth = sprintf('%04d-%02d-01', $year, $month);
        $daysInMonth = (int)date('t', strtotime($firstDayOfMonth));
        $actualDay = min($dueDay, $daysInMonth);
        return sprintf('%04d-%02d-%02d', $year, $month, $actualDay);
    }

    /**
     * Prüft, ob ein Vertrag in einem bestimmten Kalendermonat und -jahr fällig ist.
     * Berücksichtigt Gültigkeitszeitraum (start_datum / end_datum) sowie Rhythmen
     * (monatlich, vierteljährlich, halbjährlich, jährlich, einmalig).
     */
    public static function isContractDueInMonth(array $contract, int $year, int $month, ?PDO $pdo = null): bool
    {
        $frequency = $contract['frequenz'] ?? 'monatlich';
        $startDate = !empty($contract['start_datum']) ? $contract['start_datum'] : null;
        $endDate = !empty($contract['end_datum']) ? $contract['end_datum'] : null;

        $firstDayOfMonth = sprintf('%04d-%02d-01', $year, $month);
        $daysInMonth = (int)date('t', strtotime($firstDayOfMonth));
        $lastDayOfMonth = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        // 1. Gültigkeitszeitraum prüfen
        if ($startDate !== null && $startDate > $lastDayOfMonth) {
            return false;
        }
        if ($endDate !== null && $endDate < $firstDayOfMonth) {
            return false;
        }

        // 2. Monatliche Verträge sind in jedem Monat fällig
        if ($frequency === 'monatlich') {
            return true;
        }

        // 3. Wenn ein Startdatum vorliegt, Rhythmus exakt anhand des Startdatums prüfen
        if ($startDate !== null) {
            $startDt = new \DateTimeImmutable($startDate);
            $startYear = (int)$startDt->format('Y');
            $startMonth = (int)$startDt->format('n');

            if ($frequency === 'vierteljaehrlich') {
                $monthDiff = ($year - $startYear) * 12 + ($month - $startMonth);
                return ($monthDiff >= 0 && $monthDiff % 3 === 0);
            }
            if ($frequency === 'halbjaehrlich') {
                $monthDiff = ($year - $startYear) * 12 + ($month - $startMonth);
                return ($monthDiff >= 0 && $monthDiff % 6 === 0);
            }
            if ($frequency === 'jaehrlich') {
                return ($month === $startMonth && $year >= $startYear);
            }
            if ($frequency === 'einmalig') {
                return ($year === $startYear && $month === $startMonth);
            }
        }

        // 4. Wenn kein Startdatum vorliegt, Rhythmus aus der letzten Buchung ableiten
        if ($pdo !== null && !empty($contract['id'])) {
            $stmt = $pdo->prepare("
                SELECT MAX(booking_date) AS last_date
                FROM bank_giro_transactions
                WHERE contract_id = :cid
            ");
            $stmt->execute([':cid' => (int)$contract['id']]);
            $lastDate = $stmt->fetchColumn();

            if ($lastDate) {
                $lastDt = new \DateTimeImmutable($lastDate);
                $lastYear = (int)$lastDt->format('Y');
                $lastMonth = (int)$lastDt->format('n');

                if ($frequency === 'vierteljaehrlich') {
                    $monthDiff = ($year - $lastYear) * 12 + ($month - $lastMonth);
                    return ($monthDiff >= 0 && $monthDiff % 3 === 0);
                }
                if ($frequency === 'halbjaehrlich') {
                    $monthDiff = ($year - $lastYear) * 12 + ($month - $lastMonth);
                    return ($monthDiff >= 0 && $monthDiff % 6 === 0);
                }
                if ($frequency === 'jaehrlich') {
                    return ($month === $lastMonth && $year >= $lastYear);
                }
            }
        }

        // Unbekannter Rhythmus ohne Startdatum/Buchungshistorie kann nicht willkürlich diesem Monat zugeordnet werden
        return false;
    }

    /**
     * Ermittelt die in den nächsten X Tagen (inkl. heute) erwarteten Vertragsbuchungen
     * und gleicht diese mit bereits vorhandenen Girokonto-Buchungen ab.
     */
    public function getUpcomingExpectedTransactions(int $daysAhead = 3, ?int $accountId = null): array
    {
        $stmt = $this->pdo->query("
            SELECT c.*, cat.name AS category_name 
            FROM bank_contracts c
            LEFT JOIN bank_categories cat ON c.category_id = cat.id
            WHERE c.status = 'aktiv' AND c.faelligkeitstag IS NOT NULL
            ORDER BY c.faelligkeitstag ASC, c.name ASC
        ");
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($contracts)) {
            return [];
        }

        $today = new \DateTimeImmutable('today');
        $todayStr = $today->format('Y-m-d');
        $endWindow = $today->modify("+{$daysAhead} days");
        $endWindowStr = $endWindow->format('Y-m-d');

        // Alle betroffenen Monate im Zeitfenster ermitteln (normalerweise 1 oder 2 Monate)
        $monthsToCheck = [];
        $cur = $today;
        while ($cur <= $endWindow) {
            $key = $cur->format('Y-m');
            $monthsToCheck[$key] = [
                'year' => (int)$cur->format('Y'),
                'month' => (int)$cur->format('n'),
            ];
            $cur = $cur->modify('+1 day');
        }

        // Query zur Prüfung, ob die Buchung in diesem Monat bereits stattgefunden hat
        $sqlTx = "
            SELECT id, booking_date, amount
            FROM bank_giro_transactions
            WHERE contract_id = :contract_id
              AND booking_date BETWEEN :min_date AND :max_date
        ";
        if ($accountId !== null && $accountId > 0) {
            $sqlTx .= " AND account_id = :account_id";
        }
        $sqlTx .= " ORDER BY booking_date DESC LIMIT 1";
        $stmtTx = $this->pdo->prepare($sqlTx);

        $upcoming = [];

        foreach ($contracts as $c) {
            $contractId = (int)$c['id'];
            $dueDay = (int)$c['faelligkeitstag'];

            foreach ($monthsToCheck as $mInfo) {
                $year = $mInfo['year'];
                $month = $mInfo['month'];

                // Prüfen, ob der Vertrag in diesem Monat fällig ist (Rhythmus & Gültigkeit)
                if (!self::isContractDueInMonth($c, $year, $month, $this->pdo)) {
                    continue;
                }

                // Fälligkeitsdatum berechnen (inkl. Monatsende- & Schaltjahr-Korrektur)
                $expectedDate = self::calculateExpectedDate($year, $month, $dueDay);

                // Liegt im Betrachtungsfenster?
                if ($expectedDate < $todayStr || $expectedDate > $endWindowStr) {
                    continue;
                }

                // Prüfen, ob bereits verbucht: Buchung im gleichen Kalendermonat suchen
                $daysInM = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
                $minDate = sprintf('%04d-%02d-01', $year, $month);
                $maxDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInM);

                $paramsTx = [
                    ':contract_id' => $contractId,
                    ':min_date' => $minDate,
                    ':max_date' => $maxDate,
                ];
                if ($accountId !== null && $accountId > 0) {
                    $paramsTx[':account_id'] = $accountId;
                }
                $stmtTx->execute($paramsTx);
                $bookedTx = $stmtTx->fetch(PDO::FETCH_ASSOC);

                $isBooked = ($bookedTx !== false && !empty($bookedTx));

                // Relative Datumsanzeige (Heute, Morgen, Übermorgen, etc.)
                $expectedDt = new \DateTimeImmutable($expectedDate);
                $diffDays = (int)$today->diff($expectedDt)->format('%r%a');
                if ($diffDays === 0) {
                    $relativeLabel = 'Heute';
                } elseif ($diffDays === 1) {
                    $relativeLabel = 'Morgen';
                } elseif ($diffDays === 2) {
                    $relativeLabel = 'Übermorgen';
                } else {
                    $relativeLabel = "In $diffDays Tagen";
                }

                $upcoming[] = [
                    'contract_id' => $contractId,
                    'contract_name' => $c['name'],
                    'type' => $c['type'],
                    'direction' => $c['direction'] ?? 'expense',
                    'betrag' => (float)$c['betrag'],
                    'frequenz' => $c['frequenz'],
                    'faelligkeitstag' => $dueDay,
                    'expected_date' => $expectedDate,
                    'relative_label' => $relativeLabel,
                    'diff_days' => $diffDays,
                    'category_name' => $c['category_name'] ?? null,
                    'auftraggeber' => $c['auftraggeber'] ?? null,
                    'is_booked' => $isBooked,
                    'booked_date' => $isBooked ? $bookedTx['booking_date'] : null,
                    'booked_amount' => $isBooked ? (float)$bookedTx['amount'] : null,
                ];
            }
        }

        // Sortieren nach Datum aufsteigend, dann nach Name
        usort($upcoming, function ($a, $b) {
            $cmp = strcmp($a['expected_date'], $b['expected_date']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['contract_name'], $b['contract_name']);
        });

        return $upcoming;
    }

    /**
     * Initialisiert den Fälligkeitstag bei bestehenden Verträgen ohne Fälligkeitstag
     * anhand des Buchungstages der letzten zugeordneten Girokonto-Transaktion.
     */
    public function migrateMissingDueDays(): int
    {
        $stmt = $this->pdo->prepare("
            UPDATE bank_contracts c
            JOIN (
                SELECT contract_id, DAY(MAX(booking_date)) AS last_day
                FROM bank_giro_transactions
                WHERE contract_id IS NOT NULL
                GROUP BY contract_id
            ) b ON c.id = b.contract_id
            SET c.faelligkeitstag = b.last_day
            WHERE c.faelligkeitstag IS NULL
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }
}