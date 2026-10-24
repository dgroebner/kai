<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\GamesHub\QwixxService;
use Kai\Tools\GamesHub\RoomRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$repo = new RoomRepository();
$qwixx = new QwixxService();
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
            $logger->warn('GamesHub: Falscher Host-PIN eingegeben.', ['ip_hash' => $rateLimiter->getAnonymizedIpHash()]);
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

        $initialState = $qwixx->getInitialState($gameMode);
        $room = $repo->createRoom('qwixx', $gameMode, $newHostHash, $initialState);

        if (!$room) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Konnte Raum nicht erstellen.']);
            exit;
        }

        $initialSheet = $qwixx->getInitialSheet();
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

        // Falls noch kein Token vorhanden, neues erzeugen
        $currentToken = $playerToken !== '' ? $playerToken : bin2hex(random_bytes(16));
        $currentHash = hash('sha256', $currentToken);

        $isHost = hash_equals((string)$room['host_token_hash'], $currentHash);
        $initialSheet = $qwixx->getInitialSheet();
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

        // Sanierte Übersicht der Mitspieler (andere Spieler sehen nur die Kreuzzahl, nicht jedes einzelne Feld)
        $playersData = [];
        foreach ($players as $p) {
            $isMe = ((int)$p['id'] === (int)$player['id']);
            $pScore = $qwixx->calculateTotalScore($p['sheet']);

            $summaryRows = [];
            foreach (QwixxService::ROWS as $col) {
                $cCount = count($p['sheet']['rows'][$col]['crossed'] ?? []);
                if (!empty($p['sheet']['rows'][$col]['locked'])) $cCount++;
                $summaryRows[$col] = $cCount;
            }

            $playersData[] = [
                'id' => (int)$p['id'],
                'name' => $p['player_name'],
                'is_host' => (bool)$p['is_host'],
                'is_me' => $isMe,
                'missed' => (int)($p['sheet']['missed'] ?? 0),
                'rows_count' => $summaryRows,
                'total_score' => $pScore['total'],
                'turn_confirmed' => !empty($p['sheet']['turn_confirmed']),
                // Eigenen Spielzettel vollständig mitliefern, andere nur am Spielende
                'sheet' => ($isMe || $room['status'] === 'finished') ? $p['sheet'] : null,
            ];
        }

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
            ],
            'players' => $playersData,
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
        $state['turn_phase'] = 'roll';
        $state['active_player_index'] = $chosenIdx;
        $state['active_player_id'] = (int)$startPlayer['id'];

        $repo->updateRoomState($roomId, $state, 'active');
        echo json_encode(['success' => true]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 5. WÜRFELN (Aktiver Spieler im Modus digital_dice)
    // -------------------------------------------------------------------------
    if ($action === 'roll') {
        $players = $repo->getPlayersForRoom($roomId);
        $state = $room['state'];
        $activePlayerId = (int)($state['active_player_id'] ?? 0);

        if ($activePlayerId === 0) {
            $activeIdx = (int)($state['active_player_index'] ?? 0);
            $activePlayerId = (int)($players[$activeIdx]['id'] ?? 0);
        }

        if ($activePlayerId !== (int)$player['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Du bist aktuell nicht mit Würfeln an der Reihe']);
            exit;
        }

        $closedRows = $state['closed_rows'] ?? [];
        $currentDice = $state['dice'] ?? [];
        $newDice = $qwixx->rollDice($currentDice, $closedRows);

        $state['dice'] = $newDice;
        $state['turn_phase'] = 'action';
        $state['last_roll_time'] = time();

        // Alle Spieler-Bestätigungen für die neue Runde zurücksetzen
        foreach ($players as $p) {
            $sheet = $p['sheet'];
            $sheet['turn_confirmed'] = false;
            $sheet['white_done'] = false;
            $sheet['color_done'] = false;
            $sheet['crossed_in_turn'] = 0;
            $repo->updatePlayerSheet((int)$p['id'], $sheet, (int)$p['total_score']);
        }

        $repo->updateRoomState($roomId, $state);
        echo json_encode(['success' => true, 'dice' => $newDice]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 6. ZAHL ANKREUZEN (Regelprüfung + Schloss)
    // -------------------------------------------------------------------------
    if ($action === 'cross') {
        $color = (string)($input['color'] ?? '');
        $number = (int)($input['number'] ?? 0);

        $state = $room['state'];
        $closedRows = $state['closed_rows'] ?? [];
        $sheet = $player['sheet'];

        $players = $repo->getPlayersForRoom($roomId);
        $activeIdx = (int)($state['active_player_index'] ?? 0);
        $activePlayer = $players[$activeIdx] ?? null;
        $isActivePlayer = ($activePlayer && (int)$activePlayer['id'] === (int)$player['id']);

        // Ermitteln, in welchem Schritt wir sind:
        // Passiver Spieler: darf NUR weiße Summe ankreuzen (einmal)
        // Aktiver Spieler:
        // - Wenn white_done false ist, darf er zuerst die weiße Summe ankreuzen
        // - Wenn er bereits weiß hat oder darauf verzichtet hat (white_done true), darf er nur Farbkombination ankreuzen
        $step = 'any';
        if ($room['game_mode'] === 'digital_dice') {
            // Im digitalen Modus MUSS zuerst gewürfelt worden sein!
            $phase = $state['turn_phase'] ?? 'roll';
            if ($phase === 'roll') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Es muss zuerst gewürfelt werden, bevor angekreuzt werden darf']);
                exit;
            }

            if (!$isActivePlayer) {
                if (!empty($sheet['white_done'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Du hast in dieser Runde bereits die weiße Summe genutzt']);
                    exit;
                }
                $step = 'white';
            } else {
                if (!empty($sheet['color_done'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Du hast deine Farbkombination in diesem Zug bereits gesetzt']);
                    exit;
                }
                if (empty($sheet['white_done'])) {
                    // Er ist aktiv und hat noch nicht weiß gemacht.
                    // Er darf jetzt die weiße Summe ankreuzen ODER (falls er auf weiß verzichtet) direkt eine Farbe
                    $wSum = (int)($state['dice']['white1'] ?? 1) + (int)($state['dice']['white2'] ?? 1);
                    if ($number === $wSum) {
                        $step = 'white';
                    } else {
                        // Verzichtet auf weiß und geht direkt zu Farbe
                        $sheet['white_done'] = true;
                        $step = 'color';
                    }
                } else {
                    $step = 'color';
                }
            }
        }

        $dice = ($room['game_mode'] === 'digital_dice') ? ($state['dice'] ?? null) : null;
        $check = $qwixx->canCrossNumber($sheet, $color, $number, $closedRows, $room['game_mode'], $dice, $isActivePlayer, $step);
        if (!$check['allowed']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $check['reason']]);
            exit;
        }

        $rowLockedBefore = !empty($sheet['rows'][$color]['locked']);
        $applied = $qwixx->applyCross($sheet, $color, $number, $closedRows, $room['game_mode'], $dice, $isActivePlayer, $step);

        if (!$applied) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Konnte Kreuz nicht setzen']);
            exit;
        }

        // Falls durch dieses Kreuz die Reihe abgeschlossen wurde: global in room['state'] sperren!
        $newClosedCount = count($closedRows);
        if ($newClosedCount !== count($state['closed_rows'] ?? [])) {
            $state['closed_rows'] = $closedRows;
        }

        // Punktestand berechnen
        $calc = $qwixx->calculateTotalScore($sheet);
        $repo->updatePlayerSheet((int)$player['id'], $sheet, $calc['total']);

        // Spielende prüfen
        $players = $repo->getPlayersForRoom($roomId);
        $endReason = $qwixx->checkGameEnd($closedRows, $players);
        $newStatus = null;
        if ($endReason !== null) {
            $state['game_end_reason'] = $endReason;
            $newStatus = 'finished';
        }

        $repo->updateRoomState($roomId, $state, $newStatus);

        echo json_encode([
            'success' => true,
            'sheet' => $sheet,
            'total_score' => $calc['total'],
            'row_closed' => !empty($sheet['rows'][$color]['locked']) && !$rowLockedBefore,
            'game_finished' => $newStatus === 'finished',
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 6b. VERZICHT AUF WEISSE SUMME (Passieren für passiven oder aktiven Spieler)
    // -------------------------------------------------------------------------
    if ($action === 'pass_white') {
        $sheet = $player['sheet'];
        $sheet['white_done'] = true;
        $repo->updatePlayerSheet((int)$player['id'], $sheet, (int)$player['total_score']);
        echo json_encode(['success' => true, 'sheet' => $sheet]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 7. FEHLWURF EINGEHEN / ZUG BEENDEN
    // -------------------------------------------------------------------------
    if ($action === 'missed') {
        $sheet = $player['sheet'];
        $missed = (int)($sheet['missed'] ?? 0);
        if ($missed < 4) {
            $sheet['missed'] = $missed + 1;
        }

        $calc = $qwixx->calculateTotalScore($sheet);
        $repo->updatePlayerSheet((int)$player['id'], $sheet, $calc['total']);

        $state = $room['state'];
        $closedRows = $state['closed_rows'] ?? [];
        $players = $repo->getPlayersForRoom($roomId);
        $endReason = $qwixx->checkGameEnd($closedRows, $players);
        $newStatus = null;
        if ($endReason !== null) {
            $state['game_end_reason'] = $endReason;
            $newStatus = 'finished';
        }

        $repo->updateRoomState($roomId, $state, $newStatus);

        echo json_encode([
            'success' => true,
            'sheet' => $sheet,
            'total_score' => $calc['total'],
            'game_finished' => $newStatus === 'finished',
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 8. ZUG ABSCHLIESSEN (Nächster Spieler dran)
    // -------------------------------------------------------------------------
    if ($action === 'next_turn') {
        $players = $repo->getPlayersForRoom($roomId);
        $state = $room['state'];
        $activePlayerId = (int)($state['active_player_id'] ?? 0);

        if ($activePlayerId === 0) {
            $activeIdx = (int)($state['active_player_index'] ?? 0);
            $activePlayerId = (int)($players[$activeIdx]['id'] ?? 0);
        }

        if ($activePlayerId !== (int)$player['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Nur der aktive Spieler kann seinen Zug abschließen']);
            exit;
        }

        // Qwixx-Regel: Hat der aktive Spieler weder bei weiß noch bei Farbe etwas angekreuzt?
        // -> Automatischer Fehlwurf (-5 Punkte) für den aktiven Spieler!
        $crossedInTurn = (int)($player['sheet']['crossed_in_turn'] ?? 0);
        if ($room['game_mode'] === 'digital_dice' && $crossedInTurn === 0) {
            $sheet = $player['sheet'];
            $missed = (int)($sheet['missed'] ?? 0);
            if ($missed < 4) {
                $sheet['missed'] = $missed + 1;
            }
            $calc = $qwixx->calculateTotalScore($sheet);
            $repo->updatePlayerSheet((int)$player['id'], $sheet, $calc['total']);
        }

        // Nächster Spieler im Uhrzeigersinn anhand der Spieler-ID-Liste
        $playerIds = array_map(fn($p) => (int)$p['id'], $players);
        $currPos = array_search($activePlayerId, $playerIds, true);
        if ($currPos === false) {
            $currPos = 0;
        }
        $nextPos = ($currPos + 1) % count($playerIds);
        $nextPlayerId = $playerIds[$nextPos];

        $state['active_player_index'] = $nextPos;
        $state['active_player_id'] = $nextPlayerId;
        $state['turn_number'] = ((int)($state['turn_number'] ?? 1)) + 1;
        $state['turn_phase'] = 'roll';

        // Bestätigungen & Turn-Zähler für alle zurücksetzen
        foreach ($players as $p) {
            $sheet = $p['sheet'];
            $sheet['turn_confirmed'] = false;
            $sheet['white_done'] = false;
            $sheet['color_done'] = false;
            $sheet['crossed_in_turn'] = 0;
            $repo->updatePlayerSheet((int)$p['id'], $sheet, (int)$p['total_score']);
        }

        $repo->updateRoomState($roomId, $state);
        echo json_encode(['success' => true]);
        exit;
    }

    // -------------------------------------------------------------------------
    // 9. REVANCHE / NEUE RUNDE IM SELBEN RAUM (Host only)
    // -------------------------------------------------------------------------
    if ($action === 'rematch') {
        if (!$player['is_host']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Nur der Host kann eine neue Runde starten']);
            exit;
        }

        $players = $repo->getPlayersForRoom($roomId);
        $playerCount = count($players);
        $initialState = $qwixx->getInitialState($room['game_mode']);
        // Auch bei Revanche zufälligen Startspieler wählen
        if ($playerCount > 0) {
            $chosenIdx = random_int(0, $playerCount - 1);
            $initialState['active_player_index'] = $chosenIdx;
            $initialState['active_player_id'] = (int)$players[$chosenIdx]['id'];
        }

        foreach ($players as $p) {
            $emptySheet = $qwixx->getInitialSheet();
            $repo->updatePlayerSheet((int)$p['id'], $emptySheet, 0);
        }

        $repo->updateRoomState($roomId, $initialState, 'active');
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion']);
} catch (\Throwable $e) {
    $logger->error('GamesHub Qwixx API Fehler', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Interner Serverfehler']);
}
