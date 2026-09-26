document.addEventListener('DOMContentLoaded', () => {
    const dataContainer = window.INFLATION_DATA;
    if (!dataContainer || !Array.isArray(dataContainer.products)) {
        return;
    }

    const allProducts = dataContainer.products;
    const monthlyTrend = dataContainer.monthlyTrend || { labels: [], values: [] };

    let filteredProducts = [...allProducts];
    let currentFilter = 'all';
    let searchQuery = '';
    let currentPage = 1;
    const itemsPerPage = 20;

    let monthlyChart = null;
    let detailChart = null;
    let currentModalProduct = null;

    // ==========================================
    // 1. Monatlicher Trend Chart (Makro)
    // ==========================================
    const monthlyCanvas = document.getElementById('monthlyTrendChart');
    if (monthlyCanvas && typeof Chart !== 'undefined' && monthlyTrend.labels.length > 1) {
        const ctx = monthlyCanvas.getContext('2d');
        const isUp = (monthlyTrend.values[monthlyTrend.values.length - 1] || 0) >= 0;
        const lineColor = isUp ? '#ef4444' : '#10b981';

        monthlyChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: monthlyTrend.labels,
                datasets: [{
                    label: 'Preisveränderung (%)',
                    data: monthlyTrend.values,
                    borderColor: lineColor,
                    backgroundColor: isUp ? 'rgba(239, 68, 68, 0.1)' : 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.25,
                    pointBackgroundColor: lineColor,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const val = ctx.parsed.y;
                                return (val > 0 ? '+' : '') + val.toFixed(1).replace('.', ',') + ' %';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: (v) => (v > 0 ? '+' : '') + v + '%'
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.06)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // ==========================================
    // 2. Tabellenfilterung & Suche
    // ==========================================
    const searchInput = document.getElementById('productSearchInput');
    const tableBody = document.getElementById('inflationTableBody');
    const paginationEl = document.getElementById('inflationPagination');
    const filterPills = document.querySelectorAll('.js-table-filter');

    function applyFilters() {
        const q = searchQuery.toLowerCase().trim();

        filteredProducts = allProducts.filter(p => {
            // Trend-Filter
            if (currentFilter === 'increased' && p.trend !== 'increased') return false;
            if (currentFilter === 'decreased' && p.trend !== 'decreased') return false;
            if (currentFilter === 'stable' && p.trend !== 'stable') return false;
            if (currentFilter === 'jumps' && !p.has_price_jump) return false;
            if (currentFilter === 'deals' && (!p.has_deals || p.deals_count <= 0)) return false;

            // Textsuche
            if (q !== '') {
                const matchName = p.name.toLowerCase().includes(q);
                const matchStore = p.store.toLowerCase().includes(q);
                const matchCat = (p.category || '').toLowerCase().includes(q);
                if (!matchName && !matchStore && !matchCat) {
                    return false;
                }
            }
            return true;
        });

        currentPage = 1;
        renderTable();
    }

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            searchQuery = e.target.value;
            applyFilters();
        });
    }

    filterPills.forEach(pill => {
        pill.addEventListener('click', () => {
            filterPills.forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            currentFilter = pill.getAttribute('data-filter') || 'all';
            applyFilters();
        });
    });

    // ==========================================
    // 3. Tabellenzeilen rendern & Paginierung
    // ==========================================
    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const parts = dateStr.split('-');
        if (parts.length === 3) {
            return `${parts[2]}.${parts[1]}.${parts[0]}`;
        }
        return dateStr;
    }

    function formatCurrency(num) {
        return (parseFloat(num) || 0).toFixed(2).replace('.', ',') + ' €';
    }

    function renderTable() {
        if (!tableBody) return;

        if (filteredProducts.length === 0) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center text-muted" style="padding: 2rem;">
                        Keine passenden Artikel gefunden.
                    </td>
                </tr>
            `;
            if (paginationEl) paginationEl.innerHTML = '';
            return;
        }

        const totalPages = Math.ceil(filteredProducts.length / itemsPerPage);
        if (currentPage > totalPages) currentPage = totalPages;
        const startIndex = (currentPage - 1) * itemsPerPage;
        const pageItems = filteredProducts.slice(startIndex, startIndex + itemsPerPage);

        let html = '';
        pageItems.forEach(p => {
            const changePct = parseFloat(p.price_change_pct) || 0;
            const changeClass = changePct > 0 ? 'change-up' : (changePct < 0 ? 'change-down' : 'change-stable');
            const changeSign = changePct > 0 ? '+' : '';

            let jumpHtml = '<span class="text-muted">-</span>';
            if (p.has_price_jump) {
                const jSign = p.max_jump_pct > 0 ? '+' : '';
                const jClass = p.max_jump_pct > 0 ? 'jump-pill-up' : 'jump-pill-down';
                jumpHtml = `<span class="jump-pill ${jClass}" title="${p.max_jump_date ? 'Am ' + formatDate(p.max_jump_date) : ''}">
                    ⚡ ${jSign}${p.max_jump_pct.toFixed(1).replace('.', ',')}%
                </span>`;
            }

            // Grundpreis-Anzeige
            let basePriceSub = '';
            if (p.formatted_base_price) {
                basePriceSub = `<div class="base-price-sub">${KaiHtml.escape(p.formatted_base_price)}</div>`;
            }

            // Sonderangebot-Indikator
            let dealsBadge = '';
            if (p.has_deals && p.deals_count > 0) {
                dealsBadge = `<span class="deal-indicator-badge" title="${p.deals_count}x im Sonderangebot gekauft">🎉 ${p.deals_count}x</span>`;
            }

            html += `
                <tr class="clickable-row js-open-detail" data-key="${KaiHtml.escape(p.key)}">
                    <td data-label="Artikel & Händler">
                        <div class="product-cell-name cell-strong">
                            ${KaiHtml.escape(p.name)}
                            ${dealsBadge}
                        </div>
                        <div class="product-cell-store text-muted">${KaiHtml.escape(p.store)}</div>
                    </td>
                    <td data-label="Kategorie">
                        <span class="category-badge">${KaiHtml.escape(p.category || 'Sonstiges')}</span>
                    </td>
                    <td data-label="Käufe" class="text-right">
                        ${parseInt(p.purchase_count, 10)}x
                    </td>
                    <td data-label="Erster Preis" class="text-right">
                        <div>${formatCurrency(p.first_price)}</div>
                        <small class="text-muted">${formatDate(p.first_date)}</small>
                    </td>
                    <td data-label="Letzter Preis / Grundpreis" class="text-right">
                        <div class="amount-bold">${formatCurrency(p.last_price)}</div>
                        ${basePriceSub}
                        <small class="text-muted">${formatDate(p.last_date)}</small>
                    </td>
                    <td data-label="Min / Max" class="text-right">
                        <span>${formatCurrency(p.min_price)} / ${formatCurrency(p.max_price)}</span>
                    </td>
                    <td data-label="Max. Sprung" class="text-right">
                        ${jumpHtml}
                    </td>
                    <td data-label="Veränderung" class="text-right">
                        <span class="price-change ${changeClass} amount-bold">
                            ${changeSign}${changePct.toFixed(1).replace('.', ',')} %
                        </span>
                        <div class="text-muted" style="font-size: 0.75rem;">
                            (${changeSign}${formatCurrency(p.price_change_abs)})
                        </div>
                    </td>
                    <td data-label="Aktion" class="text-right">
                        <button type="button" class="btn btn-sm btn-outline btn-icon-only" title="Preisverlauf anzeigen">
                            📈
                        </button>
                    </td>
                </tr>
            `;
        });

        tableBody.innerHTML = html;
        renderPagination(totalPages);
    }

    function renderPagination(totalPages) {
        if (!paginationEl) return;
        if (totalPages <= 1) {
            paginationEl.innerHTML = '';
            return;
        }

        let html = '';
        if (currentPage > 1) {
            html += `<button type="button" class="btn btn-outline js-page-btn" data-page="${currentPage - 1}">&laquo; Vorherige</button>`;
        } else {
            html += `<span class="btn btn-outline disabled">&laquo; Vorherige</span>`;
        }

        html += `<span class="page-info">Seite ${currentPage} von ${totalPages} (${filteredProducts.length} Artikel)</span>`;

        if (currentPage < totalPages) {
            html += `<button type="button" class="btn btn-outline js-page-btn" data-page="${currentPage + 1}">Nächste &raquo;</button>`;
        } else {
            html += `<span class="btn btn-outline disabled">Nächste &raquo;</span>`;
        }

        paginationEl.innerHTML = html;
    }

    // Paginierungs-Klicks per Event-Delegation
    if (paginationEl) {
        paginationEl.addEventListener('click', (e) => {
            const btn = e.target.closest('.js-page-btn');
            if (btn && btn.dataset.page) {
                currentPage = parseInt(btn.dataset.page, 10);
                renderTable();
                const container = document.getElementById('inflationProductsTable');
                if (container) {
                    container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        });
    }

    // Initial rendern
    renderTable();

    // ==========================================
    // 4. Detail-Modal (Preisverlauf & Chart)
    // ==========================================
    const modalEl = document.getElementById('productDetailModal');
    const modalTitle = document.getElementById('modalProductName');
    const modalMeta = document.getElementById('modalProductMeta');
    const modalKpiRow = document.getElementById('modalKpiRow');
    const modalTableBody = document.getElementById('modalHistoryTableBody');
    const modalCanvas = document.getElementById('productHistoryChart');

    // Open Food Facts Box
    const btnTriggerOff = document.getElementById('btnTriggerOff');
    const offResultWrap = document.getElementById('offResultWrap');

    function openProductDetail(productKey) {
        if (!modalEl || !productKey) return;

        const product = allProducts.find(p => p.key === productKey);
        if (!product) return;
        currentModalProduct = product;

        if (modalTitle) modalTitle.textContent = product.name;
        if (modalMeta) modalMeta.textContent = `${product.store} • Kategorie: ${product.category || 'Sonstiges'}`;

        const changePct = parseFloat(product.price_change_pct) || 0;
        const changeSign = changePct > 0 ? '+' : '';
        const changeColor = changePct > 0 ? '#ef4444' : (changePct < 0 ? '#10b981' : 'var(--text-muted)');

        // Grundpreis-Zeile
        let basePriceHtml = '-';
        if (product.formatted_base_price) {
            basePriceHtml = product.formatted_base_price;
        }

        // Modal KPI Kacheln
        if (modalKpiRow) {
            modalKpiRow.innerHTML = `
                <div class="modal-kpi-item">
                    <span class="label">Erster Preis</span>
                    <span class="value">${formatCurrency(product.first_price)}</span>
                    <small class="sub">${formatDate(product.first_date)}</small>
                </div>
                <div class="modal-kpi-item">
                    <span class="label">Aktueller Preis</span>
                    <span class="value">${formatCurrency(product.last_price)}</span>
                    <small class="sub">${formatDate(product.last_date)}</small>
                </div>
                <div class="modal-kpi-item">
                    <span class="label">Grundpreis (normiert)</span>
                    <span class="value amount-bold" style="color: var(--accent);">${KaiHtml.escape(basePriceHtml)}</span>
                    <small class="sub">${product.formatted_package ? 'Packung: ' + KaiHtml.escape(product.formatted_package) : 'Stückpreis'}</small>
                </div>
                <div class="modal-kpi-item">
                    <span class="label">Normalpreis (Median)</span>
                    <span class="value">${formatCurrency(product.median_price)}</span>
                    <small class="sub">${product.deals_count > 0 ? '🎉 ' + product.deals_count + 'x im Angebot' : 'Keine Sonderangebote'}</small>
                </div>
                <div class="modal-kpi-item">
                    <span class="label">Veränderung</span>
                    <span class="value" style="color: ${changeColor};">
                        ${changeSign}${changePct.toFixed(1).replace('.', ',')}%
                    </span>
                    <small class="sub">${changeSign}${formatCurrency(product.price_change_abs)}</small>
                </div>
                <div class="modal-kpi-item">
                    <span class="label">Käufe / Gesamtausgaben</span>
                    <span class="value">${product.purchase_count}x (${product.total_quantity} Stk)</span>
                    <small class="sub">${formatCurrency(product.total_spent)}</small>
                </div>
            `;
        }

        // Reset Open Food Facts Box
        if (offResultWrap) {
            offResultWrap.classList.add('hidden');
            offResultWrap.innerHTML = '';
        }
        if (btnTriggerOff) {
            btnTriggerOff.textContent = '🔍 Daten abrufen';
            btnTriggerOff.disabled = false;
        }

        // Historie-Tabelle befüllen (neueste zuerst)
        if (modalTableBody) {
            const sortedPurchases = [...product.purchases].reverse();
            let rowsHtml = '';
            sortedPurchases.forEach(p => {
                let statusHtml = '<span class="text-muted">Normal</span>';
                if (p.is_deal) {
                    statusHtml = `<span class="deal-pill" title="Rabatt gegenüber Normalpreis: ca. ${p.deal_discount_pct}%">🎉 Angebot -${p.deal_discount_pct.toFixed(0)}%</span>`;
                }

                rowsHtml += `
                    <tr>
                        <td data-label="Datum">${formatDate(p.purchase_date)}</td>
                        <td data-label="Händler">${KaiHtml.escape(p.store)}</td>
                        <td data-label="Menge" class="text-right">${parseFloat(p.quantity).toFixed(2).replace('.', ',')}x</td>
                        <td data-label="Einzelpreis" class="text-right amount-bold">${formatCurrency(p.unit_price)}</td>
                        <td data-label="Status" class="text-right">${statusHtml}</td>
                        <td data-label="Gesamt" class="text-right">${formatCurrency(p.total_price)}</td>
                        <td data-label="Bon" class="text-right">
                            <a href="detail.php?id=${parseInt(p.receipt_id, 10)}" class="btn btn-sm btn-outline" target="_blank" title="Kassenbon öffnen">
                                🧾 Bon
                            </a>
                        </td>
                    </tr>
                `;
            });
            modalTableBody.innerHTML = rowsHtml;
        }

        // ChartJS Line Chart für Preisverlauf
        if (modalCanvas && typeof Chart !== 'undefined') {
            if (detailChart) {
                detailChart.destroy();
            }

            const chartLabels = product.purchases.map(p => formatDate(p.purchase_date));
            const chartPrices = product.purchases.map(p => parseFloat(p.unit_price));
            const pointColors = product.purchases.map(p => p.is_deal ? '#f59e0b' : '#3b82f6');

            detailChart = new Chart(modalCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'Einzelpreis (€)',
                        data: chartPrices,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        stepped: 'before',
                        pointBackgroundColor: pointColors,
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 1.5,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => {
                                    const p = product.purchases[ctx.dataIndex];
                                    let txt = 'Preis: ' + ctx.parsed.y.toFixed(2).replace('.', ',') + ' €';
                                    if (p && p.is_deal) {
                                        txt += ` (🎉 Sonderangebot -${p.deal_discount_pct}%)`;
                                    }
                                    return txt;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            ticks: {
                                callback: (v) => v.toFixed(2).replace('.', ',') + ' €'
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.06)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        modalEl.classList.remove('hidden');
    }

    function closeModal() {
        if (!modalEl) return;
        modalEl.classList.add('hidden');
        if (detailChart) {
            detailChart.destroy();
            detailChart = null;
        }
        currentModalProduct = null;
    }

    // ==========================================
    // 5. Open Food Facts Abfrage
    // ==========================================
    if (btnTriggerOff) {
        btnTriggerOff.addEventListener('click', async () => {
            if (!currentModalProduct || !offResultWrap) return;

            btnTriggerOff.textContent = '⏳ Lade...';
            btnTriggerOff.disabled = true;

            try {
                const res = await KaiHttp.postJson('api.php', {
                    action: 'lookup_open_food_facts',
                    query: currentModalProduct.name,
                    product_key: currentModalProduct.key
                });

                if (res.success && res.data) {
                    const d = res.data;
                    if (d.found) {
                        let nutriHtml = '';
                        if (d.nutriscore_grade) {
                            nutriHtml = `<span class="nutriscore-badge nutriscore-${d.nutriscore_grade.toLowerCase()}">Nutri-Score ${d.nutriscore_grade}</span>`;
                        }

                        offResultWrap.innerHTML = `
                            <div class="off-result-card">
                                ${d.image_url ? `<img src="${KaiHtml.escape(d.image_url)}" alt="Produktbild" class="off-product-thumb">` : ''}
                                <div class="off-info">
                                    <div class="off-name cell-strong">${KaiHtml.escape(d.product_name || currentModalProduct.name)}</div>
                                    <div class="off-details text-muted">
                                        ${d.brands ? 'Marke: <strong>' + KaiHtml.escape(d.brands) + '</strong> • ' : ''}
                                        ${d.quantity ? 'Füllmenge: <strong>' + KaiHtml.escape(d.quantity) + '</strong> • ' : ''}
                                        EAN: <code class="ean-code">${KaiHtml.escape(d.code)}</code>
                                    </div>
                                    <div class="off-badges" style="margin-top: 4px;">
                                        ${nutriHtml}
                                    </div>
                                </div>
                            </div>
                        `;
                        offResultWrap.classList.remove('hidden');
                        btnTriggerOff.textContent = '✅ Daten vorhanden';
                    } else if (d.queued || d.status === 'pending') {
                        offResultWrap.innerHTML = `
                            <div class="off-empty text-muted">
                                ⏳ <strong>In Warteschlange eingereiht</strong><br>
                                Die Daten werden beim nächsten Zyklus des lokalen Raspberry-Pi-Workers über deine Heim-IP abgerufen und gespeichert.
                            </div>
                        `;
                        offResultWrap.classList.remove('hidden');
                        btnTriggerOff.textContent = '⏳ In Warteschlange';
                    } else {
                        offResultWrap.innerHTML = `
                            <div class="off-empty text-muted">
                                Kein Treffer in Open Food Facts für „${KaiHtml.escape(currentModalProduct.name)}“ gefunden.
                            </div>
                        `;
                        offResultWrap.classList.remove('hidden');
                        btnTriggerOff.textContent = 'Wiederholen';
                        btnTriggerOff.disabled = false;
                    }
                }
            } catch (err) {
                offResultWrap.innerHTML = `
                    <div class="off-empty text-muted">
                        Fehler bei der Kommunikation. Bitte später erneut versuchen.
                    </div>
                `;
                offResultWrap.classList.remove('hidden');
                btnTriggerOff.textContent = 'Wiederholen';
                btnTriggerOff.disabled = false;
            }
        });
    }

    // Modal schliessen bei Close-Buttons oder Klick ins Overlay
    document.addEventListener('click', (e) => {
        if (e.target.closest('.js-modal-close')) {
            closeModal();
            return;
        }

        if (e.target === modalEl) {
            closeModal();
            return;
        }

        // Klick auf Tabellenzeile oder Highlight-Item
        const row = e.target.closest('.js-open-detail');
        if (row && row.dataset.key) {
            if (e.target.closest('a') && !e.target.closest('.js-open-detail')) return;
            openProductDetail(row.dataset.key);
        }
    });

    // Escape-Taste schließt Modal
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modalEl && !modalEl.classList.contains('hidden')) {
            closeModal();
        }
    });
});
