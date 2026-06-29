document.addEventListener("DOMContentLoaded", function() {
    // Da wir defer nutzen, wartet requestAnimationFrame kurz ab, bis Chrome das CSS fertig angewendet hat
    requestAnimationFrame(() => {
        const chart = document.querySelector('.bar-chart');
        if (chart) {
            const labels = chart.querySelectorAll('.bar-label');
            let middayCol = null;
            
            // Wir suchen die Spalte für 12 oder 13 Uhr
            for (let label of labels) {
                if (label.textContent === '12' || label.textContent === '13') {
                    middayCol = label.closest('.bar-col');
                    break;
                }
            }
            
            // Wenn die Mittagsspalte gefunden wurde, nativ in die Mitte scrollen lassen
            if (middayCol) {
                middayCol.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',   // Verhindert, dass die Seite vertikal springt
                    inline: 'center'    // Zentriert die Spalte horizontal im Chart
                });
            }
        }
    });
});