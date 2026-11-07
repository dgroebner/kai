<?php

namespace Kai\Tools\Recipes;

use Kai\Tools\System\SystemSettingsService;

/**
 * Skalierungs- und Packungsgrößen-Logik für Kochrezepte.
 *
 * Berücksichtigt Erwachsene (Faktor 1.0) und Kinder/Jugendliche (konfigurierbarer Faktor, Standard 0.8),
 * rundet auf küchentaugliche Mengen und gleicht Bedarfswerte mit handelsüblichen
 * Packungsgrößen im Supermarkt ab, um Lebensmittelverschwendung zu vermeiden.
 */
class RecipeScaler
{
    private float $kidPortionFactor;

    /**
     * Handelsübliche Packungsgrößen im deutschen Lebensmitteleinzelhandel (Rewe / Globus),
     * falls im Rezeptdatensatz keine spezifische Packungsgröße hinterlegt wurde.
     *
     * @var array<string, array{size: float, unit: string, pack_label: string, tip: string}>
     */
    private const RETAIL_PACKAGE_DEFAULTS = [
        'schmand' => ['size' => 200.0, 'unit' => 'g', 'pack_label' => 'Becher (200 g)', 'tip' => 'Rest als Dip, auf Ofenkartoffeln oder zum Verfeinern von Suppen nutzen.'],
        'saure sahne' => ['size' => 200.0, 'unit' => 'g', 'pack_label' => 'Becher (200 g)', 'tip' => 'Rest für Salatdressing oder Kräuterdip verwenden.'],
        'crème fraîche' => ['size' => 150.0, 'unit' => 'g', 'pack_label' => 'Becher (150 g)', 'tip' => 'Rest auf Flammkuchen, Brot oder in Soßen einrühren.'],
        'creme fraiche' => ['size' => 150.0, 'unit' => 'g', 'pack_label' => 'Becher (150 g)', 'tip' => 'Rest auf Flammkuchen, Brot oder in Soßen einrühren.'],
        'schlagsahne' => ['size' => 200.0, 'unit' => 'ml', 'pack_label' => 'Becher (200 ml)', 'tip' => 'Rest für Kaffee, Obst oder die nächste Soße nutzen.'],
        'sahne' => ['size' => 200.0, 'unit' => 'ml', 'pack_label' => 'Becher (200 ml)', 'tip' => 'Rest für Suppe, Rührei oder Soße verwenden.'],
        'kochsahne' => ['size' => 200.0, 'unit' => 'ml', 'pack_label' => 'Becher (200 ml)', 'tip' => 'Rest für Pfannengerichte oder Auflauf nutzen.'],
        'kokosmilch' => ['size' => 400.0, 'unit' => 'ml', 'pack_label' => 'Dose (400 ml)', 'tip' => 'Rest in Smoothie, Porridge oder Curry verwenden (oder einfrieren).'],
        'gehackte tomaten' => ['size' => 400.0, 'unit' => 'g', 'pack_label' => 'Dose (400 g)', 'tip' => 'Ganze Dose verwenden (Soße etwas länger einkochen) oder Rest für Pizza-Soße nutzen.'],
        'stückige tomaten' => ['size' => 400.0, 'unit' => 'g', 'pack_label' => 'Dose (400 g)', 'tip' => 'Ganze Dose verwenden oder Rest für Nudelsoße einfrieren.'],
        'passierte tomaten' => ['size' => 500.0, 'unit' => 'g', 'pack_label' => 'Packung (500 g)', 'tip' => 'Rest als Basis für Tomatensuppe oder Pizza-Soße nutzen.'],
        'frischkäse' => ['size' => 200.0, 'unit' => 'g', 'pack_label' => 'Packung (200 g)', 'tip' => 'Rest als Brotaufstrich zum Abendbrot verwenden.'],
        'mascarpone' => ['size' => 250.0, 'unit' => 'g', 'pack_label' => 'Becher (250 g)', 'tip' => 'Rest mit etwas Obst/Kakao als schnelles Dessert oder für Pasta-Soße nutzen.'],
        'quark' => ['size' => 250.0, 'unit' => 'g', 'pack_label' => 'Becher (250 g)', 'tip' => 'Rest als Kräuterquark oder mit Früchten essen.'],
        'magerquark' => ['size' => 250.0, 'unit' => 'g', 'pack_label' => 'Becher (250 g)', 'tip' => 'Rest mit Kräutern oder Obst zum Frühstück essen.'],
        'mozzarella' => ['size' => 125.0, 'unit' => 'g', 'pack_label' => 'Kugel (125 g)', 'tip' => 'Rest mit Tomaten als Caprese-Salat oder zum Überbacken nutzen.'],
        'feta' => ['size' => 200.0, 'unit' => 'g', 'pack_label' => 'Packung (200 g)', 'tip' => 'Rest im Salat oder im Ofen gebacken genießen.'],
        'hirtenkäse' => ['size' => 200.0, 'unit' => 'g', 'pack_label' => 'Packung (200 g)', 'tip' => 'Rest im Salat verwenden.'],
        'rinderhackfleisch' => ['size' => 400.0, 'unit' => 'g', 'pack_label' => 'Packung (400 g)', 'tip' => 'Menge ggf. auf ganze 400 g aufrunden oder kleine Frikadelle braten.'],
        'hackfleisch' => ['size' => 400.0, 'unit' => 'g', 'pack_label' => 'Packung (400 g)', 'tip' => 'Komplette Packung mitanbraten – schmeckt auch aufgewärmt am Folgetag.'],
        'mais' => ['size' => 285.0, 'unit' => 'g', 'pack_label' => 'Dose (285 g Abtropfgewicht)', 'tip' => 'Restlichen Mais einfach in den Salat oder direkt mit ins Gericht geben.'],
        'kidneybohnen' => ['size' => 255.0, 'unit' => 'g', 'pack_label' => 'Dose (255 g Abtropfgewicht)', 'tip' => 'Ganze Dose verwenden oder für Salat aufheben.'],
        'kichererbsen' => ['size' => 240.0, 'unit' => 'g', 'pack_label' => 'Dose (240 g Abtropfgewicht)', 'tip' => 'Rest mit etwas Öl und Paprika im Ofen knusprig rösten.'],
    ];

