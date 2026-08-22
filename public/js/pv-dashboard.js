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
    const liveContainer = document.querySelector('.section-title'); // oder ein spezifischer Container
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

            // Elemente im Live-KPI-Grid aktualisieren (wir selektieren über die Label-Struktur)
            updateKpiValue('[data-live="pv_power"]', fmt(d.pv_power_w) + ' <span class="kpi-unit">W</span>');
            updateKpiValue('[data-live="house_load"]', fmt(d.house_load_w) + ' <span class="kpi-unit">W</span>');
            updateKpiValue('[data-live="grid_total"]', fmt(d.grid_total_w) + ' <span class="kpi-unit">W</span>');
            updateKpiValue('[data-live="battery_soc"]', d.battery_soc_pct + ' <span class="kpi-unit">%</span>');
            updateKpiValue('[data-live="battery_power"]', '(' + fmt(d.battery_power_w) + ' W)');

            // Header-Timestamp aktualisieren, falls gewünscht
            const timeSpan = document.querySelector('.last-update-live');
            if (timeSpan && d.last_update) {
                timeSpan.textContent = 'Live-Daten: ' + d.last_update;
            }
        })
        .catch(error => {
            // Stille Fehlerbehandlung bei temporären Netzwerkproblemen
            console.debug('Live-Update fehlgeschlagen:', error);
        });
}

function updateKpiValue(selector, htmlContent) {
    const el = document.querySelector(selector);
    if (el) {
        el.innerHTML = htmlContent;
    }
}