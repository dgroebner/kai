<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub;

use Kai\Tools\Shared\Db\Database;
use PDO;

class RoomRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /**
     * Erzeugt einen neuen Spielraum mit eindeutigem 6-stelligem Code.
     */
    public function createRoom(string $gameType, string $gameMode, string $hostTokenHash, array $initialState): ?array
    {
        $this->cleanupOldRooms();

        // 6-stelligen Code generieren (nur gut lesbare Großbuchstaben und Ziffern ohne 0/O/1/I)
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $maxTries = 10;
        $roomCode = '';

        for ($t = 0; $t < $maxTries; $t++) {
            $candidate = '';
            for ($i = 0; $i < 6; $i++) {
                $candidate .= $chars[random_int(0, strlen($chars) - 1)];
            }
            // Prüfen, ob bereits belegt
            $check = $this->pdo->prepare('SELECT id FROM games_hub_rooms WHERE room_code = :code');
            $check->execute([':code' => $candidate]);
            if (!$check->fetch()) {
                $roomCode = $candidate;
                break;
            }
        }

        if ($roomCode === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO games_hub_rooms (room_code, game_type, game_mode, status, host_token_hash, state_json, state_version, created_at, updated_at)
            VALUES (:room_code, :game_type, :game_mode, "lobby", :host_token_hash, :state_json, 1, NOW(), NOW())
        ');
        $stmt->execute([
            ':room_code' => $roomCode,
            ':game_type' => $gameType,
            ':game_mode' => $gameMode,
            ':host_token_hash' => $hostTokenHash,
            ':state_json' => json_encode($initialState, JSON_UNESCAPED_UNICODE),
        ]);

        $roomId = (int)$this->pdo->lastInsertId();
        return $this->getRoomById($roomId);
    }

    public function getRoomByCode(string $roomCode): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, room_code, game_type, game_mode, status, host_token_hash, state_json, state_version, created_at, updated_at
            FROM games_hub_rooms
            WHERE room_code = :code
        ');
        $stmt->execute([':code' => strtoupper(trim($roomCode))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['state'] = !empty($row['state_json']) ? json_decode((string)$row['state_json'], true) : [];
        return $row;
    }

    public function getRoomById(int $roomId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, room_code, game_type, game_mode, status, host_token_hash, state_json, state_version, created_at, updated_at
            FROM games_hub_rooms
            WHERE id = :id
        ');
        $stmt->execute([':id' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['state'] = !empty($row['state_json']) ? json_decode((string)$row['state_json'], true) : [];
        return $row;
    }

    /**
     * Aktualisiert den Spielzustand und inkrementiert atomar die state_version.
     */
    public function updateRoomState(int $roomId, array $newState, ?string $status = null): bool
    {
        $sql = '
            UPDATE games_hub_rooms
            SET state_json = :state_json,
                state_version = state_version + 1,
                updated_at = NOW()' . ($status !== null ? ', status = :status' : '') . '
            WHERE id = :id
        ';
        $params = [
            ':id' => $roomId,
            ':state_json' => json_encode($newState, JSON_UNESCAPED_UNICODE),
        ];
        if ($status !== null) {
            $params[':status'] = $status;
        }

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Fügt einen Spieler zum Raum hinzu oder gibt den bestehenden zurück.
     */
    public function joinPlayer(int $roomId, string $playerTokenHash, string $playerName, bool $isHost = false, array $initialSheet = []): array
    {
        $existing = $this->getPlayerByToken($roomId, $playerTokenHash);
        if ($existing) {
            // Reconnect: Name und Verbindungsstatus aktualisieren
            $update = $this->pdo->prepare('
                UPDATE games_hub_players
                SET player_name = :name, is_connected = 1, updated_at = NOW()
                WHERE id = :id
            ');
            $update->execute([':name' => $playerName, ':id' => $existing['id']]);
            $existing['player_name'] = $playerName;
            return $existing;
        }

        // Neue Sitzplatznummer
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM games_hub_players WHERE room_id = :room_id');
        $countStmt->execute([':room_id' => $roomId]);
        $seatOrder = (int)$countStmt->fetchColumn();

        $stmt = $this->pdo->prepare('
            INSERT INTO games_hub_players (room_id, player_token_hash, player_name, seat_order, is_host, is_connected, sheet_json, total_score, created_at, updated_at)
            VALUES (:room_id, :token_hash, :name, :seat_order, :is_host, 1, :sheet_json, 0, NOW(), NOW())
        ');
        $stmt->execute([
            ':room_id' => $roomId,
            ':token_hash' => $playerTokenHash,
            ':name' => $playerName,
            ':seat_order' => $seatOrder,
            ':is_host' => $isHost ? 1 : 0,
            ':sheet_json' => json_encode($initialSheet, JSON_UNESCAPED_UNICODE),
        ]);

        $playerId = (int)$this->pdo->lastInsertId();
        return $this->getPlayerById($playerId);
    }

    public function getPlayerByToken(int $roomId, string $playerTokenHash): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, room_id, player_token_hash, player_name, seat_order, is_host, is_connected, sheet_json, total_score, created_at, updated_at
            FROM games_hub_players
            WHERE room_id = :room_id AND player_token_hash = :hash
        ');
        $stmt->execute([':room_id' => $roomId, ':hash' => $playerTokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['sheet'] = !empty($row['sheet_json']) ? json_decode((string)$row['sheet_json'], true) : [];
        return $row;
    }

    public function getPlayerById(int $playerId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, room_id, player_token_hash, player_name, seat_order, is_host, is_connected, sheet_json, total_score, created_at, updated_at
            FROM games_hub_players
            WHERE id = :id
        ');
        $stmt->execute([':id' => $playerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['sheet'] = !empty($row['sheet_json']) ? json_decode((string)$row['sheet_json'], true) : [];
        return $row;
    }

    /**
     * Gibt alle Spieler eines Raums sortiert nach Sitzplatz zurück.
     */
    public function getPlayersForRoom(int $roomId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, room_id, player_token_hash, player_name, seat_order, is_host, is_connected, sheet_json, total_score, created_at, updated_at
            FROM games_hub_players
            WHERE room_id = :room_id
            ORDER BY seat_order ASC, id ASC
        ');
        $stmt->execute([':room_id' => $roomId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['sheet'] = !empty($r['sheet_json']) ? json_decode((string)$r['sheet_json'], true) : [];
        }
        return $rows;
    }

    /**
     * Aktualisiert den Spielzettel und Punktestand eines Spielers.
     */
    public function updatePlayerSheet(int $playerId, array $sheet, int $totalScore): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE games_hub_players
            SET sheet_json = :sheet_json,
                total_score = :score,
                updated_at = NOW()
            WHERE id = :id
        ');
        return $stmt->execute([
            ':id' => $playerId,
            ':sheet_json' => json_encode($sheet, JSON_UNESCAPED_UNICODE),
            ':score' => $totalScore,
        ]);
    }

    /**
     * Aktualisiert den Anzeigenamen eines Spielers.
     */
    public function updatePlayerName(int $playerId, string $playerName): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE games_hub_players
            SET player_name = :name,
                updated_at = NOW()
            WHERE id = :id
        ');
        return $stmt->execute([
            ':id' => $playerId,
            ':name' => $playerName,
        ]);
    }

    /**
     * Erlaubte Emojis für Quarxl-Reaktionen samt Beschriftung.
     */
    public const ALLOWED_REACTIONS = [
        '😂' => 'Lachen',
        '😢' => 'Weinen',
        '⏳' => 'Ungeduldig warten',
        '👏' => 'Applaus',
        '😱' => 'Schock',
        '🔥' => 'Stark!',
        '🤔' => 'Grübeln',
    ];

    /**
     * Erstellt die Reaktionstabelle dynamisch, falls noch nicht vorhanden.
     */
    public function ensureReactionsTable(): void
    {
        try {
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS `games_hub_reactions` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `room_id` INT UNSIGNED NOT NULL,
                    `player_id` INT UNSIGNED NOT NULL,
                    `player_name` VARCHAR(40) NOT NULL,
                    `emoji` VARCHAR(32) NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_gh_reactions_room_id` (`room_id`, `id`),
                    INDEX `idx_gh_reactions_player_time` (`room_id`, `player_id`, `created_at`),
                    CONSTRAINT `fk_gh_reactions_room` FOREIGN KEY (`room_id`) REFERENCES `games_hub_rooms`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ');
        } catch (\Throwable) {
            // Non-blocking bei Restriktionen
        }
    }

    /**
     * Fügt eine Reaktion für einen Raum hinzu mit Cooldown-Spamschutz (3 Sekunden).
     */
    public function addReaction(int $roomId, int $playerId, string $playerName, string $emoji): array
    {
        $this->ensureReactionsTable();

        if (!array_key_exists($emoji, self::ALLOWED_REACTIONS)) {
            return [
                'success' => false,
                'error' => 'invalid_emoji',
                'message' => 'Ungültiges Emoji.',
            ];
        }

        $playerName = mb_substr(trim($playerName), 0, 30);
        if ($playerName === '') {
            $playerName = 'Spieler';
        }

        // Spam-Schutz: Wurde in den letzten 3 Sekunden bereits eine Reaktion von diesem Spieler im Raum gesendet?
        $checkStmt = $this->pdo->prepare('
            SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS diff_sec
            FROM games_hub_reactions
            WHERE room_id = :room_id AND player_id = :player_id
            ORDER BY id DESC
            LIMIT 1
        ');
        $checkStmt->execute([
            ':room_id' => $roomId,
            ':player_id' => $playerId,
        ]);
        $row = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['diff_sec'] !== null && (int)$row['diff_sec'] < 3) {
            $remaining = 3 - (int)$row['diff_sec'];
            return [
                'success' => false,
                'error' => 'spam_cooldown',
                'remaining' => $remaining,
                'message' => "Bitte warte noch {$remaining}s vor der nächsten Reaktion.",
            ];
        }

        $insertStmt = $this->pdo->prepare('
            INSERT INTO games_hub_reactions (room_id, player_id, player_name, emoji, created_at)
            VALUES (:room_id, :player_id, :player_name, :emoji, NOW())
        ');
        $insertStmt->execute([
            ':room_id' => $roomId,
            ':player_id' => $playerId,
            ':player_name' => $playerName,
            ':emoji' => $emoji,
        ]);

        $newId = (int)$this->pdo->lastInsertId();

        // Gelegentliches Cleanup alter Reaktionen (älter als 10 Minuten)
        if (random_int(1, 15) === 1) {
            try {
                $this->pdo->exec('
                    DELETE FROM games_hub_reactions
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                ');
            } catch (\Throwable) {
                // Ignore
            }
        }

        return [
            'success' => true,
            'reaction' => [
                'id' => $newId,
                'player_id' => $playerId,
                'player_name' => $playerName,
                'emoji' => $emoji,
                'label' => self::ALLOWED_REACTIONS[$emoji],
                'created_at' => date('Y-m-d H:i:s'),
                'created_ts' => time(),
            ],
        ];
    }

    /**
     * Holt die aktuellen Reaktionen eines Raums (z. B. der letzten 30 Sekunden bzw. ab einer ID).
     */
    public function getRecentReactions(int $roomId, int $sinceId = 0, int $limit = 20): array
    {
        $this->ensureReactionsTable();

        try {
            $stmt = $this->pdo->prepare('
                SELECT id, player_id, player_name, emoji, UNIX_TIMESTAMP(created_at) AS created_ts
                FROM games_hub_reactions
                WHERE room_id = :room_id AND id > :since_id AND created_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
                ORDER BY id ASC
                LIMIT ' . (int)$limit . '
            ');
            $stmt->execute([
                ':room_id' => $roomId,
                ':since_id' => $sinceId,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['player_id'] = (int)$r['player_id'];
                $r['created_ts'] = (int)$r['created_ts'];
                $r['label'] = self::ALLOWED_REACTIONS[$r['emoji']] ?? '';
            }
            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Bereinigt veraltete Räume, die älter als 48 Stunden sind.
     */
    public function cleanupOldRooms(): void
    {
        try {
            $stmt = $this->pdo->prepare('
                DELETE FROM games_hub_rooms
                WHERE updated_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)
            ');
            $stmt->execute();

            $this->pdo->exec('
                DELETE FROM games_hub_reactions
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ');
        } catch (\Throwable) {
            // Silent catch bei Cleanup
        }
    }
}
