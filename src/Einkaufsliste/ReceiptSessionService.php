<?php

namespace Kai\Tools\Einkaufsliste;

use Exception;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;

/**
 * Service zur Verknüpfung von digitalen Kassenbons (E-Bons) mit Einkaufs-Sessions
 * und dem automatischen Abgleich zur Erkennung von Spontankäufen.
 */
class ReceiptSessionService
{
    private PDO $pdo;
    private Logger $logger;
    private ShoppingSessionRepository $sessionRepo;
    private EbonMappingRepository $mappingRepo;

    public function __construct(
        ?PDO $pdo = null,
        ?ShoppingSessionRepository $sessionRepo = null,
        ?EbonMappingRepository $mappingRepo = null
    ) {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->sessionRepo = $sessionRepo ?? new ShoppingSessionRepository($this->pdo);
        $this->mappingRepo = $mappingRepo ?? new EbonMappingRepository($this->pdo);
    }

    /**
     * Sucht nach Kassenbons, die zeitlich zu einer Einkaufs-Session passen (Kaufdatum im Zeitraum),
     * und noch keiner anderen Session zugeordnet sind (oder bereits dieser Session zugeordnet sind).
     *
     * @param int $sessionId
     * @return array<int, array<string, mixed>>
     */
    public function getCandidateReceipts(int $sessionId): array
    {
        $session = $this->sessionRepo->getSession($sessionId);
        if (!$session) {
            return [];
        }

        $sessionDate = date('Y-m-d', strtotime($session['started_at']));

        // Bons im Fenster von -1 Tag bis +1 Tag um das Einkaufsdatum suchen
        $stmt = $this->pdo->prepare("
            SELECT r.*, 
                   COUNT(i.id) AS item_count,
                   (r.shopping_session_id = :session_id) AS is_currently_linked
            FROM kb_receipts r
            LEFT JOIN kb_items i ON r.id = i.receipt_id
            WHERE (r.shopping_session_id IS NULL OR r.shopping_session_id = :session_id_filter)
              AND r.purchase_date BETWEEN DATE_SUB(:session_date, INTERVAL 1 DAY) AND DATE_ADD(:session_date, INTERVAL 1 DAY)
            GROUP BY r.id
            ORDER BY r.purchase_date DESC, r.id DESC
        ");

        $stmt->execute([
            ':session_id' => $sessionId,
            ':session_id_filter' => $sessionId,
            ':session_date' => $sessionDate,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Verknüpft einen Kassenbon mit einer Einkaufs-Session (n:1).
     *
     * @param int $receiptId
     * @param int $sessionId
     * @return bool
     */
    public function linkReceipt(int $receiptId, int $sessionId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE kb_receipts 
            SET shopping_session_id = :session_id 
            WHERE id = :receipt_id
        ");
        $success = $stmt->execute([
            ':session_id' => $sessionId,
            ':receipt_id' => $receiptId,
        ]);

        if ($success) {
            $this->logger->info("ReceiptSessionService: Kassenbon #{$receiptId} mit Session #{$sessionId} verknüpft.");
        }

        return $success;
    }

    /**
     * Löst die Verknüpfung eines Kassenbons zu einer Einkaufs-Session.
     *
     * @param int $receiptId
     * @return bool
     */
    public function unlinkReceipt(int $receiptId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE kb_receipts 
            SET shopping_session_id = NULL 
            WHERE id = :receipt_id
        ");
        $success = $stmt->execute([':receipt_id' => $receiptId]);

        if ($success) {
            $this->logger->info("ReceiptSessionService: Verknüpfung für Kassenbon #{$receiptId} gelöst.");
        }

        return $success;
    }

    /**
     * Holt alle mit einer Session verknüpften Kassenbons.
     *
     * @param int $sessionId
     * @return array<int, array<string, mixed>>
     */
    public function getLinkedReceipts(int $sessionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT r.*, COUNT(i.id) AS item_count
            FROM kb_receipts r
            LEFT JOIN kb_items i ON r.id = i.receipt_id
            WHERE r.shopping_session_id = :session_id
            GROUP BY r.id
            ORDER BY r.purchase_date ASC, r.id ASC
        ");
        $stmt->execute([':session_id' => $sessionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Führt den automatisierten Abgleich zwischen den Positionen der verknüpften Kassenbons (kb_items)
     * und den tatsächlich abgehakten Artikeln der Einkaufs-Session (shopping_session_items) durch.
     *
     * Klassifizierung:
     * - Geplant (Planned): Stand auf der Einkaufsliste und wurde abgehakt.
     * - Spontankauf (Spontaneous): Befindet sich auf dem Bon, stand aber NICHT auf der Liste.
     *
     * @param int $sessionId
     * @return array<string, mixed>
     */
    public function analyzeSessionPurchases(int $sessionId): array
    {
        $session = $this->sessionRepo->getSession($sessionId);
        if (!$session) {
            return ['success' => false, 'error' => 'Session nicht gefunden'];
        }

        $sessionItems = $this->sessionRepo->getSessionItems($sessionId);
        $linkedReceipts = $this->getLinkedReceipts($sessionId);

        if (empty($linkedReceipts)) {
            return [
                'session' => $session,
                'receipt_count' => 0,
                'session_items_count' => count($sessionItems),
                'total_cost' => 0.00,
                'planned_cost' => 0.00,
                'spontaneous_cost' => 0.00,
                'spontaneous_pct_cost' => 0.0,
                'items' => [],
                'spontaneous_items' => [],
                'planned_items' => [],
            ];
        }

        // Alle Bon-Positionen der verknüpften Bons laden
        $receiptIds = array_column($linkedReceipts, 'id');
        $placeholders = implode(',', array_fill(0, count($receiptIds), '?'));

        $itemsStmt = $this->pdo->prepare("
            SELECT i.*, r.store, r.purchase_date
            FROM kb_items i
            JOIN kb_receipts r ON i.receipt_id = r.id
            WHERE i.receipt_id IN ($placeholders)
            ORDER BY r.store ASC, i.id ASC
        ");
        $itemsStmt->execute($receiptIds);
        $receiptItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Mappings für Bon-Namen vorab laden zur schnellen Erkennung
        $rawNames = array_unique(array_column($receiptItems, 'name'));
        $mappings = [];
        if (!empty($rawNames)) {
            $namePlaceholders = implode(',', array_fill(0, count($rawNames), '?'));
            $mStmt = $this->pdo->prepare("
                SELECT ebon_name, product_master_id, custom_label, pm.name AS master_name
                FROM ebon_product_mappings m
                JOIN product_master pm ON m.product_master_id = pm.id
                WHERE ebon_name IN ($namePlaceholders)
            ");
            $mStmt->execute(array_values($rawNames));
            foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $mappings[mb_strtolower($m['ebon_name'], 'UTF-8')] = $m;
            }
        }

        // Indexierung der Session-Items für schnellen Lookup
        // 1. nach product_id
        // 2. nach normalisiertem Namen
        $sessionProductIds = [];
        $sessionNames = [];
        foreach ($sessionItems as $si) {
            if (!empty($si['product_id'])) {
                $sessionProductIds[(int)$si['product_id']] = true;
            }
            $sessionNames[mb_strtolower(trim($si['name']), 'UTF-8')] = true;
        }

        $totalCost = 0.00;
        $plannedCost = 0.00;
        $spontaneousCost = 0.00;

        $plannedItems = [];
        $spontaneousItems = [];
        $processedItems = [];

        foreach ($receiptItems as $item) {
            $cost = (float)($item['total_price'] ?? 0.00);
            $totalCost += $cost;

            $rawName = trim($item['name']);
            $normRaw = mb_strtolower($rawName, 'UTF-8');

            $isPlanned = false;
            $matchedMasterName = null;

            // Check 1: Über gespeichertes eBon-Mapping
            if (isset($mappings[$normRaw])) {
                $masterId = (int)$mappings[$normRaw]['product_master_id'];
                $matchedMasterName = $mappings[$normRaw]['custom_label'] ?: $mappings[$normRaw]['master_name'];
                if (isset($sessionProductIds[$masterId])) {
                    $isPlanned = true;
                }
            }

            // Check 2: Direkter Namensabgleich (Fuzzy/Exact)
            if (!$isPlanned) {
                if (isset($sessionNames[$normRaw])) {
                    $isPlanned = true;
                    $matchedMasterName = $rawName;
                } else {
                    // Prüfen ob ein Session-Item-Name im Bon-Namen enthalten ist
                    foreach ($sessionNames as $sName => $true) {
                        if (str_contains($normRaw, $sName) || str_contains($sName, $normRaw)) {
                            $isPlanned = true;
                            $matchedMasterName = $sName;
                            break;
                        }
                    }
                }
            }

            $entry = [
                'id' => (int)$item['id'],
                'receipt_id' => (int)$item['receipt_id'],
                'name' => $rawName,
                'display_name' => $matchedMasterName ?: $rawName,
                'store' => $item['store'],
                'category' => $item['category'],
                'quantity' => (float)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
                'total_price' => $cost,
                'is_planned' => $isPlanned,
            ];

            if ($isPlanned) {
                $plannedCost += $cost;
                $plannedItems[] = $entry;
            } else {
                $spontaneousCost += $cost;
                $spontaneousItems[] = $entry;
            }

            $processedItems[] = $entry;
        }

        $spontaneousPctCost = $totalCost > 0 ? round(($spontaneousCost / $totalCost) * 100, 1) : 0.0;
        $spontaneousPctCount = count($receiptItems) > 0 ? round((count($spontaneousItems) / count($receiptItems)) * 100, 1) : 0.0;

        return [
            'session' => $session,
            'receipt_count' => count($linkedReceipts),
            'linked_receipts' => $linkedReceipts,
            'total_items_count' => count($receiptItems),
            'total_cost' => round($totalCost, 2),
            'planned_cost' => round($plannedCost, 2),
            'spontaneous_cost' => round($spontaneousCost, 2),
            'spontaneous_pct_cost' => $spontaneousPctCost,
            'spontaneous_pct_count' => $spontaneousPctCount,
            'spontaneous_items' => $spontaneousItems,
            'planned_items' => $plannedItems,
        ];
    }
}
