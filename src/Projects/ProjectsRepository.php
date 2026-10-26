<?php

namespace Kai\Tools\Projects;

use Kai\Tools\Projects\Db\ProjectsDatabase;
use Kai\Tools\Shared\Security\Sanitizer;
use PDO;

/**
 * Repository für alle fachlichen Projects-Entitäten eines Endanwenders.
 *
 * MANDANTENSICHERHEIT:
 * Jede Methode erzwingt strikt die Übergabe von $userId als Filter / Foreign Key.
 */
class ProjectsRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ProjectsDatabase::getInstance()->getConnection();
    }

    // -------------------------------------------------------------------------
    // NUTZERPROFIL & EINSTELLUNGEN
    // -------------------------------------------------------------------------

    public function getUserProfile(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, email, name, status, state_code, reminder_enabled, reminder_time, created_at
            FROM proj_users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateProfile(int $userId, ?string $name, string $stateCode, bool $reminderEnabled, string $reminderTime): bool
    {
        $stateCode = in_array(strtoupper($stateCode), ['SN', 'HH'], true) ? strtoupper($stateCode) : 'SN';
        $reminderTime = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $reminderTime) ? substr($reminderTime, 0, 5) . ':00' : '16:30:00';

        $stmt = $this->pdo->prepare("
            UPDATE proj_users
            SET name = :name,
                state_code = :state,
                reminder_enabled = :rem_en,
                reminder_time = :rem_time
            WHERE id = :id
        ");
        return $stmt->execute([
            ':name' => !empty($name) ? trim($name) : null,
            ':state' => $stateCode,
            ':rem_en' => $reminderEnabled ? 1 : 0,
            ':rem_time' => $reminderTime,
            ':id' => $userId,
        ]);
    }

    // -------------------------------------------------------------------------
    // PROJEKTE
    // -------------------------------------------------------------------------

    public function getProjects(int $userId, bool $includeArchived = false): array
    {
        $sql = "SELECT id, name, short_code, planisware_code, color, is_archived, created_at
                FROM proj_projects
                WHERE user_id = :uid";
        if (!$includeArchived) {
            $sql .= " AND is_archived = 0";
        }
        $sql .= " ORDER BY is_archived ASC, name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProjectById(int $userId, int $projectId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, short_code, planisware_code, color, is_archived, created_at
            FROM proj_projects
            WHERE id = :pid AND user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([':pid' => $projectId, ':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findProjectByShortCode(int $userId, string $shortCode): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, short_code, planisware_code, color, is_archived, created_at
            FROM proj_projects
            WHERE user_id = :uid AND UPPER(short_code) = UPPER(:code)
            LIMIT 1
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':code' => trim($shortCode),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveProject(int $userId, string $name, string $shortCode, ?string $planiswareCode = null, ?string $color = null, ?int $projectId = null): int
    {
        $cleanColor = Sanitizer::hexColor($color ?? '#0284c7');
        $cleanShort = strtoupper(trim($shortCode));
        $cleanName = trim($name);
        $cleanPlanisware = !empty($planiswareCode) ? trim($planiswareCode) : null;

        if ($projectId !== null && $projectId > 0) {
            $stmt = $this->pdo->prepare("
                UPDATE proj_projects
                SET name = :name,
                    short_code = :code,
                    planisware_code = :pcode,
                    color = :color
                WHERE id = :id AND user_id = :uid
            ");
            $stmt->execute([
                ':name' => $cleanName,
                ':code' => $cleanShort,
                ':pcode' => $cleanPlanisware,
                ':color' => $cleanColor,
                ':id' => $projectId,
                ':uid' => $userId,
            ]);
            return $projectId;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO proj_projects (user_id, name, short_code, planisware_code, color, is_archived, created_at)
            VALUES (:uid, :name, :code, :pcode, :color, 0, NOW())
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':name' => $cleanName,
            ':code' => $cleanShort,
            ':pcode' => $cleanPlanisware,
            ':color' => $cleanColor,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function archiveProject(int $userId, int $projectId, bool $archive = true): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE proj_projects
            SET is_archived = :arch
            WHERE id = :pid AND user_id = :uid
        ");
        return $stmt->execute([
            ':arch' => $archive ? 1 : 0,
            ':pid' => $projectId,
            ':uid' => $userId,
        ]);
    }

    // -------------------------------------------------------------------------
    // HAUPTPROJEKT-STEUERUNG (PROJECT DEFAULTS)
    // -------------------------------------------------------------------------

    /**
     * Ermittelt das aktive Hauptprojekt für ein bestimmtes Datum.
     * Priorisiert temporäre Überschreibungen (valid_to IS NOT NULL) vor offenen Dauer-Einträgen.
     */
    public function getDefaultProjectForDate(int $userId, string $date): ?array
    {
        // 1. Priorität: Temporäre Überschreibung, die das Datum abdeckt
        $stmt = $this->pdo->prepare("
            SELECT d.id, d.project_id, d.valid_from, d.valid_to, p.name, p.short_code, p.planisware_code, p.color
            FROM proj_project_defaults d
            JOIN proj_projects p ON p.id = d.project_id
            WHERE d.user_id = :uid
              AND d.valid_to IS NOT NULL
              AND :date BETWEEN d.valid_from AND d.valid_to
            ORDER BY d.valid_from DESC
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId, ':date' => $date]);
        $temp = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($temp) {
            $temp['is_temporary'] = true;
            return $temp;
        }

        // 2. Priorität: Offener Dauer-Eintrag (valid_to IS NULL) mit valid_from <= $date
        $stmt2 = $this->pdo->prepare("
            SELECT d.id, d.project_id, d.valid_from, d.valid_to, p.name, p.short_code, p.planisware_code, p.color
            FROM proj_project_defaults d
            JOIN proj_projects p ON p.id = d.project_id
            WHERE d.user_id = :uid
              AND d.valid_to IS NULL
              AND d.valid_from <= :date
            ORDER BY d.valid_from DESC
            LIMIT 1
        ");
        $stmt2->execute([':uid' => $userId, ':date' => $date]);
        $permanent = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($permanent) {
            $permanent['is_temporary'] = false;
            return $permanent;
        }

        // Fallback: Ältestes oder erstes nicht archiviertes Projekt
        $stmt3 = $this->pdo->prepare("
            SELECT id as project_id, name, short_code, planisware_code, color
            FROM proj_projects
            WHERE user_id = :uid AND is_archived = 0
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt3->execute([':uid' => $userId]);
        $fallback = $stmt3->fetch(PDO::FETCH_ASSOC);
        if ($fallback) {
            $fallback['valid_from'] = $date;
            $fallback['valid_to'] = null;
            $fallback['is_temporary'] = false;
            return $fallback;
        }

        return null;
    }

    /**
     * Setzt das Hauptprojekt:
     * - 'permanent': Schließt vorherige offene Dauer-Defaults und öffnet einen neuen ab $validFrom.
     * - 'today': Setzt valid_from = $validFrom und valid_to = $validFrom.
     * - 'until': Setzt valid_from = $validFrom und valid_to = $validTo.
     */
    public function setDefaultProject(int $userId, int $projectId, string $mode, string $validFrom, ?string $validTo = null): bool
    {
        if ($mode === 'permanent') {
            // Vorherige offene Dauer-Defaults beenden (oder löschen, wenn noch in der Zukunft)
            $stmtClose = $this->pdo->prepare("
                UPDATE proj_project_defaults
                SET valid_to = DATE_SUB(:from, INTERVAL 1 DAY)
                WHERE user_id = :uid AND valid_to IS NULL AND valid_from < :from
            ");
            $stmtClose->execute([':uid' => $userId, ':from' => $validFrom]);

            $stmtDel = $this->pdo->prepare("
                DELETE FROM proj_project_defaults
                WHERE user_id = :uid AND valid_to IS NULL AND valid_from >= :from
            ");
            $stmtDel->execute([':uid' => $userId, ':from' => $validFrom]);

            $stmtIns = $this->pdo->prepare("
                INSERT INTO proj_project_defaults (user_id, project_id, valid_from, valid_to, created_at)
                VALUES (:uid, :pid, :from, NULL, NOW())
            ");
            return $stmtIns->execute([
                ':uid' => $userId,
                ':pid' => $projectId,
                ':from' => $validFrom,
            ]);
        }

        // Temporäre Überschreibung ('today' oder 'until')
        $endDate = ($mode === 'today') ? $validFrom : ($validTo ?? $validFrom);

        $stmtIns = $this->pdo->prepare("
            INSERT INTO proj_project_defaults (user_id, project_id, valid_from, valid_to, created_at)
            VALUES (:uid, :pid, :from, :to, NOW())
        ");
        return $stmtIns->execute([
            ':uid' => $userId,
            ':pid' => $projectId,
            ':from' => $validFrom,
            ':to' => $endDate,
        ]);
    }

    // -------------------------------------------------------------------------
    // AUSNAHMEN (TIME EXCEPTIONS)
    // -------------------------------------------------------------------------

    public function getExceptionsForDate(int $userId, string $date): array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.id, e.project_id, e.date, e.hours, e.comment, e.created_at,
                   p.name as project_name, p.short_code, p.color
            FROM proj_time_exceptions e
            JOIN proj_projects p ON p.id = e.project_id
            WHERE e.user_id = :uid AND e.date = :date
            ORDER BY e.id ASC
        ");
        $stmt->execute([':uid' => $userId, ':date' => $date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getExceptionsForMonth(int $userId, int $year, int $month): array
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $stmt = $this->pdo->prepare("
            SELECT e.id, e.project_id, e.date, e.hours, e.comment,
                   p.name as project_name, p.short_code, p.color
            FROM proj_time_exceptions e
            JOIN proj_projects p ON p.id = e.project_id
            WHERE e.user_id = :uid AND e.date BETWEEN :start AND :end
            ORDER BY e.date ASC, e.id ASC
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':start' => $startDate,
            ':end' => $endDate,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addException(int $userId, int $projectId, string $date, float $hours, ?string $comment = null): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO proj_time_exceptions (user_id, project_id, date, hours, comment, created_at)
            VALUES (:uid, :pid, :date, :hours, :comment, NOW())
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':pid' => $projectId,
            ':date' => $date,
            ':hours' => round($hours, 1),
            ':comment' => !empty($comment) ? trim($comment) : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function deleteException(int $userId, int $exceptionId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM proj_time_exceptions WHERE id = :id AND user_id = :uid");
        return $stmt->execute([':id' => $exceptionId, ':uid' => $userId]);
    }

    // -------------------------------------------------------------------------
    // ABWESENHEITEN (ABSENCES)
    // -------------------------------------------------------------------------

    public function getAbsenceForDate(int $userId, string $date): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, date, type, hours, comment, created_at
            FROM proj_absences
            WHERE user_id = :uid AND date = :date
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId, ':date' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getAbsencesForMonth(int $userId, int $year, int $month): array
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $stmt = $this->pdo->prepare("
            SELECT id, date, type, hours, comment
            FROM proj_absences
            WHERE user_id = :uid AND date BETWEEN :start AND :end
            ORDER BY date ASC
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':start' => $startDate,
            ':end' => $endDate,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveAbsence(int $userId, string $date, string $type, float $hours = 8.0, ?string $comment = null): bool
    {
        $cleanType = in_array($type, ['vacation', 'sick', 'special_leave'], true) ? $type : 'vacation';

        $stmt = $this->pdo->prepare("
            INSERT INTO proj_absences (user_id, date, type, hours, comment, created_at)
            VALUES (:uid, :date, :type, :hours, :comment, NOW())
            ON DUPLICATE KEY UPDATE
                type = VALUES(type),
                hours = VALUES(hours),
                comment = VALUES(comment)
        ");
        return $stmt->execute([
            ':uid' => $userId,
            ':date' => $date,
            ':type' => $cleanType,
            ':hours' => round($hours, 1),
            ':comment' => !empty($comment) ? trim($comment) : null,
        ]);
    }

    public function deleteAbsence(int $userId, string $date): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM proj_absences WHERE user_id = :uid AND date = :date");
        return $stmt->execute([':uid' => $userId, ':date' => $date]);
    }
}
