<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\Transit\InsaClient;
use Kai\Tools\Transit\TransitProfileRepository;
use Kai\Tools\Transit\TransitService;

// 1. Auth-Check: Lesezugriff erforderlich
Auth::requirePage('transit_read');

$canWrite = Auth::hasPermission('transit_write');
$currentUserEmail = strtolower(trim((string)($_SESSION['user_email'] ?? '')));

$profileRepo = new TransitProfileRepository();
$transitService = new TransitService(null, $profileRepo);

$profiles = $profileRepo->getAll($currentUserEmail);
$activeProfiles = array_values(array_filter($profiles, static fn($p) => !empty($p['is_active'])));

$selectedProfileId = filter_var($_GET['profile'] ?? null, FILTER_VALIDATE_INT);
$selectedProfile = null;

if ($selectedProfileId) {
    foreach ($profiles as $p) {
        if ($p['id'] === $selectedProfileId) {
            $selectedProfile = $p;
            break;
        }
    }
}

if (!$selectedProfile && !empty($activeProfiles)) {
    $selectedProfile = $activeProfiles[0];
}

$directionOverride = isset($_GET['dir']) && in_array($_GET['dir'], ['outbound', 'return'], true) ? $_GET['dir'] : null;
$offsetMinutes = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
if ($offsetMinutes === false) {
    $offsetMinutes = 0;
}

$status = null;
if ($selectedProfile) {
    $status = $transitService->getProfileStatus($selectedProfile, $directionOverride, $offsetMinutes);
}

