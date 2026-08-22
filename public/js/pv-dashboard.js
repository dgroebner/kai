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
            const fmt = (num) => Math.round(num).toLocaleString('de-DE');

            // --- 1. Werte im Energiefluss-Diagramm aktualisieren ---
            updateFlowValue('[data-flow="pv_power"]', fmt(d.pv_power_w) + ' W');
            updateFlowValue('[data-flow="house_load"]', fmt(d.house_load_w) + ' W');
            updateFlowValue('[data-flow="grid_total"]', fmt(d.grid_total_w) + ' W');
            updateFlowValue('[data-flow="battery_power"]', fmt(Math.abs(d.battery_power_w)) + ' W (' + (d.battery_power_w > 0 ? 'Laden' : 'Entladen') + ')');

            // --- 2. Batterie-SoC aktualisieren & einfärben ---
            const socEl = document.querySelector('[data-live="battery_soc"]');
            if (socEl) {
                const soc = parseInt(d.battery_soc_pct, 10);
                socEl.textContent = soc;

                const parentValEl = socEl.closest('.flow-node').querySelector('.flow-node-value');
                if (parentValEl) {
                    parentValEl.classList.remove('text-danger', 'text-warning', 'text-success');
                    if (soc < 20) parentValEl.classList.add('text-danger');
                    else if (soc <= 50) parentValEl.classList.add('text-warning');
                    else parentValEl.classList.add('text-success');
                }
            }

            // --- 3. Gold-Status: Linien-Animationen & Richtungen anpassen ---
            updateFlowLine('line-pv-house', d.pv_power_w, '#f59e0b', false);

            // Batterie: Positiv = Laden (Strom fließt weg vom Haus in Akku), Negativ = Entladen (Strom fließt zum Haus)
            updateFlowLine('line-bat-house', d.battery_power_w, '#10b981', d.battery_power_w < 0);

            // Netz: Positiv = Bezug (Strom fließt ins Haus), Negativ = Einspeisung (Strom fließt ins Netz)
            updateFlowLine('line-grid-house', d.grid_total_w, '#3b82f6', d.grid_total_w < 0);

            // Header-Timestamp aktualisieren
            const timeSpan = document.querySelector('.last-update-live');
            if (timeSpan && d.last_update) {
                timeSpan.textContent = 'Live-Daten: ' + d.last_update;
            }
        })
        .catch(error => {
            console.debug('Live-Update fehlgeschlagen:', error);
        });
}

function updateFlowValue(selector, htmlContent) {
    const el = document.querySelector(selector);
    if (el) {
        el.innerHTML = htmlContent;
    }
}

function updateFlowLine(elementId, powerValue, baseColor, isReverse) {
    const line = document.getElementById(elementId);
    if (!line) return;

    if (Math.abs(powerValue) < 10) {
        // Fast keine Leistung -> Linie ausblenden oder beruhigen
        line.style.opacity = '0.2';
        line.className.baseVal = 'flow-line';
    } else {
        line.style.opacity = '0.8';
        line.setAttribute('stroke', baseColor);
        // Flussrichtung animieren
        line.className.baseVal = isReverse ? 'flow-line-animated-rev' : 'flow-line-animated';
    }
}