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
            desc.textContent = 'Dein Keks für heute ist geknackt – klicke, um deine Schriftrolle erneut zu lesen.';
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

    /**
     * Behandelt das Knacken des Kekses.
     */
    function handleCookieClick(trigger) {
        if (!trigger) return;

        const wasOpened = trigger.classList.contains('is-opened');

        if (!wasOpened) {
            // Erstmaliges Knacken: Knack-Animation abspielen
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

    // Event Delegation
    document.addEventListener('click', function (e) {
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
