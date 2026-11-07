<?php

namespace Kai\Tools\Recipes;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Repository für die Domäne "Recipes" (Rezepte, Varianten, Zutaten, Komponenten & Kochschritte).
 */
class RecipeRepository
{
    private PDO $pdo;

    public function __construct(?Database $db = null)
    {
        $this->pdo = ($db ?? Database::getInstance())->getConnection();
    }

    /**
     * Liefert alle Rezepte gefiltert nach Kategorie, Suchbegriff und Favoritenstatus.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllRecipes(?string $category = null, ?string $search = null, bool $onlyFavorites = false): array
    {
        $sql = "
            SELECT
                r.*,
                (SELECT COUNT(*) FROM recipe_variants rv WHERE rv.recipe_id = r.id) AS variant_count,
                (SELECT COUNT(*) FROM recipe_ingredients ri
                 JOIN recipe_variants rv2 ON ri.variant_id = rv2.id
                 WHERE rv2.recipe_id = r.id AND rv2.is_default = 1) AS ingredient_count
            FROM recipes r
            WHERE 1=1
        ";
        $params = [];

        if ($category !== null && $category !== '' && $category !== 'all') {
            $sql .= " AND r.category = :category";
            $params[':category'] = $category;
        }

        if ($search !== null && trim($search) !== '') {
            $sql .= " AND (r.title LIKE :q OR r.description LIKE :q2)";
            $params[':q'] = '%' . trim($search) . '%';
            $params[':q2'] = '%' . trim($search) . '%';
        }

        if ($onlyFavorites) {
            $sql .= " AND r.is_favorite = 1";
        }

        $sql .= " ORDER BY r.is_favorite DESC, r.title ASC, r.id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Liefert alle vorhandenen Kategorien inklusive Standard-Kategorien.
     *
     * @return string[]
     */
    public function getCategories(): array
    {
        $defaults = [
            'Hauptgericht',
            'Fleisch & Schmorgericht',
            'Pasta & Reis',
            'Vegetarisch',
            'Suppe & Eintopf',
            'Beilage & Salat',
            'Backen & Dessert',
            'Frühstück & Snack',
        ];

        $stmt = $this->pdo->query("SELECT DISTINCT category FROM recipes WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
        $dbCats = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_unique(array_merge($defaults, $dbCats)));
    }

