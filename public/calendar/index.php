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

// Filter-Parameter auslesen
$filterScope = $_GET['scope'] ?? 'all';
$filterType = $_GET['type'] ?? '';
$filterCategory = $_GET['category'] ?? '';
$filterSearch = trim((string)($_GET['q'] ?? ''));

$scopeEmail = ($filterScope === 'mine') ? $currentUserEmail : null;

// Daten abrufen
$upcomingEvents = $calendarService->getUpcomingEvents(30, $scopeEmail);
$monthsData = $calendarService->getEventsGroupedByMonth(
    $scopeEmail,
    $filterCategory !== '' ? $filterCategory : null,
    $filterType !== '' ? $filterType : null,
    $filterSearch !== '' ? $filterSearch : null
);

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

$csrfToken = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Geburtstage &amp; Jahrestage - Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <h1>🎂 Geburtstage &amp; Jahrestage</h1>
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
                <a href="?scope=all<?= $filterType ? '&type=' . urlencode($filterType) : '' ?><?= $filterCategory ? '&category=' . urlencode($filterCategory) : '' ?><?= $filterSearch ? '&q=' . urlencode($filterSearch) : '' ?>"
                   class="btn btn-sm <?= $filterScope !== 'mine' ? 'btn-primary' : 'btn-outline' ?>">
                    Alle Kontakte
                </a>
                <a href="?scope=mine<?= $filterType ? '&type=' . urlencode($filterType) : '' ?><?= $filterCategory ? '&category=' . urlencode($filterCategory) : '' ?><?= $filterSearch ? '&q=' . urlencode($filterSearch) : '' ?>"
                   class="btn btn-sm <?= $filterScope === 'mine' ? 'btn-primary' : 'btn-outline' ?>">
                    👤 Nur für mich
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

        <!-- Sektion 1: Demnächst anstehend (nächste 30 Tage) -->
        <section>
            <div class="section-header">
                <h2>🎉 Demnächst anstehend (Nächste 30 Tage)</h2>
                <span class="text-muted"><?= count($upcomingEvents) ?> Ereignisse</span>
            </div>

            <?php if (empty($upcomingEvents)): ?>
                <div class="card text-center text-muted" style="padding: 24px;">
                    <p style="margin: 0; font-size: 1rem;">In den nächsten 30 Tagen stehen keine erfassten Geburtstage oder Jahrestage an.</p>
                </div>
            <?php else: ?>
                <div class="cal-upcoming-grid">
                    <?php foreach ($upcomingEvents as $ev): ?>
                        <?php
                        $isToday = $ev['days_remaining'] === 0;
                        $typeIcon = $typeIcons[$ev['event_type']] ?? '📅';
                        $cardClass = $isToday ? 'cal-card cal-card--today' : 'cal-card';
                        $badgeClass = $isToday ? 'cal-badge-pill cal-badge-today' : ($ev['days_remaining'] <= 3 ? 'cal-badge-pill cal-badge-soon' : 'cal-badge-pill cal-badge-later');
                        ?>
                        <div class="<?= $cardClass ?>" data-event-id="<?= (int)$ev['id'] ?>">
                            <div>
                                <div class="cal-card-header">
                                    <div class="cal-date-badge">
                                        <span class="cal-date-badge-day"><?= sprintf('%02d', (int)$ev['event_day']) ?></span>
                                        <span class="cal-date-badge-month"><?= mb_substr(CalendarService::MONTH_NAMES_DE[(int)$ev['event_month']], 0, 3, 'UTF-8') ?></span>
                                    </div>
                                    <div class="cal-card-info">
                                        <h3 class="cal-card-title">
                                            <span><?= $typeIcon ?></span>
                                            <span><?= htmlspecialchars($ev['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </h3>
                                        <div class="cal-card-sub">
                                            <span class="<?= $badgeClass ?>"><?= htmlspecialchars($ev['badge_text'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if (!empty($ev['age_text'])): ?>
                                                &bull; <strong><?= htmlspecialchars($ev['age_text'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="text-muted" style="font-size: 0.8rem; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                                    <span class="badge"><?= htmlspecialchars($ev['category'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <button type="button" class="cal-zodiac-badge js-view-details" data-id="<?= (int)$ev['id'] ?>" title="Sternzeichen &amp; Horoskop anzeigen">
                                        <?= $ev['western_zodiac']['symbol'] ?> <?= htmlspecialchars($ev['western_zodiac']['name'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($ev['chinese_zodiac'])): ?>
                                            &bull; <?= $ev['chinese_zodiac']['symbol'] ?> <?= htmlspecialchars($ev['chinese_zodiac']['animal'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </button>
                                </div>

                                <!-- Empfänger-Badges -->
                                <?php if (!empty($ev['recipients'])): ?>
                                    <div class="cal-recipient-tags">
                                        <?php foreach ($ev['recipients'] as $rec): ?>
                                            <span class="cal-recipient-tag" title="Wird benachrichtigt: <?= htmlspecialchars($rec['email'], ENT_QUOTES, 'UTF-8') ?>">
                                                👤 <?= htmlspecialchars($rec['name'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="cal-recipient-tags">
                                        <span class="cal-recipient-tag text-muted" style="opacity: 0.7;">Keine Benachrichtigung</span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($ev['notes'])): ?>
                                    <div class="cal-notes-box">
                                        💡 <?= nl2br(htmlspecialchars($ev['notes'], ENT_QUOTES, 'UTF-8')) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="cal-card-actions">
                                <button type="button" class="btn btn-sm btn-outline js-view-details" data-id="<?= (int)$ev['id'] ?>" title="Sternzeichen &amp; Horoskop-Details">
                                    ✨ Horoskop
                                </button>
                                <button type="button" class="btn btn-sm btn-outline js-test-push" data-id="<?= (int)$ev['id'] ?>" title="Test-Push an mich senden">
                                    🔔
                                </button>
                                <?php if ($canWrite): ?>
                                    <button type="button" class="btn btn-sm btn-outline js-edit-event" data-id="<?= (int)$ev['id'] ?>" title="Bearbeiten">
                                        ✏️
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger js-delete-event" data-id="<?= (int)$ev['id'] ?>" title="Löschen">
                                        🗑️
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Sektion 2: Jahreskalender (12 Monate) -->
        <section>
            <div class="section-header">
                <h2>📅 Jahreskalender (Januar – Dezember)</h2>
            </div>

            <?php
            $hasAnyMonthEvents = false;
            foreach ($monthsData as $m) {
                if (!empty($m['events'])) {
                    $hasAnyMonthEvents = true;
                    break;
                }
            }
            ?>

            <?php if (!$hasAnyMonthEvents): ?>
                <div class="card text-center text-muted" style="padding: 24px;">
                    <p style="margin: 0;">Keine Einträge für die aktuellen Filter gefunden.</p>
                </div>
            <?php else: ?>
                <?php foreach ($monthsData as $mNum => $mData): ?>
                    <?php if (empty($mData['events'])) continue; ?>
                    <div class="cal-month-card">
                        <div class="cal-month-header">
                            <h3 class="cal-month-title">
                                <span>🗓️ <?= htmlspecialchars($mData['month_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="cal-month-count">(<?= count($mData['events']) ?>)</span>
                            </h3>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table stack-table">
                                <thead>
                                <tr>
                                    <th style="width: 80px;">Tag</th>
                                    <th>Typ &amp; Name</th>
                                    <th>Alter / Jubiläum</th>
                                    <th>Kategorie</th>
                                    <th>Benachrichtigung an</th>
                                    <th style="width: 140px; text-align: right;">Aktionen</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($mData['events'] as $ev): ?>
                                    <?php $typeIcon = $typeIcons[$ev['event_type']] ?? '📅'; ?>
                                    <tr data-event-id="<?= (int)$ev['id'] ?>">
                                        <td data-label="Tag">
                                            <strong><?= sprintf('%02d.%02d.', (int)$ev['event_day'], (int)$ev['event_month']) ?></strong>
                                        </td>
                                        <td data-label="Typ &amp; Name">
                                            <span><?= $typeIcon ?></span>
                                            <strong><?= htmlspecialchars($ev['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <button type="button" class="cal-zodiac-badge js-view-details" data-id="<?= (int)$ev['id'] ?>" style="margin-left: 6px;" title="Sternzeichen &amp; Horoskop anzeigen">
                                                <?= $ev['western_zodiac']['symbol'] ?> <?= htmlspecialchars($ev['western_zodiac']['name'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($ev['chinese_zodiac'])): ?>
                                                    &bull; <?= $ev['chinese_zodiac']['symbol'] ?> <?= htmlspecialchars($ev['chinese_zodiac']['animal'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                            </button>
                                            <?php if (!empty($ev['notes'])): ?>
                                                <br><small class="text-muted">💡 <?= htmlspecialchars($ev['notes'], ENT_QUOTES, 'UTF-8') ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Alter / Jubiläum">
                                            <?php if (!empty($ev['age_text'])): ?>
                                                <?= htmlspecialchars($ev['age_text'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($ev['event_year'])): ?>
                                                    <small class="text-muted">(*<?= (int)$ev['event_year'] ?>)</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Kategorie">
                                            <span class="badge"><?= htmlspecialchars($ev['category'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </td>
                                        <td data-label="Benachrichtigung an">
                                            <?php if (!empty($ev['recipients'])): ?>
                                                <div class="cal-recipient-tags">
                                                    <?php foreach ($ev['recipients'] as $rec): ?>
                                                        <span class="cal-recipient-tag">👤 <?= htmlspecialchars($rec['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size: 0.8rem;">Keine</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Aktionen" style="text-align: right;">
                                            <button type="button" class="btn btn-sm btn-outline js-view-details" data-id="<?= (int)$ev['id'] ?>" title="Sternzeichen &amp; Horoskop-Details">
                                                ✨
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline js-test-push" data-id="<?= (int)$ev['id'] ?>" title="Test-Push an mich senden">
                                                🔔
                                            </button>
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
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</div>

<!-- Modal: Ereignis anlegen / bearbeiten -->
<div class="modal-overlay hidden" id="modal-event" role="dialog" aria-modal="true" aria-labelledby="modal-event-title">
    <div class="modal-card modal-card--lg">
        <div class="modal-header">
            <h3 id="modal-event-title">🎂 Ereignis speichern</h3>
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
                        <input type="text" id="event-category" name="category" class="form-control" list="category-datalist" value="Familie" placeholder="Familie, Freunde...">
                        <datalist id="category-datalist">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>">
                            <?php endforeach; ?>
                        </datalist>
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
                        Legen Sie fest, wer bei diesem Ereignis benachrichtigt wird (z. B. nur Mama, nur Papa oder die Kinder):
                    </p>

                    <div class="cal-users-checkboxes" id="recipients-checkbox-container">
                        <?php foreach ($allUsers as $u): ?>
                            <label class="cal-checkbox-label">
                                <input type="checkbox" name="recipients[]" value="<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>" class="js-recipient-cb">
                                <span><strong><?= htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8') ?></strong> <small class="text-muted">(<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>)</small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div style="border-top: 1px solid var(--border-color); margin-top: 10px; padding-top: 10px;">
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

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="modal-event-cancel">Abbrechen</button>
                <button type="submit" class="btn btn-save" id="btn-save-event">💾 Ereignis speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Horoskop & Ereignis-Details -->
<div class="modal-overlay hidden" id="modal-event-details" role="dialog" aria-modal="true" aria-labelledby="modal-details-title">
    <div class="modal-card modal-card--lg">
        <div class="modal-header">
            <h3 id="modal-details-title">🌟 Horoskop &amp; Details</h3>
            <button type="button" class="modal-close" id="modal-details-close" aria-label="Schließen">&times;</button>
        </div>
        <div class="modal-body" id="modal-details-content">
            <div id="modal-details-loading" class="text-center text-muted" style="padding: 24px;">
                <span>⏳ Details werden geladen...</span>
            </div>
            <div id="modal-details-body" class="hidden">
                <div class="card" style="margin-bottom: 12px; background: var(--bg-hover);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <h4 id="det-title" style="margin: 0 0 4px 0; font-size: 1.25rem;"></h4>
                            <div class="text-muted" id="det-subtitle" style="font-size: 0.9rem;"></div>
                        </div>
                        <div id="det-badge-wrap"></div>
                    </div>
                </div>

                <div class="cal-zodiac-grid">
                    <!-- Westliches Sternzeichen -->
                    <div class="cal-zodiac-card">
                        <div>
                            <div class="cal-zodiac-card-header">
                                <div class="cal-zodiac-symbol-big" id="det-west-symbol">♈</div>
                                <div>
                                    <h4 class="cal-zodiac-card-title" id="det-west-name">Widder</h4>
                                    <div class="cal-zodiac-card-sub" id="det-west-range">21.03. – 20.04.</div>
                                </div>
                            </div>
                            <div style="font-size: 0.85rem; margin-bottom: 6px;">
                                Element: <strong id="det-west-element">Feuer</strong>
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
<script src="../js/http.js" defer></script>
<script src="../js/calendar.js" defer></script>
</body>
</html>
