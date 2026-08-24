<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Datenbankzugriffe für die automatischen Tagging-Regeln (`bank_tag_rules`).
 */
class BankTagRuleRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Legt eine Regel an oder aktualisiert eine bestehende.
     *
     * @param int[] $tagIds
     * @return int ID der gespeicherten Regel
     */
    public function saveRule(
        ?int $ruleId,
        ?string $textPattern,
        ?string $payeePattern,
        array $tagIds,
        int $priority = 10
    ): int {
        $params = [
            ':text_pattern'  => $textPattern,
            ':payee_pattern' => $payeePattern,
            ':tag_ids'       => json_encode(array_values(array_map('intval', $tagIds))),
            ':priority'      => $priority,
        ];

        if ($ruleId !== null && $ruleId > 0) {
            $stmt = $this->pdo->prepare("
                UPDATE bank_tag_rules
                SET text_pattern = :text_pattern,
                    payee_pattern = :payee_pattern,
                    tag_ids = :tag_ids,
                    priority = :priority
                WHERE id = :id
            ");
            $params[':id'] = $ruleId;
            $stmt->execute($params);

            return $ruleId;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO bank_tag_rules (text_pattern, payee_pattern, tag_ids, priority)
            VALUES (:text_pattern, :payee_pattern, :tag_ids, :priority)
        ");
        $stmt->execute($params);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Löscht eine Regel. Verknüpfte Umsätze verlieren durch den Fremdschlüssel
     * (ON DELETE SET NULL) automatisch ihre `matched_rule_id`.
     */
    public function deleteRule(int $ruleId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM bank_tag_rules WHERE id = :id");
        $stmt->execute([':id' => $ruleId]);
    }
}
