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

    const points = document.querySelectorAll('.chart-point');

    points.forEach(point => {
        point.addEventListener('mouseenter', () => {
            const text = point.dataset.tooltip;
            if (!text) return;

            tooltip.innerHTML = text;
            tooltip.style.display = 'block';
        });

        point.addEventListener('mousemove', (e) => {
            // Tooltip direkt an den Mauszeiger hängen
            tooltip.style.left = `${e.clientX}px`;
            tooltip.style.top = `${e.clientY - 12}px`;
        });

        point.addEventListener('mouseleave', () => {
            tooltip.style.display = 'none';
        });
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