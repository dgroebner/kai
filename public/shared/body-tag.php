<?php

use Kai\Tools\System\ActivityLogRepository;

$latestActivityId = 0;
try {
    $latestEntries = new ActivityLogRepository()->getLatestActivities(1, 0);
    if (!empty($latestEntries)) {
        $latestActivityId = (int)($latestEntries[0]['id'] ?? 0);
    }
} catch (Throwable $e) {
    $latestActivityId = 0;
}
?>
<body data-last-activity-id="<?php echo $latestActivityId; ?>">
