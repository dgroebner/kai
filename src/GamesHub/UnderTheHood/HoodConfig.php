<?php

declare(strict_types=1);

namespace Kai\Tools\GamesHub\UnderTheHood;

class HoodConfig
{
    public const STARTING_CASH = 1500;
    public const GO_REWARD = 200;
    public const BAIL_COST = 50;
    public const MAX_JAIL_TURNS = 3;

    public const COLOR_GROUPS = [
        'docks'        => ['name' => 'Docks & Industrie',    'color' => '#8B5A2B', 'badge' => '🏭'],
        'suburb'       => ['name' => 'Vorstadt & Diners',    'color' => '#38BDF8', 'badge' => '☕'],
        'arts'         => ['name' => 'Künstlerviertel',      'color' => '#EC4899', 'badge' => '🎨'],
        'promenade'    => ['name' => 'Flaniermeile',         'color' => '#F97316', 'badge' => '🌴'],
        'downtown'     => ['name' => 'Innenstadt & Skyline', 'color' => '#EF4444', 'badge' => '🏙️'],
        'marina'       => ['name' => 'Yachthafen & Kai',     'color' => '#EAB308', 'badge' => '⛵'],
        'heights'      => ['name' => 'Panorama-Höhen',       'color' => '#22C55E', 'badge' => '⛰️'],
        'millionaires' => ['name' => 'Villenviertel',        'color' => '#3B82F6', 'badge' => '💎'],
    ];

    public const VEHICLES = [
        'sportscar' => ['name' => 'Sport-Cabrio',   'icon' => '🏎️'],
        'camper'    => ['name' => 'Retro-Bulli',     'icon' => '🚐'],
        'muscle'    => ['name' => 'Muscle-Car',      'icon' => '🛻'],
        'compact'   => ['name' => 'City-Flitzer',    'icon' => '🚗'],
        'taxi'      => ['name' => 'Küsten-Taxi',     'icon' => '🚕'],
    ];

    public const PLAYER_COLORS = [
        '#ef4444', // Rot
        '#3b82f6', // Blau
        '#10b981', // Grün
        '#f59e0b', // Gold / Gelb
        '#8b5cf6', // Lila
        '#ec4899', // Pink
    ];

