<?php

namespace Kai\Tools\Gamification;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Log\Logger;

/**
 * Zentraler Orchestrator und Service-Fassade für die Domain Gamification (Familien-Quests).
 */
class GamificationService
{
    private Database $db;
    private GamificationProfileRepository $profileRepo;
    private GamificationTaskRepository $taskRepo;
    private GamificationTemplateRepository $templateRepo;
    private GamificationHelperRepository $helperRepo;
    private GamificationRatingRepository $ratingRepo;
    private GamificationRewardRepository $rewardRepo;
    private GamificationAchievementService $achievementService;
    private GamificationEscalationService $escalationService;

    public function __construct(
        ?Database $db = null,
        ?GamificationProfileRepository $profileRepo = null,
        ?GamificationTaskRepository $taskRepo = null,
        ?GamificationTemplateRepository $templateRepo = null,
        ?GamificationHelperRepository $helperRepo = null,
        ?GamificationRatingRepository $ratingRepo = null,
        ?GamificationRewardRepository $rewardRepo = null,
        ?GamificationAchievementService $achievementService = null,
        ?GamificationEscalationService $escalationService = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->profileRepo = $profileRepo ?? new GamificationProfileRepository($this->db);
        $this->taskRepo = $taskRepo ?? new GamificationTaskRepository($this->db, $this->profileRepo);
        $this->templateRepo = $templateRepo ?? new GamificationTemplateRepository($this->db);
        $this->helperRepo = $helperRepo ?? new GamificationHelperRepository($this->db, $this->profileRepo);
        $this->ratingRepo = $ratingRepo ?? new GamificationRatingRepository($this->db, $this->profileRepo);
        $this->rewardRepo = $rewardRepo ?? new GamificationRewardRepository($this->db, $this->profileRepo);
        $this->achievementService = $achievementService ?? new GamificationAchievementService($this->db, $this->profileRepo);
        $this->escalationService = $escalationService ?? new GamificationEscalationService($this->db, $this->profileRepo);
    }

    public function getProfileRepository(): GamificationProfileRepository
    {
        return $this->profileRepo;
    }

    public function getTaskRepository(): GamificationTaskRepository
    {
        return $this->taskRepo;
    }

    public function getTemplateRepository(): GamificationTemplateRepository
    {
        return $this->templateRepo;
    }

    public function getHelperRepository(): GamificationHelperRepository
    {
        return $this->helperRepo;
    }

    public function getRatingRepository(): GamificationRatingRepository
    {
        return $this->ratingRepo;
    }

    public function getRewardRepository(): GamificationRewardRepository
    {
        return $this->rewardRepo;
    }

    public function getAchievementService(): GamificationAchievementService
    {
        return $this->achievementService;
    }

    public function getEscalationService(): GamificationEscalationService
    {
        return $this->escalationService;
    }

    private static bool $schemaChecked = false;

    /**
     * Stellt sicher, dass neue Spalten wie can_escalate in der Datenbank vorhanden sind.
     */
    public function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->query("SHOW COLUMNS FROM gamification_task_templates LIKE 'can_escalate'");
            if (!$stmt->fetch()) {
                $pdo->exec("ALTER TABLE gamification_task_templates ADD COLUMN can_escalate TINYINT(1) NOT NULL DEFAULT 1 AFTER is_cooking_day");
            }
            $stmt = $pdo->query("SHOW COLUMNS FROM gamification_tasks LIKE 'can_escalate'");
            if (!$stmt->fetch()) {
                $pdo->exec("ALTER TABLE gamification_tasks ADD COLUMN can_escalate TINYINT(1) NOT NULL DEFAULT 1 AFTER is_bounty");
            }
        } catch (\Throwable $e) {
            (new Logger())->warn('Gamification: Automatische Schema-Prüfung fehlgeschlagen.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Bereitet den heutigen Tag vor: generiert Aufgaben aus Vorlagen und prüft Fristen.
     */
    public function syncDailyState(): void
    {
        $this->ensureSchema();

        $today = date('Y-m-d');
        // Vorlagen generieren
        $this->templateRepo->generateTasksForDate($today);
        // Eskalationen prüfen
        $this->escalationService->processEscalations();
    }
}
