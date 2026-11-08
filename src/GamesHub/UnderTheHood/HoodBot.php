<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\UnderTheHood;

class HoodBot
{
    private HoodService $service;

    public function __construct(HoodService $service)
    {
        $this->service = $service;
    }

    /**
     * Führt einen kompletten Zug für einen KI-Fahrer aus.
     */
    public function playTurn(int $roomId, int $botPlayerId, int $depth = 0): array
    {
        // Schutz vor unendlicher Rekursion bei reinen Bot-Spielen
        if ($depth > 5) {
            return ['success' => true];
        }

        // 1. Raumdaten holen
        $reflector = new \ReflectionClass($this->service);
        $repoProp = $reflector->getProperty('repo');
        $repoProp->setAccessible(true);
        $repo = $repoProp->getValue($this->service);

        $room = $repo->getRoomById($roomId);
        if (!$room || ($room['state']['status'] ?? '') !== 'active') {
            return ['success' => false, 'message' => 'Spiel nicht aktiv'];
        }

        $state = $room['state'];
        $player = null;
        foreach ($state['players'] as $p) {
            if ($p['id'] === $botPlayerId) {
                $player = $p;
                break;
            }
        }

        if (!$player || !$player['is_bot'] || $player['bankrupt']) {
            return ['success' => false, 'message' => 'Kein aktiver Bot'];
        }

        // Falls Bot auf Hebebühne: Kaution zahlen, wenn genug Cash
        if ($player['in_jail'] && $player['cash'] >= 250) {
            $this->service->payBail($roomId, $botPlayerId);
            $room = $repo->getRoomById($roomId);
            $state = $room['state'];
        }

        // 2. Würfeln
        if ($state['phase'] === 'ROLL') {
            $res = $this->service->rollDice($roomId, $botPlayerId);
            if (!$res['success']) {
                return $res;
            }
            $state = $res['state'];
        }

        // 3. Kauf-Entscheidung treffen
        if ($state['phase'] === 'BUY_DECISION') {
            $currentTile = $state['players'][$state['current_player_index']]['position'];
            $tileConfig = HoodConfig::getTiles()[$currentTile];
            $price = $tileConfig['price'] ?? 100;
            $botCash = $state['players'][$state['current_player_index']]['cash'];

            // Bot kauft, wenn er nach dem Kauf noch mindestens 150 $ Puffer hat
            // oder wenn es eine Farbgruppe vervollständigt
            $safetyBuffer = 150;
            if ($botCash >= ($price + $safetyBuffer) || $botCash >= $price) {
                $this->service->buyCurrentTile($roomId, $botPlayerId);
            } else {
                $this->service->passCurrentTile($roomId, $botPlayerId);
            }

            $room = $repo->getRoomById($roomId);
            $state = $room['state'];
        }

        // 4. Bau-Entscheidung: Prüfen, ob der Bot Straßen ausbauen kann
        $botCash = $state['players'][$state['current_player_index']]['cash'];
        if ($botCash >= 350) {
            foreach ($state['tiles'] as $idx => $t) {
                if ($t['owner'] === $botPlayerId && ($t['level'] ?? 0) >= 1 && ($t['level'] ?? 0) < 5) {
                    $buildRes = $this->service->buildHouse($roomId, $botPlayerId, $idx);
                    if ($buildRes['success']) {
                        $state = $buildRes['state'];
                        break;
                    }
                }
            }
        }

        // 5. Zug beenden
        if ($state['phase'] === 'TURN_END' || !empty($state['can_roll_again'])) {
            return $this->service->endTurn($roomId, $botPlayerId, $depth + 1);
        }

        return ['success' => true, 'state' => $state];
    }
}
