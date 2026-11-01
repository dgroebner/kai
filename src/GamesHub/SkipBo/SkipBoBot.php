<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\SkipBo;

/**
 * Autonome KI für Skip-Bo Mitspieler.
 * Trifft taktische Entscheidungen (Priorität auf Spielerstapel, Kombinationszüge, sinnvolles Ablegen).
 */
class SkipBoBot
{
    /**
     * Ermittelt den nächsten Zug für den Bot.
     *
     * Gibt entweder einen 'play'-Zug (Karte auf einen der 4 Baustapel legen)
     * oder einen 'discard'-Zug (Karte auf Hilfsstapel ablegen -> Zugende) zurück.
     */
    public static function decideNextMove(
        array $hand,
        ?array $stockTopCard,
        array $discardPiles,
        array $buildingPiles
    ): array {
        // ---------------------------------------------------------------------
        // 1. OBERSTE PRIORITÄT: Spielerstapel-Karte (Stockpile) direkt anlegen!
        // ---------------------------------------------------------------------
        if ($stockTopCard !== null) {
            foreach ($buildingPiles as $pileIdx => $pile) {
                if (self::canPlaceCardOnBuildingPile($stockTopCard, $pile)) {
                    return [
                        'action' => 'play',
                        'source' => 'stock',
                        'source_index' => 0,
                        'target_pile' => $pileIdx,
                        'reason' => 'Zielstapel abbauen',
                    ];
                }
            }
        }

        // ---------------------------------------------------------------------
        // 2. KÖNNEN WIR DEN SPIELERSTAPEL FREISPIELEN? (1-Schritt Vorausschau)
        // ---------------------------------------------------------------------
        if ($stockTopCard !== null) {
            $stockVal = $stockTopCard['type'] === SkipBoCard::TYPE_SKIPBO ? null : (int)$stockTopCard['value'];

            if ($stockVal !== null) {
                // Prüfen, ob eine Handkarte genau die Lücke zum Spielerstapel schließt
                foreach ($hand as $hIdx => $hCard) {
                    foreach ($buildingPiles as $pileIdx => $pile) {
                        if (self::canPlaceCardOnBuildingPile($hCard, $pile)) {
                            $nextValAfterPlay = ($pile['top_value'] ?? 0) + 1;
                            if ($nextValAfterPlay === 12) {
                                $nextValAfterPlay = 0; // würde geräumt
                            }
                            $neededAfter = ($nextValAfterPlay % 12) + 1;
                            if ($stockVal === $neededAfter) {
                                return [
                                    'action' => 'play',
                                    'source' => 'hand',
                                    'source_index' => $hIdx,
                                    'target_pile' => $pileIdx,
                                    'reason' => 'Zielstapel freispielen mit Handkarte',
                                ];
                            }
                        }
                    }
                }

                // Prüfen, ob eine Hilfsstapel-Karte die Lücke schließt
                foreach ($discardPiles as $dIdx => $dPile) {
                    if (empty($dPile)) continue;
                    $topDCard = end($dPile);
                    foreach ($buildingPiles as $pileIdx => $pile) {
                        if (self::canPlaceCardOnBuildingPile($topDCard, $pile)) {
                            $nextValAfterPlay = ($pile['top_value'] ?? 0) + 1;
                            if ($nextValAfterPlay === 12) {
                                $nextValAfterPlay = 0;
                            }
                            $neededAfter = ($nextValAfterPlay % 12) + 1;
                            if ($stockVal === $neededAfter) {
                                return [
                                    'action' => 'play',
                                    'source' => 'discard',
                                    'source_index' => $dIdx,
                                    'target_pile' => $pileIdx,
                                    'reason' => 'Zielstapel freispielen mit Hilfsstapel',
                                ];
                            }
                        }
                    }
                }
            }
        }

        // ---------------------------------------------------------------------
        // 3. HANDKARTEN ANLEGEN (Zahlenkarten vor Skip-Bo Jokern bevorzugen)
        // ---------------------------------------------------------------------
        // Erst Zahlenkarten prüfen
        foreach ($hand as $hIdx => $hCard) {
            if ($hCard['type'] === SkipBoCard::TYPE_SKIPBO) continue;
            foreach ($buildingPiles as $pileIdx => $pile) {
                if (self::canPlaceCardOnBuildingPile($hCard, $pile)) {
                    return [
                        'action' => 'play',
                        'source' => 'hand',
                        'source_index' => $hIdx,
                        'target_pile' => $pileIdx,
                        'reason' => 'Handkarte anlegen',
                    ];
                }
            }
        }

        // ---------------------------------------------------------------------
        // 4. HILFSSTAPEL-KARTEN ANLEGEN
        // ---------------------------------------------------------------------
        foreach ($discardPiles as $dIdx => $dPile) {
            if (empty($dPile)) continue;
            $topDCard = end($dPile);
            if ($topDCard['type'] === SkipBoCard::TYPE_SKIPBO) continue;
            foreach ($buildingPiles as $pileIdx => $pile) {
                if (self::canPlaceCardOnBuildingPile($topDCard, $pile)) {
                    return [
                        'action' => 'play',
                        'source' => 'discard',
                        'source_index' => $dIdx,
                        'target_pile' => $pileIdx,
                        'reason' => 'Hilfsstapel abbauen',
                    ];
                }
            }
        }

        // ---------------------------------------------------------------------
        // 5. SKIP-BO JOKER AUS DER HAND NUR SETZEN, WENN ES EINEN NEUEN STAPEL ÖFFNET
        // ODER EINEN 11er ZU EINEM 12er VOLLENDET
        // ---------------------------------------------------------------------
        foreach ($hand as $hIdx => $hCard) {
            if ($hCard['type'] === SkipBoCard::TYPE_SKIPBO) {
                foreach ($buildingPiles as $pileIdx => $pile) {
                    $topVal = (int)($pile['top_value'] ?? 0);
                    // Einen neuen Stapel mit Joker als 1 eröffnen oder 11 auf 12 schließen
                    if ($topVal === 0 || $topVal === 11) {
                        return [
                            'action' => 'play',
                            'source' => 'hand',
                            'source_index' => $hIdx,
                            'target_pile' => $pileIdx,
                            'reason' => 'Skip-Bo Joker taktisch einsetzen',
                        ];
                    }
                }
            }
        }

        // ---------------------------------------------------------------------
        // 6. KEIN WEITERER ZUG MÖGLICH -> ZUG BEENDEN (Discard auf Hilfsstapel)
        // ---------------------------------------------------------------------
        if (empty($hand)) {
            // Hand ist leer (z.B. alle 5 wurden gelegt und neue Handkarten gezogen)
            return ['action' => 'pass'];
        }

        $discardDecision = self::chooseBestCardToDiscard($hand, $discardPiles);
        return [
            'action' => 'discard',
            'card_id' => $discardDecision['card_id'],
            'target_discard_index' => $discardDecision['target_index'],
        ];
    }

