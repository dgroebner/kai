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
    // 1. Kategorien aggregieren (nur Ausgaben/negative Beträge einrechnen)
    const categoryTotals = {};
    let grandTotal = 0;

    TRANSACTIONS.forEach(tx => {
        const amount = Math.abs(parseFloat(tx.amount));
        const catName = tx.category_name || 'Sonstiges';

        categoryTotals[catName] = (categoryTotals[catName] || 0) + amount;
        grandTotal += amount;
    });

    // Sortieren nach Betrag absteigend
    const sortedCategories = Object.entries(categoryTotals)
        .sort((a, b) => b[1] - a[1]);

    const labels = sortedCategories.map(item => item[0]);
    const dataValues = sortedCategories.map(item => item[1]);

    // 2. Legende rendern
    const legendContainer = document.getElementById('categoryLegend');
    legendContainer.innerHTML = '';

    sortedCategories.forEach(([catName, sum], index) => {
        const color = CHART_COLORS[index % CHART_COLORS.length];
        const percentage = grandTotal > 0 ? ((sum / grandTotal) * 100).toFixed(1) : 0;

        const row = document.createElement('div');
        row.className = 'legend-row';
        row.dataset.category = catName;
        row.innerHTML = `
            <span class="legend-dot" style="background-color: ${color}"></span>
            <span class="legend-label">${catName}</span>
            <span class="legend-percent">${percentage}%</span>
            <span class="legend-value">${sum.toFixed(2).replace('.', ',')} €</span>
        `;

        row.addEventListener('click', () => filterByCategory(catName));
        legendContainer.appendChild(row);
    });

    // 3. ChartJS Donut-Chart erstellen
    const ctx = document.getElementById('categoryChart').getContext('2d');
    categoryChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: dataValues,
                backgroundColor: CHART_COLORS.slice(0, labels.length),
                borderWidth: 0,
                hoverOffset: 6
            }]
        },
        options: {
            cutout: '75%',
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
                    const clickedCategory = labels[index];
                    filterByCategory(clickedCategory);
                }
            }
        }
    });

    document.getElementById('resetFilterBtn').addEventListener('click', resetFilter);
}

// Filter-Logik für Tabelle, Legende und Chart
function filterByCategory(categoryName) {
    if (activeFilterCategory === categoryName) {
        resetFilter();
        return;
    }

    activeFilterCategory = categoryName;
    const rows = document.querySelectorAll('#transactionsTable tbody tr');

    rows.forEach(row => {
        if (row.dataset.categoryName === categoryName) {
            row.classList.remove('hidden');
        } else {
            row.classList.add('hidden');
        }
    });

    // Legende visuell anpassen
    document.querySelectorAll('.legend-row').forEach(row => {
        if (row.dataset.category === categoryName) {
            row.classList.add('active');
        } else {
            row.classList.remove('active');
        }
    });

    document.getElementById('resetFilterBtn').classList.remove('hidden');
}

function resetFilter() {
    activeFilterCategory = null;
    document.querySelectorAll('#transactionsTable tbody tr').forEach(row => row.classList.remove('hidden'));
    document.querySelectorAll('.legend-row').forEach(row => row.classList.remove('active'));
    document.getElementById('resetFilterBtn').classList.add('hidden');
}

// Inline-Editierung der Kategorien
function enableCategoryEdit(badgeElement, txId) {
    const currentCategoryName = badgeElement.textContent.trim();
    const parentTd = badgeElement.parentElement;

    const select = document.createElement('select');
    select.className = 'category-select-inline';

    ALL_CATEGORIES.forEach(cat => {
        const option = document.createElement('option');
        option.value = cat.id;
        option.textContent = cat.name;
        if (cat.name === currentCategoryName) {
            option.selected = true;
        }
        select.appendChild(option);
    });

    parentTd.innerHTML = '';
    parentTd.appendChild(select);
    select.focus();

    select.addEventListener('change', async () => {
        const newCatId = select.value;
        const newCatName = select.options[select.selectedIndex].text;

        try {
            const res = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tx_id: txId, category_id: newCatId })
            });

            const result = await res.json();
            if (result.success) {
                location.reload(); // Neuladen zur Aktualisierung von Chart & Legende
            } else {
                alert('Fehler beim Speichern: ' + result.error);
            }
        } catch (e) {
            alert('Netzwerkfehler beim Speichern.');
        }
    });

    select.addEventListener('blur', () => {
        location.reload();
    });
}