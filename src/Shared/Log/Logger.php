<?php
namespace Kai\Tools\Shared\Log;

class Logger {
    private string $logDir;
    private int $retentionDays;

    public function __construct(int $retentionDays = 14) {
        // Die Logs liegen sicher außerhalb des öffentlichen public/ Ordners
        $this->logDir = __DIR__ . '/../../../../storage/logs';
        $this->retentionDays = $retentionDays;

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
        $time = date('H:i:s');
        $file = $this->logDir . "/app-{$date}.log";

        $contextString = !empty($context) ? ' ' . json_encode($context) : '';
        $logEntry = "[{$date} {$time}] [{$level}] {$message}{$contextString}" . PHP_EOL;

        // LOCK_EX verhindert Dateikonflikte, falls zwei Skripte exakt zeitgleich loggen
        file_put_contents($file, $logEntry, FILE_APPEND | LOCK_EX);

        $this->cleanup();
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