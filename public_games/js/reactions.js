/**
 * reactions.js - Quarxl Live-Reaktions-System mit Spam-Schutz & Audio-Effekten
 */
(function() {
    'use strict';

    const REACTION_EMOJIS = [
        { emoji: '😂', label: 'Lachen', text: 'Haha!' },
        { emoji: '😢', label: 'Weinen', text: 'Och nöö...' },
        { emoji: '⏳', label: 'Ungeduldig', text: 'Wann geht\'s weiter? 😉' },
        { emoji: '👏', label: 'Applaus', text: 'Stark gespielt!' },
        { emoji: '🥳', label: 'Feiern', text: 'Party! Jaaa!' },
        { emoji: '😱', label: 'Schock', text: 'Oh je, das tut weh!' },
        { emoji: '🤔', label: 'Grübeln', text: 'Muss kurz überlegen...' },
        { emoji: '🔥', label: 'Feuer', text: 'On fire!' }
    ];

    const COOLDOWN_SECONDS = 3.5;

    // Organischer Web Audio Akustik-Synthesizer (Keine billigen Beeps, sondern warme, physikalisch modellierte Klänge)
    const ReactionAudio = {
        ctx: null,
        noiseBuf: null,

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
        getNoiseBuffer() {
            if (this.noiseBuf) return this.noiseBuf;
            if (!this.ctx) return null;
            const length = Math.floor(this.ctx.sampleRate * 0.35);
            const buf = this.ctx.createBuffer(1, length, this.ctx.sampleRate);
            const data = buf.getChannelData(0);
            for (let i = 0; i < length; i++) {
                data[i] = (Math.random() * 2 - 1) * Math.exp(-i / (this.ctx.sampleRate * 0.12));
            }
            this.noiseBuf = buf;
            return buf;
        },
        play(emoji) {
            if (this.isMuted()) return;
            try {
                this.init();
                if (!this.ctx) return;
                const now = this.ctx.currentTime;

                if (emoji === '😂') {
                    // Lachen: Fröhliche, weiche Wasser-Bläschen / Marimba-Drops (warm & verspielt)
                    const pops = [520, 640, 780, 940];
                    pops.forEach((f, idx) => {
                        const t = now + idx * 0.075;
                        const osc = this.ctx.createOscillator();
                        const gain = this.ctx.createGain();
                        const filter = this.ctx.createBiquadFilter();

                        filter.type = 'lowpass';
                        filter.frequency.setValueAtTime(2400, t);

                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(f * 0.85, t);
                        osc.frequency.exponentialRampToValueAtTime(f * 1.25, t + 0.045);
                        osc.frequency.exponentialRampToValueAtTime(f * 0.95, t + 0.08);

                        gain.gain.setValueAtTime(0.001, t);
                        gain.gain.linearRampToValueAtTime(0.12, t + 0.015);
                        gain.gain.exponentialRampToValueAtTime(0.001, t + 0.09);

                        osc.connect(filter);
                        filter.connect(gain);
                        gain.connect(this.ctx.destination);

                        osc.start(t);
                        osc.stop(t + 0.1);
                    });

                } else if (emoji === '😢') {
                    // Weinen: Sanfter, melancholischer Cello/Flöten-Seufzer (warm detuned mit weichem Attack)
                    const osc1 = this.ctx.createOscillator();
                    const osc2 = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    const filter = this.ctx.createBiquadFilter();

                    filter.type = 'lowpass';
                    filter.frequency.setValueAtTime(650, now);
                    filter.frequency.exponentialRampToValueAtTime(260, now + 0.45);

                    osc1.type = 'sine';
                    osc2.type = 'triangle';

                    // Sanfter Abwärtsschwung mit natürlichem Schwebungs-Chorus
                    osc1.frequency.setValueAtTime(360, now);
                    osc1.frequency.exponentialRampToValueAtTime(210, now + 0.42);
                    osc2.frequency.setValueAtTime(363, now); // +3Hz Detune
                    osc2.frequency.exponentialRampToValueAtTime(212, now + 0.42);

                    gain.gain.setValueAtTime(0.001, now);
                    gain.gain.linearRampToValueAtTime(0.13, now + 0.06);
                    gain.gain.linearRampToValueAtTime(0.08, now + 0.25);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.46);

                    osc1.connect(filter);
                    osc2.connect(filter);
                    filter.connect(gain);
                    gain.connect(this.ctx.destination);

                    osc1.start(now);
                    osc2.start(now);
                    osc1.stop(now + 0.48);
                    osc2.stop(now + 0.48);

                } else if (emoji === '⏳') {
                    // Ungeduldig: Akustisches Holz-Uhrwerk (echtes mechanisches Tick-Tack-Tick-Tack)
                    const ticks = [
                        { f: 1650, q: 12, vol: 0.15 },
                        { f: 1100, q: 10, vol: 0.12 },
                        { f: 1650, q: 12, vol: 0.15 },
                        { f: 1100, q: 10, vol: 0.12 }
                    ];

                    ticks.forEach((tick, idx) => {
                        const t = now + idx * 0.14;

                        // Resonanter Holzkörper-Bandpass
                        const filter = this.ctx.createBiquadFilter();
                        filter.type = 'bandpass';
                        filter.frequency.setValueAtTime(tick.f, t);
                        filter.Q.setValueAtTime(tick.q, t);

                        const gain = this.ctx.createGain();
                        gain.gain.setValueAtTime(tick.vol, t);
                        gain.gain.exponentialRampToValueAtTime(0.001, t + 0.04);

                        // Impuls aus Rauschbuffer für echten akustischen Schlag
                        const noise = this.ctx.createBufferSource();
                        const buf = this.getNoiseBuffer();
                        if (buf) {
                            noise.buffer = buf;
                            noise.connect(filter);
                            filter.connect(gain);
                            gain.connect(this.ctx.destination);
                            noise.start(t);
                            noise.stop(t + 0.045);
                        }

                        // Holzkorpus-Resonanz
                        const wood = this.ctx.createOscillator();
                        const woodGain = this.ctx.createGain();
                        wood.type = 'triangle';
                        wood.frequency.setValueAtTime(tick.f * 0.4, t);
                        woodGain.gain.setValueAtTime(tick.vol * 0.4, t);
                        woodGain.gain.exponentialRampToValueAtTime(0.001, t + 0.025);
                        wood.connect(woodGain);
                        woodGain.connect(this.ctx.destination);
                        wood.start(t);
                        wood.stop(t + 0.03);
                    });

                } else if (emoji === '👏') {
                    // Applaus: Natürliches Händeklatschen (4 gefilterte, gestaffelte Rausch-Impulse)
                    const claps = [0, 0.075, 0.145, 0.22];
                    claps.forEach((offset, idx) => {
                        const t = now + offset;
                        const buf = this.getNoiseBuffer();
                        if (!buf) return;

                        const src = this.ctx.createBufferSource();
                        src.buffer = buf;

                        const bp = this.ctx.createBiquadFilter();
                        bp.type = 'bandpass';
                        bp.frequency.setValueAtTime(1150 + (idx % 2 === 0 ? 80 : -60), t);
                        bp.Q.setValueAtTime(1.8, t);

                        const lp = this.ctx.createBiquadFilter();
                        lp.type = 'lowpass';
                        lp.frequency.setValueAtTime(3600, t);

                        const gain = this.ctx.createGain();
                        gain.gain.setValueAtTime(0.001, t);
                        gain.gain.linearRampToValueAtTime(0.16 + (idx === 3 ? 0.04 : 0), t + 0.004);
                        gain.gain.exponentialRampToValueAtTime(0.001, t + 0.055);

                        src.connect(bp);
                        bp.connect(lp);
                        lp.connect(gain);
                        gain.connect(this.ctx.destination);

                        src.start(t);
                        src.stop(t + 0.06);
                    });

                } else if (emoji === '🥳') {
                    // Feiern: Strahlendes Celesta / Musikdosen-Glockenspiel (aufsteigender Dur-Dreiklang mit Glockenobertönen)
                    const notes = [523.25, 659.25, 783.99, 1046.50]; // C5, E5, G5, C6
                    notes.forEach((freq, idx) => {
                        const t = now + idx * 0.07;

                        // Grundton
                        const osc = this.ctx.createOscillator();
                        const gain = this.ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(freq, t);

                        // Harmonischer Glocken-Oberton (2.76x)
                        const bellOsc = this.ctx.createOscillator();
                        const bellGain = this.ctx.createGain();
                        bellOsc.type = 'sine';
                        bellOsc.frequency.setValueAtTime(freq * 2.756, t);

                        const filter = this.ctx.createBiquadFilter();
                        filter.type = 'lowpass';
                        filter.frequency.setValueAtTime(3200, t);

                        gain.gain.setValueAtTime(0.001, t);
                        gain.gain.linearRampToValueAtTime(0.13, t + 0.008);
                        gain.gain.exponentialRampToValueAtTime(0.001, t + 0.32);

                        bellGain.gain.setValueAtTime(0.001, t);
                        bellGain.gain.linearRampToValueAtTime(0.04, t + 0.005);
                        bellGain.gain.exponentialRampToValueAtTime(0.001, t + 0.16);

                        osc.connect(filter);
                        bellOsc.connect(filter);
                        filter.connect(gain);
                        gain.connect(this.ctx.destination);

                        osc.start(t);
                        bellOsc.start(t);
                        osc.stop(t + 0.35);
                        bellOsc.stop(t + 0.35);
                    });

                } else if (emoji === '😱') {
                    // Schock: Weicher, cineastischer Tiefschlag (Sub-Thud + dezenter Shimmer)
                    const osc = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    const filter = this.ctx.createBiquadFilter();

                    filter.type = 'lowpass';
                    filter.frequency.setValueAtTime(220, now);

                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(95, now);
                    osc.frequency.exponentialRampToValueAtTime(38, now + 0.35);

                    gain.gain.setValueAtTime(0.001, now);
                    gain.gain.linearRampToValueAtTime(0.2, now + 0.015);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.38);

                    osc.connect(filter);
                    filter.connect(gain);
                    gain.connect(this.ctx.destination);

                    osc.start(now);
                    osc.stop(now + 0.4);

                } else if (emoji === '🤔') {
                    // Grübeln: Natürlicher Wassertropfen ("Bloop" / warmer Drop-Sound)
                    const osc = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    const filter = this.ctx.createBiquadFilter();

                    filter.type = 'lowpass';
                    filter.frequency.setValueAtTime(2200, now);

                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(620, now);
                    osc.frequency.exponentialRampToValueAtTime(1380, now + 0.065);
                    osc.frequency.exponentialRampToValueAtTime(1050, now + 0.11);

                    gain.gain.setValueAtTime(0.001, now);
                    gain.gain.linearRampToValueAtTime(0.15, now + 0.012);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.14);

                    osc.connect(filter);
                    filter.connect(gain);
                    gain.connect(this.ctx.destination);

                    osc.start(now);
                    osc.stop(now + 0.15);

                } else if (emoji === '🔥') {
                    // Feuer: Warmer Flammen-Whoosh (gefilterter Rausch-Schwung)
                    const buf = this.getNoiseBuffer();
                    if (!buf) return;

                    const src = this.ctx.createBufferSource();
                    src.buffer = buf;

                    const filter = this.ctx.createBiquadFilter();
                    filter.type = 'bandpass';
                    filter.frequency.setValueAtTime(220, now);
                    filter.frequency.linearRampToValueAtTime(680, now + 0.12);
                    filter.frequency.exponentialRampToValueAtTime(180, now + 0.32);
                    filter.Q.setValueAtTime(2.0, now);

                    const gain = this.ctx.createGain();
                    gain.gain.setValueAtTime(0.001, now);
                    gain.gain.linearRampToValueAtTime(0.18, now + 0.08);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.34);

                    src.connect(filter);
                    filter.connect(gain);
                    gain.connect(this.ctx.destination);

                    src.start(now);
                    src.stop(now + 0.35);
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
