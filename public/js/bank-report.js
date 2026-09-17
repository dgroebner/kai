/**
 * Bank Finanzreport Script
 * Steuert die Generierung und Aktualisierung der KI-Finanzanalyse.
 */
document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const container = document.getElementById('report-container');
    if (!container) {
        return;
    }

    const periodType = container.getAttribute('data-period-type') || 'month';
    const periodTarget = container.getAttribute('data-period-target') || '';
    const feedbackBanner = document.getElementById('report-feedback-banner');

    function showFeedback(message, isError = false) {
        if (!feedbackBanner) return;
        feedbackBanner.textContent = message;
        feedbackBanner.className = 'report-feedback-banner ' + (isError ? 'report-feedback-error' : 'report-feedback-loading');
        feedbackBanner.classList.remove('hidden');
    }

    function hideFeedback() {
        if (!feedbackBanner) return;
        feedbackBanner.classList.add('hidden');
    }

    async function generateReport() {
        const btnHeader = document.getElementById('btn-generate-report');
        const btnInline = document.getElementById('btn-generate-report-inline');

        if (btnHeader) btnHeader.disabled = true;
        if (btnInline) btnInline.disabled = true;

        showFeedback('⏳ Finanzdaten werden aggregiert und durch Gemini analysiert... Bitte einen Moment Geduld.');

        try {
            const response = await KaiHttp.postJson('api.php', {
                action: 'generate_financial_report',
                period_type: periodType,
                period_target: periodTarget
            });

            if (response && response.success) {
                showFeedback('✅ Finanzreport erfolgreich generiert! Seite wird aktualisiert...');
                setTimeout(() => {
                    window.location.reload();
                }, 600);
            } else {
                const err = response && response.message ? response.message : 'Generierung fehlgeschlagen.';
                showFeedback('❌ Fehler: ' + err, true);
                if (btnHeader) btnHeader.disabled = false;
                if (btnInline) btnInline.disabled = false;
            }
        } catch (error) {
            console.error('Fehler bei Bericht-Generierung:', error);
            showFeedback('❌ Netzwerk- oder Serverfehler bei der Bericht-Generierung.', true);
            if (btnHeader) btnHeader.disabled = false;
            if (btnInline) btnInline.disabled = false;
        }
    }

    const btnGenerate = document.getElementById('btn-generate-report');
    if (btnGenerate) {
        btnGenerate.addEventListener('click', generateReport);
    }

    const btnGenerateInline = document.getElementById('btn-generate-report-inline');
    if (btnGenerateInline) {
        btnGenerateInline.addEventListener('click', generateReport);
    }

    // =========================================================
    // Interaktive Tooltips für Trend-Chart & Barometer
    // =========================================================
    let tooltip = document.getElementById('global-chart-tooltip');
    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.id = 'global-chart-tooltip';
        tooltip.className = 'chart-tooltip';
        document.body.appendChild(tooltip);
    }

    document.addEventListener('mouseover', (e) => {
        const el = e.target.closest('[data-tooltip]');
        if (!el) return;

        const text = el.dataset.tooltip;
        if (!text) return;

        tooltip.innerHTML = text;
        tooltip.style.display = 'block';
    });

    document.addEventListener('mousemove', (e) => {
        if (tooltip.style.display === 'block') {
            tooltip.style.left = `${e.clientX}px`;
            tooltip.style.top = `${e.clientY - 15}px`;
        }
    });

    document.addEventListener('mouseout', (e) => {
        const el = e.target.closest('[data-tooltip]');
        if (el) {
            tooltip.style.display = 'none';
        }
    });
});

