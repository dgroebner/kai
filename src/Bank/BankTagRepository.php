<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Datenbankzugriffe für Tags (`bank_tags`) und deren Verknüpfung
 * mit Girokonto-Umsätzen (`bank_transaction_tags`).
 */
class BankTagRepository
{
    /**
     * Tags, die nicht in die Zeitraum-Statistik einfließen sollen.
     * ID 17 = „Umbuchungen" (Übertrag zwischen eigenen Konten, kein echter Umsatz).
     */
    private const STATISTICS_EXCLUDED_TAG_IDS = [17];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Lädt alle Tags für Popover und Auto-Suggest.
     */
    public function getAllTags(): array
    {
        $stmt = $this->pdo->query("SELECT id, name, color FROM bank_tags ORDER BY name ASC");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ermittelt Häufigkeit und Summe je Tag für einen Zeitraum.
     * Ausgeschlossene Tags (siehe STATISTICS_EXCLUDED_TAG_IDS) bleiben unberücksichtigt.
     */
    public function getTagStatistics(int $accountId, string $startDate, string $endDate): array
    {
        $excludedIds = self::STATISTICS_EXCLUDED_TAG_IDS;
        $placeholders = implode(',', array_fill(0, count($excludedIds), '?'));

        $stmt = $this->pdo->prepare("
            SELECT
                t.id, t.name, t.color,
                COUNT(DISTINCT bt.id) AS tx_count,
                SUM(bt.amount) AS total_amount
            FROM bank_tags t
            JOIN bank_transaction_tags tt ON t.id = tt.tag_id
            JOIN bank_giro_transactions bt ON tt.transaction_id = bt.id AND bt.account_id = ?
            WHERE bt.booking_date BETWEEN ? AND ?
              AND t.id NOT IN ($placeholders)
            GROUP BY t.id, t.name, t.color
            ORDER BY tx_count DESC, t.name ASC
        ");
        $stmt->execute(array_merge([$accountId, $startDate, $endDate], $excludedIds));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Aktualisiert Name und Farbe eines Tags.
     */
    public function updateTag(int $tagId, string $name, ?string $color): void
    {
        $stmt = $this->pdo->prepare("UPDATE bank_tags SET name = :name, color = :color WHERE id = :id");
        $stmt->execute([':name' => $name, ':color' => $color, ':id' => $tagId]);
    }

    /**
     * Legt ein Tag an oder liefert die ID des bereits vorhandenen Tags gleichen Namens.
     */
    public function findOrCreateTag(string $name, ?string $color): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO bank_tags (name, color)
            VALUES (:name, :color)
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
        ");
        $stmt->execute([':name' => $name, ':color' => $color]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Verknüpft ein Tag mit einem Umsatz (doppelte Zuweisungen werden ignoriert).
     */
    public function assignTagToTransaction(int $transactionId, int $tagId): void
    {
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO bank_transaction_tags (transaction_id, tag_id)
            VALUES (:tx_id, :tag_id)
        ");
        $stmt->execute([':tx_id' => $transactionId, ':tag_id' => $tagId]);
    }

    /**
     * Ersetzt sämtliche Tags eines Umsatzes durch die übergebene Liste.
     *
     * @param int[] $tagIds
     */
    public function replaceTagsOfTransaction(int $transactionId, array $tagIds): void
    {
        $this->removeAllTagsFromTransaction($transactionId);

        foreach ($tagIds as $tagId) {
            $this->assignTagToTransaction($transactionId, (int)$tagId);
        }
    }

    /**
     * Entfernt eine einzelne Tag-Zuweisung von einem Umsatz.
     */
    public function removeTagFromTransaction(int $transactionId, int $tagId): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM bank_transaction_tags
            WHERE transaction_id = :tx_id AND tag_id = :tag_id
        ");
        $stmt->execute([':tx_id' => $transactionId, ':tag_id' => $tagId]);
    }

    /**
     * Entfernt alle Tag-Zuweisungen eines Umsatzes.
     */
    public function removeAllTagsFromTransaction(int $transactionId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM bank_transaction_tags WHERE transaction_id = :tx_id");
        $stmt->execute([':tx_id' => $transactionId]);
    }

    /**
     * Entfernt alle Tag-Zuweisungen mehrerer Umsätze.
     *
     * @param int[] $transactionIds
     */
    public function removeAllTagsFromTransactions(array $transactionIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $transactionIds)));
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM bank_transaction_tags WHERE transaction_id IN ($placeholders)");
        $stmt->execute($ids);
    }
}
