document.addEventListener('DOMContentLoaded', () => {
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.js-open-candidate-modal');
        if (btn) {
            e.preventDefault();
            const receiptId = btn.dataset.receiptId;
            openCandidateModal(receiptId);
        }
    });
});

async function openCandidateModal(receiptId) {
    try {
        const data = await KaiHttp.postJson('api.php', {
            action: 'get_candidates',
            receipt_id: parseInt(receiptId, 10)
        });

        if (data && data.success) {
            showModal(receiptId, data.candidates);
        }
    } catch (err) {
        console.error('Fehler beim Laden der Kandidaten:', err);
        alert('Kandidaten konnten nicht geladen werden.');
    }
}

function showModal(receiptId, candidates) {
    // Vorheriges Modal entfernen falls vorhanden
    document.getElementById('candidateModalOverlay')?.remove();

    const giro = candidates.giro || [];
    const cc = candidates.cc || [];
    const totalCandidates = giro.length + cc.length;

    const overlay = document.createElement('div');
    overlay.id = 'candidateModalOverlay';
    overlay.className = 'rule-modal-overlay';

    let candidatesHtml = '';

    if (totalCandidates === 0) {
        candidatesHtml = `<p class="text-muted text-center" style="padding: 1.5rem 0;">Keine passenden Buchungen im Zeitraum gefunden.</p>`;
    } else {
        const renderList = (items, typeLabel) => {
            if (items.length === 0) return '';
            return `
                <h4 style="margin-top: 1rem; margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--text-muted);">${typeLabel}</h4>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    ${items.map(tx => {
                        const txAmount = parseFloat(tx.amount);
                        const absAmount = Math.abs(txAmount);
                        return `
                            <div style="background: var(--bg-surface-hover); padding: 0.75rem; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong style="display: block; font-size: 0.9rem;"><?= date('d.m.Y', strtotime(${tx.booking_date})) ?> – ${escapeHtml(tx.merchant_raw)}</strong>
                                    <span style="font-size: 0.8rem; color: var(--text-muted);">Betrag: ${txAmount.toLocaleString('de-DE', { minimumFractionDigits: 2 })} €</span>
                                </div>
                                <div style="display: flex; gap: 0.4rem;">
                                    <button type="button" class="btn btn-sm js-link-tx" data-receipt-id="${receiptId}" data-tx-id="${tx.id}" data-account-type="${tx.account_type}" data-apply-cash="0">
                                        Verknüpfen
                                    </button>
                                    ${tx.account_type === 'giro' && txAmount < 0 ? `
                                        <button type="button" class="btn btn-sm btn-outline js-link-tx" data-receipt-id="${receiptId}" data-tx-id="${tx.id}" data-account-type="${tx.account_type}" data-apply-cash="1" title="Verknüpfen und Bargeld-Tag zuweisen">
                                            + Bargeld-Tag
                                        </button>
                                    ` : ''}
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
        };

        candidatesHtml = renderList(giro, '🏦 Girokonto-Buchungen') + renderList(cc, '💳 Kreditkarten-Abrechnungen');
    }

    overlay.innerHTML = `
        <div class="rule-modal-card" style="max-width: 500px;">
            <div class="rule-modal-header">
                <h3>🔍 Zuordnungskandidaten</h3>
                <button type="button" class="rule-modal-close js-close-candidate-modal">&times;</button>
            </div>
            <div class="rule-modal-body" style="max-height: 60vh; overflow-y: auto;">
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                    Wähle die passende Bankabbuchung für diesen Kassenbon aus:
                </p>
                ${candidatesHtml}
            </div>
            <div class="rule-modal-footer">
                <button type="button" class="btn btn-outline js-close-candidate-modal">Schließen</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    overlay.querySelectorAll('.js-close-candidate-modal').forEach(el => {
        el.addEventListener('click', () => overlay.remove());
    });

    overlay.querySelectorAll('.js-link-tx').forEach(btn => {
        btn.addEventListener('click', async () => {
            const rId = btn.dataset.receiptId;
            const tId = btn.dataset.txId;
            const accType = btn.dataset.accountType;
            const applyCash = btn.dataset.applyCash === '1';

            try {
                const res = await KaiHttp.postJson('api.php', {
                    action: 'link_manual',
                    receipt_id: parseInt(rId, 10),
                    tx_id: parseInt(tId, 10),
                    account_type: accType,
                    apply_cash_tag: applyCash
                });

                if (res && res.success) {
                    window.location.reload();
                } else {
                    alert('Verknüpfung fehlgeschlagen.');
                }
            } catch (err) {
                console.error('Fehler bei manueller Verknüpfung:', err);
            }
        });
    });
}

function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}