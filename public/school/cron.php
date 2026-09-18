<?php

require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\School\SchoolService;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check: Cron-Token erforderlich (oder angemeldeter Benutzer mit school_write)
if (!Auth::cronTokenMatches() && !Auth::hasPermission('school_write')) {
    Auth::requireCronToken('school/cron.php');
}

$logger = new Logger();

try {
    $schoolService = new SchoolService();

    // Optional bestimmtes Datum synchronisieren, sonst heute + nächster Schultag
    $targetDate = filter_input(INPUT_GET, 'date', FILTER_DEFAULT);
    if ($targetDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        $result = $schoolService->syncDate($targetDate);
        $results = [$targetDate => $result];
    } else {
        $results = $schoolService->syncTodayAndNext();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Schul-Vertretungspläne abgeglichen.',
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    $logger->error('public/school/cron.php: Fehler bei Ausführung.', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Interner Fehler beim Abgleich.'], JSON_UNESCAPED_UNICODE);
}
