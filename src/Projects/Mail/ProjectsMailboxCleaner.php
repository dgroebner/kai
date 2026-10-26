<?php

namespace Kai\Tools\Projects\Mail;

use Exception;
use Kai\Tools\Shared\Log\Logger;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * Automatischer Postfach-Cleaner für projects@agent-smith.de.
 *
 * HARD-GUARDS:
 * 1. Kein Fallback auf IMAP_USER_KASSENBON (liest strikt nur PROJECTS_MAIL_USER/PROJECTS_MAIL_PASS).
 * 2. Bricht sofort ab, falls PROJECTS_MAIL_USER identisch mit IMAP_USER_KASSENBON ist.
 * 3. Bricht sofort ab, falls die Adresse nicht mit "projects@" beginnt.
 */
class ProjectsMailboxCleaner
{
    private Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger(14);
    }

    /**
     * Führt die vollständige Leerung des Projects-Postfachs durch.
     *
     * @return array{success: bool, deleted_count: int, message: string}
     */
    public function purgeMailbox(): array
    {
        $projectsUser = strtolower(trim((string)($_ENV['PROJECTS_MAIL_USER'] ?? '')));
        $projectsPass = (string)($_ENV['PROJECTS_MAIL_PASS'] ?? '');
        $kaiUser = strtolower(trim((string)($_ENV['IMAP_USER_KASSENBON'] ?? '')));

        // GUARD 1: Zugangsdaten müssen vorhanden sein
        if ($projectsUser === '' || $projectsPass === '') {
            $this->logger->warn("ProjectsMailboxCleaner: PROJECTS_MAIL_USER oder PROJECTS_MAIL_PASS nicht konfiguriert.");
            return [
                'success' => false,
                'deleted_count' => 0,
                'message' => 'Postfach-Zugangsdaten nicht konfiguriert.',
            ];
        }

        // GUARD 2: Niemals das Kai-Hauptpostfach anrühren
        if ($projectsUser === $kaiUser) {
            $msg = "KRITISCHER SICHERHEITSFEHLER: PROJECTS_MAIL_USER ({$projectsUser}) ist identisch mit IMAP_USER_KASSENBON!";
            $this->logger->error($msg);
            throw new RuntimeException($msg);
        }

        // GUARD 3: Präfix-Check — Postfach MUSS mit projects@ beginnen
        if (!str_starts_with($projectsUser, 'projects@')) {
            $msg = "SICHERHEITSABBRUCH: ProjectsMailboxCleaner ist ausschließlich für Postfächer erlaubt, die mit 'projects@' beginnen (versucht: {$projectsUser})!";
            $this->logger->error($msg);
            throw new RuntimeException($msg);
        }

        $host = (string)($_ENV['PROJECTS_IMAP_HOST'] ?? ($_ENV['IMAP_HOST'] ?? 'imap.strato.de'));
        $port = (int)($_ENV['PROJECTS_IMAP_PORT'] ?? ($_ENV['IMAP_PORT'] ?? 993));

        $clientManager = new ClientManager([
            'accounts' => [
                'projects' => [
                    'host' => $host,
                    'port' => $port,
                    'encryption' => 'ssl',
                    'validate_cert' => true,
                    'username' => $projectsUser,
                    'password' => $projectsPass,
                    'protocol' => 'imap',
                ]
            ]
        ]);

        $deletedCount = 0;
        $client = null;

        try {
            /** @var Client $client */
            $client = $clientManager->account('projects');
            $client->connect();

            // Ordner durchgehen, in denen Post landen könnte
            $foldersToClean = ['INBOX', 'Spam', 'Trash', 'Junk'];

            foreach ($foldersToClean as $folderName) {
                try {
                    $folder = $client->getFolderByPath($folderName);
                    if (!$folder) {
                        $folder = $client->getFolder($folderName);
                    }
                    if (!$folder) {
                        continue;
                    }

                    $messages = $folder->query()->all()->get();
                    foreach ($messages as $message) {
                        try {
                            $message->delete(expunge: true);
                            $deletedCount++;
                        } catch (Throwable $delErr) {
                            $this->logger->warn("ProjectsMailboxCleaner: Nachricht konnte nicht gelöscht werden: " . $delErr->getMessage());
                        }
                    }

                    // Expunge auf Ordnerebene
                    try {
                        $folder->expunge();
                    } catch (Throwable) {}

                } catch (Throwable $folderErr) {
                    // Ordner existiert eventuell nicht, ist unkritisch
                    $this->logger->debug("ProjectsMailboxCleaner: Ordner {$folderName} übersprungen: " . $folderErr->getMessage());
                }
            }

            $this->logger->info("ProjectsMailboxCleaner: Postfach {$projectsUser} erfolgreich bereinigt ({$deletedCount} Nachrichten gelöscht).");

            return [
                'success' => true,
                'deleted_count' => $deletedCount,
                'message' => "Postfach {$projectsUser} erfolgreich bereinigt ({$deletedCount} Nachrichten gelöscht).",
            ];

        } catch (Throwable $e) {
            $this->logger->error("ProjectsMailboxCleaner: Verbindungs- oder Bereinigungsfehler: " . $e->getMessage());
            return [
                'success' => false,
                'deleted_count' => $deletedCount,
                'message' => 'Fehler bei der Postfachbereinigung: ' . $e->getMessage(),
            ];
        } finally {
            if ($client && $client->isConnected()) {
                try {
                    $client->disconnect();
                } catch (Throwable) {}
            }
        }
    }
}
