<?php

namespace Kai\Tools\School;

use SimpleXMLElement;
use Throwable;

/**
 * Parser für VPPlan24 XML-Dateien (stundenplan24.de).
 */
class VPPlanParser
{
    /**
     * Parst den XML-Inhalt und liefert eine strukturierte Datenrepräsentation.
     *
     * @return array{
     *     plan_date: string,
     *     plan_timestamp: ?string,
     *     date_text: ?string,
     *     school_week: ?string,
     *     free_days: array<int, string>,
     *     global_notes: array<int, string>,
     *     items: array<int, array<string, mixed>>,
     *     raw_hash: string
     * }|null
     */
    public function parse(string $xmlContent): ?array
    {
        $cleanXml = trim($xmlContent);
        if ($cleanXml === '') {
            return null;
        }

        try {
            // Warnungen unterdrücken und XML laden
            $prevErrors = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($cleanXml);
            libxml_clear_errors();
            libxml_use_internal_errors($prevErrors);

            if (!$xml instanceof SimpleXMLElement) {
                return null;
            }

            // 1. Datum ermitteln (bevorzugt aus dem Dateinamen PlanKlYYYYMMDD.xml)
            $filename = (string)($xml->Kopf->datei ?? '');
            $planDate = null;
            if (preg_match('/PlanKl(\d{4})(\d{2})(\d{2})\.xml/i', $filename, $m)) {
                $planDate = "{$m[1]}-{$m[2]}-{$m[3]}";
            }

            if ($planDate === null) {
                // Fallback: heute
                $planDate = date('Y-m-d');
            }

            $timestamp = !empty($xml->Kopf->zeitstempel) ? trim((string)$xml->Kopf->zeitstempel) : null;
            $dateText = !empty($xml->Kopf->DatumPlan) ? trim((string)$xml->Kopf->DatumPlan) : null;
            $week = !empty($xml->Kopf->woche) ? trim((string)$xml->Kopf->woche) : null;

            // 2. Schulfreie Tage
            $freeDays = [];
            if (isset($xml->FreieTage->ft)) {
                foreach ($xml->FreieTage->ft as $ft) {
                    $val = trim((string)$ft);
                    if ($val !== '') {
                        $freeDays[] = $val;
                    }
                }
            }

            // 3. Schulweite Durchsagen (<ZusatzInfo>)
            $globalNotes = [];
            if (isset($xml->ZusatzInfo->ZiZeile)) {
                foreach ($xml->ZusatzInfo->ZiZeile as $line) {
                    $val = trim((string)$line);
                    if ($val !== '') {
                        $globalNotes[] = $val;
                    }
                }
            }

            // 4. Klassen & Unterrichtsstunden parsen
            $items = [];
            if (isset($xml->Klassen->Kl)) {
                foreach ($xml->Klassen->Kl as $kl) {
                    $className = strtoupper(trim((string)($kl->Kurz ?? '')));
                    if ($className === '') {
                        continue;
                    }

                    if (!isset($kl->Pl->Std)) {
                        continue;
                    }

                    foreach ($kl->Pl->Std as $std) {
                        $stunde = (int)($std->St ?? 0);
                        $beginn = trim((string)($std->Beginn ?? ''));
                        $ende = trim((string)($std->Ende ?? ''));

                        // Fach & Änderung
                        $subject = trim((string)($std->Fa ?? ''));
                        $subjectChanged = isset($std->Fa['FaAe']);

                        // Lehrer & Vertretung
                        $teacher = trim((string)($std->Le ?? ''));
                        $teacherChanged = isset($std->Le['LeAe']);

                        // Raum & Verlegung
                        $room = trim((string)($std->Ra ?? ''));
                        $roomChanged = isset($std->Ra['RaAe']);

                        // Kursgruppe & Infotext
                        $courseGroup = trim((string)($std->Ku2 ?? ''));
                        $info = trim((string)($std->If ?? ''));

                        // Semantische Flags ermitteln
                        $isCancelled = ($subject === '---' || stripos($info, 'fällt aus') !== false);
                        $isMoved = (stripos($info, 'verlegt') !== false || stripos($info, 'statt ') !== false);
                        $isSubstitution = ($teacherChanged || stripos($info, 'für ') !== false || stripos($info, 'Vertretung') !== false);
                        $isRoomChange = ($roomChanged && !$isCancelled);

                        $items[] = [
                            'plan_date' => $planDate,
                            'class_name' => $className,
                            'lesson_number' => $stunde,
                            'start_time' => $beginn,
                            'end_time' => $ende,
                            'subject' => $subject,
                            'subject_original' => null,
                            'teacher' => $teacher,
                            'teacher_original' => null,
                            'room' => $room,
                            'room_original' => null,
                            'course_group' => $courseGroup !== '' ? $courseGroup : null,
                            'info' => $info !== '' ? $info : null,
                            'is_cancelled' => $isCancelled ? 1 : 0,
                            'is_substitution' => $isSubstitution ? 1 : 0,
                            'is_room_change' => $isRoomChange ? 1 : 0,
                            'is_moved' => $isMoved ? 1 : 0,
                        ];
                    }
                }
            }

            return [
                'plan_date' => $planDate,
                'plan_timestamp' => $timestamp,
                'date_text' => $dateText,
                'school_week' => $week,
                'free_days' => $freeDays,
                'global_notes' => $globalNotes,
                'items' => $items,
                'raw_hash' => hash('sha256', $cleanXml),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}
