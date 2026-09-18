<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\School\SchoolService;
use Kai\Tools\Shared\Security\Auth;

// 1. Auth-Check: Lesezugriff für Schule erforderlich
Auth::requirePage('school_read');

$schoolService = new SchoolService();
$studentRepo = $schoolService->getStudentRepository();
$planRepo = $schoolService->getPlanRepository();

// Aktuell angemeldeter Nutzer
$currentUserEmail = $_SESSION['user_email'] ?? '';
$matchedStudent = $studentRepo->getByEmail($currentUserEmail);

// Alle aktiven Kinder
$allStudents = $studentRepo->getActive();

// 2. Datumsauswahl (inkl. 14:00-Uhr- und Wochenend-Wechsellogik)
$today = date('Y-m-d');
$nextSchoolDay = $schoolService->determineEffectiveDate(null);
$requestedDate = filter_input(INPUT_GET, 'date', FILTER_DEFAULT);

// Wenn kein Datum explizit angefragt wurde: Standard auf den aktuellen Zieltag (heute oder nächster Schultag)
$selectedDate = $schoolService->determineEffectiveDate($requestedDate);

// 3. Filter-Auswahl (Kind)
$requestedStudentId = filter_input(INPUT_GET, 'student', FILTER_DEFAULT);

if ($requestedStudentId !== null && $requestedStudentId !== '') {
    $selectedStudentId = $requestedStudentId;
} elseif ($matchedStudent !== null) {
    // Kind ist selbst eingeloggt -> Direktfilter auf dieses Kind
    $selectedStudentId = (string)$matchedStudent['id'];
} else {
    // Eltern / Admin -> Standardmäßig alle Kinder
    $selectedStudentId = 'all';
}

// 4. Manueller Sofort-Abgleich direkt über den SchoolService
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Auth::requireCsrfToken($_POST);

    if ($_POST['action'] === 'sync' && Auth::hasPermission('school_write')) {
        $syncDate = filter_input(INPUT_POST, 'date', FILTER_DEFAULT) ?: $selectedDate;
        $schoolService->syncDate($syncDate);
        $studentParam = filter_input(INPUT_POST, 'student', FILTER_DEFAULT) ?: $selectedStudentId;
        header('Location: index.php?date=' . urlencode($syncDate) . '&student=' . urlencode($studentParam));
        exit;
    }

    // 4b. Fächer-Filter für ein Schülerprofil speichern (Kind selbst oder Nutzer mit Schreibrechten)
    if ($_POST['action'] === 'save_subjects') {
        $targetStudentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        if ($targetStudentId) {
            $canEdit = Auth::hasPermission('school_write')
                    || ($matchedStudent !== null && (int)$matchedStudent['id'] === $targetStudentId);

            if ($canEdit) {
                $rawExcluded = $_POST['excluded'] ?? [];
                $excludedList = is_array($rawExcluded) ? $rawExcluded : [];
                $studentRepo->updateExcludedSubjects($targetStudentId, $excludedList);
            }
        }
        $syncDate = filter_input(INPUT_POST, 'date', FILTER_DEFAULT) ?: $selectedDate;
        $studentParam = filter_input(INPUT_POST, 'student', FILTER_DEFAULT) ?: $selectedStudentId;
        header('Location: index.php?date=' . urlencode($syncDate) . '&student=' . urlencode($studentParam));
        exit;
    }
}

// 5. Daten für das gewählte Datum laden
$metadata = $planRepo->getPlanMetadata($selectedDate);
$globalNotes = $planRepo->getGlobalNotes($selectedDate);
$availableDates = $planRepo->getAvailableDates();

// Wenn für das gewählte Datum noch kein Plan in der Datenbank existiert, versuchen wir einen Sync
if ($metadata === null) {
    $syncResult = $schoolService->syncDate($selectedDate);
    if ($syncResult['success']) {
        $metadata = $planRepo->getPlanMetadata($selectedDate);
        $globalNotes = $planRepo->getGlobalNotes($selectedDate);
    }
}

// Zeitplan-Daten aufbereiten
$displaySchedules = [];

if ($selectedStudentId === 'all') {
    // Alle aktiven Kinder anzeigen
    $displaySchedules = $schoolService->getAllStudentsOverview($selectedDate);
} else {
    // Einzelnes Kind ausgewählt
    $displaySchedules = [$schoolService->getStudentSchedule((int)$selectedStudentId, $selectedDate)];
}

