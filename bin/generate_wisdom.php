<?php
/**
 * CLI-Runner für die tägliche Weisheit.
 * Ausführung idealerweise nachts kurz nach Mitternacht (z. B. 00:05 Uhr via Cronjob).
 *
 * Aufruf:
 *   php bin/generate_wisdom.php
 *   php bin/generate_wisdom.php --force
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Zugriff verweigert: Dieses Skript kann nur über die Befehlszeile (CLI) ausgeführt werden.\n";
    exit(1);
}

require_once __DIR__ . '/../bootstrap.php';

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\System\DailyWisdomService;

$force = in_array('--force', $argv, true);
$logger = new Logger(14);
$service = new DailyWisdomService(logger: $logger);

$today = date('Y-m-d');
echo "[" . date('Y-m-d H:i:s') . "] Starte Prüfung/Generierung der Weisheit des Tages für $today...\n";

try {
    $result = $service->generateAndSaveWisdom($today, $force, 45);

    if ($result !== null) {
        echo "[OK] Weisheit für $today: $result\n";
        exit(0);
    }

    echo "[INFO] Keine neue Generierung erforderlich oder Spruch existiert bereits.\n";
    exit(0);
} catch (Throwable $e) {
    echo "[FEHLER] " . $e->getMessage() . "\n";
    exit(1);
}
