(() => {
    'use strict';

    const explorer = document.querySelector('[data-admin-roster-explorer]');
    if (!explorer) return;

    const endpoint = explorer.dataset.endpoint;
    const browser = explorer.querySelector('[data-admin-class-browser]');
    const search = explorer.querySelector('[data-admin-class-search]');
    const results = explorer.querySelector('[data-admin-class-results]');
    const summary = explorer.querySelector('[data-admin-class-summary]');
    const subjectPages = explorer.querySelector('[data-admin-class-pagination]');
    const rosterView = explorer.querySelector('[data-admin-roster-view]');
    const rosterDate = explorer.querySelector('[data-admin-roster-date]');
    const rosterNote = explorer.querySelector('[data-admin-roster-note]');
    const rosterRows = explorer.querySelector('[data-admin-roster-tbody]');
    const rosterSummary = explorer.querySelector('[data-admin-roster-summary]');
    const rosterPages = explorer.querySelector('[data-admin-roster-pagination]');
    const finalize = explorer.querySelector('[data-admin-roster-finalize]');
    let subjects = [];
    let currentSubject = null;
    let subjectController;
    let rosterController;
    let searchTimer;

    const element = (tag, className = '', text = '') => {
        const result = document.createElement(tag);
        result.className = className;
        result.textContent = text;
        return result;
    };
    const emptyState = (host, title, detail) => {
        const box = element('div', 'grade-empty-state');
        box.append(element('strong', '', title), element('span', '', detail));
        host.replaceChildren(box);
    };
    const request = async (url, options = {}) => {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, ...options });
        let payload;
        try { payload = await response.json(); } catch { throw new Error('The server returned an invalid attendance response.'); }
        if (!response.ok || !payload.ok) throw new Error(payload.message || 'Attendance could not be loaded.');
        return payload;
    };
    const pages = (host, pagination, callback) => {
        host.replaceChildren();
        if (!pagination || pagination.pages < 2) return;
        const first = Math.max(1, pagination.page - 2);
        const last = Math.min(pagination.pages, pagination.page + 2);
        for (let page = first; page <= last; page += 1) {
            const button = element('button', page === pagination.page ? 'is-active' : '', String(page));
            button.type = 'button';
            if (page === pagination.page) button.setAttribute('aria-current', 'page');
            button.addEventListener('click', () => callback(page));
            host.append(button);
        }
    };
    const timeLabel = (value) => {
        if (!value) return '—';
        const [hours, minutes] = String(value).slice(0, 5).split(':').map(Number);
        return `${hours % 12 || 12}:${String(minutes).padStart(2, '0')} ${hours < 12 ? 'AM' : 'PM'}`;
    };
    const subjectCard = (subject) => {
        const button = element('button', 'grade-subject-card attendance-subject-card');
        button.type = 'button';
        button.dataset.adminClassId = subject.id;
        const heading = element('span', 'grade-subject-card-heading');
        heading.append(element('span', 'grade-subject-code', subject.course_code), element('span', 'grade-subject-term', subject.semester_name));
        const counts = element('span', 'attendance-subject-stats');
        counts.append(element('span', '', `${subject.student_count} students`), element('span', '', `${subject.session_count} sessions`));
        const footer = element('span', 'grade-subject-footer');
        footer.append(element('span', '', subject.teacher_names || 'No teacher assigned'), element('span', '', 'Open roster →'));
        button.append(heading, element('strong', 'grade-subject-title', subject.course_title),
            element('span', 'grade-subject-section', `${subject.program_code} · ${subject.section_name} · ${subject.school_year_name}`),
            element('span', 'grade-subject-schedule', subject.schedule?.join(' · ') || 'Schedule not set'), counts, footer);
        return button;
    };
    const loadSubjects = async (page = 1) => {
        subjectController?.abort();
        const controller = new AbortController();
        subjectController = controller;
        const url = new URL(endpoint, location.origin);
        url.searchParams.set('mode', 'subjects');
        url.searchParams.set('q', search.value.trim());
        url.searchParams.set('page', page);
        results.setAttribute('aria-busy', 'true');
        summary.textContent = '';
        if (!subjects.length && !results.childElementCount) emptyState(results, 'Loading class offerings', 'Fetching campus subjects…');
        try {
            const payload = await request(url, { signal: controller.signal });
            subjects = payload.subjects;
            results.classList.add('grade-subject-grid');
            results.replaceChildren(...subjects.map(subjectCard));
            if (!subjects.length) emptyState(results, 'No class offerings found', 'Try a course code, title, program, or section.');
            summary.textContent = `${payload.pagination.total} class offering${payload.pagination.total === 1 ? '' : 's'}`;
            pages(subjectPages, payload.pagination, loadSubjects);
        } catch (error) {
            if (error.name !== 'AbortError') {
                emptyState(results, 'Could not load classes', error.message);
                subjectPages.replaceChildren();
            }
        } finally {
            if (!controller.signal.aborted) results.removeAttribute('aria-busy');
        }
    };
    const renderRoster = (payload) => {
        const subject = payload.subject;
        explorer.querySelector('[data-admin-roster-subject-name]').textContent = `${subject.course_code} — ${subject.course_title}`;
        explorer.querySelector('[data-admin-roster-subject-detail]').textContent = `${subject.program_code} · ${subject.section_name} · ${subject.school_year_name} · ${subject.semester_name}`;
        rosterNote.className = `attendance-session-note${payload.scheduled ? '' : ' is-warning'}`;
        rosterNote.textContent = payload.scheduled
            ? `${payload.schedule?.day_of_week || 'Scheduled class'} · ${payload.session?.finalized_at ? 'Session finalized' : 'Session open for entries'}`
            : payload.schedule_message;
        finalize.disabled = !payload.scheduled || Boolean(payload.session?.finalized_at);
        rosterSummary.replaceChildren();
        for (const status of ['Present', 'Late', 'Absent', 'Excused', 'Unrecorded']) {
            const item = element('span', `attendance-summary-item is-${status.toLowerCase()}`);
            item.append(element('strong', '', String(payload.summary[status] ?? 0)), element('small', '', status));
            rosterSummary.append(item);
        }
        rosterRows.replaceChildren();
        for (const student of payload.students) {
            const row = element('tr');
            const identity = element('td');
            identity.append(element('strong', '', student.student_name), element('br'), element('small', 'muted', student.school_id));
            const status = element('td');
            status.append(element('span', `attendance-status is-${student.status.toLowerCase()}`, student.status));
            const profile = element('td');
            const link = element('a', 'button button-secondary button-small', 'View profile');
            link.href = `/admin/?page=profile&id=${encodeURIComponent(student.student_id)}`;
            profile.append(link);
            row.append(identity, status, element('td', '', timeLabel(student.time_in)),
                element('td', 'attendance-source', student.source?.replaceAll('_', ' ') || '—'), profile);
            rosterRows.append(row);
        }
        if (!payload.students.length) {
            const row = element('tr');
            const cell = element('td', 'empty-state', 'No enrolled students are available for this class.');
            cell.colSpan = 5;
            row.append(cell);
            rosterRows.append(row);
        }
        pages(rosterPages, payload.pagination, loadRoster);
    };
    const loadRoster = async (page = 1) => {
        if (!currentSubject) return;
        rosterController?.abort();
        const controller = new AbortController();
        rosterController = controller;
        const url = new URL(endpoint, location.origin);
        url.searchParams.set('mode', 'roster');
        url.searchParams.set('offering_id', currentSubject.id);
        url.searchParams.set('date', rosterDate.value);
        url.searchParams.set('page', page);
        rosterRows.setAttribute('aria-busy', 'true');
        try {
            renderRoster(await request(url, { signal: controller.signal }));
        } catch (error) {
            if (error.name !== 'AbortError') {
                rosterNote.className = 'attendance-session-note is-warning';
                rosterNote.textContent = error.message;
                rosterRows.replaceChildren();
                rosterSummary.replaceChildren();
                rosterPages.replaceChildren();
                finalize.disabled = true;
            }
        } finally {
            if (!controller.signal.aborted) rosterRows.removeAttribute('aria-busy');
        }
    };

    search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadSubjects(), 250);
    });
    results.addEventListener('click', (event) => {
        const card = event.target.closest('[data-admin-class-id]');
        const subject = subjects.find((item) => item.id === Number(card?.dataset.adminClassId));
        if (!subject) return;
        currentSubject = subject;
        const date = subject.latest_session_date || rosterDate.value;
        rosterDate.value = date;
        rosterDate._flatpickr?.setDate(date, false, 'Y-m-d');
        browser.hidden = true;
        rosterView.hidden = false;
        loadRoster();
    });
    explorer.querySelector('[data-admin-roster-back]').addEventListener('click', () => {
        rosterController?.abort();
        currentSubject = null;
        rosterView.hidden = true;
        browser.hidden = false;
        search.focus();
    });
    rosterDate.addEventListener('change', () => loadRoster());
    finalize.addEventListener('click', async () => {
        if (!currentSubject || !window.confirm('Finalize this date and mark every unrecorded enrolled student absent?')) return;
        finalize.disabled = true;
        const data = new FormData();
        data.set('_token', explorer.querySelector('[data-admin-roster-token]').value);
        data.set('action', 'finalize_attendance');
        data.set('class_offering_id', currentSubject.id);
        data.set('attendance_date', rosterDate.value);
        try {
            const payload = await request(endpoint, { method: 'POST', body: data });
            await loadRoster();
            rosterNote.textContent = payload.message;
        } catch (error) {
            rosterNote.className = 'attendance-session-note is-warning';
            rosterNote.textContent = error.message;
            finalize.disabled = false;
        }
    });
    loadSubjects();
})();
