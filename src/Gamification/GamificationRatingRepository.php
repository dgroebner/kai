<?php

namespace Kai\Tools\Gamification;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Repository für das blinde Peer-Rating bei Kochtagen.
 */
class GamificationRatingRepository
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
     * Reicht eine Bewertung für ein Familiengericht ein.
     */
    public function submitRating(int $taskId, int $raterProfileId, int $stars, ?string $comment = null): bool
    {
        $stars = max(1, min(5, $stars));
        $stmt = $this->db->getConnection()->prepare("
            INSERT INTO gamification_ratings (task_id, rater_profile_id, rating_stars, comment)
            VALUES (:task_id, :rater_id, :stars, :comment)
            ON DUPLICATE KEY UPDATE 
                rating_stars = :stars_update,
                comment = :comment_update
        ");

        $ok = $stmt->execute([
            'task_id' => $taskId,
            'rater_id' => $raterProfileId,
            'stars' => $stars,
            'comment' => $comment ? trim($comment) : null,
            'stars_update' => $stars,
            'comment_update' => $comment ? trim($comment) : null,
        ]);

        if ($ok) {
            // Kleiner Kritiker-Bonus für das Abgeben von Feedback: +5 XP, +2 Coins
            $this->profileRepo->addXpAndCoins(
                $raterProfileId,
                5,
                2,
                "Kritiker-Bonus: Bewertung für Kochtag abgegeben",
                'rating',
                $taskId
            );
        }

        return $ok;
    }

    /**
     * Prüft, ob ein Profil für eine bestimmte Aufgabe bereits bewertet hat.
     */
    public function hasRated(int $taskId, int $raterProfileId): bool
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT id FROM gamification_ratings 
            WHERE task_id = :task_id AND rater_profile_id = :rater_id 
            LIMIT 1
        ");
        $stmt->execute([
            'task_id' => $taskId,
            'rater_id' => $raterProfileId,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Liefert alle Bewertungen zu einer Kochaufgabe (inkl. Durchschnittsberechnung).
     */
    public function getRatingsForTask(int $taskId): array
    {
        $stmt = $this->db->getConnection()->prepare("
            SELECT r.*, p.display_name AS rater_name, p.avatar_icon AS rater_avatar
            FROM gamification_ratings r
            JOIN gamification_profiles p ON r.rater_profile_id = p.id
            WHERE r.task_id = :task_id
            ORDER BY r.created_at ASC
        ");
        $stmt->execute(['task_id' => $taskId]);
        $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $count = count($ratings);
        $sum = 0;
        foreach ($ratings as $r) {
            $sum += (int)$r['rating_stars'];
        }
        $average = $count > 0 ? round($sum / $count, 1) : 0.0;

        return [
            'count' => $count,
            'average' => $average,
            'ratings' => $ratings,
        ];
    }
}
