<?php

namespace Kai\Tools\System;

use JsonException;
use Kai\Tools\Shared\Db\Database;
use PDO;

class UserProfileRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Prüft, ob ein Benutzerprofil existiert, und legt es bei Bedarf
     * mit den Standard-Benachrichtigungseinstellungen an.
     * @throws JsonException
     */
    public function ensureProfileExists(string $email): void
    {
        $dbCon = $this->db->getConnection();

        $stmt = $dbCon->prepare("SELECT id FROM user_profiles WHERE user_email = :email");
        $stmt->execute(['email' => $email]);

        if (!$stmt->fetch()) {
            $defaultPreferences = json_encode([
                'receipt_created' => true,
                'creditcard_statement_created' => true,
                'bank_data_imported' => true,
                'pv_forecast_loaded' => true,
                'car_telemetry_loaded' => true,
            ], JSON_THROW_ON_ERROR);

            $insertStmt = $dbCon->prepare("
                INSERT INTO user_profiles (user_email, notification_preferences, created_at, updated_at) 
                VALUES (:email, :preferences, NOW(), NOW())
            ");
            $insertStmt->execute([
                'email' => $email,
                'preferences' => $defaultPreferences
            ]);
        }
    }

    /**
     * Lädt die Benachrichtigungseinstellungen eines Benutzers.
     */
    public function getPreferences(string $email): array
    {
        $dbCon = $this->db->getConnection();
        $stmt = $dbCon->prepare("SELECT notification_preferences FROM user_profiles WHERE user_email = :email");
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['notification_preferences'])) {
            $decoded = json_decode($row['notification_preferences'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Fallback / Defaults
        return [
            'receipt_created' => true,
            'creditcard_statement_created' => true,
            'bank_data_imported' => true,
            'pv_forecast_loaded' => true,
            'car_telemetry_loaded' => true,
        ];
    }

    /**
     * Speichert die Benachrichtigungseinstellungen eines Benutzers.
     * @throws JsonException
     */
    public function updatePreferences(string $email, array $preferences): void
    {
        $dbCon = $this->db->getConnection();
        $encoded = json_encode($preferences, JSON_THROW_ON_ERROR);

        $stmt = $dbCon->prepare("
            UPDATE user_profiles 
            SET notification_preferences = :preferences, updated_at = NOW() 
            WHERE user_email = :email
        ");
        $stmt->execute([
            'preferences' => $encoded,
            'email' => $email,
        ]);
    }
}