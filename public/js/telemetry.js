document.addEventListener('DOMContentLoaded', () => {

    // =========================================================
    // 1. Interactive Tooltips für SVG-Charts
    // =========================================================
    let tooltip = document.getElementById('global-chart-tooltip');
    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.id = 'global-chart-tooltip';
        tooltip.className = 'chart-tooltip';
        document.body.appendChild(tooltip);
    }

    // Event Delegation auf das gesamte Document
    // Dadurch greifen Tooltips auch, wenn SVGs/Diagramme nachgeladen werden
    document.addEventListener('mouseover', (e) => {
        const point = e.target.closest('.chart-point');
        if (!point) return;

        const text = point.dataset.tooltip;
        if (!text) return;

        tooltip.innerHTML = text;
        tooltip.style.display = 'block';
    });

    document.addEventListener('mousemove', (e) => {
        if (tooltip.style.display === 'block') {
            // Tooltip direkt an die globale Mauszeiger-Position hängen (Viewport relative)
            tooltip.style.left = `${e.clientX + 10}px`;
            tooltip.style.top = `${e.clientY - 15}px`;
        }
    });

    document.addEventListener('mouseout', (e) => {
        const point = e.target.closest('.chart-point');
        if (point) {
            tooltip.style.display = 'none';
        }
    });


    // =========================================================
    // 2. Inline-Editing für Reichweite (AJAX)
    // =========================================================
    const rangeInputs = document.querySelectorAll('.inline-range-input');

    rangeInputs.forEach(input => {
        input.addEventListener('change', async function() {
            const vin = this.dataset.vin;
            const capturedAt = this.dataset.captured;
            const rangeKm = this.value.trim();

            this.style.borderColor = '#f59e0b'; // Gelb: Speichert...

            try {
                const response = await fetch('update_range.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        vin: vin,
                        car_captured_at: capturedAt,
                        range_km: rangeKm === '' ? null : parseInt(rangeKm, 10)
                    })
                });

                const result = await response.json();

                if (result.success) {
                    this.style.borderColor = '#10b981'; // Grün: Erfolgreich
                    setTimeout(() => { 
                        this.style.borderColor = 'transparent'; 
                    }, 1500);
                } else {
                    this.style.borderColor = '#ef4444'; // Rot: Fehler
                    alert('Fehler beim Speichern: ' + (result.message || 'Unbekannter Fehler'));
                }
            } catch (err) {
                this.style.borderColor = '#ef4444';
                alert('Netzwerkfehler beim Speichern der Reichweite.');
            }
        });
    });
});