    /**
     * Lädt ein vollständiges Rezept inklusive aller Varianten und den Details der gewählten (oder Standard-)Variante.
     *
     * @return array<string, mixed>|null
     */
    public function getRecipeById(int $recipeId, ?int $variantId = null): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM recipes WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $recipeId]);
        $recipe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$recipe) {
            return null;
        }

        // Alle Varianten laden
        $vStmt = $this->pdo->prepare("SELECT * FROM recipe_variants WHERE recipe_id = :rid ORDER BY is_default DESC, id ASC");
        $vStmt->execute([':rid' => $recipeId]);
        $variants = $vStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($variants)) {
            $defaultVariantId = $this->createEmptyVariant($recipeId, 'Standard (Familien-Variante)', true);
            $vStmt->execute([':rid' => $recipeId]);
            $variants = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $activeVariant = $variants[0];
        if ($variantId !== null) {
            foreach ($variants as $v) {
                if ((int)$v['id'] === $variantId) {
                    $activeVariant = $v;
                    break;
                }
            }
        }

        $activeVariantId = (int)$activeVariant['id'];

        // Zutaten der aktiven Variante laden
        $iStmt = $this->pdo->prepare("
            SELECT * FROM recipe_ingredients
            WHERE variant_id = :vid
            ORDER BY is_pantry_staple ASC, sort_order ASC, id ASC
        ");
        $iStmt->execute([':vid' => $activeVariantId]);
        $ingredients = $iStmt->fetchAll(PDO::FETCH_ASSOC);

        // Komponenten & Schritte der aktiven Variante laden
        $cStmt = $this->pdo->prepare("
            SELECT * FROM recipe_components
            WHERE variant_id = :vid
            ORDER BY sort_order ASC, id ASC
        ");
        $cStmt->execute([':vid' => $activeVariantId]);
        $components = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        $sStmt = $this->pdo->prepare("
            SELECT * FROM recipe_steps
            WHERE component_id = :cid
            ORDER BY sort_order ASC, step_number ASC, id ASC
        ");

        $allSteps = [];
        foreach ($components as &$comp) {
            $sStmt->execute([':cid' => (int)$comp['id']]);
            $steps = $sStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($steps as &$step) {
                $step['component_name'] = $comp['component_name'];
                $step['pot_or_appliance'] = $comp['pot_or_appliance'];
                $step['ingredients_inline_parsed'] = !empty($step['ingredients_inline'])
                    ? (json_decode((string)$step['ingredients_inline'], true) ?: [])
                    : [];
                $allSteps[] = $step;
            }
            unset($step);
            $comp['steps'] = $steps;
        }
        unset($comp);

        $recipe['equipment_parsed'] = !empty($recipe['equipment_list'])
            ? (json_decode((string)$recipe['equipment_list'], true) ?: array_filter(array_map('trim', explode(',', (string)$recipe['equipment_list']))))
            : [];
        $recipe['variants'] = $variants;
        $recipe['active_variant'] = $activeVariant;
        $recipe['ingredients'] = $ingredients;
        $recipe['components'] = $components;
        $recipe['all_steps'] = $allSteps;

        return $recipe;
    }

    /**
     * Speichert ein neues Rezept inklusive Standard-Variante, Zutaten, Komponenten und Schritten.
     */
    public function saveFullRecipe(array $recipeData, array $variantData): int
    {
        $this->pdo->beginTransaction();

        try {
            $adults = max(1, (int)($recipeData['default_adults'] ?? 2));
            $kids = max(0, (int)($recipeData['default_kids'] ?? 2));
            $servings = isset($recipeData['default_servings']) && (float)$recipeData['default_servings'] > 0
                ? (float)$recipeData['default_servings']
                : round($adults + ($kids * 0.8), 2);

            $prepMin = max(0, (int)($recipeData['prep_time_minutes'] ?? 15));
            $cookMin = max(0, (int)($recipeData['cook_time_minutes'] ?? 25));
            $totalMin = max($prepMin + $cookMin, (int)($recipeData['total_time_minutes'] ?? ($prepMin + $cookMin)));

            $equipment = $recipeData['equipment_list'] ?? [];
            $equipmentStr = is_array($equipment)
                ? json_encode(array_values(array_filter(array_map('trim', $equipment))), JSON_UNESCAPED_UNICODE)
                : trim((string)$equipment);

            $stmt = $this->pdo->prepare("
                INSERT INTO recipes (
                    title, description, category, source_type, source_url,
                    prep_time_minutes, cook_time_minutes, total_time_minutes,
                    default_adults, default_kids, default_servings,
                    calories_per_serving, protein_per_serving, carbs_per_serving, fat_per_serving,
                    equipment_list, is_favorite, created_by
                ) VALUES (
                    :title, :description, :category, :source_type, :source_url,
                    :prep_time_minutes, :cook_time_minutes, :total_time_minutes,
                    :default_adults, :default_kids, :default_servings,
                    :calories_per_serving, :protein_per_serving, :carbs_per_serving, :fat_per_serving,
                    :equipment_list, :is_favorite, :created_by
                )
            ");

            $sourceType = (string)($recipeData['source_type'] ?? 'manual');
            if (!in_array($sourceType, ['url', 'photo', 'pdf', 'manual', 'text'], true)) {
                $sourceType = 'manual';
            }

            $stmt->execute([
                ':title' => trim((string)($recipeData['title'] ?? 'Neues Rezept')),
                ':description' => !empty($recipeData['description']) ? trim((string)$recipeData['description']) : null,
                ':category' => !empty($recipeData['category']) ? trim((string)$recipeData['category']) : 'Hauptgericht',
                ':source_type' => $sourceType,
                ':source_url' => !empty($recipeData['source_url']) ? substr(trim((string)$recipeData['source_url']), 0, 500) : null,
                ':prep_time_minutes' => $prepMin,
                ':cook_time_minutes' => $cookMin,
                ':total_time_minutes' => $totalMin,
                ':default_adults' => $adults,
                ':default_kids' => $kids,
                ':default_servings' => $servings,
                ':calories_per_serving' => !empty($recipeData['calories_per_serving']) ? (int)$recipeData['calories_per_serving'] : null,
                ':protein_per_serving' => isset($recipeData['protein_per_serving']) && $recipeData['protein_per_serving'] !== '' ? (float)$recipeData['protein_per_serving'] : null,
                ':carbs_per_serving' => isset($recipeData['carbs_per_serving']) && $recipeData['carbs_per_serving'] !== '' ? (float)$recipeData['carbs_per_serving'] : null,
                ':fat_per_serving' => isset($recipeData['fat_per_serving']) && $recipeData['fat_per_serving'] !== '' ? (float)$recipeData['fat_per_serving'] : null,
                ':equipment_list' => $equipmentStr,
                ':is_favorite' => !empty($recipeData['is_favorite']) ? 1 : 0,
                ':created_by' => $recipeData['created_by'] ?? null,
            ]);

            $recipeId = (int)$this->pdo->lastInsertId();

            $this->insertVariantInternal(
                $recipeId,
                trim((string)($variantData['variant_name'] ?? 'Standard (Familien-Variante)')),
                !empty($variantData['notes']) ? trim((string)$variantData['notes']) : null,
                $variantData['ingredients'] ?? [],
                $variantData['components'] ?? [],
                true
            );

            $this->pdo->commit();
            return $recipeId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Speichert eine neue Rezeptabwandlung (Variante/Fork) für ein bestehendes Rezept.
     */
    public function saveVariant(
        int $recipeId,
        string $variantName,
        ?string $notes,
        array $ingredients,
        array $components,
        bool $makeDefault = false
    ): int {
        $this->pdo->beginTransaction();

        try {
            if ($makeDefault) {
                $uStmt = $this->pdo->prepare("UPDATE recipe_variants SET is_default = 0 WHERE recipe_id = :rid");
                $uStmt->execute([':rid' => $recipeId]);
            }

            $variantId = $this->insertVariantInternal($recipeId, $variantName, $notes, $ingredients, $components, $makeDefault);
            $this->pdo->commit();

            return $variantId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Schaltet den Favoriten-Status eines Rezepts um.
     */
    public function toggleFavorite(int $recipeId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE recipes SET is_favorite = 1 - is_favorite WHERE id = :id");
        return $stmt->execute([':id' => $recipeId]);
    }

    /**
     * Löscht ein Rezept samt aller Varianten, Zutaten und Schritte.
     */
    public function deleteRecipe(int $recipeId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM recipes WHERE id = :id");
        return $stmt->execute([':id' => $recipeId]);
    }

    /**
     * Löscht eine einzelne Rezept-Variante (sofern es nicht die einzige ist).
     */
    public function deleteVariant(int $variantId): bool
    {
        $stmt = $this->pdo->prepare("SELECT recipe_id, is_default FROM recipe_variants WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $variantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM recipe_variants WHERE recipe_id = :rid");
        $countStmt->execute([':rid' => (int)$row['recipe_id']]);
        if ((int)$countStmt->fetchColumn() <= 1) {
            return false;
        }

        $delStmt = $this->pdo->prepare("DELETE FROM recipe_variants WHERE id = :id");
        $ok = $delStmt->execute([':id' => $variantId]);

        if ($ok && (int)$row['is_default'] === 1) {
            $fixStmt = $this->pdo->prepare("UPDATE recipe_variants SET is_default = 1 WHERE recipe_id = :rid ORDER BY id ASC LIMIT 1");
            $fixStmt->execute([':rid' => (int)$row['recipe_id']]);
        }

        return $ok;
    }

    private function createEmptyVariant(int $recipeId, string $name, bool $isDefault): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO recipe_variants (recipe_id, variant_name, is_default) VALUES (:rid, :name, :def)");
        $stmt->execute([
            ':rid' => $recipeId,
            ':name' => $name,
            ':def' => $isDefault ? 1 : 0,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function insertVariantInternal(
        int $recipeId,
        string $variantName,
        ?string $notes,
        array $ingredients,
        array $components,
        bool $isDefault
    ): int {
        $vStmt = $this->pdo->prepare("
            INSERT INTO recipe_variants (recipe_id, variant_name, is_default, notes)
            VALUES (:recipe_id, :variant_name, :is_default, :notes)
        ");
        $vStmt->execute([
            ':recipe_id' => $recipeId,
            ':variant_name' => $variantName !== '' ? $variantName : 'Variante',
            ':is_default' => $isDefault ? 1 : 0,
            ':notes' => $notes,
        ]);
        $variantId = (int)$this->pdo->lastInsertId();

        // Zutaten einfügen
        $iStmt = $this->pdo->prepare("
            INSERT INTO recipe_ingredients (
                variant_id, ingredient_name, quantity, unit,
                package_size, package_unit, leftover_tip,
                market, shopping_category, is_pantry_staple,
                prep_note, component_name, sort_order
            ) VALUES (
                :variant_id, :ingredient_name, :quantity, :unit,
                :package_size, :package_unit, :leftover_tip,
                :market, :shopping_category, :is_pantry_staple,
                :prep_note, :component_name, :sort_order
            )
        ");

        $order = 0;
        foreach ($ingredients as $ing) {
            $name = trim((string)($ing['ingredient_name'] ?? $ing['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $order++;
            $market = ($ing['market'] ?? 'Rewe') === 'Globus' ? 'Globus' : 'Rewe';
            $iStmt->execute([
                ':variant_id' => $variantId,
                ':ingredient_name' => substr($name, 0, 150),
                ':quantity' => max(0.01, (float)($ing['quantity'] ?? 1.00)),
                ':unit' => substr(trim((string)($ing['unit'] ?? 'Stück')), 0, 50),
                ':package_size' => !empty($ing['package_size']) && (float)$ing['package_size'] > 0 ? (float)$ing['package_size'] : null,
                ':package_unit' => !empty($ing['package_unit']) ? substr(trim((string)$ing['package_unit']), 0, 50) : null,
                ':leftover_tip' => !empty($ing['leftover_tip']) ? substr(trim((string)$ing['leftover_tip']), 0, 255) : null,
                ':market' => $market,
                ':shopping_category' => substr(trim((string)($ing['shopping_category'] ?? $ing['category'] ?? 'Sonstiges')), 0, 100),
                ':is_pantry_staple' => !empty($ing['is_pantry_staple']) ? 1 : 0,
                ':prep_note' => !empty($ing['prep_note']) ? substr(trim((string)$ing['prep_note']), 0, 150) : null,
                ':component_name' => !empty($ing['component_name']) ? substr(trim((string)$ing['component_name']), 0, 100) : null,
                ':sort_order' => $order,
            ]);
        }

        // Komponenten & Schritte einfügen
        $cStmt = $this->pdo->prepare("
            INSERT INTO recipe_components (
                variant_id, component_name, pot_or_appliance,
                preheat_minutes, active_cook_minutes, passive_cook_minutes, rest_minutes, sort_order
            ) VALUES (
                :variant_id, :component_name, :pot_or_appliance,
                :preheat_minutes, :active_cook_minutes, :passive_cook_minutes, :rest_minutes, :sort_order
            )
        ");

        $sStmt = $this->pdo->prepare("
            INSERT INTO recipe_steps (
                component_id, step_number, phase, title, instruction,
                ingredients_inline, stove_level, oven_setting,
                duration_minutes, is_passive_wait, parallel_hint,
                start_offset_before_finish_min, sort_order
            ) VALUES (
                :component_id, :step_number, :phase, :title, :instruction,
                :ingredients_inline, :stove_level, :oven_setting,
                :duration_minutes, :is_passive_wait, :parallel_hint,
                :start_offset_before_finish_min, :sort_order
            )
        ");

        if (empty($components)) {
            $components = [
                [
                    'component_name' => 'Hauptgericht',
                    'pot_or_appliance' => 'Pfanne / Topf',
                    'steps' => [],
                ]
            ];
        }

        $compOrder = 0;
        $validPhases = ['mise_en_place_pre', 'preheat', 'cooking', 'mise_en_place_parallel', 'resting', 'serving'];

        foreach ($components as $comp) {
            $compOrder++;
            $compName = trim((string)($comp['component_name'] ?? 'Hauptgericht'));
            $cStmt->execute([
                ':variant_id' => $variantId,
                ':component_name' => substr($compName !== '' ? $compName : 'Hauptgericht', 0, 100),
                ':pot_or_appliance' => !empty($comp['pot_or_appliance']) ? substr(trim((string)$comp['pot_or_appliance']), 0, 100) : null,
                ':preheat_minutes' => max(0, (int)($comp['preheat_minutes'] ?? 0)),
                ':active_cook_minutes' => max(0, (int)($comp['active_cook_minutes'] ?? 0)),
                ':passive_cook_minutes' => max(0, (int)($comp['passive_cook_minutes'] ?? 0)),
                ':rest_minutes' => max(0, (int)($comp['rest_minutes'] ?? 0)),
                ':sort_order' => $compOrder,
            ]);
            $componentId = (int)$this->pdo->lastInsertId();

            $stepOrder = 0;
            foreach ($comp['steps'] ?? [] as $step) {
                $instruction = trim((string)($step['instruction'] ?? ''));
                if ($instruction === '') {
                    continue;
                }
                $stepOrder++;
                $phase = (string)($step['phase'] ?? 'cooking');
                if (!in_array($phase, $validPhases, true)) {
                    $phase = 'cooking';
                }

                $inline = $step['ingredients_inline'] ?? $step['ingredients_inline_parsed'] ?? [];
                $inlineJson = is_array($inline)
                    ? json_encode($inline, JSON_UNESCAPED_UNICODE)
                    : (is_string($inline) ? $inline : '[]');

                $sStmt->execute([
                    ':component_id' => $componentId,
                    ':step_number' => (int)($step['step_number'] ?? $stepOrder),
                    ':phase' => $phase,
                    ':title' => !empty($step['title']) ? substr(trim((string)$step['title']), 0, 150) : null,
                    ':instruction' => $instruction,
                    ':ingredients_inline' => $inlineJson,
                    ':stove_level' => !empty($step['stove_level']) ? substr(trim((string)$step['stove_level']), 0, 30) : null,
                    ':oven_setting' => !empty($step['oven_setting']) ? substr(trim((string)$step['oven_setting']), 0, 80) : null,
                    ':duration_minutes' => max(0, (int)($step['duration_minutes'] ?? 0)),
                    ':is_passive_wait' => !empty($step['is_passive_wait']) ? 1 : 0,
                    ':parallel_hint' => !empty($step['parallel_hint']) ? substr(trim((string)$step['parallel_hint']), 0, 255) : null,
                    ':start_offset_before_finish_min' => isset($step['start_offset_before_finish_min']) && $step['start_offset_before_finish_min'] !== ''
                        ? max(0, (int)$step['start_offset_before_finish_min'])
                        : null,
                    ':sort_order' => $stepOrder,
                ]);
            }
        }

        return $variantId;
    }
}
