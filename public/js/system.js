document.addEventListener('DOMContentLoaded', function () {
    // Letzte bekannte ID initialisieren
    let lastActivityId = parseInt(document.body.dataset.lastActivityId || 0, 10);

    function pollNewActivities() {
        fetch(`/system/api.php?last_id=${lastActivityId}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Netzwerkfehler beim Activity-Polling');
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.activities && data.activities.length > 0) {
                    data.activities.forEach(activity => {
                        showActivityToast(activity.message, activity.link_url);

                        if (activity.id > lastActivityId) {
                            lastActivityId = parseInt(activity.id, 10);
                        }
                    });
                }
            })
            .catch(error => {
                console.debug('Activity Polling Info:', error);
            });
    }

    function showActivityToast(text, link) {
        let container = document.getElementById('activity-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'activity-toast-container';
            container.style.cssText = 'position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; max-width: 350px;';
            document.body.appendChild(container);
        }

        const bubble = document.createElement('div');
        bubble.style.cssText = `
            background-color: #1a1d24; 
            color: #ffffff; 
            padding: 16px; 
            border-radius: 10px; 
            border: 1px solid #1b4b8a; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.3); 
            font-size: 0.9rem; 
            opacity: 0; 
            transition: opacity 0.3s ease, transform 0.3s ease; 
            transform: translateY(10px);
        `;

        const escape = window.KaiHtml && window.KaiHtml.escape ? window.KaiHtml.escape : (str => String(str ?? ''));

        let content = `
            <div style="font-size: 0.75rem; font-weight: 600; letter-spacing: 0.05em; color: #8a9ba8; margin-bottom: 6px; text-transform: uppercase;">
                Aktivität
            </div>
            <div style="color: #e2e8f0; line-height: 1.4;">
                ${escape(text)}
            </div>
        `;

        if (link) {
            content += `
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    <a href="${escape(link)}" style="color: #3b82f6; text-decoration: none; font-weight: 500;">
                        Details ansehen &rarr;
                    </a>
                </div>
            `;
        }

        bubble.innerHTML = content;
        container.appendChild(bubble);

        requestAnimationFrame(() => {
            bubble.style.opacity = '1';
            bubble.style.transform = 'translateY(0)';
        });

        setTimeout(() => {
            bubble.style.opacity = '0';
            bubble.style.transform = 'translateY(10px)';
            setTimeout(() => bubble.remove(), 300);
        }, 6000);
    }

    setInterval(pollNewActivities, 10000);

    // Globaler Confirm-Handler für Lösch- und sensible Formulare
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (form && form.classList && form.classList.contains('js-confirm-delete')) {
            const msg = form.getAttribute('data-confirm-message') || 'Diesen Eintrag wirklich löschen?';
            if (!confirm(msg)) {
                e.preventDefault();
            }
        }
    });

    // Event-Delegation für Schülerpflege (System -> Tab Schule)
    document.addEventListener('click', function (e) {
        // Neuen Schüler anlegen Modal öffnen
        const addBtn = e.target.closest('.js-add-student');
        if (addBtn) {
            e.preventDefault();
            const form = document.getElementById('student-form');
            if (form) form.reset();

            const idInput = document.getElementById('st_id');
            const colorInput = document.getElementById('st_color');
            const activeCheckbox = document.getElementById('st_active');
            const title = document.getElementById('student-modal-title');
            const submitBtn = document.getElementById('st_submit_btn');

            if (idInput) idInput.value = '';
            if (colorInput) colorInput.value = '#0284c7';
            if (activeCheckbox) activeCheckbox.checked = true;
            if (title) title.textContent = 'Neuen Schüler anlegen';
            if (submitBtn) submitBtn.textContent = '➕ Schülerprofil speichern';

            const modal = document.getElementById('student-modal');
            if (modal) {
                modal.classList.remove('hidden');
                const nameInput = document.getElementById('st_name');
                if (nameInput) nameInput.focus();
            }
            return;
        }

        // Bestehenden Schüler bearbeiten
        const editBtn = e.target.closest('.js-edit-student');
        if (editBtn) {
            e.preventDefault();
            const id = editBtn.getAttribute('data-id') || '';
            const name = editBtn.getAttribute('data-name') || '';
            const className = editBtn.getAttribute('data-class') || '';
            const besteId = editBtn.getAttribute('data-besteid') || '';
            const email = editBtn.getAttribute('data-email') || '';
            const color = editBtn.getAttribute('data-color') || '#0284c7';
            const excluded = editBtn.getAttribute('data-excluded') || '';
            const active = editBtn.getAttribute('data-active') !== '0';

            const form = document.getElementById('student-form');
            if (!form) return;

            const idInput = document.getElementById('st_id');
            const nameInput = document.getElementById('st_name');
            const classInput = document.getElementById('st_class');
            const besteIdInput = document.getElementById('st_beste_id');
            const emailSelect = document.getElementById('st_email');
            const colorInput = document.getElementById('st_color');
            const excludedInput = document.getElementById('st_excluded');
            const activeCheckbox = document.getElementById('st_active');
            const title = document.getElementById('student-modal-title');
            const submitBtn = document.getElementById('st_submit_btn');

            if (idInput) idInput.value = id;
            if (nameInput) nameInput.value = name;
            if (classInput) classInput.value = className;
            if (besteIdInput) besteIdInput.value = besteId;
            if (emailSelect) emailSelect.value = email;
            if (colorInput) colorInput.value = color;
            if (excludedInput) excludedInput.value = excluded;
            if (activeCheckbox) activeCheckbox.checked = active;

            if (title) title.textContent = `Schülerprofil bearbeiten: ${name}`;
            if (submitBtn) submitBtn.textContent = '💾 Änderungen speichern';

            const modal = document.getElementById('student-modal');
            if (modal) {
                modal.classList.remove('hidden');
                if (nameInput) nameInput.focus();
            }
            return;
        }

        // Modal schließen
        const closeBtn = e.target.closest('.js-close-student-modal');
        if (closeBtn) {
            e.preventDefault();
            const modal = document.getElementById('student-modal');
            if (modal) modal.classList.add('hidden');
            return;
        }

        // Backdrop-Klick schließt das Modal
        const studentModal = document.getElementById('student-modal');
        if (studentModal && e.target === studentModal) {
            studentModal.classList.add('hidden');
        }
    });

    // Escape-Taste schließt das Schüler-Modal
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const studentModal = document.getElementById('student-modal');
            if (studentModal && !studentModal.classList.contains('hidden')) {
                studentModal.classList.add('hidden');
            }
        }
    });
});