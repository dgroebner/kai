<?php

namespace Kai\Tools\System;

use Kai\Tools\Shared\Db\Database;
use PDO;

class GroupRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function getAllGroups(): array
    {
        return $this->db->getConnection()->query("SELECT * FROM groups ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getGroupPermissions(int $groupId): array
    {
        $stmt = $this->db->getConnection()->prepare("SELECT permission FROM group_permissions WHERE group_id = :group_id");
        $stmt->execute(["group_id" => $groupId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getAllUsers(): array
    {
        return $this->db->getConnection()->query("SELECT * FROM users ORDER BY email")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUserGroups(string $email): array
    {
        $stmt = $this->db->getConnection()->prepare("SELECT group_id FROM user_groups WHERE user_email = :email");
        $stmt->execute(["email" => $email]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function createGroup(string $name): void
    {
        $stmt = $this->db->getConnection()->prepare("INSERT INTO groups (name) VALUES (:name)");
        $stmt->execute(["name" => $name]);
    }

    public function deleteGroup(int $id): void
    {
        $stmt = $this->db->getConnection()->prepare("DELETE FROM groups WHERE id = :id");
        $stmt->execute(["id" => $id]);
    }

    public function updateGroupPermissions(int $groupId, array $permissions): void
    {
        $dbCon = $this->db->getConnection();
        $dbCon->beginTransaction();
        
        try {
            $stmt = $dbCon->prepare("DELETE FROM group_permissions WHERE group_id = :group_id");
            $stmt->execute(["group_id" => $groupId]);
            
            $stmtInsert = $dbCon->prepare("INSERT INTO group_permissions (group_id, permission) VALUES (:group_id, :permission)");
            foreach ($permissions as $permission) {
                $stmtInsert->execute(["group_id" => $groupId, "permission" => $permission]);
            }
            
            $dbCon->commit();
        } catch (\Throwable $e) {
            $dbCon->rollBack();
            throw $e;
        }
    }

    public function updateUserGroups(string $email, array $groupIds): void
    {
        $dbCon = $this->db->getConnection();
        $dbCon->beginTransaction();
        
        try {
            $stmt = $dbCon->prepare("DELETE FROM user_groups WHERE user_email = :email");
            $stmt->execute(["email" => $email]);
            
            $stmtInsert = $dbCon->prepare("INSERT INTO user_groups (user_email, group_id) VALUES (:email, :group_id)");
            foreach ($groupIds as $groupId) {
                $stmtInsert->execute(["email" => $email, "group_id" => $groupId]);
            }
            
            $dbCon->commit();
        } catch (\Throwable $e) {
            $dbCon->rollBack();
            throw $e;
        }
    }
}

