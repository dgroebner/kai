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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync') {
    Auth::requireCsrfToken($_POST);
    if (Auth::hasPermission('school_write')) {
        $syncDate = filter_input(INPUT_POST, 'date', FILTER_DEFAULT) ?: $selectedDate;
        $schoolService->syncDate($syncDate);
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

// Datumslabels für Buttons
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
            <h1>🎒 Schule &amp; Vertretungsplan</h1>
            <?php if (!empty($metadata['plan_timestamp'])): ?>
                <span class="last-update">Stand Plan: <?= htmlspecialchars($metadata['plan_timestamp'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
        <div class="page-header-actions">
            <?php if (Auth::hasPermission('school_write')): ?>
                <form method="POST" action="index.php" class="school-sync-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="sync">
                    <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="student" value="<?= htmlspecialchars($selectedStudentId, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn-outline">🔄 Aktualisieren</button>
                </form>
            <?php endif; ?>
            <a href="../index.php" class="btn btn-outline">&larr; Dashboard</a>
        </div>
    </header>

    <!-- Datum-Umschalter -->
    <div class="period-switcher school-period-switcher">
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

        <!-- Freie Datumsauswahl -->
        <form method="GET" action="index.php" class="school-date-picker-form">
            <input type="hidden" name="student" value="<?= htmlspecialchars($selectedStudentId, ENT_QUOTES, 'UTF-8') ?>">
            <input type="date" name="date" value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>" class="school-date-input" aria-label="Anderes Datum wählen">
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
                   <span class="school-pill-dot" style="background-color: <?= htmlspecialchars($st['display_color'], ENT_QUOTES, 'UTF-8') ?>;"></span>
                   <?= htmlspecialchars($st['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($st['class_name'], ENT_QUOTES, 'UTF-8') ?>)
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
                    <h2>Kein Plan für <?= htmlspecialchars($schoolService->formatDateLabel($selectedDate), ENT_QUOTES, 'UTF-8') ?> verfügbar</h2>
                    <p class="text-muted">
                        Die Schule hat für dieses Datum aktuell noch keinen Vertretungsplan bereitgestellt. 
                        Vertretungspläne für den nächsten Schultag werden in der Regel nachmittags hochgeladen.
                    </p>
                    <div class="school-empty-actions">
                        <a href="index.php?date=<?= $today ?>&amp;student=<?= urlencode($selectedStudentId) ?>" class="btn">
                            Zu heute (<?= date('d.m.') ?>) wechseln
                        </a>
                        <?php if (Auth::hasPermission('school_write')): ?>
                            <form method="POST" action="index.php" class="school-sync-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="sync">
                                <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="student" value="<?= htmlspecialchars($selectedStudentId, ENT_QUOTES, 'UTF-8') ?>">
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
                    <div class="card school-hero-card" style="border-left: 3px solid <?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
                        <div class="school-hero-header">
                            <div class="school-hero-title">
                                <span class="school-avatar" style="background-color: <?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
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
                <?php if ($sched['has_plan']): ?>
                    <section class="card school-plan-card">
                        <div class="school-card-header">
                            <h2>
                                Stundenplan: <?= htmlspecialchars($sched['student']['name'] ?? '', ENT_QUOTES, 'UTF-8') ?> 
                                (Klasse <?= htmlspecialchars($sched['class_name'], ENT_QUOTES, 'UTF-8') ?>)
                            </h2>
                            <div class="school-card-stats">
                                <?php if ($sched['cancelled_count'] > 0): ?>
                                    <span class="badge school-badge-cancel"><?= $sched['cancelled_count'] ?> Ausfall</span>
                                <?php endif; ?>
                                <?php if ($sched['substitution_count'] > 0): ?>
                                    <span class="badge school-badge-subst"><?= $sched['substitution_count'] ?> Änderung</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="data-table stack-table">
                                <thead>
                                    <tr>
                                        <th style="width: 80px;">Stunde</th>
                                        <th style="width: 130px;">Zeit</th>
                                        <th>Fach</th>
                                        <th>Lehrer</th>
                                        <th>Raum</th>
                                        <th>Information / Vertretung</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sched['items'] as $item): ?>
                                        <?php 
                                        $rowClass = '';
                                        if (!empty($item['is_cancelled'])) {
                                            $rowClass = 'school-row-cancelled';
                                        } elseif (!empty($item['is_substitution']) || !empty($item['is_moved'])) {
                                            $rowClass = 'school-row-changed';
                                        }
                                        ?>
                                        <tr class="<?= $rowClass ?>">
                                            <td data-label="Stunde">
                                                <strong><?= (int)$item['lesson_number'] ?>. Std</strong>
                                            </td>
                                            <td data-label="Zeit" class="text-muted">
                                                <?= htmlspecialchars($item['start_time'], ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars($item['end_time'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                            <td data-label="Fach">
                                                <?php if (!empty($item['is_cancelled'])): ?>
                                                    <span class="school-item-cancelled">Entfall</span>
                                                <?php else: ?>
                                                    <strong><?= htmlspecialchars($item['subject'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                    <?php if (!empty($item['course_group'])): ?>
                                                        <span class="school-course-tag"><?= htmlspecialchars($item['course_group'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Lehrer">
                                                <?php if (!empty($item['teacher'])): ?>
                                                    <span class="school-teacher-tag"><?= htmlspecialchars($item['teacher'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">–</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Raum">
                                                <?php if (!empty($item['room'])): ?>
                                                    <span class="school-room-tag"><?= htmlspecialchars($item['room'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">–</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Info">
                                                <?php if (!empty($item['info'])): ?>
                                                    <span class="school-info-text"><?= htmlspecialchars($item['info'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">Planmäßig</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>
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
