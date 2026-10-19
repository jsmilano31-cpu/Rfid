(() => {
    const root = document.querySelector('[data-global-search]');
    if (!root) return;
    const input = root.querySelector('[data-search-input]');
    const panel = root.querySelector('[data-search-panel]');
    const results = root.querySelector('[data-search-results]');
    const categories = root.querySelector('[data-search-categories]');
    const status = root.querySelector('[data-search-status]');
    const clear = root.querySelector('[data-search-clear]');
    const shortcut = root.querySelector('.global-search-shortcut');
    const labels = {pages:'Pages & actions',people:'People',academics:'Academics',facilities:'Rooms & devices',activities:'Campus activities',seat_plans:'Seat plans'};
    const icons = {
        page:'M3 4h7v7H3zm11 0h7v7h-7zM3 15h7v6H3zm11 0h7v6h-7z',
        action:'M12 4v16M4 12h16',
        person:'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4 21a8 8 0 0 1 16 0',
        department:'M3 21V6l9-4 9 4v15M8 10h2m4 0h2m-8 4h2m4 0h2M9 21v-4h6v4',
        program:'M2 8l10-5 10 5-10 5-10-5Zm4 4v6c4 3 8 3 12 0v-6',
        course:'M4 4h16v16H4zM8 9h8m-8 4h8m-8 4h5',
        section:'M4 4h16v16H4zM8 9h8m-8 4h8m-8 4h5',
        semester:'M4 5h16v16H4zM8 3v4m8-4v4M4 10h16',
        offering:'M4 5h16v16H4zM8 3v4m8-4v4M4 10h16m-10 5h8',
        room:'M4 3h16v18H4zM9 21v-5h6v5M8 8h2m4 0h2m-8 4h2m4 0h2',
        scanner:'M5 4h14v16H5zM8 9h8m-8 4h8m-8 4h4',
        event:'M4 5h16v16H4zM8 3v4m8-4v4M4 10h16m-8 3 1 2 2 .3-1.5 1.4.4 2-1.9-.9-1.9.9.4-2L9 15.3l2-.3 1-2Z',
        recollection:'M12 21s-8-4.7-8-11a4.5 4.5 0 0 1 8-2.7A4.5 4.5 0 0 1 20 10c0 6.3-8 11-8 11Zm0-12v7m-3.5-3.5h7',
        seat_plan:'M3 3h18v18H3zM6 6h4v4H6zm8 0h4v4h-4zM6 14h4v4H6zm8 0h4v4h-4z'
    };
    let pageIndex = [];
    try { pageIndex = JSON.parse(root.querySelector('[data-search-pages]').textContent); } catch (_) {}
    let groups = [];
    let category = '';
    let timer = 0;
    let controller = null;
    let serial = 0;
    let selected = -1;
    let currentQuery = '';
    const context = document.body.dataset.currentPage || '';
    const adminArea = document.body.dataset.adminArea === '1';
    const open = () => { panel.hidden = false; input.setAttribute('aria-expanded','true'); };
    const close = () => { panel.hidden = true; input.setAttribute('aria-expanded','false'); input.removeAttribute('aria-activedescendant'); selected = -1; };
    const allOptions = () => [...results.querySelectorAll('[role="option"]')];
    const score = (item, query) => {
        const title = item.title.toLocaleLowerCase();
        const other = (item.keywords || '').toLocaleLowerCase();
        const q = query.toLocaleLowerCase();
        const base = title === q ? 100 : title.startsWith(q) ? 70 : title.includes(q) ? 45 : other.includes(q) ? 25 : 0;
        return base ? base + (item.category === context ? 4 : 0) : 0;
    };
    const localPages = (query) => {
        if (!query) return (adminArea ? [...pageIndex].sort((a,b) => Number(b.category === context) - Number(a.category === context)) : pageIndex).slice(0, 8);
        return pageIndex.map(item => ({...item, score:score(item,query)})).filter(item => item.score > 0).sort((a,b) => b.score - a.score || a.title.localeCompare(b.title));
    };
    const renderCategories = () => {
        categories.replaceChildren();
        if (!currentQuery) { categories.hidden = true; return; }
        categories.hidden = false;
        for (const [key,label] of Object.entries({all:'All',...labels})) {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.className = 'global-search-category' + (category === (key === 'all' ? '' : key) ? ' is-active' : '');
            button.setAttribute('aria-pressed', category === (key === 'all' ? '' : key) ? 'true' : 'false');
            button.addEventListener('click', () => { category = key === 'all' ? '' : key; render(); });
            categories.append(button);
        }
    };
    const render = () => {
        results.replaceChildren();
        selected = -1;
        input.removeAttribute('aria-activedescendant');
        renderCategories();
        let count = 0;
        for (const group of groups) {
            if (category && category !== group.key) continue;
            if (!group.items.length) continue;
            const section = document.createElement('section');
            section.className = 'global-search-group';
            const heading = document.createElement('h3');
            heading.textContent = group.label || labels[group.key];
            section.append(heading);
            for (const item of group.items) {
                const link = document.createElement('a');
                link.className = 'global-search-option';
                link.href = item.url;
                link.id = `global-search-option-${count}`;
                link.setAttribute('role','option');
                link.setAttribute('aria-selected','false');
                const icon = document.createElement('span');
                icon.className = 'global-search-option-icon';
                icon.setAttribute('aria-hidden','true');
                const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
                svg.setAttribute('viewBox','0 0 24 24');
                const path = document.createElementNS('http://www.w3.org/2000/svg','path');
                path.setAttribute('d',icons[item.icon || item.type] || icons.page);
                svg.append(path);
                icon.append(svg);
                const copy = document.createElement('span');
                copy.className = 'global-search-option-copy';
                const title = document.createElement('strong'); title.textContent = item.title;
                const subtitle = document.createElement('small'); subtitle.textContent = item.subtitle;
                copy.append(title,subtitle); link.append(icon,copy); section.append(link); count++;
            }
            if (group.has_more) {
                const more = document.createElement('button');
                more.type = 'button';
                more.className = 'global-search-more';
                more.textContent = `Show more ${group.label.toLocaleLowerCase()}`;
                more.addEventListener('click', () => fetchResults(group.next_page, group.key));
                section.append(more);
            }
            results.append(section);
        }
        if (!count) status.textContent = currentQuery.length < 2 && currentQuery ? 'Type at least two characters to search records.' : (currentQuery ? 'No matching pages or records.' : 'Choose a shortcut to get started.');
        else status.textContent = `${count} result${count === 1 ? '' : 's'} shown`;
    };
    const setLocal = () => {
        currentQuery = input.value.trim().replace(/\s+/g,' ');
        clear.hidden = !currentQuery;
        shortcut.hidden = !!currentQuery;
        groups = [{key:'pages',label:labels.pages,items:localPages(currentQuery).slice(0,currentQuery ? 5 : 8),has_more:localPages(currentQuery).length > (currentQuery ? 5 : 8),next_page:2}];
        render(); open();
    };
    const fetchResults = async (page = 1, onlyCategory = '') => {
        if (currentQuery && currentQuery.length < 2) return;
        controller?.abort();
        controller = new AbortController();
        const requestNumber = ++serial;
        const url = new URL(root.dataset.endpoint, location.origin);
        url.searchParams.set('q',currentQuery);
        url.searchParams.set('context',context);
        url.searchParams.set('page',String(page));
        if (onlyCategory) url.searchParams.set('category',onlyCategory);
        status.textContent = page > 1 ? 'Loading more…' : 'Searching…';
        try {
            const response = await fetch(url,{credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json'}});
            if (response.status === 401) {
                status.replaceChildren(document.createTextNode('Session expired. '));
                const signIn = document.createElement('a');
                signIn.href = '/auth/login.php';
                signIn.textContent = 'Sign in again';
                status.append(signIn);
                return;
            }
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'Search is unavailable.');
            if (requestNumber !== serial || payload.query !== currentQuery) return;
            if (page === 1) groups = payload.groups;
            else {
                const returned = payload.groups[0];
                const existing = groups.find(group => group.key === returned?.key);
                if (existing) { existing.items.push(...returned.items); existing.has_more = returned.has_more; existing.next_page = returned.next_page; }
            }
            render();
        } catch (error) {
            if (error.name !== 'AbortError') {
                status.replaceChildren(document.createTextNode(error.message + ' '));
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'global-search-more';
                retry.textContent = 'Retry search';
                retry.addEventListener('click', () => fetchResults(page, onlyCategory));
                status.append(retry);
            }
        }
    };
    const move = (direction) => {
        const options = allOptions();
        if (!options.length) return;
        selected = (selected + direction + options.length) % options.length;
        options.forEach((option,index) => { option.classList.toggle('is-selected',index === selected); option.setAttribute('aria-selected',index === selected ? 'true' : 'false'); });
        input.setAttribute('aria-activedescendant',options[selected].id);
        options[selected].scrollIntoView({block:'nearest'});
    };
    input.addEventListener('focus', () => { if (panel.hidden) { setLocal(); fetchResults(); } });
    input.addEventListener('input', () => { clearTimeout(timer); controller?.abort(); serial++; setLocal(); if (!currentQuery || currentQuery.length >= 2) timer = setTimeout(fetchResults, currentQuery ? 250 : 0); });
    input.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); if (panel.hidden) setLocal(); move(event.key === 'ArrowDown' ? 1 : -1); }
        else if (event.key === 'Enter' && !panel.hidden) { const option = allOptions()[selected < 0 ? 0 : selected]; if (option) { event.preventDefault(); location.href = option.href; } }
        else if (event.key === 'Escape') { event.preventDefault(); close(); input.blur(); }
    });
    clear.addEventListener('click', () => { input.value = ''; input.focus(); input.dispatchEvent(new Event('input',{bubbles:true})); });
    document.addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); input.focus(); input.select(); }
        if (event.key === 'Escape' && !panel.hidden) close();
    });
    document.addEventListener('pointerdown', event => { if (!root.contains(event.target)) close(); });

    document.addEventListener('DOMContentLoaded', () => {
        const params = new URLSearchParams(location.search);
        let target = null;
        const eventId = Number(params.get('focus_event'));
        const recollectionId = Number(params.get('focus_recollection'));
        if (eventId > 0) target = document.querySelector(`[data-universal-record="event:${eventId}"]`);
        if (recollectionId > 0) target = document.querySelector(`[data-universal-record="recollection:${recollectionId}"]`);
        const focusType = params.get('focus_type');
        const focusId = Number(params.get('focus_id'));
        if (focusType && focusId > 0 && ['department','program','course','section','semester','room'].includes(focusType)) {
            const candidates = [...document.querySelectorAll('[data-timetable-event]')];
            target = candidates.find(item => Number(item.dataset[`${focusType}Id`]) === focusId) || null;
        }
        if (target) {
            target.hidden = false;
            target.closest('[data-schedule-panel]')?.removeAttribute('hidden');
            target.scrollIntoView({behavior:'smooth',block:'center'});
            target.classList.add('universal-search-focus');
            if (focusType === 'room' || focusType === 'section') {
                setTimeout(() => target.querySelector(`[data-timetable-inline-entity="${focusType}"]`)?.click(), 250);
            } else if (target.tabIndex >= 0) {
                target.focus({preventScroll:true});
            }
        }
        const hash = location.hash;
        if (hash) {
            const section = document.getElementById(hash.slice(1));
            if (section?.matches('dialog')) {
                if (!section.open) section.showModal();
                section.querySelector('input:not([type="hidden"]), select, textarea, button')?.focus();
            } else if (section) {
                section.scrollIntoView({behavior:'smooth',block:'start'});
                section.querySelector('input:not([type="hidden"]), select, textarea, button')?.focus({preventScroll:true});
            }
        }
    });

    const account = document.querySelector('[data-account-menu]');
    const trigger = account?.querySelector('[data-account-trigger]');
    const accountPanel = account?.querySelector('[data-account-panel]');
    const toggleAccount = (show) => { accountPanel.hidden = !show; trigger.setAttribute('aria-expanded', show ? 'true' : 'false'); if (show) accountPanel.querySelector('a,button')?.focus(); };
    trigger?.addEventListener('click', () => toggleAccount(accountPanel.hidden));
    account?.addEventListener('keydown', event => { if (event.key === 'Escape') { toggleAccount(false); trigger.focus(); } });
    document.addEventListener('pointerdown', event => { if (account && !account.contains(event.target)) toggleAccount(false); });
})();
