<?php

namespace Kai\Tools\Gamification;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Repository zur Verwaltung von Profilen, XP-, Münzguthaben und Streaks im Gamification-Ökosystem.
 */
class GamificationProfileRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Sucht ein Profil anhand der E-Mail-Adresse.
     */
    public function getProfileByEmail(string $email): ?array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT * FROM gamification_profiles WHERE user_email = :email LIMIT 1
        ");
        $stmt->execute(['email' => strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Sucht ein Profil anhand der ID.
     */
    public function getProfileById(int $id): ?array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT * FROM gamification_profiles WHERE id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Liefert alle Profile zurück (z. B. für das Eltern-Dashboard).
     */
    public function getAllProfiles(): array
    {
        $stmt = $this->db->getConnection()->query("
            SELECT * FROM gamification_profiles ORDER BY role ASC, display_name ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Liefert ausschließlich die Kinder-Profile zurück.
     */
    public function getChildProfiles(): array
    {
        $stmt = $this->db->getConnection()->query("
            SELECT * FROM gamification_profiles WHERE role = 'child' ORDER BY display_name ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Ermittelt oder erstellt automatisch ein Profil beim Aufruf.
     */
    public function getOrCreateProfile(string $email, ?string $name = null, bool $isAdmin = false): array
    {
        $email = strtolower(trim($email));
        $existing = $this->getProfileByEmail($email);
        if ($existing !== null) {
            return $existing;
        }

        // Standard-Rolle bestimmen
        $role = $isAdmin ? 'parent' : 'child';
        $displayName = $name && trim($name) !== '' ? trim($name) : explode('@', $email)[0];
        $avatar = $role === 'parent' ? '👑' : '⭐';
        $color = $role === 'parent' ? '#f59e0b' : '#3b82f6';

        $stmt = $this->db->getConnection()->prepare("
            INSERT INTO gamification_profiles (user_email, display_name, role, avatar_icon, color, xp, coins, streak_days)
            VALUES (:email, :name, :role, :avatar, :color, 0, 0, 0)
        ");
        $stmt->execute([
            'email' => $email,
            'name' => $displayName,
            'role' => $role,
            'avatar' => $avatar,
            'color' => $color,
        ]);

        $newId = (int)$this->db->getConnection()->lastInsertId();
        return $this->getProfileById($newId) ?? [];
    }

    /**
     * Aktualisiert Profildaten (z. B. Anzeigename, Icon, Farbe).
     */
    public function updateProfile(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        if (isset($data['display_name'])) {
            $fields[] = "display_name = :display_name";
            $params['display_name'] = trim($data['display_name']);
        }
        if (isset($data['role']) && in_array($data['role'], ['parent', 'child'], true)) {
            $fields[] = "role = :role";
            $params['role'] = $data['role'];
        }
        if (isset($data['avatar_icon'])) {
            $fields[] = "avatar_icon = :avatar_icon";
            $params['avatar_icon'] = trim($data['avatar_icon']);
        }
        if (isset($data['color'])) {
            $fields[] = "color = :color";
            $params['color'] = trim($data['color']);
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE gamification_profiles SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->getConnection()->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Schreibt XP und Münzen gut und erfasst die Buchung im Audit-Journal.
     */
    public function addXpAndCoins(
        int $id,
        int $xp,
        int $coins,
        string $reason,
        ?string $refType = null,
        ?int $refId = null
    ): void {
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE gamification_profiles 
                SET xp = xp + :xp, coins = coins + :coins 
                WHERE id = :id
            ");
            $stmt->execute([
                'xp' => max(0, $xp),
                'coins' => max(0, $coins),
                'id' => $id,
            ]);

            $txStmt = $pdo->prepare("
                INSERT INTO gamification_transactions (profile_id, amount_xp, amount_coins, reason, reference_type, reference_id)
                VALUES (:profile_id, :amount_xp, :amount_coins, :reason, :ref_type, :ref_id)
            ");
            $txStmt->execute([
                'profile_id' => $id,
                'amount_xp' => $xp,
                'amount_coins' => $coins,
                'reason' => $reason,
                'ref_type' => $refType,
                'ref_id' => $refId,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Zieht Münzen ab (z. B. für Prämieneinlösung).
     */
    public function deductCoins(
        int $id,
        int $coins,
        string $reason,
        ?string $refType = null,
        ?int $refId = null
    ): bool {
        if ($coins <= 0) {
            return true;
        }

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            $profile = $this->getProfileById($id);
            if (!$profile || $profile['coins'] < $coins) {
                $pdo->rollBack();
                return false;
            }

            $stmt = $pdo->prepare("
                UPDATE gamification_profiles 
                SET coins = coins - :coins 
                WHERE id = :id AND coins >= :coins_check
            ");
            $stmt->execute([
                'coins' => $coins,
                'id' => $id,
                'coins_check' => $coins,
            ]);

            $txStmt = $pdo->prepare("
                INSERT INTO gamification_transactions (profile_id, amount_xp, amount_coins, reason, reference_type, reference_id)
                VALUES (:profile_id, 0, :amount_coins, :reason, :ref_type, :ref_id)
            ");
            $txStmt->execute([
                'profile_id' => $id,
                'amount_coins' => -$coins,
                'reason' => $reason,
                'ref_type' => $refType,
                'ref_id' => $refId,
            ]);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Aktualisiert den Zuverlässigkeits-Streak (Erhöhen bei Erledigung, Zurücksetzen bei Versäumnis).
     */
    public function updateStreak(int $id, bool $increment): void
    {
        $today = date('Y-m-d');
        if ($increment) {
            $profile = $this->getProfileById($id);
            if (!$profile) {
                return;
            }

            // Wenn heute bereits eine Aufgabe den Streak erhöht hat, nicht mehrfach pro Tag hochzählen
            if ($profile['last_completed_date'] === $today) {
                return;
            }

            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $newStreak = ($profile['last_completed_date'] === $yesterday || $profile['streak_days'] === 0)
                ? $profile['streak_days'] + 1
                : 1;

            $stmt = $this->db->getConnection()->prepare("
                UPDATE gamification_profiles 
                SET streak_days = :streak, last_completed_date = :today 
                WHERE id = :id
            ");
            $stmt->execute([
                'streak' => $newStreak,
                'today' => $today,
                'id' => $id,
            ]);
        } else {
            // Pädagogischer Streak-Reset bei Fristversäumnis
            $stmt = $this->db->getConnection()->prepare("
                UPDATE gamification_profiles 
                SET streak_days = 0 
                WHERE id = :id
            ");
            $stmt->execute(['id' => $id]);
        }
    }

    /**
     * Berechnet das Level basierend auf gesammelten Erfahrungspunkten (XP).
     */
    public function calculateLevel(int $xp): int
    {
        if ($xp <= 0) {
            return 1;
        }
        // Progression: Level 1 (0 XP), Level 2 (100 XP), Level 3 (300 XP), Level 4 (600 XP), etc.
        // Formel: Level = floor(sqrt(xp / 50)) + 1
        return (int)floor(sqrt($xp / 50)) + 1;
    }

    /**
     * Liefert detaillierte Fortschrittsdaten für den XP-Balken.
     */
    public function calculateLevelProgress(int $xp): array
    {
        $level = $this->calculateLevel($xp);
        $xpForCurrentLevel = ($level - 1) * ($level - 1) * 50;
        $xpForNextLevel = $level * $level * 50;
        $diff = max(1, $xpForNextLevel - $xpForCurrentLevel);
        $currentInLevel = max(0, $xp - $xpForCurrentLevel);
        $pct = min(100, (int)round(($currentInLevel / $diff) * 100));

        // Titel-Rang ermitteln
        $titles = [
            1 => 'Neuling',
            2 => 'Aufgaben-Entdecker',
            3 => 'Haushalts-Ritter',
            4 => 'Mitmach-Profi',
            5 => 'Familien-Champion',
            6 => 'Meister der Pflichten',
            7 => 'Großmeister',
            8 => 'Legende',
        ];
        $rankTitle = $titles[min(8, $level)] ?? 'Ultimativer Alltags-Held';

        return [
            'level' => $level,
            'rank_title' => $rankTitle,
            'xp_current' => $xp,
            'xp_current_level' => $xpForCurrentLevel,
            'xp_next_level' => $xpForNextLevel,
            'xp_remaining' => max(0, $xpForNextLevel - $xp),
            'percent' => $pct,
        ];
    }

    /**
     * Liefert die letzten Punkte- und Münztransaktionen für ein Profil.
     */
    public function getTransactionHistory(int $profileId, int $limit = 20): array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT * FROM gamification_transactions 
            WHERE profile_id = :profile_id 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
