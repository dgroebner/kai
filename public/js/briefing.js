// public/js/briefing.js
// Daily-Briefing-Popup für das Dashboard (Kai Toolset)

(function () {
    'use strict';

    const STORAGE_LAST_SEEN_KEY = 'kai_briefing_last_seen';
    const STORAGE_COOLDOWN_KEY = 'kai_briefing_cooldown_hours';
    let overlayElement = null;
    let isFetching = false;

    /**
     * Speichert den aktuellen Zeitstempel der Anzeige im localStorage.
     */
    function markBriefingSeen() {
        try {
            localStorage.setItem(STORAGE_LAST_SEEN_KEY, Date.now().toString());
            sessionStorage.removeItem('kai_briefing_seen');
        } catch (_) {
            // Storage quota oder disabled
        }
    }

    /**
     * Erstellt oder aktualisiert das Modal im DOM und zeigt es an.
     */
    function renderBriefingModal(briefingData) {
        if (!briefingData || !Array.isArray(briefingData.widgets) || briefingData.widgets.length === 0) {
            return;
        }

        if (!overlayElement) {
            overlayElement = document.createElement('div');
            overlayElement.className = 'briefing-overlay';
            overlayElement.setAttribute('id', 'briefing-modal');
            overlayElement.setAttribute('role', 'dialog');
            overlayElement.setAttribute('aria-modal', 'true');
            overlayElement.setAttribute('aria-labelledby', 'briefing-modal-title');
            document.body.appendChild(overlayElement);

            // Event-Delegation für Schließen und Klick auf Kacheln
            overlayElement.addEventListener('click', function (e) {
                // Klick auf Close-Button oder Backdrop
                if (e.target.closest('.js-briefing-close') || e.target === overlayElement) {
                    closeBriefingModal();
                    return;
                }

                // Klick auf ein Widget
                const widget = e.target.closest('.js-briefing-widget');
                if (widget && widget.dataset.url) {
                    window.location.href = widget.dataset.url;
                }
            });

            // ESC-Taste zum Schließen
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlayElement && overlayElement.classList.contains('is-active')) {
                    closeBriefingModal();
                }
            });
        }

        // HTML-Struktur der Widgets generieren
        const widgetsHtml = briefingData.widgets.map(function (widget) {
            const highlightClass = widget.highlight ? (widget.badge && widget.badge.type === 'danger' ? 'briefing-widget--danger' : 'briefing-widget--highlight') : '';
            const safeTitle = KaiHtml.escape(widget.title || '');
            const safeHeadline = KaiHtml.escape(widget.headline || '');
            const safeSubtitle = KaiHtml.escape(widget.subtitle || '');
            const safeUrl = KaiHtml.escape(widget.url || '#');
            const safeIcon = KaiHtml.escape(widget.icon || '📌');

            let badgeHtml = '';
            if (widget.badge && widget.badge.text) {
                const badgeType = KaiHtml.escape(widget.badge.type || 'info');
                const badgeText = KaiHtml.escape(widget.badge.text);
                badgeHtml = `<span class="briefing-widget-badge briefing-widget-badge--${badgeType}">${badgeText}</span>`;
            }

            let pillsHtml = '';
            if (Array.isArray(widget.pills) && widget.pills.length > 0) {
                const pillItems = widget.pills.map(function (pill) {
                    const pillType = KaiHtml.escape(pill.type || 'neutral');
                    const pillIcon = KaiHtml.escape(pill.icon || '');
                    const pillLabel = KaiHtml.escape(pill.label || '');
                    return `<span class="briefing-pill briefing-pill--${pillType}">
                        ${pillIcon ? `<span class="briefing-pill-icon">${pillIcon}</span>` : ''}
                        <span>${pillLabel}</span>
                    </span>`;
                }).join('');
                pillsHtml = `<div class="briefing-widget-pills">${pillItems}</div>`;
            }

            return `
                <div class="briefing-widget ${highlightClass} js-briefing-widget" data-url="${safeUrl}" tabindex="0" role="button">
                    <div class="briefing-widget-top">
                        <div class="briefing-widget-meta">
                            <span class="briefing-widget-icon">${safeIcon}</span>
                            <span class="briefing-widget-title">${safeTitle}</span>
                        </div>
                        ${badgeHtml}
                    </div>
                    <div class="briefing-widget-main">
                        <div class="briefing-widget-headline">${safeHeadline}</div>
                        <div class="briefing-widget-subtitle">${safeSubtitle}</div>
                        ${pillsHtml}
                    </div>
                </div>
            `;
        }).join('');

        const currentDateStr = new Intl.DateTimeFormat('de-DE', {
            weekday: 'long',
            day: 'numeric',
            month: 'long'
        }).format(new Date());

        overlayElement.innerHTML = `
            <div class="briefing-card" role="document">
                <div class="briefing-header">
                    <div class="briefing-header-left">
                        <span class="briefing-header-icon">☀️</span>
                        <div>
                            <h2 class="briefing-header-title" id="briefing-modal-title">Daily Briefing</h2>
                            <span class="briefing-header-sub">${KaiHtml.escape(currentDateStr)}</span>
                        </div>
                    </div>
                    <button type="button" class="briefing-close-btn js-briefing-close" aria-label="Schließen">&times;</button>
                </div>
                <div class="briefing-body">
                    <div class="briefing-grid">
                        ${widgetsHtml}
                    </div>
                </div>
                <div class="briefing-footer">
                    <button type="button" class="btn btn-save briefing-btn-dismiss js-briefing-close">
                        Verstanden &amp; Weiter zum Dashboard
                    </button>
                </div>
            </div>
        `;

        // Einblenden und als gesehen markieren
        requestAnimationFrame(function () {
            overlayElement.classList.add('is-active');
            document.body.style.overflow = 'hidden';
            markBriefingSeen();
        });
    }

    /**
     * Schließt das Modal und aktualisiert den Zeitstempel.
     */
    function closeBriefingModal() {
        if (!overlayElement) {
            return;
        }
        overlayElement.classList.remove('is-active');
        document.body.style.overflow = '';
        markBriefingSeen();
    }

    /**
     * Lädt den Briefing-Payload vom Backend und rendert das Modal.
     */
    async function loadBriefing(forceShow = false) {
        if (isFetching) {
            return;
        }

        if (!forceShow) {
            try {
                const lastSeen = parseInt(localStorage.getItem(STORAGE_LAST_SEEN_KEY) || '0', 10);
                const cachedCooldownHours = parseFloat(localStorage.getItem(STORAGE_COOLDOWN_KEY) || '3');
                const cooldownMs = cachedCooldownHours * 60 * 60 * 1000;
                if (lastSeen > 0 && (Date.now() - lastSeen < cooldownMs)) {
                    return; // Cooldown noch aktiv
                }
            } catch (_) {
            }
        }

        isFetching = true;
        try {
            const response = await fetch('/system/api.php?action=briefing', {
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                return;
            }

            const json = await response.json();
            if (json && json.success && json.data) {
                // Wenn nicht manuell erzwungen und der Benutzer das Popup im Profil deaktiviert hat
                if (!forceShow && json.data.popup_enabled === false) {
                    return;
                }

                // Cooldown-Wert vom Server im Client sichern
                const serverCooldownHours = typeof json.data.cooldown_hours === 'number' ? json.data.cooldown_hours : 3;
                try {
                    localStorage.setItem(STORAGE_COOLDOWN_KEY, String(serverCooldownHours));
                } catch (_) {
                }

                // Zweite Prüfung mit dem aktuellen Cooldown vom Server
                if (!forceShow) {
                    try {
                        const lastSeen = parseInt(localStorage.getItem(STORAGE_LAST_SEEN_KEY) || '0', 10);
                        const cooldownMs = serverCooldownHours * 60 * 60 * 1000;
                        if (lastSeen > 0 && (Date.now() - lastSeen < cooldownMs)) {
                            return;
                        }
                    } catch (_) {
                    }
                }

                renderBriefingModal(json.data);
            }
        } catch (e) {
            console.warn('Briefing konnte nicht geladen werden:', e);
        } finally {
            isFetching = false;
        }
    }

    // Initialisierung beim Laden der Seite
    document.addEventListener('DOMContentLoaded', function () {
        // Automatischer Aufruf beim Session-Start
        loadBriefing(false);

        // Manueller Trigger über Header-Button
        const triggerBtn = document.getElementById('open-briefing-btn');
        if (triggerBtn) {
            triggerBtn.addEventListener('click', function (e) {
                e.preventDefault();
                loadBriefing(true);
            });
        }
    });

})();
