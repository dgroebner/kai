<?php

namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Utils\MerchantNormalizer;
use PDO;

/**
 * Lesezugriffe auf Kassenbons und deren Positionen für die Ansichten
 * unter `public/kassenbon/`.
 *
 * Die Schreib- und Importlogik liegt bewusst getrennt in `ReceiptRepository`.
 */
class ReceiptQueryRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Gesamtzahl der gespeicherten Bons (für die Paginierung).
     */
    public function countReceipts(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM kb_receipts")->fetchColumn();
    }

    /**
     * Eine Seite der Bon-Übersicht inklusive Positionsanzahl und
     * Buchungsdaten der verknüpften Giro- bzw. Kreditkartenumsätze.
     */
    public function getReceipts(int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                r.*,
                COUNT(i.id) as item_count,
                COALESCE(r.contract_id, gt.contract_id) AS contract_id,
                gt.booking_date AS giro_booking_date,
                ct.booking_date AS cc_booking_date,
                ct.statement_id AS cc_statement_id,
                COALESCE(c.name, c_gt.name) AS contract_name,
                EXISTS (
                    SELECT 1 FROM vehicle_charges vc WHERE vc.receipt_id = r.id
                ) AS has_linked_charge,
                EXISTS (
                    SELECT 1 FROM kb_items ki 
                    WHERE ki.receipt_id = r.id 
                      AND (ki.category = 'E-Auto laden' OR ki.name LIKE '%Ladevorgang%' OR ki.name LIKE '%kWh%')
                ) AS has_charging_items
            FROM kb_receipts r
            LEFT JOIN kb_items i ON r.id = i.receipt_id
            LEFT JOIN bank_giro_transactions gt ON r.bank_giro_transaction_id = gt.id
            LEFT JOIN bank_cc_transactions ct ON r.bank_cc_transaction_id = ct.id
            LEFT JOIN bank_contracts c ON r.contract_id = c.id
            LEFT JOIN bank_contracts c_gt ON gt.contract_id = c_gt.id
            GROUP BY r.id
            ORDER BY r.purchase_date DESC, r.id DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['store'] = MerchantNormalizer::normalize($row['store'] ?? '');
            if (($row['document_type'] ?? '') === 'receipt' || ($row['document_type'] ?? '') === 'invoice' || empty($row['document_type'])) {
                if (!empty($row['has_linked_charge']) || !empty($row['has_charging_items'])) {
                    $row['document_type'] = 'charging_invoice';
                }
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Einzelnen Bon inklusive Verknüpfung zu Girokonto oder Kreditkarte laden.
     */
    public function getReceiptById(int $receiptId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                r.*,
                COALESCE(r.contract_id, gt.contract_id) AS contract_id,
                gt.booking_date AS giro_booking_date,
                ct.booking_date AS cc_booking_date,
                ct.statement_id AS cc_statement_id,
                COALESCE(c.name, c_gt.name) AS contract_name,
                gt.contract_id AS gt_contract_id,
                EXISTS (
                    SELECT 1 FROM vehicle_charges vc WHERE vc.receipt_id = r.id
                ) AS has_linked_charge,
                EXISTS (
                    SELECT 1 FROM kb_items ki 
                    WHERE ki.receipt_id = r.id 
                      AND (ki.category = 'E-Auto laden' OR ki.name LIKE '%Ladevorgang%' OR ki.name LIKE '%kWh%')
                ) AS has_charging_items
            FROM kb_receipts r
            LEFT JOIN bank_giro_transactions gt ON r.bank_giro_transaction_id = gt.id
            LEFT JOIN bank_cc_transactions ct ON r.bank_cc_transaction_id = ct.id
            LEFT JOIN bank_contracts c ON r.contract_id = c.id
            LEFT JOIN bank_contracts c_gt ON gt.contract_id = c_gt.id
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => $receiptId]);

        $receipt = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($receipt !== null) {
            $receipt['store'] = MerchantNormalizer::normalize($receipt['store'] ?? '');
            if (!empty($receipt['gt_contract_id']) && (empty($receipt['contract_id']) || $receipt['contract_id'] === $receipt['gt_contract_id'])) {
                // Bei Bedarf auch in der Datenbank nachziehen
                $this->pdo->prepare("UPDATE kb_receipts SET contract_id = :cid WHERE id = :id AND (contract_id IS NULL OR contract_id = 0)")
                    ->execute([':cid' => $receipt['gt_contract_id'], ':id' => $receiptId]);
            }
            if (($receipt['document_type'] ?? '') === 'receipt' || ($receipt['document_type'] ?? '') === 'invoice' || empty($receipt['document_type'])) {
                if (!empty($receipt['has_linked_charge']) || !empty($receipt['has_charging_items'])) {
                    $receipt['document_type'] = 'charging_invoice';
                }
            }
            if (!empty($receipt['meta_data']) && is_string($receipt['meta_data'])) {
                $receipt['meta'] = json_decode($receipt['meta_data'], true) ?: [];
            } else {
                $receipt['meta'] = [];
            }
        }

        return $receipt;
    }

    /**
     * Alle Positionen eines Bons in Erfassungsreihenfolge.
     */
    public function getItemsForReceipt(int $receiptId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM kb_items WHERE receipt_id = :id ORDER BY id");
        $stmt->execute([':id' => $receiptId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Liefert verknüpfte Fahrzeug-Ladevorgänge für einen Kassenbon (z. B. E-Bon / Ladeabrechnung).
     *
     * @return array<array<string, mixed>>
     */
    public function getLinkedChargesForReceipt(int $receiptId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                id,
                start_time,
                end_time,
                charged_net_kwh,
                cost_eur,
                loss_kwh,
                loss_pct,
                station_name,
                station_operator,
                charge_mode,
                receipt_item_id
            FROM vehicle_charges
            WHERE receipt_id = :receipt_id
            ORDER BY start_time ASC, id ASC
        ");
        $stmt->execute([':receipt_id' => $receiptId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Alle Positionen eines Zeitraums inklusive Einkaufsdatum für die Auswertung.
     * Standardmäßig gefiltert auf reguläre E-Bons (Einzelhandel & Supermärkte),
     * da Rechnungen (Verträge, Hosting, Mobilfunk etc.) bereits im Finanzreport ausgewertet werden.
     */
    public function getItemsForPeriod(string $startDate, string $endDate, ?string $documentType = 'receipt'): array
    {
        $sql = "
            SELECT i.*, r.purchase_date
            FROM kb_items i
            JOIN kb_receipts r ON i.receipt_id = r.id
            WHERE r.purchase_date BETWEEN :start AND :end
        ";
        $params = [':start' => $startDate, ':end' => $endDate];

        if ($documentType !== null) {
            if ($documentType === 'receipt') {
                $sql .= " AND (r.document_type = 'receipt' OR r.document_type IS NULL OR r.document_type = '')
                          AND NOT EXISTS (SELECT 1 FROM vehicle_charges vc WHERE vc.receipt_id = r.id)
                          AND NOT EXISTS (SELECT 1 FROM kb_items ki2 WHERE ki2.receipt_id = r.id AND (ki2.category = 'E-Auto laden' OR ki2.name LIKE '%Ladevorgang%' OR ki2.name LIKE '%kWh%'))";
            } else {
                $sql .= " AND r.document_type = :doc_type";
                $params[':doc_type'] = $documentType;
            }
        }

        $sql .= " ORDER BY r.purchase_date DESC, r.id DESC, i.id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Alle Positionen eines Zeitraums inklusive Einkaufsdatum und Händler
     * für die Inflations- und Preisanalyse.
     * Standardmäßig gefiltert auf reguläre E-Bons (Einzelhandel & Supermärkte).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getItemsForInflation(?string $startDate = null, ?string $endDate = null, ?string $documentType = 'receipt'): array
    {
        $sql = "
            SELECT 
                i.id,
                i.receipt_id,
                i.name,
                i.quantity,
                i.unit_price,
                i.total_price,
                i.category,
                r.store,
                r.purchase_date
            FROM kb_items i
            JOIN kb_receipts r ON i.receipt_id = r.id
            WHERE i.unit_price > 0
        ";

        $params = [];
        if ($documentType !== null) {
            if ($documentType === 'receipt') {
                $sql .= " AND (r.document_type = 'receipt' OR r.document_type IS NULL OR r.document_type = '')
                          AND NOT EXISTS (SELECT 1 FROM vehicle_charges vc WHERE vc.receipt_id = r.id)
                          AND NOT EXISTS (SELECT 1 FROM kb_items ki2 WHERE ki2.receipt_id = r.id AND (ki2.category = 'E-Auto laden' OR ki2.name LIKE '%Ladevorgang%' OR ki2.name LIKE '%kWh%'))";
            } else {
                $sql .= " AND r.document_type = :doc_type";
                $params[':doc_type'] = $documentType;
            }
        }
        if ($startDate !== null) {
            $sql .= " AND r.purchase_date >= :start";
            $params[':start'] = $startDate;
        }
        if ($endDate !== null) {
            $sql .= " AND r.purchase_date <= :end";
            $params[':end'] = $endDate;
        }

        $sql .= " ORDER BY r.purchase_date ASC, r.id ASC, i.id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
