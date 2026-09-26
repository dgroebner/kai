<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Calendar\CalendarService;
use Kai\Tools\Shared\Security\Auth;

// 1. Auth-Check: Lesezugriff erforderlich
Auth::requirePage('calendar_read');

$canWrite = Auth::hasPermission('calendar_write');
$currentUserEmail = strtolower(trim((string)($_SESSION['user_email'] ?? '')));

$calendarService = new CalendarService();
$eventRepo = $calendarService->getEventRepository();

// Filter- und Paginierungs-Parameter auslesen
// Standardmäßig ('mine'): nur Termine, für die der angemeldete Benutzer Benachrichtigungen konfiguriert hat.
$filterScope = $_GET['scope'] ?? 'mine';
if ($filterScope !== 'all') {
    $filterScope = 'mine';
}

$filterType = $_GET['type'] ?? '';
$filterCategory = $_GET['category'] ?? '';
$filterSearch = trim((string)($_GET['q'] ?? ''));
$page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
$perPage = 15;

$scopeEmail = ($filterScope === 'mine') ? $currentUserEmail : null;

// Paginierte Daten chronologisch abrufen (Nächste Ereignisse zuerst!)
$pagedData = $calendarService->getPaginatedEventsChronological(
    $scopeEmail,
    $filterCategory !== '' ? $filterCategory : null,
    $filterType !== '' ? $filterType : null,
    $filterSearch !== '' ? $filterSearch : null,
    $page,
    $perPage
);

$events = $pagedData['events'];
$totalEvents = $pagedData['total'];
$totalPages = $pagedData['total_pages'];

$allUsers = $eventRepo->getAllPossibleUsers();
$categories = $eventRepo->getCategories();

$typeIcons = [
    'birthday' => '🎂',
    'anniversary' => '💍',
    'memorial' => '🕯️',
    'other' => '📅',
];

$typeLabels = [
    'birthday' => 'Geburtstag',
    'anniversary' => 'Jahrestag',
    'memorial' => 'Gedenktag',
    'other' => 'Sonstiges',
];

$advanceLabels = [
    0 => 'Am Tag selbst',
    1 => '1 Tag vorher',
    3 => '3 Tage vorher',
    7 => '1 Woche vorher',
    14 => '2 Wochen vorher',
];

function buildCalUrl(int $targetPage, string $scope, string $type, string $cat, string $q): string {
    $p = ['page' => $targetPage];
    if ($scope === 'all') {
        $p['scope'] = 'all';
    }
    if ($type !== '') {
        $p['type'] = $type;
    }
    if ($cat !== '') {
        $p['category'] = $cat;
    }
    if ($q !== '') {
        $p['q'] = $q;
    }
    return 'index.php?' . http_build_query($p);
}

function getUrgencyMeta(int $daysRemaining): array {
    if ($daysRemaining === 0) {
        return [
            'level' => 'today',
            'row_class' => 'cal-row-urgency cal-row--today',
            'badge_class' => 'cal-urgency-badge cal-urgency-badge--today',
            'badge_text' => 'Heute! 🎉',
        ];
    }
    if ($daysRemaining === 1) {
        return [
            'level' => 'tomorrow',
            'row_class' => 'cal-row-urgency cal-row--tomorrow',
            'badge_class' => 'cal-urgency-badge cal-urgency-badge--tomorrow',
            'badge_text' => 'Morgen ⏰',
        ];
    }
    if ($daysRemaining <= 7) {
        return [
            'level' => 'week',
            'row_class' => 'cal-row-urgency cal-row--week',
            'badge_class' => 'cal-urgency-badge cal-urgency-badge--week',
            'badge_text' => "In {$daysRemaining} Tagen",
        ];
    }
    if ($daysRemaining <= 14) {
        return [
            'level' => 'fortnight',
            'row_class' => 'cal-row-urgency cal-row--fortnight',
            'badge_class' => 'cal-urgency-badge cal-urgency-badge--fortnight',
            'badge_text' => "In {$daysRemaining} Tagen",
        ];
    }
    $text = $daysRemaining > 60 ? ('In ~' . round($daysRemaining / 30.4) . ' Mon.') : "In {$daysRemaining} Tagen";
    return [
        'level' => 'later',
        'row_class' => 'cal-row-urgency cal-row--later',
        'badge_class' => 'cal-urgency-badge cal-urgency-badge--later',
        'badge_text' => $text,
    ];
}

