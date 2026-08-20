<?php

namespace Kai\Tools\Kassenbon;

use PDO;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;

class ReceiptMatcher
{
    /** Wie viele Tage nach dem Kauf eine Buchung noch als Zahlung gilt. */
    private const BOOKING_DELAY_DAYS = 14;

    /** Wie viele Tage vor dem Kauf eine Bargeldabhebung berücksichtigt wird. */
    private const CASH_LOOKBACK_DAYS = 14;

    /** Maximaler Betrag, um den eine Bargeldabhebung über der Bonsumme liegen darf. */
    private const CASH_TOLERANCE = 200.00;

    /** Toleranz für Kreditkartenbuchungen (z. B. Sofortrabatte, Trinkgeld). */
    private const CC_TOLERANCE = 10.00;

    /** Buchungsarten (Spalte `type`), die einer Bargeldverfügung entsprechen. */
    private const CASH_TYPES = ['Geldautomat', 'Bar', 'Sorten (Kasse)'];

    /** Alle Textfelder einer Giro-Buchung, in denen der Händlername stehen kann. */
    private const GIRO_PARTNER_EXPRESSION = "CONCAT_WS(' ', t.remittance_info, t.creditor, t.remitter, t.debitor)";

    /** Bevorzugter Anzeigename des Buchungspartners. */
    private const GIRO_COUNTERPARTY_EXPRESSION = "COALESCE(NULLIF(t.creditor, ''), NULLIF(t.remitter, ''), NULLIF(t.debitor, ''))";

