<?php

require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Car\ChargingLocationService;
use Kai\Tools\Car\ChargingReceiptService;
use Kai\Tools\Car\ChargingTariffService;
use Kai\Tools\Car\VehicleChargeRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check: Mindestens Leserechte für Car
Auth::requireApi('car_read');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$logger = new Logger(14);
$chargeRepo = new VehicleChargeRepository();
$tariffService = new ChargingTariffService(logger: $logger);
$receiptService = new ChargingReceiptService(logger: $logger);
$locationService = new ChargingLocationService(logger: $logger);

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list_tariffs';

        if ($action === 'receipt_candidates') {
            $chargeId = filter_var($_GET['charge_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$chargeId) {
                Auth::sendJsonError(400, 'charge_id erforderlich');
            }

            $searchQuery = !empty($_GET['query']) ? trim((string)$_GET['query']) : null;
            $candidates = $receiptService->findCandidatesForCharge($chargeId, $searchQuery);

            echo json_encode(['success' => true, 'candidates' => $candidates]);
            exit;
        }

        if ($action === 'list_tariffs') {
            $tariffs = $tariffService->getAllTariffs();
            echo json_encode(['success' => true, 'tariffs' => $tariffs]);
            exit;
        }

        if ($action === 'suggest_tariff') {
            $chargeId = filter_var($_GET['charge_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$chargeId) {
                Auth::sendJsonError(400, 'charge_id erforderlich');
            }

            $charge = $chargeRepo->getCharge($chargeId);
            if (!$charge) {
                Auth::sendJsonError(404, 'Ladevorgang nicht gefunden');
            }

            $matchedTariff = $tariffService->findMatchingTariff($charge['station_operator'] ?? null);
            $estimatedCost = null;

            if ($matchedTariff) {
                $estimatedCost = $tariffService->calculateCost(
                    $matchedTariff,
                    (float)$charge['charged_net_kwh'],
                    $charge['charge_mode'] ?? 'DC',
                    (int)$charge['duration_min']
                );
            }

            echo json_encode([
                'success' => true,
                'tariff' => $matchedTariff,
                'estimated_cost' => $estimatedCost,
                'tariffs' => $tariffService->getAllTariffs(),
            ]);
            exit;
        }

        Auth::sendJsonError(400, 'Unbekannte GET-Aktion');
    }

    if ($method === 'POST') {
        // Schreibberechtigung erforderlich
        Auth::requireApi('car_write');

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            Auth::sendJsonError(400, 'Ungültige JSON-Anfrage');
        }
        Auth::requireCsrfToken($input);

        $action = $input['action'] ?? '';

        if ($action === 'link_receipt') {
            $chargeId = filter_var($input['charge_id'] ?? null, FILTER_VALIDATE_INT);
            $receiptId = filter_var($input['receipt_id'] ?? null, FILTER_VALIDATE_INT);
            $customCost = isset($input['cost_eur']) && is_numeric($input['cost_eur']) ? (float)$input['cost_eur'] : null;
            $customNote = !empty($input['note']) ? trim((string)$input['note']) : null;

            if (!$chargeId || !$receiptId) {
                Auth::sendJsonError(400, 'charge_id und receipt_id erforderlich');
            }

            $ok = $receiptService->linkReceiptToCharge($chargeId, $receiptId, $customCost, $customNote);
            if (!$ok) {
                Auth::sendJsonError(400, 'Verknüpfung fehlgeschlagen');
            }

            $updated = $chargeRepo->getCharge($chargeId);
            echo json_encode(['success' => true, 'charge' => $updated]);
            exit;
        }

        if ($action === 'unlink_receipt') {
            $chargeId = filter_var($input['charge_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$chargeId) {
                Auth::sendJsonError(400, 'charge_id erforderlich');
            }

            $ok = $receiptService->unlinkReceipt($chargeId);
            if (!$ok) {
                Auth::sendJsonError(400, 'Entkopplung fehlgeschlagen');
            }

            $updated = $chargeRepo->getCharge($chargeId);
            echo json_encode(['success' => true, 'charge' => $updated]);
            exit;
        }

        if ($action === 'apply_tariff') {
            $chargeId = filter_var($input['charge_id'] ?? null, FILTER_VALIDATE_INT);
            $tariffId = filter_var($input['tariff_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$chargeId || !$tariffId) {
                Auth::sendJsonError(400, 'charge_id und tariff_id erforderlich');
            }

            $charge = $chargeRepo->getCharge($chargeId);
            if (!$charge) {
                Auth::sendJsonError(404, 'Ladevorgang nicht gefunden');
            }

            $tariff = $tariffService->getTariff($tariffId);
            if (!$tariff) {
                Auth::sendJsonError(404, 'Tarif nicht gefunden');
            }

            $calculatedCost = $tariffService->calculateCost(
                $tariff,
                (float)$charge['charged_net_kwh'],
                $charge['charge_mode'] ?? 'DC',
                (int)$charge['duration_min']
            );

            $ok = $chargeRepo->updateChargeCost($chargeId, $calculatedCost, $tariff['name']);
            if (!$ok) {
                Auth::sendJsonError(500, 'Fehler beim Speichern der Kosten');
            }

            $updated = $chargeRepo->getCharge($chargeId);
            echo json_encode(['success' => true, 'charge' => $updated, 'cost' => $calculatedCost]);
            exit;
        }

        if ($action === 'set_cost') {
            $chargeId = filter_var($input['charge_id'] ?? null, FILTER_VALIDATE_INT);
            $costEur = filter_var($input['cost_eur'] ?? null, FILTER_VALIDATE_FLOAT);
            $category = !empty($input['tariff_category']) ? trim((string)$input['tariff_category']) : null;

            if (!$chargeId || $costEur === false || $costEur === null) {
                Auth::sendJsonError(400, 'charge_id und cost_eur erforderlich');
            }

            $ok = $chargeRepo->updateChargeCost($chargeId, (float)$costEur, $category);
            if (!$ok) {
                Auth::sendJsonError(500, 'Fehler beim Speichern der Kosten');
            }

            $updated = $chargeRepo->getCharge($chargeId);
            echo json_encode(['success' => true, 'charge' => $updated]);
            exit;
        }

        if ($action === 'resolve_location') {
            $chargeId = filter_var($input['charge_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$chargeId) {
                Auth::sendJsonError(400, 'charge_id erforderlich');
            }

            $charge = $chargeRepo->getCharge($chargeId);
            if (!$charge) {
                Auth::sendJsonError(404, 'Ladevorgang nicht gefunden');
            }

            if (empty($charge['lat']) || empty($charge['lon'])) {
                Auth::sendJsonError(400, 'Ladevorgang besitzt keine GPS-Koordinaten');
            }

            $resolved = $locationService->resolveStation(
                (float)$charge['lat'],
                (float)$charge['lon'],
                $charge['charge_mode'] ?? null
            );

            if ($resolved && (!empty($resolved['station_name']) || !empty($resolved['station_operator']))) {
                $chargeRepo->updateChargeLocation(
                    $chargeId,
                    $resolved['station_name'],
                    $resolved['station_operator']
                );
            }

            $updated = $chargeRepo->getCharge($chargeId);
            echo json_encode([
                'success' => true,
                'charge' => $updated,
                'resolved' => $resolved,
            ]);
            exit;
        }

        if ($action === 'save_tariff') {
            $name = trim((string)($input['name'] ?? ''));
            if ($name === '') {
                Auth::sendJsonError(400, 'Tarifname ist erforderlich');
            }

            $tariffId = $tariffService->saveTariff($input);
            $tariffs = $tariffService->getAllTariffs();

            echo json_encode(['success' => true, 'tariff_id' => $tariffId, 'tariffs' => $tariffs]);
            exit;
        }

        if ($action === 'delete_tariff') {
            $tariffId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$tariffId) {
                Auth::sendJsonError(400, 'Ungültige Tarif-ID');
            }

            $ok = $tariffService->deleteTariff($tariffId);
            $tariffs = $tariffService->getAllTariffs();

            echo json_encode(['success' => $ok, 'tariffs' => $tariffs]);
            exit;
        }

        if ($action === 'reset_tariffs') {
            $tariffs = $tariffService->resetToDefaultTariffs();
            echo json_encode(['success' => true, 'tariffs' => $tariffs]);
            exit;
        }

        Auth::sendJsonError(400, 'Unbekannte POST-Aktion');
    }

    Auth::sendJsonError(405, 'Methode nicht erlaubt');
} catch (\Throwable $e) {
    $logger->error('charges_api.php: Interner Fehler', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler bei der Verarbeitung');
}
