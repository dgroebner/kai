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

        $isAdmin = Auth::isAdmin();

        if ($action === 'status') {
            $profileId = filter_var($_GET['profile_id'] ?? null, FILTER_VALIDATE_INT);
            $direction = isset($_GET['direction']) && in_array($_GET['direction'], ['outbound', 'return'], true) ? $_GET['direction'] : null;
            $offsetMinutes = filter_var($_GET['offset_minutes'] ?? 0, FILTER_VALIDATE_INT);
            if ($offsetMinutes === false) {
                $offsetMinutes = 0;
            }
            $includeTrips = isset($_GET['include_trips']) ? (bool)filter_var($_GET['include_trips'], FILTER_VALIDATE_BOOLEAN) : false;

            if ($profileId) {
                $profile = $profileRepo->getById($profileId);
                if ($profile && !$isAdmin && strtolower((string)($profile['user_email'] ?? '')) !== $currentUserEmail) {
                    $profile = null;
                }
            } else {
                $active = $isAdmin ? $profileRepo->getActive(null) : $profileRepo->getActive($currentUserEmail, true);
                $profile = $active[0] ?? null;
            }

            if (!$profile) {
                echo json_encode(['success' => false, 'message' => 'Kein Profil gefunden']);
                exit;
            }

            $status = $service->getProfileStatus($profile, $direction, $offsetMinutes, $includeTrips);
            $status['profile'] = $profile;
            echo json_encode(['success' => true, 'data' => $status]);
            exit;
        }

        if ($action === 'get_profile') {
            $profileId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$profileId) {
                Auth::sendJsonError(400, 'Ungültige Profil-ID');
            }

            $profile = $profileRepo->getById($profileId);
            if ($profile && !$isAdmin && strtolower((string)($profile['user_email'] ?? '')) !== $currentUserEmail) {
                $profile = null;
            }

            if (!$profile) {
                Auth::sendJsonError(404, 'Profil nicht gefunden');
            }

            echo json_encode(['success' => true, 'data' => $profile]);
            exit;
        }

        if ($action === 'trips' || $action === 'trip_recommendations') {
            $profileId = filter_var($_GET['profile_id'] ?? null, FILTER_VALIDATE_INT);
            $direction = isset($_GET['direction']) && in_array($_GET['direction'], ['outbound', 'return'], true) ? $_GET['direction'] : null;

            if ($profileId) {
                $profile = $profileRepo->getById($profileId);
                if ($profile && !$isAdmin && strtolower((string)($profile['user_email'] ?? '')) !== $currentUserEmail) {
                    $profile = null;
                }
            } else {
                $active = $isAdmin ? $profileRepo->getActive(null) : $profileRepo->getActive($currentUserEmail, true);
                $profile = $active[0] ?? null;
            }

            if (!$profile) {
                echo json_encode(['success' => false, 'message' => 'Kein Profil gefunden']);
                exit;
            }

            $trips = $service->getProfileTripRecommendations($profile, $direction);
            echo json_encode(['success' => true, 'data' => $trips]);
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

        $isAdmin = Auth::isAdmin();

        if ($action === 'save_profile') {
            $profileData = $input['profile'] ?? null;
            if (!is_array($profileData)) {
                Auth::sendJsonError(400, 'Ungültige Profildaten');
            }

            $profileId = !empty($profileData['id']) ? (int)$profileData['id'] : null;

            if (!$isAdmin) {
                // Normale Nutzer erstellen immer Profile für sich selbst
                $profileData['user_email'] = $currentUserEmail;

                // Falls bestehendes Profil aktualisiert wird: prüfen, ob es dem Nutzer gehört
                if ($profileId !== null) {
                    $existing = $profileRepo->getById($profileId);
                    if (!$existing || strtolower((string)($existing['user_email'] ?? '')) !== $currentUserEmail) {
                        Auth::sendJsonError(403, 'Keine Berechtigung zum Bearbeiten dieses Profils');
                    }
                }
            } else {
                // Admin darf Profile beliebig zuweisen oder auf null setzen
                $profileData['user_email'] = !empty($profileData['user_email']) ? strtolower(trim((string)$profileData['user_email'])) : null;
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

            if (!$isAdmin) {
                $existing = $profileRepo->getById($id);
                if (!$existing || strtolower((string)($existing['user_email'] ?? '')) !== $currentUserEmail) {
                    Auth::sendJsonError(403, 'Keine Berechtigung zum Löschen dieses Profils');
                }
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
