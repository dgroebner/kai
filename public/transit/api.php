<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\Transit\TransitProfileRepository;
use Kai\Tools\Transit\TransitService;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check — immer zuerst
Auth::requireApi('transit_read');

// 2. HTTP-Methoden-Check
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$logger = new Logger();

try {
    $service = new TransitService();
    $profileRepo = new TransitProfileRepository();
    $currentUserEmail = $_SESSION['user_email'] ?? '';

    if ($method === 'GET') {
        $action = trim((string)($_GET['action'] ?? 'status'));

        if ($action === 'status') {
            $profileId = filter_var($_GET['profile_id'] ?? null, FILTER_VALIDATE_INT);
            $direction = isset($_GET['direction']) && in_array($_GET['direction'], ['outbound', 'return'], true) ? $_GET['direction'] : null;
            $offsetMinutes = filter_var($_GET['offset_minutes'] ?? 0, FILTER_VALIDATE_INT);
            if ($offsetMinutes === false) {
                $offsetMinutes = 0;
            }

            if ($profileId) {
                $profile = $profileRepo->getById($profileId);
            } else {
                $active = $profileRepo->getActive($currentUserEmail);
                $profile = $active[0] ?? null;
            }

            if (!$profile) {
                echo json_encode(['success' => false, 'message' => 'Kein Profil gefunden']);
                exit;
            }

            $status = $service->getProfileStatus($profile, $direction, $offsetMinutes);
            echo json_encode(['success' => true, 'data' => $status]);
            exit;
        }

        if ($action === 'station_departures') {
            $stationId = trim((string)($_GET['station_id'] ?? ''));
            $stationName = trim((string)($_GET['station_name'] ?? ''));
            if ($stationId === '') {
                Auth::sendJsonError(400, 'Ungültige Haltestellen-ID');
            }
            $data = $service->getCachedDepartures($stationId, $stationName !== '' ? $stationName : null);
            echo json_encode(['success' => true, 'data' => $data]);
            exit;
        }

        if ($action === 'search_stations') {
            $query = trim((string)($_GET['q'] ?? ''));
            if (mb_strlen($query) < 2) {
                echo json_encode(['success' => true, 'data' => []]);
                exit;
            }
            $results = $service->searchStations($query);
            echo json_encode(['success' => true, 'data' => $results]);
            exit;
        }

        if ($action === 'refresh_routes') {
            $profileId = filter_var($_GET['profile_id'] ?? null, FILTER_VALIDATE_INT);
            if ($profileId) {
                $p = $profileRepo->getById($profileId);
                if ($p) {
                    $routes = $service->getProfileRouteDirections($p, true);
                    echo json_encode(['success' => true, 'data' => $routes]);
                    exit;
                }
            }
            Auth::sendJsonError(400, 'Ungültiges Profil');
        }

        Auth::sendJsonError(400, 'Unbekannte Aktion');
    }

    if ($method === 'POST') {
        Auth::requireApi('transit_write');

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            Auth::sendJsonError(400, 'Ungültige Anfrage');
        }
        Auth::requireCsrfToken($input);

        $action = trim((string)($input['action'] ?? ''));

        if ($action === 'save_profile') {
            $profileData = $input['profile'] ?? null;
            if (!is_array($profileData)) {
                Auth::sendJsonError(400, 'Ungültige Profildaten');
            }

            $savedId = $profileRepo->save($profileData);
            $service->clearProfileCache($savedId);
            $savedProfile = $profileRepo->getById($savedId);
            if ($savedProfile) {
                try {
                    $service->getProfileRouteDirections($savedProfile, true);
                } catch (\Throwable $te) {
                    $logger->warn('Konnte Routencache nach Profil-Speichern nicht sofort aktualisieren', ['error' => $te->getMessage()]);
                }
            }

            echo json_encode(['success' => true, 'data' => ['id' => $savedId]]);
            exit;
        }

        if ($action === 'delete_profile') {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                Auth::sendJsonError(400, 'Ungültige ID');
            }
            $service->clearProfileCache($id);
            $success = $profileRepo->delete($id);
            echo json_encode(['success' => $success]);
            exit;
        }

        Auth::sendJsonError(400, 'Unbekannte POST-Aktion');
    }

    Auth::sendJsonError(405, 'Methode nicht erlaubt');
} catch (Throwable $e) {
    $logger->error('public/transit/api.php: Fehler aufgetreten.', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Serverfehler');
}
