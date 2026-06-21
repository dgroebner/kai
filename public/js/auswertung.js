document.addEventListener('DOMContentLoaded', function() {
    
    const container = document.getElementById('auswertung-container');
    if (!container) return;

    const chartContainer = container.querySelector('.js-chart-container');
    const tableRows = container.querySelectorAll('.js-category-row');
    const itemRows = container.querySelectorAll('.js-item-row');

    // ==========================================
    // 1. Diagramm Rendering (SVG Donut Chart)
    // ==========================================
    function renderChart() {
        if (!chartContainer) return;

        const categories = [];
        let grandTotal = parseFloat(chartContainer.getAttribute('data-grand-total')) || 0;

        tableRows.forEach(row => {
            const name = row.getAttribute('data-category');
            const color = row.getAttribute('data-color') || '#64748b';
            const percentage = parseFloat(row.getAttribute('data-percentage')) || 0;
            const total = parseFloat(row.getAttribute('data-total')) || 0;
            categories.push({ name, percentage, total, color, row });
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
        
        // Glow Filter Definition
        svgHtml += `
          <defs>
            <filter id="glow-filter" x="-20%" y="-20%" width="140%" height="140%">
              <feGaussianBlur stdDeviation="4" result="blur" />
              <feComposite in="SourceGraphic" in2="blur" operator="over" />
            </filter>
          </defs>
        `;

        let currentAngle = -90; // Start bei 12 Uhr

        categories.forEach((cat) => {
            const sliceAngle = (cat.percentage / 100) * 360;
            if (sliceAngle <= 0) return;

            // Vektor für Hover-Verschiebung berechnen
            const bisectorAngleDeg = currentAngle + (sliceAngle / 2);
            const bisectorRad = bisectorAngleDeg * Math.PI / 180;
            const dx = Math.cos(bisectorRad) * 6;
            const dy = Math.sin(bisectorRad) * 6;

            let pathD = '';
            if (cat.percentage >= 99.9) {
                // Voller Kreis
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

        // Innerer Kreis für den Donut-Look
        svgHtml += `
            <circle cx="${cx}" cy="${cy}" r="${innerR}" fill="var(--bg-surface)" stroke="var(--bg-surface-hover)" stroke-width="1" />
        `;

        // Formatierung des Gesamtpreises in der Mitte des Donuts
        const formattedTotal = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(grandTotal);
        svgHtml += `
            <text x="${cx}" y="${cy - 4}" text-anchor="middle" fill="var(--text-muted)" font-size="10" font-weight="500">Gesamt</text>
            <text x="${cx}" y="${cy + 12}" text-anchor="middle" fill="var(--text-main)" font-size="13" font-weight="bold">${formattedTotal}</text>
        `;

        svgHtml += '</svg>';
        chartContainer.innerHTML = svgHtml;

        // Event-Listeners an die Slices binden
        const slices = chartContainer.querySelectorAll('.chart-slice');
        slices.forEach(slice => {
            const catName = decodeURIComponent(slice.getAttribute('data-category'));
            const dx = parseFloat(slice.getAttribute('data-dx'));
            const dy = parseFloat(slice.getAttribute('data-dy'));
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
                const activeFilter = container.getAttribute('data-active-filter');
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

            slice.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleCategoryFilter(catName);
            });
        });
    }

    // ==========================================
    // 2. Tabellenfilterung & Highlights
    // ==========================================
    function toggleCategoryFilter(catName) {
        const currentFilter = container.getAttribute('data-active-filter');
        if (currentFilter === catName) {
            container.removeAttribute('data-active-filter');
        } else {
            container.setAttribute('data-active-filter', catName);
        }
        updateFilterUI();
    }

    function updateFilterUI() {
        const activeFilter = container.getAttribute('data-active-filter');
        const slices = container.querySelectorAll('.chart-slice');

        if (activeFilter) {
            // Einzelpositionen filtern
            itemRows.forEach(row => {
                const rowCat = row.getAttribute('data-category');
                if (rowCat === activeFilter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            // Tabellenzeilen-Highlights aktualisieren
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

            // SVG Slice-Highlights aktualisieren
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
            // Filter zurücksetzen (alles anzeigen)
            itemRows.forEach(row => {
                row.style.display = '';
            });

            tableRows.forEach(row => {
                row.classList.remove('filter-active');
                row.classList.remove('highlight');
            });

            slices.forEach(slice => {
                slice.classList.remove('filter-active');
                slice.style.transform = '';
                slice.style.filter = '';
                slice.style.stroke = 'var(--bg-surface)';
                slice.style.strokeWidth = '2.5';
            });
        }
    }

    // Click Delegation für Kategorietabellen-Zeilen
    container.addEventListener('click', function(e) {
        const row = e.target.closest('.js-category-row');
        if (!row) return;
        const catName = row.getAttribute('data-category');
        toggleCategoryFilter(catName);
    });

    // Hover Delegation (mouseover) für Kategorietabellen-Zeilen
    container.addEventListener('mouseover', function(e) {
        const row = e.target.closest('.js-category-row');
        if (!row) return;
        const catName = row.getAttribute('data-category');
        const slice = container.querySelector(`.chart-slice[data-category="${encodeURIComponent(catName)}"]`);
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

    // Hover Delegation (mouseout) für Kategorietabellen-Zeilen
    container.addEventListener('mouseout', function(e) {
        const row = e.target.closest('.js-category-row');
        if (!row) return;
        const catName = row.getAttribute('data-category');
        const activeFilter = container.getAttribute('data-active-filter');
        if (activeFilter === catName) {
            return;
        }
        const slice = container.querySelector(`.chart-slice[data-category="${encodeURIComponent(catName)}"]`);
        if (slice) {
            slice.style.transform = '';
            slice.style.filter = '';
            slice.style.stroke = 'var(--bg-surface)';
            slice.style.strokeWidth = '2.5';
        }
        row.classList.remove('highlight');
    });

    // Initiales Diagramm-Rendering
    renderChart();
});