    public function __construct(?SystemSettingsService $settings = null)
    {
        $pct = ($settings ?? new SystemSettingsService())->getRecipeKidPortionPct();
        $this->kidPortionFactor = round($pct / 100.0, 2);
    }

    /**
     * Berechnet den effektiven Portionenwert aus Erwachsenen und Kindern.
     */
    public function calculateEffectiveServings(int $adults, int $kids): float
    {
        $adults = max(0, $adults);
        $kids = max(0, $kids);
        $total = $adults + ($kids * $this->kidPortionFactor);

        return max(0.5, round($total, 2));
    }

    /**
     * Skaliert ein komplettes Rezept (Zutaten + Inline-Mengen in den Schritten)
     * auf die gewünschte Anzahl an Erwachsenen und Kindern.
     *
     * @param array<string, mixed> $recipe Vollständiger Datensatz aus RecipeRepository::getRecipeById
     */
    public function scaleRecipe(array $recipe, int $adults, int $kids): array
    {
        $baseServings = max(0.5, (float)($recipe['default_servings'] ?? 3.6));
        $targetServings = $this->calculateEffectiveServings($adults, $kids);
        $scaleFactor = $targetServings / $baseServings;

        $scaledIngredients = [];
        $freshIngredients = [];
        $pantryIngredients = [];

        foreach ($recipe['ingredients'] ?? [] as $ing) {
            $baseQty = (float)($ing['quantity'] ?? 1.0);
            $unit = trim((string)($ing['unit'] ?? 'Stück'));
            $scaledQty = $this->roundQuantity($baseQty * $scaleFactor, $unit);

            $ing['base_quantity'] = $baseQty;
            $ing['scaled_quantity'] = $scaledQty;
            $ing['scaled_display'] = $this->formatQuantity($scaledQty) . ($unit !== '' ? ' ' . $unit : '');

            // Packungsgrößen- und Reste-Analyse
            $packInfo = $this->analyzePackageAndLeftovers(
                (string)$ing['ingredient_name'],
                $scaledQty,
                $unit,
                !empty($ing['package_size']) ? (float)$ing['package_size'] : null,
                !empty($ing['package_unit']) ? (string)$ing['package_unit'] : null,
                !empty($ing['leftover_tip']) ? (string)$ing['leftover_tip'] : null
            );

            $ing['package_analysis'] = $packInfo;

            $scaledIngredients[] = $ing;
            if (!empty($ing['is_pantry_staple'])) {
                $pantryIngredients[] = $ing;
            } else {
                $freshIngredients[] = $ing;
            }
        }

        // Komponenten & Inline-Zutaten in den Kochschritten mitskalieren
        $scaledComponents = [];
        $scaledAllSteps = [];

        foreach ($recipe['components'] ?? [] as $comp) {
            $compSteps = [];
            foreach ($comp['steps'] ?? [] as $step) {
                $inlineList = $step['ingredients_inline_parsed'] ?? [];
                $scaledInline = [];
                foreach ($inlineList as $inItem) {
                    $inQty = (float)($inItem['quantity'] ?? 0);
                    $inUnit = trim((string)($inItem['unit'] ?? ''));
                    $sQty = $inQty > 0 ? $this->roundQuantity($inQty * $scaleFactor, $inUnit) : 0;
                    $scaledInline[] = [
                        'name' => (string)($inItem['name'] ?? ''),
                        'quantity' => $sQty,
                        'unit' => $inUnit,
                        'display' => ($sQty > 0 ? $this->formatQuantity($sQty) . ($inUnit !== '' ? ' ' . $inUnit . ' ' : ' ') : '') . (string)($inItem['name'] ?? ''),
                    ];
                }
                $step['ingredients_inline_scaled'] = $scaledInline;
                $compSteps[] = $step;
                $scaledAllSteps[] = $step;
            }
            $comp['steps'] = $compSteps;
            $scaledComponents[] = $comp;
        }

        $recipe['scaling'] = [
            'adults' => $adults,
            'kids' => $kids,
            'kid_portion_pct' => (int)round($this->kidPortionFactor * 100),
            'base_servings' => $baseServings,
            'target_servings' => $targetServings,
            'scale_factor' => round($scaleFactor, 3),
        ];
        $recipe['ingredients'] = $scaledIngredients;
        $recipe['fresh_ingredients'] = $freshIngredients;
        $recipe['pantry_ingredients'] = $pantryIngredients;
        $recipe['components'] = $scaledComponents;
        $recipe['all_steps'] = $scaledAllSteps;

        return $recipe;
    }