    /**
     * Definition aller 40 Felder des Rundkurses (0 bis 39).
     */
    public static function getTiles(): array
    {
        return [
            // 0: Start
            0 => [
                'index' => 0,
                'name'  => 'Start & Ziel',
                'type'  => 'start',
                'icon'  => '🏁',
                'desc'  => '200k € beim Passieren – Punktlandung auf Start bringt doppeltes Gehalt (400k €)!',
            ],
            // 1: Docks 1
            1 => [
                'index'      => 1,
                'name'       => 'Rostgasse',
                'type'       => 'street',
                'group'      => 'docks',
                'price'      => 60,
                'house_cost' => 50,
                'rent'       => [2, 10, 30, 90, 160, 250],
                'mortgage'   => 30,
            ],
            // 2: Card
            2 => [
                'index' => 2,
                'name'  => 'Verkehrsfunk',
                'type'  => 'card',
                'deck'  => 'radio',
                'icon'  => '📻',
                'desc'  => 'Zufällige Live-Nachricht aus der Nachbarschaft.',
            ],
            // 3: Docks 2
            3 => [
                'index'      => 3,
                'name'       => 'Am Hafenpier',
                'type'       => 'street',
                'group'      => 'docks',
                'price'      => 60,
                'house_cost' => 50,
                'rent'       => [4, 20, 60, 180, 320, 450],
                'mortgage'   => 30,
            ],
            // 4: Tax 1
            4 => [
                'index' => 4,
                'name'  => 'Kfz- & Umweltabgabe',
                'type'  => 'tax',
                'tax'   => 200,
                'icon'  => '🛑',
                'desc'  => 'Fällige Abgabe: Zahle 200k € an die Stadtkasse.',
            ],
            // 5: Strand 1
            5 => [
                'index'    => 5,
                'name'     => 'Nordstrand',
                'type'     => 'beach',
                'price'    => 200,
                'mortgage' => 100,
                'rent'     => [25, 50, 100, 200], // je nach Anzahl Strände
                'icon'     => '🏖️',
            ],
            // 6: Suburb 1
            6 => [
                'index'      => 6,
                'name'       => 'Motel-Meile',
                'type'       => 'street',
                'group'      => 'suburb',
                'price'      => 100,
                'house_cost' => 50,
                'rent'       => [6, 30, 90, 270, 400, 550],
                'mortgage'   => 50,
            ],
            // 7: Card (Drive-In Diner)
            7 => [
                'index' => 7,
                'name'  => 'Drive-In Diner',
                'type'  => 'card',
                'deck'  => 'glovebox',
                'icon'  => '🍔',
                'desc'  => 'Boxenstopp im Diner: Milkshake, Jackpot oder Zeche?',
            ],
            // 8: Suburb 2
            8 => [
                'index'      => 8,
                'name'       => 'Leuchtallee',
                'type'       => 'street',
                'group'      => 'suburb',
                'price'      => 100,
                'house_cost' => 50,
                'rent'       => [6, 30, 90, 270, 400, 550],
                'mortgage'   => 50,
            ],
            // 9: Suburb 3
            9 => [
                'index'      => 9,
                'name'       => 'Diner-Allee',
                'type'       => 'street',
                'group'      => 'suburb',
                'price'      => 120,
                'house_cost' => 50,
                'rent'       => [8, 40, 100, 300, 450, 600],
                'mortgage'   => 60,
            ],
            // 10: Werkstatt / Verwahrstelle
            10 => [
                'index' => 10,
                'name'  => 'Werkstatt / Hebebühne',
                'type'  => 'jail',
                'icon'  => '🔧',
                'desc'  => 'Nur zu Besuch oder Wagen wird repariert (Verwahrstelle).',
            ],
            // 11: Arts 1
            11 => [
                'index'      => 11,
                'name'       => 'Vinyl-Gasse',
                'type'       => 'street',
                'group'      => 'arts',
                'price'      => 140,
                'house_cost' => 100,
                'rent'       => [10, 50, 150, 450, 625, 750],
                'mortgage'   => 70,
            ],
            // 12: Club 1
            12 => [
                'index'    => 12,
                'name'     => 'Club Nachtlicht',
                'type'     => 'club',
                'price'    => 150,
                'mortgage' => 75,
                'icon'     => '🍸',
                'desc'     => 'Miete = 4x Würfelaugen (10x wenn du beide Clubs besitzt).',
            ],
            // 13: Arts 2
            13 => [
                'index'      => 13,
                'name'       => 'Graffiti-Bogen',
                'type'       => 'street',
                'group'      => 'arts',
                'price'      => 140,
                'house_cost' => 100,
                'rent'       => [10, 50, 150, 450, 625, 750],
                'mortgage'   => 70,
            ],
            // 14: Arts 3
            14 => [
                'index'      => 14,
                'name'       => 'Atelier-Weg',
                'type'       => 'street',
                'group'      => 'arts',
                'price'      => 160,
                'house_cost' => 100,
                'rent'       => [12, 60, 180, 500, 700, 900],
                'mortgage'   => 80,
            ],
            // 15: Strand 2
            15 => [
                'index'    => 15,
                'name'     => 'Palmenbucht',
                'type'     => 'beach',
                'price'    => 200,
                'mortgage' => 100,
                'rent'     => [25, 50, 100, 200],
                'icon'     => '🌴',
            ],
            // 16: Promenade 1
            16 => [
                'index'      => 16,
                'name'       => 'Kaffee-Boulevard',
                'type'       => 'street',
                'group'      => 'promenade',
                'price'      => 180,
                'house_cost' => 100,
                'rent'       => [14, 70, 200, 550, 750, 950],
                'mortgage'   => 90,
            ],
            // 17: Card
            17 => [
                'index' => 17,
                'name'  => 'Verkehrsfunk',
                'type'  => 'card',
                'deck'  => 'radio',
                'icon'  => '📻',
                'desc'  => 'Zufällige Live-Nachricht aus der Nachbarschaft.',
            ],
            // 18: Promenade 2
            18 => [
                'index'      => 18,
                'name'       => 'Palmen-Promenade',
                'type'       => 'street',
                'group'      => 'promenade',
                'price'      => 180,
                'house_cost' => 100,
                'rent'       => [14, 70, 200, 550, 750, 950],
                'mortgage'   => 90,
            ],
            // 19: Promenade 3
            19 => [
                'index'      => 19,
                'name'       => 'Marktring',
                'type'       => 'street',
                'group'      => 'promenade',
                'price'      => 200,
                'house_cost' => 100,
                'rent'       => [16, 80, 220, 600, 800, 1000],
                'mortgage'   => 100,
            ],
            // 20: Autokino & Aussichtspunkt
            20 => [
                'index' => 20,
                'name'  => 'Autokino & Abendrot',
                'type'  => 'parking',
                'icon'  => '🎬',
                'desc'  => 'Frei Parken & Autokino: Wer hier landet, räumt den Jackpot aus allen Strafgeldern ab!',
            ],
            // 21: Downtown 1
            21 => [
                'index'      => 21,
                'name'       => 'Turm-Kreuzung',
                'type'       => 'street',
                'group'      => 'downtown',
                'price'      => 220,
                'house_cost' => 150,
                'rent'       => [18, 90, 250, 700, 875, 1050],
                'mortgage'   => 110,
            ],
            // 22: Card (Drive-In Diner)
            22 => [
                'index' => 22,
                'name'  => 'Drive-In Diner',
                'type'  => 'card',
                'deck'  => 'glovebox',
                'icon'  => '🍔',
                'desc'  => 'Boxenstopp im Diner: Jukebox-Gewinn oder Rechnung?',
            ],
            // 23: Downtown 2
            23 => [
                'index'      => 23,
                'name'       => 'Banken-Viertel',
                'type'       => 'street',
                'group'      => 'downtown',
                'price'      => 220,
                'house_cost' => 150,
                'rent'       => [18, 90, 250, 700, 875, 1050],
                'mortgage'   => 110,
            ],
            // 24: Downtown 3
            24 => [
                'index'      => 24,
                'name'       => 'Börsenstraße',
                'type'       => 'street',
                'group'      => 'downtown',
                'price'      => 240,
                'house_cost' => 150,
                'rent'       => [20, 100, 300, 750, 925, 1100],
                'mortgage'   => 120,
            ],
            // 25: Strand 3
            25 => [
                'index'    => 25,
                'name'     => 'Abendrotbucht',
                'type'     => 'beach',
                'price'    => 200,
                'mortgage' => 100,
                'rent'     => [25, 50, 100, 200],
                'icon'     => '🌅',
            ],
            // 26: Marina 1
            26 => [
                'index'      => 26,
                'name'       => 'Kapitänsweg',
                'type'       => 'street',
                'group'      => 'marina',
                'price'      => 260,
                'house_cost' => 150,
                'rent'       => [22, 110, 330, 800, 975, 1150],
                'mortgage'   => 130,
            ],
            // 27: Marina 2
            27 => [
                'index'      => 27,
                'name'       => 'Segler-Korso',
                'type'       => 'street',
                'group'      => 'marina',
                'price'      => 260,
                'house_cost' => 150,
                'rent'       => [22, 110, 330, 800, 975, 1150],
                'mortgage'   => 130,
            ],
            // 28: Club 2
            28 => [
                'index'    => 28,
                'name'     => 'Club Tresor',
                'type'     => 'club',
                'price'    => 150,
                'mortgage' => 75,
                'icon'     => '🎧',
                'desc'     => 'Miete = 4x Würfelaugen (10x wenn du beide Clubs besitzt).',
            ],
            // 29: Marina 3
            29 => [
                'index'      => 29,
                'name'       => 'Hafen-Promenade',
                'type'       => 'street',
                'group'      => 'marina',
                'price'      => 280,
                'house_cost' => 150,
                'rent'       => [24, 120, 360, 850, 1025, 1200],
                'mortgage'   => 140,
            ],
            // 30: Radarfalle / Polizei
            30 => [
                'index' => 30,
                'name'  => 'Radarfalle & Abschlepper',
                'type'  => 'go_to_jail',
                'icon'  => '🚓',
                'desc'  => 'Geblitzt mit Vollgas! Fahre sofort in die Verwahrstelle.',
            ],
            // 31: Heights 1
            31 => [
                'index'      => 31,
                'name'       => 'Berg-Pass',
                'type'       => 'street',
                'group'      => 'heights',
                'price'      => 300,
                'house_cost' => 200,
                'rent'       => [26, 130, 390, 900, 1100, 1275],
                'mortgage'   => 150,
            ],
            // 32: Heights 2
            32 => [
                'index'      => 32,
                'name'       => 'Serpentinen-Allee',
                'type'       => 'street',
                'group'      => 'heights',
                'price'      => 300,
                'house_cost' => 200,
                'rent'       => [26, 130, 390, 900, 1100, 1275],
                'mortgage'   => 150,
            ],
            // 33: Card
            33 => [
                'index' => 33,
                'name'  => 'Verkehrsfunk',
                'type'  => 'card',
                'deck'  => 'radio',
                'icon'  => '📻',
                'desc'  => 'Zufällige Live-Nachricht aus der Nachbarschaft.',
            ],
            // 34: Heights 3
            34 => [
                'index'      => 34,
                'name'       => 'Panoramastraße',
                'type'       => 'street',
                'group'      => 'heights',
                'price'      => 320,
                'house_cost' => 200,
                'rent'       => [28, 150, 450, 1000, 1200, 1500],
                'mortgage'   => 160,
            ],
            // 35: Strand 4
            35 => [
                'index'    => 35,
                'name'     => 'Seebrücke',
                'type'     => 'beach',
                'price'    => 200,
                'mortgage' => 100,
                'rent'     => [25, 50, 100, 200],
                'icon'     => '🎡',
            ],
            // 36: Card (Drive-In Diner)
            36 => [
                'index' => 36,
                'name'  => 'Drive-In Diner',
                'type'  => 'card',
                'deck'  => 'glovebox',
                'icon'  => '🍔',
                'desc'  => 'Boxenstopp im Diner: Milkshake, Jackpot oder Zeche?',
            ],
            // 37: Millionaires 1
            37 => [
                'index'      => 37,
                'name'       => 'Abendsonne-Boulevard',
                'type'       => 'street',
                'group'      => 'millionaires',
                'price'      => 350,
                'house_cost' => 200,
                'rent'       => [35, 175, 500, 1100, 1300, 1500],
                'mortgage'   => 175,
            ],
            // 38: Tax 2
            38 => [
                'index' => 38,
                'name'  => 'Stadtmaut & Luxussteuer',
                'type'  => 'tax',
                'tax'   => 100,
                'icon'  => '💎',
                'desc'  => 'Luxusabgabe: Zahle 100k € an die Stadtkasse.',
            ],
            // 39: Millionaires 2 (Schlossallee)
            39 => [
                'index'      => 39,
                'name'       => 'Meeresblick-Allee',
                'type'       => 'street',
                'group'      => 'millionaires',
                'price'      => 400,
                'house_cost' => 200,
                'rent'       => [50, 200, 600, 1400, 1700, 2000],
                'mortgage'   => 200,
            ],
        ];
    }

