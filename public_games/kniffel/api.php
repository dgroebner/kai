<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\GamesHub\KniffelService;
use Kai\Tools\GamesHub\RoomRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$repo = new RoomRepository();
$kniffel = new KniffelService();
$rateLimiter = new RateLimiter();
$logger = new Logger();

$action = trim((string)($_GET['action'] ?? ''));
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = [];
}

$playerToken = trim((string)($_SERVER['HTTP_X_PLAYER_TOKEN'] ?? ''));
$playerTokenHash = $playerToken !== '' ? hash('sha256', $playerToken) : '';

try {
    // -------------------------------------------------------------------------
    // 1. RAUM ERSTELLEN (mit PIN & Brute-Force-Schutz)
    // -------------------------------------------------------------------------
    if ($action === 'create') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $actionKey = 'create_room';
        if ($rateLimiter->isBlocked($actionKey)) {
            $rem = $rateLimiter->getRemainingLockSeconds($actionKey);
            $mins = ceil($rem / 60);
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => "Zu viele Fehlversuche. Bitte warte {$mins} Minuten."]);
            exit;
        }

        $configuredPin = (string)($_ENV['GAMES_HOST_PIN'] ?? '123456');
        $inputPin = trim((string)($input['pin'] ?? ''));

        if (!hash_equals($configuredPin, $inputPin)) {
            $rateLimiter->recordFailure($actionKey, 5, 15);
            $logger->warn('Quarxl Kniffel: Falscher Host-PIN eingegeben.', ['ip_hash' => $rateLimiter->getAnonymizedIpHash()]);
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Falscher Tisch-PIN. Bitte prüfe deine Eingabe.']);
            exit;
        }

        $rateLimiter->recordSuccess($actionKey);

        $playerName = trim((string)($input['player_name'] ?? 'Host'));
        if ($playerName === '') $playerName = 'Host';
        $playerName = mb_substr($playerName, 0, 30);

        $gameMode = ($input['game_mode'] ?? '') === 'sheet_only' ? 'sheet_only' : 'digital_dice';

        // Host Player Token
        $newPlayerToken = bin2hex(random_bytes(16));
        $newHostHash = hash('sha256', $newPlayerToken);

        $initialState = $kniffel->getInitialState($gameMode);
        $room = $repo->createRoom('kniffel', $gameMode, $newHostHash, $initialState);

        if (!$room) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Konnte Raum nicht erstellen.']);
            exit;
        }

        $initialSheet = $kniffel->getInitialSheet();
        $player = $repo->joinPlayer((int)$room['id'], $newHostHash, $playerName, true, $initialSheet);

        echo json_encode([
            'success' => true,
            'room_code' => $room['room_code'],
            'player_token' => $newPlayerToken,
            'player_id' => $player['id'],
            'is_host' => true,
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 2. RAUM BEITRETEN (für Gäste via Link oder Code)
    // -------------------------------------------------------------------------
    if ($action === 'join') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Nur POST erlaubt']);
            exit;
        }

        $roomCode = strtoupper(trim((string)($input['room_code'] ?? '')));
        $playerName = trim((string)($input['player_name'] ?? 'Gast'));
        if ($playerName === '') $playerName = 'Gast';
        $playerName = mb_substr($playerName, 0, 30);

        $room = $repo->getRoomByCode($roomCode);
        if (!$room) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Raum nicht gefunden.']);
            exit;
        }

        $currentToken = $playerToken !== '' ? $playerToken : bin2hex(random_bytes(16));
        $currentHash = hash('sha256', $currentToken);

        $isHost = hash_equals((string)$room['host_token_hash'], $currentHash);
        $initialSheet = $kniffel->getInitialSheet();
        $player = $repo->joinPlayer((int)$room['id'], $currentHash, $playerName, $isHost, $initialSheet);

        echo json_encode([
            'success' => true,
            'room_code' => $room['room_code'],
            'player_token' => $currentToken,
            'player_id' => $player['id'],
            'is_host' => (bool)$player['is_host'],
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // AB HIER: Erfordert gültigen Raum & identifizierten Spieler
    // -------------------------------------------------------------------------
    $roomCode = strtoupper(trim((string)($_GET['room'] ?? $input['room_code'] ?? '')));
    $room = $repo->getRoomByCode($roomCode);
    if (!$room) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Raum nicht gefunden']);
        exit;
    }

    $roomId = (int)$room['id'];
    $player = $repo->getPlayerByToken($roomId, $playerTokenHash);
    if (!$player) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Nicht autorisiert oder nicht im Raum']);
        exit;
    }

    // -------------------------------------------------------------------------
    // 3. POLLING: STATE ABRUFEN
    // -------------------------------------------------------------------------
    if ($action === 'state') {
        $players = $repo->getPlayersForRoom($roomId);

        // Falls aktiver Raum noch keine active_player_id hat, reparieren
        if ($room['status'] === 'active' && !empty($players)) {
            $state = $room['state'];
            $activePlayerId = (int)($state['active_player_id'] ?? 0);
            if ($activePlayerId === 0) {
                $activeIdx = (int)($state['active_player_index'] ?? 0);
                $chosen = $players[$activeIdx] ?? $players[0];
                $state['active_player_id'] = (int)$chosen['id'];
                $repo->updateRoomState($roomId, $state);
                $room['state'] = $state;
            }
        }

        $activePlayerId = (int)($room['state']['active_player_id'] ?? 0);
        $playersData = [];
        foreach ($players as $p) {
            $isMe = ((int)$p['id'] === (int)$player['id']);
            $isCurActive = ((int)$p['id'] === $activePlayerId);
            $pScore = $kniffel->calculateTotalScore($p['sheet']);

            $playersData[] = [
                'id' => (int)$p['id'],
                'name' => $p['player_name'],
                'is_host' => (bool)$p['is_host'],
                'is_me' => $isMe,
                'is_active' => $isCurActive,
                'total_score' => $pScore['total'],
                'upper_sum' => $pScore['upper_sum'],
                'bonus_reached' => $pScore['bonus_reached'],
                'filled_count' => $pScore['filled_count'],
                'sheet' => ($isMe || $room['status'] === 'finished') ? $p['sheet'] : null,
            ];
        }

        // Punktevorschau für den aktuellen Wurf berechnen
        $dice = $room['state']['dice'] ?? [1, 1, 1, 1, 1];
        $rollCount = (int)($room['state']['roll_count'] ?? 0);
        $previews = ($rollCount > 0) ? $kniffel->calculatePreviews($dice) : [];

        $myScore = $kniffel->calculateTotalScore($player['sheet']);

        echo json_encode([
            'success' => true,
            'room' => [
                'code' => $room['room_code'],
                'mode' => $room['game_mode'],
                'status' => $room['status'],
                'state_version' => (int)$room['state_version'],
                'state' => $room['state'],
            ],
            'me' => [
                'id' => (int)$player['id'],
                'name' => $player['player_name'],
                'is_host' => (bool)$player['is_host'],
                'sheet' => $player['sheet'],
                'score_summary' => $myScore,
            ],
            'players' => $playersData,
            'previews' => $previews,
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 4. SPIEL STARTEN (Host only)
    // -------------------------------------------------------------------------
    if ($action === 'start') {
        if (!$player['is_host']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Nur der Host kann das Spiel starten']);
            exit;
        }

        $players = $repo->getPlayersForRoom($roomId);
        $playerCount = count($players);
        if ($playerCount === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Keine Spieler im Raum']);
            exit;
        }

        $chosenIdx = random_int(0, $playerCount - 1);
        $startPlayer = $players[$chosenIdx];

        $state = $room['state'];
        $state['active_player_index'] = $chosenIdx;
        $state['active_player_id'] = (int)$startPlayer['id'];
        $state['round_number'] = 1;
        $state['roll_count'] = 0;
        $state['dice'] = [1, 1, 1, 1, 1];
        $state['held'] = [false, false, false, false, false];

        $repo->updateRoomState($roomId, $state, 'active');
        echo json_encode(['success' => true]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 5. WÜRFELN (Aktiver Spieler, max. 3 Würfe pro Zug)
    // -------------------------------------------------------------------------
    if ($action === 'roll') {
        $players = $repo->getPlayersForRoom($roomId);
        $state = $room['state'];
        $activePlayerId = (int)($state['active_player_id'] ?? 0);

        if ($activePlayerId === 0) {
            $activeIdx = (int)($state['active_player_index'] ?? 0);
            $activePlayerId = (int)($players[$activeIdx]['id'] ?? ($players[0]['id'] ?? 0));
            $state['active_player_id'] = $activePlayerId;
        }

        if ($activePlayerId !== (int)$player['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Du bist aktuell nicht an der Reihe']);
            exit;
        }

        $rollCount = (int)($state['roll_count'] ?? 0);
        if ($rollCount >= 3) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Maximale Wurfanzahl (3) erreicht. Bitte wähle ein Feld auf deinem Zettel.']);
            exit;
        }

        $currentDice = $state['dice'] ?? [1, 1, 1, 1, 1];
        $held = $state['held'] ?? [false, false, false, false, false];

        // Wenn noch gar nicht gewürfelt wurde, alle neu werfen
        if ($rollCount === 0) {
            $held = [false, false, false, false, false];
        }

        $newDice = $kniffel->rollDice($currentDice, $held);
        $state['dice'] = $newDice;
        $state['held'] = $held;
        $state['roll_count'] = $rollCount + 1;
        $state['last_roll_time'] = time();

        $repo->updateRoomState($roomId, $state);
        $previews = $kniffel->calculatePreviews($newDice);

        echo json_encode([
            'success' => true,
            'dice' => $newDice,
            'held' => $held,
            'roll_count' => $state['roll_count'],
            'previews' => $previews,
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 6. WÜRFEL HALTEN / FREIGEBEN (Toggle Hold)
    // -------------------------------------------------------------------------
    if ($action === 'toggle_hold') {
        $state = $room['state'];
        $activePlayerId = (int)($state['active_player_id'] ?? 0);

        if ($activePlayerId !== (int)$player['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Du bist aktuell nicht an der Reihe']);
            exit;
        }

        $rollCount = (int)($state['roll_count'] ?? 0);
        if ($rollCount === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Es muss zuerst gewürfelt werden.']);
            exit;
        }

        $index = filter_var($input['index'] ?? null, FILTER_VALIDATE_INT);
        if ($index === false || $index < 0 || $index > 4) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ungültiger Würfel-Index']);
            exit;
        }

        $held = $state['held'] ?? [false, false, false, false, false];
        $held[$index] = !($held[$index] ?? false);
        $state['held'] = $held;

        $repo->updateRoomState($roomId, $state);
        echo json_encode(['success' => true, 'held' => $held]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 7. FELD AUSWÄHLEN / EINTRAGEN (Score category)
    // -------------------------------------------------------------------------
    if ($action === 'score') {
        $category = trim((string)($input['category'] ?? ''));
        $state = $room['state'];
        $activePlayerId = (int)($state['active_player_id'] ?? 0);

        if ($activePlayerId !== (int)$player['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Du bist aktuell nicht an der Reihe']);
            exit;
        }

        $rollCount = (int)($state['roll_count'] ?? 0);
        if ($room['game_mode'] === 'digital_dice' && $rollCount === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Du musst zuerst mindestens einmal würfeln']);
            exit;
        }

        $sheet = $player['sheet'];
        $dice = $state['dice'] ?? [1, 1, 1, 1, 1];
        $applied = $kniffel->applyScore($sheet, $category, $dice, $room['game_mode'], (int)($input['score'] ?? 0));

        if (!$applied) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Dieses Feld ist bereits belegt oder ungültig']);
            exit;
        }

        $myCalc = $kniffel->calculateTotalScore($sheet);
        $repo->updatePlayerSheet((int)$player['id'], $sheet, $myCalc['total']);

        // Prüfen, ob nach diesem Zug alle Spieler alle 13 Felder belegt haben
        $players = $repo->getPlayersForRoom($roomId);
        $gameEnded = $kniffel->checkGameEnd($players);

        if ($gameEnded) {
            $state['game_end_reason'] = 'all_rounds_completed';
            $repo->updateRoomState($roomId, $state, 'finished');
            echo json_encode([
                'success' => true,
                'game_finished' => true,
                'sheet' => $sheet,
                'score_summary' => $myCalc,
            ]);
            exit;
        }

        // Nächster Spieler ist an der Reihe
        $playerIds = array_map(fn($p) => (int)$p['id'], $players);
        $currPos = array_search($activePlayerId, $playerIds, true);
        if ($currPos === false) {
            $currPos = 0;
        }
        $nextPos = ($currPos + 1) % count($playerIds);
        $nextPlayerId = $playerIds[$nextPos];

        $state['active_player_index'] = $nextPos;
        $state['active_player_id'] = $nextPlayerId;
        $state['roll_count'] = 0;
        $state['held'] = [false, false, false, false, false];
        if ($nextPos === 0) {
            $state['round_number'] = ((int)($state['round_number'] ?? 1)) + 1;
        }

        $repo->updateRoomState($roomId, $state);

        echo json_encode([
            'success' => true,
            'game_finished' => false,
            'sheet' => $sheet,
            'score_summary' => $myCalc,
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 8. REVANCHE / NEUE RUNDE IM SELBEN RAUM (Host only)
    // -------------------------------------------------------------------------
    if ($action === 'rematch') {
        if (!$player['is_host']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Nur der Host kann eine neue Runde starten']);
            exit;
        }

        $players = $repo->getPlayersForRoom($roomId);
        $playerCount = count($players);
        $initialState = $kniffel->getInitialState($room['game_mode']);
        if ($playerCount > 0) {
            $chosenIdx = random_int(0, $playerCount - 1);
            $initialState['active_player_index'] = $chosenIdx;
            $initialState['active_player_id'] = (int)$players[$chosenIdx]['id'];
        }

        foreach ($players as $p) {
            $emptySheet = $kniffel->getInitialSheet();
            $repo->updatePlayerSheet((int)$p['id'], $emptySheet, 0);
        }

        $repo->updateRoomState($roomId, $initialState, 'active');
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion']);
} catch (\Throwable $e) {
    $logger->error('Quarxl Kniffel API Fehler', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Interner Serverfehler']);
}
