/**
 * JavaScript für das Kinder- und Mitmach-Dashboard (Familien-Quests).
 *
 * Verwaltet Tabs, Modals, Quest-Claiming, Einreichungen und Belohnungsanträge
 * vollständig über zentrierte modale Dialoge ohne störende Browser-Alerts/Confirms.
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

    // 3. Spontan-Hilfe Modal ("Ich hab geholfen!")
    const openPitchBtn = document.querySelector('.js-open-pitch-modal');
    const modalPitch = document.getElementById('modal-pitch');
    if (openPitchBtn && modalPitch) {
        openPitchBtn.addEventListener('click', () => {
            openModal(modalPitch);
        });
    }

    const formPitch = document.getElementById('form-pitch');
    if (formPitch) {
        formPitch.addEventListener('submit', async (e) => {
            e.preventDefault();
            const title = document.getElementById('pitch-title').value.trim();
            const category = document.getElementById('pitch-category').value;
            const notes = document.getElementById('pitch-notes').value.trim();

            if (!title) return;

            closeModal(modalPitch);
            const res = await KaiHttp.postJson('api.php', {
                action: 'task_pitch',
                title: title,
                category: category,
                notes: notes
            });

            if (res.success) {
                showFeedback('Klasse Initiative! 🚀', res.message || 'Deine Hilfe wurde eingereicht!', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Einreichen.');
            }
        });
    }

    // 4. Quest vom Schwarzen Brett beanspruchen (Claiming via Modal: modal-claim-task)
    const modalClaim = document.getElementById('modal-claim-task');
    const claimTaskIdInput = document.getElementById('claim-task-id');
    const claimTaskMsg = document.getElementById('claim-task-msg');
    const btnConfirmClaim = document.getElementById('btn-confirm-claim');

    document.addEventListener('click', (e) => {
        const claimBtn = e.target.closest('.js-claim-task-btn');
        if (claimBtn && modalClaim) {
            const taskId = claimBtn.getAttribute('data-task-id');
            const title = claimBtn.getAttribute('data-title') || 'Quest';
            const coins = claimBtn.getAttribute('data-coins') || '';
            const xp = claimBtn.getAttribute('data-xp') || '';

            if (claimTaskIdInput) claimTaskIdInput.value = taskId;
            if (claimTaskMsg) {
                claimTaskMsg.textContent = `Möchtest du dir „${title}“ schnappen? Du hast dafür 12 Stunden Zeit. Belohnung: 🪙 +${coins} Münzen, ⭐ +${xp} XP!`;
            }
            openModal(modalClaim);
        }
    });

    if (btnConfirmClaim) {
        btnConfirmClaim.addEventListener('click', async () => {
            const taskId = parseInt(claimTaskIdInput.value, 10);
            if (!taskId) return;

            btnConfirmClaim.disabled = true;
            closeModal(modalClaim);
            const res = await KaiHttp.postJson('api.php', {
                action: 'task_claim',
                task_id: taskId
            });
            btnConfirmClaim.disabled = false;

            if (res.success) {
                showFeedback('Quest gesichert! ⚡', res.message || 'Du hast die Quest erfolgreich angenommen!', true);
            } else {
                showFeedback('Fehler', res.message || 'Quest konnte nicht beansprucht werden.');
            }
        });
    }

    // 5. Quest-Reservierung wieder freigeben (Modal: modal-release-task)
    const modalRelease = document.getElementById('modal-release-task');
    const releaseTaskIdInput = document.getElementById('release-task-id');
    const releaseTaskMsg = document.getElementById('release-task-msg');
    const btnConfirmRelease = document.getElementById('btn-confirm-release');

    document.addEventListener('click', (e) => {
        const releaseBtn = e.target.closest('.js-release-claim-btn');
        if (releaseBtn && modalRelease) {
            const taskId = releaseBtn.getAttribute('data-task-id');
            const title = releaseBtn.getAttribute('data-title') || 'Quest';

            if (releaseTaskIdInput) releaseTaskIdInput.value = taskId;
            if (releaseTaskMsg) {
                releaseTaskMsg.textContent = `Möchtest du „${title}“ wirklich wieder auf das Schwarze Brett zurücklegen, damit ein anderes Geschwisterkind die Aufgabe übernehmen kann?`;
            }
            openModal(modalRelease);
        }
    });

    if (btnConfirmRelease) {
        btnConfirmRelease.addEventListener('click', async () => {
            const taskId = parseInt(releaseTaskIdInput.value, 10);
            if (!taskId) return;

            btnConfirmRelease.disabled = true;
            closeModal(modalRelease);
            const res = await KaiHttp.postJson('api.php', {
                action: 'task_release',
                task_id: taskId
            });
            btnConfirmRelease.disabled = false;

            if (res.success) {
                showFeedback('Quest freigegeben ↩️', res.message || 'Die Quest liegt wieder auf dem Schwarzen Brett.', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Freigeben.');
            }
        });
    }

    // 6. Aufgabe als erledigt melden (Modal: modal-submit-task)
    const modalSubmit = document.getElementById('modal-submit-task');
    const submitTaskIdInput = document.getElementById('submit-task-id');
    const submitTitleHeading = document.getElementById('modal-submit-title');

    document.addEventListener('click', (e) => {
        const submitBtn = e.target.closest('.js-submit-task-btn');
        if (submitBtn && modalSubmit) {
            const taskId = submitBtn.getAttribute('data-task-id');
            const title = submitBtn.getAttribute('data-title') || 'Aufgabe';
            if (submitTaskIdInput) submitTaskIdInput.value = taskId;
            if (submitTitleHeading) {
                submitTitleHeading.textContent = `„${title}“ abschließen`;
            }
            openModal(modalSubmit);
        }
    });

    const formSubmit = document.getElementById('form-submit-task');
    if (formSubmit) {
        formSubmit.addEventListener('submit', async (e) => {
            e.preventDefault();
            const taskId = submitTaskIdInput.value;
            const notes = document.getElementById('submit-notes').value.trim();

            closeModal(modalSubmit);
            const res = await KaiHttp.postJson('api.php', {
                action: 'task_submit',
                task_id: parseInt(taskId, 10),
                notes: notes
            });

            if (res.success) {
                showFeedback('Super gemacht! ✓', res.message || 'Die Aufgabe wurde zur Bestätigung an deine Eltern übermittelt.', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Einreichen.');
            }
        });
    }

    // 7. Rezept vorschlagen (Kochtag via Modal: modal-recipe)
    const modalRecipe = document.getElementById('modal-recipe');
    const recipeTaskIdInput = document.getElementById('recipe-task-id');

    document.addEventListener('click', (e) => {
        const recipeBtn = e.target.closest('.js-open-recipe-modal');
        if (recipeBtn && modalRecipe) {
            const taskId = recipeBtn.getAttribute('data-task-id');
            if (recipeTaskIdInput) recipeTaskIdInput.value = taskId;
            openModal(modalRecipe);
        }
    });

    const formRecipe = document.getElementById('form-recipe');
    if (formRecipe) {
        formRecipe.addEventListener('submit', async (e) => {
            e.preventDefault();
            const taskId = recipeTaskIdInput.value;
            const title = document.getElementById('recipe-title').value.trim();
            const details = document.getElementById('recipe-details').value.trim();

            closeModal(modalRecipe);
            const res = await KaiHttp.postJson('api.php', {
                action: 'cooking_pitch',
                task_id: parseInt(taskId, 10),
                recipe_title: title,
                recipe_details: details
            });

            if (res.success) {
                showFeedback('Rezept eingereicht! 🍳', res.message || 'Dein Koch-Vorschlag wurde gespeichert!', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Einreichen.');
            }
        });
    }

    // 8. Geschwisterhilfe melden (Co-Op via Modal: modal-coop)
    const modalCoop = document.getElementById('modal-coop');
    const coopTaskIdInput = document.getElementById('coop-task-id');

    document.addEventListener('click', (e) => {
        const coopBtn = e.target.closest('.js-coop-btn');
        if (coopBtn && modalCoop) {
            const taskId = coopBtn.getAttribute('data-task-id');
            if (coopTaskIdInput) coopTaskIdInput.value = taskId;
            openModal(modalCoop);
        }
    });

    const formCoop = document.getElementById('form-coop');
    if (formCoop) {
        formCoop.addEventListener('submit', async (e) => {
            e.preventDefault();
            const taskId = coopTaskIdInput.value;
            const note = document.getElementById('coop-note').value.trim();

            closeModal(modalCoop);
            const res = await KaiHttp.postJson('api.php', {
                action: 'helper_claim',
                task_id: parseInt(taskId, 10),
                note: note
            });

            if (res.success) {
                showFeedback('Mithilfe gemeldet! 🤝', res.message || 'Deine Mithilfe wurde gemeldet!', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Melden der Mithilfe.');
            }
        });
    }

    // 9. Prämie einlösen (Modal: modal-redeem)
    const modalRedeem = document.getElementById('modal-redeem');
    const redeemRewardIdInput = document.getElementById('redeem-reward-id');
    const redeemDescP = document.getElementById('redeem-modal-desc');

    document.addEventListener('click', (e) => {
        const redeemBtn = e.target.closest('.js-redeem-btn');
        if (redeemBtn && modalRedeem) {
            const rewardId = redeemBtn.getAttribute('data-reward-id');
            const title = redeemBtn.getAttribute('data-title');
            const cost = redeemBtn.getAttribute('data-cost');

            if (redeemRewardIdInput) redeemRewardIdInput.value = rewardId;
            if (redeemDescP) {
                redeemDescP.textContent = `Möchtest du „${title}“ für ${cost} Münzen einlösen?`;
            }
            openModal(modalRedeem);
        }
    });

    const formRedeem = document.getElementById('form-redeem');
    if (formRedeem) {
        formRedeem.addEventListener('submit', async (e) => {
            e.preventDefault();
            const rewardId = redeemRewardIdInput.value;
            const note = document.getElementById('redeem-note').value.trim();

            closeModal(modalRedeem);
            const res = await KaiHttp.postJson('api.php', {
                action: 'reward_redeem',
                reward_id: parseInt(rewardId, 10),
                note: note
            });

            if (res.success) {
                showFeedback('Antrag gestellt! 🎁', res.message || 'Dein Einlöse-Wunsch wurde an deine Eltern geschickt!', true);
            } else {
                showFeedback('Fehler', res.message || 'Fehler beim Einlösen.');
            }
        });
    }

    // 10. Spieler-Symbol / Avatar ändern
    const avatarEl = document.querySelector('.js-open-avatar-picker');
    if (avatarEl && window.GamifEmojiPicker) {
        avatarEl.addEventListener('click', () => {
            GamifEmojiPicker.open({
                onSelect: async (emoji) => {
                    avatarEl.textContent = emoji;
                    const res = await KaiHttp.postJson('api.php', {
                        action: 'profile_update',
                        avatar_icon: emoji
                    });
                    if (res.success) {
                        showFeedback('Neues Symbol gewählt! ✨', `Dein Mitspieler-Symbol wurde auf ${emoji} geändert.`);
                    } else {
                        showFeedback('Fehler', res.message || 'Symbol konnte nicht gespeichert werden.');
                    }
                }
            });
        });
    }
});
