<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Recipes\RecipeAiService;
use Kai\Tools\Recipes\RecipeRepository;
use Kai\Tools\Recipes\RecipeScaler;
use Kai\Tools\Recipes\RecipeShoppingService;
use Kai\Tools\Recipes\RecipeTimelineService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\SystemSettingsService;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check — immer zuerst
Auth::requireApi('recipes_read');

// 2. HTTP-Methoden-Check
Auth::requireMethod('POST');

// 3. Input validieren & CSRF-Token prüfen
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    Auth::sendJsonError(400, 'Ungültige Anfrage');
}
Auth::requireCsrfToken($input);

$action = trim((string)($input['action'] ?? ''));
if ($action === '') {
    Auth::sendJsonError(400, 'Keine Aktion angegeben');
}

$writeActions = [
    'import_recipe',
    'save_kitchen_profile',
    'save_manual_recipe',
    'suggest_alternatives',
    'create_substituted_variant',
    'delete_variant',
    'delete_recipe',
    'toggle_favorite',
    'add_to_shopping_list',
];

if (in_array($action, $writeActions, true)) {
    Auth::requireApi('recipes_write');
}

$logger = new Logger();
$repo = new RecipeRepository();
$scaler = new RecipeScaler();
$timelineService = new RecipeTimelineService();
$settingsService = new SystemSettingsService();

