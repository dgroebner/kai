<?php

namespace Kai\Tools\School;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Repository zur Verwaltung der Schülerprofile (Name, Klasse, verknüpfte Google-Mailadresse).
 */
class SchoolStudentRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Liefert alle Schülerprofile sortiert nach Sortierreihenfolge.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        $stmt = $this->db->getConnection()->query(
            "SELECT * FROM school_students ORDER BY sort_order ASC, name ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Liefert alle aktiven Schülerprofile.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActive(): array
    {
        $stmt = $this->db->getConnection()->query(
            "SELECT * FROM school_students WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Sucht ein Schülerprofil anhand der ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT * FROM school_students WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Findet ein Schülerprofil anhand einer verknüpften E-Mail-Adresse.
     */
    public function getByEmail(string $email): ?array
    {
        $cleanEmail = strtolower(trim($email));
        if ($cleanEmail === '') {
            return null;
        }

        $stmt = $this->db->getConnection()->prepare(
            "SELECT * FROM school_students WHERE LOWER(user_email) = :email AND is_active = 1 LIMIT 1"
        );
        $stmt->execute(['email' => $cleanEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Findet ein Schülerprofil anhand des Namens (z. B. für Sprachassistenten).
     */
    public function getByName(string $name): ?array
    {
        $cleanName = trim($name);
        if ($cleanName === '') {
            return null;
        }

        $stmt = $this->db->getConnection()->prepare(
            "SELECT * FROM school_students WHERE LOWER(name) = LOWER(:name) AND is_active = 1 LIMIT 1"
        );
        $stmt->execute(['name' => $cleanName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Speichert oder aktualisiert ein Schülerprofil.
     *
     * @param array<string, mixed> $data
     * @return int ID des Eintrags
     */
    public function save(array $data): int
    {
        $pdo = $this->db->getConnection();
        $id = !empty($data['id']) ? (int)$data['id'] : null;

        $name = trim((string)($data['name'] ?? ''));
        $className = strtoupper(trim((string)($data['class_name'] ?? '')));
        $userEmail = !empty($data['user_email']) ? strtolower(trim((string)$data['user_email'])) : null;
        $color = !empty($data['display_color']) ? trim((string)$data['display_color']) : '#2563eb';
        $sortOrder = isset($data['sort_order']) ? (int)$data['sort_order'] : 0;
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;

        if ($id !== null && $id > 0) {
            $stmt = $pdo->prepare("
                UPDATE school_students 
                SET name = :name,
                    class_name = :class_name,
                    user_email = :user_email,
                    display_color = :display_color,
                    sort_order = :sort_order,
                    is_active = :is_active,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $id,
                'name' => $name,
                'class_name' => $className,
                'user_email' => $userEmail,
                'display_color' => $color,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
            ]);

            return $id;
        }

        $stmt = $pdo->prepare("
            INSERT INTO school_students (name, class_name, user_email, display_color, sort_order, is_active, created_at, updated_at)
            VALUES (:name, :class_name, :user_email, :display_color, :sort_order, :is_active, NOW(), NOW())
        ");
        $stmt->execute([
            'name' => $name,
            'class_name' => $className,
            'user_email' => $userEmail,
            'display_color' => $color,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Löscht ein Schülerprofil.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->getConnection()->prepare(
            "DELETE FROM school_students WHERE id = :id"
        );

        return $stmt->execute(['id' => $id]);
    }
}
