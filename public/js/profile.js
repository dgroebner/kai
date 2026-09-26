// public/js/profile.js
// Interaktionen für das Benutzerprofil (Reihenfolge-Sortierung Start-Briefing)

(function () {
    'use strict';

    /**
     * Aktualisiert die Positionsnummern und versteckten Order-Inputs aller Briefing-Zeilen.
     */
    function updateBriefingOrderNumbers(tbody) {
        if (!tbody) {
            return;
        }

        const rows = tbody.querySelectorAll('.js-briefing-sortable-row');
        rows.forEach(function (row, index) {
            const badge = row.querySelector('.js-briefing-pos-badge');
            if (badge) {
                badge.textContent = '#' + (index + 1);
            }

            const input = row.querySelector('.js-briefing-order-input');
            if (input) {
                input.value = (index + 1) * 10;
            }

            const upBtn = row.querySelector('.js-move-briefing-up');
            const downBtn = row.querySelector('.js-move-briefing-down');

            if (upBtn) {
                const isFirst = (index === 0);
                upBtn.disabled = isFirst;
                upBtn.style.opacity = isFirst ? '0.35' : '1';
                upBtn.style.cursor = isFirst ? 'default' : 'pointer';
            }

            if (downBtn) {
                const isLast = (index === rows.length - 1);
                downBtn.disabled = isLast;
                downBtn.style.opacity = isLast ? '0.35' : '1';
                downBtn.style.cursor = isLast ? 'default' : 'pointer';
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const tbody = document.querySelector('.js-briefing-sortable-tbody');
        if (!tbody) {
            return;
        }

        // Initiale Berechnung der Positionen und Button-Zustände
        updateBriefingOrderNumbers(tbody);

        // Event-Delegation für Pfeil-Klicks
        tbody.addEventListener('click', function (e) {
            const upBtn = e.target.closest('.js-move-briefing-up');
            if (upBtn && !upBtn.disabled) {
                const row = upBtn.closest('.js-briefing-sortable-row');
                if (row && row.previousElementSibling) {
                    row.parentNode.insertBefore(row, row.previousElementSibling);
                    updateBriefingOrderNumbers(tbody);
                }
                return;
            }

            const downBtn = e.target.closest('.js-move-briefing-down');
            if (downBtn && !downBtn.disabled) {
                const row = downBtn.closest('.js-briefing-sortable-row');
                if (row && row.nextElementSibling) {
                    row.parentNode.insertBefore(row.nextElementSibling, row);
                    updateBriefingOrderNumbers(tbody);
                }
            }
        });
    });
})();
