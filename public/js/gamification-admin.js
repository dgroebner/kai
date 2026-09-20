/**
 * JavaScript für das Eltern-Cockpit (Familien-Quests Admin).
 *
 * Verwaltet Freigaben, Vorlagen, Prämien, Abzeichen und Punktekonten.
 */
document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    // 1. Tab-Wechsel
    document.querySelectorAll('.gamif-tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetTab = btn.getAttribute('data-tab');
            document.querySelectorAll('.gamif-tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.gamif-tab-content').forEach(c => c.classList.add('hidden'));

            btn.classList.add('active');
            const content = document.getElementById(targetTab);
            if (content) {
                content.classList.remove('hidden');
            }
        });
    });

    // 2. Modals schließen
    function closeModal(modal) {
        if (modal) {
            modal.classList.add('hidden');
        }
    }

    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const modal = e.target.closest('.modal-overlay');
            closeModal(modal);
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(closeModal);
        }
    });

    // 3. Fristen sofort prüfen (Eskalation & Tages-Sync)
    const syncEscalationBtn = document.querySelector('.js-sync-escalation-btn');
    if (syncEscalationBtn) {
        syncEscalationBtn.addEventListener('click', async () => {
            syncEscalationBtn.disabled = true;
            const res = await KaiHttp.postJson('api.php', { action: 'run_escalation' });
            if (res.success) {
                alert(res.message || 'Fristen geprüft!');
                window.location.reload();
            } else {
                alert(res.message || 'Fehler beim Fristenabgleich.');
                syncEscalationBtn.disabled = false;
            }
        });
    }

    // 4. Aufgabe genehmigen (Triage Approve)
    document.addEventListener('click', async (e) => {
        const approveBtn = e.target.closest('.js-triage-approve-btn');
        if (approveBtn) {
            const taskId = approveBtn.getAttribute('data-task-id');
            const coins = approveBtn.getAttribute('data-coins');
            const xp = approveBtn.getAttribute('data-xp');

            if (!confirm(`Aufgabe freigeben und ${coins} Münzen sowie ${xp} XP gutschreiben?`)) {
                return;
            }

            approveBtn.disabled = true;
            const res = await KaiHttp.postJson('api.php', {
                action: 'triage_review_task',
                task_id: parseInt(taskId, 10),
                sub_action: 'approve'
            });

            if (res.success) {
                if (res.new_achievements && res.new_achievements.length > 0) {
                    const badgeTitles = res.new_achievements.map(a => a.title).join(', ');
                    alert(`Aufgabe genehmigt! 🎉 Neues Abzeichen freigeschaltet: ${badgeTitles}`);
                } else {
                    alert(res.message || 'Aufgabe genehmigt!');
                }
                window.location.reload();
            } else {
                alert(res.message || 'Fehler bei der Genehmigung.');
                approveBtn.disabled = false;
            }
        }
    });

    // 5. Aufgabe zurückweisen / Nachbessern (Triage Reject)
    const modalReview = document.getElementById('modal-review');
    const reviewTaskIdInput = document.getElementById('review-task-id');
    const reviewFeedbackInput = document.getElementById('review-feedback');

    document.addEventListener('click', (e) => {
        const rejectBtn = e.target.closest('.js-triage-reject-btn');
        if (rejectBtn) {
            const taskId = rejectBtn.getAttribute('data-task-id');
            if (reviewTaskIdInput && modalReview) {
                reviewTaskIdInput.value = taskId;
                if (reviewFeedbackInput) reviewFeedbackInput.value = '';
                modalReview.classList.remove('hidden');
            }
        }
    });

    const formReview = document.getElementById('form-review');
    if (formReview) {
        formReview.addEventListener('submit', async (e) => {
            e.preventDefault();
            const taskId = reviewTaskIdInput.value;
            const feedback = reviewFeedbackInput.value.trim();

            const res = await KaiHttp.postJson('api.php', {
                action: 'triage_review_task',
                task_id: parseInt(taskId, 10),
                sub_action: 'reject',
                feedback: feedback
            });

            if (res.success) {
                alert('Rückmeldung gespeichert.');
                window.location.reload();
            } else {
                alert(res.message || 'Fehler beim Zurückweisen.');
            }
        });
    }

    // 6. Mithilfe (Co-Op) anerkennen oder ablehnen
    document.addEventListener('click', async (e) => {
        const helperBtn = e.target.closest('.js-review-helper-btn');
        if (helperBtn) {
            const helperId = helperBtn.getAttribute('data-helper-id');
            const approved = helperBtn.getAttribute('data-approved') === '1';

            helperBtn.disabled = true;
            const res = await KaiHttp.postJson('api.php', {
                action: 'triage_review_helper',
                helper_id: parseInt(helperId, 10),
                approved: approved
            });

            if (res.success) {
                alert(res.message || 'Aktualisiert!');
                window.location.reload();
            } else {
                alert(res.message || 'Fehler bei der Bewertung.');
                helperBtn.disabled = false;
            }
        }
    });

    // 7. Prämie genehmigen oder ablehnen
    document.addEventListener('click', async (e) => {
        const redBtn = e.target.closest('.js-review-redemption-btn');
        if (redBtn) {
            const redemptionId = redBtn.getAttribute('data-redemption-id');
            const action = redBtn.getAttribute('data-action');

            const confirmMsg = action === 'approve'
                ? 'Prämien-Antrag wirklich genehmigen?'
                : 'Prämien-Antrag ablehnen? Die Münzen werden dem Kind wieder gutgeschrieben.';

            if (!confirm(confirmMsg)) {
                return;
            }

            redBtn.disabled = true;
            const res = await KaiHttp.postJson('api.php', {
                action: 'triage_review_redemption',
                redemption_id: parseInt(redemptionId, 10),
                sub_action: action
            });

            if (res.success) {
                alert(res.message || 'Aktualisiert!');
                window.location.reload();
            } else {
                alert(res.message || 'Fehler beim Bearbeiten des Antrags.');
                redBtn.disabled = false;
            }
        }
    });

    // 8. Aufgaben-Vorlage anlegen / bearbeiten
    const modalTemplate = document.getElementById('modal-template');
    const formTemplate = document.getElementById('form-template');
    const openTemplateBtn = document.querySelector('.js-open-template-modal');
    const recurrenceSelect = document.getElementById('tmpl-recurrence');
    const recurrenceDaysGroup = document.getElementById('group-recurrence-days');

    if (recurrenceSelect && recurrenceDaysGroup) {
        recurrenceSelect.addEventListener('change', () => {
            recurrenceDaysGroup.style.display = recurrenceSelect.value === 'weekly' ? 'block' : 'none';
        });
    }

    if (openTemplateBtn && modalTemplate && formTemplate) {
        openTemplateBtn.addEventListener('click', () => {
            formTemplate.reset();
            document.getElementById('template-id').value = '';
            document.getElementById('modal-template-heading').textContent = 'Neue Vorlage anlegen';
            if (recurrenceDaysGroup) recurrenceDaysGroup.style.display = 'none';
            modalTemplate.classList.remove('hidden');
        });
    }

    document.addEventListener('click', (e) => {
        const editTmplBtn = e.target.closest('.js-edit-template-btn');
        if (editTmplBtn) {
            const tmplData = JSON.parse(editTmplBtn.getAttribute('data-template'));
            if (formTemplate && modalTemplate) {
                document.getElementById('template-id').value = tmplData.id || '';
                document.getElementById('tmpl-title').value = tmplData.title || '';
                document.getElementById('tmpl-desc').value = tmplData.description || '';
                document.getElementById('tmpl-category').value = tmplData.category || 'haushalt';
                document.getElementById('tmpl-assigned').value = tmplData.assigned_profile_id || '';
                document.getElementById('tmpl-recurrence').value = tmplData.recurrence || 'none';
                document.getElementById('tmpl-duetime').value = tmplData.due_time ? tmplData.due_time.substring(0, 5) : '18:00';
                document.getElementById('tmpl-coins').value = tmplData.base_coins || 20;
                document.getElementById('tmpl-xp').value = tmplData.base_xp || 50;
                document.getElementById('tmpl-cooking').checked = tmplData.is_cooking_day == 1;

                if (recurrenceDaysGroup) {
                    document.getElementById('tmpl-recurrence-days').value = tmplData.recurrence_days || '';
                    recurrenceDaysGroup.style.display = tmplData.recurrence === 'weekly' ? 'block' : 'none';
                }

                document.getElementById('modal-template-heading').textContent = 'Vorlage bearbeiten';
                modalTemplate.classList.remove('hidden');
            }
        }
    });

    if (formTemplate) {
        formTemplate.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(formTemplate);
            const payload = {
                action: 'template_save',
                id: formData.get('id') || null,
                title: formData.get('title'),
                description: formData.get('description'),
                category: formData.get('category'),
                assigned_profile_id: formData.get('assigned_profile_id') || null,
                recurrence: formData.get('recurrence'),
                recurrence_days: formData.get('recurrence_days'),
                due_time: formData.get('due_time'),
                base_coins: parseInt(formData.get('base_coins'), 10),
                base_xp: parseInt(formData.get('base_xp'), 10),
                is_cooking_day: formData.get('is_cooking_day') ? 1 : 0
            };

            const res = await KaiHttp.postJson('api.php', payload);
            if (res.success) {
                alert(res.message || 'Vorlage gespeichert!');
                window.location.reload();
            } else {
                alert(res.message || 'Fehler beim Speichern der Vorlage.');
            }
        });
    }

    // Vorlage löschen
    document.addEventListener('click', async (e) => {
        const delTmplBtn = e.target.closest('.js-delete-template-btn');
        if (delTmplBtn) {
            const tmplId = delTmplBtn.getAttribute('data-template-id');
            if (!confirm('Diese Vorlage wirklich löschen?')) return;

            const res = await KaiHttp.postJson('api.php', {
                action: 'template_delete',
                template_id: parseInt(tmplId, 10)
            });

            if (res.success) {
                window.location.reload();
            } else {
                alert(res.message || 'Fehler beim Löschen.');
            }
        }
    });

    // 9. Prämie anlegen / bearbeiten
    const modalReward = document.getElementById('modal-reward');
    const formReward = document.getElementById('form-reward');
    const openRewardBtn = document.querySelector('.js-open-reward-modal');

    if (openRewardBtn && modalReward && formReward) {
        openRewardBtn.addEventListener('click', () => {
            formReward.reset();
            document.getElementById('reward-id').value = '';
            document.getElementById('modal-reward-heading').textContent = 'Neue Prämie anlegen';
            modalReward.classList.remove('hidden');
        });
    }

    document.addEventListener('click', (e) => {
        const editRewBtn = e.target.closest('.js-edit-reward-btn');
        if (editRewBtn) {
            const rewData = JSON.parse(editRewBtn.getAttribute('data-reward'));
            if (formReward && modalReward) {
                document.getElementById('reward-id').value = rewData.id || '';
                document.getElementById('reward-title').value = rewData.title || '';
                document.getElementById('reward-desc').value = rewData.description || '';
                document.getElementById('reward-cost').value = rewData.coin_cost || 100;
                document.getElementById('reward-icon').value = rewData.icon || '🎁';
                document.getElementById('reward-type').value = rewData.type || 'privilege';
                document.getElementById('reward-cooldown').value = rewData.cooldown_days || 0;
                document.getElementById('modal-reward-heading').textContent = 'Prämie bearbeiten';
                modalReward.classList.remove('hidden');
            }
        }
    });

    if (formReward) {
        formReward.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(formReward);
            const payload = {
                action: 'reward_save',
                id: formData.get('id') || null,
                title: formData.get('title'),
                description: formData.get('description'),
                coin_cost: parseInt(formData.get('coin_cost'), 10),
                icon: formData.get('icon'),
                type: formData.get('type'),
                cooldown_days: parseInt(formData.get('cooldown_days'), 10)
            };

            const res = await KaiHttp.postJson('api.php', payload);
            if (res.success) {
                alert(res.message || 'Prämie gespeichert!');
                window.location.reload();
            } else {
                alert(res.message || 'Fehler beim Speichern der Prämie.');
            }
        });
    }

    // Prämie löschen
    document.addEventListener('click', async (e) => {
        const delRewBtn = e.target.closest('.js-delete-reward-btn');
        if (delRewBtn) {
            const rewId = delRewBtn.getAttribute('data-reward-id');
            if (!confirm('Diese Prämie wirklich löschen?')) return;

            const res = await KaiHttp.postJson('api.php', {
                action: 'reward_delete',
                reward_id: parseInt(rewId, 10)
            });

            if (res.success) {
                window.location.reload();
            } else {
                alert(res.message || 'Fehler beim Löschen.');
            }
        }
    });

    // 10. Punktekonto manuell anpassen
    const modalPoints = document.getElementById('modal-points');
    const formPoints = document.getElementById('form-points');
    const pointsProfileIdInput = document.getElementById('points-profile-id');
    const pointsHeading = document.getElementById('modal-points-heading');

    document.addEventListener('click', (e) => {
        const adjBtn = e.target.closest('.js-adjust-points-btn');
        if (adjBtn) {
            const pid = adjBtn.getAttribute('data-profile-id');
            const name = adjBtn.getAttribute('data-name');
            if (pointsProfileIdInput && modalPoints) {
                pointsProfileIdInput.value = pid;
                if (pointsHeading) pointsHeading.textContent = `Punktekonto von ${name} anpassen`;
                modalPoints.classList.remove('hidden');
            }
        }
    });

    if (formPoints) {
        formPoints.addEventListener('submit', async (e) => {
            e.preventDefault();
            const pid = pointsProfileIdInput.value;
            const coinsDelta = parseInt(document.getElementById('points-coins').value, 10) || 0;
            const xpDelta = parseInt(document.getElementById('points-xp').value, 10) || 0;
            const reason = document.getElementById('points-reason').value.trim();

            const res = await KaiHttp.postJson('api.php', {
                action: 'profile_adjust',
                profile_id: parseInt(pid, 10),
                coins_delta: coinsDelta,
                xp_delta: xpDelta,
                reason: reason
            });

            if (res.success) {
                alert('Buchung erfolgreich durchgeführt!');
                window.location.reload();
            } else {
                alert(res.message || 'Fehler bei der Punkteanpassung.');
            }
        });
    }
});
