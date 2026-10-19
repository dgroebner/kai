<?php

namespace Kai\Tools\Transit;

use Kai\Tools\Shared\Db\Database;
use PDO;
use Throwable;

/**
 * Repository zur Speicherung und Verwaltung personalisierter Pendlerprofile.
 */
class TransitProfileRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Liefert alle Profile, optional gefiltert nach Benutzer.
     *
     * @param string|null $userEmail E-Mail des Benutzers
     * @param bool $strict Wenn true, werden nur Profile geliefert, die exakt diesem Benutzer zugeordnet sind (kein NULL-Fallback).
     * @return array<int, array<string, mixed>>
     */
    public function getAll(?string $userEmail = null, bool $strict = false): array
    {
        $this->ensureSeedProfiles();

        $conn = $this->db->getConnection();
        if ($userEmail !== null && $userEmail !== '') {
            $normalizedEmail = strtolower(trim($userEmail));
            $sql = $strict
                ? "SELECT * FROM transit_profiles WHERE LOWER(user_email) = :email ORDER BY sort_order ASC, id ASC"
                : "SELECT * FROM transit_profiles WHERE LOWER(user_email) = :email OR user_email IS NULL ORDER BY sort_order ASC, id ASC";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['email' => $normalizedEmail]);
        } else {
            $stmt = $conn->query("SELECT * FROM transit_profiles ORDER BY sort_order ASC, id ASC");
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'hydrateRow'], $rows);
    }

    /**
     * Liefert alle aktiven Profile.
     *
     * @param string|null $userEmail
     * @param bool $strict
     * @return array<int, array<string, mixed>>
     */
    public function getActive(?string $userEmail = null, bool $strict = false): array
    {
        $all = $this->getAll($userEmail, $strict);
        return array_values(array_filter($all, static fn($p) => !empty($p['is_active'])));
    }

    /**
     * Liefert alle aktiven Profile strikt für einen Benutzer (z. B. für das Daily Briefing).
     *
     * @param string $userEmail
     * @return array<int, array<string, mixed>>
     */
    public function getActiveForUser(string $userEmail): array
    {
        return $this->getActive($userEmail, true);
    }

    /**
     * Sucht ein Profil anhand seiner ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->getConnection()->prepare("SELECT * FROM transit_profiles WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateRow($row) : null;
    }

    /**
     * Speichert (erstellt oder aktualisiert) ein Pendlerprofil.
     */
    public function save(array $data): int
    {
        $conn = $this->db->getConnection();
        $id = !empty($data['id']) ? (int)$data['id'] : null;

        $activeDays = is_array($data['active_days'] ?? null) 
            ? json_encode(array_values(array_map('intval', $data['active_days']))) 
            : (string)($data['active_days'] ?? '[1,2,3,4,5]');

        $params = [
            'user_email' => !empty($data['user_email']) ? trim((string)$data['user_email']) : null,
            'student_id' => !empty($data['student_id']) ? (int)$data['student_id'] : null,
            'profile_name' => trim((string)($data['profile_name'] ?? 'Pendlerprofil')),
            'origin_station_name' => trim((string)($data['origin_station_name'] ?? '')),
            'origin_station_id' => trim((string)($data['origin_station_id'] ?? '')),
            'destination_station_name' => trim((string)($data['destination_station_name'] ?? '')),
            'destination_station_id' => trim((string)($data['destination_station_id'] ?? '')),
            'via_station_name' => !empty($data['via_station_name']) ? trim((string)$data['via_station_name']) : null,
            'via_station_id' => !empty($data['via_station_id']) ? trim((string)$data['via_station_id']) : null,
            'preferred_lines' => !empty($data['preferred_lines']) ? trim((string)$data['preferred_lines']) : null,
            'transport_modes' => !empty($data['transport_modes']) ? trim((string)$data['transport_modes']) : 'all',
            'active_days' => $activeDays,
            'morning_window_start' => (string)($data['morning_window_start'] ?? '06:30:00'),
            'morning_window_end' => (string)($data['morning_window_end'] ?? '09:00:00'),
            'evening_window_start' => (string)($data['evening_window_start'] ?? '15:00:00'),
            'evening_window_end' => (string)($data['evening_window_end'] ?? '19:00:00'),
            'only_school_days' => !empty($data['only_school_days']) ? 1 : 0,
            'min_transfer_minutes' => max(1, (int)($data['min_transfer_minutes'] ?? 4)),
            'is_active' => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
            'sort_order' => (int)($data['sort_order'] ?? 0),
        ];

        if ($id !== null) {
            $params['id'] = $id;
            $stmt = $conn->prepare("
                UPDATE transit_profiles SET
                    user_email = :user_email,
                    student_id = :student_id,
                    profile_name = :profile_name,
                    origin_station_name = :origin_station_name,
                    origin_station_id = :origin_station_id,
                    destination_station_name = :destination_station_name,
                    destination_station_id = :destination_station_id,
                    via_station_name = :via_station_name,
                    via_station_id = :via_station_id,
                    preferred_lines = :preferred_lines,
                    transport_modes = :transport_modes,
                    active_days = :active_days,
                    morning_window_start = :morning_window_start,
                    morning_window_end = :morning_window_end,
                    evening_window_start = :evening_window_start,
                    evening_window_end = :evening_window_end,
                    only_school_days = :only_school_days,
                    min_transfer_minutes = :min_transfer_minutes,
                    is_active = :is_active,
                    sort_order = :sort_order
                WHERE id = :id
            ");
            $stmt->execute($params);
            return $id;
        }

        $stmt = $conn->prepare("
            INSERT INTO transit_profiles (
                user_email, student_id, profile_name,
                origin_station_name, origin_station_id,
                destination_station_name, destination_station_id,
                via_station_name, via_station_id,
                preferred_lines, transport_modes, active_days,
                morning_window_start, morning_window_end,
                evening_window_start, evening_window_end,
                only_school_days, min_transfer_minutes,
                is_active, sort_order
            ) VALUES (
                :user_email, :student_id, :profile_name,
                :origin_station_name, :origin_station_id,
                :destination_station_name, :destination_station_id,
                :via_station_name, :via_station_id,
                :preferred_lines, :transport_modes, :active_days,
                :morning_window_start, :morning_window_end,
                :evening_window_start, :evening_window_end,
                :only_school_days, :min_transfer_minutes,
                :is_active, :sort_order
            )
        ");
        $stmt->execute($params);
        return (int)$conn->lastInsertId();
    }

    /**
     * Löscht ein Profil anhand seiner ID.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->getConnection()->prepare("DELETE FROM transit_profiles WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Erstellt standardmäßige Preset-Profile, falls noch keine existieren.
     */
    public function ensureSeedProfiles(): void
    {
        try {
            $conn = $this->db->getConnection();
            $count = (int)$conn->query("SELECT COUNT(*) FROM transit_profiles")->fetchColumn();
            if ($count > 0) {
                return;
            }

            // Standard-Profile für die Familie anlegen
            $this->save([
                'user_email' => null,
                'profile_name' => 'Büro (Daniel)',
                'origin_station_name' => 'Leipzig, Meusdorf',
                'origin_station_id' => '11559',
                'destination_station_name' => 'Leipzig, Augustusplatz',
                'destination_station_id' => '13002',
                'preferred_lines' => '15',
                'transport_modes' => 'tram',
                'active_days' => [1, 3, 4], // Mo, Mi, Do
                'morning_window_start' => '06:45:00',
                'morning_window_end' => '08:30:00',
                'evening_window_start' => '15:30:00',
                'evening_window_end' => '18:30:00',
                'only_school_days' => 0,
                'sort_order' => 10,
                'is_active' => 1,
            ]);

            $this->save([
                'user_email' => null,
                'profile_name' => 'Berufsschule (Große Tochter)',
                'origin_station_name' => 'Leipzig, Meusdorf',
                'origin_station_id' => '11559',
                'destination_station_name' => 'Leipzig, Wilhelm-Leuschner-Platz (Tram/Bus)',
                'destination_station_id' => '12992',
                'preferred_lines' => '15',
                'transport_modes' => 'tram',
                'active_days' => [1, 2, 3, 4, 5], // Mo - Fr
                'morning_window_start' => '06:45:00',
                'morning_window_end' => '08:15:00',
                'evening_window_start' => '13:00:00',
                'evening_window_end' => '16:30:00',
                'only_school_days' => 1, // Pausiert automatisch in sächsischen Ferien
                'min_transfer_minutes' => 4,
                'sort_order' => 20,
                'is_active' => 1,
            ]);
        } catch (Throwable) {
            // Ignorieren falls Tabelle während Migration noch nicht bereit
        }
    }

    /**
     * Wandelt die Datenbank-Rohzeile in ein typisiertes Array um.
     */
    private function hydrateRow(array $row): array
    {
        $activeDays = json_decode((string)($row['active_days'] ?? '[]'), true);
        if (!is_array($activeDays)) {
            $activeDays = [1, 2, 3, 4, 5];
        }

        return [
            'id' => (int)$row['id'],
            'user_email' => $row['user_email'] !== null ? (string)$row['user_email'] : null,
            'student_id' => $row['student_id'] !== null ? (int)$row['student_id'] : null,
            'profile_name' => (string)$row['profile_name'],
            'origin_station_name' => (string)$row['origin_station_name'],
            'origin_station_id' => (string)$row['origin_station_id'],
            'destination_station_name' => (string)$row['destination_station_name'],
            'destination_station_id' => (string)$row['destination_station_id'],
            'via_station_name' => $row['via_station_name'] !== null ? (string)$row['via_station_name'] : null,
            'via_station_id' => $row['via_station_id'] !== null ? (string)$row['via_station_id'] : null,
            'preferred_lines' => $row['preferred_lines'] !== null ? (string)$row['preferred_lines'] : null,
            'transport_modes' => (string)($row['transport_modes'] ?? 'all'),
            'active_days' => array_map('intval', $activeDays),
            'morning_window_start' => substr((string)$row['morning_window_start'], 0, 5),
            'morning_window_end' => substr((string)$row['morning_window_end'], 0, 5),
            'evening_window_start' => substr((string)$row['evening_window_start'], 0, 5),
            'evening_window_end' => substr((string)$row['evening_window_end'], 0, 5),
            'only_school_days' => (bool)$row['only_school_days'],
            'min_transfer_minutes' => (int)$row['min_transfer_minutes'],
            'is_active' => (bool)$row['is_active'],
            'sort_order' => (int)$row['sort_order'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }
}
