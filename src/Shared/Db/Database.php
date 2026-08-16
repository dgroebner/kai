<?php
namespace Kai\Tools\Shared\Db;

use PDO;
use PDOException;
use Exception;
use Kai\Tools\Shared\Log\Logger;

class Database {
    private static ?Database $instance = null;
    private PDO $connection;
    private Logger $logger;

    /**
     * Privater Konstruktor verhindert direkte Instanziierung (Singleton)
     */
    private function __construct() {
        // Logger instanziieren
        $this->logger = new Logger(14);

        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $db   = $_ENV['DB_NAME'] ?? '';
        $user = $_ENV['DB_USER'] ?? '';
        $pass = $_ENV['DB_PASS'] ?? '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // Nutzt native Prepared Statements der DB
        ];

        try {
            $this->connection = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Wir loggen den echten Fehler für dich ins interne Log...
            $this->logger->error("Database: Verbindungsaufbau fehlgeschlagen!", ['error' => $e->getMessage()]);
            
            // ...werfen aber eine neutrale Exception nach oben, damit Zugangsdaten
            // niemals versehentlich in einer Browser-Ausgabe landen.
            throw new Exception("Kritischer Fehler: Datenbankverbindung konnte nicht hergestellt werden.");
        }
    }

    /**
     * Klonen verhindern (Singleton)
     */
    private function __clone() {}

    /**
     * Gibt die Singleton-Instanz der Klasse zurück
     */
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /**
     * Gibt das reine PDO-Verbindungsobjekt für Queries zurück
     */
    public function getConnection(): PDO {
        return $this->connection;
    }
}