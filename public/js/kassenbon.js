document.addEventListener('DOMContentLoaded', function() {
    
    // ==========================================
    // 1. Kassenbons auf- und zuklappen
    // ==========================================
    const receiptRows = document.querySelectorAll('.js-toggle-receipt');

    receiptRows.forEach(row => {
        row.addEventListener('click', function() {
            const receiptId = this.getAttribute('data-id');
            const detailsRow = document.getElementById('details-' + receiptId);

            if (detailsRow.classList.contains('active')) {
                detailsRow.classList.remove('active');
            } else {
                document.querySelectorAll('.details-row.active').forEach(activeRow => {
                    activeRow.classList.remove('active');
                });
                detailsRow.classList.add('active');
            }
        });
    });

    // ==========================================
    // 2. Inline Editing für Kategorien
    // ==========================================
    
    // Stift-Icon geklickt: Wechsel in Edit-Modus
    document.querySelectorAll('.js-edit-cat').forEach(icon => {
        icon.addEventListener('click', function(e) {
            e.stopPropagation(); // Verhindert, dass die Tabellenzeile Dinge tut
            
            const cell = this.closest('.category-cell');
            const viewDiv = cell.querySelector('.category-view');
            const editDiv = cell.querySelector('.category-edit');
            const input = editDiv.querySelector('.category-input');
            
            viewDiv.style.display = 'none';
            editDiv.style.display = 'block';
            
            // Setze Cursor ans Ende des Textes
            input.focus();
            const val = input.value;
            input.value = '';
            input.value = val;
            
            // Initial das Dropdown füllen (zeigt alle Kategorien)
            updateAutocomplete(cell, input.value);
        });
    });

    // Rotes Kreuz: Abbrechen
    document.querySelectorAll('.js-cancel-cat').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const cell = this.closest('.category-cell');
            const viewDiv = cell.querySelector('.category-view');
            const editDiv = cell.querySelector('.category-edit');
            const input = editDiv.querySelector('.category-input');
            const label = viewDiv.querySelector('.js-cat-label');
            
            // Eingabefeld zurücksetzen und verstecken
            input.value = label.textContent;
            editDiv.style.display = 'none';
            viewDiv.style.display = 'flex';
        });
    });

    // Autocomplete beim Tippen
    document.querySelectorAll('.js-cat-input').forEach(input => {
        input.addEventListener('input', function(e) {
            const cell = this.closest('.category-cell');
            updateAutocomplete(cell, this.value);
        });
    });

    // Grüner Haken: Speichern via AJAX
    document.querySelectorAll('.js-save-cat').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            
            const cell = this.closest('.category-cell');
            const itemId = cell.getAttribute('data-item-id');
            const input = cell.querySelector('.category-input');
            const newCategory = input.value.trim();
            const viewDiv = cell.querySelector('.category-view');
            const editDiv = cell.querySelector('.category-edit');
            const label = viewDiv.querySelector('.js-cat-label');

            if (newCategory === '') return; // Leer lassen wir nicht zu

            // Daten für den Server vorbereiten
            const formData = new URLSearchParams();
            formData.append('action', 'update_category');
            formData.append('item_id', itemId);
            formData.append('category', newCategory);

            // Button kurz ausgrauen
            this.style.opacity = '0.5';

            fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                this.style.opacity = '1';
                if (data.success) {
                    // UI aktualisieren: Badge auf neuen Text setzen und zurück in View-Modus
                    label.textContent = newCategory;
                    editDiv.style.display = 'none';
                    viewDiv.style.display = 'flex';
                    
                    // Falls es eine völlig neue Kategorie war, fügen wir sie unserem Array hinzu, 
                    // damit sie ab sofort im Autocomplete anderer Zeilen auftaucht
                    if (!knownCategories.includes(newCategory)) {
                        knownCategories.push(newCategory);
                        knownCategories.sort();
                    }
                } else {
                    alert('Fehler beim Speichern: ' + (data.error || 'Unbekannt'));
                }
            })
            .catch(error => {
                this.style.opacity = '1';
                alert('Netzwerkfehler beim Speichern.');
            });
        });
    });

    // Hilfsfunktion: Dropdown aktualisieren
    function updateAutocomplete(cell, searchValue) {
        const list = cell.querySelector('.js-autocomplete');
        const input = cell.querySelector('.category-input');
        list.innerHTML = '';
        
        const lowerSearch = searchValue.toLowerCase();
        
        // Finde Treffer (oder zeige alle, wenn leer)
        const matches = knownCategories.filter(cat => cat.toLowerCase().includes(lowerSearch));
        
        if (matches.length > 0) {
            matches.forEach(match => {
                const li = document.createElement('li');
                li.textContent = match;
                // Klick auf ein Listenelement
                li.addEventListener('click', function(e) {
                    e.stopPropagation();
                    input.value = match;
                    list.style.display = 'none'; // Verstecken nach Auswahl
                    input.focus();
                });
                list.appendChild(li);
            });
            list.style.display = 'block';
        } else {
            list.style.display = 'none';
        }
    }

    // Wenn man irgendwohin klickt, sollen alle offenen Autocomplete-Listen verschwinden
    document.addEventListener('click', function() {
        document.querySelectorAll('.js-autocomplete').forEach(list => {
            list.style.display = 'none';
        });
    });

    // Verhindern, dass Klicks innerhalb des Edit-Bereichs das Dokument-Click-Event auslösen
    document.querySelectorAll('.category-edit').forEach(editDiv => {
        editDiv.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
});