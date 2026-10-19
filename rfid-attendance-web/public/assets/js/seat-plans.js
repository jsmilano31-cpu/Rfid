(() => {
    'use strict';

    const icons = {
        student_seat: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 10V7a4 4 0 0 1 8 0v3M6 10h12v6H6zM8 16v4m8-4v4"/></svg>',
        teacher_table: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8h18v8H3zM6 16v4m12-4v4M8 4h8"/></svg>',
        label: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4zM8 9h8m-8 4h5"/></svg>',
    };
    const defaults = { student_seat: 'Student Seat', teacher_table: 'Teacher Table', label: 'Label' };
    const clamp = (value) => Math.max(1, Math.min(20, Number(value) || 10));
    const keyOf = (row, column) => `${row}:${column}`;

    document.querySelectorAll('[data-seat-plan-subject-browser]').forEach((browser) => {
        const input = browser.querySelector('[data-seat-plan-subject-search]');
        const results = browser.querySelector('[data-seat-plan-subject-results]');
        const empty = browser.querySelector('[data-seat-plan-subject-empty]');
        const cards = [...browser.querySelectorAll('[data-seat-plan-subject]')];
        browser.querySelectorAll('[data-seat-plan-subject-view]').forEach((button) => button.addEventListener('click', () => {
            const list = button.dataset.seatPlanSubjectView === 'list';
            results?.classList.toggle('is-list-view', list);
            browser.querySelectorAll('[data-seat-plan-subject-view]').forEach((item) => {
                const active = item === button;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }));
        input?.addEventListener('input', () => {
            const query = input.value.trim().toLowerCase();
            let shown = 0;
            cards.forEach((card) => {
                const visible = !query || (card.dataset.search || '').includes(query);
                card.hidden = !visible;
                if (visible) shown += 1;
            });
            if (empty) empty.hidden = shown > 0;
        });
    });

    const notify = (message, isError = false) => {
        const region = document.querySelector('[data-toast-region]') || document.body;
        const toast = document.createElement('div');
        toast.className = `app-toast${isError ? ' is-error' : ''}`;
        toast.setAttribute('role', isError ? 'alert' : 'status');
        toast.innerHTML = `<span class="app-toast-icon">${isError ? '!' : '✓'}</span><span class="app-toast-copy"><strong>${isError ? 'Couldn\'t save' : 'Saved'}</strong><span></span></span><button class="app-toast-close" type="button" aria-label="Dismiss">×</button>`;
        toast.querySelector('.app-toast-copy span').textContent = message;
        toast.querySelector('button').addEventListener('click', () => toast.remove());
        region.append(toast);
        window.setTimeout(() => toast.remove(), isError ? 8000 : 5000);
    };

    const requestJson = async (url, options = {}) => {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', ...(options.headers || {}) }, ...options });
        let payload;
        try { payload = await response.json(); } catch { throw new Error('The server returned an invalid seat-plan response.'); }
        if (!response.ok || !payload.ok) throw window.deletionConflictError?.(payload, 'The seat-plan request could not be completed.') || new Error(payload.message || 'The seat-plan request could not be completed.');
        return payload;
    };

    document.querySelectorAll('[data-seat-plan-editor]').forEach((editor) => {
        const endpoint = editor.dataset.endpoint;
        const content = editor.querySelector('[data-seat-plan-content]');
        const status = editor.querySelector('[data-seat-plan-status]');
        const roomSelect = editor.querySelector('[data-seat-plan-room]');
        const presetSelect = editor.querySelector('[data-seat-plan-preset]');
        const applyPresetButton = editor.querySelector('[data-seat-plan-apply-preset]');
        const managePresetButton = editor.querySelector('[data-seat-plan-manage-preset]');
        const nameInput = editor.querySelector('[data-seat-plan-name]');
        const rowsInput = editor.querySelector('[data-seat-plan-rows]');
        const columnsInput = editor.querySelector('[data-seat-plan-columns]');
        const labelInput = editor.querySelector('[data-seat-plan-label]');
        const grid = editor.querySelector('[data-seat-plan-grid]');
        const summary = editor.querySelector('[data-seat-plan-summary]');
        const saveButton = editor.querySelector('[data-seat-plan-save]');
        const newButton = editor.querySelector('[data-seat-plan-new]');
        const presetDialog = editor.nextElementSibling?.matches('[data-seat-plan-preset-dialog]') ? editor.nextElementSibling : editor.parentElement.querySelector('[data-seat-plan-preset-dialog]');
        const presetForm = presetDialog?.querySelector('[data-seat-plan-preset-form]');
        const managePresetDialog = editor.parentElement.querySelector('[data-seat-plan-manage-preset-dialog]');
        const managePresetForm = managePresetDialog?.querySelector('[data-seat-plan-manage-preset-form]');
        if (!content || !roomSelect || !presetSelect || !grid || !presetDialog || !presetForm) return;

        let rows = 10;
        let columns = 10;
        let tool = 'student_seat';
        let basedOnPresetId = null;
        let cells = new Map();
        let dragging = false;
        let visited = new Set();
        let canManageCampusPresets = false;
        const presets = new Map();
        const presetDirectory = editor.querySelector('[data-seat-plan-preset-directory]');
        const renderPresetDirectory = () => {
            if (!presetDirectory) return;
            presetDirectory.querySelectorAll('[data-bulk-item]').forEach(row => row.remove());
            presets.forEach(preset => {
                if (Number(preset.created_by) !== Number(editor.dataset.actorId) && !(canManageCampusPresets && preset.visibility === 'campus')) return;
                const row = document.createElement('div'); row.className = 'bulk-preset-row'; row.dataset.bulkItem = ''; row.dataset.bulkId = String(preset.id); row.dataset.bulkLabel = preset.name;
                const copy = document.createElement('span'), title = document.createElement('strong'), detail = document.createElement('small');
                title.textContent = preset.name; detail.textContent = `${preset.row_count} × ${preset.column_count} · ${preset.visibility} · ${preset.creator_name}`;
                copy.append(title, detail); row.append(copy); presetDirectory.append(row);
            });
        };
        document.addEventListener('bulk-delete:complete', event => {
            if (event.detail.type !== 'preset') return;
            event.detail.ids.forEach(id => { presets.delete(Number(id)); presetSelect.querySelector(`option[value="${id}"]`)?.remove(); if (Number(basedOnPresetId) === Number(id)) basedOnPresetId = null; });
            window.refreshSearchableSelect?.(presetSelect);
            managePresetButton.disabled = !presetSelect.value;
            applyPresetButton.disabled = !presetSelect.value;
            renderPresetDirectory();
        });

        const serializedCells = () => [...cells.values()].map((cell) => ({ row: cell.row, column: cell.column, type: cell.type, label: cell.label }));
        const updateSummary = () => {
            const counts = { student_seat: 0, teacher_table: 0, label: 0 };
            cells.forEach((cell) => { counts[cell.type] += 1; });
            summary.textContent = `${cells.size} active cell${cells.size === 1 ? '' : 's'} · ${counts.student_seat} student seat${counts.student_seat === 1 ? '' : 's'} · ${rows} × ${columns} grid`;
        };
        const cellCopy = (cell) => {
            if (!cell) return '';
            const custom = cell.label && cell.label !== defaults[cell.type] ? `<small></small>` : '';
            return `${icons[cell.type]}${custom}`;
        };
        const updateCellButton = (button) => {
            const key = keyOf(button.dataset.row, button.dataset.column);
            const cell = cells.get(key);
            button.className = `seat-plan-cell${cell ? ` is-active is-${cell.type.replace('_', '-')}` : ''}`;
            button.innerHTML = cellCopy(cell);
            const custom = button.querySelector('small');
            if (custom) custom.textContent = cell.label;
            button.title = cell ? `${cell.label} · Click or drag to remove` : `Open space · Paint ${labelInput.value || defaults[tool]}`;
            button.setAttribute('aria-label', `Row ${button.dataset.row}, column ${button.dataset.column}: ${cell ? cell.label : 'open space'}`);
            button.setAttribute('aria-pressed', cell ? 'true' : 'false');
        };
        const toggleCell = (button) => {
            const row = Number(button.dataset.row);
            const column = Number(button.dataset.column);
            const key = keyOf(row, column);
            if (visited.has(key)) return;
            visited.add(key);
            if (cells.has(key)) cells.delete(key);
            else cells.set(key, { row, column, type: tool, label: labelInput.value.trim() || defaults[tool] });
            updateCellButton(button);
            updateSummary();
        };
        const focusCell = (row, column) => grid.querySelector(`[data-row="${Math.max(1, Math.min(rows, row))}"][data-column="${Math.max(1, Math.min(columns, column))}"]`)?.focus();
        const renderGrid = () => {
            rows = clamp(rowsInput.value);
            columns = clamp(columnsInput.value);
            rowsInput.value = rows;
            columnsInput.value = columns;
            cells.forEach((cell, key) => { if (cell.row > rows || cell.column > columns) cells.delete(key); });
            grid.replaceChildren();
            grid.style.setProperty('--seat-plan-columns', String(columns));
            for (let row = 1; row <= rows; row += 1) {
                for (let column = 1; column <= columns; column += 1) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.dataset.row = String(row);
                    button.dataset.column = String(column);
                    button.setAttribute('role', 'gridcell');
                    updateCellButton(button);
                    button.addEventListener('pointerdown', (event) => {
                        if (event.button !== 0) return;
                        event.preventDefault();
                        dragging = true;
                        visited = new Set();
                        toggleCell(button);
                    });
                    button.addEventListener('pointerenter', () => { if (dragging) toggleCell(button); });
                    button.addEventListener('keydown', (event) => {
                        if (event.key === ' ' || event.key === 'Enter') {
                            event.preventDefault();
                            visited = new Set();
                            toggleCell(button);
                            return;
                        }
                        const moves = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] };
                        if (moves[event.key]) {
                            event.preventDefault();
                            focusCell(row + moves[event.key][0], column + moves[event.key][1]);
                        }
                    });
                    grid.append(button);
                }
            }
            updateSummary();
        };
        const hydrateCells = (items) => {
            cells = new Map((items || []).map((cell) => {
                const normalized = {
                    row: Number(cell.row ?? cell.grid_row),
                    column: Number(cell.column ?? cell.grid_column),
                    type: cell.type ?? cell.cell_type,
                    label: cell.label,
                };
                return [keyOf(normalized.row, normalized.column), normalized];
            }));
        };
        const setStatus = (copy, type = '') => {
            status.className = `seat-plan-status${type ? ` is-${type}` : ''}`;
            status.textContent = copy;
        };
        const loadRoom = async () => {
            if (!roomSelect.value) return;
            setStatus('Loading room layout…');
            const url = new URL(endpoint, location.origin);
            url.searchParams.set('mode', 'room');
            url.searchParams.set('room_id', roomSelect.value);
            try {
                const payload = await requestJson(url);
                nameInput.value = payload.plan.name;
                rowsInput.value = payload.plan.row_count;
                columnsInput.value = payload.plan.column_count;
                basedOnPresetId = payload.plan.based_on_preset_id || null;
                hydrateCells(payload.cells);
                renderGrid();
                setStatus(payload.plan.id ? `Loaded ${payload.plan.name}. Last updated by ${payload.plan.updated_by_name}.` : 'No saved layout yet. A blank 10 × 10 plan is ready.', 'ready');
            } catch (error) { setStatus(error.message, 'error'); notify(error.message, true); }
        };
        const addPresetOption = (preset, refresh = true) => {
            if (refresh) {
                presetSelect._choicesInstance?.destroy();
                presetSelect._choicesInstance = null;
            }
            presets.set(Number(preset.id), preset);
            let option = [...presetSelect.options].find((item) => Number(item.value) === Number(preset.id));
            if (!option) { option = document.createElement('option'); presetSelect.add(option); }
            option.value = preset.id;
            option.textContent = `${preset.name} · ${preset.row_count}×${preset.column_count}${preset.visibility === 'personal' ? ' · Mine' : ''}`;
            renderPresetDirectory();
            if (refresh) window.initializeSearchableSelect?.(presetSelect);
        };
        const loadBootstrap = async () => {
            const url = new URL(endpoint, location.origin);
            url.searchParams.set('mode', 'bootstrap');
            try {
                const payload = await requestJson(url);
                canManageCampusPresets = Boolean(payload.can_create_campus_presets);
                editor.dataset.actorId = String(payload.actor_id);
                roomSelect._choicesInstance?.destroy();
                roomSelect._choicesInstance = null;
                presetSelect._choicesInstance?.destroy();
                presetSelect._choicesInstance = null;
                payload.rooms.forEach((room) => roomSelect.add(new Option(room.label, room.id)));
                payload.presets.forEach((preset) => addPresetOption(preset, false));
                const visibilityField = presetForm.querySelector('[data-seat-plan-visibility-field]');
                const personalNote = presetForm.querySelector('[data-seat-plan-personal-note]');
                if (!payload.can_create_campus_presets) {
                    visibilityField.hidden = true;
                    presetForm.elements.visibility.value = 'personal';
                    personalNote.hidden = false;
                }
                if (!payload.rooms.length) {
                    setStatus('No active rooms are assigned to your classes yet.', 'empty');
                    saveButton.disabled = true;
                    newButton.disabled = true;
                    return;
                }
                const requestedRoomId = new URLSearchParams(window.location.search).get('room_id');
                const requestedRoomExists = payload.rooms.some((room) => String(room.id) === requestedRoomId);
                roomSelect.value = requestedRoomExists ? requestedRoomId : String(payload.rooms[0].id);
                window.initializeSearchableSelect?.(roomSelect);
                window.initializeSearchableSelect?.(presetSelect);
                roomSelect._choicesInstance?.setChoiceByValue(roomSelect.value);
                await loadRoom();
                content.hidden = false;
                const requestedPresetId = Number(new URLSearchParams(location.search).get('focus_preset'));
                if (requestedPresetId > 0 && presets.has(requestedPresetId)) {
                    presetSelect.value = String(requestedPresetId);
                    presetSelect._choicesInstance?.setChoiceByValue(presetSelect.value);
                    presetSelect.dispatchEvent(new Event('change', {bubbles:true}));
                    presetSelect.scrollIntoView({behavior:'smooth',block:'center'});
                    if (!managePresetButton.disabled) managePresetButton.click();
                    else presetSelect.focus();
                }
            } catch (error) { setStatus(error.message, 'error'); notify(error.message, true); }
        };

        document.addEventListener('pointerup', () => { dragging = false; visited.clear(); });
        document.addEventListener('pointercancel', () => { dragging = false; visited.clear(); });
        editor.querySelectorAll('[data-seat-plan-tool]').forEach((button) => button.addEventListener('click', () => {
            tool = button.dataset.seatPlanTool;
            editor.querySelectorAll('[data-seat-plan-tool]').forEach((item) => {
                const active = item === button;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            labelInput.value = defaults[tool];
            grid.querySelectorAll('.seat-plan-cell:not(.is-active)').forEach(updateCellButton);
        }));
        [rowsInput, columnsInput].forEach((input) => input.addEventListener('change', renderGrid));
        labelInput.addEventListener('input', () => grid.querySelectorAll('.seat-plan-cell:not(.is-active)').forEach(updateCellButton));
        roomSelect.addEventListener('change', loadRoom);
        presetSelect.addEventListener('change', () => {
            applyPresetButton.disabled = !presetSelect.value;
            const preset = presets.get(Number(presetSelect.value));
            managePresetButton.disabled = !preset || (preset.visibility === 'campus' && !canManageCampusPresets);
        });
        applyPresetButton.addEventListener('click', async () => {
            const url = new URL(endpoint, location.origin);
            url.searchParams.set('mode', 'preset');
            url.searchParams.set('preset_id', presetSelect.value);
            setStatus('Applying preset…');
            try {
                const payload = await requestJson(url);
                rowsInput.value = payload.preset.row_count;
                columnsInput.value = payload.preset.column_count;
                basedOnPresetId = payload.preset.id;
                hydrateCells(payload.cells);
                renderGrid();
                setStatus(`${payload.preset.name} applied. Save the room plan when it looks right.`, 'ready');
            } catch (error) { setStatus(error.message, 'error'); notify(error.message, true); }
        });
        newButton.addEventListener('click', () => {
            if (cells.size && !window.confirm('Start a blank plan? Unsaved grid changes will be cleared.')) return;
            rowsInput.value = 10;
            columnsInput.value = 10;
            nameInput.value = `${roomSelect.selectedOptions[0]?.textContent.split(' — ')[0] || 'Room'} Seat Plan`;
            basedOnPresetId = null;
            presetSelect.value = '';
            applyPresetButton.disabled = true;
            cells.clear();
            renderGrid();
            setStatus('Blank 10 × 10 grid ready.', 'ready');
        });
        saveButton.addEventListener('click', async () => {
            if (!roomSelect.value) { notify('Select a room first.', true); return; }
            const data = new FormData();
            data.set('_token', presetForm.elements._token.value);
            data.set('action', 'save_room_plan');
            data.set('room_id', roomSelect.value);
            data.set('name', nameInput.value);
            data.set('row_count', String(rows));
            data.set('column_count', String(columns));
            data.set('based_on_preset_id', basedOnPresetId || '');
            data.set('cells', JSON.stringify(serializedCells()));
            saveButton.disabled = true;
            setStatus('Saving room plan…');
            try {
                const payload = await requestJson(endpoint, { method: 'POST', body: data });
                setStatus(`Saved ${payload.plan.name}.`, 'ready');
                notify(payload.message);
            } catch (error) { setStatus(error.message, 'error'); notify(error.message, true); }
            finally { saveButton.disabled = false; }
        });
        presetForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!presetForm.reportValidity()) return;
            const button = event.submitter;
            const feedback = presetForm.querySelector('[data-seat-plan-preset-feedback]');
            const data = new FormData();
            data.set('_token', presetForm.elements._token.value);
            data.set('action', 'save_preset');
            data.set('name', presetForm.elements.preset_name.value);
            data.set('visibility', presetForm.elements.visibility.value);
            data.set('row_count', String(rows));
            data.set('column_count', String(columns));
            data.set('cells', JSON.stringify(serializedCells()));
            button.disabled = true;
            feedback.textContent = 'Creating preset…';
            feedback.classList.remove('is-error');
            try {
                const payload = await requestJson(endpoint, { method: 'POST', body: data });
                addPresetOption(payload.preset);
                presetSelect.value = String(payload.preset.id);
                presetSelect._choicesInstance?.setChoiceByValue(presetSelect.value);
                applyPresetButton.disabled = false;
                presetDialog.close();
                presetForm.reset();
                notify(payload.message);
            } catch (error) { feedback.textContent = error.message; feedback.classList.add('is-error'); notify(error.message, true); }
            finally { button.disabled = false; }
        });
        managePresetButton.addEventListener('click', async () => {
            const presetId = Number(presetSelect.value);
            if (!presetId || !managePresetDialog || !managePresetForm) return;
            managePresetForm.querySelector('[data-seat-plan-manage-feedback]').textContent = 'Loading preset…';
            managePresetDialog.showModal();
            try {
                const url = new URL(endpoint, location.origin);
                url.searchParams.set('mode', 'preset');
                url.searchParams.set('preset_id', String(presetId));
                const payload = await requestJson(url);
                managePresetForm.elements.preset_id.value = payload.preset.id;
                managePresetForm.elements.name.value = payload.preset.name;
                managePresetForm.elements.visibility.value = payload.preset.visibility;
                managePresetForm.querySelector('[data-seat-plan-manage-visibility]').hidden = !canManageCampusPresets;
                managePresetForm.querySelector('[data-seat-plan-manage-owner]').textContent = `Created by ${payload.preset.creator_name}. ${payload.preset.row_count}×${payload.preset.column_count} grid.`;
                managePresetForm.querySelector('[data-seat-plan-manage-feedback]').textContent = '';
                managePresetForm.elements.name.focus();
            } catch (error) {
                managePresetForm.querySelector('[data-seat-plan-manage-feedback]').textContent = error.message;
            }
        });
        managePresetForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!managePresetForm.reportValidity()) return;
            const button = event.submitter;
            const feedback = managePresetForm.querySelector('[data-seat-plan-manage-feedback]');
            const data = new FormData(managePresetForm);
            data.set('action', 'update_preset');
            button.disabled = true;
            feedback.textContent = 'Saving preset…';
            try {
                const payload = await requestJson(endpoint, { method: 'POST', body: data });
                addPresetOption(payload.preset);
                presetSelect.value = String(payload.preset.id);
                presetSelect._choicesInstance?.setChoiceByValue(presetSelect.value);
                managePresetDialog.close();
                notify(payload.message);
            } catch (error) { feedback.textContent = error.message; if (!window.showDeletionConflict?.(error.payload)) notify(error.message, true); }
            finally { button.disabled = false; }
        });
        managePresetForm?.querySelector('[data-seat-plan-delete-preset]')?.addEventListener('click', async (event) => {
            const presetName = managePresetForm.elements.name.value.trim();
            const confirmed = window.confirmDestructiveAction
                ? await window.confirmDestructiveAction({ title: 'Delete seat-plan preset?', name: presetName, message: `Are you sure you want to permanently delete ${presetName}?`, warning: 'Existing room seat plans remain saved; only this reusable preset is removed.' })
                : window.confirm(`Are you sure you want to permanently delete ${presetName}?`);
            if (!confirmed) return;
            const button = event.currentTarget;
            const feedback = managePresetForm.querySelector('[data-seat-plan-manage-feedback]');
            const data = new FormData();
            data.set('_token', managePresetForm.elements._token.value);
            data.set('action', 'delete_preset');
            data.set('preset_id', managePresetForm.elements.preset_id.value);
            button.disabled = true;
            feedback.textContent = 'Deleting preset…';
            try {
                const payload = await requestJson(endpoint, { method: 'POST', body: data });
                presets.delete(Number(payload.preset_id));
                window.noteBulkDeleted?.('preset', [payload.preset_id]);
                renderPresetDirectory();
                presetSelect.querySelector(`option[value="${payload.preset_id}"]`)?.remove();
                presetSelect.value = '';
                window.refreshSearchableSelect?.(presetSelect);
                applyPresetButton.disabled = true;
                managePresetButton.disabled = true;
                managePresetDialog.close();
                notify(payload.message);
            } catch (error) { feedback.textContent = error.message; if (!window.showDeletionConflict?.(error.payload)) notify(error.message, true); }
            finally { button.disabled = false; }
        });

        renderGrid();
        loadBootstrap();
    });
})();
