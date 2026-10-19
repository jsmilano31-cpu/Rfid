(() => {
    'use strict';
    const root = document.querySelector('[data-class-roster]');
    if (!root) return;
    const results = root.querySelector('[data-roster-results]');
    const search = root.querySelector('[data-roster-search]');
    const summary = root.querySelector('[data-roster-summary]');
    const loading = root.querySelector('[data-roster-loading]');
    const pagination = root.querySelector('[data-roster-pagination]');
    let view = 'cards';
    let timer;
    let controller;
    const render = (payload) => {
        results.className = `class-roster-results is-${view}-view`;
        results.replaceChildren();
        payload.students.forEach((student) => {
            const link = document.createElement('a');
            link.className = 'class-roster-person';
            link.href = student.profile_url;
            const image = document.createElement('img'); image.src = student.profile_image_url; image.alt = '';
            const copy = document.createElement('span'); copy.className = 'class-roster-person-copy';
            const name = document.createElement('strong'); name.textContent = student.name;
            const id = document.createElement('span'); id.textContent = student.school_id;
            const email = document.createElement('small'); email.textContent = student.email;
            copy.append(name, id, email);
            const arrow = document.createElement('svg'); arrow.setAttribute('viewBox', '0 0 20 20'); arrow.innerHTML = '<path d="m7 4 6 6-6 6"/>';
            link.append(image, copy, arrow); results.append(link);
        });
        if (!payload.students.length) { const empty = document.createElement('p'); empty.className = 'empty-state'; empty.textContent = 'No enrolled students match this search.'; results.append(empty); }
        const page = payload.pagination;
        summary.textContent = `${page.total} student${page.total === 1 ? '' : 's'}${page.pages > 1 ? ` · Page ${page.page} of ${page.pages}` : ''}`;
        pagination.replaceChildren();
        const add = (label, targetPage, active = false, disabled = false) => { const button = document.createElement('button'); button.type = 'button'; button.textContent = label; button.classList.toggle('is-active', active); button.disabled = disabled; button.addEventListener('click', () => load(targetPage)); pagination.append(button); };
        if (page.pages > 1) { add('Previous', page.page - 1, false, page.page <= 1); for (let number = Math.max(1, page.page - 2); number <= Math.min(page.pages, page.page + 2); number += 1) add(String(number), number, number === page.page); add('Next', page.page + 1, false, page.page >= page.pages); }
    };
    const load = async (page = 1) => {
        const query = search.value.trim();
        if (query.length === 1) { summary.textContent = 'Type at least 2 characters to search.'; return; }
        controller?.abort(); controller = new AbortController(); loading.hidden = false;
        const url = new URL(root.dataset.endpoint, location.origin); url.searchParams.set('offering_id', root.dataset.offeringId); url.searchParams.set('q', query); url.searchParams.set('page', page);
        try { const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal }); const payload = await response.json(); if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to load the class roster.'); render(payload); }
        catch (error) { if (error.name !== 'AbortError') summary.textContent = error.message; }
        finally { loading.hidden = true; }
    };
    root.querySelectorAll('[data-roster-view]').forEach((button) => button.addEventListener('click', () => { view = button.dataset.rosterView; root.querySelectorAll('[data-roster-view]').forEach((item) => { const active = item === button; item.classList.toggle('is-active', active); item.setAttribute('aria-pressed', active ? 'true' : 'false'); }); results.className = `class-roster-results is-${view}-view`; }));
    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => load(1), 280); });
    load();
})();
