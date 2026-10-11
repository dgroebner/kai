/**
 * games-hangman.js - Galgenraten (Hangman) Multiplayer & KI
 *
 * Beinhaltet:
 * 1. Drei Spielmodi:
 *    - Mode 1: Lokales Duell (Pass-and-Play) mit verdeckter Worterfassung
 *    - Mode 2: Kooperativ offline gegen das System mit kindgerechter Offline-Wortliste
 *    - Mode 3: KI-Spielleiter via Gemini (Backend API) mit interaktiven Hinweisen
 * 2. Dynamische Spielerverwaltung (2 bis 6 Spieler) mit Namenseingabe, Reihum-Zugverwaltung & Scoreboard
 * 3. Kindgerechter Wortschatz (ab 11 Jahren, 4 bis 12 Buchstaben)
 * 4. SVG-Galgen mit 8 Segmenten (Boden, Mast, Querbalken, Seil, Kopf, Rumpf, Arme, Beine)
 * 5. Bildschirmtastatur (A-Z) & physische Tastaturunterstützung
 * 6. Web Vibration API Haptik-Feedback & CSP-konforme Event-Delegation
 */
(function () {
    'use strict';

    // =========================================================================
    // 1. KINDGERECHTES OFFLINE-WÖRTERBUCH (ab 11 Jahren, ca. 240 Begriffe)
    // =========================================================================
    const OFFLINE_DICTIONARY = [
        // Tiere & Natur
        { w: 'DELFIN', c: 'Tiere & Natur', h1: 'Sehr kluges Meeressäugetier.', h2: 'Springt oft aus dem Wasser und hat eine Rückenflosse.' },
        { w: 'GECKO', c: 'Tiere & Natur', h1: 'Kleine Echse, die an glatten Wänden klettern kann.', h2: 'Hat spezielle Haftlamellen an den Zehen.' },
        { w: 'CHAMELEON', c: 'Tiere & Natur', h1: 'Reptil mit unabhängig beweglichen Augen.', h2: 'Kann seine Hautfarbe der Umgebung anpassen.' },
        { w: 'KOALA', c: 'Tiere & Natur', h1: 'Australisches Beuteltier, das viel schläft.', h2: 'Ernährt sich fast nur von Eukalyptusblättern.' },
        { w: 'LEOPARD', c: 'Tiere & Natur', h1: 'Schnelle Raubkatze mit geflecktem Fell.', h2: 'Zieht seine Beute oft auf Bäume.' },
        { w: 'KOLIBRI', c: 'Tiere & Natur', h1: 'Der kleinste Vogel der Welt.', h2: 'Kann in der Luft stehen und sogar rückwärts fliegen.' },
        { w: 'ERDMAENNCHEN', c: 'Tiere & Natur', h1: 'Kleines afrikanisches Raubtier, das in Kolonien lebt.', h2: 'Steht oft auf den Hinterbeinen Wache.' },
        { w: 'POLARFUCHS', c: 'Tiere & Natur', h1: 'Arktisches Tier mit dickem Winterfell.', h2: 'Wechselt die Fellfarbe von braun zu weiß.' },
        { w: 'PINGUIN', c: 'Tiere & Natur', h1: 'Flugunfähiger Seevogel im Frack.', h2: 'Ausgezeichneter Schwimmer und Taucher im eisigen Meer.' },
        { w: 'KROKODIL', c: 'Tiere & Natur', h1: 'Großes Panzerechsen-Reptil am Flussufer.', h2: 'Lauert mit getarnten Augen knapp über der Wasseroberfläche.' },
        { w: 'SCHMETTERLING', c: 'Tiere & Natur', h1: 'Farbenfrohes Insekt mit vier Flügeln.', h2: 'Entwickelt sich aus einer gefräßigen Raupe.' },
        { w: 'WASSERFALL', c: 'Tiere & Natur', h1: 'Flusswasser, das steil in die Tiefe stürzt.', h2: 'Erzeugt Gischt und oft einen Regenbogen.' },
        { w: 'REGENWALD', c: 'Tiere & Natur', h1: 'Dichter, feuchter Wald nahe dem Äquator.', h2: 'Gilt als grüne Lunge der Erde.' },
        { w: 'GRIZZLYBAER', c: 'Tiere & Natur', h1: 'Großer nordamerikanischer Braunbär.', h2: 'Fängt im Sommer Lachse an Wasserfällen.' },
        { w: 'SCHILDKROETE', c: 'Tiere & Natur', h1: 'Uraltes Panzertier, das sehr alt werden kann.', h2: 'Zieht Kopf und Beine bei Gefahr ein.' },
        { w: 'FLAMINGO', c: 'Tiere & Natur', h1: 'Großer Wasservogel mit rosa Gefieder.', h2: 'Steht oft stundenlang auf nur einem Bein.' },
        { w: 'KORALLENRIFF', c: 'Tiere & Natur', h1: 'Bunte Unterwasserwelt im tropischen Meer.', h2: 'Besteht aus winzigen Nesseltieren und Kalk.' },
        { w: 'IGEL', c: 'Tiere & Natur', h1: 'Kleines Stacheltier im Garten.', h2: 'Rollt sich bei Gefahr zu einer Kugel zusammen.' },
        { w: 'EICHHOERNCHEN', c: 'Tiere & Natur', h1: 'Flinkes Nagetier mit buschigem Schwanz.', h2: 'Vergräbt im Herbst Nüsse für den Winter.' },
        { w: 'SEESTERN', c: 'Tiere & Natur', h1: 'Stachelhäuter am Meeresgrund mit fünf Armen.', h2: 'Kann verlorene Arme wieder nachwachsen lassen.' },

        // Sport & Freizeit
        { w: 'SKATEBOARD', c: 'Sport & Freizeit', h1: 'Rollendes Brett für Tricks und Sprünge.', h2: 'Man steht seitlich darauf und stößt sich mit dem Fuß ab.' },
        { w: 'TRAMPOLIN', c: 'Sport & Freizeit', h1: 'Federndes Sprunggerät im Garten oder Turnsaal.', h2: 'Ermöglicht Saltos und hohe Sprünge.' },
        { w: 'BASKETBALL', c: 'Sport & Freizeit', h1: 'Ballsportart mit hohem Korb und Netz.', h2: 'Wird oft gedribbelt und mit zwei Händen geworfen.' },
        { w: 'SNOWBOARD', c: 'Sport & Freizeit', h1: 'Breites Brett zum Abfahren auf Schnee.', h2: 'Beide Füße sind fest auf demselben Brett montiert.' },
        { w: 'BADMINTON', c: 'Sport & Freizeit', h1: 'Rückschlagspiel mit leichtem Federball.', h2: 'Wird mit leichten Schlägern über ein Netz gespielt.' },
        { w: 'KLETTERWAND', c: 'Sport & Freizeit', h1: 'Künstliche Wand mit bunten Griffen.', h2: 'Man sichert sich mit Seil und Klettergurt.' },
        { w: 'TAUCHMASKE', c: 'Sport & Freizeit', h1: 'Ausrüstung für klare Sicht unter Wasser.', h2: 'Umschließt Augen und Nase luftdicht.' },
        { w: 'INLINESKATES', c: 'Sport & Freizeit', h1: 'Rollschuhe mit Rollen in einer einzigen Reihe.', h2: 'Man bremst meistens mit einem Stopper an der Ferse.' },
        { w: 'FALLSCHIRM', c: 'Sport & Freizeit', h1: 'Großes Stofftuch zum sanften Landen aus dem Flugzeug.', h2: 'Bremst den freien Fall durch Luftwiderstand.' },
        { w: 'FAHRRADHELM', c: 'Sport & Freizeit', h1: 'Wichtiger Kopfschutz beim Radfahren.', h2: 'Besteht aus Hartschaum mit Kinnriemen.' },
        { w: 'TORWART', c: 'Sport & Freizeit', h1: 'Spieler, der den Kasten sauber hält.', h2: 'Darf den Ball als einziger mit den Händen berühren.' },
        { w: 'SCHLITTSCHUH', c: 'Sport & Freizeit', h1: 'Schuh mit scharfer Kufe aus Stahl.', h2: 'Zum Gleiten über spiegelglattes Eis.' },
        { w: 'SCHWIMMBAD', c: 'Sport & Freizeit', h1: 'Ort mit Becken zum Schwimmen und Planschen.', h2: 'Hat oft ein 1-Meter- und 3-Meter-Sprungbrett.' },
        { w: 'TISCHTENNIS', c: 'Sport & Freizeit', h1: 'Sehr schnelles Spiel mit Zelluloid-Ball und Schlägern.', h2: 'Wird umgangssprachlich auch Pingpong genannt.' },
        { w: 'SURFBRETT', c: 'Sport & Freizeit', h1: 'Langes Brett zum Reiten auf Meereswellen.', h2: 'Wird oft mit einer Leash am Knöchel befestigt.' },

        // Essen & Trinken
        { w: 'SPAGHETTI', c: 'Essen & Trinken', h1: 'Lange, dünne Nudeln aus Italien.', h2: 'Werden gern mit Bolognese oder Tomatensauce serviert.' },
        { w: 'PANCAKE', c: 'Essen & Trinken', h1: 'Dicker, runder Eierkuchen aus der Pfanne.', h2: 'Wird in Amerika gern mit Ahornsirup gegessen.' },
        { w: 'PIZZA', c: 'Essen & Trinken', h1: 'Hefeteigfladen mit Tomatensoße und Käse überbacken.', h2: 'Kommt traditionell aus dem heißen Steinofen.' },
        { w: 'CROISSANT', c: 'Essen & Trinken', h1: 'Französisches Hörnchen aus Blätterteig.', h2: 'Sehr buttrig und knusprig zum Frühstück.' },
        { w: 'SCHOKOLADE', c: 'Essen & Trinken', h1: 'Süßigkeit aus Kakaobohnen und Zucker.', h2: 'Schmilzt im Mund und knackt beim Abbrechen.' },
        { w: 'ERDBEERE', c: 'Essen & Trinken', h1: 'Rote Sommerfrucht, botanisch eine Sammelnussfrucht.', h2: 'Trägt ihre kleinen gelben Nüsschen außen auf der Haut.' },
        { w: 'BURGER', c: 'Essen & Trinken', h1: 'Rundes Brötchen mit Patty, Salat und Sauce.', h2: 'Typischer Snack in Fast-Food-Restaurants.' },
        { w: 'WASSERMELONE', c: 'Essen & Trinken', h1: 'Große grüne Frucht mit süßem rotem Fruchtfleisch.', h2: 'Besteht zu über 90 Prozent aus Wasser.' },
        { w: 'POPCORN', c: 'Essen & Trinken', h1: 'Aufgeplatzte Maiskörner als Kino-Snack.', h2: 'Wahlweise süß oder salzig zubereitet.' },
        { w: 'APFELSTRUDEL', c: 'Essen & Trinken', h1: 'Gebäckrolle gefüllt mit Apfelstücken und Zimt.', h2: 'Wird in Österreich gerne mit Vanillesauce serviert.' },
        { w: 'HIMBEERE', c: 'Essen & Trinken', h1: 'Kleine, weiche rote Beere mit samtiger Oberfläche.', h2: 'Wächst an stacheligen Sträuchern im Wald oder Garten.' },
        { w: 'BREZEL', c: 'Essen & Trinken', h1: 'Laugengebäck mit verschlungenem Knoten.', h2: 'Ist oft mit groben Salzkörnern bestreut.' },

        // Geografie & Welt
        { w: 'VULKAN', c: 'Geografie & Welt', h1: 'Feuerspeiender Berg der Erde.', h2: 'Spuckt flüssige Magma bzw. heiße Lava aus.' },
        { w: 'KOMPASS', c: 'Geografie & Welt', h1: 'Navigationsinstrument mit magnetischer Nadel.', h2: 'Die Nadel zeigt immer zuverlässig nach Norden.' },
        { w: 'KONTINENT', c: 'Geografie & Welt', h1: 'Eine der großen Landmassen der Erde.', h2: 'Beispiele sind Europa, Asien oder Afrika.' },
        { w: 'PYRAMIDE', c: 'Geografie & Welt', h1: 'Monumentales Bauwerk im alten Ägypten.', h2: 'Diente den Pharaonen als Grabstätte.' },
        { w: 'GLETSCHER', c: 'Geografie & Welt', h1: 'Riesige Eismasse im Hochgebirge.', h2: 'Fließt extrem langsam talwärts.' },
        { w: 'INSEL', c: 'Geografie & Welt', h1: 'Ein Stück Land, das ganz von Wasser umgeben ist.', h2: 'Man erreicht es nur per Schiff oder Flugzeug.' },
        { w: 'ATLANTIK', c: 'Geografie & Welt', h1: 'Zweitgrößter Ozean unseres Planeten.', h2: 'Trennt Europa und Afrika von Amerika.' },
        { w: 'LEUCHTTURM', c: 'Geografie & Welt', h1: 'Hoher Turm an gefährlichen Küsten.', h2: 'Sendet Lichtsignale an Schiffe in der Nacht.' },
        { w: 'CANYON', c: 'Geografie & Welt', h1: 'Tiefe Schlucht, die ein Fluss in Felsen gegraben hat.', h2: 'Berühmt ist der Grand Canyon in den USA.' },
        { w: 'AQUATOR', c: 'Geografie & Welt', h1: 'Gedachte Linie, die die Erde in Nord und Süd teilt.', h2: 'Hier ist es das ganze Jahr über tropisch warm.' },
        { w: 'WUESTE', c: 'Geografie & Welt', h1: 'Extrem trockene Landschaft fast ohne Pflanzen.', h2: 'Besteht oft aus Sanddünen oder Geröll.' },

        // Schule & Wissenschaft
        { w: 'TELESKOP', c: 'Schule & Wissenschaft', h1: 'Fernrohr zum Beobachten von Sternen und Planeten.', h2: 'Wird von Astronomen in Sternwarten benutzt.' },
        { w: 'MIKROSKOP', c: 'Schule & Wissenschaft', h1: 'Optisches Gerät für winzige Dinge wie Bakterien.', h2: 'Vergrößert Objekte um das Hundertfache.' },
        { w: 'ROBOTER', c: 'Schule & Wissenschaft', h1: 'Programmierbare Maschine, die Aufgaben erledigt.', h2: 'Arbeitet oft mit Sensoren und künstlicher Intelligenz.' },
        { w: 'SATELLIT', c: 'Schule & Wissenschaft', h1: 'Künstlicher Flugkörper, der die Erde umkreist.', h2: 'Überträgt GPS-Signale, Fernsehbilder und Wetterdaten.' },
        { w: 'ASTRONAUT', c: 'Schule & Wissenschaft', h1: 'Mensch, der ins Weltall fliegt.', h2: 'Arbeitet zum Beispiel auf der Raumstation ISS.' },
        { w: 'FOSSIL', c: 'Schule & Wissenschaft', h1: 'Versteinerter Überrest von Tieren oder Pflanzen.', h2: 'Verrät Forschern, wie Dinosaurier gelebt haben.' },
        { w: 'GRAVITATION', c: 'Schule & Wissenschaft', h1: 'Naturkraft, die Gegenstände nach unten zieht.', h2: 'Lässt Äpfel vom Baum zu Boden fallen.' },
        { w: 'DINOSAURIER', c: 'Schule & Wissenschaft', h1: 'Urzeitliches Reptil, das vor Jahrmillionen lebte.', h2: 'Beispiele sind der T-Rex oder der Brachiosaurus.' },
        { w: 'CHEMIELABOR', c: 'Schule & Wissenschaft', h1: 'Raum zum Experimentieren mit Reagenzgläsern.', h2: 'Hier trägt man Schutzbrille und weißen Kittel.' },
        { w: 'TASCHENRECHNER', c: 'Schule & Wissenschaft', h1: 'Elektronisches Gerät zum Lösen von Matheaufgaben.', h2: 'Hat Tasten für Plus, Minus, Mal und Geteilt.' },

        // Unterhaltung & Medien
        { w: 'KOPFHOERER', c: 'Unterhaltung & Medien', h1: 'Gerät zum Hören von Musik direkt auf den Ohren.', h2: 'Wird per Bluetooth oder Kabel mit dem Handy verbunden.' },
        { w: 'SMARTPHONE', c: 'Unterhaltung & Medien', h1: 'Mobiltelefon mit Touchscreen und vielen Apps.', h2: 'Dient zum Telefonieren, Chatten und Fotografieren.' },
        { w: 'CONTROLLER', c: 'Unterhaltung & Medien', h1: 'Steuergerät für Spiele an der Konsole.', h2: 'Hat Joysticks, Steuerkreuze und Schultertasten.' },
        { w: 'KINOSAAL', c: 'Unterhaltung & Medien', h1: 'Dunkler Raum mit riesiger Leinwand.', h2: 'Man schaut dort Filme in bequemen Kinosesseln.' },
        { w: 'PODCAST', c: 'Unterhaltung & Medien', h1: 'Hörsendung im Internet, ähnlich wie ein Radioprogramm.', h2: 'Kann man abonnieren und unterwegs anhören.' },
        { w: 'ZAUBERER', c: 'Unterhaltung & Medien', h1: 'Künstler, der verblüffende Illusionen vorführt.', h2: 'Zieht zum Beispiel ein Kaninchen aus dem Zylinder.' },
        { w: 'COMICHEFT', c: 'Unterhaltung & Medien', h1: 'Bildergeschichte mit Sprechblasen.', h2: 'Erzählt Abenteuer von Superhelden oder Detektiven.' },
        { w: 'SCHLAGZEUG', c: 'Unterhaltung & Medien', h1: 'Rhythmus-Musikinstrument aus Trommeln und Becken.', h2: 'Wird mit hölzernen Drumsticks gespielt.' },

        // Alltag & Zuhause
        { w: 'RUCKSACK', c: 'Alltag & Zuhause', h1: 'Praktische Tasche, die man auf dem Rücken trägt.', h2: 'Ideal für Schulsachen oder Wanderausflüge.' },
        { w: 'WECKER', c: 'Alltag & Zuhause', h1: 'Uhr, die morgens mit Klingeln aufweckt.', h2: 'Hat oft eine Schlummer- bzw. Snooze-Taste.' },
        { w: 'TASCHENLAMPE', c: 'Alltag & Zuhause', h1: 'Handliche Leuchte mit Batterie für die Dunkelheit.', h2: 'Hilft beim Suchen im Keller oder beim Zelten.' },
        { w: 'REISSVERSCHLUSS', c: 'Alltag & Zuhause', h1: 'Verschluss an Jacken mit kleinen Zähnchen.', h2: 'Wird mit einem Schieber auf- und zugezogen.' },
        { w: 'ZAHNBUERSTE', c: 'Alltag & Zuhause', h1: 'Hygiene-Werkzeug für saubere Zähne.', h2: 'Wird mit Zahnpasta zweimal täglich benutzt.' },
        { w: 'HAENGEMATTE', c: 'Alltag & Zuhause', h1: 'Schwebendes Liegetuch zwischen zwei Bäumen.', h2: 'Schaukelt gemütlich im Sommergarten.' }
    ];

    // =========================================================================
    // HILFSFUNKTIONEN
    // =========================================================================
    function triggerVibration(pattern = [30]) {
        if (typeof navigator !== 'undefined' && 'vibrate' in navigator) {
            try {
                navigator.vibrate(pattern);
            } catch (_) {}
        }
    }

    function sanitizeWord(str) {
        if (!str) return '';
        return String(str)
            .toUpperCase()
            .replace(/Ä/g, 'AE')
            .replace(/Ö/g, 'OE')
            .replace(/Ü/g, 'UE')
            .replace(/ß/g, 'SS')
            .replace(/[^A-Z]/g, '');
    }

    // =========================================================================
    // HANGMAN ENGINE
    // =========================================================================
    const Hangman = {
        STORAGE_PLAYERS_KEY: 'kai_hangman_players_v1',
        MAX_MISTAKES: 8,

        // Status
        mode: 'offline', // 'solo' | 'offline' | 'pass_play' | 'ai'
        currentWord: '',
        category: 'Allgemein',
        hint1: '',
        hint2: '',
        wordSource: 'offline',
        previousMode: 'offline',

        // Spieler
        players: [
            { name: 'Spieler 1', score: 0 },
            { name: 'Spieler 2', score: 0 }
        ],
        activePlayerIndex: 0,
        wordGiverIndex: 0, // für Modus Pass-and-Play

        // Rundenstatus
        guessedLetters: new Set(),
        mistakesCount: 0,
        isGameOver: false,
        isWon: false,
        unlockedHintLevel: 0, // 0 = keins, 1 = hint1, 2 = hint2

        init() {
            this.loadPlayers();
            this.bindDOMElements();
            this.setupInitialGame();
        },

        bindDOMElements() {
            this.rootPane = document.getElementById('tool-pane-hangman');
            this.categoryBadge = document.getElementById('hangman-category-badge');
            this.slotsContainer = document.getElementById('hangman-word-slots');
            this.keyboardContainer = document.getElementById('hangman-keyboard');
            this.playersBar = document.getElementById('hangman-players-bar');
            this.statusBanner = document.getElementById('hangman-status-banner');
            this.hintBox = document.getElementById('hangman-hint-box');
            this.hintText = document.getElementById('hangman-hint-text');
            this.btnRequestHint = document.getElementById('btn-hangman-request-hint');
            this.svgStages = document.querySelectorAll('.hangman-part');

            // Modus-Radio/Buttons
            this.modeButtons = document.querySelectorAll('.js-hangman-mode-btn');

            // Setup Modal Elements
            this.setupModal = document.getElementById('hangman-setup-modal');
            this.wordInputModal = document.getElementById('hangman-word-input-modal');
        },

        loadPlayers() {
            try {
                const saved = sessionStorage.getItem(this.STORAGE_PLAYERS_KEY);
                if (saved) {
                    const parsed = JSON.parse(saved);
                    if (Array.isArray(parsed) && parsed.length >= 1) {
                        this.players = parsed.map(p => ({
                            name: String(p.name || 'Spieler').trim() || 'Spieler',
                            score: Number(p.score) || 0
                        }));
                    }
                }
            } catch (_) {}
        },

        savePlayers() {
            try {
                sessionStorage.setItem(this.STORAGE_PLAYERS_KEY, JSON.stringify(this.players));
            } catch (_) {}
        },

        setPlayerCount(count) {
            count = Math.max(1, Math.min(6, count));
            while (this.players.length < count) {
                this.players.push({ name: `Spieler ${this.players.length + 1}`, score: 0 });
            }
            while (this.players.length > count) {
                this.players.pop();
            }
            this.savePlayers();
            this.renderPlayersBar();
        },

        setMode(mode) {
            if (!['solo', 'pass_play', 'offline', 'ai'].includes(mode)) return;
            this.previousMode = this.mode;
            this.mode = mode;

            // Wenn Solo gewählt wird, Spieler auf 1 begrenzen falls gewünscht bzw. als Solo markieren
            if (mode === 'solo' && this.players.length > 1) {
                // Spieler 1 bleibt aktiv
                this.activePlayerIndex = 0;
            }

            // Buttons aktualisieren
            if (this.modeButtons) {
                this.modeButtons.forEach(btn => {
                    if (btn.dataset.mode === mode) {
                        btn.classList.add('active');
                        btn.classList.remove('btn-outline');
                    } else {
                        btn.classList.remove('active');
                        btn.classList.add('btn-outline');
                    }
                });
            }

            this.startNewRound();
        },

        setupInitialGame() {
            this.renderKeyboard();
            this.startNewRound();
        },

        async startNewRound() {
            this.guessedLetters.clear();
            this.mistakesCount = 0;
            this.isGameOver = false;
            this.isWon = false;
            this.unlockedHintLevel = 0;
            this.updateSVG();

            if (this.statusBanner) {
                this.statusBanner.classList.add('hidden');
                this.statusBanner.textContent = '';
            }

            if (this.hintBox) {
                this.hintBox.classList.add('hidden');
            }
            if (this.btnRequestHint) {
                this.btnRequestHint.classList.add('hidden');
            }

            // Je nach Modus das Wort ermitteln
            if (this.mode === 'pass_play') {
                this.openWordInputModal();
            } else if (this.mode === 'ai') {
                await this.fetchAIWord();
            } else {
                // Offline-Modus
                this.pickOfflineWord();
            }

            this.renderWordSlots();
            this.renderKeyboard();
            this.renderPlayersBar();
        },

        pickOfflineWord() {
            const pick = OFFLINE_DICTIONARY[Math.floor(Math.random() * OFFLINE_DICTIONARY.length)];
            this.currentWord = sanitizeWord(pick.w);
            this.category = pick.c || 'Allgemein';
            this.hint1 = pick.h1 || '';
            this.hint2 = pick.h2 || '';
            this.wordSource = 'offline';
            this.updateCategoryDisplay();
        },

        async fetchAIWord() {
            if (this.categoryBadge) {
                this.categoryBadge.textContent = '🤖 KI denkt nach...';
            }

            try {
                const res = await KaiHttp.postJson('api.php', {
                    action: 'generate_word'
                });

                if (res.success && res.data && res.data.word) {
                    this.currentWord = sanitizeWord(res.data.word);
                    this.category = res.data.category || 'KI-Spielleiter';
                    this.hint1 = res.data.hint1 || '';
                    this.hint2 = res.data.hint2 || '';
                    this.wordSource = res.data.source || 'gemini';
                } else {
                    // Fallback
                    this.pickOfflineWord();
                }
            } catch (err) {
                this.pickOfflineWord();
            }

            this.updateCategoryDisplay();
            this.renderWordSlots();
        },

        openWordInputModal() {
            if (!this.wordInputModal) return;

            const giver = this.players[this.wordGiverIndex] || this.players[0];
            const giverTitle = document.getElementById('hangman-giver-name');
            if (giverTitle) {
                giverTitle.textContent = giver.name;
            }

            const inputField = document.getElementById('hangman-custom-word');
            const categoryField = document.getElementById('hangman-custom-category');
            if (inputField) inputField.value = '';
            if (categoryField) categoryField.value = '';

            this.wordInputModal.classList.remove('hidden');
        },

        confirmWordInput() {
            const inputField = document.getElementById('hangman-custom-word');
            const categoryField = document.getElementById('hangman-custom-category');
            const rawWord = inputField ? inputField.value.trim() : '';
            const clean = sanitizeWord(rawWord);

            if (clean.length < 4 || clean.length > 14) {
                alert('Bitte gib ein Wort mit 4 bis 14 Buchstaben ein.');
                return;
            }

            this.currentWord = clean;
            this.category = (categoryField && categoryField.value.trim()) ? categoryField.value.trim() : 'Duell';
            this.hint1 = 'Vom Mitspieler ausgedacht.';
            this.hint2 = '';
            this.wordSource = 'player';

            // Der nächste Rater nach dem Wortgeber ist am Zug
            this.activePlayerIndex = (this.wordGiverIndex + 1) % this.players.length;

            if (this.wordInputModal) {
                this.wordInputModal.classList.add('hidden');
            }

            this.updateCategoryDisplay();
            this.renderWordSlots();
            this.renderKeyboard();
            this.renderPlayersBar();
            triggerVibration([25]);
        },

        cancelWordInput() {
            if (this.wordInputModal) {
                this.wordInputModal.classList.add('hidden');
            }
            // Wenn man im Duell abbricht, schalten wir auf den vorherigen Modus oder Standard (offline) zurück
            const fallbackMode = (this.previousMode && this.previousMode !== 'pass_play') ? this.previousMode : 'offline';
            this.setMode(fallbackMode);
        },

        updateCategoryDisplay() {
            if (this.categoryBadge) {
                this.categoryBadge.textContent = `Thema: ${this.category}`;
            }
        },

        guessLetter(letter) {
            if (this.isGameOver || !this.currentWord) return;
            letter = letter.toUpperCase();
            if (this.guessedLetters.has(letter)) return;

            this.guessedLetters.add(letter);

            const isHit = this.currentWord.includes(letter);

            if (isHit) {
                triggerVibration([30]);
                // Prüfen ob gewonnen
                const allRevealed = [...this.currentWord].every(char => this.guessedLetters.has(char));
                if (allRevealed) {
                    this.handleWin();
                } else {
                    // Bei Treffer darf der aktive Spieler weitermachen
                }
            } else {
                triggerVibration([40, 50, 40]);
                this.mistakesCount++;
                this.updateSVG();

                // Nächster Spieler ist dran
                this.nextPlayerTurn();

                // Prüfen ob verloren
                if (this.mistakesCount >= this.MAX_MISTAKES) {
                    this.handleLoss();
                }
            }

            this.checkHintAvailability();
            this.renderWordSlots();
            this.renderKeyboard();
            this.renderPlayersBar();
        },

        nextPlayerTurn() {
            if (this.players.length <= 1 || this.mode === 'solo') {
                this.activePlayerIndex = 0;
                return;
            }

            if (this.mode === 'pass_play') {
                // Wortgeber überspringen, da er das Wort kennt
                let nextIdx = (this.activePlayerIndex + 1) % this.players.length;
                if (nextIdx === this.wordGiverIndex) {
                    nextIdx = (nextIdx + 1) % this.players.length;
                }
                this.activePlayerIndex = nextIdx;
            } else {
                this.activePlayerIndex = (this.activePlayerIndex + 1) % this.players.length;
            }
        },

        checkHintAvailability() {
            if (this.isGameOver) return;

            // Tipps nur bei Modus 3 (KI) oder wenn Hint-Texte vorhanden sind
            if (!this.hint1) return;

            if (this.mistakesCount >= 5 && this.hint2 && this.unlockedHintLevel < 2) {
                if (this.btnRequestHint) {
                    this.btnRequestHint.classList.remove('hidden');
                    this.btnRequestHint.textContent = '💡 Tipp 2 freischalten';
                }
            } else if (this.mistakesCount >= 3 && this.hint1 && this.unlockedHintLevel < 1) {
                if (this.btnRequestHint) {
                    this.btnRequestHint.classList.remove('hidden');
                    this.btnRequestHint.textContent = '💡 Tipp 1 freischalten';
                }
            } else {
                if (this.btnRequestHint && this.unlockedHintLevel >= 2) {
                    this.btnRequestHint.classList.add('hidden');
                }
            }
        },

        revealHint() {
            if (this.unlockedHintLevel === 0 && this.hint1) {
                this.unlockedHintLevel = 1;
                if (this.hintBox && this.hintText) {
                    this.hintBox.classList.remove('hidden');
                    this.hintText.textContent = `Tipp 1: ${this.hint1}`;
                }
            } else if (this.unlockedHintLevel === 1 && this.hint2) {
                this.unlockedHintLevel = 2;
                if (this.hintBox && this.hintText) {
                    this.hintBox.classList.remove('hidden');
                    this.hintText.textContent = `Tipp 2: ${this.hint2}`;
                }
            }

            if (this.btnRequestHint) {
                this.btnRequestHint.classList.add('hidden');
            }
            triggerVibration([20]);
        },

        handleWin() {
            this.isGameOver = true;
            this.isWon = true;
            triggerVibration([50, 60, 50, 100]);

            // Punkte an aktiven Spieler
            const winner = this.players[this.activePlayerIndex];
            if (winner) {
                winner.score += 10;
                this.savePlayers();
            }

            if (this.statusBanner) {
                this.statusBanner.className = 'hangman-status-banner status-won';
                const winName = (this.mode === 'solo') ? (winner ? winner.name : 'Du') : (winner ? winner.name : 'Die Gruppe');
                this.statusBanner.textContent = `🎉 Super gelöst! ${winName} gewinnt 10 Punkte!`;
                this.statusBanner.classList.remove('hidden');
            }

            if (this.mode === 'pass_play') {
                // Wortgeber wechselt reihum für die nächste Runde
                this.wordGiverIndex = (this.wordGiverIndex + 1) % this.players.length;
            }
        },

        handleLoss() {
            this.isGameOver = true;
            this.isWon = false;
            triggerVibration([100, 100, 150]);

            if (this.mode === 'pass_play') {
                // Wortgeber bekommt Punkte
                const giver = this.players[this.wordGiverIndex];
                if (giver) {
                    giver.score += 10;
                    this.savePlayers();
                }
            }

            if (this.statusBanner) {
                this.statusBanner.className = 'hangman-status-banner status-lost';
                const giverName = (this.mode === 'pass_play' && this.players[this.wordGiverIndex])
                    ? this.players[this.wordGiverIndex].name
                    : 'Das Galgenmännchen';
                this.statusBanner.textContent = `💀 Schade, verloren! Das gesuchte Wort war: ${this.currentWord} (Punkt an ${giverName})`;
                this.statusBanner.classList.remove('hidden');
            }

            if (this.mode === 'pass_play') {
                this.wordGiverIndex = (this.wordGiverIndex + 1) % this.players.length;
            }
        },

        updateSVG() {
            if (!this.svgStages) return;
            this.svgStages.forEach(el => {
                const step = Number(el.dataset.step);
                if (step <= this.mistakesCount) {
                    el.classList.add('is-visible');
                } else {
                    el.classList.remove('is-visible');
                }
            });
        },

        renderWordSlots() {
            if (!this.slotsContainer) return;
            this.slotsContainer.innerHTML = '';

            [...this.currentWord].forEach(char => {
                const slot = document.createElement('div');
                slot.className = 'hangman-slot';

                if (this.guessedLetters.has(char) || (this.isGameOver && !this.isWon)) {
                    slot.textContent = char;
                    if (!this.guessedLetters.has(char)) {
                        slot.classList.add('slot-revealed-loss');
                    }
                } else {
                    slot.textContent = '';
                }

                this.slotsContainer.appendChild(slot);
            });
        },

        renderKeyboard() {
            if (!this.keyboardContainer) return;
            this.keyboardContainer.innerHTML = '';

            const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');

            alphabet.forEach(letter => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'hangman-key js-hangman-key';
                btn.textContent = letter;
                btn.dataset.letter = letter;

                if (this.guessedLetters.has(letter)) {
                    btn.disabled = true;
                    if (this.currentWord.includes(letter)) {
                        btn.classList.add('key-hit');
                    } else {
                        btn.classList.add('key-miss');
                    }
                }

                if (this.isGameOver) {
                    btn.disabled = true;
                }

                this.keyboardContainer.appendChild(btn);
            });
        },

        renderPlayersBar() {
            if (!this.playersBar) return;
            this.playersBar.innerHTML = '';

            const playersToRender = (this.mode === 'solo') ? [this.players[0] || { name: 'Spieler 1', score: 0 }] : this.players;

            playersToRender.forEach((p, idx) => {
                const badge = document.createElement('div');
                badge.className = 'hangman-player-badge';

                const isActive = (idx === this.activePlayerIndex && !this.isGameOver);
                const isGiver = (this.mode === 'pass_play' && idx === this.wordGiverIndex);

                if (isActive) badge.classList.add('is-active');
                if (isGiver) badge.classList.add('is-giver');

                let title = p.name;
                if (isGiver) title += ' ✍️';
                else if (isActive && this.mode !== 'solo') title += ' 🎯';

                badge.innerHTML = `
                    <span class="player-name">${title}</span>
                    <span class="player-score">${p.score} Pkt.</span>
                `;

                this.playersBar.appendChild(badge);
            });
        },

        openSetupModal() {
            if (!this.setupModal) return;

            const countBtns = this.setupModal.querySelectorAll('.js-setup-count-btn');
            countBtns.forEach(btn => {
                const count = Number(btn.dataset.count);
                if (count === this.players.length) {
                    btn.classList.add('active');
                    btn.classList.remove('btn-outline');
                } else {
                    btn.classList.remove('active');
                    btn.classList.add('btn-outline');
                }
            });

            this.renderSetupNamesList();
            this.setupModal.classList.remove('hidden');
        },

        renderSetupNamesList() {
            const listEl = document.getElementById('hangman-setup-names-list');
            if (!listEl) return;
            listEl.innerHTML = '';

            this.players.forEach((p, idx) => {
                const row = document.createElement('div');
                row.className = 'hangman-setup-name-row';
                row.innerHTML = `
                    <label for="setup-player-${idx}">Spieler ${idx + 1}:</label>
                    <input type="text" id="setup-player-${idx}" class="form-control js-setup-name-input" data-index="${idx}" value="${p.name}" maxlength="20">
                `;
                listEl.appendChild(row);
            });
        },

        saveSetupModal() {
            const inputs = document.querySelectorAll('.js-setup-name-input');
            inputs.forEach(inp => {
                const idx = Number(inp.dataset.index);
                if (this.players[idx]) {
                    this.players[idx].name = inp.value.trim() || `Spieler ${idx + 1}`;
                }
            });

            this.savePlayers();
            if (this.setupModal) {
                this.setupModal.classList.add('hidden');
            }
            this.renderPlayersBar();
            triggerVibration([20]);
        },

        resetAllScores() {
            if (confirm('Möchtest du alle Punktestände auf 0 zurücksetzen?')) {
                this.players.forEach(p => p.score = 0);
                this.savePlayers();
                this.renderPlayersBar();
                triggerVibration([20]);
            }
        }
    };

    // =========================================================================
    // EVENT-DELEGATION (CSP-KONFORM)
    // =========================================================================
    document.addEventListener('DOMContentLoaded', () => {
        Hangman.init();

        const container = document.getElementById('tool-pane-hangman');
        if (!container) return;

        // Klicks
        container.addEventListener('click', (e) => {
            // Modus-Umschaltung
            const modeBtn = e.target.closest('.js-hangman-mode-btn');
            if (modeBtn) {
                Hangman.setMode(modeBtn.dataset.mode);
                return;
            }

            // Buchstabentaste
            const keyBtn = e.target.closest('.js-hangman-key');
            if (keyBtn && !keyBtn.disabled) {
                Hangman.guessLetter(keyBtn.dataset.letter);
                return;
            }

            // Neues Spiel / Neue Runde
            if (e.target.closest('.js-hangman-new-game-btn')) {
                Hangman.startNewRound();
                return;
            }

            // Tipp anfordern
            if (e.target.closest('#btn-hangman-request-hint')) {
                Hangman.revealHint();
                return;
            }

            // Setup öffnen
            if (e.target.closest('.js-hangman-open-setup-btn')) {
                Hangman.openSetupModal();
                return;
            }

            // Punktestand zurücksetzen
            if (e.target.closest('.js-hangman-reset-scores-btn')) {
                Hangman.resetAllScores();
                return;
            }
        });

        // Setup-Modal Klicks
        const setupModal = document.getElementById('hangman-setup-modal');
        if (setupModal) {
            setupModal.addEventListener('click', (e) => {
                const countBtn = e.target.closest('.js-setup-count-btn');
                if (countBtn) {
                    Hangman.setPlayerCount(Number(countBtn.dataset.count));
                    Hangman.renderSetupNamesList();
                    setupModal.querySelectorAll('.js-setup-count-btn').forEach(b => {
                        if (b === countBtn) {
                            b.classList.add('active');
                            b.classList.remove('btn-outline');
                        } else {
                            b.classList.remove('active');
                            b.classList.add('btn-outline');
                        }
                    });
                    return;
                }

                if (e.target.closest('.js-setup-save-btn')) {
                    Hangman.saveSetupModal();
                    return;
                }

                if (e.target.closest('.js-setup-close-btn') || e.target === setupModal) {
                    setupModal.classList.add('hidden');
                    return;
                }
            });
        }

        // Pass-and-Play Worterfassung Modal
        const wordModal = document.getElementById('hangman-word-input-modal');
        if (wordModal) {
            wordModal.addEventListener('click', (e) => {
                if (e.target.closest('.js-word-modal-ok')) {
                    Hangman.confirmWordInput();
                    return;
                }
                if (e.target.closest('.js-word-modal-cancel') || e.target === wordModal) {
                    Hangman.cancelWordInput();
                    return;
                }
                if (e.target.closest('.js-toggle-word-visibility')) {
                    const inp = document.getElementById('hangman-custom-word');
                    if (inp) {
                        inp.type = (inp.type === 'password') ? 'text' : 'password';
                        e.target.textContent = (inp.type === 'password') ? '👁️' : '🙈';
                    }
                    return;
                }
            });
        }

        // Tastatureingabe für Buchstaben
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const wordM = document.getElementById('hangman-word-input-modal');
                if (wordM && !wordM.classList.contains('hidden')) {
                    Hangman.cancelWordInput();
                    return;
                }
                const setupM = document.getElementById('hangman-setup-modal');
                if (setupM && !setupM.classList.contains('hidden')) {
                    setupM.classList.add('hidden');
                    return;
                }
            }

            // Nur wenn Hangman Tab sichtbar ist
            const hangmanPane = document.getElementById('tool-pane-hangman');
            if (!hangmanPane || hangmanPane.classList.contains('hidden')) return;

            // Keine Eingaben fangen, wenn ein Textinput aktiv ist
            if (['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName)) return;

            const key = e.key.toUpperCase();
            if (/^[A-ZÄÖÜ]$/.test(key)) {
                e.preventDefault();
                Hangman.guessLetter(key);
            }
        });
    });
})();