    /**
     * Ereigniskarten: Radio-Ticker
     */
    public static function getRadioCards(): array
    {
        return [
            ['text' => '🏆 Du gewinnst das Straßenrennen! Kassiere 150k €.', 'action' => 'cash', 'amount' => 150],
            ['text' => '🚨 Blitzer auf der Strandpromenade! Zahle 50k € Strafe.', 'action' => 'cash', 'amount' => -50],
            ['text' => '⛽ Tankstellen-Gutschein gewonnen! Ziehe 100k € ein.', 'action' => 'cash', 'amount' => 100],
            ['text' => '🔨 Werkstatt-Inspektion fällig: Zahle 25k € pro Haus und 100k € pro Hotel.', 'action' => 'repairs', 'per_house' => 25, 'per_hotel' => 100],
            ['text' => '🏁 Gib Vollgas: Fahre direkt auf START & ZIEL (kassiere 400k € Bonus).', 'action' => 'teleport', 'tile' => 0],
            ['text' => '🏖️ Abkühlung gefällig? Fahre vor zum nächsten Strand.', 'action' => 'nearest_beach'],
            ['text' => '🚓 Polizeikontrolle: Alkoholfahrt! Fahre sofort in die Verwahrstelle.', 'action' => 'go_to_jail'],
            ['text' => '🎉 Geburtstags-Ausfahrt: Jeder Mitspieler schenkt dir 20k €.', 'action' => 'collect_from_all', 'amount' => 20],
            ['text' => '🏎️ Luxus-Tuning: Dein Wagen gewinnt den Schönheitswettbewerb. Erhalte 100k €.', 'action' => 'cash', 'amount' => 100],
            ['text' => '🔑 Notfall-Schlüsselkarte: Du kommst kostenlos aus der Verwahrstelle frei.', 'action' => 'get_out_of_jail_free'],
            ['text' => '🚧 Mautgebühr auf der Küstenstraße: Zahle 30k €.', 'action' => 'cash', 'amount' => -30],
        ];
    }

