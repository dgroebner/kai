<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Einkaufsliste\EbonMappingRepository;
use Kai\Tools\Einkaufsliste\HolidayService;
use Kai\Tools\Einkaufsliste\LearningService;
use Kai\Tools\Einkaufsliste\MarketCategoryRepository;
use Kai\Tools\Einkaufsliste\ProductMasterRepository;
use Kai\Tools\Einkaufsliste\RecipeAiService;
use Kai\Tools\Einkaufsliste\ShoppingListRepository;
use Kai\Tools\Einkaufsliste\SuggestionService;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check — immer zuerst
Auth::requireApi('shopping_write');

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

$logger = new Logger();
$listRepo = new ShoppingListRepository();
$productRepo = new ProductMasterRepository();
$categoryRepo = new MarketCategoryRepository();
$mappingRepo = new EbonMappingRepository();
$holidayService = new HolidayService();
$learningService = new LearningService($productRepo, $mappingRepo);
$suggestionService = new SuggestionService($productRepo, $listRepo, $holidayService);

try {
    switch ($action) {
        // --- 1. Artikel zur Einkaufsliste hinzufügen ---
        case 'add_item':
            $name = trim((string)($input['name'] ?? ''));
            if ($name === '') {
                Auth::sendJsonError(400, 'Artikelname darf nicht leer sein');
            }

            $market = trim((string)($input['market'] ?? 'Rewe'));
            if (!in_array($market, ['Rewe', 'Globus'], true)) {
                $market = 'Rewe';
            }

            $category = trim((string)($input['category'] ?? ''));
            $unit = trim((string)($input['unit'] ?? 'Stück'));
            $quantity = max(0.01, (float)($input['quantity'] ?? 1.00));
            $isSpontaneous = !empty($input['is_spontaneous']) ? 1 : 0;
            $source = $isSpontaneous ? 'spontaneous' : 'manual';

            // Prüfen, ob der Artikel im Artikelstamm existiert, ansonsten verknüpfen/anlegen
            $master = $productRepo->findByLabelOrName($name);
            $productId = null;
            $effectiveName = $name;

            if ($master) {
                $productId = (int)$master['id'];
                if ($category === '' && !empty($master['default_category'])) {
                    $category = $master['default_category'];
                }
                // Vorrangiges Label nutzen, falls vorhanden
                if (!empty($master['custom_label'])) {
                    $effectiveName = $master['custom_label'];
                }
            } else {
                $productId = $productRepo->saveOrUpdate([
                    'name' => $name,
                    'preferred_market' => $market,
                    'default_category' => $category !== '' ? $category : 'Sonstiges',
                    'default_unit' => $unit,
                ]);
            }

            if ($category === '') {
                $category = 'Sonstiges';
            }

            $itemId = $listRepo->addItem([
                'product_id' => $productId,
                'name' => $effectiveName,
                'quantity' => $quantity,
                'unit' => $unit,
                'market' => $market,
                'category' => $category,
                'is_spontaneous' => $isSpontaneous,
                'source' => $source,
            ]);

            $counts = $listRepo->getItemCountsByMarket();
            echo json_encode([
                'success' => true,
                'item_id' => $itemId,
                'counts' => $counts,
                'message' => 'Artikel hinzugefügt',
            ]);
            break;

        // --- 2. Abhaken umschalten ---
        case 'toggle_check':
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                Auth::sendJsonError(400, 'Ungültige Artikel-ID');
            }

            $force = isset($input['checked']) ? (bool)$input['checked'] : null;
            $success = $listRepo->toggleCheck($id, $force);
            $counts = $listRepo->getItemCountsByMarket();

            echo json_encode([
                'success' => $success,
                'counts' => $counts,
            ]);
            break;

        // --- 3. Artikel löschen ---
        case 'delete_item':
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                Auth::sendJsonError(400, 'Ungültige Artikel-ID');
            }

            $success = $listRepo->deleteItem($id);
            $counts = $listRepo->getItemCountsByMarket();

            echo json_encode([
                'success' => $success,
                'counts' => $counts,
                'message' => 'Artikel gelöscht',
            ]);
            break;

        // --- 4. Einkauf abschließen ---
        case 'complete_shopping':
            $market = trim((string)($input['market'] ?? 'all'));
            $marketFilter = ($market === 'Rewe' || $market === 'Globus') ? $market : null;

            // Abgehakte Positionen holen und aus der Einkaufsliste entfernen
            $completedItems = $listRepo->completeCheckedItems($marketFilter);
            $count = count($completedItems);

            if ($count > 0) {
                $today = date('Y-m-d');
                // Für jeden regulären Einkauf die Verbrauchsintervalle aktualisieren
                foreach ($completedItems as $item) {
                    $productId = !empty($item['product_id']) ? (int)$item['product_id'] : null;
                    $isSpontaneous = !empty($item['is_spontaneous']);

                    if ($productId) {
                        $productRepo->recordPurchase($productId, $today, $isSpontaneous);
                    }
                }

                // Im Aktivitätslog verzeichnen
                $marketLabel = $marketFilter ? $marketFilter : 'Rewe & Globus';
                $activityLogger = new ActivityLogger(Database::getInstance());
                $activityLogger->logShoppingCompleted($count, $marketLabel);
            }

            $counts = $listRepo->getItemCountsByMarket();
            echo json_encode([
                'success' => true,
                'completed_count' => $count,
                'counts' => $counts,
                'message' => "Einkauf mit {$count} Position(en) erfolgreich abgeschlossen.",
            ]);
            break;

        // --- 5. Vorschläge laden ---
        case 'get_suggestions':
            $forecastDays = filter_var($input['forecast_days'] ?? 3, FILTER_VALIDATE_INT) ?: 3;
            $suggestions = $suggestionService->generateSuggestions($forecastDays);
            $holidayContext = $holidayService->getHolidayContext();

            echo json_encode([
                'success' => true,
                'suggestions' => $suggestions,
                'holiday_context' => $holidayContext,
            ]);
            break;

        // --- 6. Einzelnen Vorschlag akzeptieren ---
        case 'accept_suggestion':
            $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId) {
                Auth::sendJsonError(400, 'Ungültige Produkt-ID');
            }

            $market = isset($input['market']) ? trim((string)$input['market']) : null;
            $quantity = isset($input['quantity']) ? max(0.01, (float)$input['quantity']) : null;

            $addedId = $suggestionService->acceptSuggestion($productId, $market, $quantity);
            $counts = $listRepo->getItemCountsByMarket();

            echo json_encode([
                'success' => $addedId > 0,
                'item_id' => $addedId,
                'counts' => $counts,
                'message' => 'Vorschlag zur Einkaufsliste hinzugefügt',
            ]);
            break;

        // --- 7. Alle Vorschläge auf einmal akzeptieren ---
        case 'accept_all_suggestions':
            $productIds = $input['product_ids'] ?? [];
            if (!is_array($productIds) || empty($productIds)) {
                Auth::sendJsonError(400, 'Keine Vorschläge ausgewählt');
            }

            $cleanIds = array_filter(array_map('intval', $productIds));
            $added = $suggestionService->acceptMultipleSuggestions($cleanIds);
            $counts = $listRepo->getItemCountsByMarket();

            echo json_encode([
                'success' => true,
                'added_count' => $added,
                'counts' => $counts,
                'message' => "{$added} Artikel zur Einkaufsliste hinzugefügt",
            ]);
            break;

        // --- 8. Aus eBons lernen (Historie synchronisieren) ---
        case 'sync_ebons':
            $learningService = new LearningService($productRepo);
            $stats = $learningService->learnFromReceipts();

            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'message' => "eBon-Analyse abgeschlossen: {$stats['products_updated']} Artikel aktualisiert.",
            ]);
            break;

        // --- 9. KI-Rezept / Freitext parsen ---
        case 'parse_recipe':
            $text = trim((string)($input['text'] ?? ''));
            if ($text === '') {
                Auth::sendJsonError(400, 'Bitte geben Sie einen Text oder ein Rezept ein');
            }

            $aiService = new RecipeAiService(null, $categoryRepo);
            $items = $aiService->parseRecipeText($text);

            echo json_encode([
                'success' => true,
                'items' => $items,
                'count' => count($items),
            ]);
            break;

        // --- 10. Geparste Rezept-Artikel zur Liste hinzufügen ---
        case 'save_recipe_items':
            $items = $input['items'] ?? [];
            if (!is_array($items) || empty($items)) {
                Auth::sendJsonError(400, 'Keine Artikel zum Speichern übergeben');
            }

            $added = 0;
            foreach ($items as $it) {
                $name = trim((string)($it['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $market = ($it['market'] ?? 'Rewe') === 'Globus' ? 'Globus' : 'Rewe';
                $category = trim((string)($it['category'] ?? 'Sonstiges'));
                $unit = trim((string)($it['unit'] ?? 'Stück'));
                $quantity = max(0.01, (float)($it['quantity'] ?? 1.00));

                $master = $productRepo->findByName($name);
                $productId = $master ? (int)$master['id'] : $productRepo->saveOrUpdate([
                    'name' => $name,
                    'preferred_market' => $market,
                    'default_category' => $category,
                    'default_unit' => $unit,
                ]);

                $listRepo->addItem([
                    'product_id' => $productId,
                    'name' => $name,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'market' => $market,
                    'category' => $category,
                    'is_spontaneous' => 0,
                    'source' => 'recipe',
                ]);
                $added++;
            }

            $counts = $listRepo->getItemCountsByMarket();
            echo json_encode([
                'success' => true,
                'added_count' => $added,
                'counts' => $counts,
                'message' => "{$added} Artikel aus dem Rezept hinzugefügt",
            ]);
            break;

        // --- 11. Gang-Reihenfolge aktualisieren ---
        case 'update_aisle_order':
            $market = trim((string)($input['market'] ?? ''));
            if (!in_array($market, ['Rewe', 'Globus'], true)) {
                Auth::sendJsonError(400, 'Ungültiger Markt');
            }

            $categories = $input['categories'] ?? [];
            if (!is_array($categories)) {
                Auth::sendJsonError(400, 'Ungültige Kategorieliste');
            }

            $categoryRepo->updateSortOrder($market, $categories);
            echo json_encode([
                'success' => true,
                'message' => 'Gang-Reihenfolge erfolgreich gespeichert',
            ]);
            break;

        // --- 12. Artikelstamm-Eintrag speichern / anpassen ---
        case 'save_product_master':
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string)($input['name'] ?? ''));

            $market = trim((string)($input['preferred_market'] ?? 'Rewe'));
            if (!in_array($market, ['Rewe', 'Globus'], true)) {
                $market = 'Rewe';
            }

            $customLabel = isset($input['custom_label']) ? trim((string)$input['custom_label']) : null;
            $isIgnored = !empty($input['is_ignored']) ? 1 : 0;
            $category = trim((string)($input['default_category'] ?? 'Sonstiges'));
            $unit = trim((string)($input['default_unit'] ?? 'Stück'));
            $interval = isset($input['avg_interval_days']) && $input['avg_interval_days'] !== ''
                ? max(0.5, (float)$input['avg_interval_days'])
                : null;
            $holidayFactor = isset($input['holiday_factor']) && $input['holiday_factor'] !== ''
                ? max(0.5, min(5.0, (float)$input['holiday_factor']))
                : 1.00;

            if ($id) {
                // Build update data, include is_ignored only if present in request
                $updateData = [
                    'custom_label' => $customLabel,
                    'preferred_market' => $market,
                    'default_category' => $category,
                    'default_unit' => $unit,
                    'avg_interval_days' => $interval,
                    'holiday_factor' => $holidayFactor,
                ];
                if (array_key_exists('is_ignored', $input)) {
                    $updateData['is_ignored'] = $isIgnored;
                }
                $success = $productRepo->updateMaster($id, $updateData);
                $productId = $id;
            } else {
                if ($name === '') {
                    Auth::sendJsonError(400, 'Artikelname darf nicht leer sein');
                }
                $productId = $productRepo->saveOrUpdate([
                    'name' => $name,
                    'custom_label' => $customLabel,
                    'preferred_market' => $market,
                    'default_category' => $category,
                    'default_unit' => $unit,
                    'avg_interval_days' => $interval,
                    'holiday_factor' => $holidayFactor,
                    'is_ignored' => $isIgnored,
                ]);
                $success = $productId > 0;
            }

            $stats = $productRepo->getStats();
            $updatedProduct = $productRepo->findById($productId);

            echo json_encode([
                'success' => $success,
                'product_id' => $productId,
                'product' => $updatedProduct,
                'stats' => $stats,
                'message' => 'Artikelstamm gespeichert',
            ]);
            break;

        // --- 13. Einzelnen Artikel laden (für Modal) ---
        case 'get_product_master':
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                Auth::sendJsonError(400, 'Ungültige Artikel-ID');
            }

            $product = $productRepo->findById($id);
            if (!$product) {
                Auth::sendJsonError(404, 'Artikel nicht gefunden');
            }

            echo json_encode([
                'success' => true,
                'product' => $product,
            ]);
            break;

        // --- 14. Artikel ignorieren / wiederherstellen (1-Klick) ---
        case 'toggle_product_ignore':
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                Auth::sendJsonError(400, 'Ungültige Artikel-ID');
            }

            $force = isset($input['is_ignored']) ? (bool)$input['is_ignored'] : null;
            $newStatus = $productRepo->toggleIgnore($id, $force);
            if ($newStatus === null) {
                Auth::sendJsonError(404, 'Artikel nicht gefunden');
            }

            $stats = $productRepo->getStats();
            echo json_encode([
                'success' => true,
                'is_ignored' => $newStatus,
                'stats' => $stats,
                'message' => $newStatus ? 'Artikel wird künftig ignoriert' : 'Artikel wieder aktiviert',
            ]);
            break;

        // --- 15. Artikelstamm durchsuchen (Autocomplete) ---
        case 'search_products':
            $query = trim((string)($input['query'] ?? ''));
            $activeOnly = !isset($input['active_only']) || (bool)$input['active_only'];
            $results = $productRepo->search($query, 10, $activeOnly);
            echo json_encode([
                'success' => true,
                'results' => $results,
            ]);
            break;

        // --- 16. eBon-Mappings eines Artikels laden ---
        case 'get_ebon_mappings':
            $id = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                Auth::sendJsonError(400, 'Ungültige Produkt-ID');
            }

            $product = $productRepo->findById($id);
            if (!$product) {
                Auth::sendJsonError(404, 'Artikel nicht gefunden');
            }

            $mappings = $mappingRepo->getByProduct($id);

            echo json_encode([
                'success'  => true,
                'mappings' => $mappings,
                'product'  => $product,
            ]);
            break;

        // --- 17. eBon-Mapping anlegen oder überschreiben ---
        case 'save_ebon_mapping':
            $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
            $ebonName  = trim((string)($input['ebon_name'] ?? ''));

            if (!$productId) {
                Auth::sendJsonError(400, 'Ungültige Produkt-ID');
            }
            if ($ebonName === '') {
                Auth::sendJsonError(400, 'eBon-Name darf nicht leer sein');
            }
            if (mb_strlen($ebonName) > 255) {
                Auth::sendJsonError(400, 'eBon-Name ist zu lang (max. 255 Zeichen)');
            }

            $product = $productRepo->findById($productId);
            if (!$product) {
                Auth::sendJsonError(404, 'Artikel nicht gefunden');
            }

            $mappingId = $mappingRepo->save($ebonName, $productId);
            $mappings  = $mappingRepo->getByProduct($productId);

            echo json_encode([
                'success'    => $mappingId > 0,
                'mapping_id' => $mappingId,
                'mappings'   => $mappings,
                'message'    => 'eBon-Zuordnung gespeichert',
            ]);
            break;

        // --- 18. eBon-Mapping löschen ---
        case 'delete_ebon_mapping':
            $id        = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$id) {
                Auth::sendJsonError(400, 'Ungültige Mapping-ID');
            }

            $success  = $mappingRepo->delete($id);
            $mappings = $productId ? $mappingRepo->getByProduct($productId) : [];

            echo json_encode([
                'success'  => $success,
                'mappings' => $mappings,
                'message'  => 'eBon-Zuordnung gelöscht',
            ]);
            break;

        // --- 19. Inbox laden ---
        case 'get_inbox':
            $items = $learningService->getInboxItems();
            echo json_encode(['success' => true, 'items' => $items]);
            break;

        // --- 20. Inbox-Item zuordnen (Neuer Kai-Artikel oder bestehender) ---
        case 'resolve_inbox':
            $ebonName = trim($input['ebon_name'] ?? '');
            $targetId = filter_var($input['target_id'] ?? null, FILTER_VALIDATE_INT);
            $actionType = $input['action_type'] ?? ''; // 'new' oder 'map'

            if ($ebonName === '') {
                Auth::sendJsonError(400, 'eBon-Name fehlt');
            }

            if ($actionType === 'new') {
                // Legt neuen Master-Artikel an
                $productId = $productRepo->saveOrUpdate(['name' => $ebonName]);
                $mappingRepo->save($ebonName, $productId);
            } elseif ($actionType === 'map') {
                if (!$targetId) {
                    Auth::sendJsonError(400, 'Ziel-Artikel fehlt');
                }
                $mappingRepo->save($ebonName, $targetId);
                // Lernprozess erneut triggern um die Historie auf das neue Mapping anzuwenden
                $learningService->learnFromReceipts();
            } else {
                Auth::sendJsonError(400, 'Ungültige Aktion');
            }

            echo json_encode(['success' => true, 'message' => 'Zuordnung gespeichert']);
            break;

        // --- 21. Artikel zusammenführen (Merge) ---
        case 'merge_products':
            $sourceIds = $input['source_ids'] ?? [];
            $targetId = filter_var($input['target_id'] ?? null, FILTER_VALIDATE_INT);

            if (!is_array($sourceIds) || empty($sourceIds)) {
                Auth::sendJsonError(400, 'Keine Quellartikel ausgewählt');
            }
            if (!$targetId) {
                Auth::sendJsonError(400, 'Ziel-Artikel fehlt');
            }

            // Sicherstellen, dass alle IDs Integer sind
            $sourceIds = array_map('intval', $sourceIds);

            $success = $productRepo->merge($sourceIds, $targetId);

            if ($success) {
                // Da wir die Zuordnungen geändert haben, berechnen wir die Verbrauchsintervalle neu
                $learningService->learnFromReceipts();
                echo json_encode(['success' => true, 'message' => count($sourceIds) . ' Artikel erfolgreich zusammengeführt']);
            } else {
                Auth::sendJsonError(500, 'Fehler beim Zusammenführen der Artikel');
            }
            break;

        // --- 22. KI-Aufräumvorschläge generieren ---
        case 'ai_suggest_merges':
            // Lade alle Artikel, die keine Mappings auf sich selbst haben, bzw.
            // einfach alle Artikel zum Clustern. Um Token zu sparen, laden wir
            // nur Name und ID.
            $allProducts = $productRepo->getAll();
            $productList = [];
            foreach ($allProducts as $p) {
                $productList[] = ['id' => $p['id'], 'name' => $p['name']];
            }

            if (empty($productList)) {
                echo json_encode(['success' => true, 'clusters' => []]);
                break;
            }

            $prompt = "Du bist ein intelligenter Datenbereinigungs-Assistent für eine Einkaufsliste.
