/**
 * Projects Companion — Administration Frontend Client
 * CSP-konform ausgelagert, kein Inline-Script.
 */
document.addEventListener('DOMContentLoaded', () => {
    // Modal Open/Close
    document.querySelectorAll('[data-open-modal]').forEach((b) => {
        b.addEventListener('click', () => {
            const m = document.getElementById(b.getAttribute('data-open-modal'));
            if (m) m.style.display = 'flex';
        });
    });
    document.querySelectorAll('[data-close-modal]').forEach((b) => {
        b.addEventListener('click', () => {
            const m = b.closest('.proj-modal-backdrop');
            if (m) m.style.display = 'none';
        });
    });

    // Einladen Formular
    const inviteForm = document.getElementById('form-invite-user');
    if (inviteForm) {
        inviteForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(inviteForm);
            const payload = {
                action: 'invite_user',
                email: fd.get('email'),
                name: fd.get('name'),
                state_code: fd.get('state_code'),
                send_mail: fd.get('send_mail') === '1',
            };
            const res = await KaiHttp.postJson('admin_api.php', payload);
            if (res.success) {
                alert(res.message);
                location.reload();
            } else {
                alert(res.message || 'Fehler');
            }
        });
    }

    // Postfach leeren
    const purgeBtn = document.getElementById('btn-purge-mailbox');
    if (purgeBtn) {
        purgeBtn.addEventListener('click', async () => {
            if (!confirm('Postfach projects@agent-smith.de jetzt restlos leeren?')) return;
            purgeBtn.disabled = true;
            purgeBtn.textContent = 'Bereinige...';
            const res = await KaiHttp.postJson('admin_api.php', { action: 'purge_mailbox' });
            purgeBtn.disabled = false;
            purgeBtn.textContent = '🧹 Postfach leeren';
            alert(res.message);
        });
    }

    // Status umschalten
    document.querySelectorAll('[data-toggle-status]').forEach((b) => {
        b.addEventListener('click', async () => {
            const uid = b.getAttribute('data-toggle-status');
            const nStatus = b.getAttribute('data-new-status');
            const res = await KaiHttp.postJson('admin_api.php', { action: 'update_status', user_id: uid, status: nStatus });
            if (res.success) location.reload();
            else alert(res.message);
        });
    });

    // Löschen
    document.querySelectorAll('[data-delete-user]').forEach((b) => {
        b.addEventListener('click', async () => {
            if (!confirm('Nutzer und all seine Buchungen unwiderruflich löschen?')) return;
            const uid = b.getAttribute('data-delete-user');
            const res = await KaiHttp.postJson('admin_api.php', { action: 'delete_user', user_id: uid });
            if (res.success) location.reload();
            else alert(res.message);
        });
    });
});
