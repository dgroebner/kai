/**
 * JavaScript für das Kinder- und Mitmach-Dashboard (Familien-Quests).
 *
 * Verwaltet Tabs, Modals, Quest-Claiming, Einreichungen und Belohnungsanträge.
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

    // 2. Modals schließen (Escape oder Close-Buttons)
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

    // 3. Spontan-Hilfe Modal öffnen
    const openPitchBtn = document.querySelector('.js-open-pitch-modal');
    const modalPitch = document.getElementById('modal-pitch');
    if (openPitchBtn && modalPitch) {
        openPitchBtn.addEventListener('click', () => {
            modalPitch.classList.remove('hidden');
        });
    }

    // Spontan-Hilfe absenden
    const formPitch = document.getElementById('form-pitch');
    if (formPitch) {
        formPitch.addEventListener('submit', async (e) => {
            e.preventDefault();
            const title = document.getElementById('pitch-title').value.trim();
            const category = document.getElementById('pitch-category').value;
            const notes = document.getElementById('pitch-notes').value.trim();

            if (!title) return;

            const res = await KaiHttp.postJson('api.php', {
                action: 'task_pitch',
                title: title,
                category: category,
                notes: notes
            });

            if (res.success) {
                alert(res.message || 'Eingereicht!');
                window.location.reload();
            } else {
                alert(res.message || 'Fehler beim Einreichen');
            }
        });
    }

    // 4. Quest vom Schwarzen Brett beanspruchen (Claiming)
    document.addEventListener('click', async (e) => {
        const claimBtn = e.target.closest('.js-claim-task-btn');
        if (claimBtn) {
            const taskId = claimBtn.getAttribute('data-task-id');
            if (!taskId) return;

            if (!confirm('Möchtest du dir diese Quest schnappen und für 12 Stunden reservieren?')) {
                return;
            }

            claimBtn.disabled = true;
            const res = await KaiHttp.postJson('api.php', {
                action: 'task_claim',
                task_id: parseInt(taskId, 10)
            });

            if (res.success) {
                alert(res.message);
                window.location.reload();
            } else {
                alert(res.message || 'Quest konnte nicht beansprucht werden.');
                claimBtn.disabled = false;
            }
        }
    });

    // 5. Quest-Reservierung wieder freigeben
    document.addEventListener('click', async (e) => {
        const releaseBtn = e.target.closest('.js-release-claim-btn');
        if (releaseBtn) {
            const taskId = releaseBtn.getAttribute('data-task-id');
            if (!taskId) return;

            if (!confirm('Möchtest du diese Quest wirklich wieder auf das Schwarze Brett zurücklegen?')) {
                return;
            }

            releaseBtn.disabled = true;
            const res = await KaiHttp.postJson('api.php', {
                action: 'task_release',
                task_id: parseInt(taskId, 10)
            });

            if (res.success) {
                window.location.reload();
            } else {
                alert(res.message || 'Fehler beim Freigeben.');
                releaseBtn.disabled = false;
            }
        }
    });

    // 6. Aufgabe als erledigt melden (Submit)
    const modalSubmit = document.getElementById('modal-submit-task');
    const submitTaskIdInput = document.getElementById('submit-task-id');
    const submitTitleHeading = document.getElementById('modal-submit-title');

    document.addEventListener('click', (e) => {
        const submitBtn = e.target.closest('.js-submit-task-btn');
        if (submitBtn) {
            const taskId = submitBtn.getAttribute('data-task-id');
            const title = submitBtn.getAttribute('data-title') || 'Aufgabe';
            if (submitTaskIdInput && modalSubmit) {
                submitTaskIdInput.value = taskId;
                if (submitTitleHeading) {
                    submitTitleHeading.textContent = '„' + title + '“ abschließen';
                }
                modalSubmit.classList.remove('hidden');
            }
        }
    });

    const formSubmit = document.getElementById('form-submit-task');
    if (formSubmit) {
        formSubmit.addEventListener('submit', async (e) => {
            e.preventDefault();
            const taskId = submitTaskIdInput.value;
            const notes = document.getElementById('submit-notes').value.trim();

            const res = await KaiHttp.postJson('api.php', {
                action: 'task_submit',
                task_id: parseInt(taskId, 10),
                notes: notes
            });

            if (res.success) {
                alert(res.message || 'Erledigt gemeldet!');
                window.location.reload();
            } else {
                alert(res.message || 'Fehler beim Einreichen.');
            }
        });
    }

    // 7. Rezept vorschlagen (Kochtag)
    const modalRecipe = document.getElementById('modal-recipe');
    const recipeTaskIdInput = document.getElementById('recipe-task-id');

    document.addEventListener('click', (e) => {
        const recipeBtn = e.target.closest('.js-open-recipe-modal');
        if (recipeBtn) {
            const taskId = recipeBtn.getAttribute('data-task-id');
            if (recipeTaskIdInput && modalRecipe) {
                recipeTaskIdInput.value = taskId;
                modalRecipe.classList.remove('hidden');
            }
        }
    });

    const formRecipe = document.getElementById('form-recipe');
    if (formRecipe) {
        formRecipe.addEventListener('submit', async (e) => {
            e.preventDefault();
            const taskId = recipeTaskIdInput.value;
            const title = document.getElementById('recipe-title').value.trim();
            const details = document.getElementById('recipe-details').value.trim();

            const res = await KaiHttp.postJson('api.php', {
                action: 'cooking_pitch',
                task_id: parseInt(taskId, 10),
                recipe_title: title,
                recipe_details: details
            });

            if (res.success) {
                alert(res.message || 'Rezept eingereicht!');
                window.location.reload();
            } else {
                alert(res.message || 'Fehler beim Einreichen.');
            }
        });
    }

    // 8. Geschwisterhilfe melden (Co-Op)
    const modalCoop = document.getElementById('modal-coop');
    const coopTaskIdInput = document.getElementById('coop-task-id');

    document.addEventListener('click', (e) => {
        const coopBtn = e.target.closest('.js-coop-btn');
        if (coopBtn) {
            const taskId = coopBtn.getAttribute('data-task-id');
            if (coopTaskIdInput && modalCoop) {
                coopTaskIdInput.value = taskId;
                modalCoop.classList.remove('hidden');
            }
        }
    });

    const formCoop = document.getElementById('form-coop');
    if (formCoop) {
        formCoop.addEventListener('submit', async (e) => {
            e.preventDefault();
            const taskId = coopTaskIdInput.value;
            const note = document.getElementById('coop-note').value.trim();

            const res = await KaiHttp.postJson('api.php', {
                action: 'helper_claim',
                task_id: parseInt(taskId, 10),
                note: note
            });

            if (res.success) {
                alert(res.message || 'Mithilfe gemeldet!');
                window.location.reload();
            } else {
                alert(res.message || 'Fehler beim Melden der Mithilfe.');
            }
        });
    }

    // 9. Prämie einlösen
    const modalRedeem = document.getElementById('modal-redeem');
    const redeemRewardIdInput = document.getElementById('redeem-reward-id');
    const redeemDescP = document.getElementById('redeem-modal-desc');

    document.addEventListener('click', (e) => {
        const redeemBtn = e.target.closest('.js-redeem-btn');
        if (redeemBtn) {
            const rewardId = redeemBtn.getAttribute('data-reward-id');
            const title = redeemBtn.getAttribute('data-title');
            const cost = redeemBtn.getAttribute('data-cost');

            if (redeemRewardIdInput && modalRedeem) {
                redeemRewardIdInput.value = rewardId;
                if (redeemDescP) {
                    redeemDescP.textContent = `Möchtest du „${title}“ für ${cost} Münzen einlösen?`;
                }
                modalRedeem.classList.remove('hidden');
            }
        }
    });

    const formRedeem = document.getElementById('form-redeem');
    if (formRedeem) {
        formRedeem.addEventListener('submit', async (e) => {
            e.preventDefault();
            const rewardId = redeemRewardIdInput.value;
            const note = document.getElementById('redeem-note').value.trim();

            const res = await KaiHttp.postJson('api.php', {
                action: 'reward_redeem',
                reward_id: parseInt(rewardId, 10),
                note: note
            });

            if (res.success) {
                alert(res.message || 'Antrag eingereicht!');
                window.location.reload();
            } else {
                alert(res.message || 'Fehler beim Einlösen.');
            }
        });
    }
});
