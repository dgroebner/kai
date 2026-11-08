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
                'index'     => $idx,
                'name'      => $t['name'],
                'type'      => $t['type'],
                'group'     => $t['group'] ?? null,
                'price'     => $t['price'] ?? 0,
                'owner'     => null, // Player ID
                'level'     => 0,    // 0 = unberührt, 1 = erschlossen, 2..4 = 1..3 Häuser, 5 = Hotel
                'mortgaged' => false,
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
        if (count($state['players']) < 1) {
            return ['success' => false, 'message' => 'Mindestens 1 Spieler erforderlich'];
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
                $player['in_jail'] = false;
                $player['jail_turns'] = 0;
                $state['log'][] = [
                    'time' => date('H:i'),
                    'text' => "🔧 {$player['name']} zahlt 50 $ Reparatur-Kaution nach 3 Runden und fährt weiter."
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
                $state['phase'] = 'TURN_END';
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

        // Startfeld passiert?
        if ($newPos < $oldPos) {
            $player['cash'] += HoodConfig::GO_REWARD;
            $state['log'][] = [
                'time' => date('H:i'),
                'text' => "🏁 {$player['name']} passiert START & ZIEL und streicht 200 $ Prämie ein!"
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
            case 'parking':
                $state['phase'] = 'TURN_END';
                break;

            case 'tax':
                $tax = $tileConfig['tax'];
                $player['cash'] -= $tax;
                $state['phase'] = 'TURN_END';
                $state['log'][] = [
                    'time' => date('H:i'),
                    'text' => "🛑 {$player['name']} zahlt {$tax} $ {$tileConfig['name']}."
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
                $state['log'][] = [
                    'time' => date('H:i'),
                    'text' => "🚓 Radarfalle! {$player['name']} wird direkt in die Werkstatt geschleppt!"
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
            2 => 'das 1. Haus',
            3 => 'das 2. Haus',
            4 => 'das 3. Haus',
            5 => 'ein Luxus-Hotel 🏨',
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
        $player['in_jail'] = false;
        $player['jail_turns'] = 0;
        $state['log'][] = [
            'time' => date('H:i'),
            'text' => "💸 {$player['name']} zahlt 50 $ Reparaturkaution und darf sofort wieder mitfahren!"
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
                $this->checkBankruptcy($state, $player);
                break;

            case 'teleport':
                $player['position'] = $card['tile'];
                if ($card['tile'] === 0) {
                    $player['cash'] += HoodConfig::GO_REWARD;
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
                    $state['log'][] = [
                        'time' => date('H:i'),
                        'text' => "🔨 {$player['name']} zahlt insgesamt {$totalRepairs} $ Reparaturkosten für alle Gebäude."
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
}
