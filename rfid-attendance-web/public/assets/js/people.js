(() => {
    'use strict';
    const directory = document.querySelector('[data-people-directory]');
    if (!directory) return;
    const search = directory.querySelector('[data-people-search]');
    const role = directory.querySelector('[data-people-role]');
    const results = directory.querySelector('[data-people-results]');
    const state = directory.querySelector('[data-people-state]');
    const summary = directory.querySelector('[data-people-summary]');
    const pagination = directory.querySelector('[data-people-pagination]');
    let timer = 0;
    let controller = null;

    const node = (tag, className = '', text = '') => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== '') element.textContent = text;
        return element;
    };
    const renderPagination = (meta) => {
        pagination.replaceChildren();
        if (!meta || meta.pages < 2) return;
        const start = Math.max(1, meta.page - 2);
        const end = Math.min(meta.pages, meta.page + 2);
        for (let page = start; page <= end; page += 1) {
            const button = node('button', page === meta.page ? 'is-active' : '', String(page));
            button.type = 'button'; button.setAttribute('aria-label', `People page ${page}`);
            if (page === meta.page) button.setAttribute('aria-current', 'page');
            button.addEventListener('click', () => load(page)); pagination.append(button);
        }
    };
    const render = (payload) => {
        results.replaceChildren();
        const items = payload.items || [];
        state.hidden = items.length > 0;
        state.textContent = items.length ? '' : 'No people match this search.';
        items.forEach((person) => {
            const card = node('a', 'people-directory-card'); card.href = person.profile_url;
            const avatar = document.createElement('img'); avatar.src = person.profile_image_path || '/assets/images/default-avatar.svg'; avatar.alt = '';
            const copy = node('span', 'people-directory-card-copy');
            copy.append(node('strong', '', person.label), node('span', '', `${person.identifier} · ${person.role}`));
            const details = [person.program_code, person.section_name, person.department_code].filter(Boolean);
            if (details.length) copy.append(node('small', '', details.join(' · ')));
            const arrow = node('svg', 'people-directory-arrow'); arrow.setAttribute('viewBox', '0 0 20 20'); arrow.setAttribute('aria-hidden', 'true'); arrow.innerHTML = '<path d="m7 4 6 6-6 6"/>';
            card.append(avatar, copy, arrow); results.append(card);
        });
        const meta = payload.pagination || {};
        summary.textContent = `${meta.total || 0} ${Number(meta.total) === 1 ? 'person' : 'people'}${meta.pages > 1 ? ` · Page ${meta.page} of ${meta.pages}` : ''}`;
        renderPagination(meta);
    };
    const load = async (page = 1) => {
        const query = search.value.trim();
        if (query.length < 2) {
            controller?.abort(); results.replaceChildren(); pagination.replaceChildren(); summary.textContent = '';
            state.hidden = false; state.textContent = query.length === 1 ? 'Type one more character to search.' : 'Start typing to find someone in the campus directory.';
            return;
        }
        controller?.abort(); controller = new AbortController();
        state.hidden = false; state.textContent = 'Searching the campus directory…';
        const url = new URL(directory.dataset.endpoint, location.origin);
        url.searchParams.set('context', 'directory'); url.searchParams.set('q', query); url.searchParams.set('page', String(page));
        if (role.value) url.searchParams.set('role', role.value);
        try {
            const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'The directory could not be searched.');
            render(payload);
        } catch (error) {
            if (error.name === 'AbortError') return;
            results.replaceChildren(); pagination.replaceChildren(); summary.textContent = ''; state.hidden = false; state.textContent = error.message;
        }
    };
    search.addEventListener('input', () => { window.clearTimeout(timer); timer = window.setTimeout(() => load(1), 280); });
    role.addEventListener('change', () => load(1));
})();
