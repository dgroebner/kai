<?php

namespace Kai\Tools\Gamification;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Repository zur Verwaltung von Aufgaben, Missionen, Spontan-Hilfen und dem Schwarzen Brett (Bounty Board).
 */
class GamificationTaskRepository
{
    private Database $db;
    private GamificationProfileRepository $profileRepo;

    public function __construct(
        ?Database $db = null,
        ?GamificationProfileRepository $profileRepo = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->profileRepo = $profileRepo ?? new GamificationProfileRepository($this->db);
    }

    /**
     * Sucht eine Aufgabe anhand der ID inkl. Profilnamen.
     */
    public function getTaskById(int $id): ?array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT t.*, 
                   pa.display_name AS assigned_name, pa.avatar_icon AS assigned_avatar, pa.color AS assigned_color,
                   po.display_name AS origin_name, po.avatar_icon AS origin_avatar,
                   pc.display_name AS claimed_name, pc.avatar_icon AS claimed_avatar
            FROM gamification_tasks t
            LEFT JOIN gamification_profiles pa ON t.assigned_profile_id = pa.id
            LEFT JOIN gamification_profiles po ON t.origin_profile_id = po.id
            LEFT JOIN gamification_profiles pc ON t.claimed_by_profile_id = pc.id
            WHERE t.id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Liefert persönliche Quests eines Kinds (geplant, in Arbeit oder heute erledigt/eingereicht).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMyTasks(int $profileId, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $stmt = $this->db->getConnection()->prepare("
            SELECT t.*, 
                   pa.display_name AS assigned_name,
                   pc.display_name AS claimed_name
            FROM gamification_tasks t
            LEFT JOIN gamification_profiles pa ON t.assigned_profile_id = pa.id
            LEFT JOIN gamification_profiles pc ON t.claimed_by_profile_id = pc.id
            WHERE (
                (t.assigned_profile_id = :pid_assigned AND t.is_bounty = 0)
                OR t.claimed_by_profile_id = :pid_claimed
            )
            AND (
                t.status IN ('planned', 'in_progress', 'submitted', 'in_review')
                OR (t.status = 'completed' AND (t.due_date = :due_date OR DATE(t.completed_at) = :completed_date))
            )
            ORDER BY 
                CASE 
                    WHEN t.status = 'in_progress' THEN 1
                    WHEN t.status = 'planned' THEN 2
                    WHEN t.status IN ('submitted', 'in_review') THEN 3
                    ELSE 4 
                END,
                t.due_date ASC, 
                t.due_time ASC
        ");
        $stmt->execute([
            'pid_assigned' => $profileId,
            'pid_claimed' => $profileId,
            'due_date' => $date,
            'completed_date' => $date,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Liefert das Schwarze Brett (Bounty Board):
     * Reguläre Bounties (ohne festen Besitzer) sowie überfällige Rettungs-Quests von Geschwistern.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBountyBoard(int $currentProfileId): array
    {
        // Aufgaben, die als Bounty markiert sind und noch nicht abgeschlossen sind
        $stmt = $this->db->getConnection()->prepare("
            SELECT t.*, 
                   po.display_name AS origin_name, po.avatar_icon AS origin_avatar, po.color AS origin_color,
                   pc.display_name AS claimed_name, pc.avatar_icon AS claimed_avatar
            FROM gamification_tasks t
            LEFT JOIN gamification_profiles po ON t.origin_profile_id = po.id
            LEFT JOIN gamification_profiles pc ON t.claimed_by_profile_id = pc.id
            WHERE t.is_bounty = 1
              AND t.status IN ('planned', 'escalated', 'in_progress')
              AND (
                  t.claimed_by_profile_id IS NULL 
                  OR t.claimed_by_profile_id = :current_profile_id
                  OR t.claimed_at < DATE_SUB(NOW(), INTERVAL 12 HOUR)
              )
            ORDER BY 
              CASE WHEN t.status = 'escalated' THEN 1 ELSE 2 END,
              t.due_date ASC,
              t.id DESC
        ");
        $stmt->execute(['current_profile_id' => $currentProfileId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Liefert alle Aufgaben, die auf Prüfung/Freigabe der Eltern warten.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPendingReviewTasks(): array
    {
        $stmt = $this->db->getConnection()->query("
            SELECT t.*, 
                   pa.display_name AS assigned_name, pa.avatar_icon AS assigned_avatar, pa.color AS assigned_color,
                   po.display_name AS origin_name, po.avatar_icon AS origin_avatar,
                   pc.display_name AS claimed_name, pc.avatar_icon AS claimed_avatar,
                   (SELECT COUNT(*) FROM gamification_task_helpers h WHERE h.task_id = t.id AND h.status = 'pending') AS pending_helpers_count
            FROM gamification_tasks t
            LEFT JOIN gamification_profiles pa ON t.assigned_profile_id = pa.id
            LEFT JOIN gamification_profiles po ON t.origin_profile_id = po.id
            LEFT JOIN gamification_profiles pc ON t.claimed_by_profile_id = pc.id
            WHERE t.status IN ('submitted', 'in_review')
            ORDER BY t.submitted_at ASC, t.id ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Liefert die zuletzt abgeschlossenen Aufgaben.
     */
    public function getRecentCompletedTasks(int $limit = 20): array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT t.*, 
                   pa.display_name AS assigned_name, pa.avatar_icon AS assigned_avatar,
                   pc.display_name AS claimed_name, pc.avatar_icon AS claimed_avatar
            FROM gamification_tasks t
            LEFT JOIN gamification_profiles pa ON t.assigned_profile_id = pa.id
            LEFT JOIN gamification_profiles pc ON t.claimed_by_profile_id = pc.id
            WHERE t.status = 'completed'
            ORDER BY t.completed_at DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Liefert alle noch offenen Aufgaben aller Kind-Profile für die Eltern-Übersicht.
     * Gibt eine flache Liste zurück, sortiert nach Profil-ID, dann Status und Fälligkeit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllActiveTasksByProfile(): array
    {
        $stmt = $this->db->getConnection()->query("
            SELECT t.*,
                   pa.display_name AS assigned_name,
                   pa.avatar_icon  AS assigned_avatar,
                   pa.color        AS assigned_color,
                   pc.display_name AS claimed_name
            FROM gamification_tasks t
            LEFT JOIN gamification_profiles pa ON t.assigned_profile_id = pa.id
            LEFT JOIN gamification_profiles pc ON t.claimed_by_profile_id = pc.id
            WHERE pa.role = 'child'
              AND t.status IN ('planned', 'in_progress')
              AND t.is_bounty = 0
            ORDER BY
                t.assigned_profile_id ASC,
                CASE WHEN t.status = 'in_progress' THEN 1 ELSE 2 END,
                t.due_date ASC,
                t.due_time ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Löscht eine Aufgabe vollständig (inkl. Helfer-Einträge und Koch-Rezept).
     * Nur für Admins im Test-/Entwicklungsmodus gedacht.
     */
    public function deleteTask(int $taskId): bool
    {
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            // Abhängige Zeilen vorher entfernen
            $pdo->prepare("DELETE FROM gamification_task_helpers WHERE task_id = :id")->execute([':id' => $taskId]);
            $pdo->prepare("DELETE FROM gamification_cooking_recipes WHERE task_id = :id")->execute([':id' => $taskId]);
            $stmt = $pdo->prepare("DELETE FROM gamification_tasks WHERE id = :id");
            $stmt->execute([':id' => $taskId]);
            $pdo->commit();
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Sichert sich eine Quest vom Schwarzen Brett (Claiming mit 12-Stunden-Lock).
     */
    public function claimTask(int $taskId, int $profileId): bool
    {
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            $task = $this->getTaskById($taskId);
            if (!$task || (int)$task['is_bounty'] !== 1) {
                $pdo->rollBack();
                return false;
            }

            // Wenn bereits von jemand anderem innerhalb der letzten 12h beansprucht, ablehnen
            if (!empty($task['claimed_by_profile_id'])
                && (int)$task['claimed_by_profile_id'] !== $profileId
                && !empty($task['claimed_at'])
                && strtotime($task['claimed_at']) > strtotime('-12 hours')
            ) {
                $pdo->rollBack();
                return false;
            }

            $stmt = $pdo->prepare("
                UPDATE gamification_tasks SET
                    claimed_by_profile_id = :pid,
                    claimed_at = NOW(),
                    status = 'in_progress'
                WHERE id = :id
            ");
            $stmt->execute([
                'pid' => $profileId,
                'id' => $taskId,
            ]);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Gibt eine beanspruchte Quest wieder frei für das Schwarze Brett.
     */
    public function releaseClaim(int $taskId, int $profileId): bool
    {
        $stmt = $this->db->getConnection()->prepare("
            UPDATE gamification_tasks SET
                claimed_by_profile_id = NULL,
                claimed_at = NULL,
                status = CASE WHEN origin_profile_id IS NOT NULL THEN 'escalated' ELSE 'planned' END
            WHERE id = :id AND claimed_by_profile_id = :pid AND status = 'in_progress'
        ");
        return $stmt->execute([
            'id' => $taskId,
            'pid' => $profileId,
        ]);
    }

    /**
     * Reicht eine Aufgabe als erledigt zur Prüfung bei den Eltern ein.
     */
    public function submitTask(int $taskId, int $profileId, ?string $notes = null): bool
    {
        $stmt = $this->db->getConnection()->prepare("
            UPDATE gamification_tasks SET
                status = 'submitted',
                submitted_at = NOW(),
                submission_notes = :notes
            WHERE id = :id 
              AND (assigned_profile_id = :pid OR claimed_by_profile_id = :pid2)
              AND status IN ('planned', 'in_progress', 'escalated')
        ");

        return $stmt->execute([
            'id' => $taskId,
            'pid' => $profileId,
            'pid2' => $profileId,
            'notes' => $notes ? trim($notes) : null,
        ]);
    }

    /**
     * Erstellt eine spontane Erledigungsmeldung („Chore Pitch“ / „Ich habe geholfen!“).
     */
    public function createPitch(
        int $profileId,
        string $title,
        ?string $description = null,
        string $category = 'haushalt',
        ?string $notes = null
    ): int {
        $stmt = $this->db->getConnection()->prepare("
            INSERT INTO gamification_tasks (
                title, description, category,
                assigned_profile_id, origin_profile_id, status, is_bounty,
                due_date, base_xp, base_coins, initiative_bonus_coins,
                submission_notes, submitted_at
            ) VALUES (
                :title, :description, :category,
                :pid, :pid2, 'submitted', 0,
                CURRENT_DATE(), 40, 15, 15,
                :notes, NOW()
            )
        ");
        $stmt->execute([
            'title' => trim($title),
            'description' => $description ? trim($description) : null,
            'category' => $category,
            'pid' => $profileId,
            'pid2' => $profileId,
            'notes' => $notes ? trim($notes) : 'Spontan erledigt gemeldet',
        ]);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    /**
     * Reicht einen Rezept-Vorschlag für einen Koch-Tag ein.
     */
    public function updateCookingPitch(int $taskId, int $profileId, string $recipeTitle, ?string $recipeDetails): bool
    {
        // Prüfen, ob mehr als 24h vor Fälligkeit (Planungs-Bonus: +10 Coins)
        $task = $this->getTaskById($taskId);
        if (!$task) {
            return false;
        }

        $planningBonus = 0;
        if (!empty($task['due_date'])) {
            $dueTimestamp = strtotime($task['due_date'] . ' ' . ($task['due_time'] ?? '18:00:00'));
            if ($dueTimestamp - time() >= 86400) {
                $planningBonus = 10;
            }
        }

        $stmt = $this->db->getConnection()->prepare("
            UPDATE gamification_tasks SET
                recipe_title = :title,
                recipe_details = :details,
                recipe_status = 'pitched',
                planning_bonus_coins = :bonus
            WHERE id = :id AND assigned_profile_id = :pid
        ");

        return $stmt->execute([
            'title' => trim($recipeTitle),
            'details' => $recipeDetails ? trim($recipeDetails) : null,
            'bonus' => $planningBonus,
            'id' => $taskId,
            'pid' => $profileId,
        ]);
    }

    /**
     * Überprüfung des Rezept-Vorschlags durch die Eltern (Machbarkeits-Check).
     */
    public function reviewCookingPitch(int $taskId, bool $approved, ?string $feedback): bool
    {
        $status = $approved ? 'approved' : 'rejected';
        $stmt = $this->db->getConnection()->prepare("
            UPDATE gamification_tasks SET
                recipe_status = :status,
                parent_feedback = :feedback
            WHERE id = :id AND is_cooking_day = 1
        ");

        return $stmt->execute([
            'status' => $status,
            'feedback' => $feedback ? trim($feedback) : null,
            'id' => $taskId,
        ]);
    }

    /**
     * Bewertet und schließt eine eingereichte Aufgabe ab (Eltern-Triage).
     */
    public function reviewTask(
        int $taskId,
        string $action, // 'approve' oder 'reject'
        ?string $feedback = null,
        ?int $customXp = null,
        ?int $customCoins = null
    ): bool {
        $task = $this->getTaskById($taskId);
        if (!$task || !in_array($task['status'], ['submitted', 'in_review'], true)) {
            return false;
        }

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();

        try {
            if ($action === 'reject') {
                $stmt = $pdo->prepare("
                    UPDATE gamification_tasks SET
                        status = 'rejected',
                        rejection_reason = :reason,
                        parent_feedback = :feedback,
                        reviewed_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    'reason' => $feedback ?: 'Aufgabe noch unvollständig',
                    'feedback' => $feedback,
                    'id' => $taskId,
                ]);

                $pdo->commit();
                return true;
            }

            // Genehmigen: Endgültige Punkte berechnen
            $recipientId = !empty($task['claimed_by_profile_id'])
                ? (int)$task['claimed_by_profile_id']
                : (int)$task['assigned_profile_id'];

            if (!$recipientId) {
                $pdo->rollBack();
                return false;
            }

            $finalXp = $customXp ?? ((int)$task['base_xp'] + (int)$task['bounty_bonus_xp']);
            $finalCoins = $customCoins ?? (
                (int)$task['base_coins']
                + (int)$task['planning_bonus_coins']
                + (int)$task['initiative_bonus_coins']
                + (int)$task['bounty_bonus_coins']
            );

            $stmt = $pdo->prepare("
                UPDATE gamification_tasks SET
                    status = 'completed',
                    final_xp = :final_xp,
                    final_coins = :final_coins,
                    parent_feedback = :feedback,
                    reviewed_at = NOW(),
                    completed_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'final_xp' => $finalXp,
                'final_coins' => $finalCoins,
                'feedback' => $feedback,
                'id' => $taskId,
            ]);

            // Gutschrift auf das Profil
            $reason = "Erledigt: " . $task['title'];
            if (!empty($task['bounty_bonus_coins'])) {
                $reason .= " (inkl. Retter-Bonus)";
            }
            $this->profileRepo->addXpAndCoins($recipientId, $finalXp, $finalCoins, $reason, 'task', $taskId);

            // Zuverlässigkeits-Streak erhöhen
            $this->profileRepo->updateStreak($recipientId, true);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
