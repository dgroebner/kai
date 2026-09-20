<?php

namespace Kai\Tools\Gamification;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Repository zur Verwaltung von Aufgaben-Vorlagen und Erzeugung wiederkehrender Aufgaben.
 */
class GamificationTemplateRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Liefert alle Vorlagen zurück.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllTemplates(bool $activeOnly = false): array
    {
        $sql = "
            SELECT t.*, p.display_name AS assigned_name, p.avatar_icon AS assigned_avatar, p.color AS assigned_color
            FROM gamification_task_templates t
            LEFT JOIN gamification_profiles p ON t.assigned_profile_id = p.id
        ";
        if ($activeOnly) {
            $sql .= " WHERE t.is_active = 1 ";
        }
        $sql .= " ORDER BY t.title ASC";

        $stmt = $this->db->getConnection()->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Sucht eine Vorlage anhand ihrer ID.
     */
    public function getTemplateById(int $id): ?array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT t.*, p.display_name AS assigned_name 
            FROM gamification_task_templates t
            LEFT JOIN gamification_profiles p ON t.assigned_profile_id = p.id
            WHERE t.id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Speichert eine neue oder aktualisiert eine bestehende Vorlage.
     */
    public function saveTemplate(array $data): int
    {
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $title = trim($data['title'] ?? '');
        $description = trim($data['description'] ?? '');
        $category = trim($data['category'] ?? 'haushalt');
        $baseXp = max(1, (int)($data['base_xp'] ?? 50));
        $baseCoins = max(1, (int)($data['base_coins'] ?? 20));
        $recurrence = in_array($data['recurrence'] ?? '', ['none', 'daily', 'weekly', 'interval'], true)
            ? $data['recurrence']
            : 'none';
        $recurrenceDays = !empty($data['recurrence_days']) ? trim($data['recurrence_days']) : null;
        $dueTime = !empty($data['due_time']) ? trim($data['due_time']) : null;
        $assignedProfileId = !empty($data['assigned_profile_id']) ? (int)$data['assigned_profile_id'] : null;
        $isCookingDay = !empty($data['is_cooking_day']) ? 1 : 0;
        $canEscalate = isset($data['can_escalate']) ? (int)(bool)$data['can_escalate'] : 1;
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;

        $hasCol = $this->hasCanEscalateColumn('gamification_task_templates');

        if ($id) {
            $escalateSql = $hasCol ? "can_escalate = :can_escalate," : "";
            $stmt = $this->db->getConnection()->prepare("
                UPDATE gamification_task_templates SET
                    title = :title,
                    description = :description,
                    category = :category,
                    base_xp = :base_xp,
                    base_coins = :base_coins,
                    recurrence = :recurrence,
                    recurrence_days = :recurrence_days,
                    due_time = :due_time,
                    assigned_profile_id = :assigned_profile_id,
                    is_cooking_day = :is_cooking_day,
                    {$escalateSql}
                    is_active = :is_active
                WHERE id = :id
            ");
            $params = [
                'title' => $title,
                'description' => $description,
                'category' => $category,
                'base_xp' => $baseXp,
                'base_coins' => $baseCoins,
                'recurrence' => $recurrence,
                'recurrence_days' => $recurrenceDays,
                'due_time' => $dueTime,
                'assigned_profile_id' => $assignedProfileId,
                'is_cooking_day' => $isCookingDay,
                'is_active' => $isActive,
                'id' => $id,
            ];
            if ($hasCol) {
                $params['can_escalate'] = $canEscalate;
            }
            $stmt->execute($params);
            return $id;
        }

        $colSql = $hasCol ? ", can_escalate" : "";
        $valSql = $hasCol ? ", :can_escalate" : "";
        $stmt = $this->db->getConnection()->prepare("
            INSERT INTO gamification_task_templates (
                title, description, category, base_xp, base_coins,
                recurrence, recurrence_days, due_time, assigned_profile_id,
                is_cooking_day{$colSql}, is_active
            ) VALUES (
                :title, :description, :category, :base_xp, :base_coins,
                :recurrence, :recurrence_days, :due_time, :assigned_profile_id,
                :is_cooking_day{$valSql}, :is_active
            )
        ");
        $params = [
            'title' => $title,
            'description' => $description,
            'category' => $category,
            'base_xp' => $baseXp,
            'base_coins' => $baseCoins,
            'recurrence' => $recurrence,
            'recurrence_days' => $recurrenceDays,
            'due_time' => $dueTime,
            'assigned_profile_id' => $assignedProfileId,
            'is_cooking_day' => $isCookingDay,
            'is_active' => $isActive,
        ];
        if ($hasCol) {
            $params['can_escalate'] = $canEscalate;
        }
        $stmt->execute($params);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    /**
     * Löscht eine Vorlage.
     */
    public function deleteTemplate(int $id): bool
    {
        $stmt = $this->db->getConnection()->prepare("
            DELETE FROM gamification_task_templates WHERE id = :id
        ");
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Generiert fällige Aufgaben aus Vorlagen für ein bestimmtes Datum.
     *
     * @param string $targetDate Format YYYY-MM-DD
     * @return int Anzahl neu erzeugter Aufgaben
     */
    public function generateTasksForDate(string $targetDate): int
    {
        $templates = $this->getAllTemplates(true);
        $dayOfWeek = (int)date('N', strtotime($targetDate)); // 1 (Mo) bis 7 (So)
        $createdCount = 0;
        $pdo = $this->db->getConnection();
        $hasTaskCol = $this->hasCanEscalateColumn('gamification_tasks');

        foreach ($templates as $tmpl) {
            $shouldGenerate = false;

            if ($tmpl['recurrence'] === 'daily') {
                $shouldGenerate = true;
            } elseif ($tmpl['recurrence'] === 'weekly') {
                $days = array_filter(array_map('trim', explode(',', $tmpl['recurrence_days'] ?? '')));
                if (in_array((string)$dayOfWeek, $days, true)) {
                    $shouldGenerate = true;
                }
            }

            if (!$shouldGenerate) {
                continue;
            }

            // Prüfen, ob bereits eine Aufgabe aus dieser Vorlage für den Tag existiert
            $checkStmt = $pdo->prepare("
                SELECT id FROM gamification_tasks 
                WHERE template_id = :template_id AND due_date = :due_date 
                LIMIT 1
            ");
            $checkStmt->execute([
                'template_id' => $tmpl['id'],
                'due_date' => $targetDate,
            ]);

            if ($checkStmt->fetchColumn()) {
                continue; // Bereits generiert
            }

            // Aufgabe anlegen
            $assignedId = $tmpl['assigned_profile_id'];
            $isBounty = ($assignedId === null) ? 1 : 0;
            $canEscalate = isset($tmpl['can_escalate']) ? (int)$tmpl['can_escalate'] : 1;

            $colSql = $hasTaskCol ? ", can_escalate" : "";
            $valSql = $hasTaskCol ? ", :can_escalate" : "";

            $insertStmt = $pdo->prepare("
                INSERT INTO gamification_tasks (
                    template_id, title, description, category,
                    assigned_profile_id, origin_profile_id, status, is_bounty,
                    due_date, due_time, base_xp, base_coins, is_cooking_day{$colSql}
                ) VALUES (
                    :template_id, :title, :description, :category,
                    :assigned_profile_id, :origin_profile_id, 'planned', :is_bounty,
                    :due_date, :due_time, :base_xp, :base_coins, :is_cooking_day{$valSql}
                )
            ");
            $params = [
                'template_id' => $tmpl['id'],
                'title' => $tmpl['title'],
                'description' => $tmpl['description'],
                'category' => $tmpl['category'],
                'assigned_profile_id' => $assignedId,
                'origin_profile_id' => $assignedId,
                'is_bounty' => $isBounty,
                'due_date' => $targetDate,
                'due_time' => $tmpl['due_time'],
                'base_xp' => $tmpl['base_xp'],
                'base_coins' => $tmpl['base_coins'],
                'is_cooking_day' => $tmpl['is_cooking_day'],
            ];
            if ($hasTaskCol) {
                $params['can_escalate'] = $canEscalate;
            }
            $insertStmt->execute($params);

            $createdCount++;
        }

        return $createdCount;
    }

    private function hasCanEscalateColumn(string $table): bool
    {
        static $hasCol = [];
        if (isset($hasCol[$table])) {
            return $hasCol[$table];
        }
        try {
            $stmt = $this->db->getConnection()->query("SHOW COLUMNS FROM {$table} LIKE 'can_escalate'");
            $hasCol[$table] = (bool)$stmt->fetch();
        } catch (\Throwable) {
            $hasCol[$table] = false;
        }
        return $hasCol[$table];
    }

    /**
     * Erzeugt eine konkrete Aufgabe aus einer Vorlage für ein bestimmtes Datum.
     */
    public function instantiateTaskFromTemplate(
        int $templateId,
        ?string $dueDate = null,
        ?int $assignedProfileId = null,
        string $status = 'planned'
    ): int {
        $tmpl = $this->getTemplateById($templateId);
        if (!$tmpl) {
            throw new \InvalidArgumentException("Vorlage nicht gefunden.");
        }

        $targetDate = $dueDate ?? date('Y-m-d');
        $assignedId = ($assignedProfileId !== null) ? $assignedProfileId : $tmpl['assigned_profile_id'];
        $isBounty = ($assignedId === null) ? 1 : 0;
        $canEscalate = isset($tmpl['can_escalate']) ? (int)$tmpl['can_escalate'] : 1;
        $hasTaskCol = $this->hasCanEscalateColumn('gamification_tasks');

        $colSql = $hasTaskCol ? ", can_escalate" : "";
        $valSql = $hasTaskCol ? ", :can_escalate" : "";

        $stmt = $this->db->getConnection()->prepare("
            INSERT INTO gamification_tasks (
                template_id, title, description, category,
                assigned_profile_id, origin_profile_id, status, is_bounty,
                due_date, due_time, base_xp, base_coins, is_cooking_day{$colSql}
            ) VALUES (
                :template_id, :title, :description, :category,
                :assigned_profile_id, :origin_profile_id, :status, :is_bounty,
                :due_date, :due_time, :base_xp, :base_coins, :is_cooking_day{$valSql}
            )
        ");
        $params = [
            'template_id' => $tmpl['id'],
            'title' => $tmpl['title'],
            'description' => $tmpl['description'],
            'category' => $tmpl['category'],
            'assigned_profile_id' => $assignedId,
            'origin_profile_id' => $assignedId,
            'status' => $status,
            'is_bounty' => $isBounty,
            'due_date' => $targetDate,
            'due_time' => $tmpl['due_time'],
            'base_xp' => (int)$tmpl['base_xp'],
            'base_coins' => (int)$tmpl['base_coins'],
            'is_cooking_day' => (int)$tmpl['is_cooking_day'],
        ];
        if ($hasTaskCol) {
            $params['can_escalate'] = $canEscalate;
        }
        $stmt->execute($params);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    /**
     * Liefert Vorlagen im Modus "Nur manuell" (on-demand), die dem Kind zugewiesen sind
     * und für die heute noch keine offene/aktive Aufgabe existiert.
     */
    public function getOnDemandTemplatesForProfile(int $profileId, ?string $date = null): array
    {
        $today = $date ?? date('Y-m-d');
        $stmt = $this->db->getConnection()->prepare("
            SELECT t.* 
            FROM gamification_task_templates t
            WHERE t.is_active = 1
              AND t.recurrence = 'none'
              AND (t.assigned_profile_id = :pid OR t.assigned_profile_id IS NULL)
              AND t.id NOT IN (
                  SELECT template_id FROM gamification_tasks 
                  WHERE template_id IS NOT NULL 
                    AND due_date = :today 
                    AND status IN ('planned', 'in_progress', 'submitted', 'in_review')
                    AND (assigned_profile_id = :pid2 OR claimed_by_profile_id = :pid3)
              )
            ORDER BY t.title ASC
        ");
        $stmt->execute([
            'pid' => $profileId,
            'today' => $today,
            'pid2' => $profileId,
            'pid3' => $profileId,
        ]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
