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
        $pct = 80;
        try {
            $service = $settings ?? new SystemSettingsService();
            $pct = $service->getRecipeKidPortionPct();
        } catch (\Throwable $e) {
            $pct = 80;
        }
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
        $baseAdults = isset($recipe['default_adults']) ? (int)$recipe['default_adults'] : null;
        $baseKids = isset($recipe['default_kids']) ? (int)$recipe['default_kids'] : null;

        $baseServings = ($baseAdults !== null && $baseKids !== null && ($baseAdults > 0 || $baseKids > 0))
            ? $this->calculateEffectiveServings($baseAdults, $baseKids)
            : max(0.5, (float)($recipe['default_servings'] ?? 3.6));

        $targetServings = $this->calculateEffectiveServings($adults, $kids);
        $isUnscaled = ($baseAdults !== null && $baseKids !== null && $adults === $baseAdults && $kids === $baseKids)
            || abs($targetServings - $baseServings) < 0.01;
        $scaleFactor = $isUnscaled ? 1.0 : ($targetServings / $baseServings);

        $scaledIngredients = [];
        $freshIngredients = [];
        $pantryIngredients = [];

        foreach ($recipe['ingredients'] ?? [] as $ing) {
            $baseQty = (float)($ing['quantity'] ?? 1.0);
            $unit = trim((string)($ing['unit'] ?? 'Stück'));
            $scaledQty = $isUnscaled ? $baseQty : $this->roundQuantity($baseQty * $scaleFactor, $unit, (string)($ing['ingredient_name'] ?? ''));

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

            // Deterministischer Vorratsschrank-Abgleich (Gewürze, Öl, Salz, Brühe, Schalotten, Zwiebeln, Knoblauch)
            $isPantry = $this->isPantryStapleIngredient((string)$ing['ingredient_name'], !empty($ing['is_pantry_staple']));
            $ing['is_pantry_staple'] = $isPantry ? 1 : 0;

            $scaledIngredients[] = $ing;
            if ($isPantry) {
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
                    $sQty = $inQty > 0 ? ($isUnscaled ? $inQty : $this->roundQuantity($inQty * $scaleFactor, $inUnit, (string)($inItem['name'] ?? ''))) : 0;
                    $scaledInline[] = [
                        'name' => (string)($inItem['name'] ?? ''),
                        'base_quantity' => $inQty,
                        'quantity' => $sQty,
                        'unit' => $inUnit,
                        'display' => ($sQty > 0 ? $this->formatQuantity($sQty) . ($inUnit !== '' ? ' ' . $inUnit . ' ' : ' ') : '') . (string)($inItem['name'] ?? ''),
                    ];
                }
                $step['ingredients_inline_scaled'] = $scaledInline;

                // Mengenangaben im Fließtext des Schritts bei Skalierungsabweichung automatisch anpassen
                if (abs($scaleFactor - 1.0) > 0.001 && !empty($step['instruction'])) {
                    $step['instruction'] = $this->scaleInstructionText((string)$step['instruction'], $scaledInline);
                }

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
    public function roundQuantity(float $qty, string $unit, string $ingredientName = ''): float
    {
        if ($qty <= 0) {
            return 0.0;
        }

        $lowerUnit = mb_strtolower(trim($unit));
        $lowerName = mb_strtolower(trim($ingredientName));

        // 1. Kleinstmengen (Prise, Messerspitze, Spritzer, Schuss, Tropfen) -> immer ganze Zahlen (mind. 1)
        if (
            preg_match('/(prise|prisen|messerspitze|msp|spritzer|schuss|tropfen)/u', $lowerUnit) ||
            preg_match('/(prise|messerspitze|spritzer|tropfen)/u', $lowerName)
        ) {
            return max(1.0, (float)round($qty));
        }

        // 2. Löffel-Mengen (TL, EL, Teelöffel, Esslöffel, inkl. "gehäufter TL", "gestrichener EL" etc.)
        // Word-Boundary Regex gegen Fehlmatches bei "Beutel", "Tafel", "Nelke" etc.
        if (preg_match('/(?<![a-zäöü])(tl|el|teelöffel|esslöffel)\.?(?![a-zäöü])/iu', $lowerUnit)) {
            if ($qty < 0.2) {
                return 0.25;
            }
            if ($qty < 1.0) {
                // Viertel-Schritte: 0.25, 0.5, 0.75, 1.0
                return round($qty * 4) / 4;
            }
            if ($qty < 3.0) {
                // Halbe Schritte: 1.0, 1.5, 2.0, 2.5, 3.0
                return round($qty * 2) / 2;
            }
            // Ab 3 EL/TL auf ganze Löffel runden
            return (float)round($qty);
        }

        // 3. Gramm / Milliliter
        if (in_array($lowerUnit, ['g', 'gramm', 'ml', 'milliliter'], true)) {
            if ($qty >= 250) {
                return round($qty / 10) * 10;
            }
            if ($qty >= 50) {
                return round($qty / 5) * 5;
            }
            return max(1.0, (float)round($qty));
        }

        // 4. Kilogramm / Liter
        if (in_array($lowerUnit, ['kg', 'kilogramm', 'l', 'liter'], true)) {
            if ($qty >= 2.0) {
                return round($qty * 10) / 10;
            }
            return round($qty * 20) / 20; // 0.05er Schritte: z. B. 0.25, 0.5, 0.75, 1.25
        }

        // 5. Knoblauch & Knoblauchzehen -> immer ganze Zehen (mind. 1)
        if (
            preg_match('/(zehe|zehen)/u', $lowerUnit) ||
            str_contains($lowerName, 'knoblauch')
        ) {
            return max(1.0, (float)round($qty));
        }

        // 6. Ganze Gewürze & Kräuterstängel -> immer ganze Einheiten
        if (
            preg_match('/(lorbeer|nelke|wacholder|sternanis|zimtstange|vanilleschote|chilischote)/u', $lowerName) ||
            preg_match('/(zweig|zweige|stängel|strunk|kapsel|kapseln)/u', $lowerUnit)
        ) {
            return max(1.0, (float)round($qty));
        }

        // 7. Schalotten & Zwiebeln -> ganze Einheiten (unter 0.75 halbe Zwiebel erlaubt)
        if (
            str_contains($lowerName, 'schalotte') ||
            (str_contains($lowerName, 'zwiebel') && !str_contains($lowerName, 'frühlingszwiebel') && !str_contains($lowerName, 'lauchzwiebel'))
        ) {
            if ($qty < 0.75) {
                return 0.5;
            }
            return max(1.0, (float)round($qty));
        }

        // 8. Diskrete Portionsartikel & Würste (Wiener Würstchen, Frikadellen, Patties, Eier, Schnitzel...) -> immer ganze Stück
        $discreteKeywords = [
            'würstchen', 'wurst', 'wiener', 'bockwurst', 'bratwurst', 'weißwurst', 'knacker', 'mettende', 'cabannossi', 'kabanos', 'chorizo', 'frankfurter', 'currywurst',
            'frikadelle', 'bulette', 'fleischpflanzerl', 'patty', 'burger',
            'schnitzel', 'kotelett', 'steak', 'keule', 'schenkel',
            'spieß', 'maiskolben', 'brötchen', 'bagel', 'tortilla', 'wrap', 'pita'
        ];
        foreach ($discreteKeywords as $kw) {
            if (str_contains($lowerName, $kw)) {
                return max(1.0, (float)round($qty));
            }
        }

        // Eier (Ei, Eier)
        if (
            preg_match('/\b(ei|eier)\b/u', $lowerUnit) ||
            preg_match('/\b(ei|eier)\b/u', $lowerName) ||
            (str_contains($lowerName, 'ei') && (str_contains($lowerName, 'hühnerei') || str_contains($lowerName, 'wachtelei')))
        ) {
            if (!str_contains($lowerName, 'eierteig') && !str_contains($lowerName, 'spätzle')) {
                return max(1.0, (float)round($qty));
            }
        }

        // 9. Dosen, Gläser, Becher, Packungen, Tuben, Bund
        if (preg_match('/(dose|dosen|glas|gläser|becher|packung|pck|tube|tuben|bund)/u', $lowerUnit)) {
            if ($qty < 0.75) {
                return 0.5;
            }
            return max(1.0, (float)round($qty));
        }

        // 10. Scheiben (Brot, Toast, Schinken, Käse)
        if (preg_match('/(scheibe|scheiben)/u', $lowerUnit)) {
            if ($qty < 1.5) {
                return max(1.0, (float)round($qty));
            }
            return (float)round($qty);
        }

        // 11. Allgemeine Stückzahlen (Obst, Gemüse wie Paprika, Zitrone, Karotte...)
        if (in_array($lowerUnit, ['stück', 'stk', 'stk.', ''], true)) {
            if ($qty < 0.75) {
                return 0.5;
            }
            if ($qty < 2.0) {
                return round($qty * 2) / 2; // 1.0, 1.5, 2.0
            }
            // Ab 2 Stück auf ganze Einheiten runden (z. B. 2.44 -> 2, 7.33 -> 7)
            return (float)round($qty);
        }

        // 12. Generischer Fallback für sonstige Einheiten (küchentaugliche Interpolation)
        if ($qty < 0.2) {
            return 0.25;
        }
        if ($qty < 1.0) {
            return round($qty * 4) / 4;
        }
        if ($qty < 3.0) {
            return round($qty * 2) / 2;
        }
        return (float)round($qty);
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

    /**
     * Passt feste Mengenangaben im Fließtext eines Kochschritts an die
     * skalierten Mengen der Inline-Zutaten an.
     *
     * @param string $instruction
     * @param array<int, array<string, mixed>> $scaledInline
     * @return string
     */
    public function scaleInstructionText(string $instruction, array $scaledInline): string
    {
        if ($instruction === '' || empty($scaledInline)) {
            return $instruction;
        }

        foreach ($scaledInline as $item) {
            $baseQty = (float)($item['base_quantity'] ?? 0);
            $scaledQty = (float)($item['quantity'] ?? 0);
            $name = trim((string)($item['name'] ?? ''));
            $unit = trim((string)($item['unit'] ?? ''));

            if ($baseQty <= 0 || $scaledQty <= 0 || abs($baseQty - $scaledQty) < 0.001 || $name === '') {
                continue;
            }

            $baseStr = $this->formatQuantity($baseQty);
            $baseStrDot = str_replace(',', '.', $baseStr);
            $scaledStr = $this->formatQuantity($scaledQty);

            // Pattern für Menge (z. B. "4", "4,5", "4.5", "500")
            $qtyVariants = array_unique([$baseStr, $baseStrDot]);
            $qtyPattern = '(?:' . implode('|', array_map(fn($q) => preg_quote($q, '/'), $qtyVariants)) . ')';

            // Einheiten-Varianten
            $unitPattern = '';
            if ($unit !== '' && mb_strtolower($unit) !== 'stück' && mb_strtolower($unit) !== 'stk') {
                $unitClean = preg_quote($unit, '/');
                $unitPattern = '(?:\s*(?:' . $unitClean . '|' . mb_strtolower($unitClean) . '))?';
            } else {
                $unitPattern = '(?:\s*(?:Stück|Stk\.?|stk))?';
            }

            // Name-Stem (z. B. "Schalotten" -> "Schalott", "Putenbrust" -> "Putenbrust")
            $nameClean = trim((string)preg_replace('/\s*\(.*?\)/', '', $name));
            $words = preg_split('/\s+/', $nameClean);
            $mainWord = end($words) ?: $nameClean;
            $stem = rtrim($mainWord, 'enEN');
            if (mb_strlen($stem) < 3) {
                $stem = $mainWord;
            }
            $namePattern = '(?:' . preg_quote($nameClean, '/') . '|' . preg_quote($mainWord, '/') . '|' . preg_quote($stem, '/') . '[a-zA-ZäöüÄÖÜß]*)';

            // 1. Markdown-Pattern mit ** : z. B. **4 Schalotten** oder **500 g Putenbrust**
            $patternBold = '/(\*\*\s*(?:ca\.\s*)?)' . $qtyPattern . '(' . $unitPattern . '(?:\s+|\b)' . $namePattern . '\s*\*\*)/iu';
            $instruction = (string)preg_replace($patternBold, '${1}' . $scaledStr . '${2}', $instruction);

            // 2. Reguläres Pattern im Text: z. B. 4 Schalotten oder 500 g Putenbrust
            $patternPlain = '/(\b(?:ca\.\s*)?)' . $qtyPattern . '(' . $unitPattern . '\s+' . $namePattern . '\b)/iu';
            $instruction = (string)preg_replace($patternPlain, '${1}' . $scaledStr . '${2}', $instruction);
        }

        return $instruction;
    }

    /**
     * Prüft deterministisch anhand des Zutatennamens, ob es sich um einen Vorratsschrank-Artikel
     * (Gewürze, Kräuter, Salz, Pfeffer, Öl, Essig, Brühe, Senf, Tomatenmark, Mehl/Stärke, Zucker, Schalotten, Zwiebeln, Knoblauch)
     * oder um eine frische Einkaufs-/Hauptzutat handelt.
     */
    public function isPantryStapleIngredient(string $ingredientName, bool $fallbackFlag = false): bool
    {
        $lower = mb_strtolower(trim($ingredientName));
        if ($lower === '') {
            return $fallbackFlag;
        }

        // Frühlingszwiebeln / Lauchzwiebeln sind Frischeartikel
        if (mb_strpos($lower, 'frühlingszwiebel') !== false || mb_strpos($lower, 'lauchzwiebel') !== false) {
            return false;
        }

        // Hauptzutaten, Frischgemüse, Obst, Milchprodukte, Fleisch & Fisch sind Frische-/Einkaufszutaten
        $freshOverrideKeywords = [
            'nudel', 'spaghetti', 'penne', 'fusilli', 'tagliatelle', 'lasagne', 'gnocchi', 'spätzle',
            'kartoffel', 'pommes', 'krokette', 'klöße',
            'pute', 'hähnchen', 'huhn', 'rind', 'schwein', 'hackfleisch', 'schnitzel', 'gulasch', 'filet', 'lachs', 'fisch', 'garnele', 'schinken', 'speck', 'wurst', 'würstchen', 'tofu',
            'erbse', 'mais', 'bohne', 'linse', 'kichererbse', 'karotte', 'möhre', 'gurke', 'zucchini', 'aubergine', 'brokkoli', 'blumenkohl', 'spinat', 'kohl', 'porree', 'sellerie', 'pilz', 'champignon', 'spargel', 'kürbis', 'salat', 'avocado',
            'ananas', 'apfel', 'banane', 'orange', 'beere', 'mango', 'pfirsich', 'ananassaft', 'orangensaft', 'apfelsaft',
            'kokosmilch', 'sahne', 'schlagsahne', 'kochsahne', 'schmand', 'crème', 'creme', 'frischkäse', 'quark', 'joghurt', 'milch', 'käse', 'mozzarella', 'parmesan', 'gouda', 'emmentaler', 'feta', 'hirtenkäse',
            'brot', 'brötchen', 'toast', 'tortilla', 'wrap', 'pita', 'blätterteig', 'hefeteig', 'pizzateig',
        ];

        foreach ($freshOverrideKeywords as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                return false;
            }
        }

        // Frische Paprika (Schote) vs. Paprikapulver (Gewürz)
        if (mb_strpos($lower, 'paprika') !== false
            && mb_strpos($lower, 'pulver') === false
            && mb_strpos($lower, 'edelsüß') === false
            && mb_strpos($lower, 'rosenscharf') === false
            && mb_strpos($lower, 'geräuchert') === false
        ) {
            return false;
        }

        // Frische Tomaten / Dosentomaten vs. Tomatenmark
        if (mb_strpos($lower, 'tomate') !== false && mb_strpos($lower, 'tomatenmark') === false) {
            return false;
        }

        // Positivliste: Diese Artikel gehören IMMER in den Vorratsschrank-Check (auch wenn die KI is_pantry_staple=0 geliefert hat)
        $alwaysPantryKeywords = [
            'schalotte', 'zwiebel', 'knoblauch', 'ingwer',
            'reis', 'basmati', 'jasminreis', 'risottoreis', 'milchreis', 'parboiled',
            'salz', 'pfeffer', 'curry', 'paprikapulver', 'edelsüß', 'rosenscharf', 'muskat', 'zimt', 'kreuzkümmel', 'cumin', 'kurkuma', 'koriander', 'chili', 'cayenne',
            'oregano', 'basilikum', 'thymian', 'rosmarin', 'majoran', 'petersilie', 'schnittlauch', 'dill', 'lorbeer', 'gewürz', 'kräuter',
            'pflanzenöl', 'sonnenblumenöl', 'rapsöl', 'olivenöl', 'sesamöl', 'kokosöl', 'speiseöl', 'bratöl', 'bratfett', 'butterschmalz',
            'essig', 'balsamico', 'sojasauce', 'sojasoße', 'worcester', 'fischsauce', 'senf', 'tomatenmark', 'ketchup', 'mayonnaise',
            'brühe', 'gemüsebrühe', 'hühnerbrühe', 'rinderbrühe', 'brühwürfel', 'fond',
            'zucker', 'honig', 'agavendicksaft', 'ahornsirup', 'vanillezucker', 'backpulver', 'natron', 'trockenhefe',
            'mehl', 'speisestärke', 'stärke', 'mondamin', 'semmelbrösel', 'paniermehl', 'wasser',
        ];

        foreach ($alwaysPantryKeywords as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                return true;
            }
        }

        if (preg_match('/\böl\b/u', $lower)) {
            return true;
        }

        return false;
    }
}
