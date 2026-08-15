<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\ActivityLogRepository;

Auth::requirePage();

$activityRepo = new ActivityLogRepository();

// Paginierung konfigurieren
$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$totalItems = $activityRepo->getTotalCount();
$totalPages = max(1, (int)ceil($totalItems / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;
$activities = $activityRepo->getLatestActivities($limit, $offset);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivitäts-Log - KAI Tools</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
</head>
<body>
    <div class="container">
        <header class="page-header">
            <h1>📋 Aktivitäts-Log</h1>
            <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
        </header> 

        <main>
            <section class="card">
                <h2>Letzte System-Aktivitäten</h2>
                <?php if (empty($activities)): ?>
                    <p class="text-muted">Noch keine Aktivitäten protokolliert.</p>
                <?php else: ?>
                    <ul class="activity-list">
                        <?php foreach ($activities as $activity): ?>
                            <li class="activity-item <?= $activity['is_read'] ? 'read' : 'unread' ?>">
                                <div class="activity-main">
                                    <span class="activity-message">
                                        <?php if (!empty($activity['link_url'])): ?>
                                            <a href="<?= htmlspecialchars($activity['link_url']) ?>" class="btn-link">
                                                <?= htmlspecialchars($activity['message']) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($activity['message']) ?>
                                        <?php endif; ?>
                                    </span>
                                    <time class="activity-time" datetime="<?= $activity['created_at'] ?>">
                                        <?= date('d.m.Y H:i', strtotime($activity['created_at'])) ?> Uhr
                                    </time>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($totalPages > 1): ?>
                        <nav class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>" class="btn btn-outline">&laquo; Vorherige</a>
                            <?php else: ?>
                                <span class="btn btn-outline disabled">&laquo; Vorherige</span>
                            <?php endif; ?>

                            <span class="page-info">
                                Seite <?= $page ?> von <?= $totalPages ?> (<?= $totalItems ?> Einträge)
                            </span>

                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page + 1 ?>" class="btn btn-outline">Nächste &raquo;</a>
                            <?php else: ?>
                                <span class="btn btn-outline disabled">Nächste &raquo;</span>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>