$csrfToken = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>ÖPNV &amp; Pendler-Monitor - Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <h1>🚋 ÖPNV &amp; Pendler-Monitor</h1>
        <div class="page-header-actions">
            <a href="../index.php" class="btn btn-outline">&larr; Übersicht</a>
            <button type="button" class="btn btn-outline js-refresh-transit" title="Live-Zeiten jetzt aktualisieren">🔄 Aktualisieren</button>
            <?php if ($canWrite): ?>
                <button type="button" class="btn btn-primary js-add-profile">➕ Neues Profil</button>
            <?php endif; ?>
        </div>
    </header>

    <main>
        <!-- Profil-Reiter / Tabs -->
        <div class="transit-tab-bar">
            <?php foreach ($profiles as $p): ?>
                <?php $isActiveTab = ($selectedProfile && $selectedProfile['id'] === $p['id']); ?>
                <a href="index.php?profile=<?= $p['id'] ?>" 
                   class="transit-tab-btn <?= $isActiveTab ? 'active' : '' ?> <?= empty($p['is_active']) ? 'transit-tab-disabled' : '' ?>">
                    <span><?= htmlspecialchars($p['profile_name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (!empty($p['only_school_days'])): ?>
                        <span class="transit-tab-badge" title="Nur an Schultagen aktiv">🎒</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
            <button type="button" class="transit-tab-btn js-open-search-tab">
                <span>🔍 Haltestellensuche</span>
            </button>
        </div>

        <?php if ($selectedProfile && $status): ?>
            <!-- Haupt-Monitor für das ausgewählte Profil -->
            <section class="card transit-profile-card" data-profile-id="<?= (int)$selectedProfile['id'] ?>" data-direction="<?= htmlspecialchars($status['direction'], ENT_QUOTES, 'UTF-8') ?>" data-offset="<?= (int)$offsetMinutes ?>">
                <div class="transit-card-header">
                    <div class="transit-route-info">
                        <div class="transit-direction-tag"><?= htmlspecialchars($status['direction_label'], ENT_QUOTES, 'UTF-8') ?></div>
                        <h2 class="transit-stations">
                            <span class="transit-station-from"><?= htmlspecialchars($status['from_station'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="transit-arrow">&rarr;</span>
                            <span class="transit-station-to"><?= htmlspecialchars($status['to_station'], ENT_QUOTES, 'UTF-8') ?></span>
                        </h2>
                    </div>

                    <div class="transit-header-actions">
                        <button type="button" class="btn btn-outline btn-sm js-swap-direction" title="Richtung zwischen Hin- und Rückfahrt umkehren">
                            ⇄ Richtung tauschen
                        </button>
                        <?php if ($canWrite): ?>
                            <button type="button" class="btn btn-outline btn-sm js-edit-profile" data-profile='<?= htmlspecialchars(json_encode($selectedProfile), ENT_QUOTES, 'UTF-8') ?>' title="Profil bearbeiten">
                                ✏️ Bearbeiten
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statusleiste & Schnellverschiebung -->
                <div class="transit-control-strip">
                    <div class="transit-status-indicator">
                        <span class="transit-status-pill transit-status--<?= htmlspecialchars($status['status_severity'], ENT_QUOTES, 'UTF-8') ?>">
                            <span class="transit-status-dot"></span>
                            <?= htmlspecialchars($status['status_text'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php if ($status['is_holiday_or_vacation']): ?>
                            <span class="badge badge-warning" title="Schulprofil pausiert heute">🏖️ <?= htmlspecialchars($status['holiday_name'] ?? 'Schulfrei', ENT_QUOTES, 'UTF-8') ?></span>
                        <?php elseif (!$status['is_scheduled_day']): ?>
                            <span class="badge badge-outline" title="Heute laut Wochentag-Plan kein regulärer Pendlertag">📅 Nicht im Wochenplan</span>
                        <?php endif; ?>
                    </div>

                    <!-- Zeit-Schnellverschiebung -->
                    <div class="transit-time-offsets">
                        <span class="text-muted" style="font-size: 0.85rem; margin-right: 0.25rem;">Abfahrt:</span>
                        <a href="index.php?profile=<?= $selectedProfile['id'] ?>&dir=<?= $status['direction'] ?>&offset=0" class="btn btn-sm <?= $offsetMinutes === 0 ? 'btn-primary' : 'btn-outline' ?>">Jetzt</a>
                        <a href="index.php?profile=<?= $selectedProfile['id'] ?>&dir=<?= $status['direction'] ?>&offset=15" class="btn btn-sm <?= $offsetMinutes === 15 ? 'btn-primary' : 'btn-outline' ?>">+15m</a>
                        <a href="index.php?profile=<?= $selectedProfile['id'] ?>&dir=<?= $status['direction'] ?>&offset=30" class="btn btn-sm <?= $offsetMinutes === 30 ? 'btn-primary' : 'btn-outline' ?>">+30m</a>
                        <a href="index.php?profile=<?= $selectedProfile['id'] ?>&dir=<?= $status['direction'] ?>&offset=45" class="btn btn-sm <?= $offsetMinutes === 45 ? 'btn-primary' : 'btn-outline' ?>">+45m</a>
                        <a href="index.php?profile=<?= $selectedProfile['id'] ?>&dir=<?= $status['direction'] ?>&offset=60" class="btn btn-sm <?= $offsetMinutes === 60 ? 'btn-primary' : 'btn-outline' ?>">+1h</a>
                    </div>
                </div>

                <!-- Hero-Abfahrt: Nächste Bahn prominent -->
                <?php $nextDep = $status['next_departure'] ?? null; ?>
                <?php if ($nextDep): ?>
                    <?php $heroBadge = $nextDep['badge'] ?? InsaClient::getLvbBadgeInfo($nextDep['line'], $nextDep['mode']); ?>
                    <div class="transit-hero-departure">
                        <div class="transit-hero-left">
                            <div class="transit-line-badge <?= htmlspecialchars($heroBadge['css_class'], ENT_QUOTES, 'UTF-8') ?>" style="background-color: <?= htmlspecialchars($heroBadge['bg'], ENT_QUOTES, 'UTF-8') ?>; color: <?= htmlspecialchars($heroBadge['text_color'], ENT_QUOTES, 'UTF-8') ?>;">
                                <?= htmlspecialchars($nextDep['line'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="transit-hero-details">
                                <div class="transit-hero-direction">Richtung <?= htmlspecialchars($nextDep['direction'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="transit-hero-times">
                                    <span class="transit-hero-real"><?= htmlspecialchars($nextDep['real_time'], ENT_QUOTES, 'UTF-8') ?> Uhr</span>
                                    <?php if ($nextDep['delay_minutes'] > 0): ?>
                                        <span class="transit-hero-delay text-danger">+<?= (int)$nextDep['delay_minutes'] ?> Min</span>
                                        <span class="transit-hero-sched text-muted">(Plan <?= htmlspecialchars($nextDep['scheduled_time'], ENT_QUOTES, 'UTF-8') ?>)</span>
                                    <?php elseif ($nextDep['has_realtime']): ?>
                                        <span class="transit-hero-delay text-success">pünktlich</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="transit-hero-right">
                            <div class="transit-countdown-box">
                                <?php if ($nextDep['countdown_minutes'] !== null && $nextDep['countdown_minutes'] >= 0): ?>
                                    <span class="transit-countdown-val"><?= (int)$nextDep['countdown_minutes'] ?></span>
                                    <span class="transit-countdown-unit">Min</span>
                                <?php else: ?>
                                    <span class="transit-countdown-val">Sofort</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="transit-hero-empty text-muted">
                        Keine unmittelbaren Abfahrten für die konfigurierten Filterkriterien gefunden.
                    </div>
                <?php endif; ?>

                <!-- Umstiegs- & Anschlussanzeige (falls via-Station vorhanden) -->
                <?php $transfer = $status['transfer_status'] ?? null; ?>
                <?php if ($transfer): ?>
                    <div class="transit-transfer-card transit-transfer--<?= htmlspecialchars($transfer['severity'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="transit-transfer-icon">🔄</div>
                        <div class="transit-transfer-body">
                            <div class="transit-transfer-title">
                                <strong>Umstieg an <?= htmlspecialchars($transfer['via_station'], ENT_QUOTES, 'UTF-8') ?>:</strong>
                                <span><?= htmlspecialchars($transfer['message'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="transit-transfer-sub text-muted">
                                Anschluss an <span class="transit-line-pill <?= htmlspecialchars($transfer['connecting_badge']['css_class'] ?? 'transit-shape-square transit-lvb-blue', ENT_QUOTES, 'UTF-8') ?>" style="background-color: <?= htmlspecialchars($transfer['connecting_badge']['bg'] ?? '#003882', ENT_QUOTES, 'UTF-8') ?>; color: <?= htmlspecialchars($transfer['connecting_badge']['text_color'] ?? '#ffffff', ENT_QUOTES, 'UTF-8') ?>;"><?= htmlspecialchars($transfer['connecting_line'], ENT_QUOTES, 'UTF-8') ?></span> Richtung <?= htmlspecialchars($transfer['connecting_direction'], ENT_QUOTES, 'UTF-8') ?> um <?= htmlspecialchars($transfer['connecting_time'], ENT_QUOTES, 'UTF-8') ?> Uhr
                                <?php if ($transfer['connecting_delay'] > 0): ?>
                                    <span class="text-danger">(+<?= (int)$transfer['connecting_delay'] ?> Min)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Abfahrtstabelle mit den nächsten Bahnen -->
                <div class="transit-table-wrap">
                    <h3 class="transit-table-title">Weitere Abfahrten</h3>
                    <div class="table-responsive">
                        <table class="data-table transit-departures-table">
                            <thead>
                                <tr>
                                    <th>Linie</th>
                                    <th>Richtung</th>
                                    <th>Plan</th>
                                    <th>Echtzeit</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">In</th>
                                </tr>
                            </thead>
                            <tbody class="js-departures-tbody">
                                <?php foreach ($status['departures'] as $dep): ?>
                                    <?php $b = $dep['badge'] ?? InsaClient::getLvbBadgeInfo($dep['line'], $dep['mode']); ?>
                                    <tr class="<?= $dep['is_cancelled'] ? 'transit-row-cancelled' : '' ?>">
                                        <td data-label="Linie">
                                            <span class="transit-line-pill <?= htmlspecialchars($b['css_class'], ENT_QUOTES, 'UTF-8') ?>" style="background-color: <?= htmlspecialchars($b['bg'], ENT_QUOTES, 'UTF-8') ?>; color: <?= htmlspecialchars($b['text_color'], ENT_QUOTES, 'UTF-8') ?>;">
                                                <?= htmlspecialchars($dep['line'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td data-label="Richtung" class="transit-col-dir">
                                            <?= htmlspecialchars($dep['direction'], ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td data-label="Plan" class="text-muted">
                                            <?= htmlspecialchars($dep['scheduled_time'], ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td data-label="Echtzeit">
                                            <strong><?= htmlspecialchars($dep['real_time'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        </td>
                                        <td data-label="Status">
                                            <?php if ($dep['is_cancelled']): ?>
                                                <span class="badge badge-danger">Ausfall</span>
                                            <?php elseif ($dep['delay_minutes'] > 0): ?>
                                                <span class="text-danger">+<?= (int)$dep['delay_minutes'] ?> Min</span>
                                            <?php elseif ($dep['has_realtime']): ?>
                                                <span class="text-success">Pünktlich</span>
                                            <?php else: ?>
                                                <span class="text-muted">Fahrplan</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="In" style="text-align: right;">
                                            <?php if ($dep['countdown_minutes'] !== null && $dep['countdown_minutes'] >= 0): ?>
                                                <strong><?= (int)$dep['countdown_minutes'] ?> Min</strong>
                                            <?php else: ?>
                                                –
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- Freie Haltestellensuche (Modal oder Section) -->
        <section class="card transit-search-section hidden js-search-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2>🔍 Freie Haltestellen-Abfrage</h2>
                <button type="button" class="btn btn-outline btn-sm js-close-search">✕ Schließen</button>
            </div>
            
            <p class="text-muted">Wähle eine Schnellstation oder suche nach einer beliebigen Haltestelle in Leipzig und dem MDV-Gebiet:</p>

            <div class="transit-quick-chips">
                <button type="button" class="btn btn-sm btn-outline js-quick-station" data-id="11559" data-name="Leipzig, Meusdorf">📍 Meusdorf</button>
                <button type="button" class="btn btn-sm btn-outline js-quick-station" data-id="13002" data-name="Leipzig, Augustusplatz">📍 Augustusplatz</button>
                <button type="button" class="btn btn-sm btn-outline js-quick-station" data-id="12992" data-name="Leipzig, Wilhelm-Leuschner-Platz (Tram/Bus)">📍 Wilhelm-Leuschner-Platz</button>
                <button type="button" class="btn btn-sm btn-outline js-quick-station" data-id="90014" data-name="Leipzig, Hbf">📍 Hauptbahnhof</button>
                <button type="button" class="btn btn-sm btn-outline js-quick-station" data-id="11029" data-name="Leipzig, Connewitz Kreuz">📍 Connewitz Kreuz</button>
            </div>

            <div class="transit-search-box">
                <input type="text" id="station-search-input" class="yield-input" placeholder="Haltestelle suchen (z. B. Thomaskirche, Paunsdorf...)" autocomplete="off">
                <div id="station-search-results" class="transit-search-dropdown hidden"></div>
            </div>

            <div id="free-board-container" class="hidden" style="margin-top: 1.5rem;">
                <h3 id="free-board-title" style="margin-bottom: 0.75rem;"></h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Linie</th>
                                <th>Richtung</th>
                                <th>Plan</th>
                                <th>Echtzeit</th>
                                <th>Status</th>
                                <th style="text-align: right;">In</th>
                            </tr>
                        </thead>
                        <tbody id="free-board-tbody"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Profil Anlegen / Bearbeiten Dialog -->
        <?php if ($canWrite): ?>
            <div id="profile-modal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="profile-modal-title">
                <form id="profile-form" class="modal-card modal-card--lg">
                    <div class="modal-header">
                        <h3 id="profile-modal-title">Pendlerprofil konfigurieren</h3>
                        <button type="button" class="modal-close js-close-profile-modal" aria-label="Schließen">✕</button>
                    </div>

                    <input type="hidden" id="p_id" name="id" value="">

                    <div class="modal-body">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                            <div>
                                <label for="p_name" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Profilbezeichnung:</label>
                                <input type="text" id="p_name" name="profile_name" class="yield-input" style="width: 100%;" placeholder="z. B. Büro (Daniel)" required>
                            </div>
                            <div>
                                <label for="p_lines" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Bevorzugte Linien (optional):</label>
                                <input type="text" id="p_lines" name="preferred_lines" class="yield-input" style="width: 100%;" placeholder="z. B. 15 oder 15,2">
                            </div>
                            <div>
                                <label for="p_modes" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Verkehrsmittel:</label>
                                <select id="p_modes" name="transport_modes" class="yield-input" style="width: 100%;">
                                    <option value="all">Alle (Tram, Bus, S-Bahn)</option>
                                    <option value="tram">Nur Straßenbahn (Tram)</option>
                                    <option value="bus">Nur Bus</option>
                                </select>
                            </div>
                            <div>
                                <label for="p_transfer_min" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Min. Umstiegszeit (Min):</label>
                                <input type="number" id="p_transfer_min" name="min_transfer_minutes" value="4" min="1" max="30" class="yield-input" style="width: 100%;">
                            </div>
                        </div>

                        <!-- Haltestellen -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-top: 1rem;">
                            <div>
                                <label for="p_origin_name" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Start-Haltestelle:</label>
                                <input type="text" id="p_origin_name" name="origin_station_name" class="yield-input js-station-autocomplete" style="width: 100%;" placeholder="z. B. Leipzig, Meusdorf" required>
                                <input type="hidden" id="p_origin_id" name="origin_station_id" value="11559">
                            </div>
                            <div>
                                <label for="p_via_name" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Umstiegshaltestelle (optional):</label>
                                <input type="text" id="p_via_name" name="via_station_name" class="yield-input js-station-autocomplete" style="width: 100%;" placeholder="z. B. Wilhelm-Leuschner-Platz">
                                <input type="hidden" id="p_via_id" name="via_station_id" value="">
                            </div>
                            <div>
                                <label for="p_dest_name" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Ziel-Haltestelle:</label>
                                <input type="text" id="p_dest_name" name="destination_station_name" class="yield-input js-station-autocomplete" style="width: 100%;" placeholder="z. B. Leipzig, Augustusplatz" required>
                                <input type="hidden" id="p_dest_id" name="destination_station_id" value="13002">
                            </div>
                        </div>

                        <!-- Wochentage -->
                        <div style="margin-top: 1.25rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Aktive Wochentage:</label>
                            <div class="transit-days-picker">
                                <label><input type="checkbox" name="active_days[]" value="1"> Mo</label>
                                <label><input type="checkbox" name="active_days[]" value="2"> Di</label>
                                <label><input type="checkbox" name="active_days[]" value="3"> Mi</label>
                                <label><input type="checkbox" name="active_days[]" value="4"> Do</label>
                                <label><input type="checkbox" name="active_days[]" value="5"> Fr</label>
                                <label><input type="checkbox" name="active_days[]" value="6"> Sa</label>
                                <label><input type="checkbox" name="active_days[]" value="7"> So</label>
                            </div>
                        </div>

                        <!-- Optionen -->
                        <div style="margin-top: 1.25rem; display: flex; flex-direction: column; gap: 0.75rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" id="p_school_days" name="only_school_days" value="1" style="transform: scale(1.2);">
                                <span><strong>Nur an Schultagen aktiv</strong> (pausiert automatisch in sächsischen Schulferien &amp; an Feiertagen)</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" id="p_active" name="is_active" value="1" checked style="transform: scale(1.2);">
                                <span>Profil aktiv</span>
                            </label>
                        </div>
                    </div>

                    <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                        <button type="button" class="btn btn-outline text-danger js-delete-profile" id="btn-delete-profile" style="display: none;">🗑️ Löschen</button>
                        <div style="display: flex; gap: 0.5rem;">
                            <button type="button" class="btn btn-outline js-close-profile-modal">Abbrechen</button>
                            <button type="submit" class="btn btn-save">💾 Speichern</button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
<script src="../js/transit.js?v=<?= APP_VERSION ?>_<?= time() ?>" defer></script>
</body>
</html>
