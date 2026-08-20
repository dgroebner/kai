<?php
namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Db\Database;

class CategoryMatcher
{
    private array $rules = [];

    public function __construct()
    {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->query("SELECT * FROM bank_tag_rules ORDER BY priority DESC");
        $this->rules = $stmt->fetchAll();
    }

    /**
     * Prüft, ob für den Buchungstext passende Tag-IDs hinterlegt sind.
     *
     * @param string $remittanceInfo
     * @return array|null Tag-IDs oder null
     */
    public function match(string $remittanceInfo): ?array
    {
        $haystack = mb_strtolower($remittanceInfo);

        foreach ($this->rules as $rule) {
            $payeeMatch = empty($rule['payee_pattern']) || str_contains($haystack, mb_strtolower($rule['payee_pattern']));
            $textMatch  = empty($rule['text_pattern'])  || str_contains($haystack, mb_strtolower($rule['text_pattern']));

            if ($payeeMatch && $textMatch) {
                return json_decode($rule['tag_ids'], true);
            }
        }

        return null;
    }
}