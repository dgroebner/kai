<?php

namespace Kai\Tools\Weather;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use Throwable;

class AstronomyIngestService
{
    private PDO $pdo;
    private Logger $logger;

    public function __construct(?PDO $pdo = null, ?Logger $logger = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Verarbeitet eingehende Astronomie- und Himmelsdaten vom Raspi-Crawler.
     *
     * @param array<string, mixed> $payload
     * @return array{state_updated: bool, events_count: int}
     */
    public function processIngest(array $payload): array
    {
        $stateUpdated = false;
        $eventsCount = 0;

        // 1. Aktuellen Himmels- & Aurora-Status aktualisieren
        if (!empty($payload['state']) && is_array($payload['state'])) {
            $stateUpdated = $this->updateState($payload['state']);
        }

        // 2. Anstehende astronomische Ereignisse aktualisieren
        if (!empty($payload['events']) && is_array($payload['events'])) {
            $eventsCount = $this->upsertEvents($payload['events']);
        }

        // 3. Veraltete Ereignisse bereinigen (älter als 30 Tage)
        $this->cleanupOldEvents();

        $this->logger->info('AstronomyIngest: Daten erfolgreich verarbeitet', [
            'state_updated' => $stateUpdated,
            'events_count' => $eventsCount,
        ]);

        return [
            'state_updated' => $stateUpdated,
            'events_count' => $eventsCount,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function updateState(array $state): bool
    {
        $kpCurrent = isset($state['kp_current']) ? (float)$state['kp_current'] : 0.0;
        $kpMax24h = isset($state['kp_max_next_24h']) ? (float)$state['kp_max_next_24h'] : 0.0;
        $kpForecast = !empty($state['kp_forecast']) ? json_encode($state['kp_forecast'], JSON_UNESCAPED_UNICODE) : null;
        $auroraChance = (string)($state['aurora_chance'] ?? 'none');
        $visiblePlanets = !empty($state['visible_planets']) ? json_encode($state['visible_planets'], JSON_UNESCAPED_UNICODE) : null;
        $activeMeteors = !empty($state['active_meteor_showers']) ? json_encode($state['active_meteor_showers'], JSON_UNESCAPED_UNICODE) : null;
        $moonPhaseName = isset($state['moon_phase_name']) ? (string)$state['moon_phase_name'] : null;
        $moonIllumination = isset($state['moon_illumination']) ? (float)$state['moon_illumination'] : null;
        $summaryText = isset($state['summary_text']) ? (string)$state['summary_text'] : null;

        $stmt = $this->pdo->prepare("
            INSERT INTO weather_astronomy_state (
                id, kp_current, kp_max_next_24h, kp_forecast_json, aurora_chance,
                visible_planets_json, active_meteor_showers_json, moon_phase_name,
                moon_illumination, summary_text, updated_at
            ) VALUES (
                1, :kp_curr, :kp_max, :kp_fc, :aurora,
                :planets, :meteors, :moon_name,
                :moon_illum, :summary, NOW()
            ) ON DUPLICATE KEY UPDATE
                kp_current = VALUES(kp_current),
                kp_max_next_24h = VALUES(kp_max_next_24h),
                kp_forecast_json = VALUES(kp_forecast_json),
                aurora_chance = VALUES(aurora_chance),
                visible_planets_json = VALUES(visible_planets_json),
                active_meteor_showers_json = VALUES(active_meteor_showers_json),
                moon_phase_name = VALUES(moon_phase_name),
                moon_illumination = VALUES(moon_illumination),
                summary_text = VALUES(summary_text),
                updated_at = NOW()
        ");

        return $stmt->execute([
            ':kp_curr' => $kpCurrent,
            ':kp_max' => $kpMax24h,
            ':kp_fc' => $kpForecast,
            ':aurora' => $auroraChance,
            ':planets' => $visiblePlanets,
            ':meteors' => $activeMeteors,
            ':moon_name' => $moonPhaseName,
            ':moon_illum' => $moonIllumination,
            ':summary' => $summaryText,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function upsertEvents(array $events): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO weather_astronomy_events (
                event_key, event_type, title, description, event_date,
                peak_time, end_date, magnitude, visibility_rating, details_json, updated_at
            ) VALUES (
                :event_key, :event_type, :title, :description, :event_date,
                :peak_time, :end_date, :magnitude, :visibility_rating, :details_json, NOW()
            ) ON DUPLICATE KEY UPDATE
                event_type = VALUES(event_type),
                title = VALUES(title),
                description = VALUES(description),
                event_date = VALUES(event_date),
                peak_time = VALUES(peak_time),
                end_date = VALUES(end_date),
                magnitude = VALUES(magnitude),
                visibility_rating = VALUES(visibility_rating),
                details_json = VALUES(details_json),
                updated_at = NOW()
        ");

        $count = 0;
        foreach ($events as $event) {
            $key = trim((string)($event['event_key'] ?? ''));
            $title = trim((string)($event['title'] ?? ''));
            $date = trim((string)($event['event_date'] ?? ''));

            if ($key === '' || $title === '' || $date === '') {
                continue;
            }

            $type = (string)($event['event_type'] ?? 'other');
            $desc = isset($event['description']) ? (string)$event['description'] : null;
            $peak = !empty($event['peak_time']) ? date('Y-m-d H:i:s', strtotime((string)$event['peak_time'])) : null;
            $end = !empty($event['end_date']) ? date('Y-m-d', strtotime((string)$event['end_date'])) : null;
            $mag = isset($event['magnitude']) && is_numeric($event['magnitude']) ? (float)$event['magnitude'] : null;
            $rating = (string)($event['visibility_rating'] ?? 'good');
            $details = !empty($event['details']) ? json_encode($event['details'], JSON_UNESCAPED_UNICODE) : null;

            $stmt->execute([
                ':event_key' => $key,
                ':event_type' => $type,
                ':title' => $title,
                ':description' => $desc,
                ':event_date' => $date,
                ':peak_time' => $peak,
                ':end_date' => $end,
                ':magnitude' => $mag,
                ':visibility_rating' => $rating,
                ':details_json' => $details,
            ]);
            $count++;
        }

        return $count;
    }

    private function cleanupOldEvents(): void
    {
        try {
            $this->pdo->exec("
                DELETE FROM weather_astronomy_events 
                WHERE event_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ");
        } catch (Throwable $e) {
            $this->logger->warn('AstronomyIngest: Alte Events konnten nicht bereinigt werden', ['error' => $e->getMessage()]);
        }
    }
}
