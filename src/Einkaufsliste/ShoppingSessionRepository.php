<?php

namespace Kai\Tools\Einkaufsliste;

use Exception;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Log\Logger;
use PDO;

/**
 * Repository zur Verwaltung von Einkaufs-Sessions (Wocheneinkauf vs. Spontaneinkauf)
 * und der Historisierung tatsächlich gekaufter Artikel (Audit-Trail für E-Bon-Matching).
 */
class ShoppingSessionRepository
{
    private PDO $pdo;
    private Logger $logger;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->logger = new Logger();
    }

    /**
     * Ermittelt die aktuell aktive Einkaufs-Session, falls vorhanden.
     *
     * @return array<string, mixed>|null
     */
    public function getActiveSession(): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM shopping_sessions 
            WHERE status = 'active' 
            ORDER BY started_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Statistik der aktuellen Liste für diesen Session-Typ anreichern
        $typeFilter = $row['session_type'] === 'spontaneinkauf' ? 1 : null;
        
        $sql = "
            SELECT 
                COUNT(*) AS total_count,
                SUM(CASE WHEN is_checked = 1 THEN 1 ELSE 0 END) AS checked_count,
                SUM(CASE WHEN is_checked = 0 THEN 1 ELSE 0 END) AS open_count
            FROM shopping_list_items
        ";
        $params = [];
        if ($typeFilter !== null) {
            $sql .= " WHERE is_spontaneous = :is_spontaneous";
            $params[':is_spontaneous'] = $typeFilter;
        }

        $countStmt = $this->pdo->prepare($sql);
        $countStmt->execute($params);
        $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_count' => 0, 'checked_count' => 0, 'open_count' => 0];

        $row['total_count'] = (int)($counts['total_count'] ?? 0);
        $row['checked_count'] = (int)($counts['checked_count'] ?? 0);
        $row['open_count'] = (int)($counts['open_count'] ?? 0);

        return $row;
    }

    /**
     * Startet eine neue Einkaufs-Session.
     * Falls bereits eine aktive Session existiert, wird deren ID zurückgegeben (keine Duplikate).
     *
     * @param string $sessionType 'wocheneinkauf' oder 'spontaneinkauf'
     * @param string|null $notes
     * @return int Session-ID
     */
    public function startSession(string $sessionType = 'wocheneinkauf', ?string $notes = null): int
    {
        $active = $this->getActiveSession();
        if ($active !== null) {
            return (int)$active['id'];
        }

        $allowedTypes = ['wocheneinkauf', 'spontaneinkauf'];
        if (!in_array($sessionType, $allowedTypes, true)) {
            $sessionType = 'wocheneinkauf';
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO shopping_sessions (session_type, status, started_at, notes, created_at)
            VALUES (:session_type, 'active', NOW(), :notes, NOW())
        ");
        $stmt->execute([
            ':session_type' => $sessionType,
            ':notes' => $notes !== null ? trim($notes) : null,
        ]);

        $sessionId = (int)$this->pdo->lastInsertId();

        $this->logger->info("ShoppingSessionRepository: Neue Einkaufs-Session gestartet.", [
            'session_id' => $sessionId,
            'type' => $sessionType,
        ]);

        return $sessionId;
    }

    /**
     * Bricht eine aktive Session ab (ohne Artikel zu löschen).
     *
     * @param int|null $sessionId
     * @return bool
     */
    public function cancelSession(?int $sessionId = null): bool
    {
        if ($sessionId !== null && $sessionId > 0) {
            $stmt = $this->pdo->prepare("
                UPDATE shopping_sessions 
                SET status = 'cancelled', updated_at = NOW() 
                WHERE id = :id OR status = 'active'
            ");
            $success = $stmt->execute([':id' => $sessionId]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE shopping_sessions 
                SET status = 'cancelled', updated_at = NOW() 
                WHERE status = 'active'
            ");
            $success = $stmt->execute();
        }

        if ($success) {
            $this->logger->info("ShoppingSessionRepository: Einkaufs-Session abgebrochen.", [
                'session_id' => $sessionId,
            ]);
        }

        return $success;
    }

    /**
     * Schließt einen Einkauf ab ("Checkout"):
     * 1. Holt alle während der Session abgehakten Artikel.
     * 2. Überführt sie in die Historie (shopping_session_items).
     * 3. Aktualisiert Verbrauchsintervalle & Kaufdaten im Artikelstamm.
     * 4. Löscht die abgehakten Artikel unwiderruflich von der aktiven Liste.
     * 5. Setzt die Session auf status = 'completed'.
     *
     * @param int $sessionId
     * @param string|null $marketFilter Optional 'Rewe' oder 'Globus' (Standard: alle Märkte)
     * @return array<int, array<string, mixed>> Die archivierten Artikel
     * @throws Exception
     */
    public function completeSession(int $sessionId, ?string $marketFilter = null): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM shopping_sessions WHERE id = :id AND status = 'active' LIMIT 1");
        $stmt->execute([':id' => $sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            throw new Exception("Keine aktive Einkaufs-Session mit ID {$sessionId} gefunden.");
        }

        // Abgehakte Artikel ermitteln
        $sql = "SELECT * FROM shopping_list_items WHERE is_checked = 1";
        $params = [];
        if ($marketFilter !== null && $marketFilter !== '' && $marketFilter !== 'all') {
            $sql .= " AND market IN (:market, 'Übergreifend')";
            $params[':market'] = $marketFilter;
        }

        $fetchStmt = $this->pdo->prepare($sql);
        $fetchStmt->execute($params);
        $checkedItems = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->pdo->beginTransaction();
        try {
            if (!empty($checkedItems)) {
                $insertItemStmt = $this->pdo->prepare("
                    INSERT INTO shopping_session_items 
                        (session_id, product_id, name, quantity, unit, market, category, is_spontaneous, checked_at)
                    VALUES 
                        (:session_id, :product_id, :name, :quantity, :unit, :market, :category, :is_spontaneous, COALESCE(:checked_at, NOW()))
                ");

                $deleteIds = [];
                $productRepo = new ProductMasterRepository($this->pdo);
                $today = date('Y-m-d');

                foreach ($checkedItems as $item) {
                    $insertItemStmt->execute([
                        ':session_id' => $sessionId,
                        ':product_id' => !empty($item['product_id']) ? (int)$item['product_id'] : null,
                        ':name' => $item['name'],
                        ':quantity' => (float)$item['quantity'],
                        ':unit' => $item['unit'] ?? 'Stück',
                        ':market' => $item['market'] ?? 'Rewe',
                        ':category' => $item['category'] ?? 'Sonstiges',
                        ':is_spontaneous' => !empty($item['is_spontaneous']) ? 1 : 0,
                        ':checked_at' => $item['checked_at'] ?? null,
                    ]);

                    $deleteIds[] = (int)$item['id'];

                    // Verbrauchsintervall für bekannte Master-Artikel fortschreiben
                    if (!empty($item['product_id'])) {
                        $productRepo->recordPurchase((int)$item['product_id'], $today, !empty($item['is_spontaneous']));
                    }
                }

                // Abgehakte Artikel von der aktiven Liste entfernen
                $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                $deleteStmt = $this->pdo->prepare("DELETE FROM shopping_list_items WHERE id IN ($placeholders)");
                $deleteStmt->execute($deleteIds);
            }

            // Session als abgeschlossen markieren
            $updateSession = $this->pdo->prepare("
                UPDATE shopping_sessions 
                SET status = 'completed', completed_at = NOW(), updated_at = NOW() 
                WHERE id = :id
            ");
            $updateSession->execute([':id' => $sessionId]);

            $this->pdo->commit();

            // Im Aktivitätslog erfassen
            $activityLogger = new ActivityLogger(Database::getInstance());
            $marketLabel = $marketFilter ? $marketFilter : 'Alle Märkte';
            $activityLogger->logShoppingCompleted(count($checkedItems), "Session #{$sessionId} ({$session['session_type']}, {$marketLabel})");

            $this->logger->info("ShoppingSessionRepository: Session erfolgreich abgeschlossen.", [
                'session_id' => $sessionId,
                'items_archived' => count($checkedItems),
            ]);

            return $checkedItems;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error("ShoppingSessionRepository: Fehler beim Abschließen der Session.", [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Holt eine Session anhand ihrer ID.
     */
    public function getSession(int $sessionId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM shopping_sessions WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Holt alle historisierten Artikel einer Session.
     *
     * @param int $sessionId
     * @return array<int, array<string, mixed>>
     */
    public function getSessionItems(int $sessionId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT i.*, COALESCE(pm.custom_label, pm.name) AS master_display_name
            FROM shopping_session_items i
            LEFT JOIN product_master pm ON i.product_id = pm.id
            WHERE i.session_id = :session_id
            ORDER BY i.market ASC, i.category ASC, i.name ASC
        ");
        $stmt->execute([':session_id' => $sessionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Liefert die letzten abgeschlossenen Einkaufs-Sessions inklusive Zähler der Artikel und verknüpften E-Bons.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getRecentSessions(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                s.*,
                COUNT(DISTINCT i.id) AS item_count,
                COUNT(DISTINCT r.id) AS receipt_count,
                COALESCE(SUM(r.total), 0.00) AS receipts_total
            FROM shopping_sessions s
            LEFT JOIN shopping_session_items i ON s.id = i.session_id
            LEFT JOIN kb_receipts r ON s.id = r.shopping_session_id
            WHERE s.status = 'completed'
            GROUP BY s.id
            ORDER BY s.completed_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
