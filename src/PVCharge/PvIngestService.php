<?php

namespace Kai\Tools\PVCharge;

use Kai\Tools\Shared\Db\Database;
use PDO;

class PvIngestService
{
    private Database $db;
    private PDO $dbCon;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->dbCon = $this->db->getConnection();
    }


    /**
     * @param array $columns
     * @param mixed $values
     * @return void
     */
    public function insertTelemetryData(array $columns, mixed $values): void
    {
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $colNames = implode(', ', $columns);

        $sql = "INSERT INTO pv_telemetry ($colNames) VALUES ($placeholders)";
        $stmt = $this->dbCon->prepare($sql);
        $stmt->execute($values);
    }

    /**
     * @param array $columns
     * @param mixed $values
     * @return void
     */
    public function upsertLiveData(array $columns, mixed $values): void
    {
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $colNames = implode(', ', $columns);

        $updateStmt = implode(', ', array_map(fn($col) => "$col=VALUES($col)", $columns));

        $sql = "INSERT INTO pv_live (id, $colNames) VALUES (1, $placeholders) ON DUPLICATE KEY UPDATE $updateStmt";
        $stmt = $this->dbCon->prepare($sql);

        $stmt->execute($values);
    }
}
