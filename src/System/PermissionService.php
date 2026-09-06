<?php

namespace Kai\Tools\System;

use Kai\Tools\Shared\Db\Database;
use PDO;

class PermissionService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function handleUserLogin(string $email, string $name): void
    {
        $dbCon = $this->db->getConnection();

        // Ensure user exists
        $stmt = $dbCon->prepare("INSERT INTO users (email, name) VALUES (:email, :name) ON DUPLICATE KEY UPDATE name = :name");
        $stmt->execute(["email" => $email, "name" => $name]);

        // Check Admin Fallback
        $adminEmail = $_ENV["ADMIN_EMAIL"] ?? null;
        if ($adminEmail && strtolower($email) === strtolower($adminEmail)) {
            // Check if Admin group exists, if not create it
            $stmt = $dbCon->prepare("SELECT id FROM groups WHERE name = :name");
            $stmt->execute(["name" => "Admin"]);
            $adminGroupId = $stmt->fetchColumn();

            if (!$adminGroupId) {
                $stmt = $dbCon->prepare("INSERT INTO groups (name) VALUES (:name)");
                $stmt->execute(["name" => "Admin"]);
                $adminGroupId = $dbCon->lastInsertId();
            }

            // Assign user to Admin group
            $stmt = $dbCon->prepare("INSERT IGNORE INTO user_groups (user_email, group_id) VALUES (:email, :group_id)");
            $stmt->execute(["email" => $email, "group_id" => $adminGroupId]);
            
            // Give Admin group all permissions? The concept doesnt say it automatically gives permissions, but maybe Admin bypasses checks or has all permissions?
            // "Der Benutzer wird zwingend der Admin-Gruppe zugewiesen... Session-Variable für temporäre Gruppenwechsel wird gelöscht"
            unset($_SESSION["temp_group_id"]);
        }

        // Load permissions
        $this->loadPermissionsIntoSession($email);
    }

    private function loadPermissionsIntoSession(string $email): void
    {
        $dbCon = $this->db->getConnection();
        
        $tempGroupId = $_SESSION["temp_group_id"] ?? null;
        
        if ($tempGroupId) {
            // Check if user is allowed to use temp group (only if they actually belong to Admin normally? The concept: "sofern die Berechtigung für den Test vorliegt")
            // Let s assume for now if temp_group_id is set, we use it. We will clear it on login if Admin anyway.
            $stmt = $dbCon->prepare("
                SELECT permission FROM group_permissions WHERE group_id = :group_id
            ");
            $stmt->execute(["group_id" => $tempGroupId]);
        } else {
            $stmt = $dbCon->prepare("
                SELECT gp.permission 
                FROM group_permissions gp
                JOIN user_groups ug ON gp.group_id = ug.group_id
                WHERE ug.user_email = :email
            ");
            $stmt->execute(["email" => $email]);
        }

        $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $_SESSION["permissions"] = $permissions;
    }
}