try {
    switch ($action) {
        // --- 0. Küchenprofil & Haushalts-Präferenzen speichern ---
        case 'save_kitchen_profile':
            $settingsService->saveKitchenProfile($input);
            echo json_encode([
                'success' => true,
                'message' => 'Küchenprofil und Haushalts-Präferenzen erfolgreich gespeichert!',
            ]);
            break;

        // --- 1. Rezept per KI importieren (URL, Foto, PDF, Text) ---
        case 'import_recipe':
            $sourceType = trim((string)($input['source_type'] ?? 'text'));
            if (!in_array($sourceType, ['url', 'photo', 'pdf', 'text'], true)) {
                Auth::sendJsonError(400, 'Ungültiger Quelltyp');
            }

            $textOrUrl = trim((string)($input['content'] ?? ''));
            $mimeType = !empty($input['mime_type']) ? trim((string)$input['mime_type']) : null;
            $base64Data = !empty($input['base64_data']) ? trim((string)$input['base64_data']) : null;

            if ($sourceType === 'url') {
                if ($textOrUrl === '') {
                    Auth::sendJsonError(400, 'Bitte gib eine gültige Rezept-URL ein.');
                }
                if (str_contains($textOrUrl, 'drive.google.com/drive/folders')) {
                    Auth::sendJsonError(400, 'Google-Drive-Ordner können nicht direkt als Webseite ausgelesen werden. Bitte wähle die PDF- oder Bilddatei über den Tab „Foto / PDF“ direkt aus.');
                }
            }
            if (($sourceType === 'photo' || $sourceType === 'pdf') && ($base64Data === null || $mimeType === null)) {
                Auth::sendJsonError(400, 'Bitte wähle eine Foto- oder PDF-Datei aus.');
            }
            if ($sourceType === 'text' && $textOrUrl === '') {
                Auth::sendJsonError(400, 'Bitte gib einen Rezepttext ein.');
            }

            if ($mimeType !== null) {
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
                if (!in_array($mimeType, $allowedMimes, true)) {
                    Auth::sendJsonError(400, 'Nicht unterstütztes Dateiformat.');
                }
            }

            $aiService = new RecipeAiService(null, $settingsService, $logger);
            $parsed = $aiService->importRecipe($sourceType, $textOrUrl, $mimeType, $base64Data);

            $parsed['recipe']['created_by'] = $_SESSION['user_email'] ?? null;
            $recipeId = $repo->saveFullRecipe($parsed['recipe'], $parsed['variant']);

            echo json_encode([
                'success' => true,
                'recipe_id' => $recipeId,
                'title' => $parsed['recipe']['title'],
                'message' => 'Rezept erfolgreich analysiert und gespeichert!',
            ]);
            break;

        // --- 2. Dynamische Skalierung & Multi-Topf-Synchronisation abrufen ---
        case 'scale_and_sync':
            $recipeId = filter_var($input['recipe_id'] ?? null, FILTER_VALIDATE_INT);
            $variantId = filter_var($input['variant_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $adults = max(0, (int)($input['adults'] ?? 2));
            $kids = max(0, (int)($input['kids'] ?? 2));
            $serveTime = !empty($input['serve_time']) ? trim((string)$input['serve_time']) : null;

            if (!$recipeId || ($adults === 0 && $kids === 0)) {
                Auth::sendJsonError(400, 'Ungültige Parameter für die Skalierung.');
            }

            $recipe = $repo->getRecipeById($recipeId, $variantId);
            if (!$recipe) {
                Auth::sendJsonError(404, 'Rezept nicht gefunden.');
            }

            $scaled = $scaler->scaleRecipe($recipe, $adults, $kids);
            $timeline = $timelineService->buildSynchronizedTimeline($scaled, $serveTime);

            echo json_encode([
                'success' => true,
                'scaling' => $scaled['scaling'],
                'ingredients' => $scaled['ingredients'],
                'fresh_ingredients' => $scaled['fresh_ingredients'],
                'pantry_ingredients' => $scaled['pantry_ingredients'],
                'components' => $scaled['components'],
                'timeline' => $timeline,
            ]);
            break;

        // --- 3. KI-Alternativen für eine Zutat suchen ---
        case 'suggest_alternatives':
            $recipeId = filter_var($input['recipe_id'] ?? null, FILTER_VALIDATE_INT);
            $variantId = filter_var($input['variant_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $ingredientName = trim((string)($input['ingredient_name'] ?? ''));
            $reason = trim((string)($input['reason'] ?? ''));

            if (!$recipeId || $ingredientName === '') {
                Auth::sendJsonError(400, 'Bitte wähle eine Zutat aus.');
            }

            $recipe = $repo->getRecipeById($recipeId, $variantId);
            if (!$recipe) {
                Auth::sendJsonError(404, 'Rezept nicht gefunden.');
            }

            $aiService = new RecipeAiService(null, $settingsService, $logger);
            $alternatives = $aiService->suggestIngredientAlternatives($recipe, $ingredientName, $reason);

            echo json_encode([
                'success' => true,
                'ingredient_name' => $ingredientName,
                'alternatives' => $alternatives,
            ]);
            break;

        // --- 4. Gewählte Zutat-Alternative als neue Rezeptabwandlung speichern ---
        case 'create_substituted_variant':
            $recipeId = filter_var($input['recipe_id'] ?? null, FILTER_VALIDATE_INT);
            $baseVariantId = filter_var($input['variant_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $oldIngredient = trim((string)($input['old_ingredient'] ?? ''));
            $customVariantName = trim((string)($input['variant_name'] ?? ''));
            $makeDefault = !empty($input['make_default']);
            $replacement = $input['replacement'] ?? null;

            if (!$recipeId || $oldIngredient === '' || !is_array($replacement) || empty($replacement['alternative_name'])) {
                Auth::sendJsonError(400, 'Ungültige Angaben für die Rezeptabwandlung.');
            }

            $recipe = $repo->getRecipeById($recipeId, $baseVariantId);
            if (!$recipe) {
                Auth::sendJsonError(404, 'Rezept nicht gefunden.');
            }

            $aiService = new RecipeAiService(null, $settingsService, $logger);
            $newVariantData = $aiService->buildSubstitutedVariant(
                $recipe,
                $oldIngredient,
                $replacement,
                $customVariantName !== '' ? $customVariantName : null
            );

            $newVariantId = $repo->saveVariant(
                $recipeId,
                $newVariantData['variant_name'],
                $newVariantData['notes'],
                $newVariantData['ingredients'],
                $newVariantData['components'],
                $makeDefault
            );

            echo json_encode([
                'success' => true,
                'recipe_id' => $recipeId,
                'variant_id' => $newVariantId,
                'variant_name' => $newVariantData['variant_name'],
                'message' => 'Rezeptabwandlung erfolgreich gespeichert!',
            ]);
            break;

        // --- 5. Ausgewählte Zutaten auf die Einkaufsliste setzen ---
        case 'add_to_shopping_list':
            $items = $input['items'] ?? [];
            $recipeTitle = trim((string)($input['recipe_title'] ?? ''));

            if (!is_array($items) || empty($items)) {
                Auth::sendJsonError(400, 'Bitte wähle mindestens eine Zutat für die Einkaufsliste aus.');
            }

            $shoppingService = new RecipeShoppingService();
            $addedCount = $shoppingService->addIngredientsToShoppingList($items, $recipeTitle !== '' ? $recipeTitle : null);

            echo json_encode([
                'success' => true,
                'added_count' => $addedCount,
                'message' => "{$addedCount} Zutat(en) erfolgreich auf die Einkaufsliste gesetzt!",
            ]);
            break;

        // --- 6. Favorit umschalten ---
        case 'toggle_favorite':
            $recipeId = filter_var($input['recipe_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$recipeId) {
                Auth::sendJsonError(400, 'Ungültige Rezept-ID.');
            }

            $ok = $repo->toggleFavorite($recipeId);
            echo json_encode([
                'success' => $ok,
            ]);
            break;

        // --- 7. Variante löschen ---
        case 'delete_variant':
            $variantId = filter_var($input['variant_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$variantId) {
                Auth::sendJsonError(400, 'Ungültige Varianten-ID.');
            }

            $ok = $repo->deleteVariant($variantId);
            if (!$ok) {
                Auth::sendJsonError(400, 'Die letzte verbleibende Variante eines Rezepts kann nicht gelöscht werden.');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Rezeptabwandlung gelöscht.',
            ]);
            break;

        // --- 8. Rezept löschen ---
        case 'delete_recipe':
            $recipeId = filter_var($input['recipe_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$recipeId) {
                Auth::sendJsonError(400, 'Ungültige Rezept-ID.');
            }

            $ok = $repo->deleteRecipe($recipeId);
            echo json_encode([
                'success' => $ok,
                'message' => 'Rezept wurde gelöscht.',
            ]);
            break;

        default:
            Auth::sendJsonError(400, 'Unbekannte Aktion.');
    }
} catch (\Throwable $e) {
    $logger->error('recipes/api.php: Fehler bei Aktion ' . $action, ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler bei der Verarbeitung des Rezepts.');
}
