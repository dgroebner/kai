document.addEventListener('DOMContentLoaded', () => {
    const btnDiorama = document.getElementById('btn-tab-diorama');
    const btnDashboard = document.getElementById('btn-tab-dashboard');
    const tabDiorama = document.getElementById('tab-diorama');
    const tabDashboard = document.getElementById('tab-dashboard');
    const btnHistory = document.getElementById('btn-tab-history');
    const tabHistory = document.getElementById('tab-history');

    if (!btnDiorama || !btnDashboard || !tabDiorama || !tabDashboard) return;

    function switchWeatherTab(tab) {
        btnDiorama.className = 'btn btn-outline';
        btnDashboard.className = 'btn btn-outline';
        if (btnHistory) btnHistory.className = 'btn btn-outline';

        tabDiorama.classList.add('hidden');
        tabDashboard.classList.add('hidden');
        if (tabHistory) tabHistory.classList.add('hidden');

        const url = new URL(window.location);

        if (tab === 'dashboard') {
            btnDashboard.className = 'btn';
            tabDashboard.classList.remove('hidden');
            url.searchParams.set('tab', 'dashboard');
        } else if (tab === 'history') {
            if (btnHistory) btnHistory.className = 'btn';
            if (tabHistory) tabHistory.classList.remove('hidden');
            url.searchParams.set('tab', 'history');
            initWeatherHistoryChart();
        } else {
            btnDiorama.className = 'btn';
            tabDiorama.classList.remove('hidden');
            url.searchParams.delete('tab');
        }

        window.history.pushState({}, '', url);
    }

    btnDiorama.addEventListener('click', (e) => { e.preventDefault(); switchWeatherTab('diorama'); });
    btnDashboard.addEventListener('click', (e) => { e.preventDefault(); switchWeatherTab('dashboard'); });
    if (btnHistory) {
        btnHistory.addEventListener('click', (e) => { e.preventDefault(); switchWeatherTab('history'); });
    }

    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') === 'dashboard') {
        switchWeatherTab('dashboard');
    } else if (params.get('tab') === 'history') {
        switchWeatherTab('history');
    }

    // Wind- und Böensteuerung initialisieren
    initWindGustController();

    // Debug-Modal Event-Handler initialisieren
    initDioramaDebugModal();
});

// Automatischer Reload alle 5 Minuten
setTimeout(() => { window.location.reload(); }, 300000);

/**
 * Initialisiert die Event-Listener für das Diorama Debug-Modal.
 * Trennt strikt HTML und JS gemäß AGENTS.md.
 */
function initDioramaDebugModal() {
    const btnOpen = document.getElementById('diorama-debug-open');
    const btnClose = document.getElementById('diorama-debug-close');
    const modal = document.getElementById('diorama-debug-modal');

    if (!btnOpen || !modal) return;

    // Öffnen
    btnOpen.addEventListener('click', (e) => {
        e.preventDefault();
        modal.removeAttribute('hidden');
    });

    // Schließen via Button
    if (btnClose) {
        btnClose.addEventListener('click', (e) => {
            e.preventDefault();
            modal.setAttribute('hidden', '');
        });
    }

    // Schließen via Klick auf den Hintergrund
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.setAttribute('hidden', '');
        }
    });

    // Live-Update der Werte-Anzeigen bei Range-Slidern
    const ranges = modal.querySelectorAll('input[type="range"][data-output]');
    ranges.forEach(range => {
        range.addEventListener('input', (e) => {
            const outputId = e.target.getAttribute('data-output');
            const suffix = e.target.getAttribute('data-suffix') || '';
            const outputEl = document.getElementById(outputId);
            if (outputEl) {
                outputEl.textContent = e.target.value + suffix;
            }
        });
    });
}


/**
 * Wind- und Boen-Controller fuer das SVG-Diorama.
 * Liest Winddaten aus data-Attributen des .diorama-container,
 * berechnet Neigungswinkel und Intervalle im Client und steuert
 * den stochastischen Boen-Timer sowie die CSS-Variablen.
 */
function initWindGustController() {
    const container = document.querySelector('.diorama-container');
    const gustLayer = document.getElementById('wind-gust-layer');

    if (!container) return;

    const windSpeed = parseFloat(container.dataset.windSpeed) || 0;
    const windGusts = parseFloat(container.dataset.windGusts) || 0;
    const windDir   = parseFloat(container.dataset.windDir) || 270;

    // Richtungsvorzeichen: Westwind (180-360deg) -> Neigung nach rechts (+), Ostwind -> nach links (-)
    const dirSign = (windDir > 180) ? 1 : -1;

    // Grundwind-Regen/Schnee-Neigung als CSS-Variable setzen
    const rainSkewDeg = Math.min(30, windSpeed * 0.6) * dirSign;
    container.style.setProperty('--rain-skew', `${rainSkewDeg}deg`);

    // Kein Wind-Schlieren-Layer im DOM -> fertig
    if (!gustLayer) return;

    // Boen-Trigger nur aktiv, wenn Boen den Grundwind um >=5 km/h uebersteigen
    const hasSensibleGusts = (windGusts - windSpeed >= 5) && windGusts >= 15;
    if (!hasSensibleGusts) return;

    // Boenstärke-abhaengige Trigger-Intervalle (Sekunden)
    let intervalMin, intervalMax;
    if (windSpeed > 30) {
        intervalMin = 3;
        intervalMax = 7;
    } else {
        intervalMin = 8;
        intervalMax = 20;
    }

    // Boen-Neigung auf Regen/Schnee: staerker als Grundwind
    const gustSkewDeg = Math.min(38, windGusts * 0.76) * dirSign;

    function fireGust() {
        // Phase 1: schnelles Anschwellen (CSS-Transition 0.25s ease-in via .gust-active)
        gustLayer.classList.add('gust-active');
        container.style.setProperty('--rain-skew', `${gustSkewDeg}deg`);

        // Phase 2: nach 1.5-3s abklingen
        const gustDuration = 1500 + Math.random() * 1500;
        setTimeout(() => {
            gustLayer.classList.remove('gust-active');
            container.style.setProperty('--rain-skew', `${rainSkewDeg}deg`);
            const nextDelay = (intervalMin + Math.random() * (intervalMax - intervalMin)) * 1000;
            setTimeout(fireGust, nextDelay);
        }, gustDuration);
    }

    const initialDelay = Math.random() * intervalMax * 1000;
    setTimeout(fireGust, initialDelay);
}


let weatherChartInstance = null;
function initWeatherHistoryChart() {
    const canvas = document.getElementById('weatherHistoryChart');
    if (!canvas) return;
    if (weatherChartInstance) return;

    const labels    = JSON.parse(canvas.getAttribute('data-labels') || '[]');
    const tempData  = JSON.parse(canvas.getAttribute('data-temp')   || '[]');
    const precipData = JSON.parse(canvas.getAttribute('data-precip') || '[]');
    const windData  = JSON.parse(canvas.getAttribute('data-wind')   || '[]');
    const humData   = JSON.parse(canvas.getAttribute('data-hum')    || '[]');
    const pressData = JSON.parse(canvas.getAttribute('data-press')  || '[]');
    const ctx = canvas.getContext('2d');
    const isDark    = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const gridColor = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
    const textColor = isDark ? '#aaaaaa' : '#666666';

    weatherChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'Temperatur (Celsius)', data: tempData, borderColor: '#f97316', backgroundColor: 'rgba(249, 115, 22, 0.1)', yAxisID: 'yTemp', borderWidth: 2, tension: 0.3, fill: true },
                { label: 'Wind (km/h)', data: windData, borderColor: '#94a3b8', backgroundColor: 'rgba(148, 163, 184, 0.1)', yAxisID: 'yTemp', borderWidth: 2, borderDash: [5, 5], tension: 0.3, fill: false, hidden: true },
                { label: 'Luftfeuchtigkeit (%)', data: humData, borderColor: '#14b8a6', backgroundColor: 'rgba(20, 184, 166, 0.1)', yAxisID: 'yHum', borderWidth: 2, tension: 0.3, fill: false, hidden: true },
                { label: 'Luftdruck (hPa)', data: pressData, borderColor: '#a855f7', backgroundColor: 'rgba(168, 85, 247, 0.1)', yAxisID: 'yPress', borderWidth: 2, tension: 0.3, fill: false, hidden: true },
                { label: 'Niederschlag (mm)', data: precipData, borderColor: '#3b82f6', backgroundColor: 'rgba(59, 130, 246, 0.4)', yAxisID: 'yPrecip', type: 'bar', barPercentage: 0.8 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { color: textColor } },
                tooltip: { callbacks: { label: function(ctx) { let l = ctx.dataset.label || ''; if (l) l += ': '; if (ctx.parsed.y !== null) l += ctx.parsed.y; return l; } } }
            },
            scales: {
                x: { grid: { color: gridColor }, ticks: { color: textColor, maxRotation: 45, minRotation: 45 } },
                yTemp: { type: 'linear', display: true, position: 'left', title: { display: true, text: 'Temperatur (Celsius)', color: textColor }, grid: { color: gridColor }, ticks: { color: textColor } },
                yPrecip: { type: 'linear', display: true, position: 'right', title: { display: true, text: 'Niederschlag (mm)', color: textColor }, grid: { drawOnChartArea: false }, ticks: { color: textColor, beginAtZero: true }, min: 0 },
                yHum: { type: 'linear', display: false, position: 'right', title: { display: true, text: 'Feuchte (%)', color: textColor }, grid: { drawOnChartArea: false }, ticks: { color: textColor }, min: 0, max: 100 },
                yPress: { type: 'linear', display: false, position: 'right', title: { display: true, text: 'Luftdruck (hPa)', color: textColor }, grid: { drawOnChartArea: false }, ticks: { color: textColor } }
            }
        }
    });
}
