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
            $chargingKeywords = [
                'stadtwerke leipzig', 'leipziger stadtwerke', 'l-charge',
                'vattenfall', 'incharge',
                'enbw', 'mobility+',
                'aral', 'pulse', 'adac',
                'ionity', 'ewe', 'shell', 'tesla', 'fastned', 'allego', 'maingau', 'ladenetz'
            ];
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

        if (!empty($results)) {
            $receiptIds = array_column($results, 'id');
            $placeholders = implode(',', array_fill(0, count($receiptIds), '?'));
            $itemStmt = $this->db->prepare("SELECT id, receipt_id, name, quantity, unit_price, total_price FROM kb_items WHERE receipt_id IN ($placeholders) ORDER BY id ASC");
            $itemStmt->execute($receiptIds);
            $itemsByReceipt = [];
            while ($item = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
                $itemsByReceipt[$item['receipt_id']][] = $item;
            }

            $chargeKwh = (float)($charge['charged_net_kwh'] ?? 0);

            foreach ($results as &$r) {
                $r['items'] = $itemsByReceipt[$r['id']] ?? [];
                foreach ($r['items'] as &$it) {
                    $qty = (float)$it['quantity'];
                    // Ladeverlust-Toleranz: Säule misst Brutto (mehr), Auto misst Netto (weniger)
                    $diff = $qty - $chargeKwh;
                    $kwhMatch = ($chargeKwh > 0 && $qty > 0 && $diff >= -0.5 && $diff <= max(2.5, $qty * 0.18));
                    $it['is_kwh_match'] = $kwhMatch;
                }
                unset($it);
            }
            unset($r);
        }

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
     * Verknüpft einen Kassenbon mit einem Ladevorgang und übernimmt dessen Betrag (oder Teilbetrag).
     */
    public function linkReceiptToCharge(int $chargeId, int $receiptId, ?float $customCost = null, ?string $customNote = null, ?int $receiptItemId = null): bool
    {
        $stmt = $this->db->prepare("SELECT id, store, total FROM kb_receipts WHERE id = :id");
        $stmt->execute([':id' => $receiptId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$receipt) {
            return false;
        }

        $totalEur = ($customCost !== null && $customCost > 0) ? $customCost : (float)$receipt['total'];
        $store = $customNote ?: (string)$receipt['store'];

        // Falls keine konkrete Position übergeben wurde: Wenn der Beleg nur 1 Position hat, diese automatisch nutzen
        if ($receiptItemId === null) {
            $itemIdsStmt = $this->db->prepare("SELECT id FROM kb_items WHERE receipt_id = :id");
            $itemIdsStmt->execute([':id' => $receiptId]);
            $allItemIds = $itemIdsStmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($allItemIds) === 1) {
                $receiptItemId = (int)$allItemIds[0];
            }
        }

        // Ladeverlust berechnen, falls Zähler-Menge vorhanden ist
        $chargeStmt = $this->db->prepare("SELECT charged_net_kwh FROM vehicle_charges WHERE id = :id");
        $chargeStmt->execute([':id' => $chargeId]);
        $netKwh = (float)$chargeStmt->fetchColumn();

        $grossKwh = null;
        if ($receiptItemId !== null) {
            $itStmt = $this->db->prepare("SELECT quantity FROM kb_items WHERE id = :item_id");
            $itStmt->execute([':item_id' => $receiptItemId]);
            $foundQty = (float)$itStmt->fetchColumn();
            if ($foundQty > $netKwh) {
                $grossKwh = $foundQty;
            }
        } else {
            $itStmt = $this->db->prepare("SELECT quantity FROM kb_items WHERE receipt_id = :id AND quantity > :net ORDER BY quantity ASC LIMIT 1");
            $itStmt->execute([':id' => $receiptId, ':net' => max(0.1, $netKwh - 0.5)]);
            $foundQty = (float)$itStmt->fetchColumn();
            if ($foundQty > $netKwh) {
                $grossKwh = $foundQty;
            }
        }

        $lossSql = "";
        $lossParams = [];
        if ($grossKwh !== null && $netKwh > 0 && $grossKwh >= $netKwh) {
            $lossKwh = round($grossKwh - $netKwh, 2);
            $lossPct = round(($lossKwh / $grossKwh) * 100, 1);
            $lossSql = ", loss_kwh = :loss_kwh, loss_pct = :loss_pct";
            $lossParams[':loss_kwh'] = $lossKwh;
            $lossParams[':loss_pct'] = $lossPct;
        }

        $updateStmt = $this->db->prepare("
            UPDATE vehicle_charges SET
                receipt_id = :receipt_id,
                receipt_item_id = :receipt_item_id,
                cost_eur = :cost_eur,
                tariff_category = :store
                {$lossSql}
            WHERE id = :charge_id
        ");

        $params = array_merge([
            ':receipt_id' => $receiptId,
            ':receipt_item_id' => $receiptItemId,
            ':cost_eur' => $totalEur,
            ':store' => $store,
            ':charge_id' => $chargeId,
        ], $lossParams);

        $success = $updateStmt->execute($params);

        if ($success) {
            $this->logger->info("ChargingReceiptService: Ladevorgang #{$chargeId} mit E-Bon #{$receiptId} (Position #" . ($receiptItemId ?: '–') . ", {$store}, {$totalEur} €) verknüpft.");
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
                receipt_id = NULL,
                receipt_item_id = NULL
            WHERE id = :charge_id
        ");

        return $stmt->execute([':charge_id' => $chargeId]);
    }

    /**
     * Versucht, einen neu importierten Beleg automatisch einem offenen Ladevorgang zuzuordnen
     * (unterstützt Einzelbelege und Monatsabrechnungen mit Einzelladungen).
     */
    public function autoMatchReceipt(int $receiptId): ?int
    {
        $stmt = $this->db->prepare("SELECT id, store, purchase_date, total FROM kb_receipts WHERE id = :id");
        $stmt->execute([':id' => $receiptId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$receipt) {
            return null;
        }

        $store = (string)$receipt['store'];
        $purchaseDate = (string)$receipt['purchase_date'];

        // Bekannte Ladeanbieter
        $chargingKeywords = [
            'stadtwerke leipzig', 'leipziger stadtwerke', 'l-charge',
            'vattenfall', 'incharge', 'enbw', 'mobility+',
            'aral', 'pulse', 'adac', 'ionity', 'ewe', 'shell', 'tesla', 'fastned', 'allego'
        ];

        $isChargingReceipt = false;
        $storeLower = mb_strtolower($store);
        foreach ($chargingKeywords as $kw) {
            if (str_contains($storeLower, $kw)) {
                $isChargingReceipt = true;
                break;
            }
        }

        // 1. Prüfe kb_items auf Einzelladevorgänge (z.B. bei Monatsabrechnungen wie EnBW)
        $itemStmt = $this->db->prepare("SELECT id, name, quantity, total_price FROM kb_items WHERE receipt_id = :id");
        $itemStmt->execute([':id' => $receiptId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$isChargingReceipt && !empty($items)) {
            foreach ($items as $it) {
                $nLower = mb_strtolower((string)$it['name']);
                if (str_contains($nLower, 'kwh') || str_contains($nLower, 'ladung') || str_contains($nLower, 'ladekarte')) {
                    $isChargingReceipt = true;
                    break;
                }
            }
        }

        if (!$isChargingReceipt) {
            return null;
        }

        // Falls Posten vorhanden sind: Versuche jeden Posten mit Einzelladungen abzugleichen
        if (!empty($items)) {
            $anyMatched = false;
            foreach ($items as $it) {
                $name = (string)$it['name'];
                $qty = (float)$it['quantity'];
                $price = (float)$it['total_price'];

                if ($price <= 0) {
                    continue;
                }

                // Datum aus Posten extrahieren falls vorhanden (z. B. 20.09.2026)
                $extractedDate = null;
                if (preg_match('/\b(\d{2})\.(\d{2})\.(\d{4})\b/', $name, $dm)) {
                    $extractedDate = "{$dm[3]}-{$dm[2]}-{$dm[1]}";
                } elseif (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $name, $dm)) {
                    $extractedDate = "{$dm[1]}-{$dm[2]}-{$dm[3]}";
                }

                $query = "
                    SELECT id FROM vehicle_charges
                    WHERE location_type != 'HOME'
                      AND receipt_id IS NULL
                ";
                $queryParams = [];

                if ($extractedDate) {
                    $query .= " AND ABS(DATEDIFF(start_time, :edate)) <= 1";
                    $queryParams[':edate'] = $extractedDate;
                } else {
                    $query .= " AND start_time >= DATE_SUB(:rdate, INTERVAL 35 DAY) AND start_time <= :rdate2";
                    $queryParams[':rdate'] = $purchaseDate;
                    $queryParams[':rdate2'] = $purchaseDate;
                }

                if ($qty > 0) {
                    $query .= " AND charged_net_kwh <= :qty_max AND charged_net_kwh >= :qty_min";
                    $queryParams[':qty_max'] = $qty + 0.5;
                    $queryParams[':qty_min'] = max(0.5, $qty * 0.78);
                }

                $query .= " ORDER BY start_time DESC LIMIT 1";

                $matchStmt = $this->db->prepare($query);
                $matchStmt->execute($queryParams);
                $chargeId = $matchStmt->fetchColumn();

                if ($chargeId) {
                    $this->linkReceiptToCharge((int)$chargeId, $receiptId, $price, $store, (int)$it['id']);
                    $anyMatched = true;
                }
            }

            if ($anyMatched) {
                return $receiptId;
            }
        }

        // Fallback für Einzelbelege ohne aufgeteilte Items: Suche nach offenen Ladevorgängen am selben Tag (+/- 1 Tag)
        $chargeStmt = $this->db->prepare("
            SELECT id, station_operator, charged_net_kwh, start_time
            FROM vehicle_charges
            WHERE location_type != 'HOME'
              AND receipt_id IS NULL
              AND ABS(DATEDIFF(start_time, :pdate)) <= 1
            ORDER BY 
              CASE WHEN DATE(start_time) = :pdate2 THEN 1 ELSE 2 END ASC,
              start_time DESC
        ");
        $chargeStmt->execute([
            ':pdate' => $purchaseDate,
            ':pdate2' => $purchaseDate,
        ]);
        $charges = $chargeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($charges)) {
            return null;
        }

        $matchedChargeId = null;
        if (count($charges) === 1) {
            $matchedChargeId = (int)$charges[0]['id'];
        } else {
            foreach ($charges as $c) {
                if (!empty($c['station_operator']) && str_contains(mb_strtolower($c['station_operator']), $storeLower)) {
                    $matchedChargeId = (int)$c['id'];
                    break;
                }
            }
        }

        if ($matchedChargeId !== null) {
            $this->linkReceiptToCharge($matchedChargeId, $receiptId);
            $this->logger->info("ChargingReceiptService: Automatischer Match! Ladevorgang #{$matchedChargeId} mit Ladebeleg #{$receiptId} ({$store}, {$receipt['total']} €) verknüpft.");
            return $matchedChargeId;
        }

        return null;
    }
}
