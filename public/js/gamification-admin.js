/**
 * JavaScript für das Eltern-Cockpit (Familien-Quests Admin).
 *
 * Verwaltet Freigaben, Vorlagen, Prämien, Abzeichen und Punktekonten
 * vollständig über modale Dialoge (ohne störende Browser-Alerts/Confirms).
 */
document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    // 1. Tab-Wechsel mit Zustandsspeicherung
    function switchTab(targetTab) {
        if (!targetTab) return;
        const targetBtn = document.querySelector(`.gamif-tab-btn[data-tab="${targetTab}"]`);
        const targetContent = document.getElementById(targetTab);
        if (!targetBtn || !targetContent) return;

        document.querySelectorAll('.gamif-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.gamif-tab-content').forEach(c => c.classList.add('hidden'));

        targetBtn.classList.add('active');
        targetContent.classList.remove('hidden');

        try {
            sessionStorage.setItem('gamif_admin_active_tab', targetTab);
            history.replaceState(null, '', '#' + targetTab);
        } catch (e) {
        }
    }

    document.querySelectorAll('.gamif-tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            switchTab(btn.getAttribute('data-tab'));
        });
    });

    // Gespeicherten Tab beim Laden wiederherstellen
    const savedAdminTab = window.location.hash.replace('#', '') || sessionStorage.getItem('gamif_admin_active_tab');
    if (savedAdminTab && document.getElementById(savedAdminTab)) {
        switchTab(savedAdminTab);
    }

    // 2. Modals Steuerung (Öffnen, Schließen, Feedback)
    let reloadAfterFeedback = false;

    function openModal(modal) {
        if (modal) {
            modal.classList.remove('hidden');
        }
    }

    function closeModal(modal) {
        if (modal) {
            modal.classList.add('hidden');
        }
        if (reloadAfterFeedback) {
            reloadAfterFeedback = false;
            window.location.reload();
        }
    }

    function showFeedback(title, message, reload = false) {
        const modal = document.getElementById('modal-feedback');
        const heading = document.getElementById('feedback-heading');
        const msg = document.getElementById('feedback-msg');
        if (modal && heading && msg) {
            heading.textContent = title;
            msg.textContent = message;
            reloadAfterFeedback = reload;
            openModal(modal);
        } else {
            alert(message);
            if (reload) window.location.reload();
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

    // 3. Fristen sofort prüfen (Modal)
    const modalSyncEscalation = document.getElementById('modal-sync-escalation');
    const syncEscalationBtn = document.querySelector('.js-sync-escalation-btn');
    const btnExecuteSyncEscalation = document.getElementById('btn-execute-sync-escalation');

    if (syncEscalationBtn && modalSyncEscalation) {
        syncEscalationBtn.addEventListener('click', () => {
            openModal(modalSyncEscalation);
        });
    }

    if (btnExecuteSyncEscalation) {
        btnExecuteSyncEscalation.addEventListener('click', async () => {
            btnExecuteSyncEscalation.disabled = true;
            closeModal(modalSyncEscalation);
            const res = await KaiHttp.postJson('api.php', {action: 'run_escalation'});
            btnExecuteSyncEscalation.disabled = false;
            if (res.success) {
                showFeedback('Fristen & Tagesaufgaben', res.message || 'Fristen erfolgreich geprüft und aktualisiert!', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Fristenabgleich.');
            }
        });
    }

    // 4. Aufgabe genehmigen (Modal: modal-approve-task)
    const modalApproveTask = document.getElementById('modal-approve-task');
    const formApproveTask = document.getElementById('form-approve-task');
    const approveTaskIdInput = document.getElementById('approve-task-id');
    const approveCoinsInput = document.getElementById('approve-coins');
    const approveXpInput = document.getElementById('approve-xp');
    const approveDescP = document.getElementById('approve-task-desc');
    const approveFeedbackInput = document.getElementById('approve-feedback');

    document.addEventListener('click', (e) => {
        const approveBtn = e.target.closest('.js-triage-approve-btn');
        if (approveBtn && modalApproveTask) {
            const taskId = approveBtn.getAttribute('data-task-id');
            const title = approveBtn.getAttribute('data-title') || 'Aufgabe';
            const recipient = approveBtn.getAttribute('data-recipient') || 'dem Kind';
            const coins = approveBtn.getAttribute('data-coins') || '20';
            const xp = approveBtn.getAttribute('data-xp') || '50';

            approveTaskIdInput.value = taskId;
            approveCoinsInput.value = coins;
            approveXpInput.value = xp;
            if (approveDescP) {
                approveDescP.textContent = `Aufgabe „${title}“ von ${recipient} bestätigen und Belohnung gutschreiben:`;
            }
            if (approveFeedbackInput) {
                approveFeedbackInput.value = 'Super erledigt! 👍';
            }
            openModal(modalApproveTask);
        }
    });

    if (formApproveTask) {
        formApproveTask.addEventListener('submit', async (e) => {
            e.preventDefault();
            const taskId = approveTaskIdInput.value;
            const coins = parseInt(approveCoinsInput.value, 10) || 0;
            const xp = parseInt(approveXpInput.value, 10) || 0;
            const feedback = approveFeedbackInput.value.trim();

            closeModal(modalApproveTask);
            const res = await KaiHttp.postJson('api.php', {
                action: 'triage_review_task',
                task_id: parseInt(taskId, 10),
                sub_action: 'approve',
                custom_coins: coins,
                custom_xp: xp,
                feedback: feedback
            });

            if (res.success) {
                let msg = res.message || 'Aufgabe genehmigt und Punkte gutgeschrieben!';
                if (res.new_achievements && res.new_achievements.length > 0) {
                    const badgeTitles = res.new_achievements.map(a => a.title).join(', ');
                    msg += ` 🎉 Neues Abzeichen freigeschaltet: ${badgeTitles}`;
                }
                showFeedback('Aufgabe genehmigt ✅', msg, true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler bei der Genehmigung.');
            }
        });
    }

    // 5. Aufgabe zurückweisen / Nachbessern (Modal: modal-review)
    const modalReview = document.getElementById('modal-review');
    const reviewTaskIdInput = document.getElementById('review-task-id');
    const reviewFeedbackInput = document.getElementById('review-feedback');
    const reviewTitleDisplay = document.getElementById('review-task-title-display');

    document.addEventListener('click', (e) => {
        const rejectBtn = e.target.closest('.js-triage-reject-btn');
        if (rejectBtn && modalReview) {
            const taskId = rejectBtn.getAttribute('data-task-id');
            const title = rejectBtn.getAttribute('data-title') || 'Aufgabe';
            if (reviewTaskIdInput) reviewTaskIdInput.value = taskId;
            if (reviewTitleDisplay) {
                reviewTitleDisplay.textContent = `Aufgabe „${title}“ zur Nachbesserung an das Kind zurückweisen.`;
            }
            if (reviewFeedbackInput) reviewFeedbackInput.value = '';
            openModal(modalReview);
        }
    });

    const formReview = document.getElementById('form-review');
    if (formReview) {
        formReview.addEventListener('submit', async (e) => {
            e.preventDefault();
            const taskId = reviewTaskIdInput.value;
            const feedback = reviewFeedbackInput.value.trim();

            closeModal(modalReview);
            const res = await KaiHttp.postJson('api.php', {
                action: 'triage_review_task',
                task_id: parseInt(taskId, 10),
                sub_action: 'reject',
                feedback: feedback
            });

            if (res.success) {
                showFeedback('Rückmeldung gesendet', 'Die Aufgabe wurde mit deiner Rückmeldung an das Kind zurückgewiesen.', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Zurückweisen.');
            }
        });
    }

    // 6. Mithilfe (Co-Op) anerkennen oder ablehnen (Modal: modal-review-helper)
    const modalReviewHelper = document.getElementById('modal-review-helper');
    const formReviewHelper = document.getElementById('form-review-helper');
    const helperIdInput = document.getElementById('helper-id');
    const helperApprovedInput = document.getElementById('helper-approved');
    const helperDescP = document.getElementById('helper-desc');
    const helperHeading = document.getElementById('review-helper-heading');
    const helperCoinsGroup = document.getElementById('group-helper-coins');
    const helperCoinsInput = document.getElementById('helper-coins');
    const helperSubmitBtn = document.getElementById('helper-submit-btn');

    document.addEventListener('click', (e) => {
        const helperBtn = e.target.closest('.js-review-helper-btn');
        if (helperBtn && modalReviewHelper) {
            const helperId = helperBtn.getAttribute('data-helper-id');
            const helperName = helperBtn.getAttribute('data-helper-name') || 'Kind';
            const taskTitle = helperBtn.getAttribute('data-task-title') || 'Aufgabe';
            const coins = helperBtn.getAttribute('data-coins') || '10';
            const action = helperBtn.getAttribute('data-action') || 'approve';
            const isApproved = action === 'approve';

            helperIdInput.value = helperId;
            helperApprovedInput.value = isApproved ? '1' : '0';
            helperCoinsInput.value = coins;

            if (isApproved) {
                if (helperHeading) helperHeading.textContent = 'Mithilfe anerkennen 🤝';
                if (helperDescP) {
                    helperDescP.textContent = `${helperName} hat freiwillig bei „${taskTitle}“ mitgeholfen. Bestätige den Helfer-Bonus:`;
                }
                if (helperCoinsGroup) helperCoinsGroup.style.display = 'block';
                if (helperSubmitBtn) {
                    helperSubmitBtn.textContent = `✅ Mithilfe bestätigen (+${coins} Münzen)`;
                    helperSubmitBtn.className = 'btn btn-primary';
                }
            } else {
                if (helperHeading) helperHeading.textContent = 'Mithilfe ablehnen ❌';
                if (helperDescP) {
                    helperDescP.textContent = `Möchtest du die gemeldete Mithilfe von ${helperName} bei „${taskTitle}“ ablehnen?`;
                }
                if (helperCoinsGroup) helperCoinsGroup.style.display = 'none';
                if (helperSubmitBtn) {
                    helperSubmitBtn.textContent = '❌ Mithilfe ablehnen';
                    helperSubmitBtn.className = 'btn btn-danger';
                }
            }

            openModal(modalReviewHelper);
        }
    });

    if (formReviewHelper) {
        formReviewHelper.addEventListener('submit', async (e) => {
            e.preventDefault();
            const helperId = helperIdInput.value;
            const approved = helperApprovedInput.value === '1';
            const customCoins = parseInt(helperCoinsInput.value, 10) || 0;

            closeModal(modalReviewHelper);
            const res = await KaiHttp.postJson('api.php', {
                action: 'triage_review_helper',
                helper_id: parseInt(helperId, 10),
                approved: approved,
                custom_coins: approved ? customCoins : 0
            });

            if (res.success) {
                showFeedback('Mithilfe bewertet', res.message || 'Status erfolgreich aktualisiert!', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler bei der Bewertung.');
            }
        });
    }

    // 7. Prämie genehmigen oder ablehnen (Modal: modal-review-redemption)
    const modalReviewRedemption = document.getElementById('modal-review-redemption');
    const formReviewRedemption = document.getElementById('form-review-redemption');
    const redemptionIdInput = document.getElementById('redemption-id');
    const redemptionActionInput = document.getElementById('redemption-action');
    const redemptionHeading = document.getElementById('redemption-heading');
    const redemptionDescP = document.getElementById('redemption-desc');
    const redemptionParentNote = document.getElementById('redemption-parent-note');
    const redemptionSubmitBtn = document.getElementById('redemption-submit-btn');

    document.addEventListener('click', (e) => {
        const redBtn = e.target.closest('.js-review-redemption-btn');
        if (redBtn && modalReviewRedemption) {
            const redemptionId = redBtn.getAttribute('data-redemption-id');
            const profileName = redBtn.getAttribute('data-profile-name') || 'Kind';
            const rewardTitle = redBtn.getAttribute('data-reward-title') || 'Prämie';
            const rewardIcon = redBtn.getAttribute('data-reward-icon') || '🎁';
            const cost = redBtn.getAttribute('data-cost') || '0';
            const note = redBtn.getAttribute('data-note') || '';
            const action = redBtn.getAttribute('data-action') || 'approve';
            const isApproved = action === 'approve';

            redemptionIdInput.value = redemptionId;
            redemptionActionInput.value = action;
            if (redemptionParentNote) redemptionParentNote.value = '';

            if (isApproved) {
                if (redemptionHeading) redemptionHeading.textContent = 'Prämien-Wunsch genehmigen 🎁';
                if (redemptionDescP) {
                    redemptionDescP.textContent = `${profileName} möchte ${rewardIcon} „${rewardTitle}“ für ${cost} Münzen einlösen.${note ? ' Wunsch-Notiz: ' + note : ''}`;
                }
                if (redemptionSubmitBtn) {
                    redemptionSubmitBtn.textContent = '✅ Genehmigen & Belohnung gewähren';
                    redemptionSubmitBtn.className = 'btn btn-primary';
                }
            } else {
                if (redemptionHeading) redemptionHeading.textContent = 'Prämien-Antrag ablehnen ❌';
                if (redemptionDescP) {
                    redemptionDescP.textContent = `Antrag von ${profileName} auf ${rewardIcon} „${rewardTitle}“ ablehnen? Die ${cost} Münzen werden dem Kind wieder gutgeschrieben.`;
                }
                if (redemptionSubmitBtn) {
                    redemptionSubmitBtn.textContent = '❌ Ablehnen & Münzen erstatten';
                    redemptionSubmitBtn.className = 'btn btn-danger';
                }
            }

            openModal(modalReviewRedemption);
        }
    });

    if (formReviewRedemption) {
        formReviewRedemption.addEventListener('submit', async (e) => {
            e.preventDefault();
            const redemptionId = redemptionIdInput.value;
            const subAction = redemptionActionInput.value;
            const parentNote = redemptionParentNote ? redemptionParentNote.value.trim() : '';

            closeModal(modalReviewRedemption);
            const res = await KaiHttp.postJson('api.php', {
                action: 'triage_review_redemption',
                redemption_id: parseInt(redemptionId, 10),
                sub_action: subAction,
                parent_note: parentNote
            });

            if (res.success) {
                showFeedback('Prämien-Antrag bearbeitet', res.message || 'Status erfolgreich aktualisiert!', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Bearbeiten des Antrags.');
            }
        });
    }

    // 8. Universeller Lösch-Dialog (Modal: modal-confirm-delete)
    const modalConfirmDelete = document.getElementById('modal-confirm-delete');
    const deleteTypeInput = document.getElementById('delete-type');
    const deleteIdInput = document.getElementById('delete-id');
    const confirmDeleteMsg = document.getElementById('confirm-delete-msg');
    const confirmDeleteHeading = document.getElementById('confirm-delete-heading');
    const btnConfirmDeleteExecute = document.getElementById('btn-confirm-delete-execute');

    document.addEventListener('click', (e) => {
        const delTmplBtn = e.target.closest('.js-delete-template-btn');
        const delRewBtn = e.target.closest('.js-delete-reward-btn');
        const delBadgeBtn = e.target.closest('.js-delete-badge-btn');

        if (delTmplBtn && modalConfirmDelete) {
            const id = delTmplBtn.getAttribute('data-template-id');
            const title = delTmplBtn.getAttribute('data-title') || 'Aufgaben-Vorlage';
            deleteTypeInput.value = 'template';
            deleteIdInput.value = id;
            if (confirmDeleteHeading) confirmDeleteHeading.textContent = 'Vorlage löschen 🗑️';
            if (confirmDeleteMsg) confirmDeleteMsg.textContent = `Möchtest du die Aufgaben-Vorlage „${title}“ wirklich löschen?`;
            openModal(modalConfirmDelete);
        } else if (delRewBtn && modalConfirmDelete) {
            const id = delRewBtn.getAttribute('data-reward-id');
            const title = delRewBtn.getAttribute('data-title') || 'Prämie';
            deleteTypeInput.value = 'reward';
            deleteIdInput.value = id;
            if (confirmDeleteHeading) confirmDeleteHeading.textContent = 'Prämie löschen 🗑️';
            if (confirmDeleteMsg) confirmDeleteMsg.textContent = `Möchtest du die Prämie „${title}“ wirklich löschen?`;
            openModal(modalConfirmDelete);
        } else if (delBadgeBtn && modalConfirmDelete) {
            const id = delBadgeBtn.getAttribute('data-badge-id');
            const title = delBadgeBtn.getAttribute('data-title') || 'Abzeichen';
            deleteTypeInput.value = 'badge';
            deleteIdInput.value = id;
            if (confirmDeleteHeading) confirmDeleteHeading.textContent = 'Abzeichen löschen 🗑️';
            if (confirmDeleteMsg) confirmDeleteMsg.textContent = `Möchtest du das Abzeichen „${title}“ wirklich löschen?`;
            openModal(modalConfirmDelete);
        }
    });

    if (btnConfirmDeleteExecute) {
        btnConfirmDeleteExecute.addEventListener('click', async () => {
            const type = deleteTypeInput.value;
            const id = parseInt(deleteIdInput.value, 10);
            if (!id) return;

            btnConfirmDeleteExecute.disabled = true;
            closeModal(modalConfirmDelete);

            let res;
            if (type === 'template') {
                res = await KaiHttp.postJson('api.php', {action: 'template_delete', template_id: id});
            } else if (type === 'reward') {
                res = await KaiHttp.postJson('api.php', {action: 'reward_delete', reward_id: id});
            } else if (type === 'badge') {
                res = await KaiHttp.postJson('api.php', {action: 'achievement_delete', achievement_id: id});
            }

            btnConfirmDeleteExecute.disabled = false;
            if (res && res.success) {
                showFeedback('Gelöscht', res.message || 'Eintrag wurde erfolgreich gelöscht.', true);
            } else {
                showFeedback('Fehler', (res && res.message) || 'Fehler beim Löschen.');
            }
        });
    }

    // 9. Aufgaben-Vorlage anlegen / bearbeiten
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
            document.querySelectorAll('input[name="recurrence_day_check"]').forEach(cb => {
                cb.checked = false;
            });
            if (recurrenceDaysGroup) recurrenceDaysGroup.style.display = 'none';
            const escCb = document.getElementById('tmpl-escalate');
            if (escCb) escCb.checked = true;
            openModal(modalTemplate);
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
                document.getElementById('tmpl-cooking').checked = tmplData.is_cooking_day === 1;
                const escCb = document.getElementById('tmpl-escalate');
                if (escCb) escCb.checked = (tmplData.can_escalate === undefined || tmplData.can_escalate === 1);

                if (recurrenceDaysGroup) {
                    const selectedDays = (tmplData.recurrence_days || '').split(',').map(s => s.trim());
                    document.querySelectorAll('input[name="recurrence_day_check"]').forEach(cb => {
                        cb.checked = selectedDays.includes(cb.value);
                    });
                    recurrenceDaysGroup.style.display = tmplData.recurrence === 'weekly' ? 'block' : 'none';
                }

                document.getElementById('modal-template-heading').textContent = 'Vorlage bearbeiten';
                openModal(modalTemplate);
            }
        }
    });

    if (formTemplate) {
        formTemplate.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(formTemplate);
            const recurrence = formData.get('recurrence');
            let recurrenceDays = null;
            if (recurrence === 'weekly') {
                const checkedBoxes = Array.from(document.querySelectorAll('input[name="recurrence_day_check"]:checked'));
                recurrenceDays = checkedBoxes
                    .map(cb => cb.value)
                    .sort((a, b) => parseInt(a, 10) - parseInt(b, 10))
                    .join(',');
            }

            const payload = {
                action: 'template_save',
                id: formData.get('id') || null,
                title: formData.get('title'),
                description: formData.get('description'),
                category: formData.get('category'),
                assigned_profile_id: formData.get('assigned_profile_id') || null,
                recurrence: recurrence,
                recurrence_days: recurrenceDays,
                due_time: formData.get('due_time'),
                base_coins: parseInt(formData.get('base_coins'), 10),
                base_xp: parseInt(formData.get('base_xp'), 10),
                is_cooking_day: formData.get('is_cooking_day') ? 1 : 0,
                can_escalate: formData.get('can_escalate') ? 1 : 0,
                spawn_immediately: formData.get('spawn_immediately') ? 1 : 0
            };

            closeModal(modalTemplate);
            const res = await KaiHttp.postJson('api.php', payload);
            if (res.success) {
                showFeedback('Vorlage gespeichert', res.message || 'Die Vorlage wurde erfolgreich gespeichert!', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Speichern der Vorlage.');
            }
        });
    }

    // 10. Prämie anlegen / bearbeiten
    const modalReward = document.getElementById('modal-reward');
    const formReward = document.getElementById('form-reward');
    const openRewardBtn = document.querySelector('.js-open-reward-modal');

    if (openRewardBtn && modalReward && formReward) {
        openRewardBtn.addEventListener('click', () => {
            formReward.reset();
            document.getElementById('reward-id').value = '';
            document.getElementById('modal-reward-heading').textContent = 'Neue Prämie anlegen';
            const rewPreview = document.getElementById('reward-icon-preview');
            if (rewPreview) rewPreview.textContent = '🎁';
            openModal(modalReward);
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
                const rewPreview = document.getElementById('reward-icon-preview');
                if (rewPreview) rewPreview.textContent = rewData.icon || '🎁';
                document.getElementById('reward-type').value = rewData.type || 'privilege';
                document.getElementById('reward-cooldown').value = rewData.cooldown_days || 0;
                document.getElementById('modal-reward-heading').textContent = 'Prämie bearbeiten';
                openModal(modalReward);
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

            closeModal(modalReward);
            const res = await KaiHttp.postJson('api.php', payload);
            if (res.success) {
                showFeedback('Prämie gespeichert', res.message || 'Prämie erfolgreich gespeichert!', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Speichern der Prämie.');
            }
        });
    }

    // 11. Punktekonto manuell anpassen
    const modalPoints = document.getElementById('modal-points');
    const formPoints = document.getElementById('form-points');
    const pointsProfileIdInput = document.getElementById('points-profile-id');
    const pointsHeading = document.getElementById('modal-points-heading');

    document.addEventListener('click', (e) => {
        const adjBtn = e.target.closest('.js-adjust-points-btn');
        if (adjBtn && pointsProfileIdInput && modalPoints) {
            const pid = adjBtn.getAttribute('data-profile-id');
            const name = adjBtn.getAttribute('data-name');
            pointsProfileIdInput.value = pid;
            if (pointsHeading) pointsHeading.textContent = `Punktekonto von ${name} anpassen`;
            openModal(modalPoints);
        }
    });

    if (formPoints) {
        formPoints.addEventListener('submit', async (e) => {
            e.preventDefault();
            const pid = pointsProfileIdInput.value;
            const coinsDelta = parseInt(document.getElementById('points-coins').value, 10) || 0;
            const xpDelta = parseInt(document.getElementById('points-xp').value, 10) || 0;
            const reason = document.getElementById('points-reason').value.trim();

            closeModal(modalPoints);
            const res = await KaiHttp.postJson('api.php', {
                action: 'profile_adjust',
                profile_id: parseInt(pid, 10),
                coins_delta: coinsDelta,
                xp_delta: xpDelta,
                reason: reason
            });

            if (res.success) {
                showFeedback('Buchung erfolgreich', 'Die Punkte und Münzen wurden erfolgreich gutgeschrieben/angepasst.', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler bei der Punkteanpassung.');
            }
        });
    }

    // 12. Abzeichen & Meilensteine anlegen / bearbeiten
    const modalBadge = document.getElementById('modal-badge');
    const formBadge = document.getElementById('form-badge');
    const openBadgeBtn = document.querySelector('.js-open-badge-modal');
    const badgeMetricSelect = document.getElementById('badge-metric-type');
    const badgeParamGroup = document.getElementById('group-badge-param');

    if (badgeMetricSelect && badgeParamGroup) {
        badgeMetricSelect.addEventListener('change', () => {
            badgeParamGroup.style.display = badgeMetricSelect.value === 'category_count' ? 'block' : 'none';
        });
    }

    if (openBadgeBtn && modalBadge && formBadge) {
        openBadgeBtn.addEventListener('click', () => {
            formBadge.reset();
            document.getElementById('badge-id').value = '';
            document.getElementById('modal-badge-heading').textContent = 'Neues Abzeichen anlegen';
            const badgePreview = document.getElementById('badge-icon-preview');
            if (badgePreview) badgePreview.textContent = '🏆';
            if (badgeParamGroup) badgeParamGroup.style.display = 'none';
            openModal(modalBadge);
        });
    }

    document.addEventListener('click', (e) => {
        const editBadgeBtn = e.target.closest('.js-edit-badge-btn');
        if (editBadgeBtn) {
            const badgeData = JSON.parse(editBadgeBtn.getAttribute('data-badge'));
            if (formBadge && modalBadge) {
                document.getElementById('badge-id').value = badgeData.id || '';
                document.getElementById('badge-title').value = badgeData.title || '';
                document.getElementById('badge-desc').value = badgeData.description || '';
                document.getElementById('badge-icon').value = badgeData.icon || '🏆';
                const badgePreview = document.getElementById('badge-icon-preview');
                if (badgePreview) badgePreview.textContent = badgeData.icon || '🏆';
                document.getElementById('badge-metric-type').value = badgeData.metric_type || 'task_count';
                document.getElementById('badge-metric-target').value = badgeData.metric_target || 1;
                document.getElementById('badge-coins').value = badgeData.reward_coins || 50;
                document.getElementById('badge-xp').value = badgeData.reward_xp || 100;

                if (badgeParamGroup) {
                    document.getElementById('badge-param').value = badgeData.metric_parameter || '';
                    badgeParamGroup.style.display = badgeData.metric_type === 'category_count' ? 'block' : 'none';
                }

                document.getElementById('modal-badge-heading').textContent = 'Abzeichen bearbeiten';
                openModal(modalBadge);
            }
        }
    });

    if (formBadge) {
        formBadge.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(formBadge);
            const payload = {
                action: 'achievement_save',
                id: formData.get('id') || null,
                title: formData.get('title'),
                description: formData.get('description'),
                icon: formData.get('icon'),
                metric_type: formData.get('metric_type'),
                metric_target: parseInt(formData.get('metric_target'), 10),
                metric_parameter: formData.get('metric_parameter') || null,
                reward_coins: parseInt(formData.get('reward_coins'), 10),
                reward_xp: parseInt(formData.get('reward_xp'), 10)
            };

            closeModal(modalBadge);
            const res = await KaiHttp.postJson('api.php', payload);
            if (res.success) {
                showFeedback('Abzeichen gespeichert', res.message || 'Das Abzeichen wurde erfolgreich gespeichert!', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Speichern des Abzeichens.');
            }
        });
    }

    // 13. Neuen Mitspieler / Profil manuell anlegen
    const modalCreateProfile = document.getElementById('modal-create-profile');
    const formCreateProfile = document.getElementById('form-create-profile');
    const openCreateProfileBtn = document.querySelector('.js-open-create-profile-modal');

    if (openCreateProfileBtn && modalCreateProfile && formCreateProfile) {
        openCreateProfileBtn.addEventListener('click', () => {
            formCreateProfile.reset();
            const avatarIn = document.getElementById('new-profile-avatar');
            if (avatarIn) avatarIn.value = '⭐';
            const profPreview = document.getElementById('new-profile-avatar-preview');
            if (profPreview) profPreview.textContent = '⭐';
            openModal(modalCreateProfile);
        });
    }

    // Live preview sync for manual typing in emoji inputs
    [
        {input: '#new-profile-avatar', preview: '#new-profile-avatar-preview'},
        {input: '#reward-icon', preview: '#reward-icon-preview'},
        {input: '#badge-icon', preview: '#badge-icon-preview'}
    ].forEach(({input, preview}) => {
        const inEl = document.querySelector(input);
        const prevEl = document.querySelector(preview);
        if (inEl && prevEl) {
            inEl.addEventListener('input', () => {
                prevEl.textContent = inEl.value.trim() || '❓';
            });
        }
    });

    if (formCreateProfile) {
        formCreateProfile.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(formCreateProfile);
            const payload = {
                action: 'profile_create',
                display_name: formData.get('display_name'),
                user_email: formData.get('user_email'),
                role: formData.get('role'),
                avatar_icon: formData.get('avatar_icon'),
                initial_coins: parseInt(formData.get('initial_coins'), 10) || 0,
                initial_xp: parseInt(formData.get('initial_xp'), 10) || 0
            };

            closeModal(modalCreateProfile);
            const res = await KaiHttp.postJson('api.php', payload);
            if (res.success) {
                showFeedback('Mitspieler angelegt 👤', res.message || 'Neues Profil erfolgreich angelegt!', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Anlegen des Profils.');
            }
        });
    }

    // 14. Bestehendes Mitspieler-Symbol nachträglich ändern
    document.addEventListener('click', (e) => {
        const avatarSpan = e.target.closest('.js-change-profile-avatar');
        if (avatarSpan && window.GamifEmojiPicker) {
            const profileId = avatarSpan.getAttribute('data-profile-id');
            const name = avatarSpan.getAttribute('data-name');
            GamifEmojiPicker.open({
                onSelect: async (emoji) => {
                    avatarSpan.textContent = emoji;
                    const res = await KaiHttp.postJson('api.php', {
                        action: 'profile_update',
                        profile_id: parseInt(profileId, 10),
                        avatar_icon: emoji
                    });
                    if (res.success) {
                        showFeedback('Symbol geändert ✨', `Das Symbol von ${name} wurde auf ${emoji} geändert.`);
                    } else {
                        showFeedback('Fehler', res.message || 'Symbol konnte nicht gespeichert werden.');
                    }
                }
            });
        }
    });

    // 15. Mitspieler-Profil bearbeiten (Name, Rolle, Symbol)
    const modalEditProfile = document.getElementById('modal-edit-profile');
    const formEditProfile = document.getElementById('form-edit-profile');

    document.addEventListener('click', (e) => {
        const editBtn = e.target.closest('.js-edit-profile-btn');
        if (editBtn && modalEditProfile && formEditProfile) {
            const p = JSON.parse(editBtn.getAttribute('data-profile'));
            document.getElementById('edit-profile-id').value = p.id || '';
            document.getElementById('edit-profile-name').value = p.display_name || '';
            document.getElementById('edit-profile-email').value = p.user_email || '';
            document.getElementById('edit-profile-role').value = p.role || 'child';
            document.getElementById('edit-profile-avatar').value = p.avatar_icon || '⭐';
            const prev = document.getElementById('edit-profile-avatar-preview');
            if (prev) prev.textContent = p.avatar_icon || '⭐';
            const shieldsInput = document.getElementById('edit-profile-shields');
            if (shieldsInput) shieldsInput.value = p.streak_shields ?? 0;
            const freezeUntilInput = document.getElementById('edit-profile-freeze-until');
            if (freezeUntilInput) freezeUntilInput.value = p.streak_freeze_until || '';
            const freezeReasonInput = document.getElementById('edit-profile-freeze-reason');
            if (freezeReasonInput) freezeReasonInput.value = p.streak_freeze_reason || '';

            openModal(modalEditProfile);
        }
    });

    if (formEditProfile) {
        formEditProfile.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(formEditProfile);
            const payload = {
                action: 'profile_update',
                profile_id: parseInt(formData.get('profile_id'), 10),
                display_name: formData.get('display_name'),
                role: formData.get('role'),
                avatar_icon: formData.get('avatar_icon'),
                streak_shields: parseInt(formData.get('streak_shields') || '0', 10),
                streak_freeze_until: formData.get('streak_freeze_until') || '',
                streak_freeze_reason: formData.get('streak_freeze_reason') || ''
            };

            closeModal(modalEditProfile);
            const res = await KaiHttp.postJson('api.php', payload);
            if (res.success) {
                showFeedback('Profil aktualisiert 👤', res.message || 'Die Änderungen wurden erfolgreich gespeichert!', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Speichern des Profils.');
            }
        });
    }

    // 16. Vorlage als Aufgabe aktivieren
    const modalSpawn = document.getElementById('modal-spawn-template');
    const formSpawn = document.getElementById('form-spawn-template');
    const spawnTemplSel = document.getElementById('spawn-template-select');
    const spawnTemplSelGrp = document.getElementById('group-spawn-template-select');

    /** Öffnet das Spawn-Modal für eine bekannte Vorlage (Zeilen-Button) */
    function openSpawnModal(templateId, title, assignedId) {
        const idInput = document.getElementById('spawn-template-id');
        const descEl = document.getElementById('spawn-template-desc');
        const assignSel = document.getElementById('spawn-assigned');
        const dateInput = document.getElementById('spawn-date');

        if (idInput) idInput.value = templateId || '';
        if (descEl) descEl.textContent = title ? `Vorlage „${title}" einmalig als aktive Aufgabe anlegen.` : '';
        if (assignSel) assignSel.value = (assignedId && assignedId !== '0') ? assignedId : '';
        if (dateInput) dateInput.value = new Date().toISOString().substring(0, 10);

        // Vorlagen-Auswahl nur im Quick-Modus zeigen
        if (spawnTemplSelGrp) spawnTemplSelGrp.style.display = templateId ? 'none' : 'block';
        if (spawnTemplSel) spawnTemplSel.value = '';

        if (modalSpawn) openModal(modalSpawn);
    }

    // Zeilen-Button ▶️ in der Vorlagen-Tabelle
    document.addEventListener('click', (e) => {
        const spawnBtn = e.target.closest('.js-spawn-template-btn');
        if (spawnBtn) {
            openSpawnModal(
                spawnBtn.getAttribute('data-template-id'),
                spawnBtn.getAttribute('data-title'),
                spawnBtn.getAttribute('data-assigned-id')
            );
        }

        // Schnell-Spawn-Button (ohne vorausgewählte Vorlage)
        if (e.target.closest('.js-open-spawn-quick-btn')) {
            openSpawnModal('', '', '');
        }

        // Aufgabe für ein bestimmtes Kind anlegen (aus Übersicht laufender Aufgaben)
        const spawnForChild = e.target.closest('.js-spawn-for-child-btn');
        if (spawnForChild) {
            const profileId = spawnForChild.getAttribute('data-profile-id');
            const profileName = spawnForChild.getAttribute('data-profile-name') || '';
            openSpawnModal('', profileName ? `Aufgabe für ${profileName} anlegen` : '', '');
            // Zuweisung direkt vorbelegen
            const assignSel = document.getElementById('spawn-assigned');
            if (assignSel && profileId) assignSel.value = profileId;
        }
    });

    // Wenn Vorlage im Dropdown gewählt → Zuweisung vorbelegen
    if (spawnTemplSel) {
        spawnTemplSel.addEventListener('change', () => {
            const opt = spawnTemplSel.options[spawnTemplSel.selectedIndex];
            const assignId = opt ? opt.getAttribute('data-assigned') : '';
            const assignSel = document.getElementById('spawn-assigned');
            if (assignSel) assignSel.value = (assignId && assignId !== '0') ? assignId : '';
        });
    }

    if (formSpawn) {
        formSpawn.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(formSpawn);

            // Im Quick-Modus kommt die template_id aus dem Dropdown
            let templateId = parseInt(formData.get('template_id'), 10) || 0;
            if (!templateId && spawnTemplSel) {
                templateId = parseInt(spawnTemplSel.value, 10) || 0;
            }
            if (!templateId) {
                showFeedback('Hinweis', 'Bitte wähle zuerst eine Vorlage aus.');
                return;
            }

            const payload = {
                action: 'template_spawn_task',
                template_id: templateId,
                assigned_profile_id: formData.get('assigned_profile_id') || null,
                due_date: formData.get('due_date') || null
            };

            closeModal(modalSpawn);
            const res = await KaiHttp.postJson('api.php', payload);
            if (res.success) {
                showFeedback('Aufgabe angelegt ▶️', res.message || 'Die Aufgabe wurde erfolgreich angelegt und ist jetzt aktiv.', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Anlegen der Aufgabe.');
            }
        });
    }

    // 17. Schnellfilter für die Vorlagen-Tabelle
    const filterCat = document.getElementById('filter-tmpl-category');
    const filterAsgn = document.getElementById('filter-tmpl-assigned');
    const filterRec = document.getElementById('filter-tmpl-recurrence');
    const filterReset = document.getElementById('btn-filter-tmpl-reset');
    const tmplEmpty = document.getElementById('filter-tmpl-empty');

    function applyTemplateFilter() {
        const cat = filterCat ? filterCat.value : '';
        const asgn = filterAsgn ? filterAsgn.value : '';
        const rec = filterRec ? filterRec.value : '';
        const rows = document.querySelectorAll('#tbl-templates tbody tr[data-tmpl-category]');
        let visibleCount = 0;

        rows.forEach(row => {
            const matchCat = !cat || row.getAttribute('data-tmpl-category') === cat;
            const matchAsgn = !asgn || row.getAttribute('data-tmpl-assigned') === asgn;
            const matchRec = !rec || row.getAttribute('data-tmpl-recurrence') === rec;
            const visible = matchCat && matchAsgn && matchRec;
            row.style.display = visible ? '' : 'none';
            if (visible) visibleCount++;
        });

        if (tmplEmpty) tmplEmpty.style.display = (rows.length > 0 && visibleCount === 0) ? 'block' : 'none';
    }

    if (filterCat) filterCat.addEventListener('change', applyTemplateFilter);
    if (filterAsgn) filterAsgn.addEventListener('change', applyTemplateFilter);
    if (filterRec) filterRec.addEventListener('change', applyTemplateFilter);

    if (filterReset) {
        filterReset.addEventListener('click', () => {
            if (filterCat) filterCat.value = '';
            if (filterAsgn) filterAsgn.value = '';
            if (filterRec) filterRec.value = '';
            applyTemplateFilter();
        });
    }

    // 18. Aufgabe löschen (Test-Modus, nur Admin)
    const modalDeleteTask     = document.getElementById('modal-delete-task');
    const deleteTitleEl       = document.getElementById('delete-task-title');
    const deleteTaskIdInput   = document.getElementById('delete-task-id');
    const btnConfirmDeleteTask = document.getElementById('btn-confirm-delete-task');

    document.addEventListener('click', (e) => {
        const delBtn = e.target.closest('.js-delete-task-btn');
        if (delBtn && modalDeleteTask) {
            const taskId = delBtn.getAttribute('data-task-id');
            const title  = delBtn.getAttribute('data-title');
            if (deleteTitleEl)     deleteTitleEl.textContent = `„${title}"`;
            if (deleteTaskIdInput) deleteTaskIdInput.value   = taskId;
            openModal(modalDeleteTask);
        }
    });

    if (btnConfirmDeleteTask) {
        btnConfirmDeleteTask.addEventListener('click', async () => {
            const taskId = deleteTaskIdInput ? deleteTaskIdInput.value : '';
            if (!taskId) return;
            closeModal(modalDeleteTask);
            const res = await KaiHttp.postJson('api.php', {
                action:  'task_delete',
                task_id: parseInt(taskId, 10)
            });
            if (res.success) {
                showFeedback('Aufgabe gelöscht 🗑️', res.message || 'Die Aufgabe wurde entfernt.', true);
            } else {
                showFeedback('Fehler', res.message || 'Aufgabe konnte nicht gelöscht werden.');
            }
        });
    }
});
