/**
 * Quarxl Playing Cards Engine & Design System
 * Zentrales Rendering & Audio für Deutsches (Altenburger Art) & Französisches Blatt
 */
(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.QuarxlCards = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // -------------------------------------------------------------------------
    // 1. VEKTOR-SVGS FÜR DEUTSCHE & FRANZÖSISCHE SPIELKARTEN
    // -------------------------------------------------------------------------
    const SVG = {
        // --- DEUTSCHES BLATT (Altenburger Art) ---

        // Eichel: Originalgetreue rot-goldene Altenburger Eichel mit grünem Schuppenhütchen
        acorns: `
            <svg viewBox="0 0 100 130" class="quarxl-svg-suit svg-acorns">
                <!-- Stiel & Blättchen an der Basis -->
                <path d="M50,126 Q46,114 50,102" stroke="#14532d" stroke-width="5" stroke-linecap="round" fill="none" />
                <path d="M50,116 Q34,120 30,110 Q40,106 50,110 Z" fill="#15803d" />
                <path d="M50,116 Q66,120 70,110 Q60,106 50,110 Z" fill="#15803d" />
                <!-- Grünes Schuppen-Hütchen (Kappe) -->
                <path d="M22,102 C22,80 78,80 78,102 C78,110 22,110 22,102 Z" fill="#16a34a" stroke="#14532d" stroke-width="2.5" />
                <!-- Schuppenmuster -->
                <path d="M26,96 Q50,104 74,96" stroke="#14532d" stroke-width="2" fill="none" />
                <path d="M30,90 Q50,98 70,90" stroke="#14532d" stroke-width="2" fill="none" />
                <path d="M36,84 Q50,92 64,84" stroke="#14532d" stroke-width="2" fill="none" />
                <path d="M38,82 L38,106 M50,80 L50,108 M62,82 L62,106" stroke="#14532d" stroke-width="1.8" stroke-dasharray="2,3" fill="none" />
                <!-- Eichel-Körper: Schlank, aufrecht, zweifarbig rot/gold geteilt -->
                <!-- Linke Hälfte: Karminrot -->
                <path d="M50,8 C42,20 23,48 23,82 C23,90 35,98 50,98 Z" fill="#dc2626" />
                <path d="M50,8 C44,22 30,52 30,80 C30,88 38,94 50,96 Z" fill="#ef4444" opacity="0.4" />
                <!-- Rechte Hälfte: Sonnengelb / Gold -->
                <path d="M50,8 C58,20 77,48 77,82 C77,90 65,98 50,98 Z" fill="#f59e0b" />
                <path d="M50,8 C56,22 70,52 70,80 C70,88 62,94 50,96 Z" fill="#fbbf24" opacity="0.65" />
                <!-- Mittlere Naht & Kontur -->
                <path d="M50,8 L50,98" stroke="#78350f" stroke-width="2.5" stroke-linecap="round" />
                <path d="M50,8 C42,20 23,48 23,82 C23,90 35,98 50,98 C65,98 77,90 77,82 C77,48 58,20 50,8 Z" stroke="#78350f" stroke-width="2.5" fill="none" />
                <!-- Glanzlicht an der Spitze -->
                <circle cx="50" cy="14" r="3.5" fill="#fef08a" />
            </svg>
        `,

        // Grün / Laub: Das typische geschwungene sächsische Wein-/Lindenblatt
        leaves: `
            <svg viewBox="0 0 100 100" class="quarxl-svg-suit svg-leaves" fill="currentColor">
                <!-- Blatt-Stiel -->
                <path d="M48,95 C49,85 49,75 49,65 C51,75 51,85 52,95 Z" fill="#14532d" />
                <!-- Blattspreite mit gezacktem Rand & Schwung -->
                <path d="M50,8 C58,22 75,22 84,36 C92,48 85,65 72,70 C62,74 54,68 50,78 C46,68 38,74 28,70 C15,65 8,48 16,36 C25,22 42,22 50,8 Z" fill="#15803d" />
                <!-- Blattrippen / Adern -->
                <path d="M50,15 L50,70" stroke="#86efac" stroke-width="3" stroke-linecap="round" fill="none" opacity="0.8" />
                <path d="M50,32 Q62,28 72,36" stroke="#86efac" stroke-width="2.2" stroke-linecap="round" fill="none" opacity="0.7" />
                <path d="M50,32 Q38,28 28,36" stroke="#86efac" stroke-width="2.2" stroke-linecap="round" fill="none" opacity="0.7" />
                <path d="M50,48 Q65,46 70,56" stroke="#86efac" stroke-width="2" stroke-linecap="round" fill="none" opacity="0.7" />
                <path d="M50,48 Q35,46 30,56" stroke="#86efac" stroke-width="2" stroke-linecap="round" fill="none" opacity="0.7" />
            </svg>
        `,

        // Herz / Rot: Vollmundiges bauchiges deutsches Spielkartenherz
        germanHearts: `
            <svg viewBox="0 0 100 100" class="quarxl-svg-suit svg-german-hearts" fill="currentColor">
                <path d="M50,88 C46,84 12,56 12,32 C12,17 24,9 37,9 C44,9 48,13 50,17 C52,13 56,9 63,9 C76,9 88,17 88,32 C88,56 54,84 50,88 Z" fill="#b91c1c" />
                <!-- Feiner Glanzverlauf -->
                <path d="M50,84 C48,80 18,54 18,34 C18,22 27,15 37,15 C42,15 46,18 48,22 C47,38 32,54 26,62 Z" fill="#f87171" opacity="0.35" />
            </svg>
        `,

        // Schellen: Runde Falkenschelle mit Kreuzschlitz und Aufhänge-Öse
        bells: `
            <svg viewBox="0 0 100 100" class="quarxl-svg-suit svg-bells" fill="currentColor">
                <!-- Aufhänge-Öse oben -->
                <circle cx="50" cy="14" r="9" fill="none" stroke="#b45309" stroke-width="4.5" />
                <!-- Schellen-Körper (Bernstein/Gold) -->
                <circle cx="50" cy="54" r="38" fill="#d97706" />
                <path d="M14,54 A36,36 0 0,0 86,54 Z" fill="#b45309" opacity="0.4" />
                <!-- Mittlerer Äquatorwulst -->
                <ellipse cx="50" cy="54" rx="38" ry="4" fill="#78350f" opacity="0.5" />
                <!-- Charakteristischer Kreuzschlitz mit Kugel-Löchern -->
                <circle cx="34" cy="65" r="4" fill="#451a03" />
                <circle cx="66" cy="65" r="4" fill="#451a03" />
                <path d="M34,65 Q50,70 66,65" stroke="#451a03" stroke-width="3.5" fill="none" stroke-linecap="round" />
                <path d="M50,68 L50,83" stroke="#451a03" stroke-width="3.5" stroke-linecap="round" />
                <circle cx="50" cy="85" r="3.5" fill="#451a03" />
                <!-- Glanzlicht -->
                <ellipse cx="38" cy="34" rx="8" ry="5" transform="rotate(-25 38 34)" fill="#fef3c7" opacity="0.6" />
            </svg>
        `,

        // Majestätische Zier-Ranke für Daus / Ass
        dausGarland: `
            <svg viewBox="0 0 100 45" class="quarxl-daus-garland" fill="currentColor">
                <path d="M10,22 Q28,8 46,24 Q48,20 50,15 Q52,20 54,24 Q72,8 90,22 Q76,34 50,26 Q24,34 10,22 Z" fill="#d97706" opacity="0.7" />
                <circle cx="50" cy="20" r="3" fill="#b45309" />
                <circle cx="30" cy="18" r="2" fill="#b45309" opacity="0.7" />
                <circle cx="70" cy="18" r="2" fill="#b45309" opacity="0.7" />
            </svg>
        `,

        // Königskrone
        crown: `
            <svg viewBox="0 0 100 65" class="quarxl-king-crown" fill="currentColor">
                <path d="M12,54 L88,54 L82,22 L62,38 L50,12 L38,38 L18,22 Z" fill="#eab308" stroke="#ca8a04" stroke-width="3" stroke-linejoin="round" />
                <circle cx="12" cy="18" r="4.5" fill="#fef08a" />
                <circle cx="50" cy="8" r="5" fill="#fef08a" />
                <circle cx="88" cy="18" r="4.5" fill="#fef08a" />
                <rect x="20" y="46" width="60" height="5" rx="2" fill="#b45309" opacity="0.4" />
            </svg>
        `,

        // --- FRANZÖSISCHES BLATT ---
        frenchClubs: `
            <svg viewBox="0 0 100 100" class="quarxl-svg-suit svg-french-clubs" fill="#0f172a">
                <circle cx="50" cy="30" r="18" />
                <circle cx="32" cy="56" r="18" />
                <circle cx="68" cy="56" r="18" />
                <circle cx="50" cy="50" r="12" />
                <path d="M46,55 L40,88 L60,88 L54,55 Z" />
            </svg>
        `,
        frenchSpades: `
            <svg viewBox="0 0 100 100" class="quarxl-svg-suit svg-french-spades" fill="#0f172a">
                <path d="M50,12 C44,28 16,50 16,68 C16,80 28,86 38,82 C44,79 48,74 50,68 C52,74 56,79 62,82 C72,86 84,80 84,68 C84,50 56,28 50,12 Z" />
                <path d="M46,65 L40,90 L60,90 L54,65 Z" />
            </svg>
        `,
        frenchHearts: `
            <svg viewBox="0 0 100 100" class="quarxl-svg-suit svg-french-hearts" fill="#dc2626">
                <path d="M50,86 C46,82 14,56 14,34 C14,19 26,11 38,11 C45,11 48,15 50,19 C52,15 55,11 62,11 C74,11 86,19 86,34 C86,56 54,82 50,86 Z" />
            </svg>
        `,
        frenchDiamonds: `
            <svg viewBox="0 0 100 100" class="quarxl-svg-suit svg-french-diamonds" fill="#dc2626">
                <path d="M50,10 L84,50 L50,90 L16,50 Z" />
            </svg>
        `
    };

    // -------------------------------------------------------------------------
    // 2. WEB AUDIO SYNTHESIZER (Kartenrascheln & Ablegen ohne Klick)
    // -------------------------------------------------------------------------
    const SoundFx = {
        ctx: null,
        noiseBuffer: null,

        init() {
            if (!this.ctx) {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (AudioCtx) {
                    this.ctx = new AudioCtx();
                }
            }
            if (this.ctx && this.ctx.state === 'suspended') {
                this.ctx.resume();
            }
            if (!this.noiseBuffer && this.ctx) {
                // 1 Sekunde weißes Rauschen als Synthese-Basis für authentische Papierreibung
                const bufferSize = Math.floor(this.ctx.sampleRate * 1.0);
                const buffer = this.ctx.createBuffer(1, bufferSize, this.ctx.sampleRate);
                const data = buffer.getChannelData(0);
                for (let i = 0; i < bufferSize; i++) {
                    data[i] = Math.random() * 2 - 1;
                }
                this.noiseBuffer = buffer;
            }
        },

        /**
         * Echtes Kartenrascheln & Gleiten ("fsscht / shhh-wip")
         * Bandpass-Filter-Frequenzsweep über weißes Rauschen (Skip-Bo Sound)
         */
        cardWhoosh() {
            this.init();
            if (!this.ctx || !this.noiseBuffer) return;
            const now = this.ctx.currentTime;

            const noise = this.ctx.createBufferSource();
            noise.buffer = this.noiseBuffer;

            // Frequenzsweep für das charakteristische Gleiten einer echten Spielkarte
            const filter = this.ctx.createBiquadFilter();
            filter.type = 'bandpass';
            filter.Q.setValueAtTime(2.2, now);
            filter.frequency.setValueAtTime(1700, now);
            filter.frequency.exponentialRampToValueAtTime(3200, now + 0.045);
            filter.frequency.exponentialRampToValueAtTime(1900, now + 0.12);

            const gain = this.ctx.createGain();
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.linearRampToValueAtTime(0.24, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.13);

            noise.connect(filter);
            filter.connect(gain);
            gain.connect(this.ctx.destination);

            noise.start(now);
            noise.stop(now + 0.14);
        },

        /**
         * Weiches Ablegen / sanfter Tapp auf Filztisch (kein synthetischer Klick)
         */
        cardPlace() {
            this.init();
            if (!this.ctx || !this.noiseBuffer) return;
            const now = this.ctx.currentTime;

            // 1. Kurzer, gedämpfter Karton-Touch
            const noise = this.ctx.createBufferSource();
            noise.buffer = this.noiseBuffer;

            const bandpass = this.ctx.createBiquadFilter();
            bandpass.type = 'bandpass';
            bandpass.frequency.setValueAtTime(2400, now);
            bandpass.Q.setValueAtTime(1.8, now);

            const noiseGain = this.ctx.createGain();
            noiseGain.gain.setValueAtTime(0.18, now);
            noiseGain.gain.exponentialRampToValueAtTime(0.001, now + 0.04);

            noise.connect(bandpass);
            bandpass.connect(noiseGain);
            noiseGain.connect(this.ctx.destination);

            noise.start(now);
            noise.stop(now + 0.045);

            // 2. Subtiler, tiefer Filztisch-Impuls (120Hz -> 50Hz)
            const osc = this.ctx.createOscillator();
            const oscGain = this.ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(120, now);
            osc.frequency.exponentialRampToValueAtTime(50, now + 0.035);

            oscGain.gain.setValueAtTime(0.1, now);
            oscGain.gain.exponentialRampToValueAtTime(0.001, now + 0.035);

            osc.connect(oscGain);
            oscGain.connect(this.ctx.destination);

            osc.start(now);
            osc.stop(now + 0.04);
        },

        /**
         * Mehrfaches schnelles Kartenrascheln beim Mischen/Geben
         */
        shuffle() {
            this.init();
            if (!this.ctx) return;
            for (let i = 0; i < 3; i++) {
                setTimeout(() => this.cardWhoosh(), i * 75);
            }
        },

        /**
         * Authentisches Klopfen auf den Holztisch ("Ich mache zu")
         */
        knock() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            [0, 0.09].forEach(delay => {
                const t = now + delay;
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(180, t);
                osc.frequency.exponentialRampToValueAtTime(70, t + 0.06);

                gain.gain.setValueAtTime(0.4, t);
                gain.gain.exponentialRampToValueAtTime(0.001, t + 0.065);

                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(t);
                osc.stop(t + 0.07);
            });
        }
    };

    // -------------------------------------------------------------------------
    // 3. DECK-VERWALTUNG & PERSISTENZ
    // -------------------------------------------------------------------------
    const STORAGE_KEY = 'quarxl_card_deck_preference';

    function getPreferredDeck() {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved === 'french' || saved === 'german') {
                return saved;
            }
        } catch (_) {}
        return 'german'; // Standard: Deutsches Blatt (Altenburger Art)
    }

    function setPreferredDeck(deck) {
        if (deck !== 'french' && deck !== 'german') deck = 'german';
        try {
            localStorage.setItem(STORAGE_KEY, deck);
        } catch (_) {}
        window.dispatchEvent(new CustomEvent('quarxl:deckchange', { detail: { deck } }));
        return deck;
    }

    /**
     * Erzeugt das interaktive Umschalt-Element in der Top-Bar
     */
    function renderDeckToggle(container, onToggleCallback) {
        if (!container) return;
        const currentDeck = getPreferredDeck();

        container.innerHTML = `
            <div class="quarxl-deck-toggle" role="group" aria-label="Kartendeck wählen">
                <button type="button" class="quarxl-deck-btn ${currentDeck === 'german' ? 'active' : ''}" data-deck="german" title="Altenburger Deutsches Blatt (Eichel, Grün, Herz, Schellen)">
                    <span>🌰</span> Deutsch
                </button>
                <button type="button" class="quarxl-deck-btn ${currentDeck === 'french' ? 'active' : ''}" data-deck="french" title="Französisches Blatt (Kreuz, Pik, Herz, Karo)">
                    <span>♣</span> Franz.
                </button>
            </div>
        `;

        const btns = container.querySelectorAll('.quarxl-deck-btn');
        btns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const targetDeck = btn.dataset.deck;
                if (targetDeck === getPreferredDeck()) return;
                
                setPreferredDeck(targetDeck);
                btns.forEach(b => b.classList.toggle('active', b.dataset.deck === targetDeck));
                SoundFx.cardWhoosh();
                
                if (typeof onToggleCallback === 'function') {
                    onToggleCallback(targetDeck);
                }
            });
        });
    }

    // -------------------------------------------------------------------------
    // 4. KARTEN-RENDERER (HTML-ERZEUGUNG)
    // -------------------------------------------------------------------------

    // Normalisierung von Farben und Rängen
    function normalizeCardData(card, options = {}) {
        let suit = (card.suit || card.color || '').toLowerCase();
        let rank = (card.rank || (card.value !== undefined ? String(card.value) : '')).toUpperCase();

        // Farb-Aliase auflösen
        if (['clubs', 'kreuz', 'acorns', 'eichel'].includes(suit)) {
            suit = 'clubs'; // intern Kreuz/Eichel
        } else if (['spades', 'pik', 'leaves', 'gruen', 'grün', 'laub'].includes(suit)) {
            suit = 'spades'; // intern Pik/Grün
        } else if (['hearts', 'herz', 'rot'].includes(suit)) {
            suit = 'hearts'; // intern Herz
        } else if (['diamonds', 'karo', 'bells', 'schellen'].includes(suit)) {
            suit = 'diamonds'; // intern Karo/Schellen
        }

        // Rang-Aliase
        if (['1', '11', 'A', 'ASS', 'DAUS'].includes(rank)) rank = 'A';
        else if (['K', 'KÖNIG', 'KOENIG', 'KING'].includes(rank)) rank = 'K';
        else if (['D', 'DAME', 'O', 'OBER', 'Q', 'QUEEN'].includes(rank)) {
            rank = (options.usePokerRanks || rank === 'Q' || rank === 'QUEEN') ? 'Q' : 'D';
        } else if (['B', 'BUBE', 'U', 'UNTER', 'J', 'JACK'].includes(rank)) {
            rank = (options.usePokerRanks || rank === 'J' || rank === 'JACK') ? 'J' : 'B';
        }

        return { suit, rank, id: card.id || `${suit}_${rank}` };
    }

    /**
     * Erzeugt den HTML-String einer Spielkarte nach aktuellem Deck
     */
    function renderCardHtml(cardData, options = {}) {
        if (!cardData) return '';
        const norm = normalizeCardData(cardData, options);
        const deck = options.deck || getPreferredDeck();
        const isGerman = (deck === 'german');

        const canPlay = Boolean(options.canPlay || options.selectable);
        const isSelected = Boolean(options.isSelected);
        const extraClass = options.extraClass || '';
        const zIndex = options.zIndex !== undefined ? `z-index: ${options.zIndex};` : '';

        // Deutsche Farben & Ränge
        let displaySuitKey = norm.suit;
        let displayRank = norm.rank;
        let suitCssClass = '';
        let miniSuitSvg = '';
        let centerHtml = '';

        if (isGerman) {
            // Mapping Französisch -> Deutsches Altenburger Blatt
            if (norm.suit === 'clubs') {
                displaySuitKey = 'acorns';
                suitCssClass = 'suit-acorns';
                miniSuitSvg = SVG.acorns;
            } else if (norm.suit === 'spades') {
                displaySuitKey = 'leaves';
                suitCssClass = 'suit-leaves';
                miniSuitSvg = SVG.leaves;
            } else if (norm.suit === 'hearts') {
                displaySuitKey = 'hearts';
                suitCssClass = 'suit-hearts';
                miniSuitSvg = SVG.germanHearts;
            } else {
                displaySuitKey = 'bells';
                suitCssClass = 'suit-bells';
                miniSuitSvg = SVG.bells;
            }

            // Ränge: Altenburger Ass = DAUS (kein Eckbuchstabe!), Bube = U, Dame = O
            let cornerRankText = '';
            let isUnter = false;
            let isOber = false;

            if (norm.rank === 'A') {
                // Altenburger Daus: 'A' im Eckindex für einheitliche und klare Lesbarkeit
                cornerRankText = 'A';
                centerHtml = `
                    <div class="quarxl-daus-composition">
                        <div class="quarxl-daus-symbol">${miniSuitSvg}</div>
                        ${SVG.dausGarland}
                        <div class="quarxl-daus-symbol" style="transform: rotate(180deg);">${miniSuitSvg}</div>
                    </div>
                `;
            } else if (norm.rank === 'K') {
                cornerRankText = 'K';
                centerHtml = `
                    <div class="quarxl-king-composition">
                        ${SVG.crown}
                        <div class="quarxl-king-symbol">${miniSuitSvg}</div>
                    </div>
                `;
            } else if (norm.rank === 'D' || norm.rank === 'Q') { // Ober
                cornerRankText = 'O';
                isOber = true;
                centerHtml = `
                    <div class="quarxl-figure-composition">
                        <div class="quarxl-figure-suit-icon">${miniSuitSvg}</div>
                        <div class="quarxl-figure-rank-huge">O</div>
                    </div>
                `;
            } else if (norm.rank === 'B' || norm.rank === 'J') { // Unter
                cornerRankText = 'U';
                isUnter = true;
                centerHtml = `
                    <div class="quarxl-figure-composition">
                        <div class="quarxl-figure-rank-huge">U</div>
                        <div class="quarxl-figure-suit-icon">${miniSuitSvg}</div>
                    </div>
                `;
            } else {
                // Zahlenkarten (7, 8, 9, 10)
                cornerRankText = norm.rank;
                centerHtml = `
                    <div class="quarxl-number-grid">
                        <div class="quarxl-number-value-big">${norm.rank}</div>
                        <div class="quarxl-number-suit-medium">${miniSuitSvg}</div>
                    </div>
                `;
            }

            const unterOberClass = isUnter ? 'is-unter' : (isOber ? 'is-ober' : '');
            const playableClass = canPlay ? 'is-playable' : '';
            const selectedClass = isSelected ? 'is-selected' : '';

            return `
                <div class="quarxl-card deck-german ${suitCssClass} ${unterOberClass} ${playableClass} ${selectedClass} ${extraClass}"
                     style="${zIndex}"
                     data-card-id="${norm.id}"
                     data-suit="${displaySuitKey}"
                     data-rank="${norm.rank}">
                    <div class="quarxl-card-corner corner-top">
                        ${cornerRankText ? `<span class="quarxl-card-rank">${cornerRankText}</span>` : ''}
                        <div class="quarxl-card-mini-suit">${miniSuitSvg}</div>
                    </div>
                    <div class="quarxl-card-center">
                        ${centerHtml}
                    </div>
                    <div class="quarxl-card-corner corner-bottom">
                        ${cornerRankText ? `<span class="quarxl-card-rank">${cornerRankText}</span>` : ''}
                        <div class="quarxl-card-mini-suit">${miniSuitSvg}</div>
                    </div>
                </div>
            `;
        }

        // --- FRANZÖSISCHES BLATT ---
        let suitSvg = '';
        if (norm.suit === 'clubs') {
            suitCssClass = 'suit-clubs';
            suitSvg = SVG.frenchClubs;
        } else if (norm.suit === 'spades') {
            suitCssClass = 'suit-spades';
            suitSvg = SVG.frenchSpades;
        } else if (norm.suit === 'hearts') {
            suitCssClass = 'suit-hearts';
            suitSvg = SVG.frenchHearts;
        } else {
            suitCssClass = 'suit-diamonds';
            suitSvg = SVG.frenchDiamonds;
        }

        let cornerRankText = norm.rank;
        if (norm.rank === 'D') cornerRankText = 'D'; // Dame
        else if (norm.rank === 'Q') cornerRankText = 'Q'; // Queen
        else if (norm.rank === 'B') cornerRankText = 'B'; // Bube
        else if (norm.rank === 'J') cornerRankText = 'J'; // Jack
        else if (norm.rank === 'A') cornerRankText = 'A'; // Ass

        if (['A', 'K', 'D', 'B', 'Q', 'J'].includes(norm.rank)) {
            centerHtml = `
                <div class="quarxl-figure-composition">
                    <div class="quarxl-figure-rank-huge">${cornerRankText}</div>
                    <div class="quarxl-figure-suit-icon">${suitSvg}</div>
                </div>
            `;
        } else {
            centerHtml = `
                <div class="quarxl-number-grid">
                    <div class="quarxl-number-value-big">${norm.rank}</div>
                    <div class="quarxl-number-suit-medium">${suitSvg}</div>
                </div>
            `;
        }

        const playableClass = canPlay ? 'is-playable' : '';
        const selectedClass = isSelected ? 'is-selected' : '';

        return `
            <div class="quarxl-card deck-french ${suitCssClass} ${playableClass} ${selectedClass} ${extraClass}"
                 style="${zIndex}"
                 data-card-id="${norm.id}"
                 data-suit="${norm.suit}"
                 data-rank="${norm.rank}">
                <div class="quarxl-card-corner corner-top">
                    <span class="quarxl-card-rank">${cornerRankText}</span>
                    <div class="quarxl-card-mini-suit">${suitSvg}</div>
                </div>
                <div class="quarxl-card-center">
                    ${centerHtml}
                </div>
                <div class="quarxl-card-corner corner-bottom">
                    <span class="quarxl-card-rank">${cornerRankText}</span>
                    <div class="quarxl-card-mini-suit">${suitSvg}</div>
                </div>
            </div>
        `;
    }

    /**
     * Erzeugt eine verdeckte Karte
     */
    function renderCardBackHtml(extraClass = '') {
        return `
            <div class="quarxl-card-back ${extraClass}">
                <div class="quarxl-card-back-ornament">🂠</div>
            </div>
        `;
    }

    // -------------------------------------------------------------------------
    // 5. FLUG-ANIMATION (Butterweiches Gleiten mit Kartenrascheln)
    // -------------------------------------------------------------------------
    function animateFlight(sourceEl, targetEl, cardDataOrHtml, options = {}) {
        return new Promise((resolve) => {
            if (!sourceEl || !targetEl) {
                resolve();
                return;
            }

            const startRect = sourceEl.getBoundingClientRect();
            const targetRect = targetEl.getBoundingClientRect();

            // Flug-Element erzeugen
            const flightEl = document.createElement('div');
            flightEl.className = 'quarxl-flying-card';

            if (typeof cardDataOrHtml === 'string' && cardDataOrHtml.startsWith('<')) {
                flightEl.innerHTML = cardDataOrHtml;
            } else if (typeof cardDataOrHtml === 'object') {
                flightEl.innerHTML = renderCardHtml(cardDataOrHtml, { deck: options.deck || getPreferredDeck() });
            } else {
                flightEl.innerHTML = renderCardBackHtml();
            }

            // Exakte Anfangs-Position
            const startW = startRect.width || 86;
            const startH = startRect.height || 128;
            flightEl.style.width = `${startW}px`;
            flightEl.style.height = `${startH}px`;
            flightEl.style.left = `${startRect.left}px`;
            flightEl.style.top = `${startRect.top}px`;
            flightEl.style.transform = 'scale(1) rotate(-3deg)';
            flightEl.style.opacity = '1';

            document.body.appendChild(flightEl);

            // Audio: Startendes Gleiten / Rascheln
            SoundFx.cardWhoosh();

            // Reflow erzwingen
            void flightEl.offsetHeight;

            // Ziel-Koordinaten & sanfter Flug
            const targetW = targetRect.width || startW;
            const targetH = targetRect.height || startH;
            const targetX = targetRect.left;
            const targetY = targetRect.top;

            flightEl.style.left = `${targetX}px`;
            flightEl.style.top = `${targetY}px`;
            flightEl.style.width = `${targetW}px`;
            flightEl.style.height = `${targetH}px`;
            flightEl.style.transform = 'scale(1.02) rotate(2deg)';

            const duration = options.duration || 440;

            setTimeout(() => {
                SoundFx.cardPlace();
                flightEl.remove();
                if (typeof options.onComplete === 'function') {
                    options.onComplete();
                }
                resolve();
            }, duration);
        });
    }

    // -------------------------------------------------------------------------
    // EXPORTIERTES MODUL
    // -------------------------------------------------------------------------
    return {
        SVG,
        SoundFx,
        getPreferredDeck,
        setPreferredDeck,
        renderDeckToggle,
        renderCardHtml,
        renderCardBackHtml,
        animateFlight
    };
}));
