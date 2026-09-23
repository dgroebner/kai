<?php

namespace Kai\Tools\System;

use JsonException;
use Kai\Tools\Shared\Db\Database;
use PDO;

class UserProfileRepository
{
    private Database $db;

    public const DEFAULT_PREFERENCES = [
        'financial_report_generated' => true,
        'bank_data_imported' => true,
        'creditcard_statement_created' => true,
        'receipt_created' => true,
        'school_plan_updated' => true,
        'school_notes_updated' => true,
        'school_grades_updated' => true,
        'shopping_completed' => true,
        'pv_forecast_loaded' => true,
        'battery_fully_charged' => true,
        'car_telemetry_loaded' => true,
        'car_charge_captured' => true,
    ];

    public const EVENT_PERMISSIONS = [
        'financial_report_generated' => 'finance_read',
        'bank_data_imported' => 'finance_read',
        'creditcard_statement_created' => 'finance_read',
        'receipt_created' => 'ebon_read',
        'school_plan_updated' => 'school_read',
        'school_notes_updated' => 'school_read',
        'school_grades_updated' => 'school_read',
        'shopping_completed' => 'shopping_read',
        'pv_forecast_loaded' => 'pv_read',
        'battery_fully_charged' => 'pv_read',
        'car_telemetry_loaded' => 'car_read',
        'car_charge_captured' => 'car_read',
    ];

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
            $defaultPreferences = json_encode(self::DEFAULT_PREFERENCES, JSON_THROW_ON_ERROR);

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
                return array_merge(self::DEFAULT_PREFERENCES, $decoded);
            }
        }

        // Fallback / Defaults
        return self::DEFAULT_PREFERENCES;
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