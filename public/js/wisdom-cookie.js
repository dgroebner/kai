// public/js/wisdom-cookie.js
// Interaktiver Glückskeks & Papierrollen-Popup für Konfuzius' Weisheit des Tages

(function () {
    'use strict';

    const STORAGE_KEY_PREFIX = 'kai_fortune_cookie_opened_';

    /**
     * Liefert den Storage-Key für das heutige Datum im Format YYYY-MM-DD (lokale Zeit).
     */
    function getTodayKey() {
        const today = new Date();
        const year = today.getFullYear();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');
        return STORAGE_KEY_PREFIX + year + '-' + month + '-' + day;
    }

    /**
     * Entfernt veraltete Cookie-Einträge älterer Tage aus dem localStorage.
     */
    function cleanupOldStorageKeys(todayKey) {
        try {
            const keysToRemove = [];
            for (let i = 0; i < localStorage.length; i++) {
                const k = localStorage.key(i);
                if (k && k.startsWith(STORAGE_KEY_PREFIX) && k !== todayKey) {
                    keysToRemove.push(k);
                }
            }
            keysToRemove.forEach(k => localStorage.removeItem(k));
        } catch (_) {
            // Storage evtl. deaktiviert
        }
    }

    /**
     * Prüft, ob der Keks heute bereits geöffnet wurde.
     */
    function isCookieOpenedToday() {
        try {
            return localStorage.getItem(getTodayKey()) === '1';
        } catch (_) {
            return false;
        }
    }

    /**
     * Markiert den Keks für heute als geöffnet.
     */
    function markCookieOpenedToday() {
        try {
            localStorage.setItem(getTodayKey(), '1');
        } catch (_) {
            // Ignorieren falls nicht verfügbar
        }
    }

    /**
     * Initialisiert den Keks-Status im DOM.
     */
    function initCookieState() {
        const trigger = document.querySelector('.js-wisdom-trigger');
        if (!trigger) return;

        const todayKey = getTodayKey();
        cleanupOldStorageKeys(todayKey);

        if (isCookieOpenedToday()) {
            setVisualOpenedState(trigger);
        }
    }

    /**
     * Aktualisiert die visuelle Darstellung des Kekses auf "bereits geöffnet".
     */
    function setVisualOpenedState(trigger) {
        trigger.classList.add('is-opened');
        trigger.setAttribute('aria-expanded', 'false');

        const desc = trigger.querySelector('.js-cookie-desc');
        if (desc) {
            desc.textContent = 'Der Keks für heute ist geknackt – klicke, um die Schriftrolle erneut zu lesen.';
        }

        const btn = trigger.querySelector('.js-cookie-btn');
        if (btn) {
            btn.innerHTML = '📜 Schriftrolle lesen';
            btn.classList.add('btn-opened');
        }

        const hint = document.querySelector('.js-wisdom-hint');
        if (hint) {
            hint.textContent = '📜 Bereits geöffnet';
        }
    }

    /**
     * Öffnet das Papierrollen-Popup.
     */
    function openWisdomModal() {
        const modal = document.getElementById('wisdom-modal');
        if (!modal) return;

        modal.removeAttribute('hidden');
        // Nächster Frame für geschmeidige CSS-Transition
        requestAnimationFrame(() => {
            modal.classList.add('is-active');
        });
        document.body.classList.add('wisdom-modal-open');

        // Fokus auf Schließen-Button für Barrierefreiheit
        const closeBtn = modal.querySelector('.wisdom-modal-close-btn');
        if (closeBtn) {
            closeBtn.focus();
        }
    }

    /**
     * Schließt das Papierrollen-Popup.
     */
    function closeWisdomModal() {
        const modal = document.getElementById('wisdom-modal');
        if (!modal || modal.hasAttribute('hidden')) return;

        modal.classList.remove('is-active');
        document.body.classList.remove('wisdom-modal-open');

        setTimeout(() => {
            modal.setAttribute('hidden', '');
            // Fokus zurück auf den Keks-Trigger
            const trigger = document.querySelector('.js-wisdom-trigger');
            if (trigger) {
                trigger.focus();
            }
        }, 260);
    }

    let audioCtx = null;
    let cookieCrunchBuffer = null;

    /**
     * Erzeugt einen AudioBuffer für ein authentisches, trockenes Cracker-/Glückskeks-Knacken.
     * Nutzt kaskadierende Mikrosnaps im Bereich 2.000 - 6.000 Hz und schneidet
     * tiefe Frequenzen (die wie ein "Schlag auf den Tisch" klingen würden) komplett ab.
     */
    function createCookieCrunchBuffer(ctx) {
        const sampleRate = ctx.sampleRate;
        const duration = 0.16; // 160ms trockener Bruch
        const numSamples = Math.floor(sampleRate * duration);
        const buffer = ctx.createBuffer(1, numSamples, sampleRate);
        const data = buffer.getChannelData(0);

        // Kaskadierende Mikrosnaps für das Zersplittern eines trockenen Keksgebäcks
        const snaps = [
            { t: 0.000, dur: 0.014, freqStart: 4800, freqEnd: 2600, amp: 0.85 },
            { t: 0.008, dur: 0.009, freqStart: 5900, freqEnd: 3400, amp: 0.55 },
            { t: 0.020, dur: 0.011, freqStart: 4100, freqEnd: 2200, amp: 0.65 },
            { t: 0.036, dur: 0.016, freqStart: 4500, freqEnd: 2000, amp: 0.95 }, // Zweite Kekshälfte bricht durch
            { t: 0.052, dur: 0.010, freqStart: 5400, freqEnd: 3000, amp: 0.45 },
            { t: 0.068, dur: 0.009, freqStart: 4600, freqEnd: 2800, amp: 0.35 },
            { t: 0.088, dur: 0.008, freqStart: 5100, freqEnd: 3200, amp: 0.25 },
            { t: 0.108, dur: 0.006, freqStart: 4400, freqEnd: 3000, amp: 0.15 }
        ];

        for (const snap of snaps) {
            const startSample = Math.floor(snap.t * sampleRate);
            const snapLen = Math.floor(snap.dur * sampleRate);
            for (let i = 0; i < snapLen; i++) {
                const idx = startSample + i;
                if (idx >= numSamples) break;
                const p = i / snapLen;
                const freq = snap.freqStart + (snap.freqEnd - snap.freqStart) * p;
                const env = Math.pow(1 - p, 2.8); // Steiler, knackiger Decay
                const osc = Math.sin(2 * Math.PI * freq * (i / sampleRate));
                const crackNoise = (Math.random() * 2 - 1) * 0.8;
                data[idx] += (osc * 0.35 + crackNoise * 0.65) * snap.amp * env;
            }
        }

        // Heller, feiner Brösel-Teppich (White Noise, steil hochpassgefiltert)
        let lastNoise = 0;
        for (let i = 0; i < numSamples; i++) {
            const t = i / sampleRate;
            let env = 0;
            if (t < 0.035) {
                env = t / 0.035;
            } else if (t < 0.14) {
                env = Math.pow(1 - (t - 0.035) / 0.105, 2.2);
            }
            const n = Math.random() * 2 - 1;
            // Hochpass: Schneidet alle tiefen Frequenzen unter 1500 Hz ab
            const hp = n - lastNoise * 0.82;
            lastNoise = n;
            data[i] += hp * env * 0.22;
        }

        // Normalisieren & Headroom sicherstellen (kein Clipping)
        let max = 0;
        for (let i = 0; i < numSamples; i++) {
            const abs = Math.abs(data[i]);
            if (abs > max) max = abs;
        }
        if (max > 0) {
            for (let i = 0; i < numSamples; i++) {
                data[i] = (data[i] / max) * 0.75;
            }
        }

        return buffer;
    }

    /**
     * Spielt das realistische Keks-Knacken ab.
     */
    function playCookieSnapSound() {
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) return;

            if (!audioCtx) {
                audioCtx = new AudioContextClass();
            }
            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }

            if (!cookieCrunchBuffer) {
                cookieCrunchBuffer = createCookieCrunchBuffer(audioCtx);
            }

            const source = audioCtx.createBufferSource();
            source.buffer = cookieCrunchBuffer;

            // Zusätzlicher leichter Hochpass-Filter als Sicherheitsnetz gegen Bass-Mumpf
            const highpass = audioCtx.createBiquadFilter();
            highpass.type = 'highpass';
            highpass.frequency.setValueAtTime(1400, audioCtx.currentTime);

            const gain = audioCtx.createGain();
            gain.gain.setValueAtTime(0.7, audioCtx.currentTime);

            source.connect(highpass);
            highpass.connect(gain);
            gain.connect(audioCtx.destination);

            source.start(0);
        } catch (_) {
            // Stille bei Geräten/Browsern ohne Audioberechtigung
        }
    }

    /**
     * Behandelt das Knacken des Kekses.
     */
    function handleCookieClick(trigger) {
        if (!trigger) return;

        const wasOpened = trigger.classList.contains('is-opened');

        if (!wasOpened) {
            // Knackgeräusch abspielen & Knack-Animation starten
            playCookieSnapSound();
            trigger.classList.add('is-cracking');
            
            setTimeout(() => {
                trigger.classList.remove('is-cracking');
                markCookieOpenedToday();
                setVisualOpenedState(trigger);
                openWisdomModal();
            }, 380);
        } else {
            // Bereits geknackt: Sofort Schriftrolle anzeigen
            openWisdomModal();
        }
    }

    /**
     * Setzt den Keks in den ungeöffneten Ausgangszustand zurück (Admin-Debug).
     */
    function resetCookieState() {
        try {
            localStorage.removeItem(getTodayKey());
        } catch (_) {}

        const trigger = document.querySelector('.js-wisdom-trigger');
        if (trigger) {
            trigger.classList.remove('is-opened', 'is-cracking');
            trigger.setAttribute('aria-expanded', 'false');

            const desc = trigger.querySelector('.js-cookie-desc');
            if (desc) {
                desc.textContent = 'Knacke den Keks, um Konfuzius\' Weisheit für heute zu enthüllen.';
            }

            const btn = trigger.querySelector('.js-cookie-btn');
            if (btn) {
                btn.innerHTML = '🥠 Keks öffnen';
                btn.classList.remove('btn-opened');
            }

            const hint = document.querySelector('.js-wisdom-hint');
            if (hint) {
                hint.textContent = '🥠 Glückskeks';
            }
        }

        closeWisdomModal();
    }

    // Event Delegation
    document.addEventListener('click', function (e) {
        // Admin-Reset-Button (Keks wieder verschließen)
        const resetBtn = e.target.closest('.js-wisdom-reset');
        if (resetBtn) {
            e.preventDefault();
            resetBtn.classList.add('is-resetting');
            setTimeout(() => resetBtn.classList.remove('is-resetting'), 420);
            resetCookieState();
            return;
        }

        // Klick auf Schließen-Button oder Backdrop
        if (e.target.closest('.js-wisdom-close')) {
            e.preventDefault();
            closeWisdomModal();
            return;
        }

        // Klick auf den Keks (Trigger oder Button im Keks)
        const trigger = e.target.closest('.js-wisdom-trigger');
        if (trigger) {
            e.preventDefault();
            handleCookieClick(trigger);
        }
    });

    // Tastatur-Bedienung: Escape schließt das Modal
    document.addEventListener('keydown', function (e) {
        const modal = document.getElementById('wisdom-modal');
        const isModalOpen = modal && !modal.hasAttribute('hidden') && modal.classList.contains('is-active');

        if (e.key === 'Escape' && isModalOpen) {
            e.preventDefault();
            closeWisdomModal();
            return;
        }

        // Enter oder Leertaste auf fokussiertem Keks
        if ((e.key === 'Enter' || e.key === ' ') && document.activeElement && document.activeElement.classList.contains('js-wisdom-trigger')) {
            e.preventDefault();
            handleCookieClick(document.activeElement);
        }
    });

    // Initialisierung bei DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCookieState);
    } else {
        initCookieState();
    }
})();
