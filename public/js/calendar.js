/**
 * JavaScript Logik für den Geburtstags- und Jahrestagskalender.
 * Verwendet KaiHttp für CSRF-gesicherte AJAX-Calls und Event-Delegation.
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    // Modal: Ereignis anlegen / bearbeiten
    const modal = document.getElementById('modal-event');
    const form = document.getElementById('form-event');
    const modalTitle = document.getElementById('modal-event-title');
    const eventIdInput = document.getElementById('event-id');
    const titleInput = document.getElementById('event-title');
    const typeSelect = document.getElementById('event-type');
    const categoryInput = document.getElementById('event-category');
    const daySelect = document.getElementById('event-day');
    const monthSelect = document.getElementById('event-month');
    const yearInput = document.getElementById('event-year');
    const notesInput = document.getElementById('event-notes');

    // Modal: Horoskop & Ereignis-Details
    const modalDetails = document.getElementById('modal-event-details');
    const modalDetailsTitle = document.getElementById('modal-details-title');
    const modalDetailsLoading = document.getElementById('modal-details-loading');
    const modalDetailsBody = document.getElementById('modal-details-body');
    let currentDetailEventId = null;

    const typeLabels = {
        birthday: 'Geburtstag',
        anniversary: 'Jahrestag',
        memorial: 'Gedenktag',
        other: 'Ereignis'
    };

    const advanceLabels = {
        0: 'Am Tag selbst',
        1: '1 Tag vorher',
        3: '3 Tage vorher',
        7: '1 Woche vorher',
        14: '2 Wochen vorher'
    };

    function openModal(isEdit = false) {
        if (!modal) return;
        modalTitle.textContent = isEdit ? '✏️ Ereignis bearbeiten' : '🎂 Neues Ereignis anlegen';
        modal.classList.remove('hidden');
        if (titleInput) {
            titleInput.focus();
        }
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.add('hidden');
        if (form) {
            form.reset();
        }
        if (eventIdInput) {
            eventIdInput.value = '';
        }
    }

    function closeDetailsModal() {
        if (!modalDetails) return;
        modalDetails.classList.add('hidden');
        currentDetailEventId = null;
    }

    function resetFormToDefaults() {
        if (!form) return;
        form.reset();
        if (eventIdInput) eventIdInput.value = '';
        if (typeSelect) typeSelect.value = 'birthday';
        if (categoryInput) categoryInput.value = 'Familie';

        const today = new Date();
        if (daySelect) daySelect.value = String(today.getDate());
        if (monthSelect) monthSelect.value = String(today.getMonth() + 1);
        if (yearInput) yearInput.value = '';
        if (notesInput) notesInput.value = '';

        // Standard Vorlauftage: 0, 1, 3 Tage
        document.querySelectorAll('.js-advance-cb').forEach(cb => {
            const val = parseInt(cb.value, 10);
            cb.checked = (val === 0 || val === 1 || val === 3);
        });

        // Alle Empfänger standardmäßig anhaken
        document.querySelectorAll('.js-recipient-cb').forEach(cb => {
            cb.checked = true;
        });
    }

    /**
     * Lädt und öffnet das Detail- & Horoskop-Modal für ein Ereignis.
     */
    async function openDetailsModal(eventId) {
        if (!modalDetails) return;
        currentDetailEventId = eventId;
        modalDetails.classList.remove('hidden');
        if (modalDetailsTitle) modalDetailsTitle.textContent = 'Ereignis-Details';

        const zodiacSectionInit = document.getElementById('det-zodiac-section');
        const weddingSectionInit = document.getElementById('det-wedding-section');
        const memorialSectionInit = document.getElementById('det-memorial-section');
        if (zodiacSectionInit) zodiacSectionInit.classList.add('hidden');
        if (weddingSectionInit) weddingSectionInit.classList.add('hidden');
        if (memorialSectionInit) memorialSectionInit.classList.add('hidden');

        if (modalDetailsLoading) modalDetailsLoading.classList.remove('hidden');
        if (modalDetailsBody) modalDetailsBody.classList.add('hidden');

        try {
            const res = await window.KaiHttp.postJson('api.php', {
                action: 'get_event',
                id: eventId
            });

            if (!res.success || !res.event) {
                alert(res.message || 'Ereignis konnte nicht geladen werden.');
                closeDetailsModal();
                return;
            }

            const ev = res.event;
            const details = res.details || {};

            // Header-Bereich
            const detTitle = document.getElementById('det-title');
            const detSubtitle = document.getElementById('det-subtitle');
            const detBadgeWrap = document.getElementById('det-badge-wrap');

            if (detTitle) detTitle.textContent = ev.title || '';

            if (detSubtitle) {
                const yearStr = ev.event_year ? ` ${ev.event_year}` : '';
                const typeText = typeLabels[ev.event_type] || 'Ereignis';
                const agePart = details.age_text ? ` • ${details.age_text}` : '';
                detSubtitle.textContent = `${typeText} am ${details.formatted_day_month || ''}${yearStr}${agePart}`;
            }

            const detWeekdayInfo = document.getElementById('det-weekday-info');
            if (detWeekdayInfo) {
                let parts = [];
                if (details.next_weekday_name && details.next_date) {
                    const d = details.next_date.split('-');
                    const formattedDate = (d.length === 3) ? `${d[2]}.${d[1]}.${d[0]}` : details.next_date;
                    parts.push(`📅 Nächster Termin: <strong>${window.KaiHtml.escape(details.next_weekday_name)}</strong>, ${formattedDate}`);
                }
                if (details.birth_weekday_name && ev.event_year) {
                    const prefix = (ev.event_type === 'birthday') ? 'Geboren an einem' : 'War ein';
                    parts.push(`${prefix} <strong>${window.KaiHtml.escape(details.birth_weekday_name)}</strong>`);
                }
                detWeekdayInfo.innerHTML = parts.join(' &bull; ');
            }

            if (detBadgeWrap) {
                const isToday = details.days_remaining === 0;
                const badgeClass = isToday ? 'cal-badge-pill cal-badge-today' : 'cal-badge-pill cal-badge-soon';
                detBadgeWrap.innerHTML = `<span class="${badgeClass}">${window.KaiHtml.escape(details.badge_text || '')}</span>`;
            }

            // Sektionen steuern je nach Ereignis-Typ
            const zodiacSection = document.getElementById('det-zodiac-section');
            const weddingSection = document.getElementById('det-wedding-section');
            const memorialSection = document.getElementById('det-memorial-section');

            if (ev.event_type === 'anniversary') {
                if (modalDetailsTitle) modalDetailsTitle.textContent = '💍 Hochzeitsjubiläum & Details';
                if (zodiacSection) zodiacSection.classList.add('hidden');
                if (memorialSection) memorialSection.classList.add('hidden');
                if (weddingSection) {
                    weddingSection.classList.remove('hidden');
                    const wed = details.wedding_anniversary || {};
                    const wedSymbol = document.getElementById('det-wedding-symbol');
                    const wedName = document.getElementById('det-wedding-name');
                    const wedYears = document.getElementById('det-wedding-years');
                    const wedMeaning = document.getElementById('det-wedding-meaning');
                    const wedGift = document.getElementById('det-wedding-gift');
                    const wedMilestones = document.getElementById('det-wedding-milestones');

                    if (wedSymbol) wedSymbol.textContent = wed.symbol || '💍';
                    if (wedName) wedName.textContent = wed.name || 'Jahrestag';
                    if (wedYears) {
                        wedYears.textContent = wed.years ? `${wed.years}. Hochzeitstag` : 'Jahrestag ohne Jahresangabe';
                    }
                    if (wedMeaning) wedMeaning.textContent = wed.meaning || 'Ein besonderer Jahrestag der Liebe und Treue.';
                    if (wedGift) wedGift.textContent = wed.gift || 'Gemeinsame Zeit oder ein schönes Geschenk.';

                    if (wedMilestones) {
                        if (Array.isArray(wed.milestones) && wed.milestones.length > 0) {
                            wedMilestones.innerHTML = wed.milestones.map(m =>
                                `<span class="cal-milestone-pill">💍 <strong>${m.years} J.:</strong> ${window.KaiHtml.escape(m.name)}</span>`
                            ).join('');
                        } else {
                            wedMilestones.innerHTML = '<span class="text-muted" style="font-size: 0.8rem;">Keine weiteren Meilensteine</span>';
                        }
                    }
                }
            } else if (ev.event_type === 'memorial') {
                if (modalDetailsTitle) modalDetailsTitle.textContent = '🕯️ Gedenktag & Details';
                if (zodiacSection) zodiacSection.classList.add('hidden');
                if (weddingSection) weddingSection.classList.add('hidden');
                if (memorialSection) memorialSection.classList.remove('hidden');
            } else {
                // Geburtstag: Sternzeichen & Horoskop
                if (modalDetailsTitle) modalDetailsTitle.textContent = '🌟 Sternzeichen, Horoskop & Details';
                if (weddingSection) weddingSection.classList.add('hidden');
                if (memorialSection) memorialSection.classList.add('hidden');
                if (zodiacSection) zodiacSection.classList.remove('hidden');

                // Westliches Sternzeichen
                const w = details.western_zodiac;
                if (w) {
                    const wSymbol = document.getElementById('det-west-symbol');
                    const wName = document.getElementById('det-west-name');
                    const wRange = document.getElementById('det-west-range');
                    const wElement = document.getElementById('det-west-element');
                    const wTraits = document.getElementById('det-west-traits');
                    const wDesc = document.getElementById('det-west-desc');

                    if (wSymbol) wSymbol.textContent = w.symbol || '✨';
                    if (wName) wName.textContent = w.name || '';
                    if (wRange) wRange.textContent = w.date_range || '';
                    if (wElement) wElement.textContent = w.element || '';
                    if (wDesc) wDesc.textContent = w.description || '';

                    if (wTraits && Array.isArray(w.traits)) {
                        wTraits.innerHTML = w.traits.map(t =>
                            `<span class="cal-trait-pill">${window.KaiHtml.escape(t)}</span>`
                        ).join('');
                    }
                }

                // Chinesisches Tierkreiszeichen
                const c = details.chinese_zodiac;
                const cWrap = document.getElementById('det-chinese-wrap');
                const cMissing = document.getElementById('det-chinese-missing');

                if (c) {
                    if (cWrap) cWrap.classList.remove('hidden');
                    if (cMissing) cMissing.classList.add('hidden');

                    const cSymbol = document.getElementById('det-chinese-symbol');
                    const cName = document.getElementById('det-chinese-name');
                    const cYear = document.getElementById('det-chinese-year');
                    const cElement = document.getElementById('det-chinese-element');
                    const cPolarity = document.getElementById('det-chinese-polarity');
                    const cTraits = document.getElementById('det-chinese-traits');
                    const cDesc = document.getElementById('det-chinese-desc');
                    const cNumbers = document.getElementById('det-chinese-numbers');
                    const cColors = document.getElementById('det-chinese-colors');

                    if (cSymbol) cSymbol.textContent = c.symbol || '🏮';
                    if (cName) cName.textContent = c.full_name || '';
                    if (cYear) cYear.textContent = `Mondjahr ${c.lunar_year}`;
                    if (cElement) cElement.textContent = c.element || '';
                    if (cPolarity) cPolarity.textContent = c.polarity || '';
                    if (cDesc) cDesc.textContent = c.description || '';
                    if (cNumbers) cNumbers.textContent = c.lucky_numbers || '-';
                    if (cColors) cColors.textContent = c.lucky_colors || '-';

                    if (cTraits && Array.isArray(c.traits)) {
                        cTraits.innerHTML = c.traits.map(t =>
                            `<span class="cal-trait-pill">${window.KaiHtml.escape(t)}</span>`
                        ).join('');
                    }
                } else {
                    if (cWrap) cWrap.classList.add('hidden');
                    if (cMissing) cMissing.classList.remove('hidden');
                }
            }

            // Benachrichtigungsempfänger
            const recWrap = document.getElementById('det-recipients-wrap');
            if (recWrap) {
                if (Array.isArray(ev.recipients) && ev.recipients.length > 0) {
                    recWrap.innerHTML = ev.recipients.map(r =>
                        `<span class="cal-recipient-tag">👤 ${window.KaiHtml.escape(r.name || r.email)}</span>`
                    ).join('');
                } else {
                    recWrap.innerHTML = '<span class="text-muted" style="font-size: 0.8rem;">Keine Benachrichtigung konfiguriert</span>';
                }
            }

            // Vorlaufzeiten
            const advWrap = document.getElementById('det-advance-wrap');
            if (advWrap) {
                const advList = details.advance_days_list || [0, 1, 3];
                const advText = advList.map(d => {
                    return d === 0 ? 'am Tag selbst' : `${d} Tage vorher`;
                }).join(', ');
                advWrap.textContent = `⏰ Erinnerung erfolgt: ${advText}`;
            }

            // Notizen
            const notesContainer = document.getElementById('det-notes-container');
            const notesContent = document.getElementById('det-notes');
            if (notesContainer && notesContent) {
                if (ev.notes && ev.notes.trim() !== '') {
                    notesContent.textContent = ev.notes;
                    notesContainer.classList.remove('hidden');
                } else {
                    notesContainer.classList.add('hidden');
                }
            }

            if (modalDetailsLoading) modalDetailsLoading.classList.add('hidden');
            if (modalDetailsBody) modalDetailsBody.classList.remove('hidden');

        } catch (err) {
            alert('Netzwerk- oder Serverfehler beim Laden der Details.');
            closeDetailsModal();
        }
    }

    /**
     * Startet den Bearbeiten-Modus für ein gegebenes Ereignis.
     */
    async function triggerEdit(eventId) {
        if (!eventId) return;
        try {
            const res = await window.KaiHttp.postJson('api.php', {
                action: 'get_event',
                id: eventId
            });

            if (!res.success || !res.event) {
                alert(res.message || 'Ereignis konnte nicht geladen werden.');
                return;
            }

            const ev = res.event;
            resetFormToDefaults();

            if (eventIdInput) eventIdInput.value = ev.id;
            if (titleInput) titleInput.value = ev.title || '';
            if (typeSelect) typeSelect.value = ev.event_type || 'birthday';
            if (categoryInput) categoryInput.value = ev.category || 'Familie';
            if (daySelect) daySelect.value = String(ev.event_day);
            if (monthSelect) monthSelect.value = String(ev.event_month);
            if (yearInput) yearInput.value = ev.event_year ? String(ev.event_year) : '';
            if (notesInput) notesInput.value = ev.notes || '';

            // Vorlauftage setzen
            const advanceArr = (ev.notify_days_advance || '0,1,3')
                .split(',')
                .map(v => parseInt(v.trim(), 10));
            document.querySelectorAll('.js-advance-cb').forEach(cb => {
                const val = parseInt(cb.value, 10);
                cb.checked = advanceArr.includes(val);
            });

            // Empfänger-Checkboxen setzen
            const recipientEmails = (ev.recipient_emails || []).map(em => String(em).toLowerCase().trim());
            document.querySelectorAll('.js-recipient-cb').forEach(cb => {
                const cbEmail = cb.value.toLowerCase().trim();
                cb.checked = recipientEmails.includes(cbEmail);
            });

            openModal(true);
        } catch (err) {
            alert('Fehler beim Laden des Ereignisses.');
        }
    }

    // Event-Delegation für Klicks
    document.addEventListener('click', async function (e) {
        // Modal öffnen für Neuanlage
        if (e.target.closest('#btn-add-event')) {
            e.preventDefault();
            resetFormToDefaults();
            openModal(false);
            return;
        }

        // Modal schließen (Ereignis-Formular)
        if (e.target.closest('#modal-event-close') || e.target.closest('#modal-event-cancel')) {
            e.preventDefault();
            closeModal();
            return;
        }

        // Klick auf Overlay außerhalb der Card schließt Modal
        if (e.target === modal) {
            closeModal();
            return;
        }

        // Modal schließen (Horoskop & Details)
        if (e.target.closest('#modal-details-close') || e.target.closest('#modal-details-close-btn')) {
            e.preventDefault();
            closeDetailsModal();
            return;
        }

        if (e.target === modalDetails) {
            closeDetailsModal();
            return;
        }

        // Detail- / Horoskop-Modal öffnen
        const detailsBtn = e.target.closest('.js-view-details');
        if (detailsBtn) {
            e.preventDefault();
            const id = parseInt(detailsBtn.getAttribute('data-id'), 10);
            if (id) {
                openDetailsModal(id);
            }
            return;
        }

        // Aus dem Detail-Modal heraus Test-Push senden
        if (e.target.closest('#det-btn-test-push')) {
            e.preventDefault();
            if (!currentDetailEventId) return;

            const btn = document.getElementById('det-btn-test-push');
            if (btn) btn.disabled = true;

            try {
                const res = await window.KaiHttp.postJson('api.php', {
                    action: 'send_test_push',
                    id: currentDetailEventId
                });
                alert(res.success ? '🔔 ' + res.message : 'Hinweis: ' + res.message);
            } catch (err) {
                alert('Netzwerk- oder Serverfehler beim Senden des Test-Push.');
            } finally {
                if (btn) btn.disabled = false;
            }
            return;
        }

        // Aus dem Detail-Modal heraus bearbeiten
        if (e.target.closest('#det-btn-edit') || e.target.closest('#det-btn-add-year')) {
            e.preventDefault();
            const id = currentDetailEventId;
            closeDetailsModal();
            if (id) {
                triggerEdit(id);
            }
            return;
        }

        // Schnellauswahl Empfänger: Alle wählen
        if (e.target.closest('#btn-select-all-recipients')) {
            e.preventDefault();
            document.querySelectorAll('.js-recipient-cb').forEach(cb => {
                cb.checked = true;
            });
            return;
        }

        // Schnellauswahl Empfänger: Nur mich
        const meBtn = e.target.closest('#btn-select-me-recipient');
        if (meBtn) {
            e.preventDefault();
            const myEmail = (meBtn.getAttribute('data-email') || '').toLowerCase().trim();
            document.querySelectorAll('.js-recipient-cb').forEach(cb => {
                cb.checked = (cb.value.toLowerCase().trim() === myEmail);
            });
            return;
        }

        // Schnellauswahl Empfänger: Keine
        if (e.target.closest('#btn-clear-recipients')) {
            e.preventDefault();
            document.querySelectorAll('.js-recipient-cb').forEach(cb => {
                cb.checked = false;
            });
            return;
        }

        // Test-Push aus der Karte oder Tabelle senden
        const testPushBtn = e.target.closest('.js-test-push');
        if (testPushBtn) {
            e.preventDefault();
            const id = parseInt(testPushBtn.getAttribute('data-id'), 10);
            if (!id) return;

            testPushBtn.disabled = true;
            const originalText = testPushBtn.innerHTML;
            testPushBtn.innerHTML = '⏳...';

            try {
                const res = await window.KaiHttp.postJson('api.php', {
                    action: 'send_test_push',
                    id: id
                });
                if (res.success) {
                    alert('🔔 ' + (res.message || 'Test-Benachrichtigung versendet!'));
                } else {
                    alert('Hinweis: ' + (res.message || 'Push konnte nicht gesendet werden. Bitte prüfen, ob Web-Push im Profil aktiviert ist.'));
                }
            } catch (err) {
                alert('Netzwerk- oder Serverfehler beim Senden des Test-Push.');
            } finally {
                testPushBtn.disabled = false;
                testPushBtn.innerHTML = originalText;
            }
            return;
        }

        // Ereignis bearbeiten aus Karte / Tabelle
        const editBtn = e.target.closest('.js-edit-event');
        if (editBtn) {
            e.preventDefault();
            const id = parseInt(editBtn.getAttribute('data-id'), 10);
            if (!id) return;

            editBtn.disabled = true;
            await triggerEdit(id);
            editBtn.disabled = false;
            return;
        }

        // Ereignis löschen
        const delBtn = e.target.closest('.js-delete-event');
        if (delBtn) {
            e.preventDefault();
            const id = parseInt(delBtn.getAttribute('data-id'), 10);
            if (!id) return;

            if (!confirm('Möchten Sie dieses Ereignis wirklich löschen?')) {
                return;
            }

            delBtn.disabled = true;

            try {
                const res = await window.KaiHttp.postJson('api.php', {
                    action: 'delete_event',
                    id: id
                });

                if (res.success) {
                    window.location.reload();
                } else {
                    alert(res.message || 'Fehler beim Löschen.');
                    delBtn.disabled = false;
                }
            } catch (err) {
                alert('Netzwerk- oder Serverfehler beim Löschen.');
                delBtn.disabled = false;
            }
        }
    });

    // Formular absenden (Speichern / Aktualisieren)
    if (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            const saveBtn = document.getElementById('btn-save-event');
            if (saveBtn) saveBtn.disabled = true;

            const recipients = [];
            document.querySelectorAll('.js-recipient-cb:checked').forEach(cb => {
                recipients.push(cb.value);
            });

            const advanceDays = [];
            document.querySelectorAll('.js-advance-cb:checked').forEach(cb => {
                advanceDays.push(parseInt(cb.value, 10));
            });

            const payload = {
                action: 'save_event',
                id: eventIdInput && eventIdInput.value ? parseInt(eventIdInput.value, 10) : null,
                title: titleInput ? titleInput.value.trim() : '',
                event_type: typeSelect ? typeSelect.value : 'birthday',
                category: categoryInput ? categoryInput.value.trim() : 'Familie',
                event_day: daySelect ? parseInt(daySelect.value, 10) : 1,
                event_month: monthSelect ? parseInt(monthSelect.value, 10) : 1,
                event_year: yearInput && yearInput.value.trim() !== '' ? parseInt(yearInput.value.trim(), 10) : null,
                notes: notesInput ? notesInput.value.trim() : '',
                recipients: recipients,
                notify_days_advance: advanceDays
            };

            if (!payload.title) {
                alert('Bitte einen Namen oder Anlass angeben.');
                if (saveBtn) saveBtn.disabled = false;
                return;
            }

            try {
                const res = await window.KaiHttp.postJson('api.php', payload);
                if (res.success) {
                    window.location.reload();
                } else {
                    alert(res.message || 'Fehler beim Speichern des Ereignisses.');
                    if (saveBtn) saveBtn.disabled = false;
                }
            } catch (err) {
                alert('Netzwerk- oder Serverfehler beim Speichern.');
                if (saveBtn) saveBtn.disabled = false;
            }
        });
    }
});
