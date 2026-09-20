<?php

namespace Kai\Tools\Gamification;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Log\Logger;
use PDO;

/**
 * Automatisierter Deadline-Watcher und Eskalations-Engine.
 *
 * Prüft überfällige Pflichtaufgaben, setzt Säumnis-Streaks zurück und schiebt
 * Aufgaben mit Retter-Zuschlag (+50 % Bonus) als offene Quests auf das Schwarze Brett.
 */
class GamificationEscalationService
{
    private Database $db;
    private GamificationProfileRepository $profileRepo;
    private Logger $logger;
    private ?ActivityLogger $activityLogger;

    public function __construct(
        ?Database $db = null,
        ?GamificationProfileRepository $profileRepo = null,
        ?Logger $logger = null,
        ?ActivityLogger $activityLogger = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->profileRepo = $profileRepo ?? new GamificationProfileRepository($this->db);
        $this->logger = $logger ?? new Logger();
        $this->activityLogger = $activityLogger;
    }

    /**
     * Führt den Eskalationslauf durch (z. B. aufgerufen über den stündlichen Cronjob).
     *
     * @return array{escalated_count: int, uncurbed_claims: int}
     */
    public function processEscalations(): array
    {
        $pdo = $this->db->getConnection();
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $currentTime = date('H:i:s');

        $escalatedCount = 0;
        $uncurbedClaims = 0;

        // 1. Überfällige persönliche Pflichtaufgaben finden
        // Kriterium: status IN ('planned', 'in_progress'), is_bounty = 0, assigned_profile_id IS NOT NULL,
        // und entweder due_date < today ODER (due_date = today UND due_time <= currentTime)
        $canEscalateCondition = $this->hasCanEscalateColumn($pdo)
            ? "AND (t.can_escalate IS NULL OR t.can_escalate = 1)"
            : "";

        $stmt = $pdo->prepare("
            SELECT t.*, p.display_name AS assigned_name
            FROM gamification_tasks t
            JOIN gamification_profiles p ON t.assigned_profile_id = p.id
            WHERE t.status IN ('planned', 'in_progress')
              AND t.is_bounty = 0
              {$canEscalateCondition}
              AND t.assigned_profile_id IS NOT NULL
              AND t.due_date IS NOT NULL
              AND (
                  t.due_date < :today
                  OR (t.due_date = :today2 AND t.due_time IS NOT NULL AND t.due_time <= :curr_time)
              )
        ");
        $stmt->execute([
            'today' => $today,
            'today2' => $today,
            'curr_time' => $currentTime,
        ]);
        $overdueTasks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($overdueTasks as $task) {
            $taskId = (int)$task['id'];
            $originId = (int)$task['assigned_profile_id'];
            $baseCoins = (int)$task['base_coins'];
            $baseXp = (int)$task['base_xp'];

            // Retter-Zuschlag berechnen (+50 %, mind. 10 Coins und 20 XP)
            $bountyBonusCoins = max(10, (int)round($baseCoins * 0.5));
            $bountyBonusXp = max(20, (int)round($baseXp * 0.5));

            $upd = $pdo->prepare("
                UPDATE gamification_tasks SET
                    status = 'escalated',
                    is_bounty = 1,
                    origin_profile_id = :origin_id,
                    assigned_profile_id = NULL,
                    claimed_by_profile_id = NULL,
                    claimed_at = NULL,
                    bounty_bonus_coins = :bonus_coins,
                    bounty_bonus_xp = :bonus_xp
                WHERE id = :id AND status IN ('planned', 'in_progress')
            ");
            $upd->execute([
                'origin_id' => $originId,
                'bonus_coins' => $bountyBonusCoins,
                'bonus_xp' => $bountyBonusXp,
                'id' => $taskId,
            ]);

            // Zuverlässigkeits-Streak des Säumigen zurücksetzen (pädagogischer Lerneffekt)
            $this->profileRepo->updateStreak($originId, false);

            $this->logger->info("Gamification: Aufgabe {$taskId} ('{$task['title']}') von {$task['assigned_name']} ist überfällig und wandert als Rettungs-Quest auf das Schwarze Brett.");

            if ($this->activityLogger !== null) {
                try {
                    $this->activityLogger->log(
                        'gamification',
                        "Aufgabe '{$task['title']}' von {$task['assigned_name']} ist überfällig und wandert auf das Schwarze Brett (+{$bountyBonusCoins} Retter-Münzen).",
                        ['task_id' => $taskId, 'origin_profile_id' => $originId]
                    );
                } catch (\Throwable $e) {
                    // Stille Ignorierung bei ActivityLogger
                }
            }

            $escalatedCount++;
        }

        // 2. Abgelaufene 12-Stunden-Locks auf dem Schwarzen Brett freigeben
        // Wenn ein Kind eine Bounty beansprucht hat, aber nach 12 Stunden nicht eingereicht hat
        $lockStmt = $pdo->prepare("
            UPDATE gamification_tasks SET
                claimed_by_profile_id = NULL,
                claimed_at = NULL,
                status = CASE WHEN origin_profile_id IS NOT NULL THEN 'escalated' ELSE 'planned' END
            WHERE is_bounty = 1
              AND claimed_by_profile_id IS NOT NULL
              AND status = 'in_progress'
              AND claimed_at < DATE_SUB(NOW(), INTERVAL 12 HOUR)
        ");
        $lockStmt->execute();
        $uncurbedClaims = $lockStmt->rowCount();

        return [
            'escalated_count' => $escalatedCount,
            'uncurbed_claims' => $uncurbedClaims,
        ];
    }

    private function hasCanEscalateColumn(\PDO $pdo): bool
    {
        static $hasCol = null;
        if ($hasCol !== null) {
            return $hasCol;
        }
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM gamification_tasks LIKE 'can_escalate'");
            $hasCol = (bool)$stmt->fetch();
        } catch (\Throwable) {
            $hasCol = false;
        }
        return $hasCol;
    }
}
