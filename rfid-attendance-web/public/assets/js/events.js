(() => {
    document.addEventListener('DOMContentLoaded', () => {
        const workspace = document.querySelector('[data-event-workspace]');
        const editor = document.querySelector('[data-event-workspace-editor]');
        const editorDialog = editor?.closest('dialog');
        const api = workspace?.dataset.api || '/api/events.php';
        const id = workspace?.dataset.eventId;
        const formatTime = (value) => {
            if (!value) return 'Not recorded';
            const date = new Date(String(value).replace(' ', 'T') + '+08:00');
            return Number.isNaN(date.getTime()) ? 'Time unavailable' : new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(date);
        };
        const actionLabel = { check_in: 'Check-in', check_out: 'Checkout', status_change: 'Attendance change' };
        const sourceLabel = { rfid: 'Connected scanner', manual: 'Manual entry', system: 'System' };
        const request = async (url, options = {}) => {
            const timeout = AbortSignal.timeout(options.method === 'POST' ? 120000 : 15000);
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, ...options, signal: options.signal ? AbortSignal.any([options.signal, timeout]) : timeout });
            let data;
            try { data = await response.json(); } catch { throw new Error('The event service could not be reached. Refresh or retry.'); }
            if (!response.ok || !data.ok) { const error = new Error(data.message || 'The request could not be completed.'); error.status = response.status; error.payload = data; throw error; }
            return data;
        };
        const feedback = (message) => {
            let region = document.querySelector('[data-event-page-feedback]');
            if (!region) { region = document.createElement('p'); region.dataset.eventPageFeedback = ''; region.setAttribute('role', 'status'); document.querySelector('.page-header')?.after(region); }
            region.textContent = message;
        };
        const confirmAction = (title, message, label) => new Promise((resolve) => {
            const dialog = document.createElement('dialog'); dialog.className = 'event-confirm-dialog';
            const heading = document.createElement('h2'); heading.textContent = title;
            const explanation = document.createElement('p'); explanation.textContent = message;
            const actions = document.createElement('div'); actions.className = 'table-actions';
            const cancel = document.createElement('button'); cancel.className = 'button button-secondary'; cancel.textContent = 'Keep event';
            const proceed = document.createElement('button'); proceed.className = 'button button-danger'; proceed.textContent = label;
            const close = (answer) => { dialog.close(); dialog.remove(); resolve(answer); };
            cancel.addEventListener('click', () => close(false)); proceed.addEventListener('click', () => close(true));
            dialog.addEventListener('cancel', (e) => { e.preventDefault(); close(false); });
            actions.append(cancel, proceed); dialog.append(heading, explanation, actions); document.body.append(dialog); dialog.showModal(); cancel.focus();
        });
        let imageUrl;
        const setField = (name, value) => {
            const field = editor?.elements[name]; if (!field) return;
            if (field.type === 'checkbox') field.checked = Boolean(Number(value));
            else { field.value = value || ''; field._flatpickr?.setDate(field.value || null, false, field.type === 'time' || ['start_time','end_time','check_in_start','check_in_end'].includes(name) ? 'H:i' : 'Y-m-d'); }
        };
        const closeEditor = () => { if (!editor?.dataset.saving) editorDialog.close(); };
        document.querySelectorAll('[data-event-editor-close]').forEach((button) => button.addEventListener('click', closeEditor));
        editorDialog?.addEventListener('cancel', (e) => { if (editor.dataset.saving) e.preventDefault(); });
        const openEditor = async (eventId) => {
            if (!editor) return;
            editor.reset(); ['title','description','venue','event_date','end_date','start_time','end_time','check_in_start','check_in_end'].forEach((name) => setField(name, '')); editor.querySelector('[data-event-editor-status]').textContent = '';
            setField('action', eventId ? 'update_event' : 'create_event'); setField('event_id', eventId);
            editor.querySelector('[data-workspace-image-preview]').src = '/assets/images/default-event-banner.svg';
            if (imageUrl) { URL.revokeObjectURL(imageUrl); imageUrl = null; }
            document.querySelector('#event-editor-title').textContent = eventId ? 'Edit event' : 'Create event';
            editor.querySelector('[data-event-editor-submit]').textContent = eventId ? 'Save changes' : 'Create event';
            const scanners = editor.elements['scanner_ids[]'];
            scanners?._choicesInstance?.removeActiveItems();
            if (scanners) Array.from(scanners.options).forEach((option) => { option.selected = false; });
            window.setEventTargets?.(editor, [{ type: 'all_students', id: null, roleFilter: 'any' }]);
            if (eventId) {
                const { event } = await request(`${api}?mode=details&event_id=${eventId}`);
                if (!event.can_manage) throw new Error('This event is outside your management scope.');
                if (!['draft','scheduled'].includes(event.status)) throw new Error('Events with active or finalized attendance are protected. Cancel the event to preserve its history.');
                ['title','description','venue','event_date','end_date','start_time','end_time','check_in_start','check_in_end','attendance_required','check_out_required','suspends_classes','is_announcement'].forEach((name) => setField(name, event[name]));
                window.setEventTargets?.(editor, event.targets || []);
                if (scanners) {
                    const selected = (event.scanners || []).map((scanner) => String(scanner.id));
                    Array.from(scanners.options).forEach((option) => { option.selected = selected.includes(option.value); });
                    scanners._choicesInstance?.setChoiceByValue(selected);
                }
                editor.querySelector('[data-workspace-image-preview]').src = event.banner_image_path || '/assets/images/default-event-banner.svg';
            }
            editorDialog.showModal(); editor.elements.title.focus();
        };
        document.querySelectorAll('[data-event-create]').forEach((button) => button.addEventListener('click', () => openEditor().catch((error) => feedback(error.message))));
        document.querySelectorAll('[data-event-edit]').forEach((button) => button.addEventListener('click', async () => { button.disabled = true; try { await openEditor(button.dataset.eventEdit); } catch (error) { feedback(error.message); } finally { button.disabled = false; } }));
        editor?.querySelector('[data-workspace-images]').addEventListener('change', (e) => {
            if (imageUrl) URL.revokeObjectURL(imageUrl);
            const file = e.target.files[0];
            if (file && ['image/jpeg','image/png','image/webp'].includes(file.type)) { imageUrl = URL.createObjectURL(file); editor.querySelector('[data-workspace-image-preview]').src = imageUrl; }
        });
        editor?.addEventListener('submit', async (e) => {
            e.preventDefault(); if (editor.dataset.saving) return;
            const data = new FormData(editor); const status = editor.querySelector('[data-event-editor-status]');
            editor.querySelectorAll('[data-event-editor-close]').forEach((button) => { button.disabled = true; });
            editor.dataset.saving = '1'; editor.setAttribute('aria-busy', 'true'); const submit = editor.querySelector('[data-event-editor-submit]'); submit.disabled = true; status.textContent = 'Saving event…';
            try {
                const result = await request(editor.dataset.endpoint, { method: 'POST', body: data });
                status.textContent = result.message; delete editor.dataset.saving; editorDialog.close();
                window.location.assign(result.event.view_url || `/event.php?id=${result.event.source_id}`);
            } catch (error) { status.textContent = error.name === 'TimeoutError' ? 'Saving is taking longer than expected. Check the events list before retrying to avoid a duplicate event.' : error.message; } finally { delete editor.dataset.saving; editor.removeAttribute('aria-busy'); submit.disabled = false; editor.querySelectorAll('[data-event-editor-close]').forEach((button) => { button.disabled = false; }); }
        });
        document.querySelectorAll('[data-event-delete]').forEach((button) => button.addEventListener('click', async () => {
            if (!await confirmAction('Delete this event?', 'Only events without protected attendance or activity history can be deleted. This permanently removes the event and its audience.', 'Delete event')) return;
            button.disabled = true;
            try {
                const data = new FormData(); data.set('action', 'delete_event'); data.set('event_id', button.dataset.eventDelete);
                const csrf = editor?.querySelector('input[name="_token"]'); if (csrf) data.set(csrf.name, csrf.value);
                await request(editor.dataset.endpoint, { method: 'POST', body: data });
                if (workspace) window.location.assign(document.querySelector('.event-back-link').href); else button.closest('[data-search-item]')?.remove();
            } catch (error) { feedback(error.message); if (error.status === 409) feedback('This event has protected attendance or activity history. Cancel the event to retain those records.'); } finally { button.disabled = false; }
        }));
        if (!workspace) return;
        let page = 1, pages = 1, activityController;
        const filters = workspace.querySelector('[data-event-activity-filters]');
        const activityFeedback = workspace.querySelector('[data-event-activity-feedback]');
        const body = workspace.querySelector('[data-event-activity-rows]');
        const loadActivity = async () => {
            activityController?.abort(); const controller = new AbortController(); activityController = controller;
            const params = new URLSearchParams(new FormData(filters)); params.set('mode', 'activity'); params.set('event_id', id); params.set('page', page);
            activityFeedback.textContent = 'Loading activity…';
            try {
                const data = await request(`${api}?${params}`, { signal: controller.signal }); page = data.page; pages = data.pages; body.replaceChildren();
                data.records.forEach((row) => {
                    const tr = document.createElement('tr'); const personCell = document.createElement('td'); const person = document.createElement('div'); person.className = 'event-activity-person';
                    const avatar = document.createElement('img'); avatar.src = row.profile_image_path || '/assets/images/default-avatar.svg'; avatar.alt = ''; avatar.addEventListener('error', () => { avatar.src = '/assets/images/default-avatar.svg'; }, { once: true });
                    const name = document.createElement('div'); const title = document.createElement('strong'); title.textContent = `${row.first_name} ${row.last_name}`; const school = document.createElement('small'); school.textContent = row.school_id;
                    name.append(title, school); person.append(avatar, name); personCell.append(person); tr.append(personCell);
                    [actionLabel[row.action] || 'Attendance change', formatTime(row.occurred_at), sourceLabel[row.source] || row.source, row.status].forEach((value, index) => { const td = document.createElement('td'); td.textContent = value; if (index === 1 && Number(row.legacy)) { const legacy = document.createElement('small'); legacy.textContent = 'Previously saved record'; td.append(legacy); } tr.append(td); }); body.append(tr);
                });
                activityFeedback.textContent = data.total ? `Updated ${formatTime(data.server_time)} · ${data.timezone}` : 'No recorded activity matches these filters.';
                workspace.querySelector('[data-event-activity-count]').textContent = `${data.total} activity records`;
                workspace.querySelector('[data-activity-page]').textContent = `Page ${page} of ${pages}`;
                workspace.querySelector('[data-activity-previous]').disabled = page <= 1; workspace.querySelector('[data-activity-next]').disabled = page >= pages;
            } catch (error) { if (error.name !== 'AbortError') activityFeedback.textContent = error.message + ' Use Refresh to retry.'; }
        };
        filters.addEventListener('submit', (e) => { e.preventDefault(); page = 1; loadActivity(); });
        filters.addEventListener('reset', () => { page = 1; setTimeout(loadActivity, 0); });
        workspace.querySelector('[data-activity-previous]').addEventListener('click', () => { page = Math.max(1, page - 1); loadActivity(); });
        workspace.querySelector('[data-activity-next]').addEventListener('click', () => { page = Math.min(pages, page + 1); loadActivity(); });
        workspace.querySelector('[data-activity-retry]').addEventListener('click', loadActivity); loadActivity();
        const statusForm = workspace.querySelector('[data-event-status-form]');
        statusForm?.addEventListener('submit', async (e) => {
            e.preventDefault(); const status = statusForm.elements.status.value;
            if (['closed','cancelled'].includes(status) && !await confirmAction(status === 'closed' ? 'Close event attendance?' : 'Cancel this event?', status === 'closed' ? 'Missing required attendees will be marked absent. Required checkouts that are missing will be marked incomplete. The activity history is preserved.' : 'This stops attendance recording and preserves existing history.', status === 'closed' ? 'Close attendance' : 'Cancel event')) return;
            const button = statusForm.querySelector('button'); button.disabled = true;
            try { const data = new FormData(statusForm); data.set('action','set_status'); data.set('event_id',id); await request(api, { method:'POST',body:data }); window.location.reload(); } catch (error) { statusForm.querySelector('[data-event-status-message]').textContent = error.message; } finally { button.disabled = false; }
        });
        const tracking = workspace.querySelector('[data-event-tracking]'); if (!tracking) return;
        const shownScans = new Set();
        let highestShownId = 0;
        let liveTimer, liveController, cursor = null, liveGeneration = 0, failures = 0;
        const liveStatus = tracking.querySelector('[data-event-live-status]');
        const showScan = (row) => {
            if (Number(row.id) && Number(row.id) <= highestShownId) return;
            if (Number(row.id)) highestShownId = Number(row.id);
            const key = `${row.school_id}:${row.action}:${row.occurred_at}`;
            if (shownScans.has(key)) return;
            shownScans.add(key); if (shownScans.size > 100) shownScans.delete(shownScans.values().next().value);
            tracking.querySelector('[data-tracking-school-id]').textContent = row.school_id;
            tracking.querySelector('[data-tracking-last-name]').textContent = row.last_name;
            tracking.querySelector('[data-tracking-first-name]').textContent = row.first_name;
            tracking.querySelector('[data-tracking-avatar]').src = row.profile_image_path || '/assets/images/default-avatar.svg';
            tracking.querySelector('[data-tracking-outcome]').dataset.action = row.action;
            tracking.querySelector('[data-tracking-action]').textContent = actionLabel[row.action] || 'Attendance change';
            tracking.querySelector('[data-tracking-time]').textContent = formatTime(row.occurred_at);
            const recent = tracking.querySelector('[data-tracking-recent]'); const item = document.createElement('li'); item.textContent = `${row.first_name} ${row.last_name} · ${actionLabel[row.action] || row.status} · ${formatTime(row.occurred_at)}`; recent.prepend(item); while (recent.children.length > 5) recent.lastElementChild.remove();
        };
        tracking.querySelector('[data-tracking-avatar]').addEventListener('error', (e) => { if (!e.target.src.endsWith('/default-avatar.svg')) e.target.src = '/assets/images/default-avatar.svg'; });
        const poll = async (generation) => {
            if (!tracking.open || generation !== liveGeneration || document.hidden) return;
            liveController?.abort(); liveController = new AbortController();
            try {
                const data = await request(`${api}?mode=live&event_id=${id}${cursor === null ? '' : `&after=${cursor}`}`, { signal: liveController.signal });
                if (generation !== liveGeneration || !tracking.open) return;
                cursor = data.cursor; failures = 0; data.records.forEach(showScan);
                liveStatus.textContent = `Live · checked ${formatTime(data.server_time)}`;
                if (!data.accepting) { liveStatus.textContent = 'Event attendance is closed. Activity history is preserved.'; tracking.dataset.blocked = '1'; tracking.querySelector('[data-event-manual-form] button').disabled = true; return; }
                liveTimer = setTimeout(() => poll(generation), 2000);
            } catch (error) {
                if (error.name === 'AbortError' || generation !== liveGeneration) return;
                liveStatus.textContent = error.message + ' Live updates are paused.';
                if ([401,403,419,422].includes(error.status)) { tracking.dataset.blocked = '1'; tracking.querySelector('[data-event-manual-form] button').disabled = true; return; }
                failures++; liveTimer = setTimeout(() => poll(generation), Math.min(15000, 2000 * failures));
            }
        };
        workspace.querySelector('[data-event-tracking-start]')?.addEventListener('click', async () => {
            cursor = null; failures = 0; highestShownId = 0; shownScans.clear();
            tracking.querySelector('[data-tracking-school-id]').textContent = 'Ready for the next scan';
            tracking.querySelector('[data-tracking-last-name]').textContent = 'Waiting for a participant';
            tracking.querySelector('[data-tracking-first-name]').textContent = 'Use a connected event scanner';
            tracking.querySelector('[data-tracking-avatar]').src = '/assets/images/default-avatar.svg';
            delete tracking.querySelector('[data-tracking-outcome]').dataset.action;
            tracking.querySelector('[data-tracking-action]').textContent = 'Waiting for activity';
            tracking.querySelector('[data-tracking-time]').textContent = 'Server timestamps';
            tracking.querySelector('[data-tracking-recent]').replaceChildren(); delete tracking.dataset.blocked; liveGeneration++; tracking.showModal(); tracking.querySelector('[data-event-manual-form] button').disabled = false;
            // Fullscreen the document; the modal remains in the browser's top layer.
            try { await document.documentElement.requestFullscreen?.(); } catch { /* The viewport modal also works without browser fullscreen support. */ }
            poll(liveGeneration);
        });
        const stopTracking = () => { liveGeneration++; clearTimeout(liveTimer); liveController?.abort(); if (tracking.open) tracking.close(); if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {}); loadActivity(); };
        tracking.querySelector('[data-event-tracking-close]').addEventListener('click', stopTracking);
        tracking.addEventListener('cancel', (e) => { e.preventDefault(); stopTracking(); });
        document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement && tracking.open) stopTracking(); });
        document.addEventListener('visibilitychange', () => { clearTimeout(liveTimer); liveController?.abort(); if (!document.hidden && tracking.open) poll(liveGeneration); });
        tracking.querySelector('[data-event-manual-form]').addEventListener('submit', async (e) => {
            e.preventDefault(); const form = e.target; const button = form.querySelector('button'); const status = form.querySelector('[data-event-manual-status]'); if (button.disabled) return;
            button.disabled = true; status.textContent = 'Recording attendance…';
            try { const data = new FormData(form); data.set('event_id', id); data.set('action', 'manual_id'); const result = await request(api, {method:'POST',body:data}); showScan({ ...result.scan.person, ...result.scan }); status.textContent = `${actionLabel[result.scan.action]} recorded for ${result.scan.person.first_name} ${result.scan.person.last_name} at ${formatTime(result.scan.occurred_at)}.`; form.elements.school_id.value = ''; form.elements.school_id.focus(); clearTimeout(liveTimer); await poll(liveGeneration); } catch (error) { status.textContent = error.message; } finally { button.disabled = tracking.dataset.blocked === '1'; }
        });
        window.addEventListener('pagehide', () => { clearTimeout(liveTimer); liveController?.abort(); activityController?.abort(); });
    });
})();