    private PDO $pdo;
    private Logger $logger;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->logger = new Logger(14);
    }

    /**
     * Gleicht alle unverknüpften Kassenbons mit Girokonto- und Kreditkarten-Umsätzen ab.
     *
     * @return array Summe der Verknüpfungen ['giro' => int, 'cc' => int]
     */
    public function syncUnlinkedReceipts(): array
    {
        $stmtUnlinked = $this->pdo->query("
            SELECT id, purchase_date, total, store
            FROM kb_receipts
            WHERE bank_giro_transaction_id IS NULL
              AND bank_cc_transaction_id IS NULL
        ");
        $unlinkedReceipts = $stmtUnlinked->fetchAll(PDO::FETCH_ASSOC);

        if (empty($unlinkedReceipts)) {
            return ['giro' => 0, 'cc' => 0];
        }

        $linkedGiro = 0;
        $linkedCc = 0;

        // Query A: Girokonto-Umsatz suchen (Betrag negativ, nur aktive Girokonten).
        // Der Händlername steht je nach Buchungsart im Verwendungszweck oder in
        // einem der Partnerfelder (creditor/remitter/debitor).
        $stmtFindGiro = $this->pdo->prepare("
            SELECT t.id
            FROM bank_giro_transactions t
            JOIN bank_accounts a ON a.id = t.account_id
            WHERE a.account_type = 'checking'
              AND a.is_active = 1
              AND t.amount = :amount
              AND t.booking_date BETWEEN :date_start AND :date_end
              AND (
                  " . self::GIRO_PARTNER_EXPRESSION . " LIKE :merchant
                  OR (:has_short = 1 AND " . self::GIRO_PARTNER_EXPRESSION . " LIKE :merchant_short)
              )
              AND t.id NOT IN (
                  SELECT bank_giro_transaction_id FROM kb_receipts WHERE bank_giro_transaction_id IS NOT NULL
              )
            ORDER BY t.booking_date ASC
            LIMIT 1
        ");

        // Query B: Kreditkarten-Transaktion suchen
        $stmtFindCc = $this->pdo->prepare("
            SELECT t.id
            FROM bank_cc_transactions t
            WHERE (t.amount = :amount OR t.amount = :amount_neg)
              AND t.booking_date BETWEEN :date_start AND :date_end
              AND (
                  t.merchant_name LIKE :merchant
                  OR (:has_short = 1 AND t.merchant_name LIKE :merchant_short)
              )
              AND t.id NOT IN (
                  SELECT bank_cc_transaction_id FROM kb_receipts WHERE bank_cc_transaction_id IS NOT NULL
              )
            ORDER BY t.booking_date ASC
            LIMIT 1
        ");

        $stmtUpdateGiro = $this->pdo->prepare("UPDATE kb_receipts SET bank_giro_transaction_id = :tx_id WHERE id = :receipt_id");
        $stmtUpdateCc   = $this->pdo->prepare("UPDATE kb_receipts SET bank_cc_transaction_id = :tx_id WHERE id = :receipt_id");

        foreach ($unlinkedReceipts as $receipt) {
            $receiptId    = (int)$receipt['id'];
            $purchaseDate = date('Y-m-d', strtotime((string)$receipt['purchase_date']));
            $totalAmount  = (float)$receipt['total'];
            $storeName    = trim((string)$receipt['store']);

            if ($storeName === '' || abs($totalAmount) < 0.01) {
                continue;
            }

            $expectedGiroAmount = -abs($totalAmount);
            $expectedCcAmount   = abs($totalAmount);

            $dateStart = $purchaseDate;
            $dateEnd   = date('Y-m-d', strtotime($purchaseDate . ' +' . self::BOOKING_DELAY_DAYS . ' days'));

            $merchantParam = '%' . $this->escapeLike($storeName) . '%';
            $shortToken    = $this->buildStoreToken($storeName);
            $hasShort      = $shortToken !== '' ? 1 : 0;
            $merchantShort = $hasShort ? '%' . $this->escapeLike($shortToken) . '%' : '';

            // 1. Erst auf dem Girokonto suchen
            $stmtFindGiro->execute([
                ':amount'         => $expectedGiroAmount,
                ':date_start'     => $dateStart,
                ':date_end'       => $dateEnd,
                ':merchant'       => $merchantParam,
                ':has_short'      => $hasShort,
                ':merchant_short' => $merchantShort
            ]);
            $giroTxId = $stmtFindGiro->fetchColumn();

            if ($giroTxId) {
                $stmtUpdateGiro->execute([':tx_id' => $giroTxId, ':receipt_id' => $receiptId]);
                $linkedGiro++;
                $this->logger->info("ReceiptMatcher: Kassenbon #{$receiptId} mit Giro-Transaktion #{$giroTxId} verknüpft.");
                continue;
            }

            // 2. Falls nicht auf Giro, auf Kreditkarte suchen
            $stmtFindCc->execute([
                ':amount'         => $expectedCcAmount,
                ':amount_neg'     => -$expectedCcAmount,
                ':date_start'     => $dateStart,
                ':date_end'       => $dateEnd,
                ':merchant'       => $merchantParam,
                ':has_short'      => $hasShort,
                ':merchant_short' => $merchantShort
            ]);
            $ccTxId = $stmtFindCc->fetchColumn();

            if ($ccTxId) {
                $stmtUpdateCc->execute([':tx_id' => $ccTxId, ':receipt_id' => $receiptId]);
                $linkedCc++;
                $this->logger->info("ReceiptMatcher: Kassenbon #{$receiptId} mit KK-Transaktion #{$ccTxId} verknüpft.");
            }
        }

        return ['giro' => $linkedGiro, 'cc' => $linkedCc];
    }

    /**
     * Liefert mögliche Bankbuchungen für die manuelle Zuordnung eines Kassenbons.
     *
     * Die Rückgabe ist für beide Kontoarten einheitlich aufgebaut:
     * id, booking_date, amount, merchant_raw, info, account_type, is_cash.
     *
     * @return array{giro: array<int, array<string, mixed>>, cc: array<int, array<string, mixed>>}
     */
    public function getCandidatesForReceipt(int $receiptId): array
    {
        $stmt = $this->pdo->prepare("SELECT purchase_date, total FROM kb_receipts WHERE id = :id");
        $stmt->execute([':id' => $receiptId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$receipt) {
            return ['giro' => [], 'cc' => []];
        }

        $purchaseDate = date('Y-m-d', strtotime((string)$receipt['purchase_date']));
        $dateEnd      = date('Y-m-d', strtotime($purchaseDate . ' +' . self::BOOKING_DELAY_DAYS . ' days'));
        $cashStart    = date('Y-m-d', strtotime($purchaseDate . ' -' . self::CASH_LOOKBACK_DAYS . ' days'));
        $totalAmount  = abs((float)$receipt['total']);

        return [
            'giro' => $this->findGiroCandidates(-$totalAmount, $purchaseDate, $dateEnd, $cashStart),
            'cc'   => $this->findCcCandidates($totalAmount, $purchaseDate, $dateEnd)
        ];
    }

    /**
     * Giro-Kandidaten: betragsgleiche Abbuchungen im Buchungsfenster oder
     * Bargeldabhebungen, die den Bon abdecken (auch vor dem Kaufdatum).
     */
    private function findGiroCandidates(float $expectedAmount, string $dateStart, string $dateEnd, string $cashStart): array
    {
        // Zwei getrennte Platzhaltersätze, da native Prepared Statements einen
        // Namensplatzhalter nicht mehrfach binden können.
        $cashParams = [];
        $selectPlaceholders = [];
        $wherePlaceholders = [];

        foreach (array_values(self::CASH_TYPES) as $index => $cashType) {
            $selectPlaceholders[] = ':cash_type_sel_' . $index;
            $wherePlaceholders[]  = ':cash_type_flt_' . $index;
            $cashParams[':cash_type_sel_' . $index] = $cashType;
            $cashParams[':cash_type_flt_' . $index] = $cashType;
        }

        $stmt = $this->pdo->prepare("
            SELECT t.id,
                   t.booking_date,
                   t.amount,
                   t.type,
                   " . self::GIRO_COUNTERPARTY_EXPRESSION . " AS counterparty,
                   t.remittance_info,
                   (t.type IN (" . implode(', ', $selectPlaceholders) . ")) AS is_cash
            FROM bank_giro_transactions t
            JOIN bank_accounts a ON a.id = t.account_id
            WHERE a.account_type = 'checking'
              AND a.is_active = 1
              AND t.amount < 0
              AND t.id NOT IN (
                  SELECT bank_giro_transaction_id FROM kb_receipts WHERE bank_giro_transaction_id IS NOT NULL
              )
              AND (
                  (t.amount = :amount AND t.booking_date BETWEEN :date_start AND :date_end)
                  OR (
                      t.type IN (" . implode(', ', $wherePlaceholders) . ")
                      AND t.amount <= :cash_amount
                      AND t.amount >= :cash_min
                      AND t.booking_date BETWEEN :cash_start AND :cash_end
                  )
              )
            ORDER BY t.booking_date ASC
        ");

        $stmt->execute($cashParams + [
            ':amount'      => $expectedAmount,
            ':date_start'  => $dateStart,
            ':date_end'    => $dateEnd,
            ':cash_amount' => $expectedAmount,
            ':cash_min'    => $expectedAmount - self::CASH_TOLERANCE,
            ':cash_start'  => $cashStart,
            ':cash_end'    => $dateEnd
        ]);

        $candidates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counterparty = trim((string)($row['counterparty'] ?? ''));
            $remittance   = trim((string)($row['remittance_info'] ?? ''));
            $type         = trim((string)($row['type'] ?? ''));

            // Fällt kein Partnername an, dient der Verwendungszweck als Anzeigename.
            $displayName = $counterparty !== '' ? $counterparty : ($remittance !== '' ? $remittance : 'Unbekannte Buchung');
            $infoParts   = array_filter([
                $type !== '' && $type !== 'Unknown' ? $type : '',
                $counterparty !== '' ? $remittance : ''
            ], static fn(string $part): bool => $part !== '');

            $candidates[] = [
                'id'           => (int)$row['id'],
                'booking_date' => (string)$row['booking_date'],
                'amount'       => (float)$row['amount'],
                'merchant_raw' => $displayName,
                'info'         => implode(' · ', $infoParts),
                'account_type' => 'giro',
                'is_cash'      => (bool)$row['is_cash']
            ];
        }

        return $candidates;
    }

    /**
     * Kreditkarten-Kandidaten: Betrag mit kleiner Toleranz im Buchungsfenster.
     * Das Vorzeichen wird ignoriert, da Abrechnungen je nach Import positiv
     * oder negativ geführt werden.
     */
    private function findCcCandidates(float $expectedAmount, string $dateStart, string $dateEnd): array
    {
        $stmt = $this->pdo->prepare("
            SELECT t.id, t.booking_date, t.amount, t.merchant_name, t.card_number_suffix
            FROM bank_cc_transactions t
            WHERE t.booking_date BETWEEN :date_start AND :date_end
              AND ABS(t.amount) BETWEEN :amount_min AND :amount_max
              AND t.id NOT IN (
                  SELECT bank_cc_transaction_id FROM kb_receipts WHERE bank_cc_transaction_id IS NOT NULL
              )
            ORDER BY t.booking_date ASC
        ");
        $stmt->execute([
            ':date_start' => $dateStart,
            ':date_end'   => $dateEnd,
            ':amount_min' => max(0.0, $expectedAmount - self::CC_TOLERANCE),
            ':amount_max' => $expectedAmount + self::CC_TOLERANCE
        ]);

        $candidates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $merchant = trim((string)$row['merchant_name']);
            $suffix   = trim((string)($row['card_number_suffix'] ?? ''));

            $candidates[] = [
                'id'           => (int)$row['id'],
                'booking_date' => (string)$row['booking_date'],
                'amount'       => (float)$row['amount'],
                'merchant_raw' => $merchant !== '' ? $merchant : 'Unbekannte Buchung',
                'info'         => $suffix !== '' ? 'Karte ' . $suffix : '',
                'account_type' => 'cc',
                'is_cash'      => false
            ];
        }

        return $candidates;
    }

    /**
     * Verknüpft einen Kassenbon manuell mit einer Transaktion und setzt optional das Bargeld-Tag.
     */
    public function linkReceiptManually(int $receiptId, int $txId, string $accountType, bool $applyCashTag = false): bool
    {
        if (!in_array($accountType, ['giro', 'cc'], true)) {
            return false;
        }

        if (!$this->transactionExists($txId, $accountType)) {
            $this->logger->error("ReceiptMatcher: Transaktion #{$txId} ({$accountType}) existiert nicht.");
            return false;
        }

        if ($accountType === 'giro') {
            $stmt = $this->pdo->prepare("UPDATE kb_receipts SET bank_giro_transaction_id = :tx_id, bank_cc_transaction_id = NULL WHERE id = :receipt_id");
            $stmt->execute([':tx_id' => $txId, ':receipt_id' => $receiptId]);

            if ($applyCashTag) {
                $this->assignCashTagToGiroTx($txId);
            }
        } else {
            $stmt = $this->pdo->prepare("UPDATE kb_receipts SET bank_cc_transaction_id = :tx_id, bank_giro_transaction_id = NULL WHERE id = :receipt_id");
            $stmt->execute([':tx_id' => $txId, ':receipt_id' => $receiptId]);
        }

        $this->logger->info("ReceiptMatcher: Kassenbon #{$receiptId} manuell mit {$accountType}-Transaktion #{$txId} verknüpft.");
        return true;
    }

    /**
     * Prüft, ob die gewählte Transaktion tatsächlich existiert.
     */
    private function transactionExists(int $txId, string $accountType): bool
    {
        $table = $accountType === 'giro' ? 'bank_giro_transactions' : 'bank_cc_transactions';
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $txId]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Hilfsmethode: Weist einer Giro-Transaktion das Tag "Bargeld" zu.
     */
    private function assignCashTagToGiroTx(int $txId): void
    {
        // Prüfen ob Tag "Bargeld" existiert, ansonsten anlegen
        $stmtTag = $this->pdo->prepare("SELECT id FROM bank_tags WHERE name = 'Bargeld' LIMIT 1");
        $stmtTag->execute();
        $tagId = $stmtTag->fetchColumn();

        if (!$tagId) {
            $stmtCreate = $this->pdo->prepare("INSERT INTO bank_tags (name, color) VALUES ('Bargeld', '#f59e0b')");
            $stmtCreate->execute();
            $tagId = $this->pdo->lastInsertId();
        }

        // Verknüpfung in bank_transaction_tags herstellen (Ignore falls bereits verknüpft)
        $stmtLink = $this->pdo->prepare("INSERT IGNORE INTO bank_transaction_tags (transaction_id, tag_id) VALUES (:tx_id, :tag_id)");
        $stmtLink->execute([':tx_id' => $txId, ':tag_id' => $tagId]);
    }

    /**
     * Ermittelt ein kurzes, aussagekräftiges Suchtoken aus dem Händlernamen
     * (z. B. "REWE Markt GmbH" -> "REWE").
     */
    private function buildStoreToken(string $storeName): string
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $storeName, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 4) {
                return $token;
            }
        }

        return mb_strlen($storeName) > 3 ? mb_substr($storeName, 0, 4) : '';
    }

    /**
     * Maskiert LIKE-Sonderzeichen, damit Händlernamen wörtlich gesucht werden.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
