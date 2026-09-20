<?php

namespace Kai\Tools\Gamification;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Repository zur Verwaltung des Prämienkatalogs und des Einlöse-Workflows.
 */
class GamificationRewardRepository
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
     * Liefert alle Prämien aus dem Katalog.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllRewards(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM gamification_rewards";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY coin_cost ASC, title ASC";

        $stmt = $this->db->getConnection()->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Sucht eine Belohnung anhand der ID.
     */
    public function getRewardById(int $id): ?array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT * FROM gamification_rewards WHERE id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Speichert oder aktualisiert eine Prämie im Katalog.
     */
    public function saveReward(array $data): int
    {
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $title = trim($data['title'] ?? '');
        $description = trim($data['description'] ?? '');
        $coinCost = max(1, (int)($data['coin_cost'] ?? 50));
        $icon = trim($data['icon'] ?? '🎁');
        $type = in_array($data['type'] ?? '', ['voucher', 'privilege', 'allowance', 'event', 'item'], true)
            ? $data['type']
            : 'privilege';
        $minAge = !empty($data['min_age']) ? (int)$data['min_age'] : null;
        $cooldown = max(0, (int)($data['cooldown_days'] ?? 0));
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;

        if ($id) {
            $stmt = $this->db->getConnection()->prepare("
                UPDATE gamification_rewards SET
                    title = :title,
                    description = :description,
                    coin_cost = :coin_cost,
                    icon = :icon,
                    type = :type,
                    min_age = :min_age,
                    cooldown_days = :cooldown_days,
                    is_active = :is_active
                WHERE id = :id
            ");
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'coin_cost' => $coinCost,
                'icon' => $icon,
                'type' => $type,
                'min_age' => $minAge,
                'cooldown_days' => $cooldown,
                'is_active' => $isActive,
                'id' => $id,
            ]);
            return $id;
        }

        $stmt = $this->db->getConnection()->prepare("
            INSERT INTO gamification_rewards (
                title, description, coin_cost, icon, type, min_age, cooldown_days, is_active
            ) VALUES (
                :title, :description, :coin_cost, :icon, :type, :min_age, :cooldown_days, :is_active
            )
        ");
        $stmt->execute([
            'title' => $title,
            'description' => $description,
            'coin_cost' => $coinCost,
            'icon' => $icon,
            'type' => $type,
            'min_age' => $minAge,
            'cooldown_days' => $cooldown,
            'is_active' => $isActive,
        ]);

        return (int)$this->db->getConnection()->lastInsertId();
    }

    /**
     * Löscht eine Prämie.
     */
    public function deleteReward(int $id): bool
    {
        $stmt = $this->db->getConnection()->prepare("
            DELETE FROM gamification_rewards WHERE id = :id
        ");
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Stellt einen Antrag auf Einlösung einer Prämie (inkl. Münzreservierung).
     */
    public function requestRedemption(int $rewardId, int $profileId, ?string $note = null): array
    {
        $reward = $this->getRewardById($rewardId);
        if (!$reward || (int)$reward['is_active'] !== 1) {
            return ['success' => false, 'message' => 'Diese Prämie ist derzeit nicht verfügbar.'];
        }

        $profile = $this->profileRepo->getProfileById($profileId);
        if (!$profile) {
            return ['success' => false, 'message' => 'Profil nicht gefunden.'];
        }

        $cost = (int)$reward['coin_cost'];
        if ((int)$profile['coins'] < $cost) {
            return ['success' => false, 'message' => 'Nicht genügend Münzen vorhanden (' . $profile['coins'] . ' von ' . $cost . ' benötigt).'];
        }

        // Cooldown-Prüfung
        if ((int)$reward['cooldown_days'] > 0) {
            $checkStmt = $this->db->getConnection()->prepare("
                SELECT created_at FROM gamification_redemptions 
                WHERE reward_id = :reward_id AND profile_id = :profile_id AND status != 'rejected'
                ORDER BY created_at DESC LIMIT 1
            ");
            $checkStmt->execute([
                'reward_id' => $rewardId,
                'profile_id' => $profileId,
            ]);
            $lastRedeemed = $checkStmt->fetchColumn();
            if ($lastRedeemed) {
                $daysDiff = (time() - strtotime($lastRedeemed)) / 86400;
                if ($daysDiff < (int)$reward['cooldown_days']) {
                    $waitDays = (int)ceil((int)$reward['cooldown_days'] - $daysDiff);
                    return ['success' => false, 'message' => "Diese Prämie kann erst in {$waitDays} Tag(en) erneut eingelöst werden."];
                }
            }
        }

        // Münzen reservieren
        $deducted = $this->profileRepo->deductCoins(
            $profileId,
            $cost,
            "Münzen reserviert für Prämie: " . $reward['title'],
            'redemption_reserve',
            $rewardId
        );

        if (!$deducted) {
            return ['success' => false, 'message' => 'Münzen konnten nicht abgebucht werden.'];
        }

        // Antrag anlegen
        $stmt = $this->db->getConnection()->prepare("
            INSERT INTO gamification_redemptions (reward_id, profile_id, coin_cost, status, request_note)
            VALUES (:reward_id, :profile_id, :coin_cost, 'requested', :note)
        ");
        $stmt->execute([
            'reward_id' => $rewardId,
            'profile_id' => $profileId,
            'coin_cost' => $cost,
            'note' => $note ? trim($note) : null,
        ]);

        $redemptionId = (int)$this->db->getConnection()->lastInsertId();

        return [
            'success' => true,
            'message' => 'Antrag erfolgreich eingereicht! Deine Eltern prüfen ihn in Kürze.',
            'redemption_id' => $redemptionId,
        ];
    }

    /**
     * Liefert alle offenen Einlöseanträge (für Eltern).
     */
    public function getPendingRedemptions(): array
    {
        $stmt = $this->db->getConnection()->query("
            SELECT red.*, r.title AS reward_title, r.icon AS reward_icon,
                   p.display_name AS profile_name, p.avatar_icon AS profile_avatar, p.color AS profile_color
            FROM gamification_redemptions red
            JOIN gamification_rewards r ON red.reward_id = r.id
            JOIN gamification_profiles p ON red.profile_id = p.id
            WHERE red.status = 'requested'
            ORDER BY red.created_at ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Liefert Einlöseanträge eines bestimmten Profils.
     */
    public function getRedemptionsForProfile(int $profileId, int $limit = 10): array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT red.*, r.title AS reward_title, r.icon AS reward_icon
            FROM gamification_redemptions red
            JOIN gamification_rewards r ON red.reward_id = r.id
            WHERE red.profile_id = :profile_id
            ORDER BY red.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Bearbeitet einen Einlöseantrag (Genehmigen / Ablehnen).
     */
    public function reviewRedemption(
        int $redemptionId,
        string $action, // 'approve' oder 'reject'
        string $reviewerEmail,
        ?string $parentNote = null
    ): bool {
        $stmt = $this->db->getConnection()->prepare("
            SELECT red.*, r.title AS reward_title 
            FROM gamification_redemptions red
            JOIN gamification_rewards r ON red.reward_id = r.id
            WHERE red.id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $redemptionId]);
        $redemption = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$redemption || $redemption['status'] !== 'requested') {
            return false;
        }

        $profileId = (int)$redemption['profile_id'];
        $cost = (int)$redemption['coin_cost'];

        if ($action === 'reject') {
            // Münzen zurückerstatten
            $this->profileRepo->addXpAndCoins(
                $profileId,
                0,
                $cost,
                "Rückerstattung: Prämie '" . $redemption['reward_title'] . "' abgelehnt",
                'redemption_refund',
                $redemptionId
            );

            $updStmt = $this->db->getConnection()->prepare("
                UPDATE gamification_redemptions SET
                    status = 'rejected',
                    parent_note = :parent_note,
                    reviewed_by_email = :email,
                    reviewed_at = NOW()
                WHERE id = :id
            ");
            return $updStmt->execute([
                'parent_note' => $parentNote ? trim($parentNote) : 'Leider abgelehnt',
                'email' => $reviewerEmail,
                'id' => $redemptionId,
            ]);
        }

        // Genehmigen
        if (stripos($redemption['reward_title'], 'Streak-Schild') !== false) {
            $this->profileRepo->updateStreakShields($profileId, 1);
        }

        $updStmt = $this->db->getConnection()->prepare("
            UPDATE gamification_redemptions SET
                status = 'approved',
                parent_note = :parent_note,
                reviewed_by_email = :email,
                reviewed_at = NOW()
            WHERE id = :id
        ");
        return $updStmt->execute([
            'parent_note' => $parentNote ? trim($parentNote) : 'Viel Spaß damit!',
            'email' => $reviewerEmail,
            'id' => $redemptionId,
        ]);
    }
}
