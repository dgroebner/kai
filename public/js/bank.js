document.addEventListener('DOMContentLoaded', () => {
    const appEl = document.getElementById('bankDetailApp');
    
    if (appEl) {
        window.ALL_CATEGORIES = JSON.parse(appEl.dataset.categories || '[]');
        window.TRANSACTIONS = JSON.parse(appEl.dataset.transactions || '[]');
        window.TOTAL_AMOUNT = parseFloat(appEl.dataset.total || '0');
    }

    initCategoryAnalysis();
});

let categoryChart = null;
let activeFilterCategory = null;

// Antigravity Farb-Palette für den Chart
const CHART_COLORS = [
    '#3b82f6', '#10b981', '#f59e0b', '#ec4899', 
    '#8b5cf6', '#06b6d4', '#f97316', '#64748b'
];

function initCategoryAnalysis() {
    // 1. Kategorien aggregieren
    const categoryTotals = {};
    let grandTotal = 0;

    TRANSACTIONS.forEach(tx => {
        const amount = Math.abs(parseFloat(tx.amount));
        const catName = tx.category_name || 'Sonstiges';
        categoryTotals[catName] = (categoryTotals[catName] || 0) + amount;
        grandTotal += amount;
    });

    const sortedCategories = Object.entries(categoryTotals).sort((a, b) => b[1] - a[1]);
    const labels = sortedCategories.map(item => item[0]);
    const dataValues = sortedCategories.map(item => item[1]);

    // Color Map für Tabellen-Badges aufbauen
    const categoryColorMap = {};
    sortedCategories.forEach(([catName], index) => {
        categoryColorMap[catName] = CHART_COLORS[index % CHART_COLORS.length];
    });

    // 2. Tabellen-Badges mit den Farben des Charts einfärben
    document.querySelectorAll('#transactionsTable tbody tr').forEach(row => {
        const catName = row.dataset.categoryName;
        const badge = row.querySelector('.clickable-badge');
        if (badge && categoryColorMap[catName]) {
            const color = categoryColorMap[catName];
            badge.style.color = color;
            badge.style.borderColor = color;
        }
    });

    // 3. Legende mit Tabellenkopf rendern
    const legendContainer = document.getElementById('categoryLegend');
    if (legendContainer) {
        legendContainer.innerHTML = `
            <div class="legend-header">
                <span></span>
                <span>Kategorie</span>
                <span style="text-align: right;">Anteil</span>
                <span style="text-align: right;">Gesamt</span>
            </div>
        `;

        sortedCategories.forEach(([catName, sum], index) => {
            const color = CHART_COLORS[index % CHART_COLORS.length];
            const percentage = grandTotal > 0 ? ((sum / grandTotal) * 100).toFixed(1) : 0;

            const row = document.createElement('div');
            row.className = 'legend-row';
            row.dataset.category = catName;
            row.innerHTML = `
                <span class="legend-dot" style="background-color: ${color}"></span>
                <span class="legend-label">${catName}</span>
                <span class="legend-percent">${percentage.replace('.', ',')}%</span>
                <span class="legend-value">${sum.toFixed(2).replace('.', ',')} €</span>
            `;

            row.addEventListener('click', () => filterByCategory(catName));
            legendContainer.appendChild(row);
        });
    }

    // 4. ChartJS Donut-Chart
    const chartEl = document.getElementById('categoryChart');
    if (chartEl) {
        const ctx = chartEl.getContext('2d');
        if (categoryChart) {
            categoryChart.destroy();
        }

        categoryChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: dataValues,
                    backgroundColor: CHART_COLORS.slice(0, labels.length),
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return ` ${context.label}: ${context.raw.toFixed(2).replace('.', ',')} €`;
                            }
                        }
                    }
                },
                onClick: (evt, activeElements) => {
                    if (activeElements.length > 0) {
                        const index = activeElements[0].index;
                        filterByCategory(labels[index]);
                    }
                }
            }
        });
    }

    const resetBtn = document.getElementById('resetFilterBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', resetFilter);
    }
	
	document.addEventListener('click', (e) => {
		const badge = e.target.closest('.clickable-badge');
		if (badge) {
			const txId = badge.dataset.txId;
			if (txId && !badge.closest('.category-edit-container')) {
				enableCategoryEdit(badge, txId);
			}
		}
	});
}

