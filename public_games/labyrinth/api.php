<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\GamesHub\Labyrinth\LabyrinthService;
use Kai\Tools\GamesHub\RoomRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$repo = new RoomRepository();
$service = new LabyrinthService($repo);
$rateLimiter = new RateLimiter();
$logger = new Logger();

$action = trim((string)($_GET['action'] ?? ''));
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$playerToken = trim((string)($_SERVER['HTTP_X_PLAYER_TOKEN'] ?? ''));
$playerTokenHash = $playerToken !== '' ? hash('sha256', $playerToken) : '';

/** Beendet den Request mit einer JSON-Antwort. */
function labyrinthRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Beendet den Request mit 405, falls es kein POST ist. */
function labyrinthRequirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        labyrinthRespond(405, ['success' => false, 'message' => 'Nur POST erlaubt']);
    }
}

/** Normalisiert einen Spielernamen (Länge, Fallback). */
function labyrinthPlayerName(mixed $raw, string $fallback): string
{
    $name = mb_substr(trim((string)$raw), 0, 30);
    return $name === '' ? $fallback : $name;
}

try {
    // -------------------------------------------------------------------------
    // 1. RAUM ERSTELLEN (mit PIN-Schutz)
    // -------------------------------------------------------------------------
    if ($action === 'create') {
        labyrinthRequirePost();

        $actionKey = 'create_labyrinth_room';
        if ($rateLimiter->isBlocked($actionKey)) {
            $mins = (int)ceil($rateLimiter->getRemainingLockSeconds($actionKey) / 60);
            labyrinthRespond(429, ['success' => false, 'message' => "Zu viele Fehlversuche. Bitte warte {$mins} Minuten."]);
        }

        $configuredPin = (string)($_ENV['GAMES_HOST_PIN'] ?? '123456');
        if (!hash_equals($configuredPin, trim((string)($input['pin'] ?? '')))) {
            $rateLimiter->recordFailure($actionKey, 5, 15);
            $logger->warn('Quarxl Labyrinth: Falscher Host-PIN eingegeben.', ['ip_hash' => $rateLimiter->getAnonymizedIpHash()]);
            labyrinthRespond(403, ['success' => false, 'message' => 'Falscher Tisch-PIN. Bitte prüfe deine Eingabe.']);
        }
        $rateLimiter->recordSuccess($actionKey);

        $gameMode = trim((string)($input['game_mode'] ?? 'standard'));
        if (!in_array($gameMode, ['fast', 'standard', 'classic'], true)) {
            $gameMode = 'standard';
        }

        $botDifficulty = trim((string)($input['bot_difficulty'] ?? 'normal'));
        if (!in_array($botDifficulty, ['easy', 'normal', 'master'], true)) {
            $botDifficulty = 'normal';
        }

        $newPlayerToken = bin2hex(random_bytes(16));
        $newHostHash = hash('sha256', $newPlayerToken);
        $room = $repo->createRoom('labyrinth', $gameMode, $newHostHash, $service->getInitialState($gameMode, $botDifficulty));
        if (!$room) {
            labyrinthRespond(500, ['success' => false, 'message' => 'Konnte Raum nicht erstellen.']);
        }

        $player = $repo->joinPlayer((int)$room['id'], $newHostHash, labyrinthPlayerName($input['player_name'] ?? '', 'Host'), true, ['is_bot' => false]);

        labyrinthRespond(200, [
            'success' => true,
            'room_code' => $room['room_code'],
            'player_token' => $newPlayerToken,
            'player_id' => $player['id'],
            'is_host' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // 2. RAUM BEITRETEN
    // -------------------------------------------------------------------------
    if ($action === 'join') {
        labyrinthRequirePost();

        $room = $repo->getRoomByCode(strtoupper(trim((string)($input['room_code'] ?? ''))));
        if (!$room) {
            labyrinthRespond(404, ['success' => false, 'message' => 'Raum nicht gefunden']);
        }
        $roomId = (int)$room['id'];

        if ($playerTokenHash !== '') {
            $existing = $repo->getPlayerByToken($roomId, $playerTokenHash);
            if ($existing) {
                labyrinthRespond(200, [
                    'success' => true,
                    'room_code' => $room['room_code'],
                    'player_token' => $playerToken,
                    'player_id' => $existing['id'],
                    'is_host' => (bool)$existing['is_host'],
                ]);
            }
        }

        if ($room['status'] !== 'lobby') {
            labyrinthRespond(403, ['success' => false, 'message' => 'Das Spiel läuft bereits']);
        }
        if (count($repo->getPlayersForRoom($roomId)) >= LabyrinthService::MAX_PLAYERS) {
            labyrinthRespond(400, ['success' => false, 'message' => 'Tisch ist voll (maximal 4 Spieler)']);
        }

        $newToken = bin2hex(random_bytes(16));
        $player = $repo->joinPlayer($roomId, hash('sha256', $newToken), labyrinthPlayerName($input['player_name'] ?? '', 'Gast'), false, ['is_bot' => false]);

        labyrinthRespond(200, [
            'success' => true,
            'room_code' => $room['room_code'],
            'player_token' => $newToken,
            'player_id' => $player['id'],
            'is_host' => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // VORBEDINGUNG FÜR WEITERE AKTIONEN: Token & Raum validieren
    // -------------------------------------------------------------------------
    $room = $repo->getRoomByCode(strtoupper(trim((string)($input['room_code'] ?? $_GET['room'] ?? ''))));
    if (!$room) {
        labyrinthRespond(404, ['success' => false, 'message' => 'Raum nicht gefunden']);
    }
    $roomId = (int)$room['id'];
    $player = $playerTokenHash !== '' ? $repo->getPlayerByToken($roomId, $playerTokenHash) : null;
    if (!$player) {
        labyrinthRespond(401, ['success' => false, 'message' => 'Nicht autorisiert oder nicht im Raum']);
    }
    $playerId = (int)$player['id'];
    $isHost = !empty($player['is_host']);

    // -------------------------------------------------------------------------
    // 3. STATE ABRUFEN (Zero-Knowledge) & AUTOMATISCHER BOT-ZUG
    // -------------------------------------------------------------------------
    if ($action === 'state') {
        $service->triggerBotTurnIfNeeded($roomId);

        $room = $repo->getRoomById($roomId);
        $publicState = $service->getPublicStateForPlayer($room, $player, $repo->getPlayersForRoom($roomId));
        $publicState['recent_reactions'] = $repo->getRecentReactions($roomId);

        labyrinthRespond(200, ['success' => true, 'data' => $publicState]);
    }

    // -------------------------------------------------------------------------
    // 4. REAKTION SENDEN (Quarxl Reaktions-Smilies)
    // -------------------------------------------------------------------------
    if ($action === 'react') {
        labyrinthRequirePost();
        $res = $repo->addReaction($roomId, $playerId, (string)$player['player_name'], trim((string)($input['emoji'] ?? '')));
        if (empty($res['success'])) {
            http_response_code(($res['error'] ?? '') === 'spam_cooldown' ? 429 : 400);
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------------------
    // 5. HOST-AKTIONEN: Start, Bots, Revanche, Schwierigkeit
    // -------------------------------------------------------------------------
    if (in_array($action, ['start', 'add_bot', 'remove_bot', 'rematch', 'set_bot_difficulty'], true)) {
        labyrinthRequirePost();
        if (!$isHost) {
            labyrinthRespond(403, ['success' => false, 'message' => 'Nur der Host darf das']);
        }

        $res = match ($action) {
            'start' => $service->startGame($roomId),
            'add_bot' => $service->addBot($roomId, ['🤖 Robo-Max', '🤖 Cyber-Ella', '🤖 Bit-Berta', '🤖 Pixel-Paul'][count($repo->getPlayersForRoom($roomId)) % 4]),
            'remove_bot' => $service->removeBot($roomId, (int)($input['bot_id'] ?? 0)),
            'set_bot_difficulty' => $service->setBotDifficulty($roomId, trim((string)($input['bot_difficulty'] ?? 'normal'))),
            default => $service->rematch($roomId),
        };
        labyrinthRespond($res['success'] ? 200 : 400, $res);
    }

    // -------------------------------------------------------------------------
    // 6. SPIELZÜGE: Kachel einschieben / Figur ziehen
    // -------------------------------------------------------------------------
    if ($action === 'shift') {
        labyrinthRequirePost();
        $res = $service->shiftTile(
            $roomId,
            $playerId,
            strtoupper(trim((string)($input['side'] ?? ''))),
            (int)($input['index'] ?? -1),
            (int)($input['rotation'] ?? -1)
        );
        labyrinthRespond($res['success'] ? 200 : 400, $res);
    }

    if ($action === 'move') {
        labyrinthRequirePost();
        $res = $service->movePawn($roomId, $playerId, (int)($input['row'] ?? -1), (int)($input['col'] ?? -1));
        labyrinthRespond($res['success'] ? 200 : 400, $res);
    }

    // -------------------------------------------------------------------------
    // 7. SPIELERNAME ÄNDERN
    // -------------------------------------------------------------------------
    if ($action === 'update_name') {
        labyrinthRequirePost();
        $newName = labyrinthPlayerName($input['player_name'] ?? '', '');
        if ($newName === '') {
            labyrinthRespond(400, ['success' => false, 'message' => 'Name darf nicht leer sein']);
        }
        $repo->updatePlayerName($playerId, $newName);
        labyrinthRespond(200, ['success' => true]);
    }

    labyrinthRespond(400, ['success' => false, 'message' => 'Unbekannte Aktion']);
} catch (\Throwable $e) {
    $logger->error('public_games/labyrinth/api.php: Fehler bei Anfrage.', [
        'error' => $e->getMessage(),
        'action' => $action,
    ]);
    labyrinthRespond(500, ['success' => false, 'message' => 'Interner Serverfehler']);
}
