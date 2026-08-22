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

            // --- 1. Batterie SoC (%) einfärben ---
            const socEl = document.querySelector('[data-live="battery_soc"]');
            if (socEl) {
                const soc = parseInt(d.battery_soc_pct, 10);
                socEl.textContent = soc;
                socEl.className = '';
                if (soc < 20) socEl.classList.add('soc-red');
                else if (soc <= 50) socEl.classList.add('soc-yellow');
                else socEl.classList.add('soc-green');
            }

            // --- 2. Hauslast ---
            updateFlowValue('[data-flow="house_load"]', fmt(d.house_load_w) + ' W');

            // --- 3. PV Anlage (Grün bei Produktion, sonst Grau) ---
            const pvW = Math.round(d.pv_power_w);
            updateFlowNode('node-pv', '[data-flow="pv_power"]',
                fmt(pvW) + ' W',
                pvW > 0 ? 'green' : 'gray',
                'line-pv-house', pvW > 0 ? '#10b981' : '#334155', false, pvW > 0
            );

            // --- 4. Batterie (Grün = Laden, Gelb = Entladen, Grau = 0) ---
            const batW = Math.round(d.battery_power_w);
            let batState = 'gray', batLineColor = '#334155', batSubtext = '', batRev = false;

            if (batW > 0) { // Strom fließt weg vom Haus in Batterie -> isReverse = true
                batState = 'green';
                batLineColor = '#10b981';
                batSubtext = '(Laden)';
                batRev = true;
            } else if (batW < 0) { // Strom fließt ins Haus -> isReverse = false
                batState = 'yellow';
                batLineColor = '#f59e0b';
                batSubtext = '(Entladen)';
                batRev = false;
            }

            updateFlowNode('node-battery', '[data-flow="battery_power"]',
                fmt(Math.abs(batW)) + ' W',
                batState, 'line-bat-house', batLineColor, batRev, batW !== 0, 'bat-subtext', batSubtext
            );

            // --- 5. Netz (Gelb = Beziehen, Grün = Einspeisen, Grau = 0) ---
            const gridW = Math.round(d.grid_total_w);
            let gridState = 'gray', gridLineColor = '#334155', gridSubtext = '', gridRev = false;

            if (gridW > 0) { // Strom fließt ins Haus -> isReverse = false
                gridState = 'yellow';
                gridLineColor = '#f59e0b';
                gridSubtext = '(Bezug)';
                gridRev = false;
            } else if (gridW < 0) { // Strom fließt weg vom Haus ins Netz -> isReverse = true
                gridState = 'green';
                gridLineColor = '#10b981';
                gridSubtext = '(Einspeisung)';
                gridRev = true;
            }

            updateFlowNode('node-grid', '[data-flow="grid_total"]',
                fmt(Math.abs(gridW)) + ' W',
                gridState, 'line-grid-house', gridLineColor, gridRev, gridW !== 0, 'grid-subtext', gridSubtext
            );

            // --- Header Update ---
            const timeSpan = document.querySelector('.last-update-live');
            if (timeSpan && d.last_update) {
                timeSpan.textContent = 'Live-Daten: ' + d.last_update;
            }
        })
        .catch(error => console.debug('Live-Update fehlgeschlagen:', error));
}

function updateFlowValue(selector, htmlContent) {
    const el = document.querySelector(selector);
    if (el) el.innerHTML = htmlContent;
}

// Neue Helferfunktion, steuert Box-Rahmen, Box-Wertfarbe, Subtext und Linienanimation gleichzeitig!
function updateFlowNode(nodeId, valSelector, valText, stateName, lineId, lineColor, isReverse, isAnimated, subtextId = null, subtextStr = '') {
    const node = document.getElementById(nodeId);
    if (node) {
        node.classList.remove('state-green', 'state-yellow', 'state-gray');
        node.classList.add('state-' + stateName);

        const valEl = node.querySelector(valSelector);
        if (valEl) {
            valEl.textContent = valText;
            valEl.classList.remove('val-green', 'val-yellow', 'val-gray');
            valEl.classList.add('val-' + stateName);
        }

        if (subtextId) {
            const sub = document.getElementById(subtextId);
            if (sub) sub.textContent = subtextStr;
        }
    }

    const line = document.getElementById(lineId);
    if (line) {
        line.setAttribute('stroke', lineColor);
        if (isAnimated) {
            line.className.baseVal = isReverse ? 'flow-line-animated-rev' : 'flow-line-animated';
        } else {
            line.className.baseVal = 'flow-line';
        }
    }
}