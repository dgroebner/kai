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

    /**
     * Erzeugt ein realistisches, trockenes Keks-Knackgeräusch über die native Web Audio API
     * (völlig autark ohne externe Sounddateien oder Ladezeiten).
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

            const now = audioCtx.currentTime;

            // 1. Trockener Knack-Burst (gefiltertes Rauschen für das Zerbrechen des Teigs)
            const bufferSize = Math.floor(audioCtx.sampleRate * 0.08);
            const buffer = audioCtx.createBuffer(1, bufferSize, audioCtx.sampleRate);
            const data = buffer.getChannelData(0);
            for (let i = 0; i < bufferSize; i++) {
                data[i] = (Math.random() * 2 - 1) * Math.exp(-i / (audioCtx.sampleRate * 0.016));
            }

            const noise = audioCtx.createBufferSource();
            noise.buffer = buffer;

            const bandpass = audioCtx.createBiquadFilter();
            bandpass.type = 'bandpass';
            bandpass.frequency.setValueAtTime(2400, now);
            bandpass.Q.setValueAtTime(1.4, now);

            const noiseGain = audioCtx.createGain();
            noiseGain.gain.setValueAtTime(0.4, now);
            noiseGain.gain.exponentialRampToValueAtTime(0.001, now + 0.07);

            noise.connect(bandpass);
            bandpass.connect(noiseGain);
            noiseGain.connect(audioCtx.destination);
            noise.start(now);

            // 2. Erster Knack-Snap (mechanischer Klick der ersten Bruchlinie)
            const snap1 = audioCtx.createOscillator();
            const snap1Gain = audioCtx.createGain();
            snap1.type = 'triangle';
            snap1.frequency.setValueAtTime(420, now);
            snap1.frequency.exponentialRampToValueAtTime(80, now + 0.035);

            snap1Gain.gain.setValueAtTime(0.3, now);
            snap1Gain.gain.exponentialRampToValueAtTime(0.001, now + 0.035);

            snap1.connect(snap1Gain);
            snap1Gain.connect(audioCtx.destination);
            snap1.start(now);
            snap1.stop(now + 0.04);

            // 3. Zweiter Mikrosnap (16ms später für die zweite Teighälfte)
            const snap2 = audioCtx.createOscillator();
            const snap2Gain = audioCtx.createGain();
            snap2.type = 'triangle';
            snap2.frequency.setValueAtTime(320, now + 0.016);
            snap2.frequency.exponentialRampToValueAtTime(60, now + 0.05);

            snap2Gain.gain.setValueAtTime(0.22, now + 0.016);
            snap2Gain.gain.exponentialRampToValueAtTime(0.001, now + 0.05);

            snap2.connect(snap2Gain);
            snap2Gain.connect(audioCtx.destination);
            snap2.start(now + 0.016);
            snap2.stop(now + 0.055);

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