Folgend ist eine JSON-Liste von Artikeln (id und name), die oft Duplikate aufgrund verschiedener Kassenbon-Schreibweisen enthalten.
Finde Gruppen von Namen, die dasselbe Produkt meinen (z.B. 'REWE Bio Erdb. 500g', 'Erdbeeren lose', 'Erdbeeren').
Gib ein JSON-Array von Clustern zurück. Jedes Cluster muss so aussehen:
{
  \"target_name\": \"Der generischste, schönste Name für diese Gruppe (z.B. 'Erdbeeren')\",
  \"source_ids\": [id1, id2, ...] (Alle IDs, die in diese Gruppe gehören. Mindestens 2 IDs pro Cluster!)
}
Ignoriere Artikel, die keine Duplikate haben. Liefere NUR sauberes JSON zurück, ohne Markdown-Codeblöcke!

Artikel-Liste:
" . json_encode($productList);

            try {
                $gemini = new GeminiClient();
                $response = $gemini->generate($prompt, null, null, true);
                
                if (!$response || !isset($response['text'])) {
                    Auth::sendJsonError(500, 'KI lieferte keine gültige Antwort');
                }

                $clusters = json_decode($response['text'], true);
                
                // Falls die KI versehentlich in Markdown antwortet, parsen:
                if ($clusters === null) {
                    $cleanJson = preg_replace('/```json|```/', '', $response['text']);
                    $clusters = json_decode(trim($cleanJson), true);
                }

                if (!is_array($clusters)) {
                    $clusters = []; // Fallback
                }

                echo json_encode(['success' => true, 'clusters' => $clusters]);
            } catch (Exception $e) {
                $logger->error('API: ai_suggest_merges failed', ['error' => $e->getMessage()]);
                Auth::sendJsonError(500, 'Fehler bei der KI-Anfrage');
            }
            break;

        default:
            Auth::sendJsonError(400, 'Unbekannte Aktion');
    }
} catch (Throwable $e) {
    $logger->error("einkaufsliste/api.php: Fehler bei Ausführung.", [
        'action' => $action,
        'error' => $e->getMessage(),
    ]);
    Auth::sendJsonError(500, 'Interner Serverfehler bei der Verarbeitung');
}

