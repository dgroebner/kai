<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Car\GeocodingService;
use Kai\Tools\Car\TripExpenseService;
use Kai\Tools\Car\TripPlanningService;
use Kai\Tools\Car\TripRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check
Auth::requireApi('car_read');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$logger = new Logger(14);
$tripRepo = new TripRepository();
$planningService = new TripPlanningService(tripRepo: $tripRepo, logger: $logger);
$expenseService = new TripExpenseService(tripRepo: $tripRepo, logger: $logger);
$geocodingService = new GeocodingService(logger: $logger);

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';

        if ($action === 'list') {
            $status = !empty($_GET['status']) ? (string)$_GET['status'] : null;
            $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;
            $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

            $trips = $tripRepo->getTrips($status, $limit, $offset);
            $total = $tripRepo->countTrips($status);

            echo json_encode([
                'success' => true,
                'data' => [
                    'trips' => $trips,
                    'total' => $total,
                ]
            ]);
            exit;
        }

        if ($action === 'get') {
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                Auth::sendJsonError(400, 'Ungültige Trip-ID');
            }

            $trip = $tripRepo->getTrip($id);
            if (!$trip) {
                Auth::sendJsonError(404, 'Reise nicht gefunden');
            }

            $steps = $tripRepo->getChargingSteps($id);
            $transactions = $tripRepo->getTripTransactions($id);
            $subtrips = $tripRepo->getSubTrips($id);
            $candidates = $expenseService->getCandidateTransactions($id);

            echo json_encode([
                'success' => true,
                'data' => [
                    'trip' => $trip,
                    'charging_steps' => $steps,
                    'transactions' => $transactions,
                    'subtrips' => $subtrips,
                    'candidates' => $candidates,
                ]
            ]);
            exit;
        }

        if ($action === 'candidates') {
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                Auth::sendJsonError(400, 'Ungültige Trip-ID');
            }

            $candidates = $expenseService->getCandidateTransactions($id);
            echo json_encode([
                'success' => true,
                'data' => $candidates,
            ]);
            exit;
        }

        Auth::sendJsonError(400, 'Unbekannte GET-Aktion');
    }

    // 2. Ab hier POST-Aktionen (state-verändernd, erfordert car_write & CSRF)
    Auth::requireApi('car_write');
    Auth::requireMethod('POST');

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        Auth::sendJsonError(400, 'Ungültige JSON-Nutzlast');
    }
    Auth::requireCsrfToken($input);

    $action = $input['action'] ?? '';

    if ($action === 'save_trip') {
        $tripData = $input['trip'] ?? [];
        if (!is_array($tripData) || empty($tripData['destination_address']) || empty($tripData['departure_time'])) {
            Auth::sendJsonError(400, 'Zieladresse und Abfahrtszeitpunkt sind erforderlich');
        }

        $tripId = !empty($tripData['id']) ? (int)$tripData['id'] : null;

        if ($tripId) {
            // Update
            $updateFields = [
                'title' => trim((string)($tripData['title'] ?? 'Reise')),
                'start_address' => trim((string)($tripData['start_address'] ?? 'Zuhause')),
                'destination_address' => trim((string)$tripData['destination_address']),
                'departure_time' => (string)$tripData['departure_time'],
                'return_time' => !empty($tripData['return_time']) ? (string)$tripData['return_time'] : null,
                'is_round_trip' => !empty($tripData['is_round_trip']) ? 1 : 0,
                'target_arrival_soc' => (int)($tripData['target_arrival_soc'] ?? 10),
                'planned_departure_soc' => (int)($tripData['planned_departure_soc'] ?? 100),
            ];

            if (isset($tripData['start_lat'], $tripData['start_lon'])) {
                $updateFields['start_lat'] = (float)$tripData['start_lat'];
                $updateFields['start_lon'] = (float)$tripData['start_lon'];
            }
            if (isset($tripData['destination_lat'], $tripData['destination_lon'])) {
                $updateFields['destination_lat'] = (float)$tripData['destination_lat'];
                $updateFields['destination_lon'] = (float)$tripData['destination_lon'];
            }

            $tripRepo->updateTrip($tripId, $updateFields);
            $planningService->recalculateTrip($tripId);

            echo json_encode(['success' => true, 'data' => ['id' => $tripId]]);
            exit;
        }

        // Neu anlegen
        $newId = $planningService->planAndSaveTrip($tripData);
        echo json_encode(['success' => true, 'data' => ['id' => $newId]]);
        exit;
    }

    if ($action === 'delete_trip') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            Auth::sendJsonError(400, 'Ungültige Trip-ID');
        }

        $success = $tripRepo->deleteTrip($id);
        echo json_encode(['success' => $success]);
        exit;
    }

    if ($action === 'recalculate_trip') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            Auth::sendJsonError(400, 'Ungültige Trip-ID');
        }

        $success = $planningService->recalculateTrip($id);
        $trip = $tripRepo->getTrip($id);
        $steps = $tripRepo->getChargingSteps($id);

        echo json_encode([
            'success' => $success,
            'data' => [
                'trip' => $trip,
                'charging_steps' => $steps,
            ]
        ]);
        exit;
    }

    if ($action === 'update_status') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string)($input['status'] ?? '');
        $allowedStatuses = ['entwurf', 'geplant', 'aktiv', 'abgeschlossen', 'storniert'];

        if (!$id || !in_array($status, $allowedStatuses, true)) {
            Auth::sendJsonError(400, 'Ungültige Parameter für Statusaktualisierung');
        }

        $success = $tripRepo->updateTrip($id, ['status' => $status]);
        echo json_encode(['success' => $success]);
        exit;
    }

    if ($action === 'update_step_status') {
        $stepId = filter_var($input['step_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string)($input['status'] ?? '');
        $allowedStatuses = ['geplant', 'erledigt', 'uebersprungen'];

        if (!$stepId || !in_array($status, $allowedStatuses, true)) {
            Auth::sendJsonError(400, 'Ungültige Parameter für Schritt-Status');
        }

        $success = $tripRepo->updateChargingStepStatus($stepId, $status);
        echo json_encode(['success' => $success]);
        exit;
    }

    if ($action === 'link_transaction') {
        $tripId = filter_var($input['trip_id'] ?? null, FILTER_VALIDATE_INT);
        $type = (string)($input['transaction_type'] ?? '');
        $txId = filter_var($input['transaction_id'] ?? null, FILTER_VALIDATE_INT);
        $category = (string)($input['cost_category'] ?? 'charge');

        if (!$tripId || !$txId || !in_array($type, ['giro', 'creditcard'], true)) {
            Auth::sendJsonError(400, 'Ungültige Buchungsparameter');
        }

        $allowedCategories = ['charge', 'toll', 'parking', 'other'];
        if (!in_array($category, $allowedCategories, true)) {
            $category = 'charge';
        }

        $tripRepo->linkTransaction($tripId, $type, $txId, $category);
        $costs = $expenseService->recalculateTripCosts($tripId);

        echo json_encode([
            'success' => true,
            'data' => [
                'costs' => $costs,
                'transactions' => $tripRepo->getTripTransactions($tripId),
            ]
        ]);
        exit;
    }

    if ($action === 'unlink_transaction') {
        $tripId = filter_var($input['trip_id'] ?? null, FILTER_VALIDATE_INT);
        $type = (string)($input['transaction_type'] ?? '');
        $txId = filter_var($input['transaction_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$tripId || !$txId || !in_array($type, ['giro', 'creditcard'], true)) {
            Auth::sendJsonError(400, 'Ungültige Buchungsparameter');
        }

        $tripRepo->unlinkTransaction($tripId, $type, $txId);
        $costs = $expenseService->recalculateTripCosts($tripId);

        echo json_encode([
            'success' => true,
            'data' => [
                'costs' => $costs,
                'transactions' => $tripRepo->getTripTransactions($tripId),
            ]
        ]);
        exit;
    }

    if ($action === 'geocode') {
        $address = trim((string)($input['address'] ?? ''));
        if ($address === '') {
            Auth::sendJsonError(400, 'Adresse ist erforderlich');
        }

        $result = $geocodingService->geocode($address);
        if (!$result) {
            Auth::sendJsonError(404, 'Adresse konnte nicht geocodiert werden');
        }

        echo json_encode(['success' => true, 'data' => $result]);
        exit;
    }

    Auth::sendJsonError(400, 'Unbekannte POST-Aktion');

} catch (Throwable $e) {
    $logger->error('public/car/trips_api.php: Unerwarteter Fehler.', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Serverfehler');
}
