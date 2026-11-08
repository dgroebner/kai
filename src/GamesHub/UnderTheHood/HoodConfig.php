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
        'downtown'     => ['name' => 'Downtown & Skyline',   'color' => '#EF4444', 'badge' => '🏙️'],
        'marina'       => ['name' => 'Yachthafen & Marina',  'color' => '#EAB308', 'badge' => '⛵'],
        'heights'      => ['name' => 'Panorama-Höhen',       'color' => '#22C55E', 'badge' => '⛰️'],
        'millionaires' => ['name' => 'Millionaires’ Hill',   'color' => '#3B82F6', 'badge' => '💎'],
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
                'desc'  => 'Ziehe 200 $ beim Passieren oder Landen ein.',
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
                'name'  => 'Radio Ticker',
                'type'  => 'card',
                'deck'  => 'radio',
                'icon'  => '📻',
                'desc'  => 'Zufällige Live-Nachricht aus der Hood.',
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
                'desc'  => 'Fällige Abgabe: Zahle 200 $ an die Stadtkasse.',
            ],
            // 5: Strand 1
            5 => [
                'index'    => 5,
                'name'     => 'North Beach',
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
            // 7: Card
            7 => [
                'index' => 7,
                'name'  => 'Handschuhfach',
                'type'  => 'card',
                'deck'  => 'glovebox',
                'icon'  => '🗃️',
                'desc'  => 'Greif ins Fach: Glück oder Bußgeld?',
            ],
            // 8: Suburb 2
            8 => [
                'index'      => 8,
                'name'       => 'Neon-Drive',
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
                'name'     => 'The Neon Lounge',
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
                'name'     => 'Palm Bay',
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
                'name'  => 'Radio Ticker',
                'type'  => 'card',
                'deck'  => 'radio',
                'icon'  => '📻',
                'desc'  => 'Zufällige Live-Nachricht aus der Hood.',
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
                'name'  => 'Autokino & Sunset Point',
                'type'  => 'parking',
                'icon'  => '🎬',
                'desc'  => 'Kostenlose Rast mit Meerblick – keine Miete.',
            ],
            // 21: Downtown 1
            21 => [
                'index'      => 21,
                'name'       => 'Tower-Kreuzung',
                'type'       => 'street',
                'group'      => 'downtown',
                'price'      => 220,
                'house_cost' => 150,
                'rent'       => [18, 90, 250, 700, 875, 1050],
                'mortgage'   => 110,
            ],
            // 22: Card
            22 => [
                'index' => 22,
                'name'  => 'Handschuhfach',
                'type'  => 'card',
                'deck'  => 'glovebox',
                'icon'  => '🗃️',
                'desc'  => 'Greif ins Fach: Dokumente oder Cash.',
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
                'name'       => 'Wall Street',
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
                'name'     => 'Sunset Bay',
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
                'name'     => 'Underground Vault',
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
                'name'  => 'Radio Ticker',
                'type'  => 'card',
                'deck'  => 'radio',
                'icon'  => '📻',
                'desc'  => 'Zufällige Live-Nachricht aus der Hood.',
            ],
            // 34: Heights 3
            34 => [
                'index'      => 34,
                'name'       => 'Skyview-Drive',
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
                'name'     => 'Ocean Pier',
                'type'     => 'beach',
                'price'    => 200,
                'mortgage' => 100,
                'rent'     => [25, 50, 100, 200],
                'icon'     => '🎡',
            ],
            // 36: Card
            36 => [
                'index' => 36,
                'name'  => 'Handschuhfach',
                'type'  => 'card',
                'deck'  => 'glovebox',
                'icon'  => '🗃️',
                'desc'  => 'Greif ins Fach: Glück oder Bußgeld?',
            ],
            // 37: Millionaires 1
            37 => [
                'index'      => 37,
                'name'       => 'Sunset-Boulevard',
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
                'name'  => 'City-Maut & Luxussteuer',
                'type'  => 'tax',
                'tax'   => 100,
                'icon'  => '💎',
                'desc'  => 'Luxusabgabe: Zahle 100 $ an die Stadtkasse.',
            ],
            // 39: Millionaires 2 (Schlossallee)
            39 => [
                'index'      => 39,
                'name'       => 'Ocean-Crest-Avenue',
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
            ['text' => '🏆 Du gewinnst das Street-Race! Kassiere 150 $.', 'action' => 'cash', 'amount' => 150],
            ['text' => '🚨 Blitzer auf der Strandpromenade! Zahle 50 $ Strafe.', 'action' => 'cash', 'amount' => -50],
            ['text' => '⛽ Tankstellen-Gutschein gewonnen! Ziehe 100 $ ein.', 'action' => 'cash', 'amount' => 100],
            ['text' => '🔨 Werkstatt-Inspektion fällig: Zahle 25 $ pro Haus und 100 $ pro Hotel.', 'action' => 'repairs', 'per_house' => 25, 'per_hotel' => 100],
            ['text' => '🏁 Gib Vollgas: Fahre direkt auf START & ZIEL (kassiere 200 $).', 'action' => 'teleport', 'tile' => 0],
            ['text' => '🏖️ Abkühlung gefällig? Fahre vor zum nächsten Strand.', 'action' => 'nearest_beach'],
            ['text' => '🚓 Polizeikontrolle: Alkoholfahrt! Fahre sofort in die Verwahrstelle.', 'action' => 'go_to_jail'],
            ['text' => '🎉 Geburtstags-Cruising: Jeder Mitspieler schenkt dir 20 $.', 'action' => 'collect_from_all', 'amount' => 20],
            ['text' => '🏎️ Luxus-Tuning: Deine Autoserie gewinnt den Best-of-Show. Erhalte 100 $.', 'action' => 'cash', 'amount' => 100],
            ['text' => '🚧 Mautgebühr auf dem Ocean-Highway: Zahle 30 $.', 'action' => 'cash', 'amount' => -30],
        ];
    }

    /**
     * Gemeinschaftskarten: Handschuhfach
     */
    public static function getGloveboxCards(): array
    {
        return [
            ['text' => '💰 Steuerrückerstattung für deinen Oldtimer! Erhalte 100 $.', 'action' => 'cash', 'amount' => 100],
            ['text' => '🎫 Parkschein abgelaufen: Zahle 50 $ Verwarngeld.', 'action' => 'cash', 'amount' => -50],
            ['text' => '🩺 Kfz-Haftpflicht-Bonus ausgeschüttet: Ziehe 50 $ ein.', 'action' => 'cash', 'amount' => 50],
            ['text' => '🔑 Notfall-Schlüsselkarte: Du kommst kostenlos aus der Verwahrstelle frei.', 'action' => 'get_out_of_jail_free'],
            ['text' => '🏠 Straßen-Erneuerung: Zahle 40 $ pro Haus und 115 $ pro Hotel.', 'action' => 'repairs', 'per_house' => 40, 'per_hotel' => 115],
            ['text' => '📦 Verschollenen Geldbeutel im Fach gefunden: +25 $!', 'action' => 'cash', 'amount' => 25],
            ['text' => '🍸 Club-Abend gesponsert: Erhalte 45 $ Promo-Prämie.', 'action' => 'cash', 'amount' => 45],
            ['text' => '⚡ Mautstraße verstopft: Zahle 20 $ Umleitungsgebühr.', 'action' => 'cash', 'amount' => -20],
            ['text' => '🏖️ Einladung ins Strand-Resort: Reise direkt zur Palm Bay.', 'action' => 'teleport', 'tile' => 15],
            ['text' => '🎂 Du spendierst der ganzen Hood einen Drink: Jeder erhält 10 $ von dir.', 'action' => 'pay_to_all', 'amount' => 10],
        ];
    }
}
