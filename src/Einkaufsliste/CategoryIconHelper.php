<?php
namespace Kai\Tools\Einkaufsliste;

class CategoryIconHelper {
    public static function getIcon(string $category): string {
        $cat = mb_strtolower(trim($category));
        
        $map = [
            'obst' => '🍏',
            'gemüse' => '🥦',
            'fleisch' => '🥩',
            'wurst' => '🥓',
            'käse' => '🧀',
            'milch' => '🥛',
            'joghurt' => '🥄',
            'eier' => '🥚',
            'backwaren' => '🥐',
            'brot' => '🍞',
            'süßwaren' => '🍫',
            'knabberzeug' => '🍿',
            'getränke' => '🥤',
            'wasser' => '💧',
            'alkohol' => '🍷',
            'spirituosen' => '🥃',
            'bier' => '🍺',
            'tiefkühl' => '❄️',
            'tk' => '🧊',
            'haushalt' => '🧻',
            'drogerie' => '🧴',
            'pflege' => '🧼',
            'baby' => '🍼',
            'tierbedarf' => '🐾',
            'konserven' => '🥫',
            'gewürze' => '🧂',
            'backen' => '🧁',
            'nudeln' => '🍝',
            'reis' => '🍚',
            'kaffee' => '☕',
            'tee' => '🍵',
            'frühstück' => '🥣',
            'müsli' => '🥣',
            'aufstrich' => '🍯',
            'kühlregal' => '🥶',
            'vegetarisch' => '🌱',
            'vegan' => '🌿',
            'apotheke' => '💊',
            'hygiene' => '🪥',
            'reinigung' => '🧽',
            'waschmittel' => '🧺'
        ];

        foreach ($map as $key => $icon) {
            if (str_contains($cat, $key)) {
                return $icon;
            }
        }

        return '🛒'; // Default icon
    }
}
