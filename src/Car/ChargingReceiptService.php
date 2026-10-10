<?php

namespace Kai\Tools\Car;

use DateTime;
use DateTimeZone;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use Throwable;

/**
 * Verknüpft E-Bons und Ladeabrechnungen aus kb_receipts mit Fahrzeug-Ladevorgängen.
 */
class ChargingReceiptService
{
    private PDO $db;
    private Logger $logger;

    public function __construct(?PDO $db = null, ?Logger $logger = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->logger = $logger ?? new Logger(14);
    }

    /**
     * Sucht passende Belege in kb_receipts für einen Ladevorgang.
     *
     * @return array<array<string, mixed>>
     */
    public function findCandidatesForCharge(int $chargeId, ?string $searchQuery = null): array
    {
        $stmt = $this->db->prepare("SELECT * FROM vehicle_charges WHERE id = :id");
        $stmt->execute([':id' => $chargeId]);
        $charge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$charge) {
            return [];
        }

        // Datum des Ladevorgangs in Lokalzeit ermitteln
        $dt = new DateTime($charge['start_time'], new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        $chargeDate = $dt->format('Y-m-d');

        $minDate = (clone $dt)->modify('-3 days')->format('Y-m-d');
        $maxDate = (clone $dt)->modify('+4 days')->format('Y-m-d');

        $operator = $charge['station_operator'] ?? '';

        $sql = "
            SELECT 
                r.id,
                r.store,
                r.purchase_date,
                r.total,
                r.file_hash,
                vc.id AS linked_charge_id
            FROM kb_receipts r
            LEFT JOIN vehicle_charges vc ON vc.receipt_id = r.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($searchQuery)) {
            $sql .= " AND (r.store LIKE :query OR r.purchase_date LIKE :query)";
            $params[':query'] = '%' . trim($searchQuery) . '%';
            $sql .= " ORDER BY r.purchase_date DESC LIMIT 25";
        } else {
            // Standard: Zeitraum +/- 3 Tage ODER Händler passt zu Ladedienstleistern
            $chargingKeywords = ['ionity', 'enbw', 'ewe', 'aral', 'shell', 'tesla', 'fastned', 'allego', 'maingau', 'ladenetz'];
            if (!empty($operator)) {
                $chargingKeywords[] = mb_strtolower($operator);
            }

            $likeClauses = [];
            foreach (array_unique($chargingKeywords) as $idx => $kw) {
                $likeClauses[] = "LOWER(r.store) LIKE :kw_{$idx}";
                $params[":kw_{$idx}"] = '%' . $kw . '%';
            }
            $orKeywordSql = implode(' OR ', $likeClauses);

            $sql .= " AND (
                (r.purchase_date >= :min_date AND r.purchase_date <= :max_date)
                OR ({$orKeywordSql})
            )";
            $params[':min_date'] = $minDate;
            $params[':max_date'] = $maxDate;

            $sql .= " ORDER BY 
                CASE 
                    WHEN r.purchase_date = :exact_date THEN 1
                    WHEN ABS(DATEDIFF(r.purchase_date, :exact_date2)) <= 1 THEN 2
                    ELSE 3
                END ASC,
                r.purchase_date DESC
                LIMIT 20
            ";
            $params[':exact_date'] = $chargeDate;
            $params[':exact_date2'] = $chargeDate;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Treffer mit Relevanz und Formatierung versehen
        foreach ($results as &$r) {
            $isExactDate = ($r['purchase_date'] === $chargeDate);
            $r['is_exact_date'] = $isExactDate;
            $r['is_current_charge'] = ($r['linked_charge_id'] == $chargeId);
            $r['is_already_linked'] = (!empty($r['linked_charge_id']) && $r['linked_charge_id'] != $chargeId);
        }

        return $results;
    }

    /**
     * Verknüpft einen Kassenbon mit einem Ladevorgang und übernimmt dessen Betrag.
     */
    public function linkReceiptToCharge(int $chargeId, int $receiptId): bool
    {
        $stmt = $this->db->prepare("SELECT id, store, total FROM kb_receipts WHERE id = :id");
        $stmt->execute([':id' => $receiptId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$receipt) {
            return false;
        }

        $totalEur = (float)$receipt['total'];
        $store = (string)$receipt['store'];

        $updateStmt = $this->db->prepare("
            UPDATE vehicle_charges SET
                receipt_id = :receipt_id,
                cost_eur = :cost_eur,
                tariff_category = :store
            WHERE id = :charge_id
        ");

        $success = $updateStmt->execute([
            ':receipt_id' => $receiptId,
            ':cost_eur' => $totalEur,
            ':store' => $store,
            ':charge_id' => $chargeId,
        ]);

        if ($success) {
            $this->logger->info("ChargingReceiptService: Ladevorgang #{$chargeId} mit E-Bon #{$receiptId} ({$store}, {$totalEur} €) verknüpft.");
        }

        return $success;
    }

    /**
     * Hebt die Verknüpfung zu einem Beleg auf.
     */
    public function unlinkReceipt(int $chargeId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE vehicle_charges SET
                receipt_id = NULL
            WHERE id = :charge_id
        ");

        return $stmt->execute([':charge_id' => $chargeId]);
    }
}
