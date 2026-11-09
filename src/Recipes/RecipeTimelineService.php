<?php

namespace Kai\Tools\Recipes;

/**
 * Multi-Topf Timing-Synchronisation ("Gleichzeitig fertig"-Planer) & 2-Phasen Mise en Place.
 *
 * Berechnet für alle Komponenten (z. B. Fleisch, Kartoffeln inkl. Wasser-Aufheizzeit, Gemüse, Soße)
 * die exakten Startzeitpunkte rückwärts vom gewünschten Servierzeitpunkt, damit alle Töpfe
 * gleichzeitig heiß auf dem Tisch stehen.
 */
class RecipeTimelineService
{
    /**
     * Erzeugt den synchronisierten Zeitplan für ein Rezept.
     *
     * @param array<string, mixed> $recipe Rezeptdaten (aus RecipeRepository / RecipeScaler)
     * @param string|null $targetServeTime Optionale Ziel-Uhrzeit im Format 'HH:MM' (z. B. '13:00')
     * @return array<string, mixed>
     */
    public function buildSynchronizedTimeline(array $recipe, ?string $targetServeTime = null): array
    {
        $components = $recipe['components'] ?? [];

        $miseEnPlacePre = [];
        $miseEnPlaceParallel = [];
        $servingSteps = [];
        $cookingComponents = [];

        $prePrepDurationMin = 0;

        foreach ($components as $comp) {
            $compName = (string)($comp['component_name'] ?? 'Hauptgericht');
            $pot = (string)($comp['pot_or_appliance'] ?? 'Topf / Pfanne');
            $cookSteps = [];
            $compCookDuration = 0;

            foreach ($comp['steps'] ?? [] as $step) {
                $phase = (string)($step['phase'] ?? 'cooking');
                $dur = max(0, (int)($step['duration_minutes'] ?? 0));

                $title = (string)($step['title'] ?? '');
                $instruction = (string)($step['instruction'] ?? '');
                $text = mb_strtolower($title . ' ' . $instruction);
                $hasStoveOrOven = ($step['stove_level'] ?? null) !== null || !empty($step['oven_setting']);

                $isServingAction = ($phase === 'serving') || preg_match('/\b(servieren|anrichten|auf tellern|auf den tellern|anrichteweise|guten appetit)\b/iu', $text);

                if ($isServingAction) {
                    $phase = 'serving';
                    $step['phase'] = 'serving';
                } elseif ($hasStoveOrOven) {
                    if ($phase === 'mise_en_place_pre' || $phase === 'mise_en_place_parallel') {
                        $isPreheatAction = preg_match('/\b(aufkochen|wasser aufsetzen|wasser erhitzen|wasser zum kochen|vorheizen|topf erhitzen|ofen vorheizen)\b/iu', $text);
                        $phase = $isPreheatAction ? 'preheat' : 'cooking';
                        $step['phase'] = $phase;
                    }
                }

                if ($phase === 'mise_en_place_pre') {
                    $effectiveDur = $dur > 0 ? $dur : 3;
                    $step['effective_duration_min'] = $effectiveDur;
                    $miseEnPlacePre[] = $step;
                    $prePrepDurationMin += $effectiveDur;
                } elseif ($phase === 'mise_en_place_parallel') {
                    $effectiveDur = $dur > 0 ? $dur : 5;
                    $step['effective_duration_min'] = $effectiveDur;
                    $miseEnPlaceParallel[] = $step;
                } elseif ($phase === 'serving') {
                    $effectiveDur = $dur > 0 ? $dur : 2;
                    $step['effective_duration_min'] = $effectiveDur;
                    $servingSteps[] = $step;
                } else {
                    $effectiveDur = $dur > 0 ? $dur : 2;
                    $step['effective_duration_min'] = $effectiveDur;
                    $cookSteps[] = $step;
                    $compCookDuration += $effectiveDur;
                }
            }

            if (!empty($cookSteps)) {
                $cookingComponents[] = [
                    'id' => $comp['id'] ?? 0,
                    'component_name' => $compName,
                    'pot_or_appliance' => $pot,
                    'total_cook_minutes' => $compCookDuration,
                    'steps' => $cookSteps,
                ];
            }
        }

        // Längste Komponenten-Garzeit bestimmt den kritischen Pfad ab Herd-Start
        $maxCookDurationMin = 0;
        foreach ($cookingComponents as $c) {
            if ($c['total_cook_minutes'] > $maxCookDurationMin) {
                $maxCookDurationMin = $c['total_cook_minutes'];
            }
        }

        $totalTimelineMinutes = $prePrepDurationMin + $maxCookDurationMin;

        // Ziel-Zeitpunkt (Unix-Timestamp) bestimmen, falls targetServeTime (HH:MM) übergeben wurde
        $serveTimestamp = null;
        if ($targetServeTime !== null && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($targetServeTime), $m)) {
            $now = time();
            $todayTarget = mktime((int)$m[1], (int)$m[2], 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
            if ($todayTarget !== false) {
                $serveTimestamp = $todayTarget;
            }
        }

        $startTimestamp = $serveTimestamp !== null ? ($serveTimestamp - ($totalTimelineMinutes * 60)) : null;
        $stoveStartTimestamp = $serveTimestamp !== null ? ($serveTimestamp - ($maxCookDurationMin * 60)) : null;

        $synchronizedSteps = [];
        $runningPreMin = 0;

        // 1. Phase 1: Mise en Place vor dem Herd-Start
        foreach ($miseEnPlacePre as &$preStep) {
            $dur = (int)$preStep['effective_duration_min'];
            $offsetStart = $runningPreMin;
            $offsetBeforeFinish = $totalTimelineMinutes - $offsetStart;

            $preStep['offset_from_start_min'] = $offsetStart;
            $preStep['offset_before_finish_min'] = $offsetBeforeFinish;
            $preStep['relative_label'] = 'T + ' . $offsetStart . ' Min';
            $preStep['countdown_label'] = $offsetBeforeFinish . ' Min vor dem Essen';
            $preStep['clock_time'] = $startTimestamp !== null ? date('H:i', $startTimestamp + ($offsetStart * 60)) : null;
            $preStep['sync_badge'] = '🔪 Vor dem Herd-Start';

            $synchronizedSteps[] = $preStep;
            $runningPreMin += $dur;
        }
        unset($preStep);

        // 2. Koch-Komponenten rückwärts vom Servierzeitpunkt terminieren
        $componentSummaries = [];
        $longestPassiveWindowStart = $prePrepDurationMin + 5;

        foreach ($cookingComponents as $comp) {
            $compDur = (int)$comp['total_cook_minutes'];
            // Wann muss dieser Topf starten, damit er zeitgleich mit dem längsten Topf fertig wird?
            $compStartFromStoveZero = max(0, $maxCookDurationMin - $compDur);
            $compOverallStartMin = $prePrepDurationMin + $compStartFromStoveZero;
            $compBeforeFinishMin = $compDur;

            $componentSummaries[] = [
                'component_name' => $comp['component_name'],
                'pot_or_appliance' => $comp['pot_or_appliance'],
                'duration_minutes' => $compDur,
                'start_offset_from_begin_min' => $compOverallStartMin,
                'start_before_finish_min' => $compBeforeFinishMin,
                'clock_start' => $startTimestamp !== null ? date('H:i', $startTimestamp + ($compOverallStartMin * 60)) : null,
                'clock_finish' => $serveTimestamp !== null ? date('H:i', $serveTimestamp) : null,
            ];

            $cursorMin = $compOverallStartMin;
            foreach ($comp['steps'] as $cStep) {
                $dur = (int)$cStep['effective_duration_min'];
                $beforeFinish = max(0, $totalTimelineMinutes - $cursorMin);

                if (!empty($cStep['is_passive_wait']) && $dur >= 15) {
                    $longestPassiveWindowStart = $cursorMin + 2;
                }

                $cStep['offset_from_start_min'] = $cursorMin;
                $cStep['offset_before_finish_min'] = $beforeFinish;
                $cStep['relative_label'] = 'T + ' . $cursorMin . ' Min';
                $cStep['countdown_label'] = $beforeFinish . ' Min vor dem Essen';
                $cStep['clock_time'] = $startTimestamp !== null ? date('H:i', $startTimestamp + ($cursorMin * 60)) : null;
                $cStep['sync_badge'] = '🍲 ' . $comp['component_name'] . (!empty($comp['pot_or_appliance']) ? ' (' . $comp['pot_or_appliance'] . ')' : '');

                $synchronizedSteps[] = $cStep;
                $cursorMin += $dur;
            }
        }

        // 3. Phase 2: Parallele Vorbereitungen in das passive Schmor-/Kochfenster legen
        $parallelCursor = min($longestPassiveWindowStart, max($prePrepDurationMin, $totalTimelineMinutes - 25));
        foreach ($miseEnPlaceParallel as &$parStep) {
            $dur = (int)$parStep['effective_duration_min'];
            $beforeFinish = max(0, $totalTimelineMinutes - $parallelCursor);

            $parStep['offset_from_start_min'] = $parallelCursor;
            $parStep['offset_before_finish_min'] = $beforeFinish;
            $parStep['relative_label'] = 'T + ' . $parallelCursor . ' Min';
            $parStep['countdown_label'] = $beforeFinish . ' Min vor dem Essen';
            $parStep['clock_time'] = $startTimestamp !== null ? date('H:i', $startTimestamp + ($parallelCursor * 60)) : null;
            $parStep['sync_badge'] = '⏳ Parallel in der Kochpause';

            $synchronizedSteps[] = $parStep;
            $parallelCursor += $dur;
        }
        unset($parStep);

        // 4. Phase 3: Servieren am Ende der Kochzeit (nachdem alle Töpfe fertig sind!)
        $servingCursor = $prePrepDurationMin + $maxCookDurationMin;
        foreach ($servingSteps as &$srvStep) {
            $dur = (int)$srvStep['effective_duration_min'];
            $srvStep['offset_from_start_min'] = $servingCursor;
            $srvStep['offset_before_finish_min'] = 0;
            $srvStep['relative_label'] = 'T + ' . $servingCursor . ' Min';
            $srvStep['countdown_label'] = 'Fertig zum Essen';
            $srvStep['clock_time'] = $serveTimestamp !== null ? date('H:i', $serveTimestamp) : ($startTimestamp !== null ? date('H:i', $startTimestamp + ($servingCursor * 60)) : null);
            $srvStep['sync_badge'] = '🍽️ Servieren';

            $synchronizedSteps[] = $srvStep;
            $servingCursor += $dur;
        }
        unset($srvStep);

        // Chronologisch nach Start-Offset sortieren
        usort($synchronizedSteps, static function (array $a, array $b): int {
            $cmp = ($a['offset_from_start_min'] <=> $b['offset_from_start_min']);
            if ($cmp !== 0) {
                return $cmp;
            }

            // Innerhalb derselben Komponente (z. B. Nudeln: 1. Wasser aufkochen, 2. Nudeln kochen)
            // muss die Reihenfolge der Schrittnummern zwingend erhalten bleiben!
            $compA = (string)($a['component_name'] ?? '');
            $compB = (string)($b['component_name'] ?? '');
            if ($compA !== '' && $compA === $compB) {
                $stepCmp = ((int)($a['step_number'] ?? 0) <=> (int)($b['step_number'] ?? 0));
                if ($stepCmp !== 0) {
                    return $stepCmp;
                }
            }

            // Bei gleicher Minute: Vorbereitung vor Aufheizen vor Kochen vor Kochpause
            $prio = [
                'mise_en_place_pre' => 1,
                'preheat' => 2,
                'cooking' => 3,
                'mise_en_place_parallel' => 4,
                'resting' => 5,
                'serving' => 6,
            ];
            $prioCmp = ($prio[$a['phase'] ?? 'cooking'] ?? 3) <=> ($prio[$b['phase'] ?? 'cooking'] ?? 3);
            if ($prioCmp !== 0) {
                return $prioCmp;
            }

            return ((int)($a['step_number'] ?? 0) <=> (int)($b['step_number'] ?? 0));
        });

        // Durchgehende Synchron-Schrittnummer vergeben
        foreach ($synchronizedSteps as $idx => &$sStep) {
            $sStep['sync_step_index'] = $idx + 1;
        }
        unset($sStep);

        return [
            'target_serve_time' => $serveTimestamp !== null ? date('H:i', $serveTimestamp) : null,
            'recommended_start_time' => $startTimestamp !== null ? date('H:i', $startTimestamp) : null,
            'stove_start_time' => $stoveStartTimestamp !== null ? date('H:i', $stoveStartTimestamp) : null,
            'pre_prep_minutes' => $prePrepDurationMin,
            'max_cook_minutes' => $maxCookDurationMin,
            'total_synchronized_minutes' => $totalTimelineMinutes,
            'mise_en_place_pre' => $miseEnPlacePre,
            'mise_en_place_parallel' => $miseEnPlaceParallel,
            'component_summaries' => $componentSummaries,
            'synchronized_steps' => $synchronizedSteps,
        ];
    }
}
