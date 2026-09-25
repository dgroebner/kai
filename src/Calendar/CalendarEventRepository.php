<?php

namespace Kai\Tools\Calendar;

use Kai\Tools\Shared\Db\Database;
use PDO;

class CalendarEventRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Ruft alle Kalender-Ereignisse ab, optional gefiltert.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(
        ?string $forUserEmail = null,
        ?string $category = null,
        ?string $eventType = null,
        ?string $search = null
    ): array {
        $pdo = $this->db->getConnection();

        $sql = "
            SELECT e.*, 
                   GROUP_CONCAT(DISTINCT cen.user_email ORDER BY cen.user_email SEPARATOR ',') AS recipient_emails_str
            FROM calendar_events e
            LEFT JOIN calendar_event_notifications cen ON e.id = cen.event_id
        ";

        $where = [];
        $params = [];

        if (!empty($category)) {
            $where[] = "e.category = :category";
            $params['category'] = $category;
        }

        if (!empty($eventType)) {
            $where[] = "e.event_type = :event_type";
            $params['event_type'] = $eventType;
        }

        if (!empty($search)) {
            $where[] = "(e.title LIKE :search OR e.notes LIKE :search2)";
            $params['search'] = '%' . $search . '%';
            $params['search2'] = '%' . $search . '%';
        }

        if (!empty($forUserEmail)) {
            $where[] = "(cen.user_email = :for_user OR e.created_by = :for_user2)";
            $params['for_user'] = $forUserEmail;
            $params['for_user2'] = $forUserEmail;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " GROUP BY e.id ORDER BY e.event_month ASC, e.event_day ASC, e.title ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $usersMap = $this->getUsersMap();

        foreach ($rows as &$row) {
            $rawEmails = !empty($row['recipient_emails_str']) ? explode(',', $row['recipient_emails_str']) : [];
            $row['recipient_emails'] = array_values(array_filter(array_map('trim', $rawEmails)));
            $row['recipients'] = [];
            foreach ($row['recipient_emails'] as $email) {
                $row['recipients'][] = [
                    'email' => $email,
                    'name' => $usersMap[$email] ?? $email,
                ];
            }
            unset($row['recipient_emails_str']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Findet ein einzelnes Ereignis anhand seiner ID.
     */
    public function getById(int $id): ?array
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM calendar_events WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            return null;
        }

        $event['recipient_emails'] = $this->getRecipients($id);
        $usersMap = $this->getUsersMap();
        $event['recipients'] = [];
        foreach ($event['recipient_emails'] as $email) {
            $event['recipients'][] = [
                'email' => $email,
                'name' => $usersMap[$email] ?? $email,
            ];
        }

        return $event;
    }

    /**
     * Liefert alle E-Mail-Adressen der für ein Ereignis konfigurierten Empfänger.
     *
     * @return string[]
     */
    public function getRecipients(int $eventId): array
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("SELECT user_email FROM calendar_event_notifications WHERE event_id = :event_id ORDER BY user_email");
        $stmt->execute(['event_id' => $eventId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Erstellt ein neues Kalender-Ereignis mit Benachrichtigungsempfängern.
     *
     * @param array<string, mixed> $data
     * @param string[] $recipientEmails
     */
    public function create(array $data, array $recipientEmails): int
    {
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
                INSERT INTO calendar_events (
                    title, event_type, event_day, event_month, event_year,
                    category, notify_days_advance, notes, created_by, created_at
                ) VALUES (
                    :title, :event_type, :event_day, :event_month, :event_year,
                    :category, :notify_days_advance, :notes, :created_by, NOW()
                )
            ");

            $stmt->execute([
                'title' => $data['title'],
                'event_type' => $data['event_type'] ?? 'birthday',
                'event_day' => (int)$data['event_day'],
                'event_month' => (int)$data['event_month'],
                'event_year' => !empty($data['event_year']) ? (int)$data['event_year'] : null,
                'category' => !empty($data['category']) ? trim((string)$data['category']) : 'Familie',
                'notify_days_advance' => $data['notify_days_advance'] ?? '0,1,3',
                'notes' => !empty($data['notes']) ? trim((string)$data['notes']) : null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $eventId = (int)$pdo->lastInsertId();

            if (!empty($recipientEmails)) {
                $insertRecipient = $pdo->prepare("
                    INSERT IGNORE INTO calendar_event_notifications (event_id, user_email)
                    VALUES (:event_id, :user_email)
                ");
                foreach ($recipientEmails as $email) {
                    $cleanedEmail = strtolower(trim((string)$email));
                    if (!empty($cleanedEmail)) {
                        $insertRecipient->execute([
                            'event_id' => $eventId,
                            'user_email' => $cleanedEmail,
                        ]);
                    }
                }
            }

            $pdo->commit();
            return $eventId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Aktualisiert ein vorhandenes Kalender-Ereignis und seine Benachrichtigungsempfänger.
     *
     * @param array<string, mixed> $data
     * @param string[] $recipientEmails
     */
    public function update(int $id, array $data, array $recipientEmails): bool
    {
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
                UPDATE calendar_events SET
                    title = :title,
                    event_type = :event_type,
                    event_day = :event_day,
                    event_month = :event_month,
                    event_year = :event_year,
                    category = :category,
                    notify_days_advance = :notify_days_advance,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                'id' => $id,
                'title' => $data['title'],
                'event_type' => $data['event_type'] ?? 'birthday',
                'event_day' => (int)$data['event_day'],
                'event_month' => (int)$data['event_month'],
                'event_year' => !empty($data['event_year']) ? (int)$data['event_year'] : null,
                'category' => !empty($data['category']) ? trim((string)$data['category']) : 'Familie',
                'notify_days_advance' => $data['notify_days_advance'] ?? '0,1,3',
                'notes' => !empty($data['notes']) ? trim((string)$data['notes']) : null,
            ]);

            // Benachrichtigungsempfänger synchronisieren
            $delStmt = $pdo->prepare("DELETE FROM calendar_event_notifications WHERE event_id = :event_id");
            $delStmt->execute(['event_id' => $id]);

            if (!empty($recipientEmails)) {
                $insertRecipient = $pdo->prepare("
                    INSERT IGNORE INTO calendar_event_notifications (event_id, user_email)
                    VALUES (:event_id, :user_email)
                ");
                foreach ($recipientEmails as $email) {
                    $cleanedEmail = strtolower(trim((string)$email));
                    if (!empty($cleanedEmail)) {
                        $insertRecipient->execute([
                            'event_id' => $id,
                            'user_email' => $cleanedEmail,
                        ]);
                    }
                }
            }

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Löscht ein Kalender-Ereignis.
     */
    public function delete(int $id): bool
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("DELETE FROM calendar_events WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Prüft, ob für dieses Ereignis, diesen Benutzer, dieses Zieljahr und diesen Vorlauftag bereits benachrichtigt wurde.
     */
    public function hasNotificationBeenSent(int $eventId, string $userEmail, int $targetYear, int $daysAdvance): bool
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("
            SELECT id FROM calendar_notification_logs
            WHERE event_id = :event_id 
              AND user_email = :user_email 
              AND target_year = :target_year 
              AND days_advance = :days_advance
        ");
        $stmt->execute([
            'event_id' => $eventId,
            'user_email' => strtolower(trim($userEmail)),
            'target_year' => $targetYear,
            'days_advance' => $daysAdvance,
        ]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Markiert eine Benachrichtigung als versendet.
     */
    public function recordNotificationSent(int $eventId, string $userEmail, int $targetYear, int $daysAdvance): void
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO calendar_notification_logs (
                event_id, user_email, target_year, days_advance, sent_at
            ) VALUES (
                :event_id, :user_email, :target_year, :days_advance, NOW()
            )
        ");
        $stmt->execute([
            'event_id' => $eventId,
            'user_email' => strtolower(trim($userEmail)),
            'target_year' => $targetYear,
            'days_advance' => $daysAdvance,
        ]);
    }

    /**
     * Liefert alle im System verfügbaren Benutzer (aus users-Tabelle und ALLOWED_USERS).
     *
     * @return array<int, array{email: string, name: string}>
     */
    public function getAllPossibleUsers(): array
    {
        $pdo = $this->db->getConnection();
        $users = [];

        try {
            $stmt = $pdo->query("SELECT email, name FROM users ORDER BY name ASC, email ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $email = strtolower(trim((string)$r['email']));
                $name = !empty($r['name']) ? trim((string)$r['name']) : ucfirst(explode('@', $email)[0]);
                $users[$email] = [
                    'email' => $email,
                    'name' => $name,
                ];
            }
        } catch (\Throwable) {
            // Falls Tabelle users noch nicht abgefragt werden kann
        }

        // Auch ALLOWED_USERS aus der .env einbinden, damit alle Familienmitglieder vor erstem Login wählbar sind
        $allowed = $_ENV['ALLOWED_USERS'] ?? '';
        if (!empty($allowed)) {
            $emails = explode(',', $allowed);
            foreach ($emails as $em) {
                $cleaned = strtolower(trim($em));
                if (!empty($cleaned) && !isset($users[$cleaned])) {
                    $users[$cleaned] = [
                        'email' => $cleaned,
                        'name' => ucfirst(explode('@', $cleaned)[0]),
                    ];
                }
            }
        }

        return array_values($users);
    }

    /**
     * Liefert ein Zuordnungs-Array von E-Mail => Anzeigename.
     *
     * @return array<string, string>
     */
    public function getUsersMap(): array
    {
        $map = [];
        foreach ($this->getAllPossibleUsers() as $u) {
            $map[$u['email']] = $u['name'];
        }
        return $map;
    }

    /**
     * Liefert alle vorkommenden Kategorien plus Standardkategorien.
     *
     * @return string[]
     */
    public function getCategories(): array
    {
        $pdo = $this->db->getConnection();
        $defaultCategories = ['Familie', 'Verwandte', 'Freunde', 'Kollegen', 'Bekannte'];
        
        try {
            $stmt = $pdo->query("SELECT DISTINCT category FROM calendar_events WHERE category IS NOT NULL AND category != ''");
            $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $merged = array_unique(array_merge($defaultCategories, $existing));
            sort($merged);
            return array_values($merged);
        } catch (\Throwable) {
            return $defaultCategories;
        }
    }
}
