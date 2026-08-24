<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Lesende Abfragen für die Girokonto-Übersicht: Umsatzliste, Paginierung,
 * Zeitraumsummen und die Sprungposition einzelner Umsätze.
 */
class GiroOverviewRepository
{
    /** Trennzeichen, mit denen die Tags eines Umsatzes per GROUP_CONCAT zusammengefasst werden. */
    private const TAG_SEPARATOR = '||';
    private const TAG_FIELD_SEPARATOR = ':';

    /** Standardfarbe für Tags ohne hinterlegten Farbwert. */
    private const DEFAULT_TAG_COLOR = '#3b82f6';

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Buchungsdatum eines einzelnen Umsatzes (für den Direktsprung per ?tx=ID).
     */
    public function getBookingDate(int $transactionId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT booking_date FROM bank_giro_transactions WHERE id = :id");
        $stmt->execute([':id' => $transactionId]);
        $bookingDate = $stmt->fetchColumn();

        return ($bookingDate === false || $bookingDate === null) ? null : (string)$bookingDate;
    }

    /**
     * Zählt die Umsätze eines Zeitraums, die in der Sortierung (Datum absteigend, ID absteigend)
     * vor dem angegebenen Umsatz liegen. Ergibt dessen Position für die Seitenberechnung.
     */
    public function countTransactionsBefore(
        int $accountId,
        string $startDate,
        string $endDate,
        string $bookingDate,
        int $transactionId
    ): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM bank_giro_transactions
            WHERE booking_date BETWEEN :start AND :end
              AND account_id = :account_id
              AND (booking_date > :bdate_min OR (booking_date = :bdate AND id > :id))
        ");
        $stmt->execute([
            ':start'      => $startDate,
            ':end'        => $endDate,
            ':account_id' => $accountId,
            ':bdate_min'  => $bookingDate,
            ':bdate'      => $bookingDate,
            ':id'         => $transactionId,
        ]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Gesamtzahl der Umsätze im Zeitraum, optional auf ein Tag eingeschränkt.
     */
    public function countTransactions(int $accountId, string $startDate, string $endDate, ?int $tagId = null): int
    {
        [$whereClause, $params] = $this->buildFilter($accountId, $startDate, $endDate, $tagId);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM bank_giro_transactions bt {$whereClause}");
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Lädt eine Seite der Umsatzliste inklusive Tags, getroffener Regel,
     * verknüpfter Kreditkartenabrechnung, E-Bon und Vertrag.
     */
    public function getTransactions(
        int $accountId,
        string $startDate,
        string $endDate,
        ?int $tagId,
        int $limit,
        int $offset
    ): array {
        [$whereClause, $params] = $this->buildFilter($accountId, $startDate, $endDate, $tagId);

        $stmt = $this->pdo->prepare("
            SELECT
                bt.*,
                r.text_pattern AS matched_text_pattern,
                r.payee_pattern AS matched_payee_pattern,
                s.id AS linked_statement_id,
                s.statement_date AS linked_statement_date,
                rec.id AS linked_receipt_id,
                c.id AS contract_id,
                c.name AS contract_name,
                GROUP_CONCAT(CONCAT(t.id, '" . self::TAG_FIELD_SEPARATOR . "', t.name, '"
                    . self::TAG_FIELD_SEPARATOR . "', t.color) SEPARATOR '" . self::TAG_SEPARATOR . "') AS tag_data
            FROM bank_giro_transactions bt
            LEFT JOIN bank_tag_rules r ON bt.matched_rule_id = r.id
            LEFT JOIN bank_cc_statements s ON bt.id = s.bank_transaction_id
            LEFT JOIN kb_receipts rec ON bt.id = rec.bank_giro_transaction_id
            LEFT JOIN bank_contracts c ON bt.contract_id = c.id
            LEFT JOIN bank_transaction_tags tt ON bt.id = tt.transaction_id
            LEFT JOIN bank_tags t ON tt.tag_id = t.id
            {$whereClause}
            GROUP BY bt.id
            ORDER BY bt.booking_date DESC, bt.id DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            function (array $row): array {
                $row['tags'] = $this->parseTagData($row['tag_data'] ?? null);
                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Ermittelt Ausgaben- und Einnahmesumme des Zeitraums direkt aus den Umsätzen
     * (ohne Doppelzählung durch mehrfach getaggte Buchungen).
     *
     * @return array{expenses: float, income: float} Ausgaben als positiver Betrag
     */
    public function getPeriodTotals(int $accountId, string $startDate, string $endDate): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) AS total_expenses,
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS total_income
            FROM bank_giro_transactions
            WHERE booking_date BETWEEN :start AND :end
              AND account_id = :account_id
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate, ':account_id' => $accountId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'expenses' => abs((float)($totals['total_expenses'] ?? 0)),
            'income'   => (float)($totals['total_income'] ?? 0),
        ];
    }

    /**
     * Baut die WHERE-Bedingung der Umsatzliste. Alle Werte werden als Parameter gebunden.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilter(int $accountId, string $startDate, string $endDate, ?int $tagId): array
    {
        $whereClause = "WHERE bt.booking_date BETWEEN :start AND :end AND bt.account_id = :account_id";
        $params = [
            ':start'      => $startDate,
            ':end'        => $endDate,
            ':account_id' => $accountId,
        ];

        if ($tagId !== null && $tagId > 0) {
            $whereClause .= " AND bt.id IN (SELECT transaction_id FROM bank_transaction_tags WHERE tag_id = :tag_id)";
            $params[':tag_id'] = $tagId;
        }

        return [$whereClause, $params];
    }

    /**
     * Zerlegt die per GROUP_CONCAT zusammengefassten Tags eines Umsatzes.
     */
    private function parseTagData(?string $tagData): array
    {
        if ($tagData === null || $tagData === '') {
            return [];
        }

        $tags = [];
        foreach (explode(self::TAG_SEPARATOR, $tagData) as $part) {
            $fields = explode(self::TAG_FIELD_SEPARATOR, $part);
            if (count($fields) < 2) {
                continue;
            }

            $tags[] = [
                'id'    => (int)$fields[0],
                'name'  => $fields[1],
                'color' => ($fields[2] ?? '') ?: self::DEFAULT_TAG_COLOR,
            ];
        }

        return $tags;
    }
}
