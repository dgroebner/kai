<?php

namespace Kai\Tools\Projects;

use Kai\Tools\Projects\Db\ProjectsDatabase;
use PDO;

/**
 * Isolierte Nutzerverwaltung für den Super-Admin.
 *
 * PRIVACY BY DESIGN:
 * Hat keinerlei Zugriff auf proj_projects, proj_time_exceptions oder proj_absences.
 * Fragt und manipuliert ausschließlich Nutzerstammdaten.
 */
class ProjectsAdminRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ProjectsDatabase::getInstance()->getConnection();
    }

    /**
     * Gibt alle registrierten und eingeladenen Nutzer zurück.
     *
     * @return array<int, array{id: int, email: string, name: ?string, status: string, state_code: string, created_at: string}>
     */
    public function getAllUsers(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, email, name, status, state_code, created_at
            FROM proj_users
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, email, name, status, state_code, created_at
            FROM proj_users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, email, name, status, state_code, created_at
            FROM proj_users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function inviteUser(string $email, ?string $name = null, string $stateCode = 'SN'): int
    {
        $email = strtolower(trim($email));
        $name = !empty($name) ? trim($name) : null;
        $stateCode = in_array(strtoupper($stateCode), ['SN', 'HH'], true) ? strtoupper($stateCode) : 'SN';

        $stmt = $this->pdo->prepare("
            INSERT INTO proj_users (email, name, status, state_code, created_at)
            VALUES (:email, :name, 'invited', :state_code, NOW())
            ON DUPLICATE KEY UPDATE
                name = COALESCE(VALUES(name), name),
                state_code = VALUES(state_code)
        ");
        $stmt->execute([
            ':email' => $email,
            ':name' => $name,
            ':state_code' => $stateCode,
        ]);

        return (int)$this->pdo->lastInsertId() ?: ($this->findByEmail($email)['id'] ?? 0);
    }

    public function updateStatus(int $userId, string $newStatus): bool
    {
        if (!in_array($newStatus, ['invited', 'active', 'disabled'], true)) {
            return false;
        }

        $stmt = $this->pdo->prepare("UPDATE proj_users SET status = :status WHERE id = :id");
        return $stmt->execute([
            ':status' => $newStatus,
            ':id' => $userId,
        ]);
    }

    public function deleteUser(int $userId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM proj_users WHERE id = :id");
        return $stmt->execute([':id' => $userId]);
    }
}
