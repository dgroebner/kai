document.addEventListener('DOMContentLoaded', () => {
    const btnDiorama = document.getElementById('btn-tab-diorama');
    const btnDashboard = document.getElementById('btn-tab-dashboard');
    const tabDiorama = document.getElementById('tab-diorama');
    const tabDashboard = document.getElementById('tab-dashboard');

    if (!btnDiorama || !btnDashboard || !tabDiorama || !tabDashboard) return;

    function switchWeatherTab(tab) {
        if (tab === 'dashboard') {
            btnDiorama.className = 'btn btn-outline';
            btnDashboard.className = 'btn';
            tabDiorama.classList.add('hidden');
            tabDashboard.classList.remove('hidden');
            
            const url = new URL(window.location);
            url.searchParams.set('tab', 'dashboard');
            window.history.pushState({}, '', url);
        } else {
            btnDashboard.className = 'btn btn-outline';
            btnDiorama.className = 'btn';
            tabDashboard.classList.add('hidden');
            tabDiorama.classList.remove('hidden');
            
            const url = new URL(window.location);
            url.searchParams.delete('tab');
            window.history.pushState({}, '', url);
        }
    }

    btnDiorama.addEventListener('click', (e) => {
        e.preventDefault();
        switchWeatherTab('diorama');
    });

    btnDashboard.addEventListener('click', (e) => {
        e.preventDefault();
        switchWeatherTab('dashboard');
    });

    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') === 'dashboard') {
        switchWeatherTab('dashboard');
    }
});
