<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../bootstrap.php';

$task = new \Kai\Tools\Kassenbon\ScannerTask();
$task->run();