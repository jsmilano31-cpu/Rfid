(() => {
    'use strict';

    const requestJson = async (url, options = {}) => {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, ...options });
        let payload;
        try { payload = await response.json(); } catch { throw new Error('The server returned an invalid classroom response.'); }
        if (!response.ok || !payload.ok) throw new Error(payload.message || 'The classroom could not be loaded.');
        return payload;
    };
    const node = (tag, className = '', text = '') => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== '') element.textContent = text;
        return element;
    };
    const formatTime = (value) => {
        if (!value) return 'TBA';
        const [hour, minute] = String(value).slice(0, 5).split(':').map(Number);
        return `${hour % 12 || 12}:${String(minute).padStart(2, '0')} ${hour < 12 ? 'AM' : 'PM'}`;
    };
    const toast = (message, error = false) => {
        const region = document.querySelector('[data-toast-region]') || document.body;
        const item = node('div', `app-toast${error ? ' is-error' : ''}`);
        item.setAttribute('role', error ? 'alert' : 'status');
        const copy = node('span', 'app-toast-copy'); copy.append(node('strong', '', error ? 'Couldn\'t update seat' : 'Seat plan updated'), node('span', '', message));
        const close = node('button', 'app-toast-close', '×'); close.type = 'button'; close.setAttribute('aria-label', 'Dismiss'); close.addEventListener('click', () => item.remove());
        item.append(node('span', 'app-toast-icon', error ? '!' : '✓'), copy, close); region.append(item);
        window.setTimeout(() => item.remove(), error ? 8000 : 4500);
    };

    document.querySelectorAll('[data-live-seat-plan]').forEach((host) => {
        const endpoint = host.dataset.endpoint;
        const mode = host.dataset.liveSeatPlanMode;
        const body = host.querySelector('[data-live-seat-body]');
        const title = host.querySelector('[data-live-seat-title]');
        const description = host.querySelector('[data-live-seat-description]');
        const eyebrow = host.querySelector('[data-live-seat-eyebrow]');
        const actions = host.querySelector('[data-live-seat-actions]');
        const count = host.querySelector('[data-live-seat-count]');
        const configureButton = host.querySelector('[data-live-seat-configure-assignments]');
        const footer = host.querySelector('[data-live-seat-footer]');
        const updated = host.querySelector('[data-live-seat-updated]');
        const dialog = host.nextElementSibling?.matches('[data-live-seat-assignment-dialog]') ? host.nextElementSibling : null;
        const form = dialog?.querySelector('[data-live-seat-assignment-form]');
        const search = dialog?.querySelector('[data-live-seat-student-search]');
        const studentResults = dialog?.querySelector('[data-live-seat-student-results]');
        const assignButton = dialog?.querySelector('[data-live-seat-assign]');
        const unassignButton = dialog?.querySelector('[data-live-seat-unassign]');
        const feedback = dialog?.querySelector('[data-live-seat-feedback]');
        let offeringId = Number(host.dataset.offeringId || 0);
        let attendanceDate = host.dataset.attendanceDate || new Date().toISOString().slice(0, 10);
        let state = null;
        let fingerprint = '';
        let configuring = false;
        let timer = null;
        let controller = null;
        let studentController = null;
        let searchTimer = null;
        let canvasObserver = null;
        let suppressSeatClick = false;

        const updateTimestamp = () => {
            if (!updated) return;
            updated.textContent = `Updated ${new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' })}`;
        };
        const classDescription = (classInfo) => {
            const parts = [classInfo.section_name, classInfo.room_code ? `${classInfo.room_code}${classInfo.room_name ? ` · ${classInfo.room_name}` : ''}` : 'Room TBA'];
            if (classInfo.start_time) parts.push(`${formatTime(classInfo.start_time)}–${formatTime(classInfo.end_time)}`);
            return parts.join(' · ');
        };
        const renderNotice = (heading, copy, actionLabel = '', actionUrl = '') => {
            canvasObserver?.disconnect(); canvasObserver = null;
            body.replaceChildren();
            const notice = node('div', 'live-seat-plan-notice');
            const icon = node('span', 'live-seat-plan-notice-icon');
            icon.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4zM8 9h8M8 13h5"/></svg>';
            const text = node('div'); text.append(node('strong', '', heading), node('p', '', copy)); notice.append(icon, text);
            if (actionLabel && actionUrl) { const link = node('a', 'button button-primary button-small', actionLabel); link.href = actionUrl; notice.append(link); }
            body.append(notice);
            actions.hidden = true; footer.hidden = true;
        };
        const renderNext = (payload) => {
            canvasObserver?.disconnect(); canvasObserver = null;
            const classInfo = payload.class;
            eyebrow.textContent = 'Next class';
            title.textContent = `${classInfo.course_code} — ${classInfo.course_title}`;
            description.textContent = classDescription(classInfo);
            body.replaceChildren();
            const starts = new Date(classInfo.starts_at);
            const next = node('div', 'live-seat-plan-next');
            const date = node('time', 'live-seat-plan-next-date'); date.dateTime = classInfo.starts_at;
            date.append(node('span', '', starts.toLocaleDateString([], { weekday: 'short' }).toUpperCase()), node('strong', '', starts.toLocaleDateString([], { day: 'numeric' })), node('small', '', starts.toLocaleDateString([], { month: 'short' })));
            const details = node('div'); details.append(node('strong', '', `Next class starts at ${formatTime(classInfo.start_time)}`), node('p', '', `${classInfo.program_code} · ${classInfo.section_name} · ${classInfo.room_code || 'Room TBA'}`));
            const link = node('a', 'button button-secondary button-small', 'View schedule'); link.href = '/index/?page=schedule';
            next.append(date, details, link); body.append(next); actions.hidden = true; footer.hidden = true;
        };
        const cellContent = (cell) => {
            const content = node('span', 'live-seat-cell-content');
            if (cell.cell_type !== 'student_seat') {
                content.innerHTML = cell.cell_type === 'teacher_table'
                    ? '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 7h14v7H3zM6 14v3M14 14v3M6 4h8v3"/></svg>'
                    : '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 4h12v12H4zM7 8h6M7 11h4"/></svg>';
                content.append(node('small', '', cell.label));
                return content;
            }
            if (!cell.student_id) {
                content.innerHTML = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 16v-1.5A3.5 3.5 0 0 1 8.5 11h3a3.5 3.5 0 0 1 3.5 3.5V16M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/></svg>';
                content.append(node('small', '', 'Open seat'));
                return content;
            }
            if (cell.profile_image_path) { const avatar = document.createElement('img'); avatar.src = cell.profile_image_path; avatar.alt = ''; content.append(avatar); }
            else content.append(node('span', 'live-seat-initials', cell.student_name.split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase()));
            content.append(node('small', '', cell.student_name));
            return content;
        };
        const setupCanvasInteraction = (shell, viewport, stage, grid, zoomLabel, updatePanHint) => {
            let scale = 1;
            let fitScale = 1;
            let x = 0;
            let y = 0;
            let panning = false;
            let moved = false;
            let pointerStartX = 0;
            let pointerStartY = 0;
            let originX = 0;
            let originY = 0;

            const apply = () => {
                stage.style.transform = `translate3d(${x}px, ${y}px, 0) scale(${scale})`;
                stage.style.setProperty('--live-seat-tooltip-scale', String(1 / scale));
                zoomLabel.textContent = `${Math.round(scale * 100)}%`;
            };
            const center = (nextScale = scale) => {
                scale = Math.max(.25, Math.min(2, nextScale));
                x = (viewport.clientWidth - grid.offsetWidth * scale) / 2;
                y = (viewport.clientHeight - grid.offsetHeight * scale) / 2;
                apply();
            };
            const fit = () => {
                const availableWidth = body.clientWidth || host.clientWidth || 320;
                const desktop = window.matchMedia('(min-width: 1024px)').matches;
                const maximumHeight = desktop ? availableWidth * 9 / 16 : 540;
                fitScale = Math.max(.25, Math.min(1, (availableWidth - 24) / grid.offsetWidth, (maximumHeight - 24) / grid.offsetHeight));
                const canvasWidth = desktop ? availableWidth : Math.min(availableWidth, Math.max(Math.min(300, availableWidth), grid.offsetWidth * fitScale + 24));
                const canvasHeight = desktop ? canvasWidth * 9 / 16 : Math.min(540, Math.max(180, grid.offsetHeight * fitScale + 24));
                shell.style.width = `${canvasWidth}px`;
                viewport.style.height = `${canvasHeight}px`;
                fitScale = Math.max(.25, Math.min(fitScale, (viewport.clientWidth - 24) / grid.offsetWidth, (viewport.clientHeight - 24) / grid.offsetHeight));
                center(fitScale);
                updatePanHint();
            };
            const zoom = (delta) => {
                const oldScale = scale;
                const nextScale = Math.max(.25, Math.min(2, scale + delta));
                if (nextScale === oldScale) return;
                const centerX = viewport.clientWidth / 2;
                const centerY = viewport.clientHeight / 2;
                const worldX = (centerX - x) / oldScale;
                const worldY = (centerY - y) / oldScale;
                scale = nextScale;
                x = centerX - worldX * scale;
                y = centerY - worldY * scale;
                apply();
            };

            shell.querySelector('[data-live-seat-zoom-in]').addEventListener('click', () => zoom(.15));
            shell.querySelector('[data-live-seat-zoom-out]').addEventListener('click', () => zoom(-.15));
            shell.querySelector('[data-live-seat-zoom-fit]').addEventListener('click', fit);
            viewport.addEventListener('wheel', (event) => {
                if (!event.ctrlKey && !event.metaKey) return;
                event.preventDefault();
                zoom(event.deltaY < 0 ? .1 : -.1);
            }, { passive: false });
            viewport.addEventListener('pointerdown', (event) => {
                if (event.button !== 0) return;
                panning = true; moved = false; pointerStartX = event.clientX; pointerStartY = event.clientY; originX = x; originY = y;
            });
            viewport.addEventListener('pointermove', (event) => {
                if (!panning) return;
                const deltaX = event.clientX - pointerStartX;
                const deltaY = event.clientY - pointerStartY;
                if (!moved && Math.hypot(deltaX, deltaY) > 4) {
                    moved = true;
                    viewport.setPointerCapture(event.pointerId);
                    viewport.classList.add('is-panning');
                }
                if (!moved) return;
                x = originX + deltaX; y = originY + deltaY; apply();
            });
            const endPan = (event) => {
                if (!panning) return;
                panning = false; viewport.classList.remove('is-panning');
                if (viewport.hasPointerCapture(event.pointerId)) viewport.releasePointerCapture(event.pointerId);
                if (moved) { suppressSeatClick = true; window.setTimeout(() => { suppressSeatClick = false; }, 0); }
            };
            viewport.addEventListener('pointerup', endPan);
            viewport.addEventListener('pointercancel', endPan);
            window.requestAnimationFrame(fit);
            canvasObserver?.disconnect();
            canvasObserver = new ResizeObserver(fit);
            canvasObserver.observe(body);
        };
        const renderPlan = (payload) => {
            const classInfo = payload.class;
            const plan = payload.plan;
            eyebrow.textContent = payload.mode === 'active' ? 'Live class · In session' : 'Class seat plan';
            title.textContent = `${classInfo.course_code} — ${classInfo.course_title}`;
            description.textContent = classDescription(classInfo);
            count.textContent = `${plan.checked_in_count} of ${plan.assigned_count} checked in`;
            actions.hidden = false; footer.hidden = false;
            configureButton.textContent = configuring ? 'Done assigning' : 'Assign seats';
            configureButton.classList.toggle('button-primary', configuring);
            configureButton.classList.toggle('button-secondary', !configuring);
            body.replaceChildren();
            const shell = node('div', 'live-seat-plan-canvas');
            const toolbar = node('div', 'live-seat-plan-toolbar');
            const panHint = node('span', 'live-seat-plan-pan-hint');
            const panHintIcon = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 2v16M2 10h16M10 2 7.5 4.5M10 2l2.5 2.5M18 10l-2.5-2.5M18 10l-2.5 2.5M10 18l-2.5-2.5M10 18l2.5-2.5M2 10l2.5-2.5M2 10l2.5 2.5"/></svg>';
            const updatePanHint = () => { panHint.innerHTML = panHintIcon + (window.matchMedia('(pointer: coarse)').matches || window.innerWidth < 720 ? 'Drag to pan · Use + / − to zoom' : 'Drag to pan · Ctrl + wheel to zoom'); };
            updatePanHint();
            const zoomControls = node('span', 'live-seat-zoom-controls');
            const zoomOut = node('button', '', '−'); zoomOut.type = 'button'; zoomOut.dataset.liveSeatZoomOut = ''; zoomOut.title = 'Zoom out'; zoomOut.setAttribute('aria-label', 'Zoom out');
            const zoomLabel = node('output', '', '100%'); zoomLabel.setAttribute('aria-live', 'polite');
            const zoomIn = node('button', '', '+'); zoomIn.type = 'button'; zoomIn.dataset.liveSeatZoomIn = ''; zoomIn.title = 'Zoom in'; zoomIn.setAttribute('aria-label', 'Zoom in');
            const zoomFit = node('button', 'live-seat-zoom-fit'); zoomFit.type = 'button'; zoomFit.dataset.liveSeatZoomFit = ''; zoomFit.title = 'Center and fit'; zoomFit.setAttribute('aria-label', 'Center and fit'); zoomFit.innerHTML = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 3H3v4M13 3h4v4M7 17H3v-4M13 17h4v-4"/></svg>';
            zoomControls.append(zoomOut, zoomLabel, zoomIn, zoomFit); toolbar.append(panHint, zoomControls);
            const front = node('div', 'live-seat-plan-front', 'Front of room');
            const scroll = node('div', 'live-seat-plan-scroll');
            scroll.setAttribute('aria-label', 'Pannable classroom seat plan');
            scroll.tabIndex = 0;
            const stage = node('div', 'live-seat-plan-stage');
            const grid = node('div', `live-seat-plan-grid${configuring ? ' is-configuring' : ''}`);
            const bounds = plan.cells.reduce((result, cell) => ({
                minRow: Math.min(result.minRow, cell.grid_row), maxRow: Math.max(result.maxRow, cell.grid_row),
                minColumn: Math.min(result.minColumn, cell.grid_column), maxColumn: Math.max(result.maxColumn, cell.grid_column),
            }), { minRow: Number.POSITIVE_INFINITY, maxRow: 0, minColumn: Number.POSITIVE_INFINITY, maxColumn: 0 });
            if (!Number.isFinite(bounds.minRow)) Object.assign(bounds, { minRow: 1, maxRow: 1, minColumn: 1, maxColumn: 1 });
            grid.style.setProperty('--live-seat-rows', bounds.maxRow - bounds.minRow + 1);
            grid.style.setProperty('--live-seat-columns', bounds.maxColumn - bounds.minColumn + 1);
            plan.cells.forEach((cell) => {
                const status = cell.attendance_status ? String(cell.attendance_status).toLowerCase() : '';
                const button = node('button', `live-seat-cell is-${cell.cell_type.replaceAll('_', '-')}${cell.student_id ? ' is-assigned' : ' is-unassigned'}${cell.checked_in ? ' is-checked-in' : ''}${status ? ` is-${status}` : ''}`);
                button.type = 'button'; button.style.gridRow = cell.grid_row - bounds.minRow + 1; button.style.gridColumn = cell.grid_column - bounds.minColumn + 1;
                button.dataset.row = cell.grid_row; button.dataset.column = cell.grid_column; button.dataset.studentId = cell.student_id || '';
                button.tabIndex = configuring && cell.cell_type === 'student_seat' ? 0 : (cell.student_id ? 0 : -1);
                button.setAttribute('aria-disabled', cell.cell_type !== 'student_seat' || !configuring ? 'true' : 'false');
                const clockedIn = cell.time_in ? ` · Clocked in ${formatTime(cell.time_in)}` : '';
                const seatDescription = cell.student_id ? `${cell.student_name}${cell.attendance_status ? ` · ${cell.attendance_status}${clockedIn}` : ' · Awaiting scan'}` : cell.label;
                if (!cell.student_id) button.title = cell.label;
                button.setAttribute('aria-label', `Row ${cell.grid_row}, column ${cell.grid_column}: ${seatDescription}`);
                button.append(cellContent(cell));
                if (cell.attendance_status) button.append(node('span', 'live-seat-status-mark', cell.attendance_status === 'Late' ? 'L' : cell.attendance_status === 'Present' ? '✓' : '!'));
                if (cell.student_id) {
                    const tooltip = node('span', 'live-seat-tooltip');
                    tooltip.append(node('strong', '', cell.student_name), node('span', '', `${cell.school_id} · ${cell.attendance_status || 'Awaiting scan'}`), node('small', '', cell.time_in ? `Clocked in at ${formatTime(cell.time_in)}` : 'No clock-in recorded'));
                    button.append(tooltip);
                }
                grid.append(button);
            });
            stage.append(grid); scroll.append(stage); shell.append(toolbar, front, scroll); body.append(shell); setupCanvasInteraction(shell, scroll, stage, grid, zoomLabel, updatePanHint); updateTimestamp();
        };
        const render = (payload) => {
            state = payload;
            host.classList.toggle('is-live', payload.mode === 'active');
            if (payload.mode === 'next') return renderNext(payload);
            if (payload.mode === 'empty') {
                eyebrow.textContent = mode === 'dashboard' ? 'Teaching schedule' : 'Live classroom';
                title.textContent = mode === 'dashboard' ? 'No upcoming class' : 'Select a subject below';
                description.textContent = payload.message;
                return renderNotice(mode === 'dashboard' ? 'You are all caught up' : 'Open a class roster', payload.message);
            }
            if (!payload.plan) {
                eyebrow.textContent = payload.mode === 'active' ? 'Live class · Setup needed' : 'Class seat plan';
                title.textContent = `${payload.class.course_code} — ${payload.class.course_title}`;
                description.textContent = classDescription(payload.class);
                const label = payload.reason === 'no_room' ? (host.dataset.roomUrl ? 'Assign room' : '') : 'Configure';
                const actionUrl = payload.reason === 'no_room' ? host.dataset.roomUrl : host.dataset.configureUrl;
                return renderNotice(payload.message, payload.reason === 'no_room' ? 'Ask an administrator to assign a classroom before using seat assignments.' : (host.dataset.configureUrl ? 'Create or apply a room layout, then return here to assign this class roster to seats.' : 'Ask an administrator to prepare this room?s seat plan. You can then assign enrolled students to its seats.'), label, actionUrl);
            }
            renderPlan(payload);
        };
        const schedulePoll = () => {
            window.clearTimeout(timer);
            if (document.visibilityState === 'visible') timer = window.setTimeout(() => load(false), state?.mode === 'active' || offeringId ? 3000 : 30000);
        };
        const load = async (force = false) => {
            if (document.visibilityState !== 'visible' && !force) return;
            controller?.abort(); controller = new AbortController();
            const url = new URL(endpoint, location.origin); url.searchParams.set('mode', 'live_seat_plan');
            if (offeringId) { url.searchParams.set('offering_id', offeringId); url.searchParams.set('date', attendanceDate); }
            try {
                const payload = await requestJson(url, { signal: controller.signal });
                const nextFingerprint = JSON.stringify({ mode: payload.mode, class: payload.class, plan: payload.plan, reason: payload.reason, message: payload.message });
                if (force || nextFingerprint !== fingerprint) {
                    const previous = state;
                    fingerprint = nextFingerprint; render(payload);
                    if (!force && previous?.plan && payload.plan && previous.class?.offering_id === payload.class?.offering_id && previous.date === payload.date) {
                        const prior = new Set(previous.plan.cells.filter((cell) => cell.checked_in).map((cell) => cell.student_id));
                        const arrivals = payload.plan.cells.filter((cell) => cell.checked_in && cell.student_id && !prior.has(cell.student_id));
                        if (arrivals.length === 1) toast(`${arrivals[0].student_name} checked in as ${arrivals[0].attendance_status}.`);
                        else if (arrivals.length > 1) toast(`${arrivals.length} students checked in.`);
                    }
                }
                else updateTimestamp();
            } catch (error) {
                if (error.name !== 'AbortError') renderNotice('Seat plan unavailable', error.message);
            } finally { schedulePoll(); }
        };
        const loadStudents = async () => {
            if (!state?.plan || !form) return;
            const query = search.value.trim();
            if (query.length === 1) { studentResults.replaceChildren(node('p', 'empty-state', 'Type at least 2 characters to search.')); return; }
            studentController?.abort(); studentController = new AbortController();
            const url = new URL(endpoint, location.origin); url.searchParams.set('mode', 'seat_students'); url.searchParams.set('offering_id', state.class.offering_id); url.searchParams.set('plan_id', state.plan.id); url.searchParams.set('q', query);
            studentResults.replaceChildren(node('p', 'empty-state', 'Loading enrolled students…'));
            try {
                const payload = await requestJson(url, { signal: studentController.signal });
                studentResults.replaceChildren();
                if (!payload.students.length) { studentResults.append(node('p', 'empty-state', 'No enrolled students match that search.')); return; }
                payload.students.forEach((student) => {
                    const button = node('button', 'live-seat-student-option'); button.type = 'button'; button.dataset.studentId = student.id;
                    const avatar = document.createElement('img'); avatar.src = student.profile_image_path || '/assets/images/default-avatar.svg'; avatar.alt = '';
                    const copy = node('span'); copy.append(node('strong', '', student.name), node('small', '', `${student.school_id}${student.grid_row ? ` · Seat ${student.grid_row}:${student.grid_column}` : ' · Not assigned'}`));
                    button.append(avatar, copy); button.addEventListener('click', () => {
                        studentResults.querySelectorAll('.is-selected').forEach((item) => item.classList.remove('is-selected'));
                        button.classList.add('is-selected'); form.elements.student_id.value = student.id; assignButton.disabled = false;
                    }); studentResults.append(button);
                });
            } catch (error) { if (error.name !== 'AbortError') studentResults.replaceChildren(node('p', 'empty-state', error.message)); }
        };
        const openAssignment = (cell) => {
            if (!dialog || !form || !state?.plan) return;
            form.reset(); form.elements.class_offering_id.value = state.class.offering_id; form.elements.seat_plan_id.value = state.plan.id;
            form.elements.grid_row.value = cell.dataset.row; form.elements.grid_column.value = cell.dataset.column; form.elements.student_id.value = '';
            dialog.querySelector('[data-live-seat-dialog-seat]').textContent = `Row ${cell.dataset.row}, column ${cell.dataset.column} · ${state.class.course_code} ${state.class.section_name}`;
            feedback.textContent = ''; feedback.classList.remove('is-error'); assignButton.disabled = true; unassignButton.hidden = !cell.dataset.studentId;
            search.value = ''; if (!dialog.open) dialog.showModal(); loadStudents(); window.setTimeout(() => search.focus(), 50);
        };

        configureButton.addEventListener('click', () => { configuring = !configuring; fingerprint = ''; render(state); });
        body.addEventListener('click', (event) => {
            const cell = event.target.closest('.live-seat-cell.is-student-seat');
            if (!cell || suppressSeatClick) return;
            if (configuring) openAssignment(cell);
            else if (cell.dataset.studentId) location.href = `/index/?page=profile&user_id=${encodeURIComponent(cell.dataset.studentId)}`;
        });
        search?.addEventListener('input', () => { window.clearTimeout(searchTimer); searchTimer = window.setTimeout(loadStudents, 280); });
        form?.addEventListener('submit', async (event) => {
            event.preventDefault(); if (!form.elements.student_id.value) return;
            assignButton.disabled = true; feedback.textContent = 'Assigning student…';
            try { const payload = await requestJson(endpoint, { method: 'POST', body: new FormData(form) }); dialog.close(); fingerprint = ''; await load(true); toast(payload.message); }
            catch (error) { feedback.textContent = error.message; feedback.classList.add('is-error'); }
            finally { assignButton.disabled = false; }
        });
        unassignButton?.addEventListener('click', async () => {
            const data = new FormData(form); data.set('action', 'unassign_class_seat'); unassignButton.disabled = true;
            try { const payload = await requestJson(endpoint, { method: 'POST', body: data }); dialog.close(); fingerprint = ''; await load(true); toast(payload.message); }
            catch (error) { feedback.textContent = error.message; feedback.classList.add('is-error'); }
            finally { unassignButton.disabled = false; }
        });
        document.addEventListener('live-seat-plan:offering', (event) => {
            if (mode !== 'attendance') return;
            offeringId = Number(event.detail?.offeringId || 0); attendanceDate = event.detail?.date || new Date().toISOString().slice(0, 10); configuring = false; fingerprint = ''; load(true);
        });
        document.addEventListener('live-seat-plan:refresh', () => { fingerprint = ''; load(true); });
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') load(true); else window.clearTimeout(timer); });
        load(true);
    });
})();
