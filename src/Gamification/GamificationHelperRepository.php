<?php

namespace Kai\Tools\Gamification;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Repository zur Erfassung freiwilliger Mithilfe von Geschwistern (Co-Op / Assistenten-Bonus).
 */
class GamificationHelperRepository
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
     * Meldet freiwillige Mithilfe bei einer Aufgabe an.
     */
    public function registerHelp(int $taskId, int $helperProfileId, ?string $note = null): int
    {
        // Prüfen, ob bereits gemeldet
        $stmt = $this->db->getConnection()->prepare("
            SELECT id FROM gamification_task_helpers 
            WHERE task_id = :task_id AND helper_profile_id = :helper_id 
            LIMIT 1
        ");
        $stmt->execute([
            'task_id' => $taskId,
            'helper_id' => $helperProfileId,
        ]);

        $existingId = $stmt->fetchColumn();
        if ($existingId) {
            return (int)$existingId;
        }

        $insertStmt = $this->db->getConnection()->prepare("
            INSERT INTO gamification_task_helpers (task_id, helper_profile_id, bonus_xp, bonus_coins, status, note)
            VALUES (:task_id, :helper_id, 25, 10, 'pending', :note)
        ");
        $insertStmt->execute([
            'task_id' => $taskId,
            'helper_id' => $helperProfileId,
            'note' => $note ? trim($note) : 'Freiwillig mitgeholfen',
        ]);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    /**
     * Liefert alle Helfer-Einträge zu einer Aufgabe.
     */
    public function getHelpersForTask(int $taskId): array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT h.*, p.display_name AS helper_name, p.avatar_icon AS helper_avatar
            FROM gamification_task_helpers h
            JOIN gamification_profiles p ON h.helper_profile_id = p.id
            WHERE h.task_id = :task_id
            ORDER BY h.id ASC
        ");
        $stmt->execute(['task_id' => $taskId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Liefert offene Helfer-Einträge, die auf elterliche Bestätigung warten.
     */
    public function getPendingHelpers(): array
    {
        $stmt = $this->db->getConnection()->query("
            SELECT h.*, p.display_name AS helper_name, p.avatar_icon AS helper_avatar,
                   t.title AS task_title
            FROM gamification_task_helpers h
            JOIN gamification_profiles p ON h.helper_profile_id = p.id
            JOIN gamification_tasks t ON h.task_id = t.id
            WHERE h.status = 'pending'
            ORDER BY h.created_at ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Bestätigt oder lehnt eine gemeldete Mithilfe ab (durch Eltern).
     */
    public function reviewHelper(int $helperClaimId, bool $approved, ?int $customCoins = null, ?int $customXp = null): bool
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT h.*, t.title AS task_title
            FROM gamification_task_helpers h
            JOIN gamification_tasks t ON h.task_id = t.id
            WHERE h.id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $helperClaimId]);
        $claim = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$claim || $claim['status'] !== 'pending') {
            return false;
        }

        if (!$approved) {
            $updStmt = $this->db->getConnection()->prepare("
                UPDATE gamification_task_helpers SET status = 'rejected' WHERE id = :id
            ");
            return $updStmt->execute(['id' => $helperClaimId]);
        }

        $coins = $customCoins ?? (int)$claim['bonus_coins'];
        $xp = $customXp ?? (int)$claim['bonus_xp'];

        $updStmt = $this->db->getConnection()->prepare("
            UPDATE gamification_task_helpers SET 
                status = 'confirmed',
                bonus_coins = :coins,
                bonus_xp = :xp
            WHERE id = :id
        ");
        $updStmt->execute([
            'coins' => $coins,
            'xp' => $xp,
            'id' => $helperClaimId,
        ]);

        // Belohnung für die Mithilfe gutschreiben
        $this->profileRepo->addXpAndCoins(
            (int)$claim['helper_profile_id'],
            $xp,
            $coins,
            "Helfer-Bonus: Mithilfe bei '" . $claim['task_title'] . "'",
            'helper',
            $helperClaimId
        );

        return true;
    }
}
