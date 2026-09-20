/**
 * Gamification Tooltip & Hint Handler
 *
 * Bietet elegante und responsive Mouse-Over (Desktop) sowie Tap/Touch-Events (Mobil)
 * für Hinweistexte und Erklärungen (z. B. "Koch-Tag", "Keine Rettung").
 * 
 * Verwendet Event Delegation auf 'data-gamif-tooltip' und 'data-gamif-title'.
 */
(() => {
    'use strict';

    let tooltipEl = null;
    let headerEl = null;
    let titleEl = null;
    let closeBtn = null;
    let bodyEl = null;
    let arrowEl = null;

    let activeTarget = null;
    let isPinned = false; // true bei Klick/Tap (Mobil)

    function initTooltipDOM() {
        if (tooltipEl) return;

        tooltipEl = document.createElement('div');
        tooltipEl.className = 'gamif-tooltip';
        tooltipEl.setAttribute('role', 'tooltip');
        tooltipEl.setAttribute('aria-hidden', 'true');

        // Header
        headerEl = document.createElement('div');
        headerEl.className = 'gamif-tooltip-header';

        titleEl = document.createElement('span');
        titleEl.className = 'gamif-tooltip-title';

        closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'gamif-tooltip-close';
        closeBtn.setAttribute('aria-label', 'Schließen');
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            hideTooltip(true);
        });

        headerEl.appendChild(titleEl);
        headerEl.appendChild(closeBtn);

        // Body
        bodyEl = document.createElement('div');
        bodyEl.className = 'gamif-tooltip-body';

        // Arrow
        arrowEl = document.createElement('div');
        arrowEl.className = 'gamif-tooltip-arrow';

        tooltipEl.appendChild(headerEl);
        tooltipEl.appendChild(bodyEl);
        tooltipEl.appendChild(arrowEl);

        document.body.appendChild(tooltipEl);
    }

    function positionTooltip(target) {
        if (!tooltipEl || !target) return;

        const targetRect = target.getBoundingClientRect();
        const ttRect = tooltipEl.getBoundingClientRect();
        const spacing = 8;
        const padding = 12;

        // Horizontale Ausrichtung: Zentriert über dem Ziel-Badge
        const targetCenterX = targetRect.left + (targetRect.width / 2);
        let left = targetCenterX - (ttRect.width / 2);

        // Viewport-Kanten respektieren
        const maxLeft = window.innerWidth - ttRect.width - padding;
        left = Math.max(padding, Math.min(maxLeft, left));

        // Vertikale Ausrichtung: Bevorzugt darüber
        const spaceAbove = targetRect.top;
        const spaceBelow = window.innerHeight - targetRect.bottom;
        let top = 0;
        let placeAbove = true;

        if (spaceAbove >= ttRect.height + spacing + 10) {
            top = targetRect.top - ttRect.height - spacing;
            placeAbove = true;
        } else if (spaceBelow >= ttRect.height + spacing) {
            top = targetRect.bottom + spacing;
            placeAbove = false;
        } else {
            // Wenn Platz knapp, dort platzieren wo mehr Raum ist
            if (spaceAbove >= spaceBelow) {
                top = Math.max(padding, targetRect.top - ttRect.height - spacing);
                placeAbove = true;
            } else {
                top = targetRect.bottom + spacing;
                placeAbove = false;
            }
        }

        tooltipEl.style.left = `${Math.round(left)}px`;
        tooltipEl.style.top = `${Math.round(top)}px`;

        // Pfeil zum Ziel ausrichten
        let arrowLeft = targetCenterX - left;
        arrowLeft = Math.max(12, Math.min(ttRect.width - 12, arrowLeft));
        arrowEl.style.left = `${Math.round(arrowLeft)}px`;

        if (placeAbove) {
            arrowEl.className = 'gamif-tooltip-arrow gamif-tooltip-arrow--bottom';
        } else {
            arrowEl.className = 'gamif-tooltip-arrow gamif-tooltip-arrow--top';
        }
    }

    function showTooltip(target, pin = false) {
        initTooltipDOM();

        const text = target.getAttribute('data-gamif-tooltip') || '';
        const title = target.getAttribute('data-gamif-title') || '';

        if (!text) return;

        if (activeTarget && activeTarget !== target) {
            activeTarget.classList.remove('is-active');
        }

        activeTarget = target;
        isPinned = pin;
        target.classList.add('is-active');

        // Inhalt via textContent (XSS-sicher)
        if (title) {
            titleEl.textContent = title;
            headerEl.style.display = 'flex';
        } else {
            headerEl.style.display = 'none';
        }
        bodyEl.textContent = text;

        tooltipEl.classList.add('is-visible');
        tooltipEl.setAttribute('aria-hidden', 'false');

        positionTooltip(target);
    }

    function hideTooltip(force = false) {
        if (!tooltipEl || !activeTarget) return;
        if (isPinned && !force) return;

        activeTarget.classList.remove('is-active');
        tooltipEl.classList.remove('is-visible');
        tooltipEl.setAttribute('aria-hidden', 'true');
        activeTarget = null;
        isPinned = false;
    }

    // Event Delegation
    document.addEventListener('mouseover', (e) => {
        const target = e.target.closest('[data-gamif-tooltip]');
        if (!target) return;
        if (isPinned && activeTarget === target) return;
        showTooltip(target, false);
    });

    document.addEventListener('mouseout', (e) => {
        const target = e.target.closest('[data-gamif-tooltip]');
        if (!target) return;
        if (isPinned) return;
        if (e.relatedTarget && target.contains(e.relatedTarget)) return;
        hideTooltip(false);
    });

    document.addEventListener('click', (e) => {
        const target = e.target.closest('[data-gamif-tooltip]');
        if (target) {
            e.preventDefault();
            e.stopPropagation();

            if (activeTarget === target && isPinned) {
                hideTooltip(true);
            } else {
                showTooltip(target, true);
            }
            return;
        }

        // Klick außerhalb schließt gepinnten Tooltip
        if (tooltipEl && !tooltipEl.contains(e.target)) {
            hideTooltip(true);
        }
    });

    // Tastatur-Bedienung: Escape schließt
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            hideTooltip(true);
        }
    });

    // Repositionieren bei Resize & Scroll
    window.addEventListener('resize', () => {
        if (activeTarget && tooltipEl && tooltipEl.classList.contains('is-visible')) {
            positionTooltip(activeTarget);
        }
    }, { passive: true });

    window.addEventListener('scroll', () => {
        if (activeTarget && tooltipEl && tooltipEl.classList.contains('is-visible')) {
            if (!isPinned) {
                hideTooltip(false);
            } else {
                positionTooltip(activeTarget);
            }
        }
    }, { passive: true });

})();
