<?php

namespace Kai\Tools\School;

use Kai\Tools\Shared\Db\Database;
use PDO;
use Throwable;

/**
 * Repository zur Persistierung und Abfrage von Vertretungsplänen, Stunden und Durchsagen.
 */
class SchoolPlanRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Speichert oder aktualisiert die Kopfdaten eines Plans.
     */
    public function savePlan(string $date, ?string $timestamp, ?string $week, ?string $hash): void
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO school_plans (plan_date, plan_timestamp, school_week, raw_hash, created_at, updated_at)
            VALUES (:plan_date, :plan_timestamp, :school_week, :raw_hash, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                plan_timestamp = VALUES(plan_timestamp),
                school_week = VALUES(school_week),
                raw_hash = VALUES(raw_hash),
                updated_at = NOW()
        ");
        $stmt->execute([
            'plan_date' => $date,
            'plan_timestamp' => $timestamp,
            'school_week' => $week,
            'raw_hash' => $hash,
        ]);
    }

    /**
     * Speichert alle Stunden eines Tages (ersetzt bestehende Einträge für dieses Datum).
     *
     * @param string $date YYYY-MM-DD
     * @param array<int, array<string, mixed>> $items
     */
    public function savePlanItems(string $date, array $items): void
    {
        $pdo = $this->db->getConnection();

        try {
            $pdo->beginTransaction();

            $delStmt = $pdo->prepare("DELETE FROM school_plan_items WHERE plan_date = :plan_date");
            $delStmt->execute(['plan_date' => $date]);

            if (!empty($items)) {
                $insStmt = $pdo->prepare("
                    INSERT INTO school_plan_items (
                        plan_date, class_name, lesson_number, start_time, end_time,
                        subject, subject_original, teacher, teacher_original, room, room_original,
                        course_group, info, is_cancelled, is_substitution, is_room_change, is_moved, created_at
                    ) VALUES (
                        :plan_date, :class_name, :lesson_number, :start_time, :end_time,
                        :subject, :subject_original, :teacher, :teacher_original, :room, :room_original,
                        :course_group, :info, :is_cancelled, :is_substitution, :is_room_change, :is_moved, NOW()
                    )
                ");

                foreach ($items as $item) {
                    $insStmt->execute([
                        'plan_date' => $date,
                        'class_name' => (string)($item['class_name'] ?? ''),
                        'lesson_number' => (int)($item['lesson_number'] ?? 0),
                        'start_time' => (string)($item['start_time'] ?? ''),
                        'end_time' => (string)($item['end_time'] ?? ''),
                        'subject' => (string)($item['subject'] ?? ''),
                        'subject_original' => !empty($item['subject_original']) ? (string)$item['subject_original'] : null,
                        'teacher' => !empty($item['teacher']) ? (string)$item['teacher'] : null,
                        'teacher_original' => !empty($item['teacher_original']) ? (string)$item['teacher_original'] : null,
                        'room' => !empty($item['room']) ? (string)$item['room'] : null,
                        'room_original' => !empty($item['room_original']) ? (string)$item['room_original'] : null,
                        'course_group' => !empty($item['course_group']) ? (string)$item['course_group'] : null,
                        'info' => !empty($item['info']) ? (string)$item['info'] : null,
                        'is_cancelled' => !empty($item['is_cancelled']) ? 1 : 0,
                        'is_substitution' => !empty($item['is_substitution']) ? 1 : 0,
                        'is_room_change' => !empty($item['is_room_change']) ? 1 : 0,
                        'is_moved' => !empty($item['is_moved']) ? 1 : 0,
                    ]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Speichert schulweite Durchsagen / Hinweise für ein Datum.
     *
     * @param string $date YYYY-MM-DD
     * @param array<int, string> $notes
     */
    public function saveGlobalNotes(string $date, array $notes): void
    {
        $pdo = $this->db->getConnection();

        try {
            $pdo->beginTransaction();

            $delStmt = $pdo->prepare("DELETE FROM school_global_notes WHERE plan_date = :plan_date");
            $delStmt->execute(['plan_date' => $date]);

            if (!empty($notes)) {
                $insStmt = $pdo->prepare("
                    INSERT INTO school_global_notes (plan_date, note_text, sort_order, created_at)
                    VALUES (:plan_date, :note_text, :sort_order, NOW())
                ");

                foreach ($notes as $idx => $note) {
                    $clean = trim((string)$note);
                    if ($clean !== '') {
                        $insStmt->execute([
                            'plan_date' => $date,
                            'note_text' => $clean,
                            'sort_order' => $idx + 1,
                        ]);
                    }
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Liefert die Metadaten eines Plans.
     */
    public function getPlanMetadata(string $date): ?array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT * FROM school_plans WHERE plan_date = :plan_date LIMIT 1
        ");
        $stmt->execute(['plan_date' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Liefert alle Stunden für eine bestimmte Klasse an einem Datum.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPlanItemsForClass(string $date, string $className): array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT * FROM school_plan_items 
            WHERE plan_date = :plan_date AND UPPER(class_name) = UPPER(:class_name)
            ORDER BY lesson_number ASC, start_time ASC, id ASC
        ");
        $stmt->execute([
            'plan_date' => $date,
            'class_name' => $className,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Liefert alle globalen Durchsagen für ein Datum.
     *
     * @return array<int, string>
     */
    public function getGlobalNotes(string $date): array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT note_text FROM school_global_notes 
            WHERE plan_date = :plan_date 
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute(['plan_date' => $date]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Liefert alle Klassen, für die an einem Datum Daten vorliegen.
     *
     * @return array<int, string>
     */
    public function getAvailableClasses(string $date): array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT DISTINCT class_name FROM school_plan_items 
            WHERE plan_date = :plan_date 
            ORDER BY class_name ASC
        ");
        $stmt->execute(['plan_date' => $date]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Liefert alle Daten, für die Pläne in der Datenbank gespeichert sind.
     *
     * @return array<int, string> Liste von YYYY-MM-DD
     */
    public function getAvailableDates(): array
    {
        $stmt = $this->db->getConnection()->query("
            SELECT plan_date FROM school_plans ORDER BY plan_date ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
}
