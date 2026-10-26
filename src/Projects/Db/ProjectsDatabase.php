<?php

namespace Kai\Tools\Projects\Db;

use Exception;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use PDOException;

/**
 * Dedizierte PDO-Verbindung für die zweite Strato-Datenbank des Projects-Moduls.
 * Vollständige physische Trennung von den Kai-Kerndaten.
 */
class ProjectsDatabase
{
    private static ?ProjectsDatabase $instance = null;
    private PDO $connection;
    private Logger $logger;

    /**
     * @throws Exception
     */
    private function __construct()
    {
        $this->logger = new Logger(14);

        $host = $_ENV['PROJECTS_DB_HOST'] ?? ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $port = $_ENV['PROJECTS_DB_PORT'] ?? '3306';
        $db = $_ENV['PROJECTS_DB_NAME'] ?? '';
        $user = $_ENV['PROJECTS_DB_USER'] ?? '';
        $pass = $_ENV['PROJECTS_DB_PASS'] ?? '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->connection = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            $this->logger->error("ProjectsDatabase: Verbindungsaufbau fehlgeschlagen!", ['error' => $e->getMessage()]);
            throw new Exception("Kritischer Fehler: Projects-Datenbankverbindung konnte nicht hergestellt werden.");
        }
    }

    public static function getInstance(): ProjectsDatabase
    {
        if (self::$instance === null) {
            self::$instance = new ProjectsDatabase();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    private function __clone()
    {
    }
}
