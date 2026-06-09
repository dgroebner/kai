<?php
namespace Kai\Tools\Shared\Log;

class Logger {
    private string $logDir;
    private int $retentionDays;

    public function __construct(int $retentionDays = 14) {
        // Pfad-Anpassung: Wenn kai_root/src/Shared/Log/Logger.php liegt, 
        // müssen wir 4 Ebenen nach oben, um wieder auf der Ebene von kai_root zu sein.
        // Falls storage parallel zu kai_root liegt, reicht das:
        $this->logDir = __DIR__ . '/../../../storage/logs';
        
        // Prüfe, ob das Verzeichnis erreichbar ist, sonst korrigiere den Pfad:
        if (!is_dir($this->logDir)) {
            // Debug-Hilfe: Falls er es nicht findet, lass ihn uns anzeigen
            error_log("Logger konnte Verzeichnis nicht finden: " . $this->logDir);
        }

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

        // Versuch das Verzeichnis zu erstellen, falls es fehlt
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }

        $contextString = !empty($context) ? ' ' . json_encode($context) : '';
        $logEntry = "[{$date} " . date('H:i:s') . "] [{$level}] {$message}{$contextString}" . PHP_EOL;

        // Fehler unterdrücken mit @, um einen 500er zu vermeiden, falls Schreiben fehlschlägt
        @file_put_contents($file, $logEntry, FILE_APPEND | LOCK_EX);
    }

    private function cleanup() {
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