/**
 * reactions.js - Quarxl Live-Reaktions-System mit Spam-Schutz & Audio-Effekten
 */
(function() {
    'use strict';

    const REACTION_EMOJIS = [
        { emoji: '😂', label: 'Lachen', text: 'Haha!' },
        { emoji: '😢', label: 'Weinen', text: 'Och nöö...' },
        { emoji: '⏳', label: 'Ungeduldig warten', text: 'Wann geht\'s weiter? 😉' },
        { emoji: '👏', label: 'Applaus', text: 'Stark gespielt!' },
        { emoji: '😱', label: 'Schock', text: 'Oh je, das tut weh!' },
        { emoji: '🔥', label: 'Feiern', text: 'Richtig stark!' },
        { emoji: '🤔', label: 'Grübeln', text: 'Muss kurz nachdenken...' }
    ];

    const COOLDOWN_SECONDS = 3.5;

    // Web Audio Synthesizer für Reaktions-Sounds (keine externen Audio-Dateien)
    const ReactionAudio = {
        ctx: null,
        isMuted() {
            return localStorage.getItem('kai_quarxl_reactions_mute') === '1';
        },
        toggleMute() {
            const next = !this.isMuted();
            localStorage.setItem('kai_quarxl_reactions_mute', next ? '1' : '0');
            return next;
        },
        init() {
            if (!this.ctx && (window.AudioContext || window.webkitAudioContext)) {
                this.ctx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (this.ctx && this.ctx.state === 'suspended') {
                this.ctx.resume().catch(() => {});
            }
        },
        play(emoji) {
            if (this.isMuted()) return;
            try {
                this.init();
                if (!this.ctx) return;
                const now = this.ctx.currentTime;

                if (emoji === '😂') {
                    // Lachen: Fröhliches aufsteigendes Staccato-Gekicher
                    const notes = [440, 554, 659, 880];
                    notes.forEach((freq, idx) => {
                        const osc = this.ctx.createOscillator();
                        const gain = this.ctx.createGain();
                        osc.type = 'triangle';
                        osc.frequency.setValueAtTime(freq, now + idx * 0.06);
                        gain.gain.setValueAtTime(0.14, now + idx * 0.06);
                        gain.gain.exponentialRampToValueAtTime(0.01, now + (idx + 1) * 0.06);
                        osc.connect(gain);
                        gain.connect(this.ctx.destination);
                        osc.start(now + idx * 0.06);
                        osc.stop(now + (idx + 1) * 0.06);
                    });
                } else if (emoji === '😢') {
                    // Weinen: Trauriges, abfallendes Seufzen
                    const osc = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(480, now);
                    osc.frequency.exponentialRampToValueAtTime(220, now + 0.35);
                    gain.gain.setValueAtTime(0.18, now);
                    gain.gain.linearRampToValueAtTime(0.01, now + 0.35);
                    osc.connect(gain);
                    gain.connect(this.ctx.destination);
                    osc.start(now);
                    osc.stop(now + 0.36);
                } else if (emoji === '⏳') {
                    // Ungeduldig warten: Scharfes Uhrenticken (Tick-Tack-Tick-Tack)
                    [0, 0.12, 0.24, 0.36].forEach((timeOffset, idx) => {
                        const osc = this.ctx.createOscillator();
                        const gain = this.ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(idx % 2 === 0 ? 1200 : 900, now + timeOffset);
                        gain.gain.setValueAtTime(0.2, now + timeOffset);
                        gain.gain.exponentialRampToValueAtTime(0.005, now + timeOffset + 0.04);
                        osc.connect(gain);
                        gain.connect(this.ctx.destination);
                        osc.start(now + timeOffset);
                        osc.stop(now + timeOffset + 0.05);
                    });
                } else if (emoji === '👏') {
                    // Applaus: Perkussiver Doppelklatscher
                    [0, 0.1].forEach((offset) => {
                        const osc = this.ctx.createOscillator();
                        const gain = this.ctx.createGain();
                        osc.type = 'triangle';
                        osc.frequency.setValueAtTime(350, now + offset);
                        osc.frequency.exponentialRampToValueAtTime(100, now + offset + 0.07);
                        gain.gain.setValueAtTime(0.2, now + offset);
                        gain.gain.linearRampToValueAtTime(0.01, now + offset + 0.07);
                        osc.connect(gain);
                        gain.connect(this.ctx.destination);
                        osc.start(now + offset);
                        osc.stop(now + offset + 0.08);
                    });
                } else if (emoji === '😱') {
                    // Schock: Dissonanter Schreck-Ton
                    const osc1 = this.ctx.createOscillator();
                    const osc2 = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    osc1.type = 'sawtooth';
                    osc2.type = 'sawtooth';
                    osc1.frequency.setValueAtTime(740, now);
                    osc2.frequency.setValueAtTime(784, now); // Halbton-Dissonanz
                    gain.gain.setValueAtTime(0.12, now);
                    gain.gain.exponentialRampToValueAtTime(0.01, now + 0.25);
                    osc1.connect(gain);
                    osc2.connect(gain);
                    gain.connect(this.ctx.destination);
                    osc1.start(now);
                    osc2.start(now);
                    osc1.stop(now + 0.26);
                    osc2.stop(now + 0.26);
                } else if (emoji === '🔥') {
                    // Feiern: Strahlender Sieges-Akkord
                    const chord = [523.25, 659.25, 783.99, 1046.50];
                    chord.forEach((freq) => {
                        const osc = this.ctx.createOscillator();
                        const gain = this.ctx.createGain();
                        osc.type = 'triangle';
                        osc.frequency.setValueAtTime(freq, now);
                        gain.gain.setValueAtTime(0.12, now);
                        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.35);
                        osc.connect(gain);
                        gain.connect(this.ctx.destination);
                        osc.start(now);
                        osc.stop(now + 0.36);
                    });
                } else {
                    // Standard / Grübeln: Sanfter Pling
                    const osc = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(587.33, now);
                    gain.gain.setValueAtTime(0.15, now);
                    gain.gain.exponentialRampToValueAtTime(0.01, now + 0.2);
                    osc.connect(gain);
                    gain.connect(this.ctx.destination);
                    osc.start(now);
                    osc.stop(now + 0.21);
                }
            } catch (_) {}
        }
    };

    const QuarxlReactions = {
        roomCode: '',
        lastSeenId: 0,
        seenReactionIds: new Set(),
        cooldownTimer: null,
        cooldownEnd: 0,
        isPolling: false,
        pollTimer: null,
        trayEl: null,
        triggerBtn: null,
        containerEl: null,

        init(options = {}) {
            const urlParams = new URLSearchParams(window.location.search);
            this.roomCode = (options.roomCode || urlParams.get('room') || '').toUpperCase().trim();
            if (!this.roomCode) {
                return;
            }

            this.ensureUI();
            this.startPolling();
        },

        ensureUI() {
            // 1. Container für fliegende Animationen im DOM verankern
            if (!document.getElementById('quarxl-floating-reactions')) {
                const zone = document.createElement('div');
                zone.id = 'quarxl-floating-reactions';
                zone.className = 'quarxl-floating-reactions-zone';
                document.body.appendChild(zone);
                this.containerEl = zone;
            } else {
                this.containerEl = document.getElementById('quarxl-floating-reactions');
            }

            // 2. Reaktions-Button in Headerleiste einbinden (falls noch nicht vorhanden)
            let existingBtn = document.querySelector('.js-btn-reactions');
            if (!existingBtn) {
                const headerActions = document.querySelector('.qwixx-top-actions, .cards-header-actions, .stb-header-actions, .loch6-header-actions');
                if (headerActions) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-sm btn-outline js-btn-reactions quarxl-btn-react';
                    btn.title = 'Reaktion senden';
                    btn.innerHTML = '😀 <span class="quarxl-react-btn-label">Reagieren</span>';
                    headerActions.insertBefore(btn, headerActions.firstChild);
                    existingBtn = btn;
                }
            }
            this.triggerBtn = existingBtn;

            if (this.triggerBtn) {
                this.triggerBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.toggleTray();
                });
            }

            // 3. Reaktions-Tray (Popover / Bar) erstellen
            if (!document.getElementById('quarxl-reaction-tray')) {
                const tray = document.createElement('div');
                tray.id = 'quarxl-reaction-tray';
                tray.className = 'quarxl-reaction-tray hidden';
                tray.setAttribute('role', 'dialog');
                tray.setAttribute('aria-label', 'Reaktion wählen');

                let emojisHtml = REACTION_EMOJIS.map(item => `
                    <button type="button" class="quarxl-emoji-btn" data-emoji="${item.emoji}" title="${item.label}">
                        <span class="quarxl-emoji-char">${item.emoji}</span>
                        <span class="quarxl-emoji-sub">${item.label}</span>
                    </button>
                `).join('');

                const soundMuted = ReactionAudio.isMuted();

                tray.innerHTML = `
                    <div class="quarxl-tray-header">
                        <span class="quarxl-tray-title">Reaktion senden</span>
                        <div class="quarxl-tray-tools">
                            <button type="button" class="quarxl-btn-sound" id="quarxl-toggle-sound" title="${soundMuted ? 'Ton einschalten' : 'Ton stummschalten'}">
                                ${soundMuted ? '🔇' : '🔊'}
                            </button>
                            <button type="button" class="quarxl-tray-close" id="quarxl-close-tray" title="Schließen">&times;</button>
                        </div>
                    </div>
                    <div class="quarxl-emojis-grid">
                        ${emojisHtml}
                    </div>
                    <div class="quarxl-cooldown-banner hidden" id="quarxl-cooldown-banner">
                        <span class="quarxl-cooldown-text">⏳ Noch <strong id="quarxl-cooldown-seconds">3</strong>s Pause...</span>
                    </div>
                `;

                document.body.appendChild(tray);
                this.trayEl = tray;

                // Event-Handling im Tray
                tray.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const soundBtn = e.target.closest('#quarxl-toggle-sound');
                    if (soundBtn) {
                        const muted = ReactionAudio.toggleMute();
                        soundBtn.textContent = muted ? '🔇' : '🔊';
                        soundBtn.title = muted ? 'Ton einschalten' : 'Ton stummschalten';
                        return;
                    }

                    const closeBtn = e.target.closest('#quarxl-close-tray');
                    if (closeBtn) {
                        this.hideTray();
                        return;
                    }

                    const emojiBtn = e.target.closest('.quarxl-emoji-btn');
                    if (emojiBtn && !emojiBtn.disabled) {
                        const emoji = emojiBtn.dataset.emoji;
                        if (emoji) {
                            this.sendReaction(emoji);
                        }
                    }
                });

                // Klick außerhalb schließt den Tray
                document.addEventListener('click', (e) => {
                    if (this.trayEl && !this.trayEl.contains(e.target) && (!this.triggerBtn || !this.triggerBtn.contains(e.target))) {
                        this.hideTray();
                    }
                });

                // Escape schließt den Tray
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && this.trayEl && !this.trayEl.classList.contains('hidden')) {
                        this.hideTray();
                    }
                });
            } else {
                this.trayEl = document.getElementById('quarxl-reaction-tray');
            }
        },

        toggleTray() {
            if (!this.trayEl) return;
            if (this.trayEl.classList.contains('hidden')) {
                this.showTray();
            } else {
                this.hideTray();
            }
        },

        showTray() {
            if (!this.trayEl) return;
            this.trayEl.classList.remove('hidden');

            // Intelligente Positionierung relativ zum Trigger-Button
            if (this.triggerBtn) {
                const rect = this.triggerBtn.getBoundingClientRect();
                const trayWidth = 320;
                let top = rect.bottom + 8;
                let left = rect.right - trayWidth;
                if (left < 10) left = 10;
                if (left + trayWidth > window.innerWidth - 10) {
                    left = window.innerWidth - trayWidth - 10;
                }
                this.trayEl.style.top = `${top}px`;
                this.trayEl.style.left = `${left}px`;
            }
        },

        hideTray() {
            if (this.trayEl) {
                this.trayEl.classList.add('hidden');
            }
        },

        startCooldown(seconds = COOLDOWN_SECONDS) {
            this.cooldownEnd = Date.now() + seconds * 1000;
            const banner = document.getElementById('quarxl-cooldown-banner');
            const secondsEl = document.getElementById('quarxl-cooldown-seconds');
            const buttons = this.trayEl ? this.trayEl.querySelectorAll('.quarxl-emoji-btn') : [];

            buttons.forEach(b => b.disabled = true);
            if (banner) banner.classList.remove('hidden');

            if (this.cooldownTimer) {
                clearInterval(this.cooldownTimer);
            }

            const updateRemaining = () => {
                const rem = Math.max(0, Math.ceil((this.cooldownEnd - Date.now()) / 1000));
                if (secondsEl) secondsEl.textContent = String(rem);

                if (rem <= 0) {
                    clearInterval(this.cooldownTimer);
                    this.cooldownTimer = null;
                    buttons.forEach(b => b.disabled = false);
                    if (banner) banner.classList.add('hidden');
                }
            };

            updateRemaining();
            this.cooldownTimer = setInterval(updateRemaining, 300);
        },

        async sendReaction(emoji) {
            if (Date.now() < this.cooldownEnd) {
                return;
            }

            // Lokales Feedback sofort anzeigen
            const myName = localStorage.getItem('kai_gh_player_name') || 'Ich';
            this.showFloatingReaction({
                id: 'local_' + Date.now(),
                player_name: myName,
                emoji: emoji
            }, true);

            // Spam-Schutz aktivieren
            this.startCooldown(COOLDOWN_SECONDS);

            // Audio-Effekt sofort wiedergeben
            ReactionAudio.play(emoji);

            // Automatisch nach Klick schließen für flüssiges Spiel
            setTimeout(() => this.hideTray(), 350);

            // Server-POST senden
            try {
                const payload = { room_code: this.roomCode, emoji: emoji };
                let res = await HubHttp.postJson('./api.php?action=react', payload);
                if (!res.ok && res.status === 404) {
                    // Fallback auf Root-Hub
                    res = await HubHttp.postJson('../api.php?action=react', payload);
                }

                if (res.data && res.data.reaction && res.data.reaction.id) {
                    this.seenReactionIds.add(Number(res.data.reaction.id));
                }

                if (!res.ok && res.data && res.data.error === 'spam_cooldown') {
                    const wait = res.data.remaining || 3;
                    this.startCooldown(wait);
                }
            } catch (_) {}
        },

        handleIncoming(reactions) {
            if (!Array.isArray(reactions) || reactions.length === 0) return;

            const nowTs = Math.floor(Date.now() / 1000);
            reactions.forEach(r => {
                const id = Number(r.id);
                if (!id || this.seenReactionIds.has(id)) return;
                this.seenReactionIds.add(id);

                if (id > this.lastSeenId) {
                    this.lastSeenId = id;
                }

                // Nur Reaktionen anzeigen, die nicht älter als 12 Sekunden sind
                const createdTs = Number(r.created_ts || nowTs);
                if (nowTs - createdTs <= 12) {
                    this.showFloatingReaction(r, false);
                    ReactionAudio.play(r.emoji);
                }
            });
        },

        showFloatingReaction(reaction, isLocal = false) {
            if (!this.containerEl) return;

            const toast = document.createElement('div');
            toast.className = 'quarxl-reaction-toast' + (isLocal ? ' is-own' : '');

            const foundDef = REACTION_EMOJIS.find(item => item.emoji === reaction.emoji);
            const caption = foundDef ? foundDef.text : '';

            // Zufällige horizontale Drift-Positionierung für lebendigen Partikel-Effekt
            const randomX = Math.floor(Math.random() * 60) - 30; // -30px bis +30px

            toast.style.setProperty('--drift-x', `${randomX}px`);
            toast.innerHTML = `
                <div class="quarxl-toast-emoji-badge">${reaction.emoji}</div>
                <div class="quarxl-toast-body">
                    <div class="quarxl-toast-sender">${HubHtml.escape(reaction.player_name || 'Spieler')}</div>
                    ${caption ? `<div class="quarxl-toast-caption">${HubHtml.escape(caption)}</div>` : ''}
                </div>
            `;

            this.containerEl.appendChild(toast);

            // Fliegende Emoji-Partikel im Hintergrund aufsteigen lassen
            this.spawnEmojiParticles(reaction.emoji);

            // Nach Ablauf der Animation aus dem DOM entfernen
            setTimeout(() => {
                if (toast && toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 3200);
        },

        spawnEmojiParticles(emoji) {
            if (!this.containerEl) return;
            const count = 3;
            for (let i = 0; i < count; i++) {
                setTimeout(() => {
                    const particle = document.createElement('div');
                    particle.className = 'quarxl-floating-particle';
                    particle.textContent = emoji;

                    const startX = Math.floor(Math.random() * 80) + 10; // 10% - 90%
                    const duration = (2.2 + Math.random() * 0.8).toFixed(2);
                    const scale = (1.2 + Math.random() * 0.8).toFixed(2);
                    const sway = Math.floor(Math.random() * 80) - 40;

                    particle.style.left = `${startX}%`;
                    particle.style.animationDuration = `${duration}s`;
                    particle.style.setProperty('--sway-x', `${sway}px`);
                    particle.style.transform = `scale(${scale})`;

                    this.containerEl.appendChild(particle);

                    setTimeout(() => {
                        if (particle && particle.parentNode) {
                            particle.parentNode.removeChild(particle);
                        }
                    }, parseFloat(duration) * 1000 + 100);
                }, i * 160);
            }
        },

        startPolling() {
            if (this.pollTimer) return;
            this.pollTimer = setInterval(() => this.pollReactions(), 1600);
        },

        async pollReactions() {
            if (this.isPolling || !this.roomCode) return;
            this.isPolling = true;

            try {
                let url = `./api.php?action=reactions&room=${encodeURIComponent(this.roomCode)}&since=${this.lastSeenId}`;
                let res = await HubHttp.getJson(url);
                if (!res.ok && res.status === 404) {
                    url = `../api.php?action=reactions&room=${encodeURIComponent(this.roomCode)}&since=${this.lastSeenId}`;
                    res = await HubHttp.getJson(url);
                }

                if (res.ok && res.data && Array.isArray(res.data.reactions)) {
                    this.handleIncoming(res.data.reactions);
                }
            } catch (_) {
            } finally {
                this.isPolling = false;
            }
        }
    };

    // Global bereitstellen
    window.QuarxlReactions = QuarxlReactions;

    // Automatischer Start sobald das DOM bereit ist
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => QuarxlReactions.init());
    } else {
        QuarxlReactions.init();
    }
})();
