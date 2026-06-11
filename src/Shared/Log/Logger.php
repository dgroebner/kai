<?php
namespace Kai\Tools\Shared\Log;

class Logger {
    private string $logDir;
    private int $retentionDays;

    public function __construct(?int $retentionDays = null) {
        // Pfad-Anpassung: Wenn kai_root/src/Shared/Log/Logger.php liegt, 
        // müssen wir 4 Ebenen nach oben, um wieder auf der Ebene von kai_root zu sein.
        // Falls storage parallel zu kai_root liegt, reicht das:
        $this->logDir = __DIR__ . '/../../../storage/logs';
        $this->retentionDays = $retentionDays ?? (int)($_ENV['LOG_RETENTION_DAYS'] ?? 14);

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public function info(string $message, array $context = []) {
        $this->writeLog('INFO', $message, $context);
    }

    public function error(string $message, array $context = []) {
        $this->writeLog('ERROR', $message, $context);
    }

    private function writeLog(string $level, string $message, array $context = []) {
        $date = date('Y-m-d');
        $file = $this->logDir . "/app-{$date}.log";

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }

        $contextString = !empty($context) ? ' ' . json_encode($context) : '';
        $logEntry = "[{$date} " . date('H:i:s') . "] [{$level}] {$message}{$contextString}" . PHP_EOL;

        @file_put_contents($file, $logEntry, FILE_APPEND | LOCK_EX);

        // Cleanup nur einmal täglich ausführen (gesteuert über eine Marker-Datei)
        $markerFile = $this->logDir . '/.cleanup_' . $date;
        if (!file_exists($markerFile)) {
            @touch($markerFile);
            // Alte Marker-Dateien ebenfalls entfernen
            foreach (glob($this->logDir . '/.cleanup_*') as $oldMarker) {
                if ($oldMarker !== $markerFile) {
                    @unlink($oldMarker);
                }
            }
            $this->cleanup();
        }
    }

    private function cleanup(): void {
        $files = glob($this->logDir . '/app-*.log');
        $now = time();
        
        foreach ($files as $file) {
            if (is_file($file)) {
                // filemtime gibt den Zeitstempel der letzten Änderung zurück
                if ($now - filemtime($file) >= 60 * 60 * 24 * $this->retentionDays) {
                    unlink($file);
                }
            }
        }
    }
}