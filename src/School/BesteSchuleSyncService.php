<?php

namespace Kai\Tools\School;

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Db\Database;

class BesteSchuleSyncService
{
    private BesteSchuleClient $client;
    private BesteSchuleRepository $repo;
    private Logger $logger;

    public function __construct()
    {
        $this->client = new BesteSchuleClient();
        $this->repo = new BesteSchuleRepository();
        $this->logger = new Logger();
    }

    public function syncAll(): array
    {
        if (!$this->client->isConfigured()) {
            $this->logger->info("BesteSchuleSync: Übersprungen, da kein API Token konfiguriert ist.");
            return ['success' => false, 'message' => 'API Token nicht konfiguriert'];
        }

        $this->logger->info("BesteSchuleSync: Starte Synchronisation...");
        
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->query("SELECT id, name, beste_schule_id FROM school_students WHERE is_active = 1 AND beste_schule_id IS NOT NULL AND beste_schule_id != ''");
        $students = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($students)) {
            $this->logger->info("BesteSchuleSync: Abbruch, keine aktiven Schüler mit beste_schule_id gefunden.");
            return ['success' => true, 'message' => 'Keine aktiven Schüler mit beste_schule_id gefunden'];
        }

        $stats = ['grades' => 0, 'absences' => 0, 'journal' => 0];

        foreach ($students as $student) {
            $kaiId = (int)$student['id'];
            $bsId = $student['beste_schule_id'];
            
            $this->logger->info("BesteSchuleSync: Frage Daten für Schüler ab...", ['kai_id' => $kaiId, 'beste_schule_id' => $bsId, 'name' => $student['name']]);

            // 1. Noten abrufen
            $grades = $this->client->getGrades($bsId);
            if (is_array($grades)) {
                foreach ($grades as $g) {
                    $this->repo->upsertGrade([
                        'id' => (int)$g['id'],
                        'student_id' => $kaiId,
                        'subject' => $g['subject']['name'] ?? 'Unbekannt',
                        'collection_name' => $g['collection']['name'] ?? 'Unbekannt',
                        'grade_value' => (string)($g['value'] ?? ''),
                        'given_at' => substr($g['given_at'] ?? date('Y-m-d'), 0, 10),
                        'read_status' => !empty($g['read']) ? 1 : 0
                    ]);
                    $stats['grades']++;
                }
            }

            // 2. Fehlzeiten abrufen
            $absences = $this->client->getAbsences($bsId);
            if (is_array($absences)) {
                foreach ($absences as $a) {
                    $isUnexcused = !empty($a['has_unexcused']) || (isset($a['verification']['confirmed']) && !$a['verification']['confirmed']);
                    $this->repo->upsertAbsence([
                        'id' => (int)$a['id'],
                        'student_id' => $kaiId,
                        'from_time' => date('Y-m-d H:i:s', strtotime($a['from'] ?? 'now')),
                        'to_time' => date('Y-m-d H:i:s', strtotime($a['to'] ?? 'now')),
                        'absence_type' => $a['type']['name'] ?? 'Unbekannt',
                        'is_unexcused' => $isUnexcused ? 1 : 0,
                        'note' => $a['note'] ?? $a['note_guardian'] ?? null
                    ]);
                    $stats['absences']++;
                }
            }

            // 3. Journal (Hausaufgaben/Material) abrufen
            $journal = $this->client->getJournal($bsId);
            if (is_array($journal)) {
                foreach ($journal as $j) {
                    // Wir speichern nur die Einträge, bei denen wirklich was vergessen wurde,
                    // um die DB nicht mit Millionen von OK-Datensätzen zuzumüllen.
                    $missingHw = !empty($j['missing_homework']);
                    $missingEq = !empty($j['missing_equipment']);

                    if ($missingHw || $missingEq) {
                        $this->repo->upsertJournalEntry([
                            'id' => (int)$j['id'],
                            'student_id' => $kaiId,
                            'lesson_date' => substr($j['time']['start'] ?? $j['lesson']['date'] ?? $j['lesson']['day']['date'] ?? date('Y-m-d'), 0, 10),
                            'subject' => $j['lesson']['subject']['name'] ?? 'Unbekanntes Fach',
                            'missing_homework' => $missingHw ? 1 : 0,
                            'missing_equipment' => $missingEq ? 1 : 0
                        ]);
                        $stats['journal']++;
                    }
                }
            }
            
            // 4. Hausaufgaben (Notes) für die nächsten 14 Tage abrufen
            $fromDate = date('Y-m-d');
            $toDate = date('Y-m-d', strtotime('+14 days'));
            $lessonsWithNotes = $this->client->getUpcomingLessonsWithNotes($bsId, $fromDate, $toDate);
            if (is_array($lessonsWithNotes)) {
                if (!isset($stats['notes'])) $stats['notes'] = 0;
                foreach ($lessonsWithNotes as $lesson) {
                    if (empty($lesson['notes']) || !is_array($lesson['notes'])) continue;
                    
                    foreach ($lesson['notes'] as $note) {
                        $this->repo->upsertNote([
                            'student_id' => $kaiId,
                            'lesson_date' => $lesson['day']['date'] ?? date('Y-m-d'),
                            'subject' => $lesson['subject']['name'] ?? 'Unbekannt',
                            'type_name' => $note['type']['name'] ?? 'Notiz',
                            'description' => $note['description'] ?? '',
                            'api_note_id' => (int)$note['id']
                        ]);
                        $stats['notes']++;
                    }
                }
            }
        }

        $this->logger->info("BesteSchuleSyncService: Sync abgeschlossen", $stats);
        return ['success' => true, 'message' => "Sync erfolgreich", 'stats' => $stats];
    }
}
