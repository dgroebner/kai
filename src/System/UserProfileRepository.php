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
        'calendar_reminder' => true,
    ];

    public const EVENT_PERMISSIONS = [
        'financial_report_generated' => 'finance_read',
        'bank_data_imported' => 'finance_read',
        'creditcard_statement_created' => 'finance_read',
        'receipt_created' => 'ebon_read',
        'school_plan_updated' => 'school_read',
        'school_notes_updated' => 'school_read',
        'school_grades_updated' => 'school_read',
        'calendar_reminder' => 'calendar_read',
        'shopping_completed' => 'shopping_read',
        'pv_forecast_loaded' => 'pv_read',
        'battery_fully_charged' => 'pv_read',
        'car_telemetry_loaded' => 'car_read',
        'car_charge_captured' => 'car_read',
    ];

    public const DEFAULT_BRIEFING_PREFERENCES = [
        'weather' => ['enabled' => true, 'order' => 10],
        'school' => ['enabled' => true, 'order' => 20],
        'pv_car' => ['enabled' => true, 'order' => 30],
        'shopping' => ['enabled' => true, 'order' => 40],
        'calendar' => ['enabled' => true, 'order' => 50],
        'finance' => ['enabled' => true, 'order' => 60],
    ];

    public const BRIEFING_WIDGET_CONFIG = [
        'weather' => [
            'label' => 'Wetter & Bekleidung',
            'icon' => '🌤️',
            'desc' => '6-Stunden-Wetterprognose, Kleidungsempfehlung & Regenschirm',
            'permission' => 'weather_read',
        ],
        'school' => [
            'label' => 'Schule & Aufgaben',
            'icon' => '🎒',
            'desc' => 'Schulschluss, Vertretungen/Ausfälle & Hausaufgaben/Tests',
            'permission' => 'school_read',
        ],
        'pv_car' => [
            'label' => 'Energie & Fahrzeug',
            'icon' => '⚡',
            'desc' => 'PV-Ertragsprognose, Batteriestand & Lade-Empfehlung für ID.Buzz',
            'permission' => 'pv_read',
        ],
        'shopping' => [
            'label' => 'Einkaufsliste',
            'icon' => '🛒',
            'desc' => 'Offene Sofortbedarfe & Wocheneinkauf (Rewe / Globus)',
            'permission' => 'shopping_read',
        ],
        'calendar' => [
            'label' => 'Jubiläen & Geburtstage',
            'icon' => '🎉',
            'desc' => 'Anstehende Geburtstage & Jahrestage der nächsten 3 Tage',
            'permission' => 'calendar_read',
        ],
        'finance' => [
            'label' => 'Finanzen',
            'icon' => '🏦',
            'desc' => 'Girokonto-Saldo & erwartete Fixkosten der nächsten 3 Tage',
            'permission' => 'finance_read',
        ],
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
            $defaultBriefing = json_encode(self::DEFAULT_BRIEFING_PREFERENCES, JSON_THROW_ON_ERROR);

            $insertStmt = $dbCon->prepare("
                INSERT INTO user_profiles (user_email, notification_preferences, briefing_preferences, created_at, updated_at) 
                VALUES (:email, :preferences, :briefing, NOW(), NOW())
            ");
            $insertStmt->execute([
                'email' => $email,
                'preferences' => $defaultPreferences,
                'briefing' => $defaultBriefing,
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

    /**
     * Lädt die Briefing-Einstellungen (aktivierte Widgets & Sortierreihenfolge) eines Benutzers.
     *
     * @return array<string, array{enabled: bool, order: int}>
     */
    public function getBriefingPreferences(string $email): array
    {
        $dbCon = $this->db->getConnection();
        $stmt = $dbCon->prepare("SELECT briefing_preferences FROM user_profiles WHERE user_email = :email");
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $prefs = self::DEFAULT_BRIEFING_PREFERENCES;

        if ($row && !empty($row['briefing_preferences'])) {
            $decoded = json_decode($row['briefing_preferences'], true);
            if (is_array($decoded)) {
                foreach (self::DEFAULT_BRIEFING_PREFERENCES as $widgetKey => $defaultConfig) {
                    if (isset($decoded[$widgetKey]) && is_array($decoded[$widgetKey])) {
                        $prefs[$widgetKey] = [
                            'enabled' => isset($decoded[$widgetKey]['enabled']) ? (bool)$decoded[$widgetKey]['enabled'] : $defaultConfig['enabled'],
                            'order' => isset($decoded[$widgetKey]['order']) && is_numeric($decoded[$widgetKey]['order']) ? (int)$decoded[$widgetKey]['order'] : $defaultConfig['order'],
                        ];
                    }
                }
            }
        }

        // Nach Reihenfolge sortieren
        uasort($prefs, static function ($a, $b) {
            return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
        });

        return $prefs;
    }

    /**
     * Speichert die Briefing-Einstellungen eines Benutzers.
     * @throws JsonException
     */
    public function updateBriefingPreferences(string $email, array $preferences): void
    {
        $dbCon = $this->db->getConnection();
        $current = $this->getBriefingPreferences($email);

        foreach ($preferences as $widgetKey => $config) {
            if (isset(self::DEFAULT_BRIEFING_PREFERENCES[$widgetKey])) {
                $current[$widgetKey]['enabled'] = isset($config['enabled']) ? (bool)$config['enabled'] : false;
                if (isset($config['order']) && is_numeric($config['order'])) {
                    $current[$widgetKey]['order'] = (int)$config['order'];
                }
            }
        }

        $encoded = json_encode($current, JSON_THROW_ON_ERROR);

        $stmt = $dbCon->prepare("
            UPDATE user_profiles 
            SET briefing_preferences = :preferences, updated_at = NOW() 
            WHERE user_email = :email
        ");
        $stmt->execute([
            'preferences' => $encoded,
            'email' => $email,
        ]);
    }
}