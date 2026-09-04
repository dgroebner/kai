<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Einkaufsliste\HolidayService;
use Kai\Tools\Einkaufsliste\MarketCategoryRepository;
use Kai\Tools\Einkaufsliste\ProductMasterRepository;
use Kai\Tools\Einkaufsliste\ShoppingListRepository;
use Kai\Tools\Einkaufsliste\SuggestionService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// 1. Auth-Check — immer zuerst
Auth::requirePage();

$csrfToken = Auth::csrfToken();
$activeTab = $_GET['tab'] ?? 'list';
$activeMarket = $_GET['market'] ?? 'all';
if (!in_array($activeMarket, ['all', 'Rewe', 'Globus'], true)) {
    $activeMarket = 'all';
}

try {
    $listRepo = new ShoppingListRepository();
    $productRepo = new ProductMasterRepository();
    $categoryRepo = new MarketCategoryRepository();
    $holidayService = new HolidayService();
    $suggestionService = new SuggestionService($productRepo, $listRepo, $holidayService);

    // Daten für die Ansichten laden
    $marketFilter = $activeMarket === 'all' ? null : $activeMarket;
    $items = $listRepo->getItems($marketFilter, true);
    $marketCounts = $listRepo->getItemCountsByMarket();
    $holidayContext = $holidayService->getHolidayContext();
    $categoriesGrouped = $categoryRepo->getAllCategoriesGrouped();
    $allProducts = $productRepo->getAll();

    // Vorschläge vorab berechnen
    $suggestions = $suggestionService->generateSuggestions(3);

} catch (Throwable $e) {
    new Logger()->error('einkaufsliste/index.php: Fehler beim Laden der Daten.', ['error' => $e->getMessage()]);
    http_response_code(500);
    exit('Interner Fehler. Bitte versuche es später erneut.');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Einkaufsliste - Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <div>
            <h1>🛒 Intelligente Einkaufsliste</h1>
            <p class="text-muted" style="margin-bottom: 0;">2-Märkte-Splitting (Rewe & Globus) mit Gang-Sortierung und lernendem Vorschlagsmodell</p>
        </div>
        <div class="page-header-actions">
            <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
        </div>
    </header>

    <!-- Status-Banner: Sächsische Schulferien -->
    <div class="shopping-holiday-banner <?= $holidayContext['is_holiday'] ? 'banner-holiday' : '' ?>">
        <span><?= htmlspecialchars($holidayContext['status_badge'], ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <!-- Haupt-Navigation (Tabs) -->
    <div class="period-switcher shopping-tab-nav">
        <button type="button" class="btn <?= $activeTab === 'list' ? '' : 'btn-outline' ?> js-tab-btn" data-tab="list">
            🛒 Einkaufsliste
            <span class="badge badge-info shopping-badge-counter"><?= (int)$marketCounts['all']['open'] ?></span>
        </button>
        <button type="button" class="btn <?= $activeTab === 'suggestions' ? '' : 'btn-outline' ?> js-tab-btn" data-tab="suggestions">
            💡 Vorschläge
            <?php if (count($suggestions) > 0): ?>
                <span class="badge badge-warning shopping-badge-counter"><?= count($suggestions) ?></span>
            <?php endif; ?>
        </button>
        <button type="button" class="btn <?= $activeTab === 'recipe' ? '' : 'btn-outline' ?> js-tab-btn" data-tab="recipe">
            🧑‍🍳 Rezept & KI
        </button>
        <button type="button" class="btn <?= $activeTab === 'aisles' ? '' : 'btn-outline' ?> js-tab-btn" data-tab="aisles">
            🏪 Gänge & Artikelstamm
        </button>
    </div>

    <main>
        <!-- ============================================================== -->
        <!-- TAB 1: EINKAUFSLISTE                                           -->
        <!-- ============================================================== -->
        <section id="tab-list" class="shopping-tab-pane <?= $activeTab === 'list' ? '' : 'hidden' ?>">
            
            <!-- Markt-Filter Bar -->
            <div class="card shopping-market-filter-card">
                <div class="shopping-market-chips">
                    <button type="button" class="btn btn-sm <?= $activeMarket === 'all' ? 'btn-active-filter' : 'btn-outline' ?> js-market-filter" data-market="all">
                        Alle Märkte (<?= (int)$marketCounts['all']['open'] ?>)
                    </button>
                    <button type="button" class="btn btn-sm <?= $activeMarket === 'Rewe' ? 'btn-active-filter' : 'btn-outline' ?> js-market-filter chip-rewe" data-market="Rewe">
                        🔴 Rewe (<?= (int)$marketCounts['Rewe']['open'] ?>)
                    </button>
                    <button type="button" class="btn btn-sm <?= $activeMarket === 'Globus' ? 'btn-active-filter' : 'btn-outline' ?> js-market-filter chip-globus" data-market="Globus">
                        🟠 Globus (<?= (int)$marketCounts['Globus']['open'] ?>)
                    </button>
                </div>
                
                <?php if ((int)$marketCounts['all']['checked'] > 0): ?>
                    <button type="button" class="btn btn-success js-complete-shopping-btn" data-market="<?= htmlspecialchars($activeMarket, ENT_QUOTES, 'UTF-8') ?>">
                        ✔️ Einkauf abschließen (<?= (int)($activeMarket === 'all' ? $marketCounts['all']['checked'] : $marketCounts[$activeMarket]['checked']) ?>)
                    </button>
                <?php endif; ?>
            </div>

            <!-- Schnellerfassung neuer Artikel -->
            <section class="card shopping-quick-add-card">
                <h3>+ Artikel schnell hinzufügen</h3>
                <form id="shopping-add-form" class="shopping-add-form">
                    <div class="shopping-add-grid">
                        <div class="form-group flex-2">
                            <label for="input-item-name">Artikelname</label>
                            <input type="text" id="input-item-name" name="name" class="form-control" list="known-products-datalist" placeholder="z.B. Bio-Milch, Butter, Kaffee..." required autocomplete="off">
                            <datalist id="known-products-datalist">
                                <?php foreach ($allProducts as $p): ?>
                                    <option value="<?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>" 
                                            data-market="<?= htmlspecialchars($p['preferred_market'] ?? 'Rewe', ENT_QUOTES, 'UTF-8') ?>"
                                            data-category="<?= htmlspecialchars($p['default_category'] ?? 'Sonstiges', ENT_QUOTES, 'UTF-8') ?>"
                                            data-unit="<?= htmlspecialchars($p['default_unit'] ?? 'Stück', ENT_QUOTES, 'UTF-8') ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="form-group flex-1">
                            <label for="input-item-quantity">Menge</label>
                            <input type="number" id="input-item-quantity" name="quantity" class="form-control" value="1" step="0.1" min="0.1">
                        </div>

                        <div class="form-group flex-1">
                            <label for="input-item-unit">Einheit</label>
                            <select id="input-item-unit" name="unit" class="form-control">
                                <option value="Stück">Stück</option>
                                <option value="Packung">Packung</option>
                                <option value="kg">kg</option>
                                <option value="g">g</option>
                                <option value="Liter">Liter</option>
                                <option value="Dose">Dose</option>
                                <option value="Flasche">Flasche</option>
                                <option value="Bund">Bund</option>
                                <option value="Becher">Becher</option>
                            </select>
                        </div>

                        <div class="form-group flex-1">
                            <label for="input-item-market">Markt</label>
                            <select id="input-item-market" name="market" class="form-control">
                                <option value="Rewe" <?= $activeMarket === 'Rewe' ? 'selected' : '' ?>>Rewe</option>
                                <option value="Globus" <?= $activeMarket === 'Globus' ? 'selected' : '' ?>>Globus</option>
                            </select>
                        </div>

                        <div class="form-group flex-2">
                            <label for="input-item-category">Gang / Kategorie</label>
                            <select id="input-item-category" name="category" class="form-control">
                                <option value="">-- Automatisch / Auswählen --</option>
                                <?php
                                $reweCats = $categoriesGrouped['Rewe'] ?? [];
                                $globusCats = $categoriesGrouped['Globus'] ?? [];
                                $uniqueCats = array_unique(array_merge(
                                    array_column($reweCats, 'category_name'),
                                    array_column($globusCats, 'category_name')
                                ));
                                sort($uniqueCats);
                                foreach ($uniqueCats as $c): ?>
                                    <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="shopping-add-options">
                        <label class="checkbox-label" title="Spontankäufe fließen nicht in die wöchentliche Intervall-Berechnung ein">
                            <input type="checkbox" id="input-is-spontaneous" name="is_spontaneous" value="1">
                            <span>⚡ Spontaner Einkauf (akuter Bedarf)</span>
                        </label>

                        <button type="submit" class="btn btn-primary">+ Zur Liste</button>
                    </div>
                </form>
            </section>

            <!-- Offene Einkaufslisten-Elemente nach Gängen gruppiert -->
            <div id="shopping-items-container">
                <?php
                $openItems = array_filter($items, fn($i) => (int)$i['is_checked'] === 0);
                $checkedItems = array_filter($items, fn($i) => (int)$i['is_checked'] === 1);

                // Gruppieren der offenen Artikel nach Gang/Kategorie
                $groupedOpen = [];
                foreach ($openItems as $item) {
                    $cat = !empty($item['category']) ? $item['category'] : 'Sonstiges';
                    $order = (int)($item['aisle_order'] ?? 999);
                    if (!isset($groupedOpen[$cat])) {
                        $groupedOpen[$cat] = [
                            'name' => $cat,
                            'order' => $order,
                            'items' => []
                        ];
                    }
                    $groupedOpen[$cat]['items'][] = $item;
                }

                // Gänge sortieren
                uasort($groupedOpen, fn($a, $b) => $a['order'] <=> $b['order']);
                ?>

                <?php if (empty($openItems)): ?>
                    <div class="card text-center shopping-empty-state">
                        <p>🎉 Keine offenen Artikel für diesen Markt auf der Einkaufsliste!</p>
                        <button type="button" class="btn btn-outline js-tab-btn" data-tab="suggestions">💡 Vorschläge prüfen</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($groupedOpen as $catName => $group): ?>
                        <div class="card shopping-aisle-group">
                            <div class="shopping-aisle-header">
                                <h4 class="aisle-title">
                                    <span class="aisle-badge">Gang <?= $group['order'] < 900 ? $group['order'] : '•' ?></span>
                                    <?= htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?>
                                    <span class="text-muted">(<?= count($group['items']) ?>)</span>
                                </h4>
                            </div>

                            <div class="shopping-items-list">
                                <?php foreach ($group['items'] as $item): ?>
                                    <div class="shopping-item-row" data-id="<?= (int)$item['id'] ?>" data-market="<?= htmlspecialchars($item['market'], ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="shopping-item-check">
                                            <input type="checkbox" class="shopping-checkbox js-item-check" data-id="<?= (int)$item['id'] ?>" title="Als erledigt markieren">
                                        </div>
                                        <div class="shopping-item-details">
                                            <span class="item-name"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="item-quantity">
                                                <?= (float)$item['quantity'] == (int)$item['quantity'] ? (int)$item['quantity'] : number_format((float)$item['quantity'], 1, ',', '') ?>
                                                <?= htmlspecialchars($item['unit'] ?? 'Stück', ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                        <div class="shopping-item-meta">
                                            <span class="badge badge-market <?= $item['market'] === 'Rewe' ? 'badge-rewe' : 'badge-globus' ?>">
                                                <?= htmlspecialchars($item['market'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <?php if (!empty($item['is_spontaneous'])): ?>
                                                <span class="badge badge-warning" title="Spontankauf (verzerrt das Verbrauchsintervall nicht)">⚡ Spontan</span>
                                            <?php endif; ?>
                                            <?php if (($item['source'] ?? '') === 'recipe'): ?>
                                                <span class="badge badge-info" title="Aus Rezept generiert">🧑‍🍳 Rezept</span>
                                            <?php elseif (($item['source'] ?? '') === 'suggestion'): ?>
                                                <span class="badge badge-info" title="Aus automatischem Intervall vorgeschlagen">💡 Vorschlag</span>
                                            <?php endif; ?>
                                            <button type="button" class="btn-icon js-delete-item-btn" data-id="<?= (int)$item['id'] ?>" title="Löschen">🗑️</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Abgehakte Artikel (Erledigt) -->
                <?php if (!empty($checkedItems)): ?>
                    <div class="card shopping-checked-group">
                        <div class="shopping-aisle-header">
                            <h4 class="aisle-title text-muted">
                                ✔️ Erledigt (<?= count($checkedItems) ?>)
                            </h4>
                            <button type="button" class="btn btn-sm btn-success js-complete-shopping-btn" data-market="<?= htmlspecialchars($activeMarket, ENT_QUOTES, 'UTF-8') ?>">
                                Einkauf abschließen & löschen
                            </button>
                        </div>
                        <div class="shopping-items-list shopping-checked-list">
                            <?php foreach ($checkedItems as $item): ?>
                                <div class="shopping-item-row is-checked" data-id="<?= (int)$item['id'] ?>">
                                    <div class="shopping-item-check">
                                        <input type="checkbox" class="shopping-checkbox js-item-check" data-id="<?= (int)$item['id'] ?>" checked title="Wieder öffnen">
                                    </div>
                                    <div class="shopping-item-details">
                                        <span class="item-name strike-through"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="item-quantity text-muted">
                                            <?= (float)$item['quantity'] == (int)$item['quantity'] ? (int)$item['quantity'] : number_format((float)$item['quantity'], 1, ',', '') ?>
                                            <?= htmlspecialchars($item['unit'] ?? 'Stück', ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                    <div class="shopping-item-meta">
                                        <span class="badge badge-market <?= $item['market'] === 'Rewe' ? 'badge-rewe' : 'badge-globus' ?>">
                                            <?= htmlspecialchars($item['market'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <button type="button" class="btn-icon js-delete-item-btn" data-id="<?= (int)$item['id'] ?>" title="Löschen">🗑️</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- TAB 2: VORSCHLÄGE (Wocheneinkauf & eBon-Lernen)                 -->
        <!-- ============================================================== -->
        <section id="tab-suggestions" class="shopping-tab-pane <?= $activeTab === 'suggestions' ? '' : 'hidden' ?>">
            <div class="card">
                <div class="shopping-section-header">
                    <div>
                        <h3>💡 Intelligente Vorschläge für den Wocheneinkauf</h3>
                        <p class="text-muted" style="margin-bottom: 0;">
                            Ermittelt Artikel, deren Verbrauchsintervall fällig ist – angepasst an sächsische Schulferien und historische eBons.
                        </p>
                    </div>
                    <div class="shopping-header-actions">
                        <button type="button" id="btn-sync-ebons" class="btn btn-outline">
                            🔄 Aus eBons lernen
                        </button>
                        <?php if (!empty($suggestions)): ?>
                            <button type="button" id="btn-accept-all-suggestions" class="btn btn-primary" data-ids="<?= htmlspecialchars(json_encode(array_column($suggestions, 'product_id')), ENT_QUOTES, 'UTF-8') ?>">
                                Alle <?= count($suggestions) ?> übernehmen
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="suggestions-list-container" style="margin-top: 1.5rem;">
                    <?php if (empty($suggestions)): ?>
                        <div class="text-center shopping-empty-state">
                            <p>Keine fälligen Artikel gefunden. Entweder stehen alle Artikel bereits auf der Liste oder es liegen noch nicht genügend eBons vor.</p>
                            <button type="button" class="btn btn-outline" id="btn-trigger-sync">🔄 Jetzt historische eBons analysieren</button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table stack-table">
                                <thead>
                                <tr>
                                    <th>Artikel</th>
                                    <th>Markt</th>
                                    <th>Kategorie</th>
                                    <th>Letzter Kauf</th>
                                    <th>Intervall</th>
                                    <th>Dringlichkeit</th>
                                    <th class="text-right">Aktion</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($suggestions as $sug): ?>
                                    <tr>
                                        <td data-label="Artikel">
                                            <strong><?= htmlspecialchars($sug['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?php if ($sug['holiday_adapted']): ?>
                                                <div class="badge badge-warning" style="font-size: 0.75rem;">🏖️ Ferienfaktor</div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Markt">
                                            <span class="badge badge-market <?= $sug['preferred_market'] === 'Rewe' ? 'badge-rewe' : 'badge-globus' ?>">
                                                <?= htmlspecialchars($sug['preferred_market'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td data-label="Kategorie"><?= htmlspecialchars($sug['default_category'] ?? 'Sonstiges', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td data-label="Letzter Kauf">
                                            vor <?= (int)$sug['days_since_last'] ?> Tagen
                                            <div class="text-muted" style="font-size: 0.8rem;"><?= date('d.m.Y', strtotime($sug['last_purchased_at'])) ?></div>
                                        </td>
                                        <td data-label="Intervall">
                                            ca. alle <?= number_format((float)$sug['effective_interval'], 1, ',', '') ?> Tage
                                        </td>
                                        <td data-label="Dringlichkeit">
                                            <div class="urgency-bar-container">
                                                <div class="urgency-bar <?= $sug['is_overdue'] ? 'urgency-overdue' : '' ?>" style="width: <?= min(100, $sug['urgency_percent']) ?>%;"></div>
                                            </div>
                                            <span class="text-muted" style="font-size: 0.8rem;">
                                                <?= $sug['is_overdue'] ? '⚠️ Fällig seit ' . abs($sug['days_until_due']) . ' Tag(en)' : 'Fällig in ' . $sug['days_until_due'] . ' Tag(en)' ?>
                                            </span>
                                        </td>
                                        <td data-label="Aktion" class="text-right">
                                            <button type="button" class="btn btn-sm btn-primary js-accept-single-suggestion" 
                                                    data-id="<?= (int)$sug['product_id'] ?>"
                                                    data-market="<?= htmlspecialchars($sug['preferred_market'], ENT_QUOTES, 'UTF-8') ?>">
                                                + Übernehmen
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- TAB 3: REZEPT & FREITEXT (GEMINI KI-ASSISTENT)                 -->
        <!-- ============================================================== -->
        <section id="tab-recipe" class="shopping-tab-pane <?= $activeTab === 'recipe' ? '' : 'hidden' ?>">
            <div class="card">
                <h3>🧑‍🍳 Rezept- & Freitext-Assistent (Google Gemini)</h3>
                <p class="text-muted">
                    Füge hier ein Kochrezept, eine unformatierte Zutatenliste oder eine formlose Einkaufsnotiz ein.
                    Die KI erkennt alle Zutaten, ermittelt Mengen/Einheiten und ordnet sie automatisch nach Rewe bzw. Globus und den korrekten Gängen zu.
                </p>

                <form id="form-recipe-ai" class="recipe-form">
                    <div class="form-group">
                        <textarea id="recipe-input-text" class="form-control" rows="6" placeholder="z. B. Spaghetti Bolognese für 4 Personen:
500g Rinderhackfleisch
1 Zwiebel und 2 Zehen Knoblauch
1 Packung Spaghetti
2 Dosen gehackte Tomaten
50g Parmesan
Olivenöl, Salz, Pfeffer, Oregano"></textarea>
                    </div>
                    <div style="display: flex; gap: 1rem; align-items: center; justify-content: flex-end;">
                        <span id="recipe-loading-indicator" class="hidden text-muted">⏳ Gemini analysiert Rezept...</span>
                        <button type="submit" id="btn-parse-recipe" class="btn btn-primary">🤖 Rezept analysieren</button>
                    </div>
                </form>

                <!-- Container für die KI-Ergebnisse mit Checkboxen vor der Übernahme -->
                <div id="recipe-preview-container" class="hidden" style="margin-top: 1.5rem; border-top: 1px solid var(--bg-surface-hover); padding-top: 1.5rem;">
                    <h4>Gefundene Zutaten & Zuordnungen:</h4>
                    <p class="text-muted" style="font-size: 0.9rem;">Prüfe die Zuordnung vor dem Hinzufügen. Du kannst Markt und Menge noch anpassen:</p>
                    <div class="table-responsive">
                        <table class="data-table stack-table" id="recipe-preview-table">
                            <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="check-all-recipe-items" checked></th>
                                <th>Artikel</th>
                                <th>Menge</th>
                                <th>Einheit</th>
                                <th>Zielmarkt</th>
                                <th>Gang / Kategorie</th>
                            </tr>
                            </thead>
                            <tbody id="recipe-preview-body">
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top: 1rem; text-align: right;">
                        <button type="button" id="btn-save-recipe-items" class="btn btn-success">✔️ Ausgewählte Artikel zur Einkaufsliste hinzufügen</button>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- TAB 4: GÄNGE & ARTIKELSTAMM                                    -->
        <!-- ============================================================== -->
        <section id="tab-aisles" class="shopping-tab-pane <?= $activeTab === 'aisles' ? '' : 'hidden' ?>">
            
            <!-- Gang-Reihenfolge Konfiguration -->
            <div class="card">
                <h3>🏪 Gang-Reihenfolge der Märkte konfigurieren</h3>
                <p class="text-muted">
                    Bestimme die exakte Reihenfolge der Gänge und Regale für deinen Stamm-Rewe und Globus, um den Gang durch den Markt zu optimieren.
                </p>

                <div class="period-switcher" style="justify-content: flex-start; margin-bottom: 1rem;">
                    <button type="button" class="btn btn-sm js-aisle-market-toggle" data-market="Rewe">🔴 Rewe Gänge</button>
                    <button type="button" class="btn btn-sm btn-outline js-aisle-market-toggle" data-market="Globus">🟠 Globus Gänge</button>
                </div>

                <div id="aisle-list-rewe" class="aisle-management-box">
                    <ul class="aisle-sortable-list" data-market="Rewe">
                        <?php foreach ($categoriesGrouped['Rewe'] ?? [] as $cat): ?>
                            <li class="aisle-sortable-item" data-category="<?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="aisle-handle">☰</span>
                                <span class="aisle-name"><?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="aisle-item-actions">
                                    <button type="button" class="btn-icon js-move-aisle-up" title="Nach oben">⬆️</button>
                                    <button type="button" class="btn-icon js-move-aisle-down" title="Nach unten">⬇️</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn btn-primary js-save-aisle-order" data-market="Rewe" style="margin-top: 1rem;">
                        💾 Gang-Reihenfolge für Rewe speichern
                    </button>
                </div>

                <div id="aisle-list-globus" class="aisle-management-box hidden">
                    <ul class="aisle-sortable-list" data-market="Globus">
                        <?php foreach ($categoriesGrouped['Globus'] ?? [] as $cat): ?>
                            <li class="aisle-sortable-item" data-category="<?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="aisle-handle">☰</span>
                                <span class="aisle-name"><?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="aisle-item-actions">
                                    <button type="button" class="btn-icon js-move-aisle-up" title="Nach oben">⬆️</button>
                                    <button type="button" class="btn-icon js-move-aisle-down" title="Nach unten">⬇️</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn btn-primary js-save-aisle-order" data-market="Globus" style="margin-top: 1rem;">
                        💾 Gang-Reihenfolge für Globus speichern
                    </button>
                </div>
            </div>

            <!-- Artikelstamm Übersicht -->
            <div class="card" style="margin-top: 1.5rem;">
                <h3>📦 Gelernter Artikelstamm (<?= count($allProducts) ?> Artikel)</h3>
                <p class="text-muted">
                    Verknüpfungen aus eBons und manuellen Eingaben mit Marktzuordnung und Verbrauchszyklen.
                </p>

                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="data-table stack-table">
                        <thead>
                        <tr>
                            <th>Artikel</th>
                            <th>Bevorzugter Markt</th>
                            <th>Kategorie</th>
                            <th>Kaufintervall</th>
                            <th>Ferienfaktor</th>
                            <th>Letzter Kauf</th>
                                                    <th class="text-right">Aktion</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($allProducts)): ?>
                            <tr><td colspan="7" class="text-center text-muted">Noch keine Artikel im Stamm. Nutze „Aus eBons lernen" im Tab Vorschläge.</td></tr>
                        <?php else: ?>
                            <?php foreach ($allProducts as $p): ?>
                                <tr data-id="<?= $p['id'] ?>" class="<?= $p['is_ignored'] ? 'row-ignored' : '' ?>">
                                    <td data-label="Artikel"><strong><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                    <td data-label="Bevorzugter Markt">
                                        <span class="badge badge-market <?= $p['preferred_market'] === 'Rewe' ? 'badge-rewe' : 'badge-globus' ?>">
                                            <?= htmlspecialchars($p['preferred_market'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td data-label="Kategorie"><?= htmlspecialchars($p['default_category'] ?? 'Sonstiges', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td data-label="Kaufintervall">
                                        <?= $p['avg_interval_days'] !== null ? number_format((float)$p['avg_interval_days'], 1, ',', '') . ' Tage' : '—' ?>
                                    </td>
                                    <td data-label="Ferienfaktor">
                                        <?= (float)$p['holiday_factor'] > 1.0 ? '⚡ ' . (float)$p['holiday_factor'] . 'x' : '1.0x' ?>
                                    </td>
                                    <td data-label="Letzter Kauf">
                                        <?= $p['last_purchased_at'] ? date('d.m.Y', strtotime($p['last_purchased_at'])) : '—' ?>
                                    </td>
                                    <td class="text-right">
                                        <button class="btn-icon js-edit-product-btn" data-id="<?= $p['id'] ?>" title="Editieren">✏️</button>
                                        <button class="btn-icon js-toggle-ignore-btn" data-id="<?= $p['id'] ?>" data-ignored="<?= $p['is_ignored'] ? '1' : '0' ?>" title="Ignorieren">
                                            <?= $p['is_ignored'] ? '🚫' : '👁️' ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<!-- Modal / Toast Alert Container -->
<div id="shopping-toast" class="shopping-toast hidden"></div>
<div id="product-edit-modal" class="rule-modal-overlay hidden">
    <div class="rule-modal-card" style="max-width: 450px;">
        <div class="rule-modal-header">
            <h3>Produkt bearbeiten</h3>
            <button type="button" class="rule-modal-close" id="btn-close-product-modal">&times;</button>
        </div>
        <div class="rule-modal-body">
            <input type="hidden" id="modal-product-id">
            <div class="form-group">
                <label for="modal-custom-label">Label</label>
                <input type="text" id="modal-custom-label" class="tag-search-input" placeholder="Eigenes Label">
            </div>
            <div class="form-group">
                <label><input type="checkbox" id="modal-ignore-checkbox"> Ignorieren</label>
            </div>
        </div>
        <div class="rule-modal-footer">
            <button type="button" class="btn btn-primary" id="btn-save-product">Speichern</button>
            <button type="button" class="btn btn-outline" id="btn-cancel-product">Abbrechen</button>
        </div>
    </div>
</div>

<script src="../js/einkaufsliste.js?v=<?= APP_VERSION ?>" defer></script>
<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
</body>
</html>
