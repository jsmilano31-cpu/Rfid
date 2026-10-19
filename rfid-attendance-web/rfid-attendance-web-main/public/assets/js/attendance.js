(() => {
    'use strict';

    const notify = (message, error = false) => {
        const toast = document.createElement('div');
        toast.className = `app-toast${error ? ' is-error' : ''}`;
        toast.setAttribute('role', error ? 'alert' : 'status');
        toast.innerHTML = `<span class="app-toast-icon">${error ? '!' : '✓'}</span><span class="app-toast-copy"><strong>${error ? 'Couldn\'t save' : 'Saved'}</strong><span></span></span><button class="app-toast-close" type="button" aria-label="Dismiss">×</button>`;
        toast.querySelector('.app-toast-copy span').textContent = message;
        toast.querySelector('button').addEventListener('click', () => toast.remove());
        (document.querySelector('dialog[open]') || document.querySelector('[data-toast-region]') || document.body).append(toast);
        window.setTimeout(() => toast.remove(), error ? 8000 : 5000);
    };
    const node = (tag, className = '', copy = '') => {
        const item = document.createElement(tag);
        if (className) item.className = className;
        if (copy !== '') item.textContent = copy;
        return item;
    };
    const requestJson = async (url, options = {}) => {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', ...(options.headers || {}) }, ...options });
        let payload;
        try { payload = await response.json(); } catch { throw new Error('The server returned an invalid attendance response.'); }
        if (!response.ok || !payload.ok) throw new Error(payload.message || 'The attendance request could not be completed.');
        return payload;
    };
    const formatTime = (value) => {
        if (!value) return '—';
        const [hour, minute] = String(value).slice(0, 5).split(':').map(Number);
        return `${hour % 12 || 12}:${String(minute).padStart(2, '0')} ${hour < 12 ? 'AM' : 'PM'}`;
    };
    const icon = (kind) => kind === 'present'
        ? '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="m4.5 10.5 3.2 3.2 7.8-8"/></svg>'
        : '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M11.5 4.5H5.8A1.8 1.8 0 0 0 4 6.3v7.9A1.8 1.8 0 0 0 5.8 16h7.9a1.8 1.8 0 0 0 1.8-1.8V8.5"/><path d="m10 11 1.1-3.1 4.6-4.6a1.4 1.4 0 0 1 2 2l-4.6 4.6L10 11Z"/></svg>';

    document.querySelectorAll('[data-attendance-manager]').forEach((manager) => {
        const endpoint = manager.dataset.endpoint;
        const subjectBrowser = manager.querySelector('[data-attendance-subject-browser]');
        const rosterBrowser = manager.querySelector('[data-attendance-roster-browser]');
        const subjectResults = manager.querySelector('[data-attendance-subject-results]');
        const rosterResults = manager.querySelector('[data-attendance-roster-results]');
        const subjectSearch = manager.querySelector('[data-attendance-subject-search]');
        const studentSearch = manager.querySelector('[data-attendance-student-search]');
        const dateInput = manager.querySelector('[data-attendance-date]');
        const calendarElement = manager.querySelector('[data-attendance-calendar]');
        const calendarMonthLabel = manager.querySelector('[data-attendance-calendar-month]');
        const policyButton = manager.querySelector('[data-attendance-policy-current]');
        const editDialog = document.querySelector(`#${manager.id}-edit`);
        const editForm = editDialog?.querySelector('[data-attendance-edit-form]');
        const editUi = editDialog ? {
            avatar: editDialog.querySelector('[data-attendance-student-avatar]'),
            studentName: editDialog.querySelector('[data-attendance-student-name]'),
            studentId: editDialog.querySelector('[data-attendance-student-id]'),
            subject: editDialog.querySelector('[data-attendance-edit-subject]'),
            currentStatus: editDialog.querySelector('[data-attendance-edit-current]'),
            feedback: editDialog.querySelector('[data-attendance-edit-feedback]'),
        } : null;
        if (!editDialog || !editForm || !editUi || Object.values(editUi).some((element) => !element)) {
            console.error('Attendance editor markup is incomplete.', { manager: manager.id, editDialog, editForm, editUi });
            return;
        }
        const subjectCache = new Map();
        const rosterCache = new Map();
        let view = localStorage.getItem('rfid-attendance-subject-view') === 'list' ? 'list' : 'cards';
        let subjects = [];
        let students = [];
        let currentSubject = null;
        let currentPayload = null;
        let subjectController;
        let rosterController;
        let calendarController;
        let attendanceCalendar;
        let subjectTimer;
        let rosterTimer;

        const paginate = (host, data, callback) => {
            host.replaceChildren();
            if (!data || data.pages <= 1) return;
            for (let page = Math.max(1, data.page - 2); page <= Math.min(data.pages, data.page + 2); page += 1) {
                const button = node('button', page === data.page ? 'is-active' : '', String(page));
                button.type = 'button';
                button.addEventListener('click', () => callback(page));
                host.append(button);
            }
        };
        const updateView = () => manager.querySelectorAll('[data-attendance-view]').forEach((button) => {
            const active = button.dataset.attendanceView === view;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        const dateValue = (date) => {
            const value = date instanceof Date && !Number.isNaN(date.valueOf()) ? date : new Date();
            return `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`;
        };
        const updateCalendarMonthLabel = () => {
            const displayed = attendanceCalendar?.getCurrentDisplayDate() || new Date();
            calendarMonthLabel.textContent = displayed.toLocaleDateString([], { month: 'long', year: 'numeric' });
        };
        const markCalendarDate = () => {
            calendarElement.querySelectorAll('.is-selected-date').forEach((cell) => cell.classList.remove('is-selected-date'));
            if (!attendanceCalendar) return;
            const selected = new Date(`${dateInput.value}T12:00:00`);
            const displayed = attendanceCalendar.getCurrentDisplayDate();
            const first = new Date(displayed.getFullYear(), displayed.getMonth(), 1);
            first.setDate(first.getDate() - ((first.getDay() + 6) % 7));
            const index = Math.round((Date.UTC(selected.getFullYear(), selected.getMonth(), selected.getDate()) - Date.UTC(first.getFullYear(), first.getMonth(), first.getDate())) / 86400000) + 1;
            if (index >= 1 && index <= 42) calendarElement.querySelector(`#calendar-day-${index}`)?.classList.add('is-selected-date');
        };
        const selectAttendanceDate = (date) => {
            const value = dateValue(date);
            dateInput.value = value;
            dateInput._flatpickr?.setDate(value, false, 'Y-m-d');
            document.dispatchEvent(new CustomEvent('live-seat-plan:offering', { detail: { offeringId: currentSubject?.id || 0, date: value } }));
            rosterCache.clear();
            loadRoster(1, true);
            window.requestAnimationFrame(markCalendarDate);
        };
        const loadAttendanceCalendar = async () => {
            if (!attendanceCalendar || !currentSubject?.id) return;
            const displayed = attendanceCalendar.getCurrentDisplayDate();
            const month = `${displayed.getFullYear()}-${String(displayed.getMonth() + 1).padStart(2, '0')}`;
            calendarController?.abort();
            calendarController = new AbortController();
            const url = new URL(endpoint, location.origin);
            url.searchParams.set('mode', 'calendar'); url.searchParams.set('offering_id', currentSubject.id); url.searchParams.set('month', month);
            try {
                const payload = await requestJson(url, { signal: calendarController.signal });
                attendanceCalendar.setEvents(payload.sessions.map((session) => ({
                    id: `attendance-${currentSubject.id}-${session.attendance_date}`,
                    title: `${session.present_count} present${session.late_count ? ` · ${session.late_count} late` : ''}`,
                    from: new Date(`${session.attendance_date}T08:00:00`),
                    to: new Date(`${session.attendance_date}T09:00:00`),
                    color: session.finalized ? '#067647' : '#175CD3',
                    colorText: '#ffffff',
                    customTags: { attendanceDate: session.attendance_date, finalized: session.finalized },
                })), true, false);
                updateCalendarMonthLabel();
                window.requestAnimationFrame(markCalendarDate);
            } catch (error) { if (error.name !== 'AbortError') notify(error.message, true); }
        };
        const ensureAttendanceCalendar = () => {
            if (attendanceCalendar || !window.calendarJs) return;
            attendanceCalendar = new window.calendarJs(calendarElement.id, {
                data: [], manualEditingEnabled: false, dragAndDropForEventsEnabled: false,
                exportEventsEnabled: false, importEventsEnabled: false, autoRefreshTimerDelay: 0,
                useAmPmForTimeDisplays: true, showDayNumberOrdinals: false, fullScreenModeEnabled: false,
                weekendDays: [0],
                views: { fullDay: { enabled: false }, fullWeek: { enabled: false }, fullMonth: { maximumEventsPerDayDisplay: 2, minimumDayHeight: 64, showExtraTitleBarButtons: false, showPreviousNextMonthNames: false, showPreviousNextYears: false, showMonthButtonsInYearDropDownMenu: false, showYearDropDownButtonIcon: false }, fullYear: { enabled: false }, timeline: { enabled: false }, allEvents: { enabled: false } },
                events: {
                    onRender: () => { updateCalendarMonthLabel(); window.requestAnimationFrame(markCalendarDate); },
                    onPreviousMonth: () => window.setTimeout(loadAttendanceCalendar),
                    onNextMonth: () => window.setTimeout(loadAttendanceCalendar),
                    onToday: () => window.setTimeout(loadAttendanceCalendar),
                    onSetDate: () => window.setTimeout(loadAttendanceCalendar),
                    onEventClick: (calendarEvent) => selectAttendanceDate(new Date(calendarEvent.from)),
                    onFullMonthEventRender: (element) => { element.classList.add('attendance-calendar-entry'); return false; },
                },
            });
            calendarElement.addEventListener('click', (event) => {
                if (event.target.closest('.event')) return;
                const cell = event.target.closest('.full-month-view .row-cells.days .cell');
                const index = Number(cell?.id.match(/^calendar-day-(\d+)$/)?.[1]);
                if (!index) return;
                const displayed = attendanceCalendar.getCurrentDisplayDate();
                const selected = new Date(displayed.getFullYear(), displayed.getMonth(), 1);
                selected.setDate(selected.getDate() - ((selected.getDay() + 6) % 7) + index - 1);
                selectAttendanceDate(selected);
            });
            updateCalendarMonthLabel();
        };
        const subjectButton = (subject, list = false) => {
            const button = node('button', list ? 'grade-subject-list-row' : 'grade-subject-card attendance-subject-card');
            button.type = 'button';
            button.dataset.attendanceSubjectId = subject.id;
            if (list) {
                [[subject.course_code, subject.course_title], [subject.section_name, `${subject.program_code} · ${subject.program_name}`], [String(subject.student_count), 'Students'], [String(subject.session_count), 'Sessions']].forEach(([primary, secondary]) => {
                    const cell = node('span', 'grade-subject-list-cell');
                    cell.append(node('strong', '', primary), node('small', '', secondary));
                    button.append(cell);
                });
                const arrow = node('span', 'grade-subject-arrow');
                arrow.innerHTML = '<svg viewBox="0 0 20 20"><path d="m7.5 5 5 5-5 5"/></svg>';
                button.append(arrow);
                return button;
            }
            const heading = node('span', 'grade-subject-card-heading');
            heading.append(node('span', 'grade-subject-code', subject.course_code), node('span', 'grade-subject-term', subject.semester_name));
            const schedule = node('span', 'grade-subject-schedule');
            schedule.innerHTML = '<svg viewBox="0 0 20 20"><circle cx="10" cy="10" r="7"/><path d="M10 6v4l3 2"/></svg>';
            schedule.append(document.createTextNode(subject.schedule?.length ? subject.schedule.join(' · ') : 'Schedule not set'));
            const stats = node('span', 'attendance-subject-stats');
            stats.append(node('span', '', `${subject.student_count} students`), node('span', '', `${subject.session_count} sessions`));
            const footer = node('span', 'grade-subject-footer');
            footer.append(node('span', '', subject.teacher_names || 'No teacher assigned'), node('span', '', 'Open roster →'));
            button.append(heading, node('strong', 'grade-subject-title', subject.course_title), node('span', 'grade-subject-section', `${subject.program_code} · ${subject.section_name}`), schedule, stats, footer);
            return button;
        };
        const renderSubjects = (payload) => {
            subjects = payload.subjects;
            subjectResults.replaceChildren();
            subjectResults.className = view === 'cards' ? 'grade-subject-grid' : 'grade-subject-list';
            if (!subjects.length) {
                const empty = node('div', 'grade-empty-state');
                empty.append(node('strong', '', 'No subjects found'), node('span', '', subjectSearch.value ? 'Try another course code, title, program, or section.' : 'No active subjects are available for attendance management.'));
                subjectResults.append(empty);
            } else if (view === 'cards') subjects.forEach((subject) => subjectResults.append(subjectButton(subject)));
            else {
                const header = node('div', 'grade-subject-list-header');
                ['Subject', 'Class', 'Students', 'Sessions', ''].forEach((label) => header.append(node('span', '', label)));
                subjectResults.append(header);
                subjects.forEach((subject) => subjectResults.append(subjectButton(subject, true)));
            }
            const { total, page, pages } = payload.pagination;
            manager.querySelector('[data-attendance-subject-summary]').textContent = `${total} subject${total === 1 ? '' : 's'}${pages > 1 ? ` · Page ${page} of ${pages}` : ''}`;
            paginate(manager.querySelector('[data-attendance-subject-pagination]'), payload.pagination, loadSubjects);
        };
        const loadSubjects = async (page = 1, force = false) => {
            const query = subjectSearch.value.trim();
            if (query.length === 1) return;
            const key = `${query}|${page}`;
            if (!force && subjectCache.has(key)) return renderSubjects(subjectCache.get(key));
            subjectController?.abort();
            subjectController = new AbortController();
            manager.querySelector('[data-attendance-subject-loading]').hidden = false;
            const url = new URL(endpoint, location.origin);
            url.searchParams.set('mode', 'subjects'); url.searchParams.set('q', query); url.searchParams.set('page', page);
            try { const payload = await requestJson(url, { signal: subjectController.signal }); subjectCache.set(key, payload); renderSubjects(payload); }
            catch (error) { if (error.name !== 'AbortError') notify(error.message, true); }
            finally { manager.querySelector('[data-attendance-subject-loading]').hidden = true; }
        };
        const statusBadge = (status) => node('span', `attendance-status is-${status.toLowerCase()}`, status);
        const studentRow = (student) => {
            const row = document.createElement('tr');
            const identityCell = document.createElement('td');
            const identity = node('a', 'grade-student-cell'); identity.href = `/index/?page=profile&user_id=${encodeURIComponent(student.student_id)}`; identity.title = `View ${student.student_name}'s profile`;
            const avatar = document.createElement('img'); avatar.src = student.profile_image_path || '/assets/images/default-avatar.svg'; avatar.alt = ''; avatar.loading = 'lazy';
            const copy = node('span'); copy.append(node('strong', '', student.student_name), node('small', '', student.school_id)); identity.append(avatar, copy); identityCell.append(identity);
            const status = document.createElement('td'); status.append(statusBadge(student.status));
            const time = node('td', '', formatTime(student.time_in));
            const source = node('td', 'attendance-source', student.source ? student.source.replaceAll('_', ' ') : '—');
            const actions = document.createElement('td');
            const group = node('span', 'attendance-row-actions');
            if (student.status === 'Unrecorded' && currentPayload?.scheduled) {
                const present = node('button', 'icon-action-button is-success'); present.type = 'button'; present.dataset.attendanceQuick = student.student_id; present.title = 'Mark present'; present.setAttribute('aria-label', `Mark ${student.student_name} present`); present.innerHTML = icon('present'); group.append(present);
            }
            const edit = node('button', 'icon-action-button'); edit.type = 'button'; edit.dataset.attendanceEdit = student.student_id; edit.title = student.status === 'Unrecorded' ? 'Record attendance' : 'Edit attendance'; edit.setAttribute('aria-label', `${edit.title} for ${student.student_name}`); edit.innerHTML = icon('edit'); edit.disabled = !currentPayload?.scheduled; group.append(edit); actions.append(group);
            row.append(identityCell, status, time, source, actions);
            return row;
        };
        const renderRoster = (payload) => {
            currentPayload = payload; currentSubject = payload.subject; students = payload.students;
            if (policyButton) policyButton.dataset.offeringId = currentSubject.id;
            manager.querySelector('[data-attendance-subject-name]').textContent = `${currentSubject.course_code} — ${currentSubject.course_title}`;
            manager.querySelector('[data-attendance-subject-detail]').textContent = `${currentSubject.program_code} · ${currentSubject.section_name} · ${currentSubject.school_year_name} · ${currentSubject.semester_name}`;
            const note = manager.querySelector('[data-attendance-session-note]');
            note.className = `attendance-session-note${payload.scheduled ? '' : ' is-warning'}`;
            note.textContent = payload.scheduled
                ? `${payload.schedule?.day_of_week || 'Scheduled class'}${payload.session?.finalized_at ? ' · Session finalized' : ' · Session open for entries'}`
                : payload.schedule_message;
            manager.querySelector('[data-attendance-finalize]').disabled = !payload.scheduled || Boolean(payload.session?.finalized_at);
            const summary = manager.querySelector('[data-attendance-summary]'); summary.replaceChildren();
            ['Present', 'Late', 'Absent', 'Excused', 'Unrecorded'].forEach((status) => { const item = node('span', `attendance-summary-item is-${status.toLowerCase()}`); item.append(node('strong', '', payload.summary[status]), node('small', '', status)); summary.append(item); });
            rosterResults.replaceChildren(); students.forEach((student) => rosterResults.append(studentRow(student)));
            if (!students.length) { const row = document.createElement('tr'); const cell = node('td', 'empty-state', studentSearch.value ? 'No students match that search.' : 'No active students are enrolled.'); cell.colSpan = 5; row.append(cell); rosterResults.append(row); }
            const { total, page, pages } = payload.pagination;
            manager.querySelector('[data-attendance-roster-summary]').textContent = `${total} student${total === 1 ? '' : 's'}${pages > 1 ? ` · Page ${page} of ${pages}` : ''}`;
            paginate(manager.querySelector('[data-attendance-roster-pagination]'), payload.pagination, loadRoster);
        };
        const loadRoster = async (page = 1, force = false) => {
            if (!currentSubject?.id) return;
            const query = studentSearch.value.trim();
            if (query.length === 1) { manager.querySelector('[data-attendance-roster-summary]').textContent = 'Type at least 2 characters to search.'; return; }
            const key = `${currentSubject.id}|${dateInput.value}|${query}|${page}`;
            if (!force && rosterCache.has(key)) return renderRoster(rosterCache.get(key));
            rosterController?.abort(); rosterController = new AbortController(); manager.querySelector('[data-attendance-roster-loading]').hidden = false;
            const url = new URL(endpoint, location.origin); url.searchParams.set('mode', 'roster'); url.searchParams.set('offering_id', currentSubject.id); url.searchParams.set('date', dateInput.value); url.searchParams.set('q', query); url.searchParams.set('page', page);
            try { const payload = await requestJson(url, { signal: rosterController.signal }); rosterCache.set(key, payload); renderRoster(payload); return payload; }
            catch (error) { if (error.name !== 'AbortError') notify(error.message, true); }
            finally { manager.querySelector('[data-attendance-roster-loading]').hidden = true; }
        };
        const post = async (data, button, feedback) => {
            if (button) button.disabled = true;
            try { const payload = await requestJson(endpoint, { method: 'POST', body: data }); subjectCache.clear(); rosterCache.clear(); if (currentSubject) { await loadRoster(1, true); await loadAttendanceCalendar(); } document.dispatchEvent(new CustomEvent('live-seat-plan:refresh')); notify(payload.message); return payload; }
            catch (error) { if (feedback) { feedback.textContent = error.message; feedback.classList.add('is-error'); } notify(error.message, true); throw error; }
            finally { if (button) button.disabled = false; }
        };
        const openEditor = (student) => {
            if (!currentSubject || !student) {
                notify('Select a subject and student before editing attendance.', true);
                return;
            }
            const status = student.status || 'Unrecorded';
            editForm.reset();
            editForm.elements.class_offering_id.value = currentSubject.id;
            editForm.elements.student_id.value = student.student_id;
            editForm.elements.attendance_date.value = dateInput.value;
            editForm.elements.status.value = status === 'Unrecorded' ? 'Present' : status;
            editForm.elements.time_in.value = student.time_in?.slice(0, 5) || '';
            editForm.elements.reason.value = student.correction_reason || '';
            editUi.avatar.src = student.profile_image_path || '/assets/images/default-avatar.svg';
            editUi.studentName.textContent = student.student_name || 'Student';
            editUi.studentId.textContent = student.school_id || '';
            editUi.subject.textContent = `${currentSubject.course_code} · ${currentSubject.section_name} · ${dateInput.value}`;
            editUi.currentStatus.className = `attendance-status is-${status.toLowerCase()}`;
            editUi.currentStatus.textContent = status;
            editUi.feedback.textContent = '';
            editUi.feedback.classList.remove('is-error');
            editForm.elements.reason.required = status !== 'Unrecorded';
            editForm.elements.time_in._flatpickr?.setDate(editForm.elements.time_in.value || null, false, 'H:i');
            editForm.elements.status.dispatchEvent(new Event('change'));
            if (!editDialog.open) editDialog.showModal();
        };

        const openSubject = (subject) => {
            currentSubject = subject;
            if (policyButton) policyButton.dataset.offeringId = subject.id;
            studentSearch.value = '';
            subjectBrowser.hidden = true;
            rosterBrowser.hidden = false;
            manager.querySelector('[data-attendance-workspace-title]').textContent = 'Class roster';
            manager.querySelector('[data-attendance-workspace-description]').textContent = 'Choose a date in the picker or calendar, then review and update the roster.';
            ensureAttendanceCalendar();
            document.dispatchEvent(new CustomEvent('live-seat-plan:offering', { detail: { offeringId: subject.id, date: dateInput.value } }));
            loadRoster();
            loadAttendanceCalendar();
        };

        manager.querySelectorAll('[data-attendance-view]').forEach((button) => button.addEventListener('click', () => { view = button.dataset.attendanceView; localStorage.setItem('rfid-attendance-subject-view', view); updateView(); renderSubjects({ subjects, pagination: { total: subjects.length, page: 1, pages: 1 } }); loadSubjects(); }));
        subjectResults.addEventListener('click', (event) => { const button = event.target.closest('[data-attendance-subject-id]'); if (!button) return; const subject = subjects.find((item) => Number(item.id) === Number(button.dataset.attendanceSubjectId)); if (subject) openSubject(subject); });
        manager.querySelector('[data-attendance-back]').addEventListener('click', () => { currentSubject = null; currentPayload = null; if (policyButton) delete policyButton.dataset.offeringId; rosterBrowser.hidden = true; subjectBrowser.hidden = false; document.dispatchEvent(new CustomEvent('live-seat-plan:offering', { detail: { offeringId: 0 } })); manager.querySelector('[data-attendance-workspace-title]').textContent = 'Assigned subjects'; manager.querySelector('[data-attendance-workspace-description]').textContent = 'Choose a subject to open its dated class roster and record attendance.'; if (!subjects.length) loadSubjects(); });
        subjectSearch.addEventListener('input', () => { clearTimeout(subjectTimer); subjectTimer = setTimeout(() => loadSubjects(), 280); });
        studentSearch.addEventListener('input', () => { clearTimeout(rosterTimer); rosterTimer = setTimeout(() => loadRoster(), 280); });
        dateInput.addEventListener('change', () => { rosterCache.clear(); document.dispatchEvent(new CustomEvent('live-seat-plan:offering', { detail: { offeringId: currentSubject?.id || 0, date: dateInput.value } })); loadRoster(1, true); window.requestAnimationFrame(markCalendarDate); });
        manager.querySelector('[data-attendance-calendar-previous]').addEventListener('click', () => attendanceCalendar?.moveToPreviousMonth());
        manager.querySelector('[data-attendance-calendar-next]').addEventListener('click', () => attendanceCalendar?.moveToNextMonth());
        manager.querySelector('[data-attendance-calendar-today]').addEventListener('click', () => { attendanceCalendar?.moveToToday(); selectAttendanceDate(new Date()); });
        rosterResults.addEventListener('click', async (event) => {
            const edit = event.target.closest('[data-attendance-edit]');
            if (edit) { const student = students.find((item) => Number(item.student_id) === Number(edit.dataset.attendanceEdit)); if (student) openEditor(student); return; }
            const quick = event.target.closest('[data-attendance-quick]');
            if (!quick) return;
            const data = new FormData(); data.set('_token', editForm.elements._token.value); data.set('action', 'quick_present'); data.set('class_offering_id', currentSubject.id); data.set('student_id', quick.dataset.attendanceQuick); data.set('attendance_date', dateInput.value); data.set('time_in', new Date().toTimeString().slice(0, 5));
            try { await post(data, quick); } catch { /* toast already shown */ }
        });
        editForm.addEventListener('submit', async (event) => { event.preventDefault(); if (!editForm.reportValidity()) return; const button = event.submitter; const feedback = editUi.feedback; feedback.textContent = 'Saving attendance…'; feedback.classList.remove('is-error'); try { await post(new FormData(editForm), button, feedback); editDialog.close(); } catch { /* feedback already shown */ } });
        editForm.elements.status.addEventListener('change', () => {
            const usesTime = ['Present', 'Late'].includes(editForm.elements.status.value);
            editForm.elements.time_in.disabled = !usesTime;
            if (!usesTime) {
                editForm.elements.time_in.value = '';
                editForm.elements.time_in._flatpickr?.clear();
            }
        });
        manager.querySelector('[data-attendance-finalize]').addEventListener('click', async (event) => { if (!confirm('Finalize this date and mark every unrecorded enrolled student absent?')) return; const data = new FormData(); data.set('_token', editForm.elements._token.value); data.set('action', 'finalize_attendance'); data.set('class_offering_id', currentSubject.id); data.set('attendance_date', dateInput.value); try { await post(data, event.currentTarget); } catch { /* toast already shown */ } });
        manager.addEventListener('attendance:refresh', () => {
            subjectCache.clear();
            rosterCache.clear();
            loadSubjects(1, true);
            if (currentSubject) loadRoster(1, true);
        });
        updateView();
        const attendanceUrlParameters = new URLSearchParams(window.location.search);
        const linkedAttendanceOfferingId = Number(attendanceUrlParameters.get('offering_id'));
        if (linkedAttendanceOfferingId > 0 && attendanceUrlParameters.get('view') !== 'personal') openSubject({ id: linkedAttendanceOfferingId });
        else loadSubjects();
    });

    document.querySelectorAll('[data-student-attendance-calendar]').forEach((calendarElement) => {
        if (!window.calendarJs) return;
        let records = [];
        try { records = JSON.parse(calendarElement.dataset.records || '[]'); } catch { records = []; }
        const statusColors = { Present: '#067647', Late: '#B54708', Absent: '#B42318', Excused: '#6941C6' };
        const recordsByDate = new Map(records.map((record) => [record.attendance_date, record]));
        const monthLabel = document.querySelector('[data-student-calendar-month]');
        let calendar;
        const valueOf = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        const updateMonth = () => { const displayed = calendar?.getCurrentDisplayDate() || new Date(); monthLabel.textContent = displayed.toLocaleDateString([], { month: 'long', year: 'numeric' }); };
        const markStatusCells = () => {
            calendarElement.querySelectorAll('[class*="attendance-day-"]').forEach((cell) => cell.classList.remove('attendance-day-present', 'attendance-day-late', 'attendance-day-absent', 'attendance-day-excused'));
            if (!calendar) return;
            const displayed = calendar.getCurrentDisplayDate();
            const first = new Date(displayed.getFullYear(), displayed.getMonth(), 1);
            first.setDate(first.getDate() - ((first.getDay() + 6) % 7));
            for (let index = 1; index <= 42; index += 1) {
                const date = new Date(first); date.setDate(first.getDate() + index - 1);
                const record = recordsByDate.get(valueOf(date));
                if (record) calendarElement.querySelector(`#calendar-day-${index}`)?.classList.add(`attendance-day-${record.status.toLowerCase()}`);
            }
        };
        const focusRecord = (date) => {
            const row = document.querySelector(`[data-attendance-history-date="${CSS.escape(date)}"]`);
            if (!row) return;
            document.querySelectorAll('[data-attendance-history-date].is-highlighted').forEach((item) => item.classList.remove('is-highlighted'));
            row.classList.add('is-highlighted');
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            window.setTimeout(() => row.classList.remove('is-highlighted'), 2400);
        };
        calendar = new window.calendarJs(calendarElement.id, {
            data: records.map((record) => ({ id: `student-attendance-${record.id}`, title: record.status, from: new Date(`${record.attendance_date}T08:00:00`), to: new Date(`${record.attendance_date}T09:00:00`), color: statusColors[record.status] || '#667085', colorText: '#ffffff', customTags: { attendanceDate: record.attendance_date, status: record.status } })),
            manualEditingEnabled: false, dragAndDropForEventsEnabled: false, exportEventsEnabled: false, importEventsEnabled: false, autoRefreshTimerDelay: 0, useAmPmForTimeDisplays: true, showDayNumberOrdinals: false, fullScreenModeEnabled: false, weekendDays: [0],
            views: { fullDay: { enabled: false }, fullWeek: { enabled: false }, fullMonth: { maximumEventsPerDayDisplay: 1, minimumDayHeight: 70, showExtraTitleBarButtons: false, showPreviousNextMonthNames: false, showPreviousNextYears: false, showMonthButtonsInYearDropDownMenu: false, showYearDropDownButtonIcon: false }, fullYear: { enabled: false }, timeline: { enabled: false }, allEvents: { enabled: false } },
            events: { onRender: () => { updateMonth(); window.requestAnimationFrame(markStatusCells); }, onPreviousMonth: () => window.setTimeout(updateMonth), onNextMonth: () => window.setTimeout(updateMonth), onToday: () => window.setTimeout(updateMonth), onSetDate: () => window.setTimeout(updateMonth), onEventClick: (event) => focusRecord(event.customTags?.attendanceDate), onFullMonthEventRender: (element, event) => { element.classList.add(`student-attendance-entry-${String(event.customTags?.status || '').toLowerCase()}`); return false; } },
        });
        document.querySelector('[data-student-calendar-previous]')?.addEventListener('click', () => calendar.moveToPreviousMonth());
        document.querySelector('[data-student-calendar-next]')?.addEventListener('click', () => calendar.moveToNextMonth());
        document.querySelector('[data-student-calendar-today]')?.addEventListener('click', () => calendar.moveToToday());
        calendarElement.addEventListener('click', (event) => {
            if (event.target.closest('.event')) return;
            const cell = event.target.closest('.full-month-view .row-cells.days .cell');
            const index = Number(cell?.id.match(/^calendar-day-(\d+)$/)?.[1]);
            if (!index) return;
            const displayed = calendar.getCurrentDisplayDate();
            const selected = new Date(displayed.getFullYear(), displayed.getMonth(), 1);
            selected.setDate(selected.getDate() - ((selected.getDay() + 6) % 7) + index - 1);
            focusRecord(valueOf(selected));
        });
        updateMonth();
    });

    document.querySelectorAll('[data-attendance-advanced-form], [data-attendance-rules-form]').forEach((form) => form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const button = event.submitter;
        const feedback = form.querySelector('[data-attendance-feedback]');
        if (feedback) { feedback.textContent = 'Saving…'; feedback.classList.remove('is-error'); }
        button.disabled = true;
        try {
            const endpoint = document.querySelector('[data-attendance-manager]')?.dataset.endpoint || '/attendance.php';
            const payload = await requestJson(endpoint, { method: 'POST', body: new FormData(form) });
            if (feedback) feedback.textContent = payload.message;
            notify(payload.message);
            document.dispatchEvent(new CustomEvent('live-seat-plan:refresh'));
            document.querySelectorAll('[data-attendance-manager]').forEach((manager) => manager.dispatchEvent(new CustomEvent('attendance:refresh')));
        } catch (error) { if (feedback) { feedback.textContent = error.message; feedback.classList.add('is-error'); } notify(error.message, true); }
        finally { button.disabled = false; }
    }));
})();
