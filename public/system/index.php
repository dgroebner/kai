<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\ActivityLogRepository;

Auth::requirePage();

$activityRepo = new ActivityLogRepository();
$activities = $activityRepo->getLatestActivities(50);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivitäts-Log - KAI Tools</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <header class="header-container">
        <div class="header-left">
            <a href="/" class="btn btn-secondary">&larr; Dashboard</a>
            <h1>📋 Aktivitäts-Log</h1>
        </div>
        <div class="user-info">
            <a href="/logout.php" class="btn btn-secondary">Abmelden</a>
        </div>
    </header>

    <main class="container">
        <section class="card">
            <h2>Letchte System-Aktivitäten</h2>
            <?php if (empty($activities)): ?>
                <p class="text-muted">Noch keine Aktivitäten protokolliert.</p>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($activities as $activity): ?>
                        <li class="activity-item <?= $activity['is_read'] ? 'read' : 'unread' ?>">
                            <div class="activity-main">
                                <span class="activity-message">
                                    <?php if (!empty($activity['link_url'])): ?>
                                        <a href="<?= htmlspecialchars($activity['link_url']) ?>">
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
            <?php endif; ?>
        </section>
    </main>
</body>
</html>