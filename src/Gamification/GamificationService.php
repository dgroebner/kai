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

    /**
     * Bereitet den heutigen Tag vor: generiert Aufgaben aus Vorlagen und prüft Fristen.
     */
    public function syncDailyState(): void
    {
        $today = date('Y-m-d');
        // Vorlagen generieren
        $this->templateRepo->generateTasksForDate($today);
        // Eskalationen prüfen
        $this->escalationService->processEscalations();
    }
}