    /**
     * Gemeinschaftskarten: Drive-In Diner
     */
    public static function getGloveboxCards(): array
    {
        return [
            ['text' => '🎰 Jukebox-Jackpot im Diner geknackt! Du kassierst 100k € Bonus.', 'action' => 'cash', 'amount' => 100],
            ['text' => '🍔 Riesen-Bestellung für die Crew ausgegeben: Zahle 50k € an die Kasse.', 'action' => 'cash', 'amount' => -50],
            ['text' => '🥤 Stammgast-Rabatt & Trinkgeld-Topf geteilt: Ziehe 50k € ein.', 'action' => 'cash', 'amount' => 50],
            ['text' => '🔑 VIP-Diner-Pass vom Sheriff: Du kommst kostenlos aus der Verwahrstelle frei.', 'action' => 'get_out_of_jail_free'],
            ['text' => '🧽 Diner-Frühjahrsputz in deinen Vierteln: Zahle 40k € pro Haus und 115k € pro Hotel.', 'action' => 'repairs', 'per_house' => 40, 'per_hotel' => 115],
            ['text' => '🪙 Glücksmünze in der alten Jukebox gefunden: +25k €!', 'action' => 'cash', 'amount' => 25],
            ['text' => '🍩 Oldtimer-Treffen auf dem Diner-Parkplatz gesponsert: Erhalte 45k € Prämie.', 'action' => 'cash', 'amount' => 45],
            ['text' => '☕ Kaffeefleck auf dem weißen Ledersitz – Reinigung fällig: Zahle 20k €.', 'action' => 'cash', 'amount' => -20],
            ['text' => '🏖️ Nach dem Milkshake geht es weiter ans Meer: Reise direkt zur Palmenbucht.', 'action' => 'teleport', 'tile' => 15],
            ['text' => '🍨 Eine Runde Milkshakes für alle Mitspieler! Jeder erhält 10k € von dir.', 'action' => 'pay_to_all', 'amount' => 10],
        ];
    }
}
