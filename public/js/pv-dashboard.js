document.addEventListener("DOMContentLoaded", function() {
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
            const leftPos = middayCol.offsetLeft - (chart.clientWidth / 2) + (middayCol.clientWidth / 2);
            chart.scrollTo({
                left: leftPos,
                behavior: 'smooth'
            });
        } else {
            chart.scrollTo({
                left: (chart.scrollWidth / 2) - (chart.clientWidth / 2),
                behavior: 'smooth'
            });
        }
    }
});