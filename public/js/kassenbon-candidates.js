/**
 * Modal zur manuellen Zuordnung eines Kassenbons zu einer Bankbuchung.
 * Bindet alle Interaktionen per Event Delegation ein (CSP-konform).
 */
document.addEventListener('click', (e) => {
    const openBtn = e.target.closest('.js-open-candidate-modal');
    if (openBtn) {
        e.preventDefault();
        openCandidateModal(openBtn.dataset.receiptId);
        return;
    }

    const overlay = document.getElementById('candidateModalOverlay');
    if (!overlay) {
        return;
    }

    if (e.target.closest('.js-close-candidate-modal') || e.target === overlay) {
        overlay.remove();
        return;
    }

    const linkBtn = e.target.closest('.js-link-tx');
    if (linkBtn) {
        e.preventDefault();
        linkTransaction(linkBtn);
    }
});

async function openCandidateModal(receiptId) {
    const id = parseInt(receiptId, 10);
    if (!Number.isInteger(id) || id <= 0) {
        return;
    }

    try {
        const data = await KaiHttp.postJson('api.php', {
            action: 'get_candidates',
            receipt_id: id
        });

        if (data && data.success) {
            showModal(id, data.candidates || {});
        } else {
            alert('Kandidaten konnten nicht geladen werden.');
        }
    } catch (err) {
        console.error('Fehler beim Laden der Kandidaten:', err);
        alert('Kandidaten konnten nicht geladen werden.');
    }
}

async function linkTransaction(btn) {
    if (btn.disabled) {
        return;
    }
    btn.disabled = true;

    try {
        const res = await KaiHttp.postJson('api.php', {
            action: 'link_manual',
            receipt_id: parseInt(btn.dataset.receiptId, 10),
            tx_id: parseInt(btn.dataset.txId, 10),
            account_type: btn.dataset.accountType,
            apply_cash_tag: btn.dataset.applyCash === '1'
        });

        if (res && res.success) {
            window.location.reload();
        } else {
            btn.disabled = false;
            alert('Verknüpfung fehlgeschlagen.');
        }
    } catch (err) {
        btn.disabled = false;
        console.error('Fehler bei manueller Verknüpfung:', err);
        alert('Verknüpfung fehlgeschlagen.');
    }
}

function showModal(receiptId, candidates) {
    document.getElementById('candidateModalOverlay')?.remove();

    const giro = Array.isArray(candidates.giro) ? candidates.giro : [];
    const cc = Array.isArray(candidates.cc) ? candidates.cc : [];

    let candidatesHtml;
    if (giro.length + cc.length === 0) {
        candidatesHtml = '<p class="candidate-empty">Keine passenden Buchungen im Zeitraum gefunden.</p>';
    } else {
        candidatesHtml = renderCandidateGroup(receiptId, giro, '🏦 Girokonto-Buchungen')
            + renderCandidateGroup(receiptId, cc, '💳 Kreditkarten-Abrechnungen');
    }

    const overlay = document.createElement('div');
    overlay.id = 'candidateModalOverlay';
    overlay.className = 'rule-modal-overlay';
    overlay.innerHTML = `
        <div class="rule-modal-card candidate-modal-card">
            <div class="rule-modal-header">
                <h3>🔍 Zuordnungskandidaten</h3>
                <button type="button" class="rule-modal-close js-close-candidate-modal">&times;</button>
            </div>
            <div class="rule-modal-body candidate-modal-body">
                <p class="candidate-hint">Wähle die passende Bankbuchung für diesen Kassenbon aus:</p>
                ${candidatesHtml}
            </div>
            <div class="rule-modal-footer">
                <button type="button" class="btn btn-outline js-close-candidate-modal">Schließen</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);
}

function renderCandidateGroup(receiptId, items, groupLabel) {
    if (!items.length) {
        return '';
    }

    const rows = items.map((tx) => {
        const amount = parseFloat(tx.amount) || 0;
        const info = tx.info ? `<span class="candidate-meta">${escapeHtml(tx.info)}</span>` : '';
        const cashButton = tx.account_type === 'giro' && tx.is_cash
            ? `<button type="button" class="btn btn-sm btn-outline js-link-tx"
                    data-receipt-id="${receiptId}" data-tx-id="${tx.id}"
                    data-account-type="${escapeHtml(tx.account_type)}" data-apply-cash="1"
                    title="Verknüpfen und Bargeld-Tag zuweisen">+ Bargeld-Tag</button>`
            : '';

        return `
            <div class="candidate-item">
                <div class="candidate-info">
                    <strong class="candidate-title">${formatDate(tx.booking_date)} – ${escapeHtml(tx.merchant_raw)}</strong>
                    <span class="candidate-meta">Betrag: ${formatAmount(amount)} €</span>
                    ${info}
                </div>
                <div class="candidate-actions">
                    <button type="button" class="btn btn-sm js-link-tx"
                        data-receipt-id="${receiptId}" data-tx-id="${tx.id}"
                        data-account-type="${escapeHtml(tx.account_type)}" data-apply-cash="0">Verknüpfen</button>
                    ${cashButton}
                </div>
            </div>
        `;
    }).join('');

    return `<h4 class="candidate-group-title">${groupLabel}</h4><div class="candidate-list">${rows}</div>`;
}

function formatDate(isoDate) {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(isoDate ?? ''));
    return match ? `${match[3]}.${match[2]}.${match[1]}` : '';
}

function formatAmount(value) {
    return value.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escapeHtml(value) {
    return KaiHtml.escape(value);
}
