<?php

namespace Kai\Tools\Gamification;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Regelbasierte Achievement- & Abzeichen-Engine.
 * Wertet datengetriebene Bedingungen aus und schüttet Trophäen und Boni aus.
 */
class GamificationAchievementService
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
     * Liefert alle konfigurierten Abzeichen.
     */
    public function getAllAchievements(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM gamification_achievements";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY reward_xp ASC, title ASC";

        $stmt = $this->db->getConnection()->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Speichert oder bearbeitet eine Achievement-Regel.
     */
    public function saveAchievement(array $data): int
    {
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $keyName = trim($data['key_name'] ?? '');
        $title = trim($data['title'] ?? '');
        $description = trim($data['description'] ?? '');
        $icon = trim($data['icon'] ?? '🏆');
        $metricType = $data['metric_type'] ?? 'task_count';
        $metricTarget = max(1, (int)($data['metric_target'] ?? 1));
        $metricParam = !empty($data['metric_parameter']) ? trim($data['metric_parameter']) : null;
        $rewardXp = max(0, (int)($data['reward_xp'] ?? 100));
        $rewardCoins = max(0, (int)($data['reward_coins'] ?? 50));
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;

        if ($id) {
            $stmt = $this->db->getConnection()->prepare("
                UPDATE gamification_achievements SET
                    title = :title,
                    description = :description,
                    icon = :icon,
                    metric_type = :metric_type,
                    metric_target = :metric_target,
                    metric_parameter = :metric_parameter,
                    reward_xp = :reward_xp,
                    reward_coins = :reward_coins,
                    is_active = :is_active
                WHERE id = :id
            ");
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'icon' => $icon,
                'metric_type' => $metricType,
                'metric_target' => $metricTarget,
                'metric_parameter' => $metricParam,
                'reward_xp' => $rewardXp,
                'reward_coins' => $rewardCoins,
                'is_active' => $isActive,
                'id' => $id,
            ]);
            return $id;
        }

        if ($keyName === '') {
            $keyName = 'ach_' . substr(md5($title . time()), 0, 8);
        }

        $stmt = $this->db->getConnection()->prepare("
            INSERT INTO gamification_achievements (
                key_name, title, description, icon, metric_type,
                metric_target, metric_parameter, reward_xp, reward_coins, is_active
            ) VALUES (
                :key_name, :title, :description, :icon, :metric_type,
                :metric_target, :metric_parameter, :reward_xp, :reward_coins, :is_active
            )
        ");
        $stmt->execute([
            'key_name' => $keyName,
            'title' => $title,
            'description' => $description,
            'icon' => $icon,
            'metric_type' => $metricType,
            'metric_target' => $metricTarget,
            'metric_parameter' => $metricParam,
            'reward_xp' => $rewardXp,
            'reward_coins' => $rewardCoins,
            'is_active' => $isActive,
        ]);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    /**
     * Löscht ein Achievement.
     */
    public function deleteAchievement(int $id): bool
    {
        $stmt = $this->db->getConnection()->prepare("
            DELETE FROM gamification_achievements WHERE id = :id
        ");
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Prüft alle Regeln für ein Profil und schüttet neu freigeschaltete Badges aus.
     *
     * @return array<int, array<string, mixed>> Neu freigeschaltete Abzeichen
     */
    public function checkAndAwardAchievements(int $profileId): array
    {
        $profile = $this->profileRepo->getProfileById($profileId);
        if (!$profile) {
            return [];
        }

        $achievements = $this->getAllAchievements(true);
        $pdo = $this->db->getConnection();

        // Bereits freigeschaltete IDs ermitteln
        $stmt = $pdo->prepare("
            SELECT achievement_id FROM gamification_profile_achievements WHERE profile_id = :pid
        ");
        $stmt->execute(['pid' => $profileId]);
        $unlockedIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $newlyUnlocked = [];

        foreach ($achievements as $ach) {
            if (in_array((int)$ach['id'], array_map('intval', $unlockedIds), true)) {
                continue; // bereits vorhanden
            }

            $currentVal = $this->calculateMetricValue($profileId, $ach, $profile);
            $targetVal = (int)$ach['metric_target'];

            if ($currentVal >= $targetVal) {
                // Freischalten!
                $insStmt = $pdo->prepare("
                    INSERT IGNORE INTO gamification_profile_achievements (profile_id, achievement_id)
                    VALUES (:pid, :aid)
                ");
                $insStmt->execute([
                    'pid' => $profileId,
                    'aid' => $ach['id'],
                ]);

                // Belohnung gutschreiben
                $rewardXp = (int)$ach['reward_xp'];
                $rewardCoins = (int)$ach['reward_coins'];
                if ($rewardXp > 0 || $rewardCoins > 0) {
                    $this->profileRepo->addXpAndCoins(
                        $profileId,
                        $rewardXp,
                        $rewardCoins,
                        "Erfolg freigeschaltet: " . $ach['title'],
                        'achievement',
                        (int)$ach['id']
                    );
                }

                $newlyUnlocked[] = $ach;
            }
        }

        return $newlyUnlocked;
    }

    /**
     * Liefert alle Abzeichen mit dem aktuellen Fortschritt des Profils (für die Trophäenwand).
     */
    public function getProfileAchievementsWithProgress(int $profileId): array
    {
        $profile = $this->profileRepo->getProfileById($profileId);
        if (!$profile) {
            return [];
        }

        $achievements = $this->getAllAchievements(true);
        $pdo = $this->db->getConnection();

        $stmt = $pdo->prepare("
            SELECT achievement_id, unlocked_at 
            FROM gamification_profile_achievements 
            WHERE profile_id = :pid
        ");
        $stmt->execute(['pid' => $profileId]);
        $unlockedMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $unlockedMap[(int)$row['achievement_id']] = $row['unlocked_at'];
        }

        $results = [];
        foreach ($achievements as $ach) {
            $id = (int)$ach['id'];
            $isUnlocked = isset($unlockedMap[$id]);
            $currentVal = $this->calculateMetricValue($profileId, $ach, $profile);
            $targetVal = (int)$ach['metric_target'];
            $pct = $isUnlocked ? 100 : min(100, (int)round(($currentVal / max(1, $targetVal)) * 100));

            $results[] = [
                'id' => $id,
                'title' => $ach['title'],
                'description' => $ach['description'],
                'icon' => $ach['icon'],
                'reward_xp' => (int)$ach['reward_xp'],
                'reward_coins' => (int)$ach['reward_coins'],
                'is_unlocked' => $isUnlocked,
                'unlocked_at' => $unlockedMap[$id] ?? null,
                'current_value' => $currentVal,
                'target_value' => $targetVal,
                'percent' => $pct,
            ];
        }

        return $results;
    }

    /**
     * Ermittelt den aktuellen Zahlenwert für eine Metrik.
     */
    private function calculateMetricValue(int $profileId, array $achievement, array $profile): int
    {
        $pdo = $this->db->getConnection();
        $metricType = $achievement['metric_type'];

        switch ($metricType) {
            case 'rescue_count':
                // Rette überfällige Aufgabe eines Geschwisterkinds
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM gamification_tasks 
                    WHERE status = 'completed'
                      AND claimed_by_profile_id = :pid 
                      AND origin_profile_id IS NOT NULL 
                      AND origin_profile_id != :pid2
                      AND bounty_bonus_coins > 0
                ");
                $stmt->execute(['pid' => $profileId, 'pid2' => $profileId]);
                return (int)$stmt->fetchColumn();

            case 'task_count':
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM gamification_tasks 
                    WHERE status = 'completed' 
                      AND (
                          (assigned_profile_id = :pid AND claimed_by_profile_id IS NULL)
                          OR claimed_by_profile_id = :pid2
                      )
                ");
                $stmt->execute(['pid' => $profileId, 'pid2' => $profileId]);
                return (int)$stmt->fetchColumn();

            case 'category_count':
                $cat = $achievement['metric_parameter'] ?? 'haushalt';
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM gamification_tasks 
                    WHERE status = 'completed' 
                      AND category = :cat
                      AND (
                          (assigned_profile_id = :pid AND claimed_by_profile_id IS NULL)
                          OR claimed_by_profile_id = :pid2
                      )
                ");
                $stmt->execute(['cat' => $cat, 'pid' => $profileId, 'pid2' => $profileId]);
                return (int)$stmt->fetchColumn();

            case 'streak_days':
                return (int)($profile['streak_days'] ?? 0);

            case 'initiative_count':
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM gamification_tasks 
                    WHERE status = 'completed' 
                      AND origin_profile_id = :pid 
                      AND initiative_bonus_coins > 0
                ");
                $stmt->execute(['pid' => $profileId]);
                return (int)$stmt->fetchColumn();

            case 'xp_total':
                return (int)($profile['xp'] ?? 0);

            default:
                return 0;
        }
    }
}
