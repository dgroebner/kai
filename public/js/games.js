/**
 * games.js - Interaktive Spiele- & Reise-Werkzeuge für Kai
 *
 * Beinhaltet:
 * 1. Münzwurf mit CSS-Flip-Animation, Zähler & Haptik
 * 2. Würfelbecher (1 bis 6 Würfel) mit Augensumme & Haptik
 * 3. Stadt-Land-Fluss Selektor mit Ziehen ohne Zurücklegen, sessionStorage & Transparenz-Raster
 *
 * Befolgt CSP (keine Inline-Handler, reine Event-Delegation am Hauptcontainer).
 */
(function () {
    'use strict';

    // =========================================================================
    // HILFSFUNKTIONEN: Haptisches Feedback (Web Vibration API)
    // =========================================================================
    function triggerVibration(pattern = [30]) {
        if (typeof navigator !== 'undefined' && 'vibrate' in navigator) {
            try {
                navigator.vibrate(pattern);
            } catch (_) {
                // Ignore silent errors on unsupported devices/permissions
            }
        }
    }

    // =========================================================================
    // 1. MÜNZWURF (Entscheidungs-Tool)
    // =========================================================================
    const CoinTool = {
        isFlipping: false,
        headsCount: 0,
        tailsCount: 0,
        currentRotation: 0,

        init() {
            this.coinEl = document.getElementById('games-coin');
            this.resultLabelEl = document.querySelector('.js-coin-result-label');
            this.headsValEl = document.getElementById('coin-stat-heads');
            this.tailsValEl = document.getElementById('coin-stat-tails');
            this.totalValEl = document.getElementById('coin-stat-total');

            // Aus sessionStorage wiederherstellen falls vorhanden
            try {
                const saved = sessionStorage.getItem('kai_games_coin_stats');
                if (saved) {
                    const data = JSON.parse(saved);
                    this.headsCount = Number(data.heads) || 0;
                    this.tailsCount = Number(data.tails) || 0;
                    this.updateStatsUI();
                }
            } catch (_) {}
        },

        flip() {
            if (this.isFlipping || !this.coinEl) return;

            this.isFlipping = true;
            triggerVibration([20]); // Leichtes Anstoß-Haptik-Feedback

            if (this.resultLabelEl) {
                this.resultLabelEl.textContent = 'Münze dreht sich...';
                this.resultLabelEl.classList.remove('is-result');
            }

            // Zufallsergebnis ermitteln: 0 = Kopf (Front), 1 = Zahl (Back)
            const isHeads = Math.random() < 0.5;

            // Mindestens 5 volle Drehungen (5 * 360 = 1800 Grad)
            const extraFullTurns = (5 + Math.floor(Math.random() * 3)) * 360;
            // Falls Kopf: gerade Anzahl an 180er Drehungen (z.B. 0 mod 360)
            // Falls Zahl: ungerade Anzahl (z.B. 180 mod 360)
            const targetFaceAngle = isHeads ? 0 : 180;
            
            // Fortlaufende Rotation aufbauen, damit sie sich immer vorwärts dreht
            const currentMod = this.currentRotation % 360;
            let delta = targetFaceAngle - currentMod;
            if (delta < 0) delta += 360;
            this.currentRotation += extraFullTurns + delta;

            this.coinEl.style.transition = 'transform 1.2s cubic-bezier(0.15, 0.9, 0.3, 1)';
            this.coinEl.style.transform = `rotateY(${this.currentRotation}deg)`;

            setTimeout(() => {
                this.isFlipping = false;
                triggerVibration([40]); // Lande-Impuls

                if (isHeads) {
                    this.headsCount++;
                    if (this.resultLabelEl) {
                        this.resultLabelEl.textContent = '👑 KOPF';
                        this.resultLabelEl.classList.add('is-result');
                    }
                } else {
                    this.tailsCount++;
                    if (this.resultLabelEl) {
                        this.resultLabelEl.textContent = '🔢 ZAHL';
                        this.resultLabelEl.classList.add('is-result');
                    }
                }

                this.updateStatsUI();
                this.saveStats();
            }, 1250);
        },

        updateStatsUI() {
            if (this.headsValEl) this.headsValEl.textContent = String(this.headsCount);
            if (this.tailsValEl) this.tailsValEl.textContent = String(this.tailsCount);
            if (this.totalValEl) this.totalValEl.textContent = String(this.headsCount + this.tailsCount);
        },

        saveStats() {
            try {
                sessionStorage.setItem('kai_games_coin_stats', JSON.stringify({
                    heads: this.headsCount,
                    tails: this.tailsCount
                }));
            } catch (_) {}
        },

        resetStats() {
            this.headsCount = 0;
            this.tailsCount = 0;
            this.updateStatsUI();
            this.saveStats();
            triggerVibration([15]);
            if (this.resultLabelEl) {
                this.resultLabelEl.textContent = 'Tippe auf die Münze oder den Button';
                this.resultLabelEl.classList.remove('is-result');
            }
        }
    };

    // =========================================================================
    // 2. UNIVERSELLER WÜRFELBECHER (1 bis 6 Würfel)
    // =========================================================================
    const DiceTool = {
        activeDiceCount: 1,
        isRolling: false,
        diceValues: [1],
        lockedIndices: new Set(),

        // SVG-Würfelaugen oder DOM-Pips für 1 bis 6
        pipsMap: {
            1: ['pip-center'],
            2: ['pip-top-left', 'pip-bottom-right'],
            3: ['pip-top-left', 'pip-center', 'pip-bottom-right'],
            4: ['pip-top-left', 'pip-top-right', 'pip-bottom-left', 'pip-bottom-right'],
            5: ['pip-top-left', 'pip-top-right', 'pip-center', 'pip-bottom-left', 'pip-bottom-right'],
            6: ['pip-top-left', 'pip-top-right', 'pip-mid-left', 'pip-mid-right', 'pip-bottom-left', 'pip-bottom-right']
        },

        init() {
            this.gridEl = document.getElementById('games-dice-grid');
            this.sumDisplayEl = document.getElementById('games-dice-sum-display');
            this.sumValEl = document.getElementById('games-dice-sum-val');
            this.unfixBtn = document.getElementById('btn-dice-unfix-all');

            // Zuletzt gewählte Anzahl wiederherstellen falls vorhanden
            try {
                const savedCount = Number(sessionStorage.getItem('kai_games_dice_count'));
                if (savedCount >= 1 && savedCount <= 6) {
                    this.activeDiceCount = savedCount;
                }
            } catch (_) {}

            this.updateSelectorButtonsUI();
            this.roll(false); // Initiale Würfel anzeigen (ohne Animation)
        },

        setDiceCount(count) {
            if (count < 1 || count > 6) return;
            this.activeDiceCount = count;
            this.lockedIndices.clear(); // Beim Ändern der Würfelanzahl Fixierungen zurücksetzen
            try {
                sessionStorage.setItem('kai_games_dice_count', String(count));
            } catch (_) {}
            this.updateSelectorButtonsUI();
            this.roll(false);
            triggerVibration([20]);
        },

        updateSelectorButtonsUI() {
            document.querySelectorAll('.js-dice-count-btn').forEach(btn => {
                const c = Number(btn.dataset.count);
                if (c === this.activeDiceCount) {
                    btn.classList.add('active');
                    btn.classList.remove('btn-outline');
                } else {
                    btn.classList.remove('active');
                    btn.classList.add('btn-outline');
                }
            });
        },

        toggleLock(index) {
            if (this.isRolling) return;
            if (index < 0 || index >= this.activeDiceCount) return;

            if (this.lockedIndices.has(index)) {
                this.lockedIndices.delete(index);
                triggerVibration([15]);
            } else {
                this.lockedIndices.add(index);
                triggerVibration([25]);
            }

            this.renderDice();
            this.updateUnfixButton();
        },

        unfixAll() {
            if (this.isRolling || this.lockedIndices.size === 0) return;
            this.lockedIndices.clear();
            this.renderDice();
            this.updateUnfixButton();
            triggerVibration([20]);
        },

        updateUnfixButton() {
            if (this.unfixBtn) {
                if (this.lockedIndices.size > 0) {
                    this.unfixBtn.classList.remove('hidden');
                } else {
                    this.unfixBtn.classList.add('hidden');
                }
            }
        },

        roll(animate = true) {
            if (this.isRolling && animate) return;

            // Wenn alle Würfel fixiert sind, gibt es nichts zu würfeln
            if (animate && this.lockedIndices.size >= this.activeDiceCount) {
                triggerVibration([20, 50, 20]);
                return;
            }

            if (!animate) {
                this.diceValues = [];
                for (let i = 0; i < this.activeDiceCount; i++) {
                    this.diceValues.push(Math.floor(Math.random() * 6) + 1);
                }
                this.renderDice();
                this.updateSum();
                this.updateUnfixButton();
                return;
            }

            this.isRolling = true;
            triggerVibration([30, 40, 30]); // Rollendes Haptik-Muster

            // Würfel-Elemente wackeln lassen
            if (this.gridEl) {
                this.gridEl.classList.add('is-rolling');
            }

            // Schnelles Zufalls-Flimmern während des Rollens nur für NICHT fixierte Würfel
            let rollTicks = 0;
            const maxTicks = 6;
            const interval = setInterval(() => {
                rollTicks++;
                for (let i = 0; i < this.activeDiceCount; i++) {
                    if (!this.lockedIndices.has(i)) {
                        this.diceValues[i] = Math.floor(Math.random() * 6) + 1;
                    }
                }
                this.renderDice();
                if (rollTicks >= maxTicks) {
                    clearInterval(interval);
                    this.finishRoll();
                }
            }, 80);
        },

        finishRoll() {
            for (let i = 0; i < this.activeDiceCount; i++) {
                if (!this.lockedIndices.has(i)) {
                    this.diceValues[i] = Math.floor(Math.random() * 6) + 1;
                }
            }
            this.renderDice();
            this.updateSum();
            this.updateUnfixButton();

            if (this.gridEl) {
                this.gridEl.classList.remove('is-rolling');
            }

            this.isRolling = false;
            triggerVibration([50]); // Schlusseinschlag
        },

        renderDice() {
            if (!this.gridEl) return;
            this.gridEl.innerHTML = '';

            this.diceValues.forEach((val, idx) => {
                const isLocked = this.lockedIndices.has(idx);
                const die = document.createElement('div');
                die.className = `games-die die-val-${val} js-die-item ${isLocked ? 'is-locked' : ''}`;
                die.dataset.index = String(idx);
                die.setAttribute('role', 'button');
                die.setAttribute('tabindex', '0');
                die.setAttribute('aria-label', `Würfel ${idx + 1}: ${val} Augen${isLocked ? ' (fixiert)' : ''}`);
                die.setAttribute('aria-pressed', isLocked ? 'true' : 'false');

                // Badge / Icon für Fixierung
                if (isLocked) {
                    const lockBadge = document.createElement('span');
                    lockBadge.className = 'die-lock-badge';
                    lockBadge.textContent = '🔒';
                    lockBadge.setAttribute('aria-hidden', 'true');
                    die.appendChild(lockBadge);
                }

                const pips = this.pipsMap[val] || [];
                pips.forEach(posClass => {
                    const pip = document.createElement('span');
                    pip.className = `die-pip ${posClass}`;
                    die.appendChild(pip);
                });

                this.gridEl.appendChild(die);
            });
        },

        updateSum() {
            const sum = this.diceValues.reduce((a, b) => a + b, 0);
            if (this.sumValEl) {
                this.sumValEl.textContent = String(sum);
            }
            if (this.sumDisplayEl) {
                // Bei 1 Würfel kann die Summe ausgeblendet oder kompakt angezeigt werden
                this.sumDisplayEl.style.display = this.activeDiceCount > 1 ? 'block' : 'none';
            }
        }
    };

    // =========================================================================
    // 3. STADT-LAND-FLUSS BUCHSTABENSELEKTOR (mit Gedächtnis)
    // =========================================================================
    const CityCountryTool = {
        STORAGE_KEY: 'kai_games_city_country_state_v1',
        SUPPRESS_CONFIRM_KEY: 'kai_games_suppress_reset_confirm',
        FULL_ALPHABET: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split(''),
        RARE_LETTERS: ['Q', 'X', 'Y'],

        excludeRare: false,
        suppressConfirm: false,
        manualExcludedLetters: [],
        availableLetters: [],
        drawnLetters: [],
        currentLetter: null,
        isDrawing: false,

        init() {
            this.heroEl = document.getElementById('games-current-letter');
            this.statusEl = document.getElementById('games-letter-status');
            this.gridEl = document.getElementById('games-alphabet-grid');
            this.counterEl = document.getElementById('games-alphabet-counter');
            this.chkExcludeRare = document.getElementById('chk-exclude-rare');
            this.btnNext = document.getElementById('btn-letter-next');
            this.modalEl = document.getElementById('games-confirm-modal');
            this.chkSuppressConfirm = document.getElementById('chk-games-suppress-confirm');

            // Prüfen, ob Bestätigungen für diese Session unterdrückt werden sollen
            try {
                this.suppressConfirm = sessionStorage.getItem(this.SUPPRESS_CONFIRM_KEY) === '1';
                if (this.chkSuppressConfirm) {
                    this.chkSuppressConfirm.checked = this.suppressConfirm;
                }
            } catch (_) {}

            // Aus sessionStorage laden oder Standard initialisieren
            this.loadState();
            this.render();
        },

        getActivePool() {
            let pool = [...this.FULL_ALPHABET];
            if (this.excludeRare) {
                pool = pool.filter(l => !this.RARE_LETTERS.includes(l));
            }
            if (this.manualExcludedLetters && this.manualExcludedLetters.length > 0) {
                pool = pool.filter(l => !this.manualExcludedLetters.includes(l));
            }
            return pool;
        },

        loadState() {
            try {
                const saved = sessionStorage.getItem(this.STORAGE_KEY);
                if (saved) {
                    const data = JSON.parse(saved);
                    this.excludeRare = Boolean(data.excludeRare);
                    this.manualExcludedLetters = Array.isArray(data.manualExcludedLetters) ? data.manualExcludedLetters : [];
                    this.drawnLetters = Array.isArray(data.drawnLetters) ? data.drawnLetters : [];
                    this.currentLetter = data.currentLetter || null;
                    
                    const pool = this.getActivePool();
                    this.availableLetters = pool.filter(l => !this.drawnLetters.includes(l));

                    if (this.chkExcludeRare) {
                        this.chkExcludeRare.checked = this.excludeRare;
                    }
                    return;
                }
            } catch (_) {}

            // Standardinitialisierung
            this.excludeRare = false;
            this.manualExcludedLetters = [];
            this.resetRound(false);
        },

        saveState() {
            try {
                const data = {
                    excludeRare: this.excludeRare,
                    manualExcludedLetters: this.manualExcludedLetters,
                    drawnLetters: this.drawnLetters,
                    currentLetter: this.currentLetter
                };
                sessionStorage.setItem(this.STORAGE_KEY, JSON.stringify(data));
            } catch (_) {}
        },

        setExcludeRare(exclude) {
            this.excludeRare = exclude;
            // Wenn der Schalter mitten in der Runde geändert wird:
            // Verbleibenden Pool mit neuer Regel synchronisieren
            const pool = this.getActivePool();
            this.drawnLetters = this.drawnLetters.filter(l => pool.includes(l));
            this.availableLetters = pool.filter(l => !this.drawnLetters.includes(l));
            if (this.currentLetter && !pool.includes(this.currentLetter)) {
                this.currentLetter = null;
            }
            this.saveState();
            this.render();
            triggerVibration([15]);
        },

        toggleLetterExclusion(letter) {
            if (!this.FULL_ALPHABET.includes(letter)) return;

            // Wenn der Buchstabe bereits durch "Schwierige Buchstaben" ausgeschlossen ist,
            // Checkbox umschalten oder Hinweis
            const isRareExcluded = this.excludeRare && this.RARE_LETTERS.includes(letter);
            if (isRareExcluded) {
                // Schalter ausschalten und nur diesen Buchstaben wieder erlauben
                this.excludeRare = false;
                if (this.chkExcludeRare) this.chkExcludeRare.checked = false;
                // Die anderen seltenen Buchstaben als manuell ausgeschlossen merken
                this.RARE_LETTERS.forEach(rl => {
                    if (rl !== letter && !this.manualExcludedLetters.includes(rl)) {
                        this.manualExcludedLetters.push(rl);
                    }
                });
            } else {
                const idx = this.manualExcludedLetters.indexOf(letter);
                if (idx !== -1) {
                    // Wieder einschließen
                    this.manualExcludedLetters.splice(idx, 1);
                    triggerVibration([15]);
                } else {
                    // Manuell ausschließen
                    this.manualExcludedLetters.push(letter);
                    triggerVibration([25]);
                }
            }

            // Pool synchronisieren
            const pool = this.getActivePool();
            this.drawnLetters = this.drawnLetters.filter(l => pool.includes(l));
            this.availableLetters = pool.filter(l => !this.drawnLetters.includes(l));
            if (this.currentLetter && !pool.includes(this.currentLetter)) {
                this.currentLetter = null;
            }

            this.saveState();
            this.render();
        },

        drawNext() {
            if (this.isDrawing) return;

            if (this.availableLetters.length === 0) {
                triggerVibration([50, 100, 50]);
                if (this.statusEl) {
                    this.statusEl.textContent = '🎉 Runde beendet! Alle Buchstaben wurden gespielt.';
                }
                return;
            }

            this.isDrawing = true;
            triggerVibration([25]);

            if (this.heroEl) {
                this.heroEl.classList.add('is-animating');
            }

            // Schnelles Durchfliegen von Buchstaben für optischen Shuffle-Effekt
            let ticks = 0;
            const maxTicks = 8;
            const interval = setInterval(() => {
                ticks++;
                const randomIdx = Math.floor(Math.random() * this.availableLetters.length);
                if (this.heroEl) {
                    this.heroEl.textContent = this.availableLetters[randomIdx];
                }

                if (ticks >= maxTicks) {
                    clearInterval(interval);
                    this.finishDraw();
                }
            }, 60);
        },

        finishDraw() {
            // Zufälligen Buchstaben ohne Zurücklegen ziehen
            const randomIdx = Math.floor(Math.random() * this.availableLetters.length);
            const drawn = this.availableLetters.splice(randomIdx, 1)[0];

            this.currentLetter = drawn;
            this.drawnLetters.push(drawn);
            this.isDrawing = false;

            if (this.heroEl) {
                this.heroEl.classList.remove('is-animating');
                this.heroEl.textContent = drawn;
            }

            this.saveState();
            this.render();
            triggerVibration([40]);
        },

        resetRound(confirmPrompt = true) {
            // Wenn keine Buchstaben gezogen wurden oder der Nutzer Bestätigungen für diese Session deaktiviert hat:
            if (!confirmPrompt || this.drawnLetters.length === 0 || this.suppressConfirm) {
                this.performResetRound();
                return;
            }

            // Statt blockierendem window.confirm ein hübsches, freundliches In-App-Modal anzeigen
            this.openConfirmModal();
        },

        openConfirmModal() {
            if (!this.modalEl) {
                this.performResetRound();
                return;
            }

            if (this.chkSuppressConfirm) {
                this.chkSuppressConfirm.checked = this.suppressConfirm;
            }
            this.modalEl.classList.remove('hidden');
            triggerVibration([20]);
        },

        closeConfirmModal() {
            if (this.modalEl) {
                this.modalEl.classList.add('hidden');
            }
        },

        confirmResetOk() {
            if (this.chkSuppressConfirm && this.chkSuppressConfirm.checked) {
                this.suppressConfirm = true;
                try {
                    sessionStorage.setItem(this.SUPPRESS_CONFIRM_KEY, '1');
                } catch (_) {}
            }
            this.closeConfirmModal();
            this.performResetRound();
        },

        performResetRound() {
            const pool = this.getActivePool();
            this.availableLetters = [...pool];
            this.drawnLetters = [];
            this.currentLetter = null;

            this.saveState();
            this.render();
            triggerVibration([20]);
        },

        render() {
            // Hero Letter
            if (this.heroEl) {
                this.heroEl.textContent = this.currentLetter ? this.currentLetter : '?';
            }

            // Statuszeile
            if (this.statusEl) {
                if (this.currentLetter) {
                    const remaining = this.availableLetters.length;
                    if (remaining === 0) {
                        this.statusEl.textContent = `Buchstabe „${this.currentLetter}“ gezogen. Alle Buchstaben aufgebraucht!`;
                    } else {
                        this.statusEl.textContent = `Buchstabe „${this.currentLetter}“ gezogen. Noch ${remaining} ${remaining === 1 ? 'Buchstabe' : 'Buchstaben'} übrig.`;
                    }
                } else {
                    this.statusEl.textContent = 'Klicke auf „Nächster Buchstabe“, um die Runde zu starten.';
                }
            }

            // Counter
            if (this.counterEl) {
                const total = this.getActivePool().length;
                const remaining = this.availableLetters.length;
                this.counterEl.textContent = `${remaining} von ${total} verbleibend`;
            }

            // Button Disabling wenn Pool leer
            if (this.btnNext) {
                this.btnNext.disabled = (this.availableLetters.length === 0);
                if (this.availableLetters.length === 0) {
                    this.btnNext.classList.add('disabled');
                } else {
                    this.btnNext.classList.remove('disabled');
                }
            }

            // 26er-Alphabet-Raster Transparenz
            if (this.gridEl) {
                this.gridEl.innerHTML = '';
                const pool = this.getActivePool();

                this.FULL_ALPHABET.forEach(letter => {
                    const isDrawn = this.drawnLetters.includes(letter);
                    const isCurrent = (letter === this.currentLetter);
                    const isExcluded = !pool.includes(letter);

                    const cell = document.createElement('div');
                    cell.className = 'games-alphabet-cell js-alphabet-cell';
                    cell.textContent = letter;
                    cell.dataset.letter = letter;
                    cell.setAttribute('role', 'button');
                    cell.setAttribute('tabindex', '0');

                    if (isExcluded) {
                        cell.classList.add('is-excluded');
                        cell.setAttribute('title', `Buchstabe „${letter}“ (ausgeschlossen — tippen zum Aktivieren)`);
                        cell.setAttribute('aria-pressed', 'false');
                    } else if (isDrawn) {
                        cell.classList.add('is-used');
                        if (isCurrent) {
                            cell.classList.add('is-current');
                            cell.setAttribute('title', `Buchstabe „${letter}“ (aktuell gezogen)`);
                        } else {
                            cell.setAttribute('title', `Buchstabe „${letter}“ (in dieser Runde bereits gezogen)`);
                        }
                        cell.setAttribute('aria-pressed', 'true');
                    } else {
                        cell.classList.add('is-available');
                        cell.setAttribute('title', `Buchstabe „${letter}“ (verfügbar — tippen zum Ausschließen)`);
                        cell.setAttribute('aria-pressed', 'false');
                    }

                    this.gridEl.appendChild(cell);
                });
            }
        }
    };

    // =========================================================================
    // ZENTRALE EVENT-DELEGATION (CSP-konform)
    // =========================================================================
    document.addEventListener('DOMContentLoaded', () => {
        // Module initialisieren
        CoinTool.init();
        DiceTool.init();
        CityCountryTool.init();

        const mainContainer = document.querySelector('.games-container');
        if (!mainContainer) return;

        // Klick-Delegation
        mainContainer.addEventListener('click', (e) => {
            // 1. Tab-Navigation
            const tabBtn = e.target.closest('.js-games-tab-btn');
            if (tabBtn) {
                const targetTool = tabBtn.dataset.tool;
                if (!targetTool) return;

                // Buttons anpassen
                document.querySelectorAll('.js-games-tab-btn').forEach(btn => {
                    if (btn === tabBtn) {
                        btn.classList.remove('btn-outline');
                    } else {
                        btn.classList.add('btn-outline');
                    }
                });

                // Panes umschalten
                document.querySelectorAll('.games-tool-pane').forEach(pane => {
                    pane.classList.add('hidden');
                });
                const targetPane = document.getElementById(`tool-pane-${targetTool}`);
                if (targetPane) {
                    targetPane.classList.remove('hidden');
                }
                triggerVibration([15]);
                return;
            }

            // 2. Münzwurf Aktionen
            if (e.target.closest('.js-coin-flip-btn') || e.target.closest('.js-coin-flip-trigger')) {
                CoinTool.flip();
                return;
            }
            if (e.target.closest('.js-coin-reset-stats')) {
                CoinTool.resetStats();
                return;
            }

            // 3. Würfel Aktionen
            const dieItem = e.target.closest('.js-die-item');
            if (dieItem) {
                const idx = Number(dieItem.dataset.index);
                DiceTool.toggleLock(idx);
                return;
            }
            if (e.target.closest('.js-dice-unfix-all-btn')) {
                DiceTool.unfixAll();
                return;
            }
            const diceCountBtn = e.target.closest('.js-dice-count-btn');
            if (diceCountBtn) {
                const count = Number(diceCountBtn.dataset.count);
                DiceTool.setDiceCount(count);
                return;
            }
            if (e.target.closest('.js-dice-roll-btn') || e.target.closest('.js-dice-roll-trigger')) {
                DiceTool.roll(true);
                return;
            }

            // 4. Stadt-Land-Fluss Aktionen
            const alphabetCell = e.target.closest('.js-alphabet-cell');
            if (alphabetCell) {
                const letter = alphabetCell.dataset.letter;
                if (letter) {
                    CityCountryTool.toggleLetterExclusion(letter);
                }
                return;
            }
            if (e.target.closest('.js-letter-next-btn')) {
                CityCountryTool.drawNext();
                return;
            }
            if (e.target.closest('.js-letter-reset-btn')) {
                CityCountryTool.resetRound(true);
                return;
            }

            // 5. Modal Bestätigung
            if (e.target.closest('.js-games-confirm-ok')) {
                CityCountryTool.confirmResetOk();
                return;
            }
            if (e.target.closest('.js-games-confirm-cancel') || e.target.classList.contains('games-modal-overlay')) {
                CityCountryTool.closeConfirmModal();
                return;
            }
        });

        // Change-Events (z. B. Checkbox schwierige Buchstaben oder Unterdrückungs-Checkbox)
        mainContainer.addEventListener('change', (e) => {
            if (e.target && e.target.id === 'chk-exclude-rare') {
                CityCountryTool.setExcludeRare(e.target.checked);
            }
        });

        // Tastaturunterstützung (Enter / Leertaste für Münze, Würfel, Alphabet-Zellen, Escape für Modal)
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const modal = document.getElementById('games-confirm-modal');
                if (modal && !modal.classList.contains('hidden')) {
                    CityCountryTool.closeConfirmModal();
                    return;
                }
            }

            if (e.key === ' ' || e.key === 'Enter') {
                if (e.target && e.target.classList.contains('js-coin-flip-trigger')) {
                    e.preventDefault();
                    CoinTool.flip();
                } else if (e.target && e.target.classList.contains('js-die-item')) {
                    e.preventDefault();
                    const idx = Number(e.target.dataset.index);
                    DiceTool.toggleLock(idx);
                } else if (e.target && e.target.classList.contains('js-alphabet-cell')) {
                    e.preventDefault();
                    const letter = e.target.dataset.letter;
                    if (letter) {
                        CityCountryTool.toggleLetterExclusion(letter);
                    }
                } else if (e.target && e.target.classList.contains('js-dice-roll-trigger')) {
                    e.preventDefault();
                    DiceTool.roll(true);
                }
            }
        });
    });
})();
