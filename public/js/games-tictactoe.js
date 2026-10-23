/**
 * games-tictactoe.js - Tic-Tac-Toe & Tant Fant (3-Marker-Schiebespiel) für Kai
 *
 * Beinhaltet:
 * 1. Tant Fant (traditionelles indisches 3-Marker-Schiebespiel mit vorgegebener Startaufstellung)
 * 2. Klassisches Tic-Tac-Toe (Drei gewinnt auf leerem 3x3 Gitter)
 * 3. Einzelspieler gegen konfigurierbare KI (Leicht, Taktisch, Unschlagbar via Minimax & Alpha-Beta-Pruning)
 * 4. Lokaler 2-Spieler-Modus (Pass & Play)
 * 5. SVG-Netz-Visualisierung, dynamische Gewinnlinien-Animation & haptisches Feedback
 *
 * Befolgt CSP (keine Inline-Handler, reine Event-Delegation).
 */
(function () {
    'use strict';

    // =========================================================================
    // HILFSFUNKTIONEN & KONFIGURATION
    // =========================================================================

    function triggerVibration(pattern) {
        if (typeof navigator !== 'undefined' && 'vibrate' in navigator) {
            try {
                navigator.vibrate(pattern || [25]);
            } catch (_) {}
        }
    }

    // Tant Fant Graphen-Adjazenz (3x3 Raster mit Diagonalen)
    // 0 1 2
    // 3 4 5
    // 6 7 8
    const ADJACENCY = {
        0: [1, 3, 4],
        1: [0, 2, 4],
        2: [1, 4, 5],
        3: [0, 4, 6],
        4: [0, 1, 2, 3, 5, 6, 7, 8], // Zentrum mit allen 8 Knoten verbunden
        5: [2, 4, 8],
        6: [3, 4, 7],
        7: [4, 6, 8],
        8: [4, 5, 7]
    };

    // Alle möglichen 3er-Reihen
    const WIN_LINES = [
        [0, 1, 2], [3, 4, 5], [6, 7, 8], // Zeilen
        [0, 3, 6], [1, 4, 7], [2, 5, 8], // Spalten
        [0, 4, 8], [2, 4, 6]             // Diagonalen
    ];

    // SVG-Koordinaten der 9 Knotenpunkte (in der 300x300 viewBox)
    const NODE_COORDS = [
        { x: 50, y: 50 },   { x: 150, y: 50 },  { x: 250, y: 50 },
        { x: 50, y: 150 },  { x: 150, y: 150 }, { x: 250, y: 150 },
        { x: 50, y: 250 },  { x: 150, y: 250 }, { x: 250, y: 250 }
    ];

    // =========================================================================
    // SPIEL-ENGINE & STATE
    // =========================================================================
    const TicTacToeTool = {
        variant: 'tantfant',      // 'tantfant' | 'classic'
        mode: 'ai',               // 'ai' | 'pvp'
        difficulty: 'medium',     // 'easy' | 'medium' | 'hard'

        board: Array(9).fill(null),
        turn: 'X',                // 'X' (Spieler 1) | 'O' (Spieler 2 / KI)
        selectedPiece: null,      // Index des ausgewählten Steins (für Tant Fant)
        isGameOver: false,
        isAiThinking: false,
        moveCount: 0,
        positionHistory: [],      // Zur Erkennung von 3-facher Stellungswiederholung

        scores: {
            p1: 0,
            p2: 0,
            draw: 0
        },

        player1Name: 'Spieler 1',
        player2Name: 'Computer',

        // DOM-Referenzen
        containerEl: null,
        boardContainerEl: null,
        cellsGridEl: null,
        instructionEl: null,
        resultBannerEl: null,
        resultTextEl: null,
        diffBarEl: null,
        winSvgEl: null,
        winLineEl: null,
        drawBtnEl: null,
        scoreP1El: null,
        scoreP2El: null,
        scoreDrawEl: null,
        labelP1El: null,
        labelP2El: null,
        badgeP1El: null,
        badgeP2El: null,
        cardP1El: null,
        cardP2El: null,
        cardDrawEl: null,

        // =====================================================================
        // INITIALISIERUNG
        // =====================================================================
        init() {
            this.containerEl = document.getElementById('tool-pane-tictactoe');
            if (!this.containerEl) return;

            this.boardContainerEl = document.getElementById('ttt-board-container');
            this.cellsGridEl = document.getElementById('ttt-cells-grid');
            this.instructionEl = document.getElementById('ttt-instruction-bar');
            this.resultBannerEl = document.getElementById('ttt-result-banner');
            this.resultTextEl = document.getElementById('ttt-result-text');
            this.diffBarEl = document.getElementById('ttt-difficulty-bar');
            this.winSvgEl = document.getElementById('ttt-win-svg');
            this.winLineEl = document.getElementById('ttt-win-line');
            this.drawBtnEl = document.getElementById('btn-ttt-draw');

            this.scoreP1El = document.getElementById('ttt-score-p1');
            this.scoreP2El = document.getElementById('ttt-score-p2');
            this.scoreDrawEl = document.getElementById('ttt-score-draw');
            this.labelP1El = document.getElementById('ttt-label-p1');
            this.labelP2El = document.getElementById('ttt-label-p2');
            this.badgeP1El = document.getElementById('ttt-turn-badge-p1');
            this.badgeP2El = document.getElementById('ttt-turn-badge-p2');
            this.cardP1El = document.getElementById('ttt-card-p1');
            this.cardP2El = document.getElementById('ttt-card-p2');
            this.cardDrawEl = document.getElementById('ttt-card-draw');

            // Spielername ermitteln
            const metaUser = document.querySelector('meta[name="user-first-name"]');
            if (metaUser && metaUser.content && metaUser.content.trim() !== '') {
                this.player1Name = metaUser.content.trim();
            } else {
                this.player1Name = 'Du';
            }

            // Gitterzellen erzeugen
            this.renderEmptyGrid();

            // Punktestände aus Storage laden
            this.loadScores();

            // Erstes Spiel starten
            this.startNewGame(false);

            // Event-Delegation am Hauptcontainer registrieren
            this.bindEvents();
        },

        renderEmptyGrid() {
            if (!this.cellsGridEl) return;
            let html = '';
            for (let i = 0; i < 9; i++) {
                html += `<button type="button" class="ttt-cell js-ttt-cell" data-index="${i}" aria-label="Feld ${i + 1}" tabindex="0"></button>`;
            }
            this.cellsGridEl.innerHTML = html;
        },

        // =====================================================================
        // STORAGE & SCORES
        // =====================================================================
        getStorageKey() {
            return `kai_games_ttt_${this.variant}_scores`;
        },

        loadScores() {
            try {
                const raw = localStorage.getItem(this.getStorageKey());
                if (raw) {
                    const parsed = JSON.parse(raw);
                    this.scores.p1 = Number(parsed.p1) || 0;
                    this.scores.p2 = Number(parsed.p2) || 0;
                    this.scores.draw = Number(parsed.draw) || 0;
                } else {
                    this.scores = { p1: 0, p2: 0, draw: 0 };
                }
            } catch (_) {
                this.scores = { p1: 0, p2: 0, draw: 0 };
            }
            this.updateScoresUI();
        },

        saveScores() {
            try {
                localStorage.setItem(this.getStorageKey(), JSON.stringify(this.scores));
            } catch (_) {}
            this.updateScoresUI();
        },

        resetScores() {
            this.scores = { p1: 0, p2: 0, draw: 0 };
            this.saveScores();
            triggerVibration([20]);
        },

        updateScoresUI() {
            if (this.scoreP1El) this.scoreP1El.textContent = this.scores.p1;
            if (this.scoreP2El) this.scoreP2El.textContent = this.scores.p2;
            if (this.scoreDrawEl) this.scoreDrawEl.textContent = this.scores.draw;

            if (this.labelP1El) {
                this.labelP1El.textContent = this.mode === 'pvp' ? 'Spieler 1' : this.player1Name;
            }
            if (this.labelP2El) {
                this.labelP2El.textContent = this.mode === 'pvp' ? 'Spieler 2' : 'Computer';
            }
        },

        // =====================================================================
        // SPIELSTART & MODUS-WECHSEL
        // =====================================================================
        setVariant(variant) {
            if (this.variant === variant) return;
            this.variant = variant;

            // UI-Schaltflächen umschalten
            if (this.containerEl) {
                this.containerEl.querySelectorAll('.js-ttt-variant-btn').forEach(btn => {
                    const isActive = btn.dataset.variant === variant;
                    btn.classList.toggle('active', isActive);
                    btn.classList.toggle('btn-outline', !isActive);
                });
            }

            // Board-Klasse anpassen
            if (this.boardContainerEl) {
                this.boardContainerEl.classList.toggle('is-classic', variant === 'classic');
                this.boardContainerEl.classList.toggle('is-tantfant', variant === 'tantfant');
            }

            this.loadScores();
            this.startNewGame(true);
        },

        setMode(mode) {
            if (this.mode === mode) return;
            this.mode = mode;

            if (this.containerEl) {
                this.containerEl.querySelectorAll('.js-ttt-mode-btn').forEach(btn => {
                    const isActive = btn.dataset.mode === mode;
                    btn.classList.toggle('active', isActive);
                    btn.classList.toggle('btn-outline', !isActive);
                });
            }

            if (this.diffBarEl) {
                this.diffBarEl.classList.toggle('hidden', mode !== 'ai');
            }

            this.updateScoresUI();
            this.startNewGame(true);
        },

        setDifficulty(diff) {
            if (this.difficulty === diff) return;
            this.difficulty = diff;

            if (this.diffBarEl) {
                this.diffBarEl.querySelectorAll('.js-ttt-diff-btn').forEach(btn => {
                    const isActive = btn.dataset.diff === diff;
                    btn.classList.toggle('active', isActive);
                    btn.classList.toggle('btn-outline', !isActive);
                });
            }
            triggerVibration([15]);
        },

        startNewGame(silent = false) {
            this.isGameOver = false;
            this.isAiThinking = false;
            this.selectedPiece = null;
            this.moveCount = 0;
            this.positionHistory = [];
            this.turn = 'X'; // X fängt immer an

            // Initiales Spielfeld
            if (this.variant === 'tantfant') {
                // X (Spieler 1) oben [0, 1, 2]
                // O (Spieler 2) unten [6, 7, 8]
                // Mitte leer [3, 4, 5]
                this.board = ['X', 'X', 'X', null, null, null, 'O', 'O', 'O'];
            } else {
                // Klassisches Tic-Tac-Toe: leeres 3x3
                this.board = Array(9).fill(null);
            }

            this.recordPosition();

            // UI zurücksetzen
            if (this.boardContainerEl) {
                this.boardContainerEl.classList.toggle('is-classic', this.variant === 'classic');
                this.boardContainerEl.classList.toggle('is-tantfant', this.variant === 'tantfant');
            }

            if (this.winSvgEl) this.winSvgEl.classList.add('hidden');
            if (this.resultBannerEl) this.resultBannerEl.classList.add('hidden');
            if (this.drawBtnEl) this.drawBtnEl.classList.add('hidden');

            this.renderBoard();
            this.updateTurnUI();

            if (!silent) {
                triggerVibration([20]);
            }
        },

        // =====================================================================
        // ZUGVERWALTUNG & INTERAKTION
        // =====================================================================
        handleCellClick(idx) {
            if (this.isGameOver || this.isAiThinking) return;

            // Wenn KI am Zug ist, darf der Nutzer nicht klicken
            if (this.mode === 'ai' && this.turn === 'O') return;

            if (this.variant === 'classic') {
                this.handleClassicCellClick(idx);
            } else {
                this.handleTantFantCellClick(idx);
            }
        },

        handleClassicCellClick(idx) {
            // Nur freie Felder erlaubt
            if (this.board[idx] !== null) return;

            this.makeMove(null, idx, this.turn);
        },

        handleTantFantCellClick(idx) {
            const occupant = this.board[idx];

            // 1. Wenn ein eigener Stein angeklickt wird: Stein auswählen bzw. wechseln
            if (occupant === this.turn) {
                if (this.selectedPiece === idx) {
                    // Bereits ausgewählt -> deselektieren
                    this.selectedPiece = null;
                } else {
                    this.selectedPiece = idx;
                    triggerVibration([15]);
                }
                this.renderBoard();
                this.updateInstruction();
                return;
            }

            // 2. Wenn ein leeres Feld angeklickt wird und ein Stein ausgewählt ist
            if (occupant === null && this.selectedPiece !== null) {
                // Prüfen, ob das Zielfeld ein gültiger Nachbar ist
                const validNeighbors = ADJACENCY[this.selectedPiece] || [];
                if (validNeighbors.includes(idx)) {
                    const fromIdx = this.selectedPiece;
                    this.selectedPiece = null;
                    this.makeMove(fromIdx, idx, this.turn);
                } else {
                    // Ungültiger Zug (keine direkte Verbindung)
                    triggerVibration([40, 40]);
                }
            }
        },

        makeMove(fromIdx, toIdx, player) {
            if (this.variant === 'tantfant') {
                this.board[fromIdx] = null;
                this.board[toIdx] = player;
            } else {
                this.board[toIdx] = player;
            }

            this.moveCount++;
            this.recordPosition();
            triggerVibration([25]);

            this.renderBoard();

            // Gewinn-Check
            const winResult = this.checkWin(this.board, this.variant);
            if (winResult) {
                this.handleWin(winResult);
                return;
            }

            // Bei Tant Fant: Remis-Button ab 8 Zügen anbieten
            if (this.variant === 'tantfant' && this.moveCount >= 8 && this.drawBtnEl) {
                this.drawBtnEl.classList.remove('hidden');
            }

            // Unentschieden-Check bei klassischem Tic-Tac-Toe
            if (this.variant === 'classic' && !this.board.includes(null)) {
                this.handleDraw();
                return;
            }

            // Bei Tant Fant: Prüfen, ob der nächste Spieler überhaupt einen legalen Zug hat
            const nextPlayer = player === 'X' ? 'O' : 'X';
            if (this.variant === 'tantfant') {
                const nextMoves = this.getLegalMoves(this.board, nextPlayer);
                if (nextMoves.length === 0) {
                    // Eingesperrt / bewegungsunfähig -> der ziehende Spieler gewinnt!
                    this.handleWin({
                        winner: player,
                        line: null,
                        reason: 'blocked'
                    });
                    return;
                }
            }

            // Zug abgeben
            this.turn = nextPlayer;
            this.updateTurnUI();

            // Falls KI an der Reihe ist:
            if (this.mode === 'ai' && this.turn === 'O') {
                this.scheduleAiMove();
            }
        },

        // =====================================================================
        // GEWINN-LOGIK & REGELN
        // =====================================================================
        checkWin(board, variant) {
            for (const line of WIN_LINES) {
                const [a, b, c] = line;
                const p = board[a];
                if (p !== null && p === board[b] && p === board[c]) {
                    // Tant-Fant-Sonderregel:
                    // Die anfängliche Startreihe zählt nicht als Gewinn!
                    // X startete bei [0, 1, 2] -> Zeile 0 zählt für X nicht
                    // O startete bei [6, 7, 8] -> Zeile 2 zählt für O nicht
                    if (variant === 'tantfant') {
                        if (p === 'X' && a === 0 && b === 1 && c === 2) continue;
                        if (p === 'O' && a === 6 && b === 7 && c === 8) continue;
                    }

                    return { winner: p, line: line };
                }
            }
            return null;
        },

        getLegalMoves(board, player) {
            const moves = [];
            for (let from = 0; from < 9; from++) {
                if (board[from] === player) {
                    const neighbors = ADJACENCY[from] || [];
                    for (const to of neighbors) {
                        if (board[to] === null) {
                            moves.push({ from, to });
                        }
                    }
                }
            }
            return moves;
        },

        recordPosition() {
            const hash = this.board.map(c => c || '.').join('') + ':' + this.turn;
            this.positionHistory.push(hash);
        },

        hasRepetition() {
            if (this.positionHistory.length < 6) return false;
            const current = this.positionHistory[this.positionHistory.length - 1];
            let count = 0;
            for (const h of this.positionHistory) {
                if (h === current) count++;
            }
            return count >= 3;
        },

        // =====================================================================
        // GEWINN & REMIS BEHANDLUNG
        // =====================================================================
        handleWin(winResult) {
            this.isGameOver = true;
            this.selectedPiece = null;

            const winner = winResult.winner;
            const isP1 = winner === 'X';

            if (isP1) {
                this.scores.p1++;
            } else {
                this.scores.p2++;
            }
            this.saveScores();

            // Gewinnlinie animieren
            if (winResult.line && this.winSvgEl && this.winLineEl) {
                const [a, , c] = winResult.line;
                const p1 = NODE_COORDS[a];
                const p2 = NODE_COORDS[c];

                this.winLineEl.setAttribute('x1', p1.x);
                this.winLineEl.setAttribute('y1', p1.y);
                this.winLineEl.setAttribute('x2', p2.x);
                this.winLineEl.setAttribute('y2', p2.y);
                this.winSvgEl.classList.remove('hidden');

                // Siegerzellen markieren
                winResult.line.forEach(idx => {
                    const cell = this.cellsGridEl.querySelector(`[data-index="${idx}"]`);
                    if (cell) cell.classList.add('is-winning-cell');
                });
            }

            // Banner anzeigen
            let bannerText = '';
            if (this.mode === 'ai') {
                if (isP1) {
                    bannerText = `🎉 Glückwunsch! Du hast gegen den Computer gewonnen!`;
                    triggerVibration([40, 60, 100]);
                } else {
                    bannerText = `🤖 Der Computer gewinnt diese Runde!`;
                    triggerVibration([60, 40, 40]);
                }
            } else {
                const name = isP1 ? 'Spieler 1 (✕)' : 'Spieler 2 (○)';
                bannerText = `🏆 ${name} gewinnt die Partie!`;
                triggerVibration([40, 60, 100]);
            }

            if (winResult.reason === 'blocked') {
                bannerText += ' (Gegner eingesperrt)';
            }

            this.showResultBanner(bannerText, isP1 ? 'win' : 'lose');
            this.updateTurnUI();
        },

        handleDraw(manual = false) {
            this.isGameOver = true;
            this.selectedPiece = null;
            this.scores.draw++;
            this.saveScores();

            triggerVibration([30, 30]);

            const msg = manual
                ? '🤝 Unentschieden durch Vereinbarung beider Spieler!'
                : '🤝 Unentschieden! Niemand konnte eine 3er-Reihe bilden.';

            this.showResultBanner(msg, 'draw');
            this.updateTurnUI();
        },

        showResultBanner(text, type) {
            if (!this.resultBannerEl || !this.resultTextEl) return;
            this.resultTextEl.textContent = text;
            this.resultBannerEl.className = `ttt-result-banner is-${type}`;
            this.resultBannerEl.classList.remove('hidden');
        },

        // =====================================================================
        // KÜNSTLICHE INTELLIGENZ (KI)
        // =====================================================================
        scheduleAiMove() {
            this.isAiThinking = true;
            this.updateInstruction();

            // Natürliche menschliche Denkverzögerung (280 - 450 ms)
            const delay = 320 + Math.floor(Math.random() * 120);
            setTimeout(() => {
                if (this.isGameOver) {
                    this.isAiThinking = false;
                    return;
                }
                this.executeAiMove();
                this.isAiThinking = false;
                this.updateInstruction();
            }, delay);
        },

        executeAiMove() {
            if (this.variant === 'classic') {
                const targetIdx = this.getBestClassicMove();
                if (targetIdx !== null && targetIdx !== undefined) {
                    this.makeMove(null, targetIdx, 'O');
                }
            } else {
                const move = this.getBestTantFantMove();
                if (move) {
                    this.makeMove(move.from, move.to, 'O');
                }
            }
        },

        // --- Klassisches Tic-Tac-Toe Minimax ---
        getBestClassicMove() {
            const emptyIndices = [];
            for (let i = 0; i < 9; i++) {
                if (this.board[i] === null) emptyIndices.push(i);
            }
            if (emptyIndices.length === 0) return null;

            // Leicht: 75% Zufall, 25% Minimax
            if (this.difficulty === 'easy' && Math.random() < 0.75) {
                return emptyIndices[Math.floor(Math.random() * emptyIndices.length)];
            }

            // Mittel: 35% Zufall, 65% Minimax
            if (this.difficulty === 'medium' && Math.random() < 0.35) {
                return emptyIndices[Math.floor(Math.random() * emptyIndices.length)];
            }

            // Unschlagbar: Reiner Minimax
            let bestScore = -Infinity;
            let bestMove = emptyIndices[0];

            for (const idx of emptyIndices) {
                this.board[idx] = 'O';
                const score = this.minimaxClassic(this.board, 0, false);
                this.board[idx] = null;

                if (score > bestScore) {
                    bestScore = score;
                    bestMove = idx;
                }
            }

            return bestMove;
        },

        minimaxClassic(board, depth, isMax) {
            const win = this.checkWin(board, 'classic');
            if (win) {
                return win.winner === 'O' ? (10 - depth) : (depth - 10);
            }
            if (!board.includes(null)) {
                return 0; // Remis
            }

            if (isMax) {
                let maxEval = -Infinity;
                for (let i = 0; i < 9; i++) {
                    if (board[i] === null) {
                        board[i] = 'O';
                        const evalVal = this.minimaxClassic(board, depth + 1, false);
                        board[i] = null;
                        maxEval = Math.max(maxEval, evalVal);
                    }
                }
                return maxEval;
            } else {
                let minEval = Infinity;
                for (let i = 0; i < 9; i++) {
                    if (board[i] === null) {
                        board[i] = 'X';
                        const evalVal = this.minimaxClassic(board, depth + 1, true);
                        board[i] = null;
                        minEval = Math.min(minEval, evalVal);
                    }
                }
                return minEval;
            }
        },

        // --- Tant Fant Minimax & Heuristik ---
        getBestTantFantMove() {
            const legalMoves = this.getLegalMoves(this.board, 'O');
            if (legalMoves.length === 0) return null;

            // Leicht: 70% Zufall, 30% Taktik
            if (this.difficulty === 'easy' && Math.random() < 0.7) {
                return legalMoves[Math.floor(Math.random() * legalMoves.length)];
            }

            // Tiefe je nach Schwierigkeitsgrad
            const maxDepth = this.difficulty === 'hard' ? 7 : 3;

            let bestMove = legalMoves[0];
            let maxEval = -Infinity;
            const visited = new Set();

            for (const m of legalMoves) {
                const key = this.board.join('');
                visited.add(key);

                this.board[m.from] = null;
                this.board[m.to] = 'O';

                const evalVal = this.minimaxTantFant(this.board, 0, maxDepth, false, -Infinity, Infinity, visited);

                this.board[m.from] = 'O';
                this.board[m.to] = null;
                visited.delete(key);

                if (evalVal > maxEval) {
                    maxEval = evalVal;
                    bestMove = m;
                }
            }

            return bestMove;
        },

        minimaxTantFant(board, depth, maxDepth, isMax, alpha, beta, path) {
            const win = this.checkWin(board, 'tantfant');
            if (win) {
                return win.winner === 'O' ? (1000 - depth) : (-1000 + depth);
            }
            if (depth >= maxDepth) {
                return this.evaluateTantFantBoard(board, depth);
            }

            const player = isMax ? 'O' : 'X';
            const moves = this.getLegalMoves(board, player);

            // Keine Züge mehr -> Eingesperrt
            if (moves.length === 0) {
                return isMax ? (-900 + depth) : (900 - depth);
            }

            if (isMax) {
                let maxEval = -Infinity;
                for (const m of moves) {
                    const key = board.join('');
                    if (path.has(key)) continue;
                    path.add(key);

                    board[m.from] = null;
                    board[m.to] = 'O';

                    const res = this.minimaxTantFant(board, depth + 1, maxDepth, false, alpha, beta, path);

                    board[m.from] = 'O';
                    board[m.to] = null;
                    path.delete(key);

                    maxEval = Math.max(maxEval, res);
                    alpha = Math.max(alpha, res);
                    if (beta <= alpha) break;
                }
                return maxEval === -Infinity ? 0 : maxEval;
            } else {
                let minEval = Infinity;
                for (const m of moves) {
                    const key = board.join('');
                    if (path.has(key)) continue;
                    path.add(key);

                    board[m.from] = null;
                    board[m.to] = 'X';

                    const res = this.minimaxTantFant(board, depth + 1, maxDepth, true, alpha, beta, path);

                    board[m.from] = 'X';
                    board[m.to] = null;
                    path.delete(key);

                    minEval = Math.min(minEval, res);
                    beta = Math.min(beta, res);
                    if (beta <= alpha) break;
                }
                return minEval === Infinity ? 0 : minEval;
            }
        },

        evaluateTantFantBoard(board, depth) {
            let score = 0;
            // Zentrumskontrolle (Knoten 4 hat 8 Linien, strategisch extrem wertvoll)
            if (board[4] === 'O') score += 45;
            else if (board[4] === 'X') score -= 45;

            // Mobilitätsbonus (wie viele Züge hat jeder)
            const oMoves = this.getLegalMoves(board, 'O').length;
            const xMoves = this.getLegalMoves(board, 'X').length;
            score += (oMoves - xMoves) * 5;

            return score;
        },

        // =====================================================================
        // RENDERING & UI-AKTUALISIERUNG
        // =====================================================================
        renderBoard() {
            if (!this.cellsGridEl) return;

            // Ermittle gültige Ziel-Indizes, falls ein Stein ausgewählt ist
            let validTargets = [];
            if (this.variant === 'tantfant' && this.selectedPiece !== null) {
                const neighbors = ADJACENCY[this.selectedPiece] || [];
                validTargets = neighbors.filter(idx => this.board[idx] === null);
            }

            const cells = this.cellsGridEl.querySelectorAll('.ttt-cell');
            cells.forEach((cell, idx) => {
                const val = this.board[idx];
                cell.className = 'ttt-cell js-ttt-cell';

                // Symbol-Rendering
                if (val === 'X') {
                    cell.innerHTML = '<span class="ttt-token token-x">✕</span>';
                    cell.setAttribute('aria-label', `Feld ${idx + 1}: Spieler 1 (✕)`);
                } else if (val === 'O') {
                    cell.innerHTML = '<span class="ttt-token token-o">○</span>';
                    cell.setAttribute('aria-label', `Feld ${idx + 1}: Spieler 2 (○)`);
                } else {
                    cell.innerHTML = '';
                    cell.setAttribute('aria-label', `Feld ${idx + 1}: Leer`);
                }

                // Ausgewählter Stein
                if (this.selectedPiece === idx) {
                    cell.classList.add('is-selected');
                }

                // Gültige Ziel-Zellen
                if (validTargets.includes(idx)) {
                    cell.classList.add('is-valid-target');
                    cell.setAttribute('aria-label', `Feld ${idx + 1}: Gültiges Ziel`);
                }
            });
        },

        updateTurnUI() {
            const isP1 = this.turn === 'X';

            if (this.cardP1El) this.cardP1El.classList.toggle('is-active', isP1 && !this.isGameOver);
            if (this.cardP2El) this.cardP2El.classList.toggle('is-active', !isP1 && !this.isGameOver);

            if (this.badgeP1El) this.badgeP1El.classList.toggle('hidden', !isP1 || this.isGameOver);
            if (this.badgeP2El) this.badgeP2El.classList.toggle('hidden', isP1 || this.isGameOver);

            this.updateInstruction();
        },

        updateInstruction() {
            if (!this.instructionEl) return;

            if (this.isGameOver) {
                this.instructionEl.textContent = 'Partie beendet. Klicke auf „Neues Spiel“, um eine Revanche zu starten.';
                return;
            }

            if (this.isAiThinking) {
                this.instructionEl.textContent = '🤖 Computer überlegt...';
                return;
            }

            const isP1 = this.turn === 'X';
            const name = isP1
                ? (this.mode === 'pvp' ? 'Spieler 1 (✕)' : 'Du (✕)')
                : (this.mode === 'pvp' ? 'Spieler 2 (○)' : 'Computer (○)');

            if (this.variant === 'classic') {
                this.instructionEl.textContent = `${name} ist am Zug. Tippe auf ein freies Feld, um dein Zeichen zu setzen.`;
            } else {
                if (this.selectedPiece !== null) {
                    this.instructionEl.textContent = `${name}: Tippe auf ein markiertes Nachbarfeld, um den Stein zu ziehen.`;
                } else {
                    this.instructionEl.textContent = `${name}: Wähle einen deiner 3 Steine aus, um ihn zu bewegen.`;
                }
            }
        },

        // =====================================================================
        // EVENT-DELEGATION (CSP-KONFORM)
        // =====================================================================
        bindEvents() {
            if (!this.containerEl) return;

            this.containerEl.addEventListener('click', (e) => {
                // 1. Zelle geklickt
                const cell = e.target.closest('.js-ttt-cell');
                if (cell) {
                    const idx = Number(cell.dataset.index);
                    if (!isNaN(idx)) {
                        this.handleCellClick(idx);
                    }
                    return;
                }

                // 2. Varianten-Umschaltung
                const variantBtn = e.target.closest('.js-ttt-variant-btn');
                if (variantBtn && variantBtn.dataset.variant) {
                    this.setVariant(variantBtn.dataset.variant);
                    return;
                }

                // 3. Modus-Umschaltung (AI vs PvP)
                const modeBtn = e.target.closest('.js-ttt-mode-btn');
                if (modeBtn && modeBtn.dataset.mode) {
                    this.setMode(modeBtn.dataset.mode);
                    return;
                }

                // 4. KI-Schwierigkeit
                const diffBtn = e.target.closest('.js-ttt-diff-btn');
                if (diffBtn && diffBtn.dataset.diff) {
                    this.setDifficulty(diffBtn.dataset.diff);
                    return;
                }

                // 5. Neues Spiel
                if (e.target.closest('.js-ttt-restart-btn')) {
                    this.startNewGame();
                    return;
                }

                // 6. Remis vereinbaren
                if (e.target.closest('.js-ttt-draw-btn')) {
                    this.handleDraw(true);
                    return;
                }

                // 7. Punktestände zurücksetzen
                if (e.target.closest('.js-ttt-reset-scores-btn')) {
                    this.resetScores();
                    return;
                }
            });

            // Tastaturunterstützung (Enter & Leertaste für Gitterzellen)
            this.containerEl.addEventListener('keydown', (e) => {
                if (e.key === ' ' || e.key === 'Enter') {
                    const cell = e.target.closest('.js-ttt-cell');
                    if (cell) {
                        e.preventDefault();
                        const idx = Number(cell.dataset.index);
                        if (!isNaN(idx)) {
                            this.handleCellClick(idx);
                        }
                    }
                }
            });
        }
    };

    // Beim Laden des Dokuments initialisieren
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => TicTacToeTool.init());
    } else {
        TicTacToeTool.init();
    }
})();
