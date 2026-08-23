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

            // --- 1. Batterie SoC (%) einfärben & Grafik anpassen ---
            const socEl = document.querySelector('[data-live="battery_soc"]');
            if (socEl) {
                const soc = parseInt(d.battery_soc_pct, 10);
                socEl.textContent = soc;
                socEl.className = '';

                let batteryColor = 'var(--pv-green)'; // Standard / Grün
                if (soc < 20) {
                    socEl.classList.add('soc-red');
                    batteryColor = 'var(--color-red, #ef4444)';
                } else if (soc <= 50) {
                    socEl.classList.add('soc-yellow');
                    batteryColor = 'var(--color-yellow, #f59e0b)';
                } else {
                    socEl.classList.add('soc-green');
                    batteryColor = 'var(--pv-green, #10b981)';
                }

                // SVG-Batterie Füllstand und Farbe aktualisieren (gestuft in 25%-Schritten)
                const batterySvg = document.querySelector('.battery-icon');
                if (batterySvg) {
                    const steppedSoc = Math.round(soc / 25) * 25; // Ergibt exakt 0, 25, 50, 75 oder 100
                    batterySvg.style.color = batteryColor;

                    // Das innere Füll-Rechteck horizontal von links nach rechts steuern
                    const fillRect = batterySvg.querySelector('.battery-level-fill');
                    if (fillRect) {
                        const maxWidth = 12; // Entspricht der maximalen Innenbreite des Rechtecks im SVG
                        const targetWidth = (steppedSoc / 100) * maxWidth;

                        fillRect.setAttribute('width', targetWidth.toString());
                        // Die X-Position bleibt konstant bei 4 (Startpunkt des inneren Füllbereichs)
                        fillRect.setAttribute('x', '4');
                    }
                }
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

            if (batW < 0) { // Strom fließt weg vom Haus in Batterie -> isReverse = true
                batState = 'green';
                batLineColor = '#10b981';
                batSubtext = '(Laden)';
                batRev = true;
            } else if (batW > 0) { // Strom fließt ins Haus -> isReverse = false
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

            updateFlowNode('node-grid', '[data-flow="grid_total_w"]',
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

// 3. Initialisierung des Telemetrie-Charts via data-Attributes
document.addEventListener("DOMContentLoaded", function () {
    const canvas = document.getElementById('telemetryChart');
    if (!canvas) return;

    if (typeof Chart === 'undefined') {
        console.error('Chart.js ist nicht geladen.');
        return;
    }

    try {
        const labels = JSON.parse(canvas.dataset.labels || '[]');
        const pvData = JSON.parse(canvas.dataset.pv || '[]');
        const houseData = JSON.parse(canvas.dataset.house || '[]');
        const gridImport = JSON.parse(canvas.dataset.gridImport || '[]');
        const gridExport = JSON.parse(canvas.dataset.gridExport || '[]');
        const batCharge = JSON.parse(canvas.dataset.batCharge || '[]');
        const batDischarge = JSON.parse(canvas.dataset.batDischarge || '[]');
        const socData = JSON.parse(canvas.dataset.soc || '[]');

        const ctx = canvas.getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'PV (W)',
                        data: pvData,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.1)',
                        borderWidth: 2,
                        pointRadius: 0,
                        tension: 0.2,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Haus (W)',
                        data: houseData,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 2,
                        pointRadius: 0,
                        tension: 0.2,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Netzbezug (W)',
                        data: gridImport,
                        borderColor: '#ef4444',
                        borderWidth: 2,
                        pointRadius: 0,
                        tension: 0.2,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Netzeinspeisung (W)',
                        data: gridExport,
                        borderColor: '#10b981',
                        borderWidth: 2,
                        pointRadius: 0,
                        tension: 0.2,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Batterieladung (W)',
                        data: batCharge,
                        borderColor: '#06b6d4',
                        borderWidth: 1.5,
                        pointRadius: 0,
                        tension: 0.2,
                        hidden: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Batterieentladung (W)',
                        data: batDischarge,
                        borderColor: '#8b5cf6',
                        borderWidth: 1.5,
                        pointRadius: 0,
                        tension: 0.2,
                        hidden: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'SoC (%)',
                        data: socData,
                        borderColor: '#ec4899',
                        borderWidth: 2,
                        pointRadius: 0,
                        tension: 0.2,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        labels: {
                            color: '#94a3b8',
                            font: {size: 11}
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {color: '#94a3b8', maxTicksLimit: 8},
                        grid: {color: 'rgba(255, 255, 255, 0.05)'}
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {display: true, text: 'Leistung (W)', color: '#94a3b8'},
                        ticks: {color: '#94a3b8'},
                        grid: {color: 'rgba(255, 255, 255, 0.05)'}
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        min: 0,
                        max: 100,
                        title: {display: true, text: 'Batterie SoC (%)', color: '#94a3b8'},
                        ticks: {color: '#94a3b8'},
                        grid: {drawOnChartArea: false}
                    }
                }
            }
        });
    } catch (e) {
        console.error('Fehler beim Initialisieren des Telemetrie-Charts:', e);
    }
});

document.addEventListener("DOMContentLoaded", function () {
    const compCanvas = document.getElementById('todayComparisonChart');
    if (compCanvas && typeof Chart !== 'undefined') {
        try {
            const labels = JSON.parse(compCanvas.dataset.labels || '[]');
            const forecastData = JSON.parse(compCanvas.dataset.forecast || '[]');
            const correctedForecastData = JSON.parse(compCanvas.dataset.correctedForecast || '[]'); // 1. Hier auslesen
            const actualData = JSON.parse(compCanvas.dataset.actual || '[]');

            new Chart(compCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Prognose (W)',
                            data: forecastData,
                            borderColor: '#94a3b8', // Farbe etwas dezenter (z.B. Grau), da korrigierte Linie primär
                            backgroundColor: 'transparent',
                            borderWidth: 1.5,
                            borderDash: [4, 4], // Gestrichelte Linie zur Unterscheidung
                            pointRadius: 0,
                            tension: 0.3,
                            fill: false
                        },
                        {
                            label: 'Prognose korrigiert (W)', // 2. Neues Dataset hinzufügen
                            data: correctedForecastData,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            borderWidth: 2,
                            pointRadius: 0,
                            tension: 0.3,
                            fill: true
                        },
                        {
                            label: 'Real (W)',
                            data: actualData,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 2,
                            pointRadius: 3,
                            tension: 0.3,
                            fill: false,
                            spanGaps: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: '#94a3b8',
                                font: {size: 11}
                            }
                        }
                    },
                    scales: {
                        x: {
                            ticks: {color: '#94a3b8', maxTicksLimit: 12},
                            grid: {color: 'rgba(255, 255, 255, 0.05)'}
                        },
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {display: true, text: 'Leistung (W)', color: '#94a3b8'},
                            ticks: {color: '#94a3b8'},
                            grid: {color: 'rgba(255, 255, 255, 0.05)'},
                            min: 0
                        }
                    }
                }
            });
        } catch (e) {
            console.error('Fehler beim Initialisieren des Vergleichs-Charts:', e);
        }
    }
});