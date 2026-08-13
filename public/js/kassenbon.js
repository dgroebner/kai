document.addEventListener('DOMContentLoaded', () => {
    const appEl = document.getElementById('kassenbonDetailApp');
    if (appEl) {
        window.ALL_CATEGORIES = JSON.parse(appEl.dataset.categories || '[]');
        window.ITEMS = JSON.parse(appEl.dataset.items || '[]');
        initKassenbonAnalysis();
    }
});

let categoryChart = null;
let activeFilterCategory = null;

const CHART_COLORS = [
    '#3b82f6', '#10b981', '#f59e0b', '#ec4899', 
    '#8b5cf6', '#06b6d4', '#f97316', '#84cc16'
];

function initKassenbonAnalysis() {
    const totals = {};
    let grandTotal = 0;

    ITEMS.forEach(item => {
        const amount = parseFloat(item.total_price || 0);
        const cat = item.category || 'Sonstiges';
        totals[cat] = (totals[cat] || 0) + amount;
        grandTotal += amount;
    });

    const sorted = Object.entries(totals).sort((a, b) => b[1] - a[1]);
    const labels = sorted.map(i => i[0]);
    const dataValues = sorted.map(i => i[1]);

    const colorMap = {};
    sorted.forEach(([cat], idx) => colorMap[cat] = CHART_COLORS[idx % CHART_COLORS.length]);

    // Badges einfärben
    document.querySelectorAll('#itemsTable tbody tr').forEach(row => {
        const cat = row.dataset.categoryName;
        const badge = row.querySelector('.clickable-badge');
        if (badge && colorMap[cat]) {
            badge.style.color = colorMap[cat];
            badge.style.borderColor = colorMap[cat];
        }
    });

    // Legende bauen
    const legendEl = document.getElementById('categoryLegend');
    if (legendEl) {
        legendEl.innerHTML = `
            <div class="legend-header">
                <span></span><span>Kategorie</span><span class="text-right">Anteil</span><span class="text-right">Gesamt</span>
            </div>
        `;
        sorted.forEach(([cat, sum], idx) => {
            const color = CHART_COLORS[idx % CHART_COLORS.length];
            const pct = grandTotal > 0 ? ((sum / grandTotal) * 100).toFixed(1) : 0;
            const row = document.createElement('div');
            row.className = 'legend-row';
            row.innerHTML = `
                <span class="legend-dot" style="background-color:${color}"></span>
                <span>${cat}</span>
                <span class="legend-percent">${pct.replace('.', ',')}%</span>
                <span class="legend-value">${sum.toFixed(2).replace('.', ',')} €</span>
            `;
            row.addEventListener('click', () => filterByCategory(cat));
            legendEl.appendChild(row);
        });
    }

    // ChartJS
    const chartEl = document.getElementById('categoryChart');
    if (chartEl) {
        categoryChart = new Chart(chartEl.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: dataValues, backgroundColor: CHART_COLORS.slice(0, labels.length), borderWidth: 0 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: { legend: { display: false } },
                onClick: (evt, active) => {
                    if (active.length > 0) filterByCategory(labels[active[0].index]);
                }
            }
        });
    }

    document.getElementById('resetFilterBtn')?.addEventListener('click', resetFilter);

    // Click Delegation für Inline-Editing
    document.addEventListener('click', (e) => {
        const badge = e.target.closest('.clickable-badge');
        if (badge && !badge.closest('.category-edit-container')) {
            enableCategoryEdit(badge, badge.dataset.itemId);
        }
    });
}

function filterByCategory(catName) {
    const clean = catName.trim().toLowerCase();
    if (activeFilterCategory === clean) return resetFilter();

    activeFilterCategory = clean;
    document.querySelectorAll('#itemsTable tbody tr').forEach(row => {
        const rowCat = (row.dataset.categoryName || '').trim().toLowerCase();
        row.classList.toggle('hidden', rowCat !== clean);
    });

    document.getElementById('resetFilterBtn')?.classList.remove('hidden');
}

function resetFilter() {
    activeFilterCategory = null;
    document.querySelectorAll('#itemsTable tbody tr').forEach(row => row.classList.remove('hidden'));
    document.getElementById('resetFilterBtn')?.classList.add('hidden');
}

function enableCategoryEdit(badgeElement, itemId) {
    const parentTd = badgeElement.parentElement;
    const currentName = badgeElement.querySelector('.badge-text').textContent.trim();
    const originalHtml = parentTd.innerHTML;

    const container = document.createElement('div');
    container.className = 'category-edit-container';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'category-input';
    input.value = currentName;
    input.setAttribute('list', 'catSuggestions');

    let datalist = document.getElementById('catSuggestions');
    if (!datalist) {
        datalist = document.createElement('datalist');
        datalist.id = 'catSuggestions';
        document.body.appendChild(datalist);
    }
    datalist.innerHTML = ALL_CATEGORIES.map(c => `<option value="${c}">`).join('');

    const saveBtn = document.createElement('button');
    saveBtn.className = 'btn-icon-action';
    saveBtn.innerHTML = '✅';

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn-icon-action';
    cancelBtn.innerHTML = '❌';

    container.appendChild(input);
    container.appendChild(saveBtn);
    container.appendChild(cancelBtn);

    parentTd.innerHTML = '';
    parentTd.appendChild(container);
    input.focus();
    input.select();

    const save = async () => {
        const newCat = input.value.trim();
        if (!newCat) return;
        try {
            const data = await KaiHttp.postJson('api.php', { item_id: itemId, category_name: newCat });
            if (data.success) {
                location.reload();
            } else {
                alert('Fehler beim Speichern: ' + (data.message || data.error || 'Unbekannter Fehler'));
            }
        } catch (e) {
            alert('Netzwerkfehler beim Speichern.');
        }
    };

    saveBtn.addEventListener('click', save);
    cancelBtn.addEventListener('click', () => parentTd.innerHTML = originalHtml);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') save();
        if (e.key === 'Escape') parentTd.innerHTML = originalHtml;
    });
}