// Filter-Logik für Tabelle, Legende und Chart
function filterByCategory(categoryName) {
    const cleanCategoryName = categoryName.trim().toLowerCase();

    // Bei erneutem Klick auf dieselbe Kategorie Filter aufheben
    if (activeFilterCategory === cleanCategoryName) {
        resetFilter();
        return;
    }

    activeFilterCategory = cleanCategoryName;
    const rows = document.querySelectorAll('#transactionsTable tbody tr');

    rows.forEach(row => {
        const rowCategory = (row.dataset.categoryName || '').trim().toLowerCase();
        
        if (rowCategory === cleanCategoryName) {
            row.classList.remove('hidden');
        } else {
            row.classList.add('hidden');
        }
    });

    // Legenden-Zeilen visuell anpassen
    document.querySelectorAll('.legend-row').forEach(row => {
        const rowCategory = (row.dataset.category || '').trim().toLowerCase();
        if (rowCategory === cleanCategoryName) {
            row.classList.add('active');
        } else {
            row.classList.remove('active');
        }
    });

    // Reset-Button anzeigen
    const resetBtn = document.getElementById('resetFilterBtn');
    if (resetBtn) {
        resetBtn.classList.remove('hidden');
    }
}

function resetFilter() {
    activeFilterCategory = null;
    document.querySelectorAll('#transactionsTable tbody tr').forEach(row => row.classList.remove('hidden'));
    document.querySelectorAll('.legend-row').forEach(row => row.classList.remove('active'));
    
    const resetBtn = document.getElementById('resetFilterBtn');
    if (resetBtn) {
        resetBtn.classList.add('hidden');
    }
}

// Inline-Editierung der Kategorien mit Speichern/Abbrechen
function enableCategoryEdit(badgeElement, txId) {
    const parentTd = badgeElement.parentElement;
    const textNode = badgeElement.querySelector('.badge-text') || badgeElement;
    const currentCategoryName = textNode.textContent.trim();
    const originalHtml = parentTd.innerHTML;

    // Container für Input + Buttons
    const container = document.createElement('div');
    container.className = 'category-edit-container';

    // Text-Input mit Vorschlagsliste (Datalist)
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'category-input-custom';
    input.value = currentCategoryName;
    input.setAttribute('list', 'categorySuggestions');
    input.placeholder = 'Kategorie suchen/neu...';

    // Datalist für Auto-Suggest aufbauen
    let datalist = document.getElementById('categorySuggestions');
    if (!datalist) {
        datalist = document.createElement('datalist');
        datalist.id = 'categorySuggestions';
        document.body.appendChild(datalist);
    }
    
    datalist.innerHTML = '';
    ALL_CATEGORIES.forEach(cat => {
        const option = document.createElement('option');
        option.value = cat.name;
        datalist.appendChild(option);
    });

    const saveBtn = document.createElement('button');
    saveBtn.className = 'btn-icon-action';
    saveBtn.innerHTML = '✅';
    saveBtn.title = 'Speichern';

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn-icon-action';
    cancelBtn.innerHTML = '❌';
    cancelBtn.title = 'Abbrechen';

    container.appendChild(input);
    container.appendChild(saveBtn);
    container.appendChild(cancelBtn);

    parentTd.innerHTML = '';
    parentTd.appendChild(container);
    
    input.focus();
    input.select(); // Text direkt markieren zum schnellen Überschreiben

    // Speichern
    const handleSave = async () => {
        const newCategoryName = input.value.trim();
        if (!newCategoryName) {
            alert('Bitte eine Kategorie eingeben.');
            return;
        }

        try {
            const res = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tx_id: txId, category_name: newCategoryName })
            });

            const result = await res.json();
            if (result.success) {
                location.reload();
            } else {
                alert('Fehler beim Speichern: ' + (result.error || 'Unbekannter Fehler'));
            }
        } catch (e) {
            alert('Netzwerkfehler beim Speichern.');
        }
    };

    saveBtn.addEventListener('click', handleSave);

    // Enter drückt Speichern, Escape bricht ab
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleSave();
        } else if (e.key === 'Escape') {
            parentTd.innerHTML = originalHtml;
        }
    });

    // Abbrechen
    cancelBtn.addEventListener('click', () => {
        parentTd.innerHTML = originalHtml;
    });
}