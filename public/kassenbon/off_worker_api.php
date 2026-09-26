<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Kassenbon\OpenFoodFactsQueueRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

$logger = new Logger();

// Authentifizierung: Nur mit gültigem CRON/API-Token (Header x-api-key oder Bearer-Token)
if (!Auth::cronTokenMatches(false)) {
    $logger->error('OpenFoodFacts Worker API: Unbefugter Zugriff versucht (ungültiges oder fehlendes Token).');
    Auth::sendJsonError(401, 'Unauthorized');
}

$repo = new OpenFoodFactsQueueRepository();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // 1. Jobs abrufen (Pull)
    $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 20;
    $jobs = $repo->getPendingJobs($limit);

    echo json_encode([
        'success' => true,
        'count' => count($jobs),
        'jobs' => $jobs,
    ]);
    exit;
}

if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        Auth::sendJsonError(400, 'Ungültiges JSON Payload');
    }

    $action = $input['action'] ?? 'push';

    if ($action === 'pull') {
        $limit = isset($input['limit']) ? (int)$input['limit'] : 20;
        $jobs = $repo->getPendingJobs($limit);

        echo json_encode([
            'success' => true,
            'count' => count($jobs),
            'jobs' => $jobs,
        ]);
        exit;
    }

    if ($action === 'push') {
        $results = $input['results'] ?? [];
        if (!is_array($results)) {
            Auth::sendJsonError(400, 'Ungültige Results-Liste');
        }

        $savedCount = 0;
        foreach ($results as $item) {
            if (is_array($item) && !empty($item['product_key'])) {
                $repo->saveResult($item);
                $savedCount++;
            }
        }

        $logger->info("OpenFoodFacts Worker API: $savedCount Produkt-Ergebnisse vom Raspi gespeichert.");

        echo json_encode([
            'success' => true,
            'saved' => $savedCount,
        ]);
        exit;
    }

    Auth::sendJsonError(400, 'Unbekannte Aktion');
}

Auth::sendJsonError(405, 'Methode nicht erlaubt');