// Datumslabels & Navigationstage für Buttons
$prevDay = $schoolService->getPreviousSchoolDay($selectedDate);
$nextDay = $schoolService->getNextSchoolDay($selectedDate);
$todayLabel = 'Heute (' . date('d.m.') . ')';
$nextLabel = ($nextSchoolDay === $today)
        ? 'Folgetag'
        : $schoolService->formatDateLabel($nextSchoolDay);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= Auth::csrfToken() ?>">
    <title>Schule &amp; Vertretungsplan - KAI Tools</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <div>
            <h1 style="display: flex; align-items: center; gap: 0.5rem;">
                🎒 Schule
                <?php if (!empty($metadata['school_week'])): ?>
                    <?php 
                    $weekDisplay = '';
                    if ($metadata['school_week'] === '1') $weekDisplay = 'A-Woche';
                    elseif ($metadata['school_week'] === '2') $weekDisplay = 'B-Woche';
                    else $weekDisplay = 'Woche ' . $metadata['school_week'];
                    ?>
                    <span class="badge badge-outline" style="font-size: 0.85rem; letter-spacing: 0.5px;"><?= htmlspecialchars($weekDisplay, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </h1>
            <?php if (!empty($metadata['plan_timestamp'])): ?>
                <span class="last-update">Stand Plan: <?= htmlspecialchars($metadata['plan_timestamp'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
        <div class="page-header-actions">
            <?php if (Auth::hasPermission('school_write')): ?>
                <form method="POST" action="index.php" class="school-sync-form">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="sync">
                    <input type="hidden" name="date"
                           value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="student"
                           value="<?= htmlspecialchars($selectedStudentId, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn-outline">🔄 Aktualisieren</button>
                </form>
            <?php endif; ?>
            <a href="../index.php" class="btn btn-outline">&larr; Dashboard</a>
        </div>
    </header>

    <!-- Datum-Umschalter -->
    <div class="period-switcher school-period-switcher">
        <a href="index.php?date=<?= $prevDay ?>&amp;student=<?= urlencode($selectedStudentId) ?>"
           class="btn btn-outline" title="Vorherigen Schultag anzeigen (<?= htmlspecialchars($schoolService->formatDateLabel($prevDay), ENT_QUOTES, 'UTF-8') ?>)">
            &larr; Letzter Tag
        </a>

        <a href="index.php?date=<?= $today ?>&amp;student=<?= urlencode($selectedStudentId) ?>"
           class="btn <?= $selectedDate === $today ? '' : 'btn-outline' ?>">
            📅 <?= htmlspecialchars($todayLabel, ENT_QUOTES, 'UTF-8') ?>
        </a>

        <?php if ($nextSchoolDay !== $today): ?>
            <a href="index.php?date=<?= $nextSchoolDay ?>&amp;student=<?= urlencode($selectedStudentId) ?>"
               class="btn <?= $selectedDate === $nextSchoolDay ? '' : 'btn-outline' ?>">
                🚀 <?= htmlspecialchars(ucfirst($nextLabel), ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endif; ?>

        <a href="index.php?date=<?= $nextDay ?>&amp;student=<?= urlencode($selectedStudentId) ?>"
           class="btn btn-outline" title="Nächsten Schultag anzeigen (<?= htmlspecialchars($schoolService->formatDateLabel($nextDay), ENT_QUOTES, 'UTF-8') ?>)">
            Nächster Tag &rarr;
        </a>

        <!-- Freie Datumsauswahl -->
        <form method="GET" action="index.php" class="school-date-picker-form">
            <input type="hidden" name="student"
                   value="<?= htmlspecialchars($selectedStudentId, ENT_QUOTES, 'UTF-8') ?>">
            <input type="date" name="date" value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>"
                   class="school-date-input" aria-label="Anderes Datum wählen">
            <button type="submit" class="btn btn-outline school-date-submit-btn">Anzeigen</button>
        </form>
    </div>

    <!-- Kinder-Filter -->
    <div class="school-filter-bar">
        <div class="school-student-pills">
            <?php if ($matchedStudent === null): ?>
                <a href="index.php?date=<?= urlencode($selectedDate) ?>&amp;student=all"
                   class="school-pill <?= $selectedStudentId === 'all' ? 'active' : '' ?>">
                    Alle Kinder
                </a>
            <?php endif; ?>

            <?php foreach ($allStudents as $st): ?>
                <a href="index.php?date=<?= urlencode($selectedDate) ?>&amp;student=<?= $st['id'] ?>"
                   class="school-pill <?= $selectedStudentId === (string)$st['id'] ? 'active' : '' ?>">
                    <span class="school-pill-dot"
                          style="background-color: <?= htmlspecialchars($st['display_color'], ENT_QUOTES, 'UTF-8') ?>;"></span>
                    <?= htmlspecialchars($st['name'], ENT_QUOTES, 'UTF-8') ?>
                    (<?= htmlspecialchars($st['class_name'], ENT_QUOTES, 'UTF-8') ?>)
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <main>
        <!-- Hinweis wenn noch kein Plan vorliegt -->
        <?php if ($metadata === null): ?>
            <div class="card school-empty-notice">
                <div class="school-empty-icon">⏳</div>
                <div class="school-empty-content">
                    <h2>Kein Plan
                        für <?= htmlspecialchars($schoolService->formatDateLabel($selectedDate), ENT_QUOTES, 'UTF-8') ?>
                        verfügbar</h2>
                    <p class="text-muted">
                        Die Schule hat für dieses Datum aktuell noch keinen Vertretungsplan bereitgestellt.
                        Vertretungspläne für den nächsten Schultag werden in der Regel nachmittags hochgeladen.
                    </p>
                    <div class="school-empty-actions">
                        <a href="index.php?date=<?= $today ?>&amp;student=<?= urlencode($selectedStudentId) ?>"
                           class="btn">
                            Zu heute (<?= date('d.m.') ?>) wechseln
                        </a>
                        <?php if (Auth::hasPermission('school_write')): ?>
                            <form method="POST" action="index.php" class="school-sync-form">
                                <input type="hidden" name="csrf_token"
                                       value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="sync">
                                <input type="hidden" name="date"
                                       value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="student"
                                       value="<?= htmlspecialchars($selectedStudentId, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-outline">Jetzt prüfen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Schulweite Durchsagen / ZusatzInfo -->
        <?php if (!empty($globalNotes)): ?>
            <div class="card school-notice-card">
                <div class="school-notice-header">
                    <span class="school-notice-icon">📢</span>
                    <h3>Schulweite Mitteilungen</h3>
                </div>
                <ul class="school-notice-list">
                    <?php foreach ($globalNotes as $note): ?>
                        <li><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Smart-Banner: Kern-Auskunft & Abweichungen in natürlicher Sprache -->
        <?php if (!empty($displaySchedules)): ?>
            <div class="school-smart-summary-grid">
                <?php foreach ($displaySchedules as $sched): ?>
                    <?php
                    $st = $sched['student'] ?? [];
                    $color = $st['display_color'] ?? '#2563eb';
                    ?>
                    <div class="card school-hero-card"
                         style="border-left: 3px solid <?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
                        <div class="school-hero-header">
                            <div class="school-hero-title">
                                <span class="school-avatar"
                                      style="background-color: <?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
                                    <?= htmlspecialchars(mb_substr($st['name'] ?? 'K', 0, 1), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <strong><?= htmlspecialchars($st['name'] ?? 'Kind', ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="badge badge-outline">Kl. <?= htmlspecialchars($sched['class_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <?php if ($sched['has_plan'] && $sched['end_time']): ?>
                                <span class="school-hero-time-pill">
                                    Schluss: <strong><?= htmlspecialchars($sched['end_time'], ENT_QUOTES, 'UTF-8') ?> Uhr</strong>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="school-hero-sentence">
                            <?= htmlspecialchars($sched['summary_sentence'], ENT_QUOTES, 'UTF-8') ?>
                        </div>

                        <?php if (!empty($sched['deviations'])): ?>
                            <div class="school-hero-deviations">
                                <span class="school-deviations-title">Besonderheiten:</span>
                                <ul>
                                    <?php foreach ($sched['deviations'] as $dev): ?>
                                        <li><?= htmlspecialchars($dev, ENT_QUOTES, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Detaillierte Stundenpläne -->
        <?php if (!empty($displaySchedules)): ?>
            <?php foreach ($displaySchedules as $sched): ?>
                <?php
                $st = $sched['student'] ?? [];
                $stId = (int)($st['id'] ?? 0);
                $canEditStudent = Auth::hasPermission('school_write') || ($matchedStudent !== null && (int)$matchedStudent['id'] === $stId);
                $distinctClassSubjects = $planRepo->getDistinctSubjectsForClass($sched['class_name']);
                $currentExcluded = $sched['excluded_subjects'] ?? [];
                ?>
                <section class="card school-plan-card">
                    <div class="school-card-header">
                        <h2>
                            Stundenplan: <?= htmlspecialchars($st['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            (Klasse <?= htmlspecialchars($sched['class_name'], ENT_QUOTES, 'UTF-8') ?>)
                        </h2>
                        <div class="school-card-actions">
                            <div class="school-card-stats">
                                <?php if (!empty($sched['cancelled_count']) && $sched['cancelled_count'] > 0): ?>
                                    <span class="badge school-badge-cancel"><?= $sched['cancelled_count'] ?> Ausfall</span>
                                <?php endif; ?>
                                <?php if (!empty($sched['substitution_count']) && $sched['substitution_count'] > 0): ?>
                                    <span class="badge school-badge-subst"><?= $sched['substitution_count'] ?> Änderung</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($canEditStudent): ?>
                                <button type="button"
                                        class="btn btn-outline btn-sm school-config-toggle js-school-config-toggle"
                                        data-target="school-config-<?= $stId ?>">
                                    ⚙️ Fächer anpassen
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Fächer-Konfigurationspanel (ein-/ausklappbar) -->
                    <?php if ($canEditStudent): ?>
                        <div id="school-config-<?= $stId ?>" class="school-config-panel js-school-config-panel"
                             style="display: none;">
                            <form method="POST" action="index.php" class="school-subjects-form">
                                <input type="hidden" name="csrf_token"
                                       value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="save_subjects">
                                <input type="hidden" name="student_id" value="<?= $stId ?>">
                                <input type="hidden" name="date"
                                       value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="student"
                                       value="<?= htmlspecialchars($selectedStudentId, ENT_QUOTES, 'UTF-8') ?>">

                                <div class="school-config-intro">
                                    <strong>Belegte Fächer
                                        für <?= htmlspecialchars($st['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>:</strong>
                                    <p class="text-muted">
                                        Wähle die Fächer ab, die du nicht belegst (z. B. Ethik statt Religion,
                                        Französisch statt Latein).
                                        Abgewählte Fächer werden aus deinem Stundenplan und deiner
                                        Schulschluss-Berechnung entfernt.
                                    </p>
                                </div>

                                <div class="school-subjects-grid">
                                    <?php if (empty($distinctClassSubjects)): ?>
                                        <p class="text-muted">Noch keine Fächer für
                                            Klasse <?= htmlspecialchars($sched['class_name'], ENT_QUOTES, 'UTF-8') ?>
                                            erfasst.</p>
                                    <?php else: ?>
                                        <?php foreach ($distinctClassSubjects as $subj): ?>
                                            <?php
                                            $isExcluded = in_array(strtoupper($subj), $currentExcluded, true);
                                            if (!$isExcluded && preg_match('/^([A-Za-z0-9:\/]+)\s*\(/u', $subj, $m)) {
                                                $baseSubj = strtoupper(trim($m[1]));
                                                if (in_array($baseSubj, $currentExcluded, true)) {
                                                    $isExcluded = true;
                                                }
                                            }
                                            ?>
                                            <label class="school-subject-item <?= $isExcluded ? 'is-excluded' : 'is-included' ?>">
                                                <input type="checkbox"
                                                       name="excluded[]"
                                                       value="<?= htmlspecialchars($subj, ENT_QUOTES, 'UTF-8') ?>"
                                                        <?= $isExcluded ? 'checked' : '' ?>
                                                       data-student-self-edit="1"
                                                       class="school-subject-checkbox">
                                                <span class="school-subject-label">
                                                    <span class="school-subject-name"><?= htmlspecialchars($subj, ENT_QUOTES, 'UTF-8') ?></span>
                                                    <span class="school-subject-status"><?= $isExcluded ? '❌ Abgewählt' : '✅ Belegt' ?></span>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <div class="school-config-actions">
                                    <button type="submit" class="btn btn-save" data-student-self-edit="1">💾 Fächer
                                        speichern
                                    </button>
                                    <button type="button" class="btn btn-outline js-school-config-close"
                                            data-target="school-config-<?= $stId ?>">Abbrechen
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($currentExcluded)): ?>
                        <div class="school-excluded-notice">
                            <span class="text-muted">Nicht belegte Fächer:</span>
                            <?php foreach ($currentExcluded as $ex): ?>
                                <span class="badge badge-outline school-badge-excluded"><?= htmlspecialchars($ex, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($sched['has_plan']): ?>
                        <div class="table-responsive">
                            <table class="data-table school-timetable">
                                <thead>
                                    <tr>
                                        <th class="school-col-stunde">Stunde</th>
                                        <th class="school-col-zeit">Zeit</th>
                                        <th class="school-col-fach">Fach</th>
                                        <th class="school-col-lehrer">Lehrer</th>
                                        <th class="school-col-raum">Raum</th>
                                        <th class="school-col-info">Information / Vertretung</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sched['items'] as $item): ?>
                                        <?php 
                                        $rowClass = '';
                                        if (!empty($item['is_free_period'])) {
                                            $rowClass = 'school-row-free';
                                        } elseif (!empty($item['is_cancelled'])) {
                                            $rowClass = 'school-row-cancelled';
                                        } elseif (!empty($item['is_substitution']) || !empty($item['is_moved']) || !empty($item['is_room_change'])) {
                                            $rowClass = 'school-row-changed';
                                        }
                                        ?>
                                        <tr class="<?= $rowClass ?>">
                                            <td class="school-cell-stunde" data-label="Stunde">
                                                <strong><?= (int)$item['lesson_number'] ?>. Std</strong>
                                            </td>
                                            <td class="school-cell-zeit" data-label="Zeit">
                                                <span class="school-time-text"><?= htmlspecialchars($item['start_time'], ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars($item['end_time'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </td>
                                            <td class="school-cell-fach" data-label="Fach">
                                                <?php if (!empty($item['is_free_period'])): ?>
                                                    <span class="school-item-free">Unterrichtsfrei</span>
                                                <?php elseif (!empty($item['is_cancelled'])): ?>
                                                    <span class="school-item-cancelled">Entfall</span>
                                                    <?php if (!empty($item['subject_original']) && $item['subject_original'] !== '---'): ?>
                                                        <span class="school-orig-subject">(<?= htmlspecialchars($item['subject_original'], ENT_QUOTES, 'UTF-8') ?>)</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <strong class="school-subject-name-cell"><?= htmlspecialchars($item['subject'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                    <?php if (!empty($item['course_group'])): ?>
                                                        <span class="school-course-tag"><?= htmlspecialchars($item['course_group'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="school-cell-lehrer" data-label="Lehrer">
                                                <?php if (!empty($item['teacher'])): ?>
                                                    <span class="school-teacher-tag"><?= htmlspecialchars($item['teacher'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted school-cell-empty">–</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="school-cell-raum" data-label="Raum">
                                                <?php if (!empty($item['room'])): ?>
                                                    <span class="school-room-tag <?= !empty($item['is_room_change']) ? 'school-room-changed' : '' ?>"><?= htmlspecialchars($item['room'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted school-cell-empty">–</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="school-cell-info" data-label="Info">
                                                <?php if (!empty($item['info'])): ?>
                                                    <span class="school-info-text <?= !empty($item['is_free_period']) ? 'school-info-free' : '' ?>"><?= htmlspecialchars($item['info'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php elseif (!empty($item['is_room_change'])): ?>
                                                    <span class="school-info-text">Raumänderung</span>
                                                <?php elseif (!empty($item['is_substitution']) || !empty($item['is_moved'])): ?>
                                                    <span class="school-info-text">Geändert</span>
                                                <?php else: ?>
                                                    <span class="text-muted school-cell-plan">Planmäßig</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted school-empty-schedule-text">
                            Für diesen Tag liegen noch keine Stunden für <?= htmlspecialchars($st['name'] ?? '', ENT_QUOTES, 'UTF-8') ?> vor.
                        </p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>

    </main>

    <footer class="app-footer">
        <div>kai v<?= APP_VERSION ?> · Schule &amp; Vertretungsplan</div>
    </footer>
</div>

<script src="../js/http.js?v=<?= APP_VERSION ?>"></script>
<script src="../js/school.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
