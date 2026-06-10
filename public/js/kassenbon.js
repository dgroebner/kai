document.addEventListener('DOMContentLoaded', function() {
    
    // ==========================================
    // 0. Daten aus dem HTML laden (CSP-sicher!)
    // ==========================================
    const container = document.querySelector('.container');
    let knownCategories = [];
    if (container && container.hasAttribute('data-categories')) {
        try {
            knownCategories = JSON.parse(container.getAttribute('data-categories'));
        } catch (e) {
            console.error("Fehler beim Parsen der Kategorien.");
        }
    }

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
            e.stopPropagation(); 
            
            const cell = this.closest('.category-cell');
            const viewDiv = cell.querySelector('.category-view');
            const editDiv = cell.querySelector('.category-edit');
            const input = editDiv.querySelector('.category-input');
            
            viewDiv.style.display = 'none';
            editDiv.style.display = 'block';
            
            input.focus();
            const val = input.value;
            input.value = '';
            input.value = val;
            
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

            if (newCategory === '') return;

            const formData = new URLSearchParams();
            formData.append('action', 'update_category');
            formData.append('item_id', itemId);
            formData.append('category', newCategory);

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
                    label.textContent = newCategory;
                    editDiv.style.display = 'none';
                    viewDiv.style.display = 'flex';
                    
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

    function updateAutocomplete(cell, searchValue) {
        const list = cell.querySelector('.js-autocomplete');
        const input = cell.querySelector('.category-input');
        list.innerHTML = '';
        
        const lowerSearch = searchValue.toLowerCase();
        
        const matches = knownCategories.filter(cat => cat.toLowerCase().includes(lowerSearch));
        
        if (matches.length > 0) {
            matches.forEach(match => {
                const li = document.createElement('li');
                li.textContent = match;
                li.addEventListener('click', function(e) {
                    e.stopPropagation();
                    input.value = match;
                    list.style.display = 'none';
                    input.focus();
                });
                list.appendChild(li);
            });
            list.style.display = 'block';
        } else {
            list.style.display = 'none';
        }
    }

    document.addEventListener('click', function() {
        document.querySelectorAll('.js-autocomplete').forEach(list => {
            list.style.display = 'none';
        });
    });

    document.querySelectorAll('.category-edit').forEach(editDiv => {
        editDiv.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
});