    /**
     * Rundet eine skalierte Menge auf praxistaugliche Küchenwerte.
     */
    public function roundQuantity(float $qty, string $unit): float
    {
        if ($qty <= 0) {
            return 0.0;
        }

        $lowerUnit = mb_strtolower(trim($unit));

        // Gramm / Milliliter ab 50 auf 5er oder 10er runden
        if (in_array($lowerUnit, ['g', 'gramm', 'ml', 'milliliter'], true)) {
            if ($qty >= 250) {
                return round($qty / 10) * 10;
            }
            if ($qty >= 50) {
                return round($qty / 5) * 5;
            }
            return max(1.0, round($qty));
        }

        // Stückzahlen, Zehen, Schalotten, Eier etc. auf halbe oder ganze Einheiten runden
        if (in_array($lowerUnit, ['stück', 'stk', 'zehe', 'zehen', 'bund', 'dose', 'dosen', 'becher', 'packung', 'pck', 'scheibe', 'scheiben'], true)) {
            if ($qty < 0.4) {
                return 0.5;
            }
            return round($qty * 2) / 2;
        }

        // TL / EL auf Viertel oder Halbe runden
        if (in_array($lowerUnit, ['tl', 'el', 'teelöffel', 'esslöffel', 'prise', 'prisen'], true)) {
            if ($qty < 0.3) {
                return 0.25;
            }
            return round($qty * 2) / 2;
        }

        return round($qty, 2);
    }

    /**
     * Formatiert eine Dezimalmenge lesbar (z. B. 1.5 -> "1,5", 2.0 -> "2").
     */
    public function formatQuantity(float $qty): string
    {
        if (abs($qty - round($qty)) < 0.01) {
            return (string)(int)round($qty);
        }
        return str_replace('.', ',', rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'));
    }

    /**
     * Prüft, ob für die Zutat eine handelsübliche Packungsgröße relevant ist,
     * und berechnet die optimale Packungsanzahl sowie eventuelle Restmengen.
     *
     * @return array<string, mixed>|null
     */
    public function analyzePackageAndLeftovers(
        string $ingredientName,
        float $neededQty,
        string $unit,
        ?float $dbPackSize,
        ?string $dbPackUnit,
        ?string $dbLeftoverTip
    ): ?array {
        $lowerName = mb_strtolower(trim($ingredientName));
        $lowerUnit = mb_strtolower(trim($unit));

        $packSize = $dbPackSize;
        $packUnit = $dbPackUnit ?: $unit;
        $packLabel = null;
        $tip = $dbLeftoverTip;

        if ($packSize === null || $packSize <= 0) {
            foreach (self::RETAIL_PACKAGE_DEFAULTS as $keyword => $def) {
                if (str_contains($lowerName, $keyword) && mb_strtolower($def['unit']) === $lowerUnit) {
                    $packSize = $def['size'];
                    $packUnit = $def['unit'];
                    $packLabel = $def['pack_label'];
                    $tip = $tip ?: $def['tip'];
                    break;
                }
            }
        }

        if ($packSize === null || $packSize <= 0 || mb_strtolower($packUnit) !== $lowerUnit) {
            return null;
        }

        $packagesNeeded = (int)ceil($neededQty / $packSize);
        $totalPurchased = $packagesNeeded * $packSize;
        $leftover = max(0.0, round($totalPurchased - $neededQty, 1));

        $useAllHint = null;
        if ($leftover > 0 && ($leftover / $packSize) <= 0.25) {
            $useAllHint = sprintf(
                'Tipp gegen Reste: Einfach die vollen %s %s komplett ins Gericht geben!',
                $this->formatQuantity($totalPurchased),
                $packUnit
            );
        }

        return [
            'package_size' => $packSize,
            'package_unit' => $packUnit,
            'package_label' => $packLabel ?: ($this->formatQuantity($packSize) . ' ' . $packUnit),
            'packages_to_buy' => $packagesNeeded,
            'total_purchased' => $totalPurchased,
            'leftover_qty' => $leftover,
            'leftover_display' => $leftover > 0 ? ($this->formatQuantity($leftover) . ' ' . $packUnit . ' Rest') : 'Kein Rest (geht genau auf)',
            'leftover_tip' => $useAllHint ?: ($tip ?: 'Rest zeitnah für ein anderes Gericht oder Beilage verwerten.'),
            'is_exact_match' => $leftover < 1.0,
        ];
    }
}
