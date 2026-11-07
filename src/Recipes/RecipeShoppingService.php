<?php

namespace Kai\Tools\Recipes;

use Kai\Tools\Einkaufsliste\MarketCategoryRepository;
use Kai\Tools\Einkaufsliste\OfferSyncService;
use Kai\Tools\Einkaufsliste\ProductMasterRepository;
use Kai\Tools\Einkaufsliste\ShoppingListRepository;

/**
 * Integrations-Service zwischen der Domäne "Recipes" und der "Einkaufsliste".
 *
 * Übernimmt ausgewählte Rezept-Zutaten (nach Vorrats-Prüfung und Packungsgrößen-Abgleich)
 * inkl. Produkt-Stammdaten-Zuordnung direkt auf die aktive Einkaufsliste.
 */
class RecipeShoppingService
{
    private ShoppingListRepository $listRepo;
    private ProductMasterRepository $productRepo;
    private MarketCategoryRepository $categoryRepo;

    public function __construct(
        ?ShoppingListRepository $listRepo = null,
        ?ProductMasterRepository $productRepo = null,
        ?MarketCategoryRepository $categoryRepo = null
    ) {
        $this->listRepo = $listRepo ?? new ShoppingListRepository();
        $this->productRepo = $productRepo ?? new ProductMasterRepository();
        $this->categoryRepo = $categoryRepo ?? new MarketCategoryRepository();
    }

    /**
     * Fügt die vom Nutzer ausgewählten Rezept-Zutaten zur Einkaufsliste hinzu.
     *
     * @param array<int, array<string, mixed>> $items
     * @param string|null $recipeTitle
     * @return int Anzahl der hinzugefügten Artikel
     */
    public function addIngredientsToShoppingList(array $items, ?string $recipeTitle = null): int
    {
        $addedCount = 0;

        foreach ($items as $item) {
            $name = trim((string)($item['name'] ?? $item['ingredient_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $market = ($item['market'] ?? 'Rewe') === 'Globus' ? 'Globus' : 'Rewe';
            $rawCategory = trim((string)($item['category'] ?? $item['shopping_category'] ?? 'Sonstiges'));
            $category = $this->categoryRepo->canonicalizeCategory($rawCategory) ?? 'Sonstiges';
            $unit = trim((string)($item['unit'] ?? 'Stück'));
            $quantity = max(0.01, (float)($item['quantity'] ?? 1.0));
            $note = trim((string)($item['note'] ?? ''));

            if ($recipeTitle !== null && $recipeTitle !== '' && $note === '') {
                $note = 'Rezept: ' . $recipeTitle;
            }

            $master = $this->productRepo->findByName($name);
            if ($master) {
                $productId = (int)$master['id'];
                if (!empty($master['default_category']) && $master['default_category'] !== 'Sonstiges') {
                    $category = (string)$master['default_category'];
                }
            } else {
                $productId = $this->productRepo->saveOrUpdate([
                    'name' => $name,
                    'preferred_market' => $market,
                    'default_category' => $category,
                    'default_unit' => $unit,
                ]);
            }

            $this->listRepo->addItem([
                'product_id' => $productId,
                'name' => $name,
                'quantity' => $quantity,
                'unit' => $unit,
                'market' => $market,
                'category' => $category,
                'note' => $note !== '' ? $note : null,
                'is_spontaneous' => 0,
                'source' => 'recipe',
            ]);

            $addedCount++;
        }

        if ($addedCount > 0) {
            try {
                (new OfferSyncService())->syncRelevantOffers(8, false, $this->listRepo);
            } catch (\Throwable) {
                // Angebots-Abgleich ist optional und darf den Export nicht blockieren
            }
        }

        return $addedCount;
    }
}
