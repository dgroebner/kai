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

            // Statusanzeige über Klassen statt Inline-Styles (CSP-konform)
            this.classList.remove('is-saved', 'is-error');
            this.classList.add('is-saving');

            try {
                const result = await KaiHttp.postJson('update_range.php', {
                    vin: vin,
                    car_captured_at: capturedAt,
                    range_km: rangeKm === '' ? null : parseInt(rangeKm, 10)
                });

                if (result.success) {
                    this.classList.remove('is-saving');
                    this.classList.add('is-saved');
                    setTimeout(() => {
                        this.classList.remove('is-saved');
                    }, 1500);
                } else {
                    this.classList.remove('is-saving');
                    this.classList.add('is-error');
                    alert('Fehler beim Speichern: ' + (result.message || 'Unbekannter Fehler'));
                }
            } catch (err) {
                this.classList.remove('is-saving');
                this.classList.add('is-error');
                alert('Netzwerkfehler beim Speichern der Reichweite.');
            }
        });
    });
});