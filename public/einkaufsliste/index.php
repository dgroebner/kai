<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Einkaufsliste\CategoryIconHelper;
use Kai\Tools\Einkaufsliste\HolidayService;
use Kai\Tools\Einkaufsliste\MarketCategoryRepository;
use Kai\Tools\Einkaufsliste\ProductMasterRepository;
use Kai\Tools\Einkaufsliste\ReceiptSessionService;
use Kai\Tools\Einkaufsliste\ShoppingListRepository;
use Kai\Tools\Einkaufsliste\ShoppingSessionRepository;
use Kai\Tools\Einkaufsliste\SuggestionService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

// 1. Auth-Check — immer zuerst
Auth::requirePage('shopping_read');

$csrfToken = Auth::csrfToken();
$activeTab = $_GET['tab'] ?? 'list';
if (!Auth::hasPermission('shopping_master') && in_array($activeTab, ['inbox', 'aisles'])) {
    $activeTab = 'list';
}
$activeMarket = $_GET['market'] ?? 'all';
if (!in_array($activeMarket, ['all', 'Rewe', 'Globus'], true)) {
    $activeMarket = 'all';
}

try {
    $listRepo = new ShoppingListRepository();
    $productRepo = new ProductMasterRepository();
    $categoryRepo = new MarketCategoryRepository();
    $holidayService = new HolidayService();
    $sessionRepo = new ShoppingSessionRepository();
    $receiptSessionService = new ReceiptSessionService();
    $suggestionService = new SuggestionService($productRepo, $listRepo, $holidayService);

    // Daten für die Ansichten laden
    $activeSession = $sessionRepo->getActiveSession();
    $recentSessions = $sessionRepo->getRecentSessions(10);
    $marketFilter = $activeMarket === 'all' ? null : $activeMarket;
    $items = $listRepo->getItems($marketFilter, true);
    $marketCounts = $listRepo->getItemCountsByMarket();
    $holidayContext = $holidayService->getHolidayContext();
    $categoriesGrouped = $categoryRepo->getAllCategoriesGrouped();
    $allProducts = $productRepo->getAll();

    $uniqueCats = [];
    foreach ($categoriesGrouped as $cats) {
        foreach ($cats as $cat) {
            $uniqueCats[$cat['category_name']] = true;
        }
    }
    $uniqueCats = array_keys($uniqueCats);
    sort($uniqueCats);

    // Vorschläge vorab berechnen
    $suggestions = $suggestionService->generateSuggestions(3);

} catch (Throwable $e) {
    (new Logger())->error('einkaufsliste/index.php: Fehler beim Laden der Daten.', ['error' => $e->getMessage()]);
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
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
    <title>kai - Einkaufsliste</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <!-- FontAwesome oder eigene Icons knnten hier folgen -->
    <meta name="market-categories"
          content="<?= htmlspecialchars(json_encode($categoriesGrouped ?? []), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="unique-cats" content="<?= htmlspecialchars(json_encode($uniqueCats ?? []), ENT_QUOTES, 'UTF-8') ?>">
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <div>
            <h1>🛒 Intelligente Einkaufsliste</h1>
            <p class="text-muted" style="margin-bottom: 0;">2-Märkte-Splitting (Rewe & Globus) mit Gang-Sortierung und
                lernendem Vorschlagsmodell</p>
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
        <button type="button" class="btn <?= $activeTab === 'suggestions' ? '' : 'btn-outline' ?> js-tab-btn"
                data-tab="suggestions">
            💡 Vorschläge
            <?php if (count($suggestions) > 0): ?>
                <span class="badge badge-warning shopping-badge-counter"><?= count($suggestions) ?></span>
            <?php endif; ?>
        </button>
        <button type="button" class="btn <?= $activeTab === 'recipe' ? '' : 'btn-outline' ?> js-tab-btn"
                data-tab="recipe">
            🧑‍🍳 Rezept & KI
        </button>
        <button type="button" class="btn <?= $activeTab === 'history' ? '' : 'btn-outline' ?> js-tab-btn"
                data-tab="history">
            📋 Historie & E-Bons
        </button>
        <?php if (Auth::hasPermission('shopping_master')): ?>
            <button type="button" class="btn <?= $activeTab === 'inbox' ? '' : 'btn-outline' ?> js-tab-btn"
                    data-tab="inbox" id="tab-btn-inbox">
                📥 Unbekannte eBons
            </button>
            <button type="button" class="btn <?= $activeTab === 'aisles' ? '' : 'btn-outline' ?> js-tab-btn"
                    data-tab="aisles">
                🏪 Gänge & Artikelstamm
            </button>
        <?php endif; ?>
    </div>

    <main>
        <!-- Aktive Einkaufs-Session Banner -->
        <div id="shopping-active-banner" class="shopping-active-banner <?= $activeSession ? '' : 'hidden' ?>"
             data-session-id="<?= $activeSession ? (int)$activeSession['id'] : '' ?>">
            <div class="shopping-active-banner-info">
                <span class="shopping-active-banner-pulse"></span>
                <strong>Einkauf aktiv:</strong>
                <span id="banner-session-type"><?= htmlspecialchars(ucfirst($activeSession['session_type'] ?? 'Wocheneinkauf'), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="text-muted">(seit <span
                            id="banner-session-time"><?= $activeSession ? date('H:i', strtotime($activeSession['started_at'])) : '' ?></span> Uhr)</span>
                &bull;
                <span id="banner-checked-count"><?= (int)($activeSession['checked_count'] ?? 0) ?></span> / <span
                        id="banner-total-count"><?= (int)($activeSession['total_count'] ?? 0) ?></span> abgehakt
            </div>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <button type="button" class="btn btn-success btn-sm js-open-live-mode">📱 Live-Modus öffnen</button>
                <button type="button" class="btn btn-outline btn-sm js-cancel-session-btn"
                        data-session-id="<?= $activeSession ? (int)$activeSession['id'] : '' ?>" title="Einkauf abbrechen">Abbrechen
                </button>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- TAB 1: EINKAUFSLISTE                                           -->
        <!-- ============================================================== -->
        <section id="tab-list" class="shopping-tab-pane <?= $activeTab === 'list' ? '' : 'hidden' ?>">

            <!-- Schnellerfassung neuer Artikel -->
            <section class="card shopping-quick-add-card">
                <h3>+ Artikel schnell hinzufügen</h3>
                <form id="shopping-add-form" class="shopping-add-form">
                    <div class="shopping-add-grid">
                        <div class="form-group flex-2" style="flex-basis: 100%;">
                            <label for="input-item-name" class="sr-only">Artikelname</label>
                            <input type="text" id="input-item-name" name="name" class="form-control"
                                   list="known-products-datalist" placeholder="z.B. Bio-Milch, Butter, Kaffee..."
                                   required autocomplete="off" style="font-size: 1.1rem; padding: 0.75rem;">
                            <datalist id="known-products-datalist">
                                <?php foreach ($allProducts

                                as $p): ?>
                                <option value="<?= htmlspecialchars($p['display_name'] ?? $p['name'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-market="<?= htmlspecialchars($p['preferred_market'] ?? 'Rewe', ENT_QUOTES, 'UTF-8') ?>"
                                        data-category="<?= htmlspecialchars($p['default_category'] ?? 'Sonstiges', ENT_QUOTES, 'UTF-8') ?>"
                                        data-unit="<?= htmlspecialchars($p['default_unit'] ?? 'Stück', ENT_QUOTES, 'UTF-8') ?>">
                                    <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>

                    <div class="shopping-add-options"
                         style="margin-top: 1rem; display: flex; justify-content: space-between; align-items: center;">
                        <label style="cursor: pointer;">
                            <input type="checkbox" id="input-is-spontaneous" name="is_spontaneous" value="1">
                            ⚡ Spontaner Einkauf (akuter Bedarf)
                        </label>
                        <button type="button" class="btn btn-primary" id="btn-quick-start-next">Details & Hinzufügen
                            &rarr;
                        </button>
                    </div>
                </form>
            </section>

            <!-- Start-Bar für Supermarkt-Besuch (falls keine Session aktiv) -->
            <div id="shopping-start-session-bar" class="card"
                 style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; <?= $activeSession ? 'display:none;' : '' ?>">
                <div>
                    <h3 style="margin-bottom: 0.2rem; font-size: 1.05rem;">Supermarkt-Besuch starten</h3>
                    <p class="text-muted" style="margin-bottom: 0; font-size: 0.85rem;">Schaltet in den mobilen
                        Live-Modus mit großen Touch-Zielen & Markt-Filter.</p>
                </div>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary js-start-session-btn" data-type="wocheneinkauf">
                        🛒 Wocheneinkauf starten
                    </button>
                    <button type="button" class="btn btn-outline js-start-session-btn" data-type="spontaneinkauf">
                        ⚡ Spontaneinkauf starten
                    </button>
                </div>
            </div>

            <!-- Markt-Filter Bar -->
            <div class="card shopping-market-filter-card">
                <div class="shopping-market-chips">
                    <button type="button"
                            class="btn btn-sm <?= $activeMarket === 'all' ? 'btn-active-filter' : 'btn-outline' ?> js-market-filter"
                            data-market="all">
                        Alle Märkte (<?= (int)$marketCounts['all']['open'] ?>)
                    </button>
                    <button type="button"
                            class="btn btn-sm <?= $activeMarket === 'Rewe' ? 'btn-active-filter' : 'btn-outline' ?> js-market-filter chip-rewe"
                            data-market="Rewe">
                        🔴 Rewe (<?= (int)$marketCounts['Rewe']['open'] ?>)
                    </button>
                    <button type="button"
                            class="btn btn-sm <?= $activeMarket === 'Globus' ? 'btn-active-filter' : 'btn-outline' ?> js-market-filter chip-globus"
                            data-market="Globus">
                        🟠 Globus (<?= (int)$marketCounts['Globus']['open'] ?>)
                    </button>
                </div>

                <?php if ((int)$marketCounts['all']['checked'] > 0): ?>
                    <button type="button" class="btn btn-success js-complete-shopping-btn"
                            data-market="<?= htmlspecialchars($activeMarket, ENT_QUOTES, 'UTF-8') ?>">
                        ✔️ Einkauf abschließen
                        (<?= (int)($activeMarket === 'all' ? $marketCounts['all']['checked'] : $marketCounts[$activeMarket]['checked']) ?>
                        )
                    </button>
                <?php endif; ?>
            </div>

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
                        <button type="button" class="btn btn-outline js-tab-btn" data-tab="suggestions">💡 Vorschläge
                            prüfen
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($groupedOpen as $catName => $group): ?>
                        <div class="card shopping-aisle-group">
                            <div class="shopping-aisle-header">
                                <h4 class="aisle-title">
                                    <span class="aisle-badge">Gang <?= $group['order'] < 900 ? $group['order'] : '❓' ?></span>
                                    <?= CategoryIconHelper::getIcon($catName) . ' ' . htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?>
                                    <span class="text-muted">(<?= count($group['items']) ?>)</span>
                                </h4>
                            </div>

                            <div class="shopping-items-list">
                                <?php foreach ($group['items'] as $item): ?>
                                    <div class="shopping-item-row"
                                         data-id="<?= (int)$item['id'] ?>"
                                         data-market="<?= htmlspecialchars($item['market'] ?? 'Rewe', ENT_QUOTES, 'UTF-8') ?>"
                                         data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>"
                                         data-quantity="<?= (float)$item['quantity'] ?>"
                                         data-unit="<?= htmlspecialchars($item['unit'] ?? 'Stück', ENT_QUOTES, 'UTF-8') ?>"
                                         data-category="<?= htmlspecialchars($item['category'] ?? 'Sonstiges', ENT_QUOTES, 'UTF-8') ?>"
                                         data-note="<?= htmlspecialchars($item['note'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="shopping-item-check">
                                            <input type="checkbox" class="shopping-checkbox js-item-check"
                                                   data-id="<?= (int)$item['id'] ?>" title="Als erledigt markieren">
                                        </div>
                                        <div class="shopping-item-details js-edit-list-item-trigger"
                                             style="cursor: pointer;">
                                            <span class="item-name"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="item-quantity">
                                                <?= (float)$item['quantity'] == (int)$item['quantity'] ? (int)$item['quantity'] : number_format((float)$item['quantity'], 1, ',', '') ?>
                                                <?= htmlspecialchars($item['unit'] ?? 'Stück', ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <?php if (!empty($item['note'])): ?>
                                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">
                                                    <small><i><?= htmlspecialchars($item['note'], ENT_QUOTES, 'UTF-8') ?></i></small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="shopping-item-meta">
                                            <span class="badge badge-market <?= $item['market'] === 'Rewe' ? 'badge-rewe' : 'badge-globus' ?>">
                                                <?= htmlspecialchars($item['market'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <?php if (!empty($item['is_spontaneous'])): ?>
                                                <span class="badge badge-warning"
                                                      title="Spontankauf (verzerrt das Verbrauchsintervall nicht)">⚡ Spontan</span>
                                            <?php endif; ?>
                                            <?php if (($item['source'] ?? '') === 'recipe'): ?>
                                                <span class="badge badge-info"
                                                      title="Aus Rezept generiert">🧑‍🍳 Rezept</span>
                                            <?php elseif (($item['source'] ?? '') === 'suggestion'): ?>
                                                <span class="badge badge-info"
                                                      title="Aus automatischem Intervall vorgeschlagen">✨ Vorschlag</span>
                                            <?php endif; ?>
                                            <button type="button" class="btn-icon js-edit-list-item-trigger"
                                                    title="Bearbeiten">✏️
                                            </button>
                                            <button type="button" class="btn-icon js-delete-item-btn"
                                                    data-id="<?= (int)$item['id'] ?>" title="Löschen">🗑️
                                            </button>
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
                            <button type="button" class="btn btn-sm btn-success js-complete-shopping-btn"
                                    data-market="<?= htmlspecialchars($activeMarket, ENT_QUOTES, 'UTF-8') ?>">
                                Einkauf abschließen & löschen
                            </button>
                        </div>
                        <div class="shopping-items-list shopping-checked-list">
                            <?php foreach ($checkedItems as $item): ?>
                                <div class="shopping-item-row is-checked" data-id="<?= (int)$item['id'] ?>">
                                    <div class="shopping-item-check">
                                        <input type="checkbox" class="shopping-checkbox js-item-check"
                                               data-id="<?= (int)$item['id'] ?>" checked title="Wieder öffnen">
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
                                        <button type="button" class="btn-icon js-delete-item-btn"
                                                data-id="<?= (int)$item['id'] ?>" title="Löschen">🗑️
                                        </button>
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
                            Ermittelt Artikel, deren Verbrauchsintervall fällig ist – angepasst an sächsische
                            Schulferien und historische eBons.
                        </p>
                    </div>
                    <div class="shopping-header-actions">
                        <button type="button" id="btn-sync-ebons" class="btn btn-outline">
                            🔄 Aus eBons lernen
                        </button>
                        <?php if (!empty($suggestions)): ?>
                            <button type="button" id="btn-accept-all-suggestions" class="btn btn-primary"
                                    data-ids="<?= htmlspecialchars(json_encode(array_column($suggestions, 'product_id')), ENT_QUOTES, 'UTF-8') ?>">
                                Alle <?= count($suggestions) ?> übernehmen
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="suggestions-list-container" style="margin-top: 1.5rem;">
                    <?php if (empty($suggestions)): ?>
                        <div class="text-center shopping-empty-state">
                            <p>Keine fälligen Artikel gefunden. Entweder stehen alle Artikel bereits auf der Liste oder
                                es liegen noch nicht genügend eBons vor.</p>
                            <button type="button" class="btn btn-outline" id="btn-trigger-sync">🔄 Jetzt historische
                                eBons analysieren
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table stack-table table-compact">
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
                                                <div class="badge badge-warning" style="font-size: 0.75rem;">🏖️
                                                    Ferienfaktor
                                                </div>
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
                                            <div class="text-muted"
                                                 style="font-size: 0.8rem;"><?= date('d.m.Y', strtotime($sug['last_purchased_at'])) ?></div>
                                        </td>
                                        <td data-label="Intervall">
                                            ca. alle <?= number_format((float)$sug['effective_interval'], 1, ',', '') ?>
                                            Tage
                                        </td>
                                        <td data-label="Dringlichkeit">
                                            <div class="urgency-bar-container">
                                                <div class="urgency-bar <?= $sug['is_overdue'] ? 'urgency-overdue' : '' ?>"
                                                     style="width: <?= min(100, $sug['urgency_percent']) ?>%;"></div>
                                            </div>
                                            <span class="text-muted" style="font-size: 0.8rem;">
                                                <?= $sug['is_overdue'] ? '⚠️ Fällig seit ' . abs($sug['days_until_due']) . ' Tag(en)' : 'Fällig in ' . $sug['days_until_due'] . ' Tag(en)' ?>
                                            </span>
                                        </td>
                                        <td data-label="Aktion" class="text-right">
                                            <button type="button"
                                                    class="btn btn-sm btn-primary js-accept-single-suggestion"
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
                    Die KI erkennt alle Zutaten, ermittelt Mengen/Einheiten und ordnet sie automatisch nach Rewe bzw.
                    Globus und den korrekten Gängen zu.
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
                        <span id="recipe-loading-indicator"
                              class="hidden text-muted">⏳ Gemini analysiert Rezept...</span>
                        <button type="submit" id="btn-parse-recipe" class="btn btn-primary">🤖 Rezept analysieren
                        </button>
                    </div>
                </form>

                <!-- Container für die KI-Ergebnisse mit Checkboxen vor der Übernahme -->
                <div id="recipe-preview-container" class="hidden"
                     style="margin-top: 1.5rem; border-top: 1px solid var(--bg-surface-hover); padding-top: 1.5rem;">
                    <h4>Gefundene Zutaten & Zuordnungen:</h4>
                    <p class="text-muted" style="font-size: 0.9rem;">Prüfe die Zuordnung vor dem Hinzufügen. Du kannst
                        Markt und Menge noch anpassen:</p>
                    <div class="table-responsive">
                        <table class="data-table stack-table table-compact" id="recipe-preview-table">
                            <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="check-all-recipe-items" checked>
                                </th>
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
                        <button type="button" id="btn-save-recipe-items" class="btn btn-success">✔️ Ausgewählte Artikel
                            zur Einkaufsliste hinzufügen
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- TAB 4: INBOX (UNBEKANNTE EBONS)                                -->
        <!-- ============================================================== -->
        <section id="tab-inbox" class="shopping-tab-pane <?= $activeTab === 'inbox' ? '' : 'hidden' ?>">
            <div class="card">
                <h3>📥 Unbekannte eBons (Inbox)</h3>
                <p class="text-muted">
                    Hier landen Artikel aus Kassenbons, die das System noch nicht kennt.
                    Ordne sie bestehenden Kai-Artikeln zu oder lege sie als neue Artikel an.
                    Damit bleibt dein Artikelstamm sauber.
                </p>

                <div id="inbox-loading-indicator" class="text-center" style="padding: 2rem;">
                    <span class="spinner"></span> Lade Inbox...
                </div>

                <div id="inbox-list-container" class="inbox-list hidden">
                    <!-- Wird per JS befüllt -->
                </div>

                <div id="inbox-empty-state" class="hidden text-center" style="padding: 3rem 1rem;">
                    <span style="font-size: 3rem;">🎉</span>
                    <h4>Alles aufgeräumt!</h4>
                    <p class="text-muted">Es gibt keine unbekannten Kassenbon-Positionen mehr.</p>
                </div>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- TAB 5: GÄNGE & ARTIKELSTAMM                                    -->
        <!-- ============================================================== -->
        <section id="tab-aisles" class="shopping-tab-pane <?= $activeTab === 'aisles' ? '' : 'hidden' ?>">

            <!-- Gang-Reihenfolge Konfiguration -->
            <div class="card">
                <h3>🏪 Gang-Reihenfolge der Märkte konfigurieren</h3>
                <p class="text-muted">
                    Bestimme die exakte Reihenfolge der Gänge und Regale für deinen Stamm-Rewe und Globus, um den Gang
                    durch den Markt zu optimieren.
                </p>

                <div class="period-switcher" style="justify-content: flex-start; margin-bottom: 1rem;">
                    <button type="button" class="btn btn-sm js-aisle-market-toggle" data-market="Rewe">🔴 Rewe Gänge
                    </button>
                    <button type="button" class="btn btn-sm btn-outline js-aisle-market-toggle" data-market="Globus">🟠
                        Globus Gänge
                    </button>
                </div>

                <div id="aisle-list-rewe" class="aisle-management-box">
                    <ul class="aisle-sortable-list" data-market="Rewe">
                        <?php foreach ($categoriesGrouped['Rewe'] ?? [] as $cat): ?>
                            <li class="aisle-sortable-item"
                                data-category="<?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="aisle-handle">☰</span>
                                <span class="aisle-name"><?= CategoryIconHelper::getIcon($cat['category_name']) . ' ' . htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="aisle-item-actions">
                                    <button type="button" class="btn-icon js-move-aisle-up" title="Nach oben">⬆️
                                    </button>
                                    <button type="button" class="btn-icon js-move-aisle-down" title="Nach unten">⬇️
                                    </button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn btn-primary js-save-aisle-order" data-market="Rewe"
                            style="margin-top: 1rem;">
                        💾 Gang-Reihenfolge für Rewe speichern
                    </button>
                </div>

                <div id="aisle-list-globus" class="aisle-management-box hidden">
                    <ul class="aisle-sortable-list" data-market="Globus">
                        <?php foreach ($categoriesGrouped['Globus'] ?? [] as $cat): ?>
                            <li class="aisle-sortable-item"
                                data-category="<?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="aisle-handle">☰</span>
                                <span class="aisle-name"><?= CategoryIconHelper::getIcon($cat['category_name']) . ' ' . htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <div class="aisle-item-actions">
                                    <button type="button" class="btn-icon js-move-aisle-up" title="Nach oben">⬆️
                                    </button>
                                    <button type="button" class="btn-icon js-move-aisle-down" title="Nach unten">⬇️
                                    </button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn btn-primary js-save-aisle-order" data-market="Globus"
                            style="margin-top: 1rem;">
                        💾 Gang-Reihenfolge für Globus speichern
                    </button>
                </div>
            </div>

            <!-- Artikelstamm Übersicht -->
            <div class="card" style="margin-top: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h3>📦 Gelernter Artikelstamm (<?= count($allProducts) ?> Artikel)</h3>
                        <p class="text-muted" style="margin-bottom:0;">
                            Hier verwaltest du deine sauberen Kai-Artikel.
                        </p>
                    </div>

                    <!-- Bulk Action Bar -->
                    <div id="bulk-action-bar" class="bulk-action-bar hidden" style="margin-top: 1.5rem;">
                        <div>
                            <span id="bulk-selected-count">0</span> Artikel markiert
                        </div>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <span>Zusammenführen in:</span>
                            <select id="bulk-target-select" class="form-control" style="width: auto; min-width: 200px;">
                                <option value="">-- Ziel-Artikel wählen --</option>
                                <?php foreach ($allProducts as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['display_name'] ?? $p['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-primary" id="btn-execute-bulk-merge" disabled>
                                Zusammenführen
                            </button>
                        </div>
                    </div>

                    <!-- Schnellfilter -->
                    <div style="display: flex; align-items: center; gap: 0.5rem; width: 100%; max-width: 320px;">
                        <input type="text" id="product-master-filter" class="form-control"
                               placeholder="🔍 Artikel filtern..." style="width: 100%;">
                        <button type="button" id="btn-clear-product-filter" class="btn-icon hidden" title="Filter leeren" style="background:none; border:none; cursor:pointer; font-size:1.1rem; padding:0 0.25rem;">✕</button>
                    </div>
                </div>

                <div class="table-responsive" style="margin-top: 1.5rem; max-height: 500px; overflow-y: auto;">
                    <table class="data-table stack-table table-compact" id="product-master-table">
                        <thead>
                        <tr>
                            <th style="width: 30px; padding-right: 5px;"><input type="checkbox"
                                                                                id="check-all-products"
                                                                                title="Alle auswählen"></th>
                            <th>Artikel</th>
                            <th>Markt</th>
                            <th>Gang</th>
                            <th>Intervall</th>
                            <th>Ferien</th>
                            <th>Gekauft</th>
                            <th class="text-right">Aktion</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr id="product-master-filter-empty" class="hidden">
                            <td colspan="8" class="text-center text-muted" style="padding: 2rem;">
                                🔍 Keine Artikel gefunden, die der Suche entsprechen.
                            </td>
                        </tr>
                        <?php if (empty($allProducts)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">Noch keine Artikel im Stamm. Nutze
                                    „Aus eBons lernen" im Tab Vorschläge.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allProducts as $p): ?>
                                <tr data-id="<?= $p['id'] ?>" class="<?= $p['is_ignored'] ? 'row-ignored' : '' ?>">
                                    <td data-label="Auswahl" style="padding-right: 5px;">
                                        <input type="checkbox" class="merge-checkbox js-merge-check"
                                               value="<?= $p['id'] ?>">
                                    </td>
                                    <td data-label="Artikel">
                                        <strong><?= htmlspecialchars($p['display_name'] ?? $p['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    </td>
                                    <td data-label="Markt">
                                    <span class="badge badge-market <?= $p['preferred_market'] === 'Rewe' ? 'badge-rewe' : 'badge-globus' ?>">
                                        <?= htmlspecialchars($p['preferred_market'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    </td>
                                    <td data-label="Kategorie"><?= CategoryIconHelper::getIcon($p['default_category'] ?? 'Sonstiges') . ' ' . htmlspecialchars($p['default_category'] ?? 'Sonstiges', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td data-label="Intervall">
                                        <?= $p['avg_interval_days'] !== null ? number_format((float)$p['avg_interval_days'], 1, ',', '') . ' Tage' : '—' ?>
                                    </td>
                                    <td data-label="Ferien">
                                        <?php if ((float)$p['holiday_factor'] < 0.05): ?>
                                            <span class="badge badge-warning" title="Brotbüchse: Pausiert vor &amp; in Ferien, aktiv vor Schulstart">🥪 Brotbüchse</span>
                                        <?php elseif ((float)$p['holiday_factor'] > 1.0): ?>
                                            <span class="badge badge-info" title="Mehrbedarf in Ferien">🏖️ <?= (float)$p['holiday_factor'] ?>x</span>
                                        <?php else: ?>
                                            <span class="text-muted">1.0x</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Gekauft">
                                        <?= $p['last_purchased_at'] ? date('d.m.Y', strtotime($p['last_purchased_at'])) : '—' ?>
                                    </td>
                                    <td class="text-right" style="white-space:nowrap;">
                                        <button class="btn-icon js-mapping-btn" data-id="<?= $p['id'] ?>"
                                                data-name="<?= htmlspecialchars($p['display_name'] ?? $p['name'], ENT_QUOTES, 'UTF-8') ?>"
                                                title="eBon-Zuordnungen verwalten">🔗
                                        </button>
                                        <button class="btn-icon js-edit-product-btn" data-id="<?= $p['id'] ?>"
                                                title="Editieren">✏️
                                        </button>
                                        <button class="btn-icon js-toggle-ignore-btn" data-id="<?= $p['id'] ?>"
                                                data-ignored="<?= $p['is_ignored'] ? '1' : '0' ?>"
                                                title="Ignorieren">
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

        <!-- ============================================================== -->
        <!-- TAB 5: HISTORIE & E-BONS                                      -->
        <!-- ============================================================== -->
        <section id="tab-history" class="shopping-tab-pane <?= $activeTab === 'history' ? '' : 'hidden' ?>">
            <div class="card" style="margin-bottom: 1.5rem;">
                <div class="shopping-section-header">
                    <div>
                        <h3>📋 Einkaufshistorie & E-Bon-Matching</h3>
                        <p class="text-muted" style="margin-bottom: 0;">
                            Abgeschlossene Einkäufe, verknüpfte Kassenbons und Auswertung von geplanten Artikeln vs.
                            Spontankäufen.
                        </p>
                    </div>
                </div>
            </div>

            <div id="recent-sessions-container">
                <?php if (empty($recentSessions)): ?>
                    <div class="card text-center shopping-empty-state">
                        <p>Noch keine abgeschlossenen Einkäufe vorhanden.</p>
                        <p class="text-muted" style="font-size: 0.9rem;">
                            Starte deinen nächsten Einkauf im Tab "Einkaufsliste" und schließe ihn nach dem Bezahlen ab!
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recentSessions as $s): ?>
                        <div class="card shopping-receipt-card" data-session-id="<?= (int)$s['id'] ?>">
                            <div style="flex: 1; min-width: 260px;">
                                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.35rem;">
                                    <strong><?= htmlspecialchars(ucfirst($s['session_type']), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span class="badge badge-market badge-rewe">
                                        <?= date('d.m.Y', strtotime($s['started_at'])) ?>
                                    </span>
                                    <span class="text-muted" style="font-size: 0.85rem;">
                                        <?= date('H:i', strtotime($s['started_at'])) ?> - <?= $s['completed_at'] ? date('H:i', strtotime($s['completed_at'])) : '?' ?> Uhr
                                    </span>
                                </div>
                                <div style="font-size: 0.9rem; color: var(--text-muted); display: flex; gap: 1rem; flex-wrap: wrap;">
                                    <span>📦 <strong><?= (int)$s['item_count'] ?></strong> Artikel archiviert</span>
                                    <span>🧾 <strong><?= (int)$s['receipt_count'] ?></strong> E-Bons verknüpft</span>
                                    <?php if ((float)$s['receipts_total'] > 0): ?>
                                        <span>💶 <strong><?= number_format((float)$s['receipts_total'], 2, ',', '.') ?> €</strong></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                                <button type="button" class="btn btn-outline btn-sm js-view-session-analysis-btn"
                                        data-session-id="<?= (int)$s['id'] ?>">
                                    📊 E-Bon Abgleich
                                </button>
                                <button type="button" class="btn btn-outline btn-sm js-link-receipts-btn"
                                        data-session-id="<?= (int)$s['id'] ?>">
                                    🔗 Bons verknüpfen (<?= (int)$s['receipt_count'] ?>)
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<!-- ============================================================== -->
<!-- LIVE EINKAUFS-MODUS (MOBILE FULLSCREEN OVERLAY)                -->
<!-- ============================================================== -->
<div id="shopping-live-overlay" class="shopping-live-overlay hidden">
    <div class="shopping-live-sticky-header">
        <div class="shopping-live-header-inner">
            <div class="shopping-live-header-top">
                <div class="shopping-live-title">
                    <span class="shopping-active-banner-pulse"></span>
                    <span>🛒 Live-Einkauf</span>
                    <span id="live-session-type-badge" class="badge badge-info">Wocheneinkauf</span>
                </div>
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <span style="font-size: 0.95rem; font-weight: 600;">
                        <span id="live-checked-counter">0</span> / <span id="live-total-counter">0</span> erledigt
                    </span>
                    <button type="button" class="btn btn-outline btn-sm js-close-live-mode" title="Live-Modus pausieren">&times; Pause</button>
                </div>
            </div>
            <div class="shopping-live-filter-chips">
                <button type="button" class="btn btn-sm btn-active-filter js-live-market-filter" data-market="all">Alle Märkte</button>
                <button type="button" class="btn btn-sm btn-outline js-live-market-filter chip-rewe" data-market="Rewe">🔴 Rewe</button>
                <button type="button" class="btn btn-sm btn-outline js-live-market-filter chip-globus" data-market="Globus">🟠 Globus</button>
            </div>
        </div>
    </div>

    <div class="shopping-live-content" id="shopping-live-content">
        <!-- Wird live per JS befüllt -->
    </div>

    <div class="shopping-live-footer">
        <div class="shopping-live-footer-inner">
            <button type="button" class="btn btn-outline js-close-live-mode">⏸️ Pause</button>
            <button type="button" class="btn btn-success js-finish-live-session">✔️ Einkauf beenden</button>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- CHECKOUT BESTÄTIGUNGS-MODAL                                    -->
<!-- ============================================================== -->
<div id="checkout-confirm-modal" class="rule-modal-overlay hidden">
    <div class="rule-modal-card" style="max-width: 500px;">
        <div class="rule-modal-header">
            <h3>🛒 Einkauf abschließen?</h3>
            <button type="button" class="rule-modal-close" id="btn-close-checkout-modal">&times;</button>
        </div>
        <div class="rule-modal-body">
            <p style="margin-bottom: 1rem;">
                Möchtest du deinen Supermarkt-Besuch jetzt abschließen?
            </p>
            <div class="card"
                 style="background: rgba(255, 255, 255, 0.03); margin-bottom: 1rem; border-color: rgba(255, 255, 255, 0.1);">
                <p style="margin-bottom: 0.4rem;">
                    <strong><span id="checkout-modal-checked-count">0</span> abgehakte Artikel</strong> werden in deine
                    Einkaufshistorie überführt und von der aktiven Liste gelöscht.
                </p>
                <p class="text-muted" style="margin-bottom: 0; font-size: 0.85rem;">
                    <span id="checkout-modal-open-count">0</span> nicht gefundene Artikel verbleiben für das nächste Mal
                    auf der Liste.
                </p>
            </div>
        </div>
        <div class="rule-modal-footer">
            <button type="button" class="btn btn-outline" id="btn-cancel-checkout-modal">Abbrechen</button>
            <button type="button" class="btn btn-success" id="btn-confirm-checkout">Ja, Einkauf beenden</button>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- E-BON ZUORDNUNGS-MODAL                                         -->
<!-- ============================================================== -->
<div id="session-link-receipts-modal" class="rule-modal-overlay hidden">
    <div class="rule-modal-card" style="max-width: 650px;">
        <div class="rule-modal-header">
            <h3>🧾 Kassenbons zuordnen</h3>
            <button type="button" class="rule-modal-close" id="btn-close-link-receipts-modal">&times;</button>
        </div>
        <div class="rule-modal-body">
            <p class="text-muted" style="font-size: 0.9rem; margin-bottom: 1rem;">
                Wähle digitale Kassenbons (E-Bons) aus, die zu diesem Einkauf gehören. Es können mehrere Bons (z. B.
                Rewe und Globus) verknüpft werden.
            </p>
            <div id="candidate-receipts-loading" class="text-center hidden" style="padding: 1.5rem;">
                <span class="spinner"></span> Lade Kassenbons...
            </div>
            <div id="candidate-receipts-list"></div>
        </div>
        <div class="rule-modal-footer">
            <button type="button" class="btn btn-outline" id="btn-cancel-link-receipts-modal">Schließen</button>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- E-BON ABGLEICH & SPONTANKAUF-ANALYSE MODAL                     -->
<!-- ============================================================== -->
<div id="session-analysis-modal" class="rule-modal-overlay hidden">
    <div class="rule-modal-card" style="max-width: 800px;">
        <div class="rule-modal-header">
            <h3 id="session-analysis-title">📊 E-Bon-Abgleich & Spontankäufe</h3>
            <button type="button" class="rule-modal-close" id="btn-close-analysis-modal">&times;</button>
        </div>
        <div class="rule-modal-body">
            <div id="session-analysis-loading" class="text-center hidden" style="padding: 2rem;">
                <span class="spinner"></span> Analysiere Kassenbons und Einkaufsliste...
            </div>
            <div id="session-analysis-content"></div>
        </div>
        <div class="rule-modal-footer">
            <button type="button" class="btn btn-outline" id="btn-cancel-analysis-modal">Schließen</button>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- KI-MERGE MODAL                                                 -->
<!-- ============================================================== -->
<div id="ai-merge-modal" class="rule-modal-overlay hidden">
    <div class="rule-modal-card" style="max-width: 600px;">
        <div class="rule-modal-header">
            <h3>✨ KI-Aufräumvorschläge</h3>
            <button type="button" class="rule-modal-close" id="btn-close-ai-modal">&times;</button>
        </div>
        <div class="rule-modal-body">
            <p class="text-muted" style="font-size: 0.9rem;">
                Die KI analysiert deinen gesamten Artikelstamm und sucht nach ähnlichen Namen, die vermutlich dasselbe
                Produkt meinen.
            </p>

            <div id="ai-loading-indicator" class="text-center hidden" style="padding: 2rem;">
                <span class="spinner"></span> Analysiere Artikelstamm (das kann ein paar Sekunden dauern)...
            </div>

            <div id="ai-results-container" class="hidden">
                <!-- Wird per JS gefüllt -->
            </div>

            <div id="ai-empty-state" class="text-center hidden" style="padding: 2rem;">
                <span style="font-size: 2rem;">👍</span>
                <p>Die KI hat keine offensichtlichen Duplikate mehr gefunden.</p>
            </div>
        </div>
        <div class="rule-modal-footer">
            <button type="button" class="btn btn-outline" id="btn-cancel-ai-modal">Schließen</button>
            <button type="button" class="btn btn-primary" id="btn-start-ai-analysis">Analyse starten</button>
        </div>
    </div>
</div>

<!-- Modal / Toast Alert Container -->
<div id="shopping-toast" class="shopping-toast hidden"></div>

<!-- Quick-Add Modal -->
<div id="quick-add-modal" class="rule-modal-overlay hidden">
    <div class="rule-modal-card" style="max-width: 500px;">
        <div class="rule-modal-header">
            <h3>Ab auf die Liste</h3>
            <button type="button" class="rule-modal-close" id="btn-close-quick-add-modal">&times;</button>
        </div>
        <div class="rule-modal-body">
            <h4 id="quick-add-display-name" style="margin-bottom: 1rem; color: var(--primary-color);"></h4>

            <div class="form-group mb-2">
                <label>Menge: <span id="quick-add-slider-display" style="font-weight:bold;">1</span> <span
                            id="quick-add-unit-display"></span></label>
                <input type="range" id="quick-add-slider" class="form-control" style="margin: 10px 0;" min="0" max="9"
                       step="1" value="0">
                <div id="quick-add-slider-ticks"
                     style="display:flex; justify-content:space-between; font-size:0.7rem; color:var(--text-muted);">
                    <!-- Ticks dynamically injected via JS -->
                </div>
            </div>

            <div class="shopping-add-grid" style="margin-top: 1.5rem; display: flex; gap: 0.75rem;">
                <div class="form-group flex-1">
                    <label for="modal-add-quantity">Menge (manuell)</label>
                    <input type="number" id="modal-add-quantity" class="form-control" value="1" step="0.1" min="0.1">
                </div>
                <div class="form-group flex-1">
                    <label for="modal-add-unit">Einheit</label>
                    <select id="modal-add-unit" class="form-control">
                        <option value="Stück">Stück</option>
                        <option value="Packung">Packung</option>
                        <option value="kg">kg</option>
                        <option value="g">g</option>
                        <option value="Liter">Liter</option>
                        <option value="ml">ml</option>
                        <option value="Dose">Dose</option>
                        <option value="Flasche">Flasche</option>
                        <option value="Bund">Bund</option>
                        <option value="Becher">Becher</option>
                    </select>
                </div>
            </div>

            <div class="shopping-add-grid" style="margin-top: 0.75rem; display: flex; gap: 0.75rem;">
                <div class="form-group flex-1">
                    <label for="modal-add-market">Markt</label>
                    <select id="modal-add-market" class="form-control">
                        <option value="Rewe">Rewe</option>
                        <option value="Globus">Globus</option>
                        <option value="Übergreifend">Übergreifend</option>
                    </select>
                </div>
                <div class="form-group flex-1">
                    <label for="modal-add-category">Kategorie</label>
                    <select id="modal-add-category" class="form-control">
                        <option value="Sonstiges">Sonstiges</option>
                        <?php foreach ($uniqueCats ?? [] as $c): ?>
                            <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>"><?= CategoryIconHelper::getIcon($c) . ' ' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-top: 0.75rem;">
                <label for="modal-add-note">Bemerkung (optional)</label>
                <input type="text" id="modal-add-note" class="form-control"
                       placeholder='z.B. "lactosefrei", "für Mama"'>
            </div>
        </div>
        <div class="rule-modal-footer">
            <button type="button" class="btn btn-primary" id="btn-submit-quick-add">+ Zur Liste</button>
        </div>
    </div>
</div>

<!-- Einkaufslisten-Eintrag bearbeiten Modal -->
<div id="list-item-edit-modal" class="rule-modal-overlay hidden">
    <div class="rule-modal-card" style="max-width: 450px;">
        <div class="rule-modal-header">
            <h3>Eintrag bearbeiten</h3>
            <button type="button" class="rule-modal-close" id="btn-close-list-item-modal">&times;</button>
        </div>
        <div class="rule-modal-body">
            <input type="hidden" id="modal-list-item-id">
            <div class="form-group">
                <label for="modal-list-item-name">Artikelname</label>
                <input type="text" id="modal-list-item-name" class="form-control">
            </div>
            <div class="form-group mb-2">
                <label>Menge: <span id="edit-item-slider-display" style="font-weight:bold;">1</span> <span
                            id="edit-item-unit-display"></span></label>
                <input type="range" id="edit-item-slider" class="form-control" style="margin: 10px 0;" min="0" max="9"
                       step="1" value="0">
                <div id="edit-item-slider-ticks"
                     style="display:flex; justify-content:space-between; font-size:0.7rem; color:var(--text-muted);">
                    <!-- Ticks dynamically injected via JS -->
                </div>
            </div>

            <div class="shopping-add-grid" style="margin-top: 1.5rem; display: flex; gap: 0.75rem;">
                <div class="form-group flex-1">
                    <label for="modal-list-item-quantity">Menge (manuell)</label>
                    <input type="number" id="modal-list-item-quantity" step="0.1" min="0.1" class="form-control">
                </div>
                <div class="form-group flex-1">
                    <label for="modal-list-item-unit">Einheit</label>
                    <select id="modal-list-item-unit" class="form-control">
                        <option value="Stück">Stück</option>
                        <option value="Packung">Packung</option>
                        <option value="kg">kg</option>
                        <option value="g">g</option>
                        <option value="Liter">Liter</option>
                        <option value="ml">ml</option>
                        <option value="Dose">Dose</option>
                        <option value="Flasche">Flasche</option>
                        <option value="Bund">Bund</option>
                        <option value="Becher">Becher</option>
                    </select>
                </div>
            </div>

            <div class="shopping-add-grid" style="margin-top: 0.75rem; display: flex; gap: 0.75rem;">
                <div class="form-group flex-1">
                    <label for="modal-list-item-market">Markt</label>
                    <select id="modal-list-item-market" class="form-control">
                        <option value="Rewe">Rewe</option>
                        <option value="Globus">Globus</option>
                        <option value="Übergreifend">Übergreifend</option>
                    </select>
                </div>
                <div class="form-group flex-1">
                    <label for="modal-list-item-category">Kategorie</label>
                    <select id="modal-list-item-category" class="form-control">
                        <option value="Sonstiges">Sonstiges</option>
                        <?php foreach ($uniqueCats ?? [] as $c): ?>
                            <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>"><?= CategoryIconHelper::getIcon($c) . ' ' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-top: 0.75rem;">
                <label for="modal-list-item-note">Bemerkung</label>
                <input type="text" id="modal-list-item-note" class="form-control"
                       placeholder="z.B. laktosefrei, für Mama...">
            </div>
        </div>
        <div class="rule-modal-footer">
            <button type="button" class="btn btn-outline" id="btn-cancel-list-item">Abbrechen</button>
            <button type="button" class="btn btn-primary" id="btn-save-list-item">Speichern</button>
        </div>
    </div>
</div>

<div id="product-edit-modal" class="rule-modal-overlay hidden">
    <div class="rule-modal-card" style="max-width: 450px;">
        <div class="rule-modal-header">
            <h3>Produkt bearbeiten</h3>
            <button type="button" class="rule-modal-close" id="btn-close-product-modal">&times;</button>
        </div>
        <div class="rule-modal-body">
            <input type="hidden" id="modal-product-id">
            <div class="form-group">
                <label for="modal-name">Artikelname</label>
                <input type="text" id="modal-name" class="form-control" placeholder="Artikelname">
            </div>
            <div class="shopping-add-grid" style="display: flex; gap: 0.75rem;">
                <div class="form-group flex-1">
                    <label for="modal-product-market">Bevorzugter Markt</label>
                    <select id="modal-product-market" class="form-control">
                        <option value="Rewe">Rewe</option>
                        <option value="Globus">Globus</option>
                        <option value="Übergreifend">Übergreifend</option>
                    </select>
                </div>
                <div class="form-group flex-1">
                    <label for="modal-product-unit">Einheit</label>
                    <select id="modal-product-unit" class="form-control">
                        <option value="Stück">Stück</option>
                        <option value="Packung">Packung</option>
                        <option value="kg">kg</option>
                        <option value="g">g</option>
                        <option value="Liter">Liter</option>
                        <option value="ml">ml</option>
                        <option value="Dose">Dose</option>
                        <option value="Flasche">Flasche</option>
                        <option value="Bund">Bund</option>
                        <option value="Becher">Becher</option>
                    </select>
                </div>
            </div>
            <div class="shopping-add-grid" style="display: flex; gap: 0.75rem;">
                <div class="form-group flex-1">
                    <label for="modal-product-category">Standard-Kategorie (Gang)</label>
                    <select id="modal-product-category" class="form-control">
                        <option value="Sonstiges">Sonstiges</option>
                        <?php foreach ($uniqueCats ?? [] as $c): ?>
                            <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>"><?= CategoryIconHelper::getIcon($c) . ' ' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group flex-1">
                    <label for="modal-product-interval">Kaufzyklus (Tage)</label>
                    <input type="number" id="modal-product-interval" step="0.5" min="0.5" class="form-control" placeholder="z. B. 7 (automatisch)">
                </div>
            </div>
            <div class="form-group">
                <label for="modal-product-holiday-mode">Ferien-Verhalten</label>
                <select id="modal-product-holiday-mode" class="form-control">
                    <option value="1.00">🔄 Normal (ganzjährig gleicher Bedarf)</option>
                    <option value="0.00">🥪 Nur Schulzeit / Brotbüchse (pausiert vor &amp; in Ferien, aktiv vor Schulstart)</option>
                    <option value="1.50">🏖️ Mehrbedarf in Ferien (+50 % häufiger)</option>
                    <option value="2.00">🏖️ Starker Mehrbedarf in Ferien (doppelt so häufig)</option>
                </select>
            </div>
            <div class="form-group">
                <label><input type="checkbox" id="modal-ignore-checkbox"> Ignorieren</label>
            </div>
        </div>
        <div class="rule-modal-footer">
            <button type="button" class="btn btn-outline" id="btn-cancel-product">Abbrechen</button>
            <button type="button" class="btn btn-primary" id="btn-save-product">Speichern</button>
        </div>
    </div>
</div>

<!-- eBon-Mapping Modal -->
<div id="ebon-mapping-modal" class="rule-modal-overlay hidden">
    <div class="rule-modal-card" style="max-width: 550px;">
        <div class="rule-modal-header">
            <h3>🔗 eBon-Zuordnungen: <span id="mapping-modal-product-name"></span></h3>
            <button type="button" class="rule-modal-close" id="btn-close-mapping-modal">&times;</button>
        </div>
        <div class="rule-modal-body">
            <input type="hidden" id="mapping-modal-product-id">
            <p class="text-muted" style="font-size: 0.875rem; margin-bottom: 1rem;">
                Kassenbonbezeichnungen, die diesem Master-Artikel zugeordnet sind.
                Beim nächsten eBon-Import werden diese Namen automatisch auf den Master-Artikel gemappt.
            </p>

            <!-- Liste vorhandener Mappings -->
            <div id="mapping-list-container" style="margin-bottom: 1.25rem;">
                <p class="text-muted" style="font-size: 0.85rem;">Keine Zuordnungen vorhanden.</p>
            </div>

            <!-- Neues Mapping hinzufügen -->
            <div class="form-group" style="display: flex; gap: 0.5rem; align-items: flex-end;">
                <div style="flex: 1;">
                    <label for="mapping-new-ebon-name" style="font-size: 0.875rem;">Neuen eBon-Namen zuordnen</label>
                    <input type="text" id="mapping-new-ebon-name" class="form-control"
                           placeholder='z. B. "Erdb. 500g" oder "Erdbeeren lose"' maxlength="255">
                </div>
                <button type="button" class="btn btn-primary" id="btn-add-mapping" style="white-space: nowrap;">+
                    Zuordnen
                </button>
            </div>
        </div>
        <div class="rule-modal-footer">
            <button type="button" class="btn btn-outline" id="btn-close-mapping-modal-footer">Schließen</button>
        </div>
    </div>
</div>

<script src="../js/einkaufsliste.js?v=<?= APP_VERSION ?>" defer></script>
<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
</body>
</html>


