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

    /**
     * Bereinigt und formatiert Notiztexte (z. B. HTML-Entities auflösen, Pfeil-Schreibweisen vereinheitlichen).
     */
    public static function formatNoteDescription(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // HTML-Entities dekodieren (z. B. &gt; -> >, &amp; -> &)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Gängige Pfeil-Schreibweisen vereinheitlichen
        return str_replace(['—>', '–>', '-->', '->', '==>', '=>'], '→', $text);
    }

    public function upsertNote(array $noteData): void
    {
        $pdo = $this->db->getConnection();
        $noteData['description'] = self::formatNoteDescription($noteData['description'] ?? '');

        // Prüfen, ob für dasselbe Kind am selben Tag im selben Fach bereits exakt dieselbe Notiz existiert (z. B. bei Doppelstunden)
        $checkStmt = $pdo->prepare("
            SELECT id FROM school_beste_notes 
            WHERE student_id = :student_id 
              AND lesson_date = :lesson_date 
              AND subject = :subject 
              AND description = :description
            LIMIT 1
        ");
        $checkStmt->execute([
            ':student_id' => $noteData['student_id'],
            ':lesson_date' => $noteData['lesson_date'],
            ':subject' => $noteData['subject'],
            ':description' => $noteData['description'],
        ]);
        $existingId = $checkStmt->fetchColumn();

        if ($existingId) {
            // Bereits vorhanden -> nur Typ und API-Note-ID aktualisieren, kein Duplikat anlegen
            $updateStmt = $pdo->prepare("
                UPDATE school_beste_notes 
                SET type_name = :type_name,
                    api_note_id = :api_note_id
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':type_name' => $noteData['type_name'],
                ':api_note_id' => $noteData['api_note_id'],
                ':id' => $existingId
            ]);
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO school_beste_notes (student_id, lesson_date, subject, type_name, description, api_note_id, created_at)
            VALUES (:student_id, :lesson_date, :subject, :type_name, :description, :api_note_id, NOW())
            ON DUPLICATE KEY UPDATE 
                lesson_date = VALUES(lesson_date),
                subject = VALUES(subject),
                type_name = VALUES(type_name),
                description = VALUES(description)
        ");
        $stmt->execute($noteData);
    }

    /**
     * Entfernt bestehende Duplikate aus der Datenbank (behält jeweils den ältesten Eintrag mit kleinster ID).
     */
    public function deleteDuplicateNotes(): void
    {
        $pdo = $this->db->getConnection();
        $pdo->exec("
            DELETE n1 FROM school_beste_notes n1
            INNER JOIN school_beste_notes n2 
            WHERE n1.id > n2.id 
              AND n1.student_id = n2.student_id 
              AND n1.lesson_date = n2.lesson_date 
              AND n1.subject = n2.subject 
              AND n1.description = n2.description
        ");
    }

    // -------------------------------------------------------------------------
    // QUERIES (Lesen pro Kind/er)
    // -------------------------------------------------------------------------

    public function getAllGrades(array $studentIds): array
    {
        if (empty($studentIds)) return [];

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $sql = "SELECT g.*, s.name as student_name, s.display_color 
                FROM school_beste_grades g
                JOIN school_students s ON g.student_id = s.id
                WHERE g.student_id IN ($placeholders)
                ORDER BY s.name ASC, g.subject ASC, g.given_at DESC, g.id DESC";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array_values($studentIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

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

    public function getUpcomingNotes(array $studentIds, ?string $fromDate = null): array
    {
        if (empty($studentIds)) return [];

        if ($fromDate === null) {
            $now = new \DateTimeImmutable();
            $hour = (int)$now->format('G');
            $dayOfWeek = (int)$now->format('N'); // 1 = Mo, ..., 7 = So

            if ($dayOfWeek === 6) { // Samstag -> nächster Montag
                $fromDate = $now->modify('+2 days')->format('Y-m-d');
            } elseif ($dayOfWeek === 7) { // Sonntag -> nächster Montag
                $fromDate = $now->modify('+1 day')->format('Y-m-d');
            } elseif ($dayOfWeek === 5 && $hour >= 15) { // Freitag ab 15:00 Uhr -> nächster Montag
                $fromDate = $now->modify('+3 days')->format('Y-m-d');
            } elseif ($hour >= 15) { // Mo-Do ab 15:00 Uhr -> nächster Tag
                $fromDate = $now->modify('+1 day')->format('Y-m-d');
            } else {
                $fromDate = $now->format('Y-m-d');
            }
        }

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));

        $params = array_values($studentIds);
        array_unshift($params, $fromDate);

        $sql = "SELECT n.*, s.name as student_name, s.display_color 
                FROM school_beste_notes n
                JOIN school_students s ON n.student_id = s.id
                WHERE n.lesson_date >= ?
                  AND n.student_id IN ($placeholders)
                ORDER BY n.lesson_date ASC, n.id ASC";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Duplikate herausfiltern (z. B. wenn bei Doppelstunden derselbe Eintrag an beiden Stunden hängt)
        $unique = [];
        $deduped = [];
        foreach ($rows as $row) {
            $key = $row['student_id'] . '|' . $row['lesson_date'] . '|' . mb_strtolower(trim($row['subject'])) . '|' . mb_strtolower(trim($row['description']));
            if (!isset($unique[$key])) {
                $unique[$key] = true;
                $deduped[] = $row;
            }
        }

        return $deduped;
    }
}
