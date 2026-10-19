document.addEventListener('DOMContentLoaded', () => {
    const dialogs = {
        room: document.querySelector('[data-facility-dialog="room"]'),
        device: document.querySelector('[data-facility-dialog="device"]'),
    };
    const credentialsDialog = document.querySelector('[data-scanner-credentials-dialog]');
    if (!dialogs.room && !dialogs.device) return;

    const requestJson = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'fetch', ...(options.headers || {}) },
            ...options,
        });
        let payload;
        try { payload = await response.json(); } catch { throw new Error('The server returned an invalid response.'); }
        if (!response.ok || !payload.ok) throw window.deletionConflictError?.(payload, 'The request could not be completed.') || new Error(payload.message || 'The request could not be completed.');
        return payload;
    };

    const notify = (message, type = 'success') => {
        const toast = document.createElement('div');
        toast.className = `app-toast${type === 'success' ? '' : ` is-${type}`}`;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.innerHTML = `<span class="app-toast-icon">${type === 'success' ? '✓' : '!'}</span><span class="app-toast-copy"><strong>${type === 'success' ? 'Saved' : 'Couldn\'t save'}</strong><span></span></span><button type="button" class="app-toast-close" aria-label="Dismiss notification">×</button>`;
        toast.querySelector('.app-toast-copy span').textContent = message;
        const host = document.querySelector('[data-toast-region]') || document.body;
        host.append(toast);
        const remove = () => toast.remove();
        toast.querySelector('button').addEventListener('click', remove);
        window.setTimeout(remove, type === 'error' ? 8000 : 5000);
    };

    const setSelectValue = (select, value) => {
        select.value = value == null ? '' : String(value);
        select._choicesInstance?.setChoiceByValue(select.value);
    };

    const refreshSearchableSelect = (select) => {
        if (window.refreshSearchableSelect) {
            window.refreshSearchableSelect(select);
            return;
        }
        if (!select._choicesInstance || !window.Choices) return;
        const selectedValue = select.value;
        select._choicesInstance.destroy();
        select._choicesInstance = new window.Choices(select, {
            allowHTML: false,
            itemSelectText: '',
            noResultsText: 'No matching options',
            noChoicesText: 'No options available',
            searchEnabled: true,
            searchFloor: 1,
            searchPlaceholderValue: 'Type to search…',
            searchResultLimit: 50,
            shouldSort: false,
        });
        select._choicesInstance.setChoiceByValue(selectedValue);
    };

    const setBusy = (dialog, busy, message = '') => {
        const loading = dialog.querySelector('[data-facility-loading]');
        const form = dialog.querySelector('[data-facility-form]');
        const error = dialog.querySelector('[data-facility-error]');
        loading.hidden = !busy;
        form.hidden = busy;
        error.hidden = !message;
        error.textContent = message;
    };

    const openEditor = async (entity, id) => {
        const dialog = dialogs[entity];
        if (!dialog || !id) return;
        const form = dialog.querySelector('[data-facility-form]');
        form.reset();
        form.querySelector('[data-facility-feedback]').textContent = '';
        setBusy(dialog, true);
        dialog.showModal();
        const url = new URL(dialog.dataset.endpoint, window.location.origin);
        url.searchParams.set('entity', entity);
        url.searchParams.set('id', id);
        try {
            const payload = await requestJson(url);
            const record = payload[entity];
            if (entity === 'room') {
                form.elements.room_id.value = record.id;
                form.elements.room_code.value = record.room_code;
                form.elements.room_name.value = record.room_name;
                form.elements.building.value = record.building || '';
                form.elements.floor.value = record.floor || '';
                dialog.querySelector('[data-facility-subtitle]').textContent = `${record.room_code} · ${record.room_name}`;
                dialog.querySelector('[data-room-seat-plan-name]').textContent = record.seat_plan_name || 'No saved seat plan';
                dialog.querySelector('[data-room-seat-plan-meta]').textContent = record.seat_plan_id
                    ? `${record.seat_plan_rows}×${record.seat_plan_columns} grid · ${record.seat_plan_cell_count} active cells`
                    : 'Create a layout for this room or manage its existing grid.';
                dialog.querySelector('[data-open-room-seat-plan]').dataset.roomId = record.id;
            } else {
                form.elements.device_id.value = record.id;
                form.elements.device_code.value = record.device_code;
                form.elements.name.value = record.name;
                setSelectValue(form.elements.room_id, record.room_id);
            }
            setBusy(dialog, false);
            window.requestAnimationFrame(() => form.querySelector('input:not([type="hidden"]), select')?.focus());
        } catch (error) {
            setBusy(dialog, false, error.message);
            form.hidden = true;
        }
    };

    document.addEventListener('click', (event) => {
        const roomButton = event.target.closest('[data-manage-room]');
        if (roomButton) openEditor('room', Number(roomButton.dataset.roomId));
        const deviceButton = event.target.closest('[data-manage-device]');
        if (deviceButton) openEditor('device', Number(deviceButton.dataset.deviceId));
        const seatPlanButton = event.target.closest('[data-open-room-seat-plan]');
        if (seatPlanButton) {
            const roomSelect = document.querySelector('[data-seat-plan-room]');
            const target = document.querySelector('#rooms-seat-plans');
            if (roomSelect && target) {
                dialogs.room?.close();
                roomSelect.value = seatPlanButton.dataset.roomId;
                roomSelect.dispatchEvent(new Event('change', { bubbles: true }));
                target.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
            }
        }
    });

    const requestedEditor = new URLSearchParams(window.location.search);
    if (['room', 'device'].includes(requestedEditor.get('manage')) && Number(requestedEditor.get('entity_id')) > 0) {
        openEditor(requestedEditor.get('manage'), Number(requestedEditor.get('entity_id')));
    }

    const syncRoom = (room) => {
        const row = document.querySelector(`[data-room-row="${room.id}"]`);
        if (row) {
            row.querySelector('[data-room-identity]').innerHTML = '';
            const strong = document.createElement('strong');
            strong.textContent = room.room_code;
            row.querySelector('[data-room-identity]').append(strong, document.createElement('br'), document.createTextNode(room.room_name));
            row.querySelector('[data-room-location]').textContent = [room.building, room.floor].filter(Boolean).join(' ') || 'Not specified';
            const edit = row.querySelector('[data-manage-room]');
            edit.setAttribute('aria-label', `Edit room ${room.room_code}`);
        }
        document.querySelectorAll(`tr[data-device-room-id="${room.id}"] [data-device-room]`).forEach((cell) => { cell.textContent = room.room_code; });
        const affectedSelects = new Set();
        document.querySelectorAll(`select[name="room_id"] option[value="${room.id}"]`).forEach((option) => {
            option.dataset.detail = room.room_name;
            option.textContent = option.closest('[data-facility-dialog="device"]') ? `${room.room_code} — ${room.room_name}` : room.room_code;
            affectedSelects.add(option.closest('select'));
        });
        affectedSelects.forEach(refreshSearchableSelect);
        const seatOption = document.querySelector(`[data-seat-plan-room] option[value="${room.id}"]`);
        if (seatOption) {
            seatOption.textContent = `${room.room_code} — ${room.room_name}`;
            refreshSearchableSelect(seatOption.closest('select'));
        }
    };

    const syncDevice = (device, oldRoomId) => {
        const row = document.querySelector(`[data-device-row="${device.id}"]`);
        if (row) {
            const identity = row.querySelector('[data-device-identity]');
            identity.innerHTML = '';
            const strong = document.createElement('strong');
            strong.textContent = device.device_code;
            identity.append(strong, document.createElement('br'), document.createTextNode(device.name));
            row.querySelector('[data-device-room]').textContent = device.room_code || 'Unassigned';
            row.dataset.deviceRoomId = device.room_id || '';
            row.querySelector('[data-manage-device]').setAttribute('aria-label', `Edit scanner ${device.device_code}`);
        }
        if (oldRoomId) document.querySelector(`[data-room-row="${oldRoomId}"] [data-room-scanner]`)?.replaceChildren(document.createTextNode('Unassigned'));
        if (device.room_id) document.querySelector(`[data-room-row="${device.room_id}"] [data-room-scanner]`)?.replaceChildren(document.createTextNode(device.device_code));
    };

    const statusIconMarkup = (active) => active
        ? '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7.5"></circle><path d="m6.5 10 2.2 2.3 4.8-5"></path></svg>'
        : '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7.5"></circle><path d="m7.2 7.2 5.6 5.6M12.8 7.2l-5.6 5.6"></path></svg>';

    const syncStatus = (entity, record) => {
        const row = document.querySelector(`[data-${entity}-row="${record.id}"]`);
        if (!row) return;
        const active = record.status === 'active';
        const symbol = row.querySelector('.status-symbol');
        const form = row.querySelector('[data-facility-status-form]');
        const button = form?.querySelector('button');
        const identity = entity === 'room' ? record.room_code : record.device_code;
        const entityLabel = entity === 'room' ? 'room' : 'scanner';
        const nextAction = active ? 'Deactivate' : 'Activate';

        row.dataset.status = record.status;
        if (symbol) {
            symbol.classList.toggle('status-symbol-positive', active);
            symbol.classList.toggle('status-symbol-negative', !active);
            symbol.setAttribute('aria-label', `Status: ${active ? 'Active' : 'Inactive'}`);
            symbol.title = active ? 'Active' : 'Inactive';
            symbol.innerHTML = statusIconMarkup(active);
        }
        if (button) {
            button.setAttribute('aria-label', `${nextAction} ${entityLabel} ${identity}`);
            button.title = `${nextAction} ${entityLabel}`;
        }
    };

    const syncDeviceRoomOption = (room) => {
        const select = dialogs.device?.querySelector('select[name="room_id"]');
        if (!select) return;
        let option = select.querySelector(`option[value="${room.id}"]`);
        if (room.status === 'active') {
            if (!option) {
                option = new Option(`${room.room_code} — ${room.room_name}`, String(room.id));
                select.add(option);
            } else {
                option.textContent = `${room.room_code} — ${room.room_name}`;
            }
        } else if (option) {
            option.remove();
        }
        refreshSearchableSelect(select);
    };

    const initializeFacilityTable = (table) => {
        if (!table) return;
        window.initializeDataTable?.(table);
    };

    const refreshFacilityDirectories = async () => {
        const response = await fetch(window.location.href, { credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'fetch' } });
        if (!response.ok) throw new Error('The facility lists could not be refreshed.');
        const documentResponse = new DOMParser().parseFromString(await response.text(), 'text/html');
        ['rooms-directory', 'rooms-scanners'].forEach((id) => {
            const current = document.getElementById(id);
            const incoming = documentResponse.getElementById(id);
            if (!current || !incoming) return;
            current.replaceWith(incoming);
            initializeFacilityTable(document.getElementById(id)?.querySelector('table'));
        });
    };

    const showScannerCredentials = (credentials, device) => {
        if (!credentialsDialog) return;
        credentialsDialog.querySelector('[data-scanner-credential-device-code]').textContent = credentials.device_code;
        credentialsDialog.querySelector('[data-scanner-credential-room]').textContent = device?.room_code
            ? `Assigned to ${device.room_code}`
            : 'Not assigned to a room';
        credentialsDialog.querySelector('[data-scanner-credential-key]').textContent = credentials.api_key;
        const copyButton = credentialsDialog.querySelector('[data-copy-scanner-key]');
        copyButton.textContent = 'Copy key';
        copyButton.classList.remove('is-copied');
        credentialsDialog.querySelector('[data-copy-scanner-status]').textContent = '';
        if (!credentialsDialog.open) credentialsDialog.showModal();
        window.requestAnimationFrame(() => copyButton.focus());
    };

    const copyText = async (value) => {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(value);
            return;
        }
        const input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.append(input);
        input.select();
        const copied = document.execCommand('copy');
        input.remove();
        if (!copied) throw new Error('Copy was blocked. Select the key and copy it manually.');
    };

    credentialsDialog?.querySelector('[data-copy-scanner-key]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const status = credentialsDialog.querySelector('[data-copy-scanner-status]');
        button.disabled = true;
        try {
            await copyText(credentialsDialog.querySelector('[data-scanner-credential-key]').textContent);
            button.textContent = 'Copied';
            button.classList.add('is-copied');
            status.textContent = 'API key copied to clipboard.';
            status.className = 'ajax-form-status is-success';
        } catch (error) {
            status.textContent = error.message;
            status.className = 'ajax-form-status is-error';
        } finally {
            button.disabled = false;
        }
    });

    credentialsDialog?.addEventListener('close', () => {
        credentialsDialog.querySelector('[data-scanner-credential-key]').textContent = '';
        credentialsDialog.querySelector('[data-copy-scanner-status]').textContent = '';
    });

    if (credentialsDialog?.dataset.pending === '1') {
        credentialsDialog.showModal();
        window.requestAnimationFrame(() => credentialsDialog.querySelector('[data-copy-scanner-key]')?.focus());
    }

    const createDeviceForm = document.querySelector('[data-device-create-form]');
    createDeviceForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!createDeviceForm.reportValidity() || createDeviceForm.dataset.submitting === '1') return;
        const button = event.submitter || createDeviceForm.querySelector('[type="submit"], button');
        const errorBox = createDeviceForm.querySelector('[data-device-create-error]');
        const status = createDeviceForm.querySelector('[data-device-create-status]');
        createDeviceForm.dataset.submitting = '1';
        createDeviceForm.setAttribute('aria-busy', 'true');
        button.disabled = true;
        errorBox.hidden = true;
        errorBox.textContent = '';
        status.className = 'ajax-form-status';
        status.textContent = 'Creating scanner…';
        try {
            const payload = await requestJson(createDeviceForm.dataset.endpoint, { method: 'POST', body: new FormData(createDeviceForm) });
            createDeviceForm.reset();
            createDeviceForm.querySelector('select[name="room_id"]')?.dispatchEvent(new Event('change', { bubbles: true }));
            status.textContent = '';
            showScannerCredentials(payload.credentials, payload.device);
            try {
                await refreshFacilityDirectories();
            } catch {
                notify('The scanner was created, but the tables could not refresh. Reload the page after saving the API key.', 'error');
            }
        } catch (error) {
            errorBox.textContent = error.message;
            errorBox.hidden = false;
            status.textContent = 'Review the highlighted issue and try again.';
            status.className = 'ajax-form-status is-error';
            errorBox.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
        } finally {
            delete createDeviceForm.dataset.submitting;
            createDeviceForm.removeAttribute('aria-busy');
            button.disabled = false;
        }
    });

    document.addEventListener('click', async (event) => {
        const rotateButton = event.target.closest('[data-rotate-device-key]');
        if (rotateButton && !rotateButton.disabled) {
            const dialog = dialogs.device;
            const form = dialog?.querySelector('[data-facility-form]');
            if (!form?.elements.device_id.value) return;
            const confirmed = window.confirm('Generate a new API key? The scanner will stop working until its local configuration is updated.');
            if (!confirmed) return;
            const data = new FormData();
            data.set('_token', form.elements._token.value);
            data.set('action', 'rotate_device_key');
            data.set('device_id', form.elements.device_id.value);
            rotateButton.disabled = true;
            try {
                const payload = await requestJson(dialog.dataset.endpoint, { method: 'POST', body: data });
                dialog.close();
                showScannerCredentials(payload.credentials, payload.device);
            } catch (error) {
                notify(error.message, 'error');
            } finally {
                rotateButton.disabled = false;
            }
            return;
        }
        const button = event.target.closest('[data-delete-facility]');
        if (!button || button.disabled) return;
        const entity = button.dataset.deleteFacility;
        const dialog = dialogs[entity];
        const form = dialog?.querySelector('[data-facility-form]');
        if (!form) return;
        const isRoom = entity === 'room';
        const idField = isRoom ? 'room_id' : 'device_id';
        const codeField = isRoom ? 'room_code' : 'device_code';
        const recordId = form.elements[idField].value;
        const name = `${form.elements[codeField].value} — ${form.elements[isRoom ? 'room_name' : 'name'].value}`;
        const confirmed = window.confirmDestructiveAction
            ? await window.confirmDestructiveAction({
                title: `Delete ${isRoom ? 'room' : 'scanner'}?`,
                name,
                message: `Are you sure you want to permanently delete ${name}?`,
                warning: isRoom
                    ? 'The room can only be deleted after scanners and class schedules are reassigned. Its saved seat plan will also be permanently deleted.'
                    : 'The scanner registration will be removed. This does not delete its room.',
            })
            : window.confirm(`Are you sure you want to permanently delete ${name}?`);
        if (!confirmed) return;

        const data = new FormData();
        data.set('_token', form.elements._token.value);
        data.set('action', `delete_${entity}`);
        data.set(idField, recordId);
        button.disabled = true;
        try {
            const payload = await requestJson(dialog.dataset.endpoint, { method: 'POST', body: data });
            window.noteBulkDeleted?.(entity, [payload.deleted.id]);
            dialog.close();
            if (isRoom) {
                document.querySelectorAll(`select[name="room_id"] option[value="${recordId}"], [data-seat-plan-room] option[value="${recordId}"]`).forEach((option) => option.remove());
                document.querySelectorAll('select[name="room_id"]').forEach(refreshSearchableSelect);
            }
            await refreshFacilityDirectories();
            notify(payload.message);
        } catch (error) {
            form.querySelector('[data-facility-feedback]').textContent = error.message;
            if (!window.showDeletionConflict?.(error.payload)) notify(error.message, 'error');
        } finally {
            if (button.isConnected) button.disabled = false;
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-facility-status-form]');
        if (!form) return;
        event.preventDefault();
        if (form.dataset.submitting === '1') return;

        const action = form.elements.action?.value;
        const entity = action === 'toggle_room' ? 'room' : action === 'toggle_device' ? 'device' : '';
        if (!entity) return;
        const button = event.submitter || form.querySelector('button');
        form.dataset.submitting = '1';
        form.setAttribute('aria-busy', 'true');
        if (button) button.disabled = true;

        try {
            const payload = await requestJson(dialogs.room.dataset.endpoint, { method: 'POST', body: new FormData(form) });
            const record = payload[entity];
            syncStatus(entity, record);
            if (entity === 'room') syncDeviceRoomOption(record);
            notify(payload.message);
        } catch (error) {
            notify(error.message, 'error');
        } finally {
            delete form.dataset.submitting;
            form.removeAttribute('aria-busy');
            if (button?.isConnected) button.disabled = false;
        }
    });

    Object.entries(dialogs).forEach(([entity, dialog]) => {
        if (!dialog) return;
        const form = dialog.querySelector('[data-facility-form]');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!form.reportValidity() || form.dataset.submitting === '1') return;
            form.dataset.submitting = '1';
            const button = event.submitter || form.querySelector('[type="submit"]');
            const feedback = form.querySelector('[data-facility-feedback]');
            const oldRoomId = entity === 'device' ? form.elements.room_id.value : '';
            button.disabled = true;
            feedback.textContent = 'Saving…';
            try {
                const payload = await requestJson(dialog.dataset.endpoint, { method: 'POST', body: new FormData(form) });
                if (entity === 'room') syncRoom(payload.room);
                else syncDevice(payload.device, oldRoomId);
                dialog.close();
                notify(payload.message);
            } catch (error) {
                feedback.textContent = error.message;
                notify(error.message, 'error');
            } finally {
                delete form.dataset.submitting;
                button.disabled = false;
            }
        });
    });
});
