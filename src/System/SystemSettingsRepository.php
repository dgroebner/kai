<?php

namespace Kai\Tools\System;

use Kai\Tools\Shared\Db\Database;
use PDO;

class SystemSettingsRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = :key");
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();

        return $value !== false ? $value : $default;
    }

    public function set(string $key, string $value, ?string $label = null): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO system_settings (setting_key, setting_value, label) 
            VALUES (:key, :value, :label)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), label = COALESCE(VALUES(label), label)
        ");
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
            ':label' => $label
        ]);
    }

    public function getAll(): array
    {
        $stmt = $this->db->query("SELECT setting_key, setting_value, label FROM system_settings");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}