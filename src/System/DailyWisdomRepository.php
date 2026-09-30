<?php

namespace Kai\Tools\System;

use Kai\Tools\Shared\Db\Database;
use PDO;
use Throwable;

class DailyWisdomRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /**
     * Holt die Weisheit für ein bestimmtes Datum (Format: YYYY-MM-DD).
     */
    public function getByDate(string $date): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, wisdom_date, content, created_at 
                FROM daily_wisdoms 
                WHERE wisdom_date = :date 
                LIMIT 1
            ");
            $stmt->execute([':date' => $date]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Holt die Weisheit des heutigen Tages.
     */
    public function getToday(): ?array
    {
        return $this->getByDate(date('Y-m-d'));
    }

    /**
     * Liefert die letzten N Weisheitstexte chronologisch absteigend (für den KI-Prompt-Kontext).
     *
     * @return string[] Array von Weisheitstexten
     */
    public function getRecentWisdomTexts(int $limit = 60): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT content 
                FROM daily_wisdoms 
                ORDER BY wisdom_date DESC 
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Speichert eine neue Weisheit für ein bestimmtes Datum.
     */
    public function save(string $date, string $content): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO daily_wisdoms (wisdom_date, content, created_at)
                VALUES (:date, :content, NOW())
                ON DUPLICATE KEY UPDATE content = VALUES(content)
            ");
            return $stmt->execute([
                ':date' => $date,
                ':content' => $content,
            ]);
        } catch (Throwable) {
            return false;
        }
    }
}
