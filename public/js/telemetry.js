document.addEventListener('DOMContentLoaded', () => {
    const rangeInputs = document.querySelectorAll('.inline-range-input');

    rangeInputs.forEach(input => {
        input.addEventListener('change', async function() {
            const vin = this.dataset.vin;
            const capturedAt = this.dataset.captured;
            const rangeKm = this.value.trim();

            // Optisches Feedback: Gelb = Speichert...
            this.style.borderColor = '#f59e0b';

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
                    // Grün = Erfolgreich gespeichert
                    this.style.borderColor = '#10b981';
                    setTimeout(() => { 
                        this.style.borderColor = 'transparent'; 
                    }, 1500);
                } else {
                    // Rot = Fehler vom Server
                    this.style.borderColor = '#ef4444';
                    alert('Fehler beim Speichern: ' + (result.message || 'Unbekannter Fehler'));
                }
            } catch (err) {
                // Rot = Netzwerk- oder Caching-Fehler
                this.style.borderColor = '#ef4444';
                alert('Netzwerkfehler beim Speichern der Reichweite.');
            }
        });
    });
});