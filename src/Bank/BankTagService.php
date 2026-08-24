<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Orchestriert die mehrstufigen Tagging-Abläufe (Regel speichern, Regel löschen)
 * inklusive Transaktionsklammer.
 */
class BankTagService
{
    private PDO $pdo;
    private BankTagRepository $tagRepository;
    private BankTagRuleRepository $ruleRepository;
    private BankTransactionRepository $transactionRepository;

    public function __construct(
        ?BankTagRepository $tagRepository = null,
        ?BankTagRuleRepository $ruleRepository = null,
        ?BankTransactionRepository $transactionRepository = null
    ) {
        $this->pdo = Database::getInstance()->getConnection();
        $this->tagRepository = $tagRepository ?? new BankTagRepository();
        $this->ruleRepository = $ruleRepository ?? new BankTagRuleRepository();
        $this->transactionRepository = $transactionRepository ?? new BankTransactionRepository();
    }

    /**
     * Legt ein Tag an (falls nötig) und verknüpft es sofort mit einem Umsatz.
     */
    public function createAndAssignTag(int $transactionId, string $name, ?string $color): int
    {
        $tagId = $this->tagRepository->findOrCreateTag($name, $color);
        $this->tagRepository->assignTagToTransaction($transactionId, $tagId);

        return $tagId;
    }

    /**
     * Speichert eine Tagging-Regel, wendet sie optional sofort auf einen konkreten Umsatz an
     * und danach retroaktiv auf alle noch regel-losen Umsätze.
     *
     * @param int[] $tagIds
     * @return array{rule_id: int, retroactive_matches: int}
     */
    public function saveRuleAndApply(
        ?int $ruleId,
        ?int $transactionId,
        ?string $textPattern,
        ?string $payeePattern,
        array $tagIds,
        int $priority = 10
    ): array {
        $this->pdo->beginTransaction();

        try {
            $ruleId = $this->ruleRepository->saveRule($ruleId, $textPattern, $payeePattern, $tagIds, $priority);

            // Die auslösende Transaktion sofort mit der Regel und ihren Tags versehen
            if ($transactionId !== null && $transactionId > 0) {
                $this->transactionRepository->setMatchedRule($transactionId, $ruleId);
                $this->tagRepository->replaceTagsOfTransaction($transactionId, $tagIds);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Retroaktiv auf alle verbleibenden regel-losen Umsätze anwenden
        $matcher = new RuleMatcher($this->pdo);
        $retroactiveMatches = $matcher->applyRuleToAllTransactions($ruleId);

        return [
            'rule_id'             => $ruleId,
            'retroactive_matches' => $retroactiveMatches,
        ];
    }

    /**
     * Löscht eine Regel und entfernt die Tags der zuvor durch sie getaggten Umsätze.
     */
    public function deleteRuleAndCleanup(int $ruleId): void
    {
        $this->pdo->beginTransaction();

        try {
            // Betroffene Umsätze ermitteln, bevor der Fremdschlüssel die Zuordnung löst
            $affectedTransactionIds = $this->transactionRepository->getTransactionIdsByRule($ruleId);

            $this->ruleRepository->deleteRule($ruleId);
            $this->tagRepository->removeAllTagsFromTransactions($affectedTransactionIds);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
