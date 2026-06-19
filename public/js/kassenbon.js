document.addEventListener('DOMContentLoaded', function() {
    
    // ==========================================
    // 0. Daten aus dem HTML laden (CSP-sicher!)
    // ==========================================
    const container = document.querySelector('.container');
    let knownCategories = [];
    let csrfToken = '';
    if (container) {
        if (container.hasAttribute('data-categories')) {
            try {
                knownCategories = JSON.parse(container.getAttribute('data-categories'));
            } catch (e) {
                console.error("Fehler beim Parsen der Kategorien.");
            }
        }
        csrfToken = container.getAttribute('data-csrf-token') || '';
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
                
                // Freshly draw the interactive SVG chart when details row becomes visible
                renderReceiptChart(detailsRow);
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
            formData.append('csrf_token', csrfToken);

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

                    // Recalculate category shares and redraw chart
                    recalculateReceiptAnalysis(cell.closest('.details-row'));
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

    // ==========================================
    // 3. Diagramm- & Tabellenfunktionen
    // ==========================================

    function updateCategoryFilterUI(detailsContainer) {
        const activeFilter = detailsContainer.getAttribute('data-active-filter');
        const itemRows = detailsContainer.querySelectorAll('.js-item-row');
        const tableRows = detailsContainer.querySelectorAll('.js-category-row');
        const slices = detailsContainer.querySelectorAll('.chart-slice');

        if (activeFilter) {
            // Filter item rows to show only clicked category
            itemRows.forEach(row => {
                const catLabel = row.querySelector('.js-cat-label');
                if (!catLabel) return;
                const category = catLabel.textContent.trim() || 'Sonstiges';
                if (category === activeFilter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            // Keep the corresponding table row highlighted
            tableRows.forEach(row => {
                const name = row.getAttribute('data-category');
                if (name === activeFilter) {
                    row.classList.add('filter-active');
                    row.classList.add('highlight');
                } else {
                    row.classList.remove('filter-active');
                    row.classList.remove('highlight');
                }
            });

            // Keep the corresponding SVG slice highlighted
            slices.forEach(slice => {
                const catName = decodeURIComponent(slice.getAttribute('data-category'));
                const dx = parseFloat(slice.getAttribute('data-dx'));
                const dy = parseFloat(slice.getAttribute('data-dy'));
                if (catName === activeFilter) {
                    slice.classList.add('filter-active');
                    slice.style.transform = `translate(${dx}px, ${dy}px) scale(1.02)`;
                    slice.style.filter = 'url(#glow-filter)';
                    slice.style.stroke = '#ffffff';
                    slice.style.strokeWidth = '3';
                } else {
                    slice.classList.remove('filter-active');
                    slice.style.transform = '';
                    slice.style.filter = '';
                    slice.style.stroke = 'var(--bg-surface)';
                    slice.style.strokeWidth = '2.5';
                }
            });
        } else {
            // Reset - Show all item rows
            itemRows.forEach(row => {
                row.style.display = '';
            });

            // Clear table row highlights
            tableRows.forEach(row => {
                row.classList.remove('filter-active');
                row.classList.remove('highlight');
            });

            // Clear SVG slice highlights
            slices.forEach(slice => {
                slice.classList.remove('filter-active');
                slice.style.transform = '';
                slice.style.filter = '';
                slice.style.stroke = 'var(--bg-surface)';
                slice.style.strokeWidth = '2.5';
            });
        }
    }

    function renderReceiptChart(detailsContainer) {
        const chartContainer = detailsContainer.querySelector('.js-chart-container');
        if (!chartContainer) return;

        const tableRows = detailsContainer.querySelectorAll('.js-category-row');
        const categories = [];
        let grandTotal = 0;

        tableRows.forEach(row => {
            const name = row.getAttribute('data-category');
            const color = row.getAttribute('data-color') || '#64748b';
            const percentage = parseFloat(row.getAttribute('data-percentage')) || 0;
            const total = parseFloat(row.getAttribute('data-total')) || 0;
            categories.push({ name, percentage, total, color, row });
            grandTotal += total;
        });

        if (categories.length === 0) {
            chartContainer.innerHTML = '<p style="color: var(--text-muted); font-size: 0.9em; text-align: center;">Keine Daten</p>';
            return;
        }

        const size = 220;
        const cx = size / 2;
        const cy = size / 2;
        const r = 80;
        const innerR = 48; // Donut hole radius

        let svgHtml = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" width="100%" height="100%" style="max-width: ${size}px; display: block; margin: 0 auto; overflow: visible;">`;
        
        // Define a drop shadow filter for glowing effect
        svgHtml += `
          <defs>
            <filter id="glow-filter" x="-20%" y="-20%" width="140%" height="140%">
              <feGaussianBlur stdDeviation="4" result="blur" />
              <feComposite in="SourceGraphic" in2="blur" operator="over" />
            </filter>
          </defs>
        `;

        let currentAngle = -90; // Start at 12 o'clock

        categories.forEach((cat, index) => {
            const sliceAngle = (cat.percentage / 100) * 360;
            if (sliceAngle <= 0) return;

            // Calculate bisector angle for translation on hover
            const bisectorAngleDeg = currentAngle + (sliceAngle / 2);
            const bisectorRad = bisectorAngleDeg * Math.PI / 180;
            const dx = Math.cos(bisectorRad) * 6;
            const dy = Math.sin(bisectorRad) * 6;

            let pathD = '';
            if (cat.percentage >= 99.9) {
                // Full circle
                pathD = `
                    M ${cx} ${cy - r} 
                    A ${r} ${r} 0 1 1 ${cx - 0.01} ${cy - r}
                    Z
                `;
            } else {
                const radStart = currentAngle * Math.PI / 180;
                const radEnd = (currentAngle + sliceAngle) * Math.PI / 180;

                const xStart = cx + r * Math.cos(radStart);
                const yStart = cy + r * Math.sin(radStart);
                const xEnd = cx + r * Math.cos(radEnd);
                const yEnd = cy + r * Math.sin(radEnd);

                const largeArcFlag = sliceAngle > 180 ? 1 : 0;

                pathD = `M ${cx} ${cy} L ${xStart} ${yStart} A ${r} ${r} 0 ${largeArcFlag} 1 ${xEnd} ${yEnd} Z`;
            }

            svgHtml += `
                <path 
                    d="${pathD.trim()}" 
                    fill="${cat.color}" 
                    stroke="var(--bg-surface)" 
                    stroke-width="2.5" 
                    class="chart-slice" 
                    data-category="${encodeURIComponent(cat.name)}"
                    data-dx="${dx}"
                    data-dy="${dy}"
                    data-color="${cat.color}"
                    style="--slice-color: ${cat.color};"
                />
            `;

            currentAngle += sliceAngle;
        });

        // Draw inner donut hole to make it a donut chart
        svgHtml += `
            <circle cx="${cx}" cy="${cy}" r="${innerR}" fill="var(--bg-surface)" stroke="var(--bg-surface-hover)" stroke-width="1" />
        `;

        // Format total price
        const formattedTotal = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(grandTotal);
        // Put total price in the center of the donut chart
        svgHtml += `
            <text x="${cx}" y="${cy - 4}" text-anchor="middle" fill="var(--text-muted)" font-size="10" font-weight="500">Gesamt</text>
            <text x="${cx}" y="${cy + 12}" text-anchor="middle" fill="var(--text-main)" font-size="13" font-weight="bold">${formattedTotal}</text>
        `;

        svgHtml += '</svg>';
        chartContainer.innerHTML = svgHtml;

        // Attach event listeners
        const slices = chartContainer.querySelectorAll('.chart-slice');
        slices.forEach(slice => {
            const catName = decodeURIComponent(slice.getAttribute('data-category'));
            const dx = parseFloat(slice.getAttribute('data-dx'));
            const dy = parseFloat(slice.getAttribute('data-dy'));
            const color = slice.getAttribute('data-color');
            const row = Array.from(tableRows).find(r => r.getAttribute('data-category') === catName);

            function highlight() {
                slice.style.transform = `translate(${dx}px, ${dy}px) scale(1.02)`;
                slice.style.filter = 'url(#glow-filter)';
                slice.style.stroke = '#ffffff';
                slice.style.strokeWidth = '3';
                if (row) {
                    row.classList.add('highlight');
                }
            }

            function unhighlight() {
                const activeFilter = detailsContainer.getAttribute('data-active-filter');
                if (activeFilter === catName) {
                    return;
                }
                slice.style.transform = '';
                slice.style.filter = '';
                slice.style.stroke = 'var(--bg-surface)';
                slice.style.strokeWidth = '2.5';
                if (row) {
                    row.classList.remove('highlight');
                }
            }

            slice.addEventListener('mouseenter', highlight);
            slice.addEventListener('mouseleave', unhighlight);

            function toggleFilter(e) {
                e.stopPropagation();
                const currentFilter = detailsContainer.getAttribute('data-active-filter');
                if (currentFilter === catName) {
                    detailsContainer.removeAttribute('data-active-filter');
                } else {
                    detailsContainer.setAttribute('data-active-filter', catName);
                }
                updateCategoryFilterUI(detailsContainer);
            }

            slice.addEventListener('click', toggleFilter);
        });
    }

    function recalculateReceiptAnalysis(detailsRow) {
        // Clear active filter to prevent indexing issues during reconstruction
        detailsRow.removeAttribute('data-active-filter');

        const itemRows = detailsRow.querySelectorAll('.js-item-row');
        const categoryTotals = {};
        let grandTotal = 0;

        itemRows.forEach(row => {
            const catLabel = row.querySelector('.js-cat-label');
            if (!catLabel) return;
            const category = catLabel.textContent.trim() || 'Sonstiges';
            const price = parseFloat(row.getAttribute('data-total-price')) || 0;
            
            if (!categoryTotals[category]) {
                categoryTotals[category] = 0;
            }
            categoryTotals[category] += price;
            grandTotal += price;
        });

        const sortedCategories = Object.keys(categoryTotals).map(catName => {
            return {
                name: catName,
                total: categoryTotals[catName],
                percentage: grandTotal > 0 ? (categoryTotals[catName] / grandTotal) * 100 : 0
            };
        }).sort((a, b) => b.total - a.total);

        const tableBody = detailsRow.querySelector('.js-category-table-body');
        if (!tableBody) return;

        const niceColors = [
            '#3b82f6', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', 
            '#06b6d4', '#f97316', '#84cc16', '#a855f7', '#14b8a6', 
            '#ef4444', '#64748b'
        ];

        let newRowsHtml = '';
        sortedCategories.forEach((cat, index) => {
            const color = niceColors[index % niceColors.length];
            
            const formattedTotal = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(cat.total) + ' €';
            const formattedPercentage = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(cat.percentage) + '%';

            newRowsHtml += `
                <tr class="js-category-row" data-category="${escapeHtml(cat.name)}" data-percentage="${cat.percentage}" data-total="${cat.total}" data-color="${color}" style="--category-color: ${color};">
                    <td>
                        <span class="category-color-dot" style="background-color: ${color};"></span>
                        <span class="category-name">${escapeHtml(cat.name)}</span>
                    </td>
                    <td class="percentage-cell" style="text-align: right;">${formattedPercentage}</td>
                    <td class="total-cell" style="text-align: right;">${formattedTotal}</td>
                </tr>
            `;
        });

        tableBody.innerHTML = newRowsHtml;

        // Build category to color mapping
        const categoryColorMap = {};
        sortedCategories.forEach((cat, index) => {
            categoryColorMap[cat.name] = niceColors[index % niceColors.length];
        });

        // Update item badges color dynamically to match the table color
        itemRows.forEach(row => {
            const catLabel = row.querySelector('.js-cat-label');
            if (!catLabel) return;
            const category = catLabel.textContent.trim() || 'Sonstiges';
            const color = categoryColorMap[category] || '#64748b';
            catLabel.style.setProperty('--category-color', color);
        });

        // Draw the updated chart
        renderReceiptChart(detailsRow);
    }

    function escapeHtml(str) {
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Initialisierung aller Diagramme beim Laden
    document.querySelectorAll('.details-row').forEach(row => {
        renderReceiptChart(row);
    });

    // ==========================================
    // 4. Delegierte Events für Tabellenzeilen
    // ==========================================
    
    // Click delegation for category table rows
    document.addEventListener('click', function(e) {
        const row = e.target.closest('.js-category-row');
        if (!row) return;
        const detailsRow = row.closest('.details-row');
        if (!detailsRow) return;

        const catName = row.getAttribute('data-category');
        const currentFilter = detailsRow.getAttribute('data-active-filter');
        if (currentFilter === catName) {
            detailsRow.removeAttribute('data-active-filter');
        } else {
            detailsRow.setAttribute('data-active-filter', catName);
        }
        updateCategoryFilterUI(detailsRow);
    });

    // Hover delegation for category table rows (mouseover)
    document.addEventListener('mouseover', function(e) {
        const row = e.target.closest('.js-category-row');
        if (!row) return;
        const detailsRow = row.closest('.details-row');
        if (!detailsRow) return;

        const catName = row.getAttribute('data-category');
        const slice = detailsRow.querySelector(`.chart-slice[data-category="${encodeURIComponent(catName)}"]`);
        if (slice) {
            const dx = parseFloat(slice.getAttribute('data-dx')) || 0;
            const dy = parseFloat(slice.getAttribute('data-dy')) || 0;
            slice.style.transform = `translate(${dx}px, ${dy}px) scale(1.02)`;
            slice.style.filter = 'url(#glow-filter)';
            slice.style.stroke = '#ffffff';
            slice.style.strokeWidth = '3';
        }
        row.classList.add('highlight');
    });

    // Hover delegation for category table rows (mouseout)
    document.addEventListener('mouseout', function(e) {
        const row = e.target.closest('.js-category-row');
        if (!row) return;
        const detailsRow = row.closest('.details-row');
        if (!detailsRow) return;

        const catName = row.getAttribute('data-category');
        const activeFilter = detailsRow.getAttribute('data-active-filter');
        if (activeFilter === catName) {
            return;
        }

        const slice = detailsRow.querySelector(`.chart-slice[data-category="${encodeURIComponent(catName)}"]`);
        if (slice) {
            slice.style.transform = '';
            slice.style.filter = '';
            slice.style.stroke = 'var(--bg-surface)';
            slice.style.strokeWidth = '2.5';
        }
        row.classList.remove('highlight');
    });
});