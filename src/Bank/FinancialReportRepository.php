<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Db\Database;
use PDO;

class FinancialReportRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Lädt einen gespeicherten Finanzbericht anhand von Typ ('month'|'year') und Zielzeitraum.
     *
     * @param string $periodType
     * @param string $periodTarget
     * @return array|null
     */
    public function getReport(string $periodType, string $periodTarget): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, period_type, period_target, period_reference, aggregated_data, ai_analysis, created_at, updated_at
            FROM bank_financial_reports
            WHERE period_type = :period_type AND period_target = :period_target
            LIMIT 1
        ");
        $stmt->execute([
            ':period_type' => $periodType,
            ':period_target' => $periodTarget,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['aggregated_data'] = json_decode($row['aggregated_data'], true) ?: [];
        $row['ai_analysis'] = json_decode($row['ai_analysis'], true) ?: [];

        return $row;
    }

    /**
     * Speichert oder aktualisiert einen Finanzbericht (Upsert via ON DUPLICATE KEY UPDATE).
     *
     * @param string $periodType
     * @param string $periodTarget
     * @param string $periodReference
     * @param array $aggregatedData
     * @param array $aiAnalysis
     * @return int
     */
    public function saveReport(
        string $periodType,
        string $periodTarget,
        string $periodReference,
        array $aggregatedData,
        array $aiAnalysis
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO bank_financial_reports
                (period_type, period_target, period_reference, aggregated_data, ai_analysis)
            VALUES
                (:period_type, :period_target, :period_reference, :aggregated_data, :ai_analysis)
            ON DUPLICATE KEY UPDATE
                period_reference = VALUES(period_reference),
                aggregated_data = VALUES(aggregated_data),
                ai_analysis = VALUES(ai_analysis),
                updated_at = NOW()
        ");

        $stmt->execute([
            ':period_type' => $periodType,
            ':period_target' => $periodTarget,
            ':period_reference' => $periodReference,
            ':aggregated_data' => json_encode($aggregatedData, JSON_UNESCAPED_UNICODE),
            ':ai_analysis' => json_encode($aiAnalysis, JSON_UNESCAPED_UNICODE),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Ruft die letzten Berichte für eine Übersicht ab.
     *
     * @param int $limit
     * @return array
     */
    public function listRecentReports(int $limit = 24): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, period_type, period_target, period_reference, created_at, updated_at
            FROM bank_financial_reports
            ORDER BY period_target DESC, id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
