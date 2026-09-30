<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\DailyWisdomService;

Auth::requireCronToken('shared/wisdom_cron.php');

$logger = new Logger(14);
$force = isset($_GET['force']) && $_GET['force'] === '1';

try {
    $service = new DailyWisdomService(logger: $logger);
    $today = date('Y-m-d');
    $wisdom = $service->generateAndSaveWisdom($today, $force, 45);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'date' => $today,
        'wisdom' => $wisdom
    ]);
} catch (Throwable $e) {
    $logger->error('wisdom_cron.php: Fehler bei der Generierung.', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler');
}