    /**
     * Prüft, ob eine Karte legal auf einen Baustapel gelegt werden kann.
     */
    public static function canPlaceCardOnBuildingPile(array $card, array $pile): bool
    {
        $currentTop = (int)($pile['top_value'] ?? 0);
        $needed = $currentTop + 1;

        if ($needed > 12) {
            return false;
        }

        if (($card['type'] ?? '') === SkipBoCard::TYPE_SKIPBO) {
            return true;
        }

        return (int)($card['value'] ?? 0) === $needed;
    }

    /**
     * Wählt die taktisch beste Handkarte zum Abwerfen auf einen der 4 Hilfsstapel.
     */
    public static function chooseBestCardToDiscard(array $hand, array $discardPiles): array
    {
        if (empty($hand)) {
            return ['card_id' => '', 'target_index' => 0];
        }

        // 1. Vermeide Skip-Bo Joker abzuwerfen
        $candidates = array_filter($hand, fn($c) => ($c['type'] ?? '') !== SkipBoCard::TYPE_SKIPBO);
        if (empty($candidates)) {
            $candidates = $hand;
        }

        // Heuristik: Karten mit gleichem Wert auf existierende Stapel mit demselben Wert ablegen
        // oder hohe Karten auf etwas höhere (z.B. 8 auf 9, absteigend sortiert)
        $bestCard = reset($candidates);
        $bestTargetPile = 0;
        $bestScore = -9999;

        if (!$bestCard || !is_array($bestCard)) {
            $bestCard = $hand[0];
        }

        foreach ($candidates as $card) {
            $val = (int)($card['value'] ?? 1);

            for ($pIdx = 0; $pIdx < 4; $pIdx++) {
                $pile = $discardPiles[$pIdx] ?? [];
                $score = 0;

                if (empty($pile)) {
                    // Auf leeren Stapel legen: Hohe Karten (10-12) bevorzugen
                    $score = $val * 2;
                } else {
                    $topCard = end($pile);
                    $topVal = (int)($topCard['value'] ?? 0);

                    if ($val === $topVal) {
                        // Gleicher Wert aufeinander stapeln ist top (blockiert keine neuen Optionen)
                        $score = 50 + $val;
                    } elseif ($val === $topVal - 1) {
                        // Absteigend (z.B. 6 auf 7): sehr gut, da wir danach 6 vor 7 ziehen können
                        $score = 40 + $val;
                    } elseif ($val < $topVal) {
                        $score = 20 + $val;
                    } else {
                        // Aufsteigend (niedrige Karte unter hohe begraben) ist meist ungünstig
                        $score = -20 - ($val - $topVal);
                    }
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestCard = $card;
                    $bestTargetPile = $pIdx;
                }
            }
        }

        return [
            'card_id' => $bestCard['id'],
            'target_index' => $bestTargetPile,
        ];
    }
}
