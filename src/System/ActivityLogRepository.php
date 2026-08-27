<?php

namespace Kai\Tools\System;

use Exception;
use Kai\Tools\Shared\Db\Database;
use PDO;

class ActivityLogRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Holt die Gesamtzahl aller Log-Einträge
     */
    public function getTotalCount(): int
    {
        $dbCon = $this->db->getConnection();
        try {
            $stmt = $dbCon->query("SELECT COUNT(*) FROM activity_log");
            return (int)$stmt->fetchColumn();
        } catch (Exception) {
            return 0;
        }
    }

    /**
     * Holt Aktivitäts-Einträge paginiert
     */
    public function getLatestActivities(int $limit = 20, int $offset = 0): array
    {
        $dbCon = $this->db->getConnection();

        try {
            $stmt = $dbCon->prepare("
                SELECT id, event_type, message, link_url, is_read, created_at 
                FROM activity_log 
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception) {
            return [];
        }
    }

    /**
     * Holt alle Log-Einträge, deren ID größer ist als die angegebene ID (für Live-Polling)
     */
    public function getEntriesAfter(int $lastId): array
    {
        $dbCon = $this->db->getConnection();

        try {
            $stmt = $dbCon->prepare("
                SELECT id, event_type, message, link_url, is_read, created_at 
                FROM activity_log 
                WHERE id > :last_id 
                ORDER BY created_at ASC
            ");
            $stmt->bindValue(':last_id', $lastId, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception) {
            return [];
        }
    }
}