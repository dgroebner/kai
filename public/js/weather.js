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
        
        // Preserve hist_filter if present
        window.history.pushState({}, '', url);
    }

    btnDiorama.addEventListener('click', (e) => {
        e.preventDefault();
        switchWeatherTab('diorama');
    });

    btnDashboard.addEventListener('click', (e) => {
        e.preventDefault();
        switchWeatherTab('dashboard');
    });

    if (btnHistory) {
        btnHistory.addEventListener('click', (e) => {
            e.preventDefault();
            switchWeatherTab('history');
        });
    }

    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') === 'dashboard') {
        switchWeatherTab('dashboard');
    } else if (params.get('tab') === 'history') {
        switchWeatherTab('history');
    }
});

    // Automatischer Reload alle 5 Minuten (300.000 ms), um Live-Daten aktuell zu halten
    setTimeout(() => {
        window.location.reload();
    }, 300000);


let weatherChartInstance = null;
function initWeatherHistoryChart() {
    const canvas = document.getElementById('weatherHistoryChart');
    if (!canvas) return;
    
    if (weatherChartInstance) {
        return; // Already initialized
    }
    
    const labels = JSON.parse(canvas.getAttribute('data-labels') || '[]');
    const tempData = JSON.parse(canvas.getAttribute('data-temp') || '[]');
    const precipData = JSON.parse(canvas.getAttribute('data-precip') || '[]');
    
    const ctx = canvas.getContext('2d');
    
    // Determine tick color based on scheme
    const isDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const gridColor = isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
    const textColor = isDark ? '#aaaaaa' : '#666666';
    
    weatherChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Temperatur (°C)',
                    data: tempData,
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249, 115, 22, 0.1)',
                    yAxisID: 'yTemp',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true
                },
                {
                    label: 'Niederschlag (mm)',
                    data: precipData,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.4)',
                    yAxisID: 'yPrecip',
                    type: 'bar',
                    barPercentage: 0.8
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
                    position: 'top',
                    labels: { color: textColor }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) label += ': ';
                            if (context.parsed.y !== null) {
                                label += context.parsed.y;
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: gridColor },
                    ticks: { color: textColor, maxRotation: 45, minRotation: 45 }
                },
                yTemp: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: { display: true, text: 'Temperatur (°C)', color: textColor },
                    grid: { color: gridColor },
                    ticks: { color: textColor }
                },
                yPrecip: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: { display: true, text: 'Niederschlag (mm)', color: textColor },
                    grid: { drawOnChartArea: false }, // Only draw grid lines for one axis
                    ticks: { color: textColor, beginAtZero: true },
                    min: 0
                }
            }
        }
    });
}