$csrfToken = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Geburtstage &amp; Jahrestage - Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>_<?= filemtime(__DIR__ . '/../css/style.css') ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <h1>🎉 Geburtstage &amp; Jahrestage</h1>
        <div class="page-header-actions">
            <a href="../index.php" class="btn btn-outline">&larr; Übersicht</a>
            <?php if ($canWrite): ?>
                <button type="button" class="btn btn-primary" id="btn-add-event">➕ Neues Ereignis</button>
            <?php endif; ?>
        </div>
    </header>

    <main>
        <!-- Filter & Suchleiste -->
        <section class="card cal-filter-bar">
            <div class="cal-filter-group">
                <a href="<?= buildCalUrl(1, 'mine', $filterType, $filterCategory, $filterSearch) ?>"
                   class="btn btn-sm <?= $filterScope === 'mine' ? 'btn-primary' : 'btn-outline' ?>"
                   title="Nur Ereignisse anzeigen, für die eine Benachrichtigung für mich konfiguriert ist">
                    🔔 Meine Termine
                </a>
                <a href="<?= buildCalUrl(1, 'all', $filterType, $filterCategory, $filterSearch) ?>"
                   class="btn btn-sm <?= $filterScope === 'all' ? 'btn-primary' : 'btn-outline' ?>"
                   title="Alle Kontakte und Ereignisse anzeigen">
                    🌐 Alle anzeigen
                </a>
            </div>

            <form method="GET" action="index.php" class="cal-filter-group" id="filter-form">
                <input type="hidden" name="scope" value="<?= htmlspecialchars($filterScope, ENT_QUOTES, 'UTF-8') ?>">

                <select name="type" class="form-control form-control-sm" id="filter-type">
                    <option value="">Alle Typen</option>
                    <option value="birthday" <?= $filterType === 'birthday' ? 'selected' : '' ?>>🎂 Geburtstage</option>
                    <option value="anniversary" <?= $filterType === 'anniversary' ? 'selected' : '' ?>>💍 Jahrestage</option>
                    <option value="memorial" <?= $filterType === 'memorial' ? 'selected' : '' ?>>🕯️ Gedenktage</option>
                    <option value="other" <?= $filterType === 'other' ? 'selected' : '' ?>>📅 Sonstige</option>
                </select>

                <select name="category" class="form-control form-control-sm" id="filter-category">
                    <option value="">Alle Kategorien</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="text" name="q" class="form-control form-control-sm cal-search-input"
                       placeholder="Suchen nach Name..." value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>" id="filter-search">

                <button type="submit" class="btn btn-sm btn-outline">Filtern</button>
                <?php if ($filterType || $filterCategory || $filterSearch): ?>
                    <a href="index.php?scope=<?= htmlspecialchars($filterScope, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-text">Zurücksetzen</a>
                <?php endif; ?>
            </form>
        </section>

        <!-- Hauptsektion: Paginierte Tabelle aller Ereignisse (chronologisch sortiert, nächste zuerst) -->
        <section class="card">
            <div class="cal-table-header-wrap">
                <div>
                    <h2 style="margin: 0; font-size: 1.25rem;">Anstehende Ereignisse</h2>
                    <span class="u-muted" style="font-size: 0.85rem;">
                        <?= $totalEvents ?> Ereignis(se) &bull; <?= $filterScope === 'mine' ? 'Nur für mich (mit Benachrichtigung)' : 'Alle Kontakte' ?> &bull; Nächste Termine zuerst
                    </span>
                </div>
                <!-- Dringlichkeits-Legende -->
                <div class="cal-urgency-legend">
                    <span class="cal-urgency-legend-item"><span class="cal-legend-dot cal-legend-dot--today"></span> Heute</span>
                    <span class="cal-urgency-legend-item"><span class="cal-legend-dot cal-legend-dot--tomorrow"></span> Morgen</span>
                    <span class="cal-urgency-legend-item"><span class="cal-legend-dot cal-legend-dot--week"></span> Nächste 7 Tage</span>
                    <span class="cal-urgency-legend-item"><span class="cal-legend-dot cal-legend-dot--fortnight"></span> Nächste 14 Tage</span>
                </div>
            </div>

            <?php if (empty($events)): ?>
                <div class="no-data u-mt-md text-center text-muted" style="padding: 2.5rem 1rem;">
                    <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📅</div>
                    <?php if ($filterScope === 'mine'): ?>
                        <p style="margin: 0; font-size: 1.05rem;">Für dich sind aktuell keine Benachrichtigungen für Ereignisse hinterlegt.</p>
                        <div style="margin-top: 1rem; display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                            <a href="<?= buildCalUrl(1, 'all', $filterType, $filterCategory, $filterSearch) ?>" class="btn btn-primary btn-sm">🌐 Alle anzeigen</a>
                        </div>
                    <?php else: ?>
                        <p style="margin: 0; font-size: 1.05rem;">Keine passenden Geburtstage oder Jahrestage gefunden.</p>
                        <?php if ($filterType || $filterCategory || $filterSearch): ?>
                            <div style="margin-top: 1rem;">
                                <a href="index.php?scope=all" class="btn btn-outline btn-sm">Filter zurücksetzen</a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive u-mt-md">
                    <table class="data-table cal-table">
                        <thead>
                            <tr>
                                <th style="width: 140px;">Fälligkeit</th>
                                <th style="width: 140px;">Datum &amp; Wochentag</th>
                                <th>Name / Anlass</th>
                                <th style="width: 160px;">Alter / Jubiläum</th>
                                <th style="width: 200px;">Sternzeichen / Jubiläum</th>
                                <th style="width: 95px; text-align: right;">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $ev): ?>
                                <?php
                                $urgency = getUrgencyMeta((int)$ev['days_remaining']);
                                $typeIcon = $typeIcons[$ev['event_type']] ?? '📅';
                                ?>
                                <tr class="<?= $urgency['row_class'] ?>" data-event-id="<?= (int)$ev['id'] ?>">
                                    <!-- 1. Dringlichkeit / Fälligkeit -->
                                    <td>
                                        <span class="<?= $urgency['badge_class'] ?>">
                                            <?= htmlspecialchars($urgency['badge_text'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>

                                    <!-- 2. Datum & Wochentag -->
                                    <td>
                                        <div class="cal-table-date">
                                            <?= sprintf('%02d.%02d.', (int)$ev['event_day'], (int)$ev['event_month']) ?>
                                        </div>
                                        <div class="cal-table-subdate <?= !empty($ev['is_weekend']) ? 'cal-subdate--weekend' : '' ?>">
                                            <?= htmlspecialchars($ev['next_weekday_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>, <?= date('Y', strtotime($ev['next_date'])) ?>
                                        </div>
                                    </td>

                                    <!-- 3. Name & Anlass -->
                                    <td>
                                        <div class="cal-table-name-cell">
                                            <div class="cal-table-title-row">
                                                <span class="cal-table-type-icon"><?= $typeIcon ?></span>
                                                <strong class="cal-table-title"><?= htmlspecialchars($ev['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            </div>
                                            <div class="cal-table-cat-row">
                                                <span class="badge cal-table-category"><?= htmlspecialchars($ev['category'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <?php if (!empty($ev['notes'])): ?>
                                                <div class="cal-table-notes" title="<?= htmlspecialchars($ev['notes'], ENT_QUOTES, 'UTF-8') ?>">
                                                    💡 <?= htmlspecialchars(mb_strimwidth($ev['notes'], 0, 75, '...'), ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- 4. Alter / Jubiläum -->
                                    <td>
                                        <?php if (!empty($ev['age_text'])): ?>
                                            <strong class="cal-table-age"><?= htmlspecialchars($ev['age_text'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?php if (!empty($ev['event_year'])): ?>
                                                <div class="cal-table-subdate">
                                                    *<?= (int)$ev['event_year'] ?><?php if (!empty($ev['birth_weekday_name'])): ?> (<?= htmlspecialchars($ev['birth_weekday_name'], ENT_QUOTES, 'UTF-8') ?>)<?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="u-muted">–</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 5. Sternzeichen (Geburtstag) ODER Hochzeitsjubiläum (Jahrestag) -->
                                    <td>
                                        <?php if ($ev['event_type'] === 'anniversary'): ?>
                                            <?php
                                            $weddingInfo = $ev['wedding_anniversary'] ?? null;
                                            $badgeText = $weddingInfo['badge_label'] ?? '💍 Jahrestag';
                                            ?>
                                            <button type="button" class="cal-zodiac-badge cal-wedding-badge js-view-details" data-id="<?= (int)$ev['id'] ?>" title="Hochzeitsjubiläum &amp; Bräuche anzeigen">
                                                <span><?= htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8') ?></span>
                                            </button>
                                        <?php elseif ($ev['event_type'] === 'memorial'): ?>
                                            <button type="button" class="cal-zodiac-badge js-view-details" data-id="<?= (int)$ev['id'] ?>" title="Gedenktag-Details anzeigen">
                                                <span>🕯️ Gedenktag</span>
                                            </button>
                                        <?php elseif ($ev['event_type'] === 'other'): ?>
                                            <button type="button" class="cal-zodiac-badge js-view-details" data-id="<?= (int)$ev['id'] ?>" title="Details anzeigen">
                                                <span>📅 Ereignis</span>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="cal-zodiac-badge js-view-details" data-id="<?= (int)$ev['id'] ?>" title="Horoskop &amp; Details anzeigen">
                                                <span><?= $ev['western_zodiac']['symbol'] ?? '✨' ?> <?= htmlspecialchars($ev['western_zodiac']['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php if (!empty($ev['chinese_zodiac'])): ?>
                                                    <span class="u-muted" style="margin: 0 2px;">•</span>
                                                    <span><?= $ev['chinese_zodiac']['symbol'] ?> <?= htmlspecialchars($ev['chinese_zodiac']['animal'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 6. Aktionen -->
                                    <td style="text-align: right; white-space: nowrap;">
                                        <?php if ($canWrite): ?>
                                            <button type="button" class="btn btn-sm btn-outline js-edit-event" data-id="<?= (int)$ev['id'] ?>" title="Bearbeiten">
                                                ✏️
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger js-delete-event" data-id="<?= (int)$ev['id'] ?>" title="Löschen">
                                                🗑️
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

                <!-- Paginierung -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination u-mt-lg">
                        <?php if ($page > 1): ?>
                            <a href="<?= buildCalUrl($page - 1, $filterScope, $filterType, $filterCategory, $filterSearch) ?>" class="btn btn-outline">&larr; Zurück</a>
                        <?php else: ?>
                            <span class="btn btn-outline" style="opacity: 0.4; cursor: not-allowed;">&larr; Zurück</span>
                        <?php endif; ?>

                        <span class="page-info">Seite <?= $page ?> von <?= $totalPages ?> (<?= $totalEvents ?> Ereignisse)</span>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= buildCalUrl($page + 1, $filterScope, $filterType, $filterCategory, $filterSearch) ?>" class="btn btn-outline">Weiter &rarr;</a>
                        <?php else: ?>
                            <span class="btn btn-outline" style="opacity: 0.4; cursor: not-allowed;">Weiter &rarr;</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
        </section>
    </main>
</div>

<!-- Modal: Ereignis anlegen / bearbeiten -->
<div class="modal-overlay hidden" id="modal-event" role="dialog" aria-modal="true" aria-labelledby="modal-event-title">
    <div class="modal-card modal-card--lg">
        <div class="modal-header">
            <h3 id="modal-event-title">🎉 Ereignis speichern</h3>
            <button type="button" class="modal-close" id="modal-event-close" aria-label="Schließen">&times;</button>
        </div>
        <form id="form-event">
            <div class="modal-body">
                <input type="hidden" name="id" id="event-id" value="">

                <div class="form-group">
                    <label for="event-title">Name / Anlass *</label>
                    <input type="text" id="event-title" name="title" class="form-control" required placeholder="z. B. Oma Erna, Hochzeitstag, Papa">
                </div>

                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label for="event-type">Ereignis-Typ</label>
                        <select id="event-type" name="event_type" class="form-control">
                            <option value="birthday">🎂 Geburtstag</option>
                            <option value="anniversary">💍 Jahrestag / Jubiläum</option>
                            <option value="memorial">🕯️ Gedenktag</option>
                            <option value="other">📅 Sonstiges Ereignis</option>
                        </select>
                    </div>
                    <div>
                        <label for="event-category">Kategorie</label>
                        <select id="event-category" name="category" class="form-control">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>" <?= $cat === 'Familie' ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="__custom__">➕ Eigene Kategorie...</option>
                        </select>
                        <input type="text" id="event-category-custom" class="form-control hidden" placeholder="Eigene Kategorie eingeben..." style="margin-top: 6px;" maxlength="50">
                    </div>
                </div>

                <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div>
                        <label for="event-day">Tag *</label>
                        <select id="event-day" name="event_day" class="form-control" required>
                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                <option value="<?= $d ?>"><?= sprintf('%02d', $d) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label for="event-month">Monat *</label>
                        <select id="event-month" name="event_month" class="form-control" required>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>"><?= CalendarService::MONTH_NAMES_DE[$m] ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label for="event-year">Jahr (optional)</label>
                        <input type="number" id="event-year" name="event_year" class="form-control" placeholder="z. B. 1985" min="1900" max="2100">
                    </div>
                </div>

                <!-- Benachrichtigungskonfiguration -->
                <div class="cal-config-card">
                    <div class="cal-config-header">
                        <span>🔔 Benachrichtigungskonfiguration je Ereignis</span>
                        <div style="display: flex; gap: 6px;">
                            <button type="button" class="btn btn-sm btn-text" id="btn-select-all-recipients">Alle wählen</button>
                            <button type="button" class="btn btn-sm btn-text" id="btn-select-me-recipient" data-email="<?= htmlspecialchars($currentUserEmail, ENT_QUOTES, 'UTF-8') ?>">Nur mich</button>
                            <button type="button" class="btn btn-sm btn-text" id="btn-clear-recipients">Keine</button>
                        </div>
                    </div>
                    <p class="text-muted" style="font-size: 0.8rem; margin-bottom: 8px;">
                        Lege fest, wer bei diesem Ereignis benachrichtigt wird (z. B. nur Mama, nur Papa oder die Kinder):
                    </p>

                    <div class="cal-users-checkboxes" id="recipients-checkbox-container">
                        <?php foreach ($allUsers as $u): ?>
                            <label class="cal-checkbox-label">
                                <input type="checkbox" name="recipients[]" value="<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>" class="js-recipient-cb">
                                <span><strong><?= htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8') ?></strong> <small class="text-muted">(<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>)</small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div style="border-top: 1px solid var(--bg-surface-hover); margin-top: 10px; padding-top: 10px;">
                        <span style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 6px;">Vorlaufzeit (Wann erinnern?):</span>
                        <div class="cal-advance-options">
                            <?php foreach ($advanceLabels as $days => $label): ?>
                                <label class="cal-checkbox-label">
                                    <input type="checkbox" name="notify_days_advance[]" value="<?= $days ?>" class="js-advance-cb" <?= in_array($days, [0, 1, 3], true) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 12px;">
                    <label for="event-notes">Notizen &amp; Geschenkideen</label>
                    <textarea id="event-notes" name="notes" class="form-control" rows="3" placeholder="z. B. Wünscht sich Bücher, Hobbys, Restaurant-Gutschein..."></textarea>
                </div>
            </div>

            <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div>
                    <button type="button" class="btn btn-outline hidden" id="modal-event-test-push" title="Test-Push für dieses Ereignis an mich senden">
                        🔔 Test-Push an mich senden
                    </button>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="btn btn-outline" id="modal-event-cancel">Abbrechen</button>
                    <button type="submit" class="btn btn-save" id="btn-save-event">💾 Ereignis speichern</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Horoskop & Ereignis-Details -->
<div class="modal-overlay hidden" id="modal-event-details" role="dialog" aria-modal="true" aria-labelledby="modal-details-title">
    <div class="modal-card modal-card--lg">
        <div class="modal-header">
            <h3 id="modal-details-title">Ereignis-Details</h3>
            <button type="button" class="modal-close" id="modal-details-close" aria-label="Schließen">&times;</button>
        </div>
        <div class="modal-body" id="modal-details-content">
            <div id="modal-details-loading" class="text-center text-muted" style="padding: 24px;">
                <span>⏳ Details werden geladen...</span>
            </div>
            <div id="modal-details-body" class="hidden">
                <div class="card" style="margin-bottom: 12px; background: var(--bg-surface-hover);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <h4 id="det-title" style="margin: 0 0 4px 0; font-size: 1.25rem; color: var(--text-main);"></h4>
                            <div class="text-muted" id="det-subtitle" style="font-size: 0.9rem;"></div>
                            <div class="text-muted" id="det-weekday-info" style="font-size: 0.85rem; margin-top: 4px;"></div>
                        </div>
                        <div id="det-badge-wrap"></div>
                    </div>
                </div>

                <!-- Sternzeichen & Horoskop (bei Geburtstag) -->
                <div id="det-zodiac-section" class="cal-zodiac-grid hidden">
                    <!-- Westliches Sternzeichen -->
                    <div class="cal-zodiac-card">
                        <div>
                            <div class="cal-zodiac-card-header">
                                <div class="cal-zodiac-symbol-big" id="det-west-symbol">✨</div>
                                <div>
                                    <h4 class="cal-zodiac-card-title" id="det-west-name"></h4>
                                    <div class="cal-zodiac-card-sub" id="det-west-range"></div>
                                </div>
                            </div>
                            <div style="font-size: 0.85rem; margin-bottom: 6px;">
                                Element: <strong id="det-west-element"></strong>
                            </div>
                            <div class="cal-traits-list" id="det-west-traits"></div>
                            <p class="cal-zodiac-desc" id="det-west-desc"></p>
                        </div>
                    </div>

                    <!-- Chinesisches Tierkreiszeichen -->
                    <div class="cal-zodiac-card">
                        <div id="det-chinese-wrap">
                            <div class="cal-zodiac-card-header">
                                <div class="cal-zodiac-symbol-big" id="det-chinese-symbol">🐉</div>
                                <div>
                                    <h4 class="cal-zodiac-card-title" id="det-chinese-name">Holz-Drache</h4>
                                    <div class="cal-zodiac-card-sub" id="det-chinese-year">Mondjahr</div>
                                </div>
                            </div>
                            <div style="font-size: 0.85rem; margin-bottom: 6px;">
                                Element: <strong id="det-chinese-element">Holz</strong> &bull; Polarität: <strong id="det-chinese-polarity">Yang</strong>
                            </div>
                            <div class="cal-traits-list" id="det-chinese-traits"></div>
                            <p class="cal-zodiac-desc" id="det-chinese-desc"></p>
                            <div class="cal-lucky-box" id="det-chinese-lucky">
                                🍀 Glückszahlen: <span id="det-chinese-numbers"></span> &bull; Farben: <span id="det-chinese-colors"></span>
                            </div>
                        </div>
                        <div id="det-chinese-missing" class="hidden text-center text-muted" style="padding: 20px 10px;">
                            <div style="font-size: 2.2rem; margin-bottom: 8px;">🏮</div>
                            <strong>Chinesisches Tierkreiszeichen</strong>
                            <p style="font-size: 0.85rem; margin: 8px 0;">
                                Für das chinesische Tierkreiszeichen wird das Geburtsjahr benötigt.
                            </p>
                            <button type="button" class="btn btn-sm btn-outline" id="det-btn-add-year">✏️ Geburtsjahr nachtragen</button>
                        </div>
                    </div>
                </div>

                <!-- Hochzeitsjubiläum (bei Jahrestag) -->
                <div id="det-wedding-section" class="hidden">
                    <div class="cal-wedding-card">
                        <div class="cal-wedding-card-header">
                            <div class="cal-wedding-symbol-big" id="det-wedding-symbol">💍</div>
                            <div>
                                <h4 class="cal-wedding-card-title" id="det-wedding-name">Rosenhochzeit</h4>
                                <div class="cal-wedding-card-sub" id="det-wedding-years">10. Hochzeitstag</div>
                            </div>
                        </div>
                        <div class="cal-wedding-meaning-box" style="margin-top: 10px;">
                            <strong style="color: var(--accent);">Bedeutung &amp; Symbolik:</strong>
                            <p id="det-wedding-meaning" style="margin: 4px 0 0 0; font-size: 0.92rem; line-height: 1.45; color: var(--text-main);"></p>
                        </div>
                        <div class="cal-wedding-gift-box" style="margin-top: 10px;">
                            <strong style="color: #f59e0b;">🎁 Tradition &amp; Geschenkideen:</strong>
                            <p id="det-wedding-gift" style="margin: 4px 0 0 0; font-size: 0.88rem; color: var(--text-muted);"></p>
                        </div>
                        <div id="det-wedding-milestones-box" style="margin-top: 14px; border-top: 1px solid var(--bg-surface-hover); padding-top: 10px;">
                            <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px;">
                                🌟 Nächste Meilensteine:
                            </div>
                            <div id="det-wedding-milestones" class="cal-milestone-list"></div>
                        </div>
                    </div>
                </div>

                <!-- Gedenktag & Sonstiges -->
                <div id="det-memorial-section" class="hidden">
                    <div class="cal-wedding-card" style="border-left: 4px solid var(--text-muted);">
                        <div class="cal-wedding-card-header">
                            <div class="cal-wedding-symbol-big">🕯️</div>
                            <div>
                                <h4 class="cal-wedding-card-title" id="det-memorial-title">Gedenktag</h4>
                                <div class="cal-wedding-card-sub">In stillem Gedenken</div>
                            </div>
                        </div>
                        <p style="margin: 8px 0 0 0; font-size: 0.9rem; color: var(--text-muted); line-height: 1.4;">
                            Ein persönlicher Tag des Innehaltens und der Erinnerung.
                        </p>
                    </div>
                </div>

                <!-- Benachrichtigung & Notizen -->
                <div class="cal-config-card" style="margin-top: 12px;">
                    <div style="font-size: 0.85rem; font-weight: 600; margin-bottom: 6px;">
                        🔔 Benachrichtigungsempfänger:
                    </div>
                    <div class="cal-recipient-tags" id="det-recipients-wrap"></div>
                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 8px;" id="det-advance-wrap"></div>
                </div>

                <div id="det-notes-container" class="cal-notes-box hidden" style="margin-top: 12px;">
                    <strong>💡 Notizen &amp; Geschenkideen:</strong>
                    <div id="det-notes" style="margin-top: 4px;"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="det-btn-test-push">🔔 Test-Push senden</button>
            <?php if ($canWrite): ?>
                <button type="button" class="btn btn-primary" id="det-btn-edit">✏️ Bearbeiten</button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline" id="modal-details-close-btn">Schließen</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
<script src="../js/calendar.js?v=<?= APP_VERSION ?>_<?= filemtime(__DIR__ . '/../js/calendar.js') ?>" defer></script>
</body>
</html>
