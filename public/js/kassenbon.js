// public/js/kassenbon.js

// Wir warten, bis das HTML vollständig geladen ist
document.addEventListener('DOMContentLoaded', function() {
    
    // Wir suchen uns alle anklickbaren Zeilen (wir geben ihnen gleich im HTML die Klasse 'js-toggle-receipt')
    const receiptRows = document.querySelectorAll('.js-toggle-receipt');

    receiptRows.forEach(row => {
        row.addEventListener('click', function() {
            // Die ID lesen wir aus dem data-id Attribut der geklickten Zeile aus
            const receiptId = this.getAttribute('data-id');
            const detailsRow = document.getElementById('details-' + receiptId);

            // Toggle Logik
            if (detailsRow.classList.contains('active')) {
                detailsRow.classList.remove('active');
            } else {
                // Erst alle anderen schließen
                document.querySelectorAll('.details-row.active').forEach(activeRow => {
                    activeRow.classList.remove('active');
                });
                // Dann das geklickte Element öffnen
                detailsRow.classList.add('active');
            }
        });
    });
});