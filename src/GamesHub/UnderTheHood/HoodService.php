<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\UnderTheHood;

use Kai\Tools\GamesHub\RoomRepository;

class HoodService
{
    private RoomRepository $repo;

    public function __construct(?RoomRepository $repo = null)
    {
        $this->repo = $repo ?? new RoomRepository();
    }

    /**
     * Erstellt den initialen Zustand für eine Under-the-Hood Partie.
     */
    public function createInitialState(array $options = []): array
    {
        $tiles = HoodConfig::getTiles();
        $boardTiles = [];
        foreach ($tiles as $idx => $t) {
            $boardTiles[$idx] = [
                'index'      => $idx,
                'name'       => $t['name'],
                'type'       => $t['type'],
                'group'      => $t['group'] ?? null,
                'price'      => $t['price'] ?? 0,
                'house_cost' => $t['house_cost'] ?? 100,
                'rent'       => $t['rent'] ?? null,
                'owner'      => null, // Player ID
                'level'      => 0,    // 0 = unberührt, 1 = erschlossen, 2..4 = 1..3 Häuser, 5 = Hotel
                'mortgaged'  => false,
            ];
        }

        $radioIndices = range(0, count(HoodConfig::getRadioCards()) - 1);
        shuffle($radioIndices);

        $gloveboxIndices = range(0, count(HoodConfig::getGloveboxCards()) - 1);
        shuffle($gloveboxIndices);

        return [
            'status'               => 'lobby',
            'is_hotseat'           => !empty($options['is_hotseat']),
            'round'                => 1,
            'current_player_index' => 0,
            'phase'                => 'ROLL', // ROLL, BUY_DECISION, TURN_END
            'last_dice'            => [1, 1],
            'doubles_count'        => 0,
            'can_roll_again'       => false,
            'tiles'                => $boardTiles,
            'players'              => [],
            'radio_deck'           => $radioIndices,
            'glovebox_deck'        => $gloveboxIndices,
            'last_card'            => null,
            'last_action'          => null,
            'parking_pot'          => 100,
            'active_trade'         => null,
            'jail_alert'           => null,
            'log'                  => [
                ['time' => date('H:i'), 'text' => '🏎️ Willkommen in der Hood! Erstelle dein Fahrzeug und lade Mitspieler ein.']
            ],
            'winner_id'            => null,
        ];
    }

    /**
     * Startet das Spiel aus der Lobby.
     */
    public function startGame(int $roomId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];
        if (count($state['players']) < 2) {
            return ['success' => false, 'message' => 'Mindestens 2 Spieler oder KI-Fahrer erforderlich, um zu starten!'];
        }

        $state['status'] = 'active';
        $state['phase'] = 'ROLL';
        $state['current_player_index'] = 0;
        $state['log'][] = [
            'time' => date('H:i'),
            'text' => '🏁 Die Motoren dröhnen! Die Partie beginnt. ' . $state['players'][0]['name'] . ' ist am Zug.'
        ];

        $this->repo->updateRoomState($roomId, $state, 'active');
        return ['success' => true, 'state' => $state];
    }

    /**
     * Fügt einen Spieler hinzu.
     */
    public function addPlayer(int $roomId, string $playerTokenHash, string $name, string $vehicle, string $color, bool $isHost = false): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];

        // Prüfen, ob bereits beigetreten
        foreach ($state['players'] as &$p) {
            if ($p['token_hash'] === $playerTokenHash) {
                $p['name'] = $name;
                $p['vehicle'] = $vehicle;
                $p['color'] = $color;
                $this->repo->updateRoomState($roomId, $state);
                return ['success' => true, 'player' => $p, 'state' => $state];
            }
        }

        if (count($state['players']) >= 6) {
            return ['success' => false, 'message' => 'Maximale Spieleranzahl (6) erreicht'];
        }

        // Automatische Zuweisung einer noch freien Farbe
        $colors = HoodConfig::PLAYER_COLORS;
        $usedColors = array_column($state['players'], 'color');
        if (empty($color) || in_array($color, $usedColors, true)) {
            $chosenColor = $colors[count($state['players']) % count($colors)];
            foreach ($colors as $c) {
                if (!in_array($c, $usedColors, true)) {
                    $chosenColor = $c;
                    break;
                }
            }
            $color = $chosenColor;
        }

        // Automatische Zuweisung eines noch freien Fahrzeugs
        $vehicles = array_keys(HoodConfig::VEHICLES);
        $usedVehicles = array_column($state['players'], 'vehicle');
        if (empty($vehicle) || in_array($vehicle, $usedVehicles, true)) {
            $chosenVehicle = $vehicles[count($state['players']) % count($vehicles)];
            foreach ($vehicles as $v) {
                if (!in_array($v, $usedVehicles, true)) {
                    $chosenVehicle = $v;
                    break;
                }
            }
            $vehicle = $chosenVehicle;
        }

        $playerId = count($state['players']) + 1;
        $newPlayer = [
            'id'               => $playerId,
            'token_hash'       => $playerTokenHash,
            'name'             => $name,
            'vehicle'          => $vehicle,
            'color'            => $color,
            'cash'             => HoodConfig::STARTING_CASH,
            'position'         => 0,
            'in_jail'          => false,
            'jail_turns'       => 0,
            'jail_free_cards'  => 0,
            'is_bot'           => false,
            'bankrupt'         => false,
            'is_host'          => $isHost,
        ];

        $state['players'][] = $newPlayer;
        $state['log'][] = [
            'time' => date('H:i'),
            'text' => '🚗 ' . $name . ' rollt mit einem ' . (HoodConfig::VEHICLES[$vehicle]['name'] ?? 'Wagen') . ' an die Startlinie!'
        ];

        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'player' => $newPlayer, 'state' => $state];
    }

    /**
     * Fügt einen weiteren lokalen menschlichen Spieler für den Hot-Seat-Modus hinzu.
     */
    public function addLocalPlayer(int $roomId, string $name, string $vehicle, string $color): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];

        if (count($state['players']) >= 6) {
            return ['success' => false, 'message' => 'Maximale Spieleranzahl (6) erreicht'];
        }

        // Automatische Zuweisung einer noch freien Farbe
        $colors = HoodConfig::PLAYER_COLORS;
        $usedColors = array_column($state['players'], 'color');
        if (empty($color) || in_array($color, $usedColors, true)) {
            $chosenColor = $colors[count($state['players']) % count($colors)];
            foreach ($colors as $c) {
                if (!in_array($c, $usedColors, true)) {
                    $chosenColor = $c;
                    break;
                }
            }
            $color = $chosenColor;
        }

        // Automatische Zuweisung eines noch freien Fahrzeugs
        $vehicles = array_keys(HoodConfig::VEHICLES);
        $usedVehicles = array_column($state['players'], 'vehicle');
        if (empty($vehicle) || in_array($vehicle, $usedVehicles, true)) {
            $chosenVehicle = $vehicles[count($state['players']) % count($vehicles)];
            foreach ($vehicles as $v) {
                if (!in_array($v, $usedVehicles, true)) {
                    $chosenVehicle = $v;
                    break;
                }
            }
            $vehicle = $chosenVehicle;
        }

        $playerId = count($state['players']) + 1;
        $newPlayer = [
            'id'               => $playerId,
            'token_hash'       => 'local_' . bin2hex(random_bytes(8)),
            'name'             => $name,
            'vehicle'          => $vehicle,
            'color'            => $color,
            'cash'             => HoodConfig::STARTING_CASH,
            'position'         => 0,
            'in_jail'          => false,
            'jail_turns'       => 0,
            'jail_free_cards'  => 0,
            'is_bot'           => false,
            'bankrupt'         => false,
            'is_host'          => false,
        ];

        $state['players'][] = $newPlayer;
        $state['log'][] = [
            'time' => date('H:i'),
            'text' => '🛋️ ' . $name . ' (' . (HoodConfig::VEHICLES[$vehicle]['name'] ?? 'Wagen') . ') sitzt mit am Steuer (Hot-Seat)!'
        ];

        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'player' => $newPlayer, 'state' => $state];
    }

    /**
     * Fügt einen Bot-Fahrer hinzu.
     */
    public function addBot(int $roomId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];

        if (count($state['players']) >= 6) {
            return ['success' => false, 'message' => 'Maximale Spieleranzahl erreicht'];
        }

        $botNames = ['Bot Bruno', 'Bot Sally', 'Bot Rico', 'Bot Roxxy', 'Bot Turbo', 'Bot Axel'];
        $vehicles = array_keys(HoodConfig::VEHICLES);
        $colors = HoodConfig::PLAYER_COLORS;

        $usedNames = array_column($state['players'], 'name');
        $usedColors = array_column($state['players'], 'color');

        $chosenName = 'Bot ' . (count($state['players']) + 1);
        foreach ($botNames as $bn) {
            if (!in_array($bn, $usedNames, true)) {
                $chosenName = $bn;
                break;
            }
        }

        $chosenColor = $colors[count($state['players']) % count($colors)];
        foreach ($colors as $c) {
            if (!in_array($c, $usedColors, true)) {
                $chosenColor = $c;
                break;
            }
        }

        $chosenVehicle = $vehicles[count($state['players']) % count($vehicles)];

        $playerId = count($state['players']) + 1;
        $botPlayer = [
            'id'               => $playerId,
            'token_hash'       => 'bot_' . bin2hex(random_bytes(8)),
            'name'             => $chosenName,
            'vehicle'          => $chosenVehicle,
            'color'            => $chosenColor,
            'cash'             => HoodConfig::STARTING_CASH,
            'position'         => 0,
            'in_jail'          => false,
            'jail_turns'       => 0,
            'jail_free_cards'  => 0,
            'is_bot'           => true,
            'bankrupt'         => false,
            'is_host'          => false,
        ];

        $state['players'][] = $botPlayer;
        $state['log'][] = [
            'time' => date('H:i'),
            'text' => '🤖 ' . $chosenName . ' (' . (HoodConfig::VEHICLES[$chosenVehicle]['name'] ?? 'Wagen') . ') tritt der Runde bei.'
        ];

        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'bot' => $botPlayer, 'state' => $state];
    }

    /**
     * Führt den Würfelwurf für den aktiven Spieler aus.
     */
    public function rollDice(int $roomId, int $playerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];
        $player = &$state['players'][$state['current_player_index']];

        if ($player['id'] !== $playerId) {
            return ['success' => false, 'message' => 'Du bist nicht an der Reihe'];
        }
        if ($state['phase'] !== 'ROLL') {
            return ['success' => false, 'message' => 'Würfeln in dieser Phase nicht möglich'];
        }

        $d1 = random_int(1, 6);
        $d2 = random_int(1, 6);
        $roll = $d1 + $d2;
        $isDoubles = ($d1 === $d2);
        $state['last_dice'] = [$d1, $d2];
        $state['last_card'] = null;

        // Fall A: Spieler sitzt in der Verwahrstelle / Werkstatt
        if ($player['in_jail']) {
            if ($isDoubles) {
                $player['in_jail'] = false;
                $player['jail_turns'] = 0;
                $state['log'][] = [
                    'time' => date('H:i'),
                    'text' => "🎉 {$player['name']} würfelt Pasch ({$d1}+{$d2}) und verlässt die Werkstatt!"
                ];
                return $this->advancePlayer($roomId, $state, $player, $roll, false);
            }

            $player['jail_turns']++;
            if ($player['jail_turns'] >= HoodConfig::MAX_JAIL_TURNS) {
                // Zwangs-Auslöse
                $player['cash'] -= HoodConfig::BAIL_COST;
                $state['parking_pot'] = ($state['parking_pot'] ?? 0) + HoodConfig::BAIL_COST;
                $player['in_jail'] = false;
                $player['jail_turns'] = 0;
                $state['log'][] = [
                    'time' => date('H:i'),
                    'text' => "🔧 {$player['name']} zahlt 50 $ Reparatur-Kaution nach 3 Runden (wandert in den Jackpot) und fährt weiter."
                ];
                return $this->advancePlayer($roomId, $state, $player, $roll, false);
            }

            $state['phase'] = 'TURN_END';
            $state['log'][] = [
                'time' => date('H:i'),
                'text' => "⏳ {$player['name']} würfelt {$d1}+{$d2} (kein Pasch) und bleibt auf der Hebebühne ({$player['jail_turns']}/3)."
            ];
            $this->repo->updateRoomState($roomId, $state);
            return ['success' => true, 'state' => $state];
        }

        // Fall B: Normaler Zug
        if ($isDoubles) {
            $state['doubles_count']++;
            if ($state['doubles_count'] >= 3) {
                // 3x Pasch = Radarfalle!
                $player['position'] = 10;
                $player['in_jail'] = true;
                $player['jail_turns'] = 0;
                $state['doubles_count'] = 0;
                $state['can_roll_again'] = false;
                $state['phase'] = 'TURN_END';
                $state['jail_alert'] = [
                    'player_name' => $player['name'],
                    'reason'      => '🚨 3x Pasch hintereinander gewürfelt! Radarfalle! Du wirst sofort auf die Hebebühne geschleppt!'
                ];
                $state['log'][] = [
                    'time' => date('H:i'),
                    'text' => "🚨 3x Pasch! {$player['name']} raste mit Überschall und wird sofort in die Werkstatt abgeschleppt!"
                ];
                $this->repo->updateRoomState($roomId, $state);
                return ['success' => true, 'state' => $state];
            }
            $state['can_roll_again'] = true;
        } else {
            $state['doubles_count'] = 0;
            $state['can_roll_again'] = false;
        }

        return $this->advancePlayer($roomId, $state, $player, $roll, $isDoubles);
    }

    /**
     * Bewegt das Fahrzeug vorwärts und wickelt das Zielfeld ab.
     */
    private function advancePlayer(int $roomId, array &$state, array &$player, int $steps, bool $isDoubles): array
    {
        $oldPos = $player['position'];
        $newPos = ($oldPos + $steps) % 40;
        $player['position'] = $newPos;

        // Startfeld passiert oder genau gelandet?
        if ($newPos === 0) {
            // Punktlandung auf START & ZIEL: Doppeltes Gehalt (400 $)
            $doubleReward = HoodConfig::GO_REWARD * 2;
            $player['cash'] += $doubleReward;
            $state['log'][] = [
                'time' => date('H:i'),
                'text' => "🎯🎯 Punktlandung auf START & ZIEL! {$player['name']} kassiert den doppelten Bonus von {$doubleReward} $!"
            ];
        } elseif ($newPos < $oldPos) {
            $player['cash'] += HoodConfig::GO_REWARD;
            $state['log'][] = [
                'time' => date('H:i'),
                'text' => "🏁 {$player['name']} passiert START & ZIEL und streicht " . HoodConfig::GO_REWARD . " $ Prämie ein!"
            ];
        }

        $tileConfig = HoodConfig::getTiles()[$newPos];
        $tileState = &$state['tiles'][$newPos];

        $state['log'][] = [
            'time' => date('H:i'),
            'text' => "🎲 {$player['name']} zieht {$steps} Felder auf '{$tileConfig['name']}'."
        ];

        // Feld-Typen abwickeln
        switch ($tileConfig['type']) {
            case 'start':
            case 'jail':
                $state['phase'] = 'TURN_END';
                break;

            case 'parking':
                $pot = (int)($state['parking_pot'] ?? 0);
                if ($pot > 0) {
                    $player['cash'] += $pot;
                    $state['parking_pot'] = 0;
                    $state['log'][] = [
                        'time' => date('H:i'),
                        'text' => "🎰 FREI PARKEN JACKPOT! {$player['name']} räumt den gesamten Jackpot von {$pot} $ ab!"
                    ];
                } else {
                    $state['log'][] = [
                        'time' => date('H:i'),
                        'text' => "🎬 {$player['name']} entspannt am Autokino (Frei Parken). Der Jackpot ist aktuell leer."
                    ];
                }
                $state['phase'] = 'TURN_END';
                break;

            case 'tax':
                $tax = $tileConfig['tax'];
                $player['cash'] -= $tax;
                $state['parking_pot'] = ($state['parking_pot'] ?? 0) + $tax;
                $state['phase'] = 'TURN_END';
                $state['log'][] = [
                    'time' => date('H:i'),
                    'text' => "🛑 {$player['name']} zahlt {$tax} $ {$tileConfig['name']} (wandert in den Frei-Parken-Jackpot)."
                ];
                $this->checkBankruptcy($state, $player);
                break;

            case 'go_to_jail':
                $player['position'] = 10;
                $player['in_jail'] = true;
                $player['jail_turns'] = 0;
                $state['doubles_count'] = 0;
                $state['can_roll_again'] = false;
                $state['phase'] = 'TURN_END';
                $state['jail_alert'] = [
                    'player_name' => $player['name'],
                    'reason'      => "🚓 Radarfalle auf Feld 30 ('Gehe in die Werkstatt')! Du wirst direkt auf die Hebebühne geschleppt!"
                ];
                $state['log'][] = [
                    'time' => date('H:i'),
                    'text' => "🚓 Radarfalle auf Feld 30! {$player['name']} wird direkt in die Werkstatt geschleppt!"
                ];
                break;

            case 'card':
                $deck = $tileConfig['deck'];
                $this->drawCard($state, $player, $deck);
                break;

            case 'street':
            case 'beach':
            case 'club':
                if ($tileState['owner'] === null) {
                    // Frei: Spieler kann kaufen
                    $state['phase'] = 'BUY_DECISION';
                } elseif ($tileState['owner'] === $player['id']) {
                    // Eigenes Feld
                    $state['phase'] = 'TURN_END';
                } else {
                    // Fremdes Feld: Miete fällig
                    $ownerIndex = $this->getPlayerIndexById($state, $tileState['owner']);
                    if ($ownerIndex !== null && !$tileState['mortgaged']) {
                        $owner = &$state['players'][$ownerIndex];
                        $rent = $this->calculateRent($state, $newPos, $state['last_dice'][0] + $state['last_dice'][1]);
                        
                        $player['cash'] -= $rent;
                        $owner['cash'] += $rent;

                        $state['log'][] = [
                            'time' => date('H:i'),
                            'text' => "💸 {$player['name']} zahlt {$rent} $ Miete an {$owner['name']} für {$tileConfig['name']}!"
                        ];

                        $this->checkBankruptcy($state, $player, $owner);
                    }
                    $state['phase'] = 'TURN_END';
                }
                break;
        }

        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'state' => $state];
    }

    /**
     * Kauft das aktuelle Grundstück.
     */
    public function buyCurrentTile(int $roomId, int $playerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];
        $player = &$state['players'][$state['current_player_index']];

        if ($player['id'] !== $playerId) {
            return ['success' => false, 'message' => 'Nicht an der Reihe'];
        }
        if ($state['phase'] !== 'BUY_DECISION') {
            return ['success' => false, 'message' => 'Kauf in dieser Phase nicht möglich'];
        }

        $tileIdx = $player['position'];
        $tileConfig = HoodConfig::getTiles()[$tileIdx];
        $tileState = &$state['tiles'][$tileIdx];

        if ($tileState['owner'] !== null) {
            return ['success' => false, 'message' => 'Bereits im Besitz'];
        }

        $price = $tileConfig['price'] ?? 0;
        if ($player['cash'] < $price) {
            return ['success' => false, 'message' => 'Nicht genügend Bares'];
        }

        $player['cash'] -= $price;
        $tileState['owner'] = $player['id'];
        $tileState['level'] = 1; // 1 = Erschlossen mit Zaun und Fahne!
        $state['phase'] = 'TURN_END';

        $state['log'][] = [
            'time' => date('H:i'),
            'text' => "🏡 {$player['name']} kauft '{$tileConfig['name']}' für {$price} $! Grundstück wird erschlossen."
        ];

        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'state' => $state];
    }

    /**
     * Überspringt den Kauf.
     */
    public function passCurrentTile(int $roomId, int $playerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];
        $player = &$state['players'][$state['current_player_index']];

        if ($player['id'] !== $playerId) {
            return ['success' => false, 'message' => 'Nicht an der Reihe'];
        }
        if ($state['phase'] !== 'BUY_DECISION') {
            return ['success' => false, 'message' => 'Aktion ungültig'];
        }

        $state['phase'] = 'TURN_END';
        $state['log'][] = [
            'time' => date('H:i'),
            'text' => "💨 {$player['name']} lässt das Grundstück links liegen und fährt weiter."
        ];

        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'state' => $state];
    }

    /**
     * Baut ein Haus / Hotel auf einer Straße.
     */
    public function buildHouse(int $roomId, int $playerId, int $tileIndex): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];
        $player = &$state['players'][$this->getPlayerIndexById($state, $playerId)];
        $tileConfig = HoodConfig::getTiles()[$tileIndex] ?? null;
        $tileState = &$state['tiles'][$tileIndex];

        if (!$tileConfig || $tileConfig['type'] !== 'street') {
            return ['success' => false, 'message' => 'Nur auf Straßen kann gebaut werden'];
        }
        if ($tileState['owner'] !== $playerId) {
            return ['success' => false, 'message' => 'Gehört dir nicht'];
        }
        if ($tileState['mortgaged']) {
            return ['success' => false, 'message' => 'Grundstück ist beliehen'];
        }

        // Monopol-Check (Alle Straßen der Farbgruppe müssen dem Spieler gehören)
        $group = $tileConfig['group'];
        if (!$this->hasFullGroup($state, $playerId, $group)) {
            return ['success' => false, 'message' => 'Du benötigst alle Straßen dieser Farbgruppe zum Bauen!'];
        }

        // Maximal 5 Stufen (1 = Erschlossen, 2..4 = 1..3 Häuser, 5 = Hotel)
        if ($tileState['level'] >= 5) {
            return ['success' => false, 'message' => 'Bereits voll ausgebaut (Hotel)'];
        }

        $cost = $tileConfig['house_cost'] ?? 100;
        if ($player['cash'] < $cost) {
            return ['success' => false, 'message' => "Zu wenig Cash ({$cost} $ benötigt)"];
        }

        // Gleichmäßiges Bauen prüfen
        foreach ($state['tiles'] as $t) {
            if (($t['group'] ?? null) === $group && $t['level'] < $tileState['level']) {
                return ['success' => false, 'message' => 'Du musst gleichmäßig in der Farbgruppe bauen!'];
            }
        }

        $player['cash'] -= $cost;
        $tileState['level']++;

        $levelNames = [
            2 => 'ein Einfamilienhaus 🏡',
            3 => 'eine gehobene Stadtvilla 🏛️',
            4 => 'eine Luxus-Designer-Residenz mit Pool 🏰',
            5 => 'ein prachtvolles Grand Schloss & Palais 👑',
        ];
        $stufenText = $levelNames[$tileState['level']] ?? 'eine Ausbaustufe';

        $state['log'][] = [
            'time' => date('H:i'),
            'text' => "🏗️ {$player['name']} investiert {$cost} $ und baut {$stufenText} auf '{$tileConfig['name']}'!"
        ];

        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'state' => $state];
    }

    /**
     * Beleiht ein Grundstück oder löst eine Hypothek wieder ab.
     */
    public function toggleMortgage(int $roomId, int $playerId, int $tileIndex): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];
        $player = &$state['players'][$this->getPlayerIndexById($state, $playerId)];
        $tileConfig = HoodConfig::getTiles()[$tileIndex] ?? null;
        $tileState = &$state['tiles'][$tileIndex];

        if ($tileState['owner'] !== $playerId) {
            return ['success' => false, 'message' => 'Gehört dir nicht'];
        }

        $mortgageVal = $tileConfig['mortgage'] ?? (int)(($tileConfig['price'] ?? 100) / 2);

        if ($tileState['mortgaged']) {
            // Ablösen: 10% Zinsen
            $repayCost = (int)ceil($mortgageVal * 1.10);
            if ($player['cash'] < $repayCost) {
                return ['success' => false, 'message' => "Zu wenig Cash zum Auslösen ({$repayCost} $ fällig)"];
            }
            $player['cash'] -= $repayCost;
            $tileState['mortgaged'] = false;
            $state['log'][] = [
                'time' => date('H:i'),
                'text' => "🏦 {$player['name']} löst Hypothek auf '{$tileConfig['name']}' für {$repayCost} $ ab."
            ];
        } else {
            // Beleihen: Nur wenn keine Häuser auf der Gruppe stehen
            if ($tileState['level'] > 1) {
                return ['success' => false, 'message' => 'Zuerst müssen alle Gebäude abgerissen werden'];
            }
            $player['cash'] += $mortgageVal;
            $tileState['mortgaged'] = true;
            $state['log'][] = [
                'time' => date('H:i'),
                'text' => "📉 {$player['name']} beleiht '{$tileConfig['name']}' und erhält {$mortgageVal} $ Notfall-Cash."
            ];
        }

        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'state' => $state];
    }

    /**
     * Verkauft ein Haus / eine Ausbaustufe an die Bank zurück (50% Erstattung der Baukosten).
     */
    public function sellBuilding(int $roomId, int $playerId, int $tileIndex): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];
        $playerIdx = $this->getPlayerIndexById($state, $playerId);
        if ($playerIdx === null) {
            return ['success' => false, 'message' => 'Spieler nicht gefunden'];
        }
        $player = &$state['players'][$playerIdx];
        $tileConfig = HoodConfig::getTiles()[$tileIndex] ?? null;
        $tileState = &$state['tiles'][$tileIndex];

        if (!$tileConfig || ($tileConfig['type'] ?? '') !== 'street') {
            return ['success' => false, 'message' => 'Nur Straßen haben Gebäude'];
        }
        if ($tileState['owner'] !== $playerId) {
            return ['success' => false, 'message' => 'Gehört dir nicht'];
        }
        if (($tileState['level'] ?? 0) <= 1) {
            return ['success' => false, 'message' => 'Keine Häuser vorhanden zum Verkaufen'];
        }

        $group = $tileConfig['group'];
        // Gleichmäßiges Abreißen prüfen
        foreach ($state['tiles'] as $t) {
            if (($t['group'] ?? null) === $group && ($t['level'] ?? 1) > $tileState['level']) {
                return ['success' => false, 'message' => 'Du musst gleichmäßig abreißen (höchste Stufe zuerst)'];
            }
        }

        $houseCost = $tileConfig['house_cost'] ?? 100;
        $refund = (int)($houseCost / 2);

        $tileState['level']--;
        $player['cash'] += $refund;

        $targetNames = [
            1 => 'die Holzhütte 🏕️',
            2 => 'das Einfamilienhaus 🏡',
            3 => 'die Stadtvilla 🏛️',
            4 => 'die Luxus-Residenz 🏰',
        ];
        $targetText = $targetNames[$tileState['level']] ?? 'die Vorstufe';

        $state['log'][] = [
            'time' => date('H:i'),
            'text' => "🏚️ {$player['name']} baut auf '{$tileConfig['name']}' zurück auf {$targetText} und erhält {$refund} $ von der Bank."
        ];

        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'state' => $state];
    }

    /**
     * Verkauft ein unbebautes Grundstück komplett an die Bank zurück.
     * Erstattet den Kaufpreis, damit das Grundstück wieder frei wird.
     */
    public function sellProperty(int $roomId, int $playerId, int $tileIndex): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];
        $playerIdx = $this->getPlayerIndexById($state, $playerId);
        if ($playerIdx === null) {
            return ['success' => false, 'message' => 'Spieler nicht gefunden'];
        }
        $player = &$state['players'][$playerIdx];
        $tileConfig = HoodConfig::getTiles()[$tileIndex] ?? null;
        $tileState = &$state['tiles'][$tileIndex];

        if (!$tileConfig || !in_array($tileConfig['type'] ?? '', ['street', 'beach', 'club'], true)) {
            return ['success' => false, 'message' => 'Dieses Feld kann nicht verkauft werden'];
        }
        if ($tileState['owner'] !== $playerId) {
            return ['success' => false, 'message' => 'Gehört dir nicht'];
        }
        if (($tileState['level'] ?? 0) > 1) {
            return ['success' => false, 'message' => 'Zuerst müssen alle Gebäude abgerissen werden'];
        }

        $price = $tileConfig['price'] ?? 100;
        $refund = !empty($tileState['mortgaged']) ? (int)($price / 2) : $price;

        $player['cash'] += $refund;
        $tileState['owner'] = null;
        $tileState['level'] = 0;
        $tileState['mortgaged'] = false;

        $state['log'][] = [
            'time' => date('H:i'),
            'text' => "🏷️ {$player['name']} verkauft '{$tileConfig['name']}' an die Bank zurück (+{$refund} $). Das Grundstück ist wieder frei!"
        ];

        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'state' => $state];
    }

    /**
     * Zahlt 50 $ Kaution, um sofort aus der Werkstatt frei zu kommen.
     */
    public function payBail(int $roomId, int $playerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];
        $player = &$state['players'][$this->getPlayerIndexById($state, $playerId)];

        if (!$player['in_jail']) {
            return ['success' => false, 'message' => 'Du bist nicht auf der Hebebühne'];
        }
        if ($player['cash'] < HoodConfig::BAIL_COST) {
            return ['success' => false, 'message' => 'Zu wenig Cash für Kaution'];
        }

        $player['cash'] -= HoodConfig::BAIL_COST;
        $state['parking_pot'] = ($state['parking_pot'] ?? 0) + HoodConfig::BAIL_COST;
        $player['in_jail'] = false;
        $player['jail_turns'] = 0;
        $state['log'][] = [
            'time' => date('H:i'),
            'text' => "💸 {$player['name']} zahlt 50 $ Reparaturkaution (wandert in den Jackpot) und darf sofort wieder mitfahren!"
        ];

        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'state' => $state];
    }

    /**
     * Beendet den Zug und schaltet zum nächsten aktiven Spieler weiter.
     */
    public function endTurn(int $roomId, int $playerId, int $depth = 0): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];
        $player = &$state['players'][$state['current_player_index']];

        if ($player['id'] !== $playerId) {
            return ['success' => false, 'message' => 'Nicht an der Reihe'];
        }

        // Pasch-Bonus: Nochmal würfeln?
        if (!empty($state['can_roll_again']) && !$player['in_jail'] && !$player['bankrupt']) {
            $state['phase'] = 'ROLL';
            $state['can_roll_again'] = false;
            $state['log'][] = [
                'time' => date('H:i'),
                'text' => "⚡ Pasch! {$player['name']} darf noch einmal Gas geben."
            ];
            $this->repo->updateRoomState($roomId, $state);

            // Falls Bot: sofort den Pasch-Bonuswurf ausführen!
            if ($player['is_bot']) {
                $bot = new HoodBot($this);
                return $bot->playTurn($roomId, $player['id'], $depth + 1);
            }

            return ['success' => true, 'state' => $state];
        }

        $state['can_roll_again'] = false;
        $state['doubles_count'] = 0;

        // Nächsten nicht-bankrotten Spieler ermitteln
        $totalPlayers = count($state['players']);
        $nextIdx = $state['current_player_index'];
        for ($i = 0; $i < $totalPlayers; $i++) {
            $nextIdx = ($nextIdx + 1) % $totalPlayers;
            if (!$state['players'][$nextIdx]['bankrupt']) {
                break;
            }
        }

        $state['current_player_index'] = $nextIdx;
        $state['phase'] = 'ROLL';
        $nextPlayer = $state['players'][$nextIdx];

        $state['log'][] = [
            'time' => date('H:i'),
            'text' => "👉 Jetzt ist {$nextPlayer['name']} an der Reihe."
        ];

        $this->repo->updateRoomState($roomId, $state);

        // Falls nächster Spieler ein Bot ist: Bot-Zug ausführen!
        if ($nextPlayer['is_bot'] && !$nextPlayer['bankrupt'] && $state['status'] === 'active') {
            $bot = new HoodBot($this);
            return $bot->playTurn($roomId, $nextPlayer['id'], $depth + 1);
        }

        return ['success' => true, 'state' => $state];
    }

    /**
     * Zieht eine Ereignis- oder Gemeinschaftskarte.
     */
    private function drawCard(array &$state, array &$player, string $deck): void
    {
        $deckKey = $deck === 'radio' ? 'radio_deck' : 'glovebox_deck';
        $cards = $deck === 'radio' ? HoodConfig::getRadioCards() : HoodConfig::getGloveboxCards();

        if (empty($state[$deckKey])) {
            $state[$deckKey] = range(0, count($cards) - 1);
            shuffle($state[$deckKey]);
        }

        $cardIdx = array_shift($state[$deckKey]);
        $card = $cards[$cardIdx];
        $state['last_card'] = $card;
        $state['phase'] = 'TURN_END';

        $state['log'][] = [
            'time' => date('H:i'),
            'text' => ($deck === 'radio' ? '📻 RADIO TICKER: ' : '🗃️ HANDSCHUHFACH: ') . $card['text']
        ];

        // Kartenaktion ausführen
        switch ($card['action']) {
            case 'cash':
                $player['cash'] += $card['amount'];
                if ($card['amount'] < 0) {
                    $penalty = abs($card['amount']);
                    $state['parking_pot'] = ($state['parking_pot'] ?? 0) + $penalty;
                    $state['log'][] = [
                        'time' => date('H:i'),
                        'text' => "💸 Strafe von {$penalty} $ wandert in den Frei-Parken-Jackpot!"
                    ];
                    $this->checkBankruptcy($state, $player);
                }
                break;

            case 'teleport':
                $player['position'] = $card['tile'];
                if ($card['tile'] === 0) {
                    $doubleReward = HoodConfig::GO_REWARD * 2;
                    $player['cash'] += $doubleReward;
                    $state['log'][] = [
                        'time' => date('H:i'),
                        'text' => "🎯🎯 Direktfahrt auf START & ZIEL! {$player['name']} kassiert {$doubleReward} $ Bonus!"
                    ];
                }
                break;

            case 'nearest_beach':
                $beaches = [5, 15, 25, 35];
                $pos = $player['position'];
                $target = 5;
                foreach ($beaches as $b) {
                    if ($b > $pos) {
                        $target = $b;
                        break;
                    }
                }
                if ($target <= $pos) {
                    $player['cash'] += HoodConfig::GO_REWARD;
                }
                $player['position'] = $target;
                // Zielfeld-Kaufabwicklung prüfen
                $tileState = &$state['tiles'][$target];
                if ($tileState['owner'] === null) {
                    $state['phase'] = 'BUY_DECISION';
                }
                break;

            case 'go_to_jail':
                $player['position'] = 10;
                $player['in_jail'] = true;
                $player['jail_turns'] = 0;
                $state['doubles_count'] = 0;
                $state['can_roll_again'] = false;
                $state['jail_alert'] = [
                    'player_name' => $player['name'],
                    'reason'      => '🚓 Karte gezogen: Polizeikontrolle / Werkstatt! Du wirst sofort auf die Hebebühne geschleppt!'
                ];
                break;

            case 'repairs':
                $totalRepairs = 0;
                foreach ($state['tiles'] as $t) {
                    if ($t['owner'] === $player['id']) {
                        if ($t['level'] >= 2 && $t['level'] <= 4) {
                            $totalRepairs += ($t['level'] - 1) * $card['per_house'];
                        } elseif ($t['level'] === 5) {
                            $totalRepairs += $card['per_hotel'];
                        }
                    }
                }
                if ($totalRepairs > 0) {
                    $player['cash'] -= $totalRepairs;
                    $state['parking_pot'] = ($state['parking_pot'] ?? 0) + $totalRepairs;
                    $state['log'][] = [
                        'time' => date('H:i'),
                        'text' => "🔨 {$player['name']} zahlt insgesamt {$totalRepairs} $ Reparaturkosten (wandert in den Frei-Parken-Jackpot)."
                    ];
                    $this->checkBankruptcy($state, $player);
                }
                break;

            case 'collect_from_all':
                $amt = $card['amount'];
                foreach ($state['players'] as &$other) {
                    if ($other['id'] !== $player['id'] && !$other['bankrupt']) {
                        $other['cash'] -= $amt;
                        $player['cash'] += $amt;
                    }
                }
                break;

            case 'pay_to_all':
                $amt = $card['amount'];
                foreach ($state['players'] as &$other) {
                    if ($other['id'] !== $player['id'] && !$other['bankrupt']) {
                        $other['cash'] += $amt;
                        $player['cash'] -= $amt;
                    }
                }
                $this->checkBankruptcy($state, $player);
                break;

            case 'get_out_of_jail_free':
                $player['jail_free_cards']++;
                break;
        }
    }

    /**
     * Berechnet die fällige Miete für ein Feld.
     */
    public function calculateRent(array $state, int $tileIndex, int $diceSum): int
    {
        $tileConfig = HoodConfig::getTiles()[$tileIndex];
        $tileState = $state['tiles'][$tileIndex];

        if ($tileState['mortgaged']) {
            return 0;
        }

        if ($tileConfig['type'] === 'street') {
            $level = $tileState['level']; // 1 = Erschlossen, 2..4 = Häuser, 5 = Hotel
            $rentTable = $tileConfig['rent'];
            
            // Wenn 1 (erschlossen) und ganzer Farbbezirk im Besitz -> doppelte Miete
            if ($level <= 1 && $this->hasFullGroup($state, $tileState['owner'], $tileConfig['group'])) {
                return $rentTable[0] * 2;
            }

            $tableIdx = max(0, min($level - 1, 5));
            return $rentTable[$tableIdx];
        }

        if ($tileConfig['type'] === 'beach') {
            // Wie viele Strände besitzt der Eigentümer?
            $beachesOwned = 0;
            foreach ([5, 15, 25, 35] as $b) {
                if ($state['tiles'][$b]['owner'] === $tileState['owner'] && !$state['tiles'][$b]['mortgaged']) {
                    $beachesOwned++;
                }
            }
            $beachesOwned = max(1, min(4, $beachesOwned));
            return $tileConfig['rent'][$beachesOwned - 1];
        }

        if ($tileConfig['type'] === 'club') {
            // Wie viele Clubs besitzt der Eigentümer?
            $clubsOwned = 0;
            foreach ([12, 28] as $c) {
                if ($state['tiles'][$c]['owner'] === $tileState['owner'] && !$state['tiles'][$c]['mortgaged']) {
                    $clubsOwned++;
                }
            }
            return ($clubsOwned >= 2) ? (10 * $diceSum) : (4 * $diceSum);
        }

        return 0;
    }

    /**
     * Prüft, ob ein Spieler alle Straßen einer Farbgruppe besitzt.
     */
    public function hasFullGroup(array $state, int $playerId, ?string $group): bool
    {
        if (!$group) {
            return false;
        }
        foreach (HoodConfig::getTiles() as $idx => $cfg) {
            if (($cfg['group'] ?? null) === $group) {
                if (($state['tiles'][$idx]['owner'] ?? null) !== $playerId) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Bankrott-Prüfung.
     */
    private function checkBankruptcy(array &$state, array &$player, ?array &$beneficiary = null): void
    {
        if ($player['cash'] >= 0) {
            return;
        }

        // Automatische Notfall-Beleihung oder Bankrott
        $player['bankrupt'] = true;
        $player['cash'] = 0;

        $state['log'][] = [
            'time' => date('H:i'),
            'text' => "💥 {$player['name']} ist pleite! Alle Grundstücke und Besitztümer werden abgewickelt."
        ];

        // Grundstücke übertragen oder freigeben
        foreach ($state['tiles'] as &$t) {
            if ($t['owner'] === $player['id']) {
                if ($beneficiary !== null) {
                    $t['owner'] = $beneficiary['id'];
                } else {
                    $t['owner'] = null;
                    $t['level'] = 0; // wieder Wildnis
                    $t['mortgaged'] = false;
                }
            }
        }

        // Prüfen, ob nur noch 1 aktiver Spieler übrig ist -> Sieger!
        $activePlayers = array_filter($state['players'], fn($p) => !$p['bankrupt']);
        if (count($activePlayers) === 1) {
            $winner = reset($activePlayers);
            $state['status'] = 'finished';
            $state['winner_id'] = $winner['id'];
            $state['log'][] = [
                'time' => date('H:i'),
                'text' => "👑 {$winner['name']} beherrscht die gesamte Hood und gewinnt das Spiel!"
            ];
        }
    }

    private function getPlayerIndexById(array $state, int $playerId): ?int
    {
        foreach ($state['players'] as $idx => $p) {
            if ($p['id'] === $playerId) {
                return $idx;
            }
        }
        return null;
    }

    /**
     * Unterbreitet einem anderen Spieler oder KI-Fahrer ein Handelsangebot.
     */
    public function proposeTrade(int $roomId, int $fromPlayerId, int $toPlayerId, array $offerTiles, int $offerCash, array $requestTiles, int $requestCash): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];

        if (($state['status'] ?? '') !== 'active') {
            return ['success' => false, 'message' => 'Spiel ist nicht aktiv'];
        }
        if ($fromPlayerId === $toPlayerId) {
            return ['success' => false, 'message' => 'Du kannst nicht mit dir selbst handeln'];
        }

        $fromIdx = $this->getPlayerIndexById($state, $fromPlayerId);
        $toIdx = $this->getPlayerIndexById($state, $toPlayerId);
        if ($fromIdx === null || $toIdx === null) {
            return ['success' => false, 'message' => 'Spieler nicht gefunden'];
        }

        $fromPlayer = &$state['players'][$fromIdx];
        $toPlayer = &$state['players'][$toIdx];

        if ($fromPlayer['bankrupt'] || $toPlayer['bankrupt']) {
            return ['success' => false, 'message' => 'Bankrotte Spieler können nicht handeln'];
        }

        $offerCash = max(0, (int)$offerCash);
        $requestCash = max(0, (int)$requestCash);

        if ($offerCash > $fromPlayer['cash']) {
            return ['success' => false, 'message' => 'Du hast nicht genügend Geld für dieses Angebot'];
        }
        if ($requestCash > $toPlayer['cash']) {
            return ['success' => false, 'message' => "{$toPlayer['name']} hat nicht genügend Geld"];
        }

        if (empty($offerTiles) && $offerCash === 0) {
            return ['success' => false, 'message' => 'Du musst mindestens ein Grundstück oder Geld anbieten'];
        }
        if (empty($requestTiles) && $requestCash === 0) {
            return ['success' => false, 'message' => 'Du musst mindestens ein Grundstück oder Geld fordern'];
        }

        // Grundstücke validieren
        foreach ($offerTiles as $tIdx) {
            $tile = $state['tiles'][$tIdx] ?? null;
            if (!$tile || $tile['owner'] !== $fromPlayerId) {
                return ['success' => false, 'message' => 'Eines der angebotenen Grundstücke gehört dir nicht'];
            }
            if (($tile['level'] ?? 0) > 1) {
                return ['success' => false, 'message' => 'Bebaute Grundstücke können nicht gehandelt werden (zuerst Häuser verkaufen)'];
            }
        }

        foreach ($requestTiles as $tIdx) {
            $tile = $state['tiles'][$tIdx] ?? null;
            if (!$tile || $tile['owner'] !== $toPlayerId) {
                return ['success' => false, 'message' => "Eines der geforderten Grundstücke gehört {$toPlayer['name']} nicht"];
            }
            if (($tile['level'] ?? 0) > 1) {
                return ['success' => false, 'message' => 'Bebaute Grundstücke können nicht gehandelt werden'];
            }
        }

        // Falls toPlayer ein KI-Bot ist: sofort bewerten!
        if (!empty($toPlayer['is_bot'])) {
            $accept = $this->evaluateBotTrade($state, $toPlayer, $fromPlayer, $offerTiles, $offerCash, $requestTiles, $requestCash);
            if ($accept) {
                $fromPlayer['cash'] = $fromPlayer['cash'] - $offerCash + $requestCash;
                $toPlayer['cash'] = $toPlayer['cash'] + $offerCash - $requestCash;
                foreach ($offerTiles as $tIdx) {
                    $state['tiles'][$tIdx]['owner'] = $toPlayerId;
                }
                foreach ($requestTiles as $tIdx) {
                    $state['tiles'][$tIdx]['owner'] = $fromPlayerId;
                }
                $state['log'][] = [
                    'time' => date('H:i'),
                    'text' => "🤝 DEAL! {$toPlayer['name']} (KI) akzeptiert das Handelsangebot von {$fromPlayer['name']}!"
                ];
                $this->repo->updateRoomState($roomId, $state);
                return ['success' => true, 'deal' => true, 'accepted' => true, 'state' => $state, 'message' => "{$toPlayer['name']} hat das Angebot angenommen! 🎉"];
            } else {
                $state['log'][] = [
                    'time' => date('H:i'),
                    'text' => "❌ {$toPlayer['name']} (KI) lehnt das Handelsangebot von {$fromPlayer['name']} ab."
                ];
                $this->repo->updateRoomState($roomId, $state);
                return ['success' => true, 'deal' => false, 'accepted' => false, 'state' => $state, 'message' => "{$toPlayer['name']} hat das Angebot abgelehnt."];
            }
        }

        // Menschlicher Mitspieler: Handelsangebot im Spielstatus hinterlegen
        $state['active_trade'] = [
            'id'            => 'tr_' . bin2hex(random_bytes(4)),
            'from_id'       => $fromPlayerId,
            'to_id'         => $toPlayerId,
            'offer_tiles'   => $offerTiles,
            'offer_cash'    => $offerCash,
            'request_tiles' => $requestTiles,
            'request_cash'  => $requestCash,
        ];
        $state['log'][] = [
            'time' => date('H:i'),
            'text' => "🤝 {$fromPlayer['name']} bietet {$toPlayer['name']} einen Grundstücks-Handel an."
        ];
        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'pending' => true, 'state' => $state, 'message' => "Handelsangebot an {$toPlayer['name']} gesendet!"];
    }

    /**
     * KI-Entscheidungslogik für Handelsangebote.
     */
    public function evaluateBotTrade(array $state, array $bot, array $proposer, array $offerTiles, int $offerCash, array $requestTiles, int $requestCash): bool
    {
        // Kosten für den Bot (was er abgibt)
        $cost = $requestCash;
        foreach ($requestTiles as $tileIdx) {
            $tileCfg = HoodConfig::getTiles()[$tileIdx] ?? null;
            if (!$tileCfg) continue;
            $price = $tileCfg['price'] ?? 100;
            $group = $tileCfg['group'] ?? '';

            $botOwnsInGroup = 0;
            $groupTotal = 0;
            foreach ($state['tiles'] as $t) {
                if (($t['group'] ?? '') === $group) {
                    $groupTotal++;
                    if ($t['owner'] === $bot['id']) {
                        $botOwnsInGroup++;
                    }
                }
            }

            if ($botOwnsInGroup === $groupTotal) {
                $cost += $price * 3.5;
            } elseif ($botOwnsInGroup > 1) {
                $cost += $price * 2.0;
            } else {
                $cost += $price * 1.2;
            }

            // Bringt dieser Tausch dem Gegner ein Monopol?
            $proposerOwnsInGroup = 0;
            foreach ($state['tiles'] as $t) {
                if (($t['group'] ?? '') === $group && $t['owner'] === $proposer['id']) {
                    $proposerOwnsInGroup++;
                }
            }
            if ($proposerOwnsInGroup + 1 === $groupTotal) {
                $cost += $price * 1.8;
            }
        }

        // Nutzen für den Bot (was er bekommt)
        $gain = $offerCash;
        foreach ($offerTiles as $tileIdx) {
            $tileCfg = HoodConfig::getTiles()[$tileIdx] ?? null;
            if (!$tileCfg) continue;
            $price = $tileCfg['price'] ?? 100;
            $group = $tileCfg['group'] ?? '';

            $botOwnsInGroup = 0;
            $groupTotal = 0;
            foreach ($state['tiles'] as $t) {
                if (($t['group'] ?? '') === $group) {
                    $groupTotal++;
                    if ($t['owner'] === $bot['id']) {
                        $botOwnsInGroup++;
                    }
                }
            }

            if ($botOwnsInGroup + 1 === $groupTotal) {
                $gain += $price * 3.5; // Monopol vollendet!
            } elseif ($botOwnsInGroup >= 1) {
                $gain += $price * 1.8;
            } else {
                $gain += $price * 1.1;
            }
        }

        // Hoher Geldbedarf bei knappem Cash
        if ($bot['cash'] < 150 && $offerCash >= 150) {
            $gain += 120;
        }

        return ($gain >= $cost);
    }

    /**
     * Antwort auf ein Handelsangebot (Annehmen / Ablehnen).
     */
    public function respondTrade(int $roomId, int $playerId, bool $accept): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];
        $trade = $state['active_trade'] ?? null;
        if (!$trade) {
            return ['success' => false, 'message' => 'Kein aktives Handelsangebot vorhanden'];
        }
        if ($trade['to_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Du bist nicht der Empfänger dieses Angebots'];
        }

        $fromIdx = $this->getPlayerIndexById($state, $trade['from_id']);
        $toIdx = $this->getPlayerIndexById($state, $trade['to_id']);
        if ($fromIdx === null || $toIdx === null) {
            $state['active_trade'] = null;
            $this->repo->updateRoomState($roomId, $state);
            return ['success' => false, 'message' => 'Beteiligte Spieler nicht mehr verfügbar'];
        }

        $fromPlayer = &$state['players'][$fromIdx];
        $toPlayer = &$state['players'][$toIdx];

        if ($accept) {
            if ($fromPlayer['cash'] < $trade['offer_cash'] || $toPlayer['cash'] < $trade['request_cash']) {
                $state['active_trade'] = null;
                $this->repo->updateRoomState($roomId, $state);
                return ['success' => false, 'message' => 'Handel fehlgeschlagen: Zu wenig Guthaben'];
            }
            foreach ($trade['offer_tiles'] as $tIdx) {
                if (($state['tiles'][$tIdx]['owner'] ?? null) !== $trade['from_id']) {
                    $state['active_trade'] = null;
                    $this->repo->updateRoomState($roomId, $state);
                    return ['success' => false, 'message' => 'Handel ungültig: Besitzverhältnisse haben sich geändert'];
                }
            }
            foreach ($trade['request_tiles'] as $tIdx) {
                if (($state['tiles'][$tIdx]['owner'] ?? null) !== $trade['to_id']) {
                    $state['active_trade'] = null;
                    $this->repo->updateRoomState($roomId, $state);
                    return ['success' => false, 'message' => 'Handel ungültig: Besitzverhältnisse haben sich geändert'];
                }
            }

            $fromPlayer['cash'] = $fromPlayer['cash'] - $trade['offer_cash'] + $trade['request_cash'];
            $toPlayer['cash'] = $toPlayer['cash'] + $trade['offer_cash'] - $trade['request_cash'];
            foreach ($trade['offer_tiles'] as $tIdx) {
                $state['tiles'][$tIdx]['owner'] = $trade['to_id'];
            }
            foreach ($trade['request_tiles'] as $tIdx) {
                $state['tiles'][$tIdx]['owner'] = $trade['from_id'];
            }

            $state['log'][] = [
                'time' => date('H:i'),
                'text' => "🤝 DEAL! {$toPlayer['name']} nimmt den Handel von {$fromPlayer['name']} an!"
            ];
        } else {
            $state['log'][] = [
                'time' => date('H:i'),
                'text' => "❌ {$toPlayer['name']} lehnt den Handel von {$fromPlayer['name']} ab."
            ];
        }

        $state['active_trade'] = null;
        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'state' => $state];
    }

    /**
     * Zieht ein eigenes offenes Handelsangebot zurück.
     */
    public function cancelTrade(int $roomId, int $playerId): array
    {
        $room = $this->repo->getRoomById($roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'Raum nicht gefunden'];
        }
        $state = $room['state'];
        $trade = $state['active_trade'] ?? null;
        if (!$trade || $trade['from_id'] !== $playerId) {
            return ['success' => false, 'message' => 'Kein eigenes Angebot zum Abbrechen'];
        }
        $state['active_trade'] = null;
        $state['log'][] = [
            'time' => date('H:i'),
            'text' => "↩️ Handelsangebot wurde zurückgezogen."
        ];
        $this->repo->updateRoomState($roomId, $state);
        return ['success' => true, 'state' => $state];
    }
}
