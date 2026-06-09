<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('memory_limit', '512M'); // Gibt dem Skript ordentlich Luft zum Atmen
ini_set('max_execution_time', '120'); // Verhindert, dass Strato nach 30s abbricht
error_reporting(E_ALL);

require_once __DIR__ . '/../../bootstrap.php';

$task = new \Kai\Tools\Kassenbon\ScannerTask();
$task->run();