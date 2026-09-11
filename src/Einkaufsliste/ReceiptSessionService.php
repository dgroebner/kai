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

        // Alle Artikel des Artikelstamms laden für schnelle Zuordnung und Umbenennungen
        $masterById = [];
        $masterByName = [];
        try {
            $allMastersStmt = $this->pdo->query("SELECT id, name, custom_label FROM product_master");
            foreach ($allMastersStmt->fetchAll(PDO::FETCH_ASSOC) as $pm) {
                $mId = (int)$pm['id'];
                $masterById[$mId] = $pm;
                $masterByName[mb_strtolower(trim($pm['name']), 'UTF-8')] = $mId;
                if (!empty($pm['custom_label'])) {
                    $masterByName[mb_strtolower(trim($pm['custom_label']), 'UTF-8')] = $mId;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warn("ReceiptSessionService: Konnte product_master nicht laden", ['error' => $e->getMessage()]);
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

        // Mappings für Bon-Namen UND Einkaufslisten-Namen laden
        $rawReceiptNames = array_unique(array_column($receiptItems, 'name'));
        $rawSessionNames = array_unique(array_map(static fn($si) => trim($si['name']), $sessionItems));
        $allLookupNames = array_unique(array_merge($rawReceiptNames, $rawSessionNames));

        $mappings = [];
        if (!empty($allLookupNames)) {
            $namePlaceholders = implode(',', array_fill(0, count($allLookupNames), '?'));
            $mStmt = $this->pdo->prepare("
                SELECT ebon_name, product_master_id, custom_label, pm.name AS master_name
                FROM ebon_product_mappings m
                JOIN product_master pm ON m.product_master_id = pm.id
                WHERE ebon_name IN ($namePlaceholders)
            ");
            $mStmt->execute(array_values($allLookupNames));
            foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $mappings[mb_strtolower($m['ebon_name'], 'UTF-8')] = $m;
            }
        }

        // Einkaufslisten-Positionen nachauflösen (falls gemappt oder im Master umbenannt)
        foreach ($formattedSessionItems as $siId => &$fsi) {
            $siNorm = mb_strtolower(trim($fsi['name']), 'UTF-8');

            // 1. Wenn in ebon_product_mappings vorhanden (z.B. Mini-Steaks -> Minutensteaks)
            if (isset($mappings[$siNorm])) {
                $mappedMasterId = (int)$mappings[$siNorm]['product_master_id'];
                $fsi['product_id'] = $mappedMasterId;
                $fsi['display_name'] = $mappings[$siNorm]['custom_label'] ?: $mappings[$siNorm]['master_name'];

                // DB-Eintrag heilen falls nötig
                try {
                    $upStmt = $this->pdo->prepare("UPDATE shopping_session_items SET product_id = :pid WHERE id = :id AND (product_id IS NULL OR product_id != :pid2)");
                    $upStmt->execute([':pid' => $mappedMasterId, ':id' => $siId, ':pid2' => $mappedMasterId]);
                } catch (\Throwable $e) {}
            }
            // 2. Wenn product_id fehlt, aber Name im Master existiert
            elseif (empty($fsi['product_id']) && isset($masterByName[$siNorm])) {
                $foundId = $masterByName[$siNorm];
                $fsi['product_id'] = $foundId;
                $fsi['display_name'] = $masterById[$foundId]['custom_label'] ?: $masterById[$foundId]['name'];
            }
            // 3. Wenn product_id veraltet/ungültig ist, Name aus masterById auffrischen
            elseif (!empty($fsi['product_id']) && isset($masterById[$fsi['product_id']])) {
                $fsi['display_name'] = $masterById[$fsi['product_id']]['custom_label'] ?: $masterById[$fsi['product_id']]['name'];
            }
        }
        unset($fsi);

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

            $mappedMasterId = null;
            $mappedMasterName = null;

            // Mapping für diese Bon-Position ermitteln
            if (isset($mappings[$normRaw])) {
                $mappedMasterId = (int)$mappings[$normRaw]['product_master_id'];
                $mappedMasterName = $mappings[$normRaw]['custom_label'] ?: $mappings[$normRaw]['master_name'];
            } elseif (isset($masterByName[$normRaw])) {
                $mappedMasterId = $masterByName[$normRaw];
                $mappedMasterName = $masterById[$mappedMasterId]['custom_label'] ?: $masterById[$mappedMasterId]['name'];
            }

            $matchedSessionItemId = null;
            $matchedDisplayName = null;

            // Stufe 1: Direkter Match über product_master_id
            if ($mappedMasterId !== null) {
                foreach ($formattedSessionItems as $siId => $si) {
                    if ($si['product_id'] !== null && $si['product_id'] === $mappedMasterId) {
                        $matchedSessionItemId = $siId;
                        $matchedDisplayName = $mappedMasterName ?: $si['display_name'];
                        break;
                    }
                }
            }

            // Stufe 2: Match über Master-Namen (z.B. wenn Einkaufsliste den Master-Namen trägt)
            if ($matchedSessionItemId === null && $mappedMasterName !== null) {
                $normMappedMaster = mb_strtolower(trim($mappedMasterName), 'UTF-8');
                foreach ($formattedSessionItems as $siId => $si) {
                    $siNorm = mb_strtolower(trim($si['name']), 'UTF-8');
                    $siDisplayNorm = mb_strtolower(trim($si['display_name']), 'UTF-8');
                    if ($normMappedMaster === $siNorm || $normMappedMaster === $siDisplayNorm) {
                        $matchedSessionItemId = $siId;
                        $matchedDisplayName = $mappedMasterName;
                        break;
                    }
                }
            }

            // Stufe 3: Exakter Namensabgleich zwischen Kassenbon-Position und Einkaufsliste
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

            // Stufe 4: Teilstring-Abgleich (Fuzzy, mind. 3 Zeichen)
            if ($matchedSessionItemId === null) {
                foreach ($formattedSessionItems as $siId => $si) {
                    $siNorm = mb_strtolower(trim($si['name']), 'UTF-8');
                    $siDisplayNorm = mb_strtolower(trim($si['display_name']), 'UTF-8');
                    if (mb_strlen($siNorm, 'UTF-8') >= 3 && (str_contains($normRaw, $siNorm) || str_contains($siNorm, $normRaw))) {
                        $matchedSessionItemId = $siId;
                        $matchedDisplayName = $si['display_name'];
                        break;
                    }
                    if ($mappedMasterName !== null) {
                        $normMappedMaster = mb_strtolower(trim($mappedMasterName), 'UTF-8');
                        if (mb_strlen($siNorm, 'UTF-8') >= 3 && (str_contains($normMappedMaster, $siNorm) || str_contains($siNorm, $normMappedMaster))) {
                            $matchedSessionItemId = $siId;
                            $matchedDisplayName = $mappedMasterName;
                            break;
                        }
                    }
                }
            }

            $isPlanned = ($matchedSessionItemId !== null);
            $effectiveDisplayName = $matchedDisplayName ?: ($mappedMasterName ?: $rawName);
            $hasLearnedName = ($mappedMasterName !== null && mb_strtolower($mappedMasterName, 'UTF-8') !== $normRaw);

            $entry = [
                'id' => (int)$item['id'],
                'receipt_id' => (int)$item['receipt_id'],
                'name' => $rawName,
                'display_name' => $effectiveDisplayName,
                'master_name' => $mappedMasterName,
                'has_learned_name' => $hasLearnedName,
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
                    'display_name' => $effectiveDisplayName,
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
