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

        $startDate = date('Y-m-d', strtotime($session['started_at']));
        $endDate = !empty($session['completed_at'])
            ? date('Y-m-d', strtotime($session['completed_at']))
            : (!empty($session['updated_at']) ? date('Y-m-d', strtotime($session['updated_at'])) : date('Y-m-d'));

        $minDate = min($startDate, $endDate);
        $maxDate = max($startDate, $endDate);

        // Primary search: Bons im Fenster von -7 Tagen vor Start bis +7 Tage nach Ende/heute suchen
        $stmt = $this->pdo->prepare("
            SELECT r.*, 
                   COUNT(i.id) AS item_count,
                   (CASE WHEN r.shopping_session_id = :session_id THEN 1 ELSE 0 END) AS is_currently_linked
            FROM kb_receipts r
            LEFT JOIN kb_items i ON r.id = i.receipt_id
            WHERE (r.shopping_session_id IS NULL OR r.shopping_session_id = :session_id_filter)
              AND r.purchase_date BETWEEN DATE_SUB(:min_date, INTERVAL 7 DAY) AND DATE_ADD(:max_date, INTERVAL 7 DAY)
            GROUP BY r.id
            ORDER BY is_currently_linked DESC, r.purchase_date DESC, r.id DESC
        ");

        $stmt->execute([
            ':session_id' => $sessionId,
            ':session_id_filter' => $sessionId,
            ':min_date' => $minDate,
            ':max_date' => $maxDate,
        ]);

        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: Falls im 7-Tage-Fenster keine unverknüpften Bons gefunden wurden,
        // weiten wir die Suche auf alle unverknüpften Bons der letzten 60 Tage aus.
        $unlinkedCount = count(array_filter($candidates, static fn($c) => (int)$c['is_currently_linked'] === 0));
        if ($unlinkedCount === 0) {
            $fallbackStmt = $this->pdo->prepare("
                SELECT r.*, 
                       COUNT(i.id) AS item_count,
                       (CASE WHEN r.shopping_session_id = :session_id THEN 1 ELSE 0 END) AS is_currently_linked
                FROM kb_receipts r
                LEFT JOIN kb_items i ON r.id = i.receipt_id
                WHERE (r.shopping_session_id IS NULL OR r.shopping_session_id = :session_id_filter)
                  AND r.purchase_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                GROUP BY r.id
                ORDER BY is_currently_linked DESC, r.purchase_date DESC, r.id DESC
                LIMIT 30
            ");
            $fallbackStmt->execute([
                ':session_id' => $sessionId,
                ':session_id_filter' => $sessionId,
            ]);
            $candidates = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $candidates;
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

        // Basis-Liste aller Einkaufslisten-Artikel vorbereiten
        $formattedSessionItems = [];
        foreach ($sessionItems as $si) {
            $siId = (int)$si['id'];
            $formattedSessionItems[$siId] = [
                'id' => $siId,
                'product_id' => !empty($si['product_id']) ? (int)$si['product_id'] : null,
                'name' => $si['name'],
                'display_name' => $si['master_display_name'] ?: $si['name'],
                'quantity' => (float)$si['quantity'],
                'unit' => $si['unit'] ?? 'Stück',
                'market' => $si['market'] ?? 'Rewe',
                'category' => $si['category'] ?? 'Sonstiges',
                'is_spontaneous' => !empty($si['is_spontaneous']),
                'is_matched' => false,
                'matches' => [],
                'matched_cost' => 0.00,
            ];
        }

        // Falls noch kein Kassenbon verknüpft ist, Liste der Artikel trotzdem zurückgeben
        if (empty($linkedReceipts)) {
            return [
                'session' => $session,
                'receipt_count' => 0,
                'linked_receipts' => [],
                'session_items_count' => count($sessionItems),
                'session_items' => array_values($formattedSessionItems),
                'matched_session_items_count' => 0,
                'total_items_count' => 0,
                'total_cost' => 0.00,
                'planned_cost' => 0.00,
                'spontaneous_cost' => 0.00,
                'spontaneous_pct_cost' => 0.0,
                'spontaneous_pct_count' => 0.0,
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

            $matchedSessionItemId = null;
            $matchedDisplayName = null;

            // Check 1: Über gespeichertes eBon-Mapping (product_master_id)
            if (isset($mappings[$normRaw])) {
                $masterId = (int)$mappings[$normRaw]['product_master_id'];
                $mappedName = $mappings[$normRaw]['custom_label'] ?: $mappings[$normRaw]['master_name'];
                foreach ($formattedSessionItems as $siId => $si) {
                    if ($si['product_id'] === $masterId) {
                        $matchedSessionItemId = $siId;
                        $matchedDisplayName = $mappedName;
                        break;
                    }
                }
            }

            // Check 2: Exakter Namensabgleich (oder Display-Name)
            if ($matchedSessionItemId === null) {
                foreach ($formattedSessionItems as $siId => $si) {
                    $siNorm = mb_strtolower(trim($si['name']), 'UTF-8');
                    $siDisplayNorm = mb_strtolower(trim($si['display_name']), 'UTF-8');
                    if ($normRaw === $siNorm || $normRaw === $siDisplayNorm) {
                        $matchedSessionItemId = $siId;
                        $matchedDisplayName = $si['display_name'];
                        break;
                    }
                }
            }

            // Check 3: Teilstring-Abgleich (Fuzzy)
            if ($matchedSessionItemId === null) {
                foreach ($formattedSessionItems as $siId => $si) {
                    $siNorm = mb_strtolower(trim($si['name']), 'UTF-8');
                    if (mb_strlen($siNorm, 'UTF-8') >= 3 && (str_contains($normRaw, $siNorm) || str_contains($siNorm, $normRaw))) {
                        $matchedSessionItemId = $siId;
                        $matchedDisplayName = $si['display_name'];
                        break;
                    }
                }
            }

            $isPlanned = ($matchedSessionItemId !== null);
            $entry = [
                'id' => (int)$item['id'],
                'receipt_id' => (int)$item['receipt_id'],
                'name' => $rawName,
                'display_name' => $matchedDisplayName ?: $rawName,
                'store' => $item['store'],
                'category' => $item['category'],
                'quantity' => (float)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
                'total_price' => $cost,
                'is_planned' => $isPlanned,
                'matched_session_item_id' => $matchedSessionItemId,
            ];

            if ($isPlanned) {
                $plannedCost += $cost;
                $plannedItems[] = $entry;

                // Zugehörigen Einkaufslisten-Artikel als gematcht markieren
                $formattedSessionItems[$matchedSessionItemId]['is_matched'] = true;
                $formattedSessionItems[$matchedSessionItemId]['matches'][] = [
                    'receipt_item_id' => (int)$item['id'],
                    'receipt_id' => (int)$item['receipt_id'],
                    'name' => $rawName,
                    'price' => $cost,
                    'store' => $item['store'],
                ];
                $formattedSessionItems[$matchedSessionItemId]['matched_cost'] += $cost;
            } else {
                $spontaneousCost += $cost;
                $spontaneousItems[] = $entry;
            }

            $processedItems[] = $entry;
        }

        $matchedSessionItemsCount = count(array_filter($formattedSessionItems, static fn($si) => $si['is_matched']));
        $spontaneousPctCost = $totalCost > 0 ? round(($spontaneousCost / $totalCost) * 100, 1) : 0.0;
        $spontaneousPctCount = count($receiptItems) > 0 ? round((count($spontaneousItems) / count($receiptItems)) * 100, 1) : 0.0;

        return [
            'session' => $session,
            'receipt_count' => count($linkedReceipts),
            'linked_receipts' => $linkedReceipts,
            'session_items_count' => count($sessionItems),
            'session_items' => array_values($formattedSessionItems),
            'matched_session_items_count' => $matchedSessionItemsCount,
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
