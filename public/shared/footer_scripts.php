<?php

use Kai\Tools\System\ActivityLogRepository;

// Neueste ID beim Seitenaufruf ermitteln, damit keine alten Logs beim Laden aufpoppen
$latestActivityId = 0;
try {
    $latestEntries = (new ActivityLogRepository())->getLatestActivities(1, 0);
    if (!empty($latestEntries)) {
        $latestActivityId = (int)($latestEntries[0]['id'] ?? 0);
    }
} catch (Throwable $e) {
    // Fallback falls die Tabelle o.ä. zickt
    $latestActivityId = 0;
}
?>
<!-- Activity Polling Initialisierung -->
<script>
    // Setzt die Start-ID global für die system.js verfügbar
    window.KaiInitialActivityId = <?php echo $latestActivityId; ?>;
</script>
<script src="../js/http.js"></script>
<script src="../js/system.js"></script>