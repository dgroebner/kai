<?php

namespace Kai\Tools\System;

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
     * Holt die neuesten Aktivitäts-Einträge für das Dashboard
     *
     * @param int $limit
     * @return array
     */
    public function getLatestActivities(int $limit = 8): array
    {
        $dbCon = $this->db->getConnection();
        
        try {
            $stmt = $dbCon->prepare("
                SELECT id, event_type, message, link_url, is_read, created_at 
                FROM activity_log 
                ORDER BY created_at DESC 
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }
}