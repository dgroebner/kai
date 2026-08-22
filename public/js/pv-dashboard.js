document.addEventListener("DOMContentLoaded", function () {
    // 1. Horizontales Scrollen des stündlichen Balkendiagramms (Mittagsstunde zentrieren)
    requestAnimationFrame(() => {
        const chart = document.querySelector('.bar-chart');
        if (chart) {
            const labels = chart.querySelectorAll('.bar-label');
            let middayCol = null;

            for (let label of labels) {
                if (label.textContent === '12' || label.textContent === '13') {
                    middayCol = label.closest('.bar-col');
                    break;
                }
            }

            if (middayCol) {
                middayCol.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                    inline: 'center'
                });
            }
        }
    });

    // 2. Automatischer Live-Daten-Reload alle 5 Sekunden
    const liveContainer = document.querySelector('.section-title');
    if (liveContainer) {
        setInterval(updateLiveValues, 5000);
    }
});

function updateLiveValues() {
    fetch('api_live.php')
        .then(response => response.json())
        .then(result => {
            if (!result.success || !result.data) return;

            const d = result.data;

            // Hilfsfunktion zum Formatieren von Zahlen (mit Tausendertrennzeichen)
            const fmt = (num) => Math.round(num).toLocaleString('de-DE');

            // Standard-KPIs aktualisieren
            updateKpiValue('[data-live="pv_power"]', fmt(d.pv_power_w) + ' <span class="kpi-unit">W</span>');
            updateKpiValue('[data-live="house_load"]', fmt(d.house_load_w) + ' <span class="kpi-unit">W</span>');
            updateKpiValue('[data-live="grid_total"]', fmt(d.grid_total_w) + ' <span class="kpi-unit">W</span>');
            updateKpiValue('[data-live="battery_power"]', '(' + fmt(d.battery_power_w) + ' W)');

            // Batterie-SoC mit dynamischem Farbwechsel aktualisieren
            const socEl = document.querySelector('[data-live="battery_soc"]');
            if (socEl) {
                const soc = parseInt(d.battery_soc_pct, 10);

                // Wert und Einheit setzen
                socEl.innerHTML = soc + ' <span class="kpi-unit">%</span>';

                // Vorherige Farbklassen entfernen und basierend auf Schwellenwerten neu setzen
                socEl.classList.remove('text-danger', 'text-warning', 'text-success');
                if (soc < 20) {
                    socEl.classList.add('text-danger');
                } else if (soc <= 50) {
                    socEl.classList.add('text-warning');
                } else {
                    socEl.classList.add('text-success');
                }
            }

            // Header-Timestamp aktualisieren, falls vorhanden
            const timeSpan = document.querySelector('.last-update-live');
            if (timeSpan && d.last_update) {
                timeSpan.textContent = 'Live-Daten: ' + d.last_update;
            }
        })
        .catch(error => {
            console.debug('Live-Update fehlgeschlagen:', error);
        });
}

function updateKpiValue(selector, htmlContent) {
    const el = document.querySelector(selector);
    if (el) {
        el.innerHTML = htmlContent;
    }
}