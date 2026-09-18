<?php

namespace Kai\Tools\School;

use Kai\Tools\Shared\Db\Database;
use PDO;

class BesteSchuleRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // -------------------------------------------------------------------------
    // UPSERT (Schreiben)
    // -------------------------------------------------------------------------

    public function upsertGrade(array $gradeData): void
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO school_beste_grades (id, student_id, subject, collection_name, grade_value, given_at, read_status, created_at, updated_at)
            VALUES (:id, :student_id, :subject, :collection_name, :grade_value, :given_at, :read_status, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                subject = VALUES(subject),
                collection_name = VALUES(collection_name),
                grade_value = VALUES(grade_value),
                given_at = VALUES(given_at),
                read_status = VALUES(read_status),
                updated_at = NOW()
        ");
        $stmt->execute($gradeData);
    }

    public function upsertAbsence(array $absenceData): void
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO school_beste_absences (id, student_id, from_time, to_time, absence_type, is_unexcused, note, created_at, updated_at)
            VALUES (:id, :student_id, :from_time, :to_time, :absence_type, :is_unexcused, :note, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                from_time = VALUES(from_time),
                to_time = VALUES(to_time),
                absence_type = VALUES(absence_type),
                is_unexcused = VALUES(is_unexcused),
                note = VALUES(note),
                updated_at = NOW()
        ");
        $stmt->execute($absenceData);
    }

    public function upsertJournalEntry(array $journalData): void
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO school_beste_journal (id, student_id, lesson_date, subject, missing_homework, missing_equipment, created_at, updated_at)
            VALUES (:id, :student_id, :lesson_date, :subject, :missing_homework, :missing_equipment, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                lesson_date = VALUES(lesson_date),
                subject = VALUES(subject),
                missing_homework = VALUES(missing_homework),
                missing_equipment = VALUES(missing_equipment),
                updated_at = NOW()
        ");
        $stmt->execute($journalData);
    }

    // -------------------------------------------------------------------------
    // QUERIES (Lesen pro Kind/er)
    // -------------------------------------------------------------------------

    public function getRecentGrades(array $studentIds, int $limit = 10): array
    {
        if (empty($studentIds)) return [];

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $sql = "SELECT g.*, s.name as student_name, s.display_color 
                FROM school_beste_grades g
                JOIN school_students s ON g.student_id = s.id
                WHERE g.student_id IN ($placeholders)
                ORDER BY g.given_at DESC, g.id DESC
                LIMIT " . (int)$limit;

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array_values($studentIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUnexcusedAbsences(array $studentIds): array
    {
        if (empty($studentIds)) return [];

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $sql = "SELECT a.*, s.name as student_name, s.display_color 
                FROM school_beste_absences a
                JOIN school_students s ON a.student_id = s.id
                WHERE a.student_id IN ($placeholders) 
                  AND a.is_unexcused = 1
                ORDER BY a.from_time DESC";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array_values($studentIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMissingHomework(array $studentIds, int $daysBack = 14): array
    {
        if (empty($studentIds)) return [];

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $cutoffDate = date('Y-m-d', strtotime("-{$daysBack} days"));

        // Füge den $cutoffDate an den Anfang des Arrays ein
        $params = array_values($studentIds);
        array_unshift($params, $cutoffDate);

        $sql = "SELECT j.*, s.name as student_name, s.display_color 
                FROM school_beste_journal j
                JOIN school_students s ON j.student_id = s.id
                WHERE j.lesson_date >= ?
                  AND j.student_id IN ($placeholders) 
                  AND (j.missing_homework = 1 OR j.missing_equipment = 1)
                ORDER BY j.lesson_date DESC";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
