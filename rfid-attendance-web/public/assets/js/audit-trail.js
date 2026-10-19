(() => {
    'use strict';
    const root = document.querySelector('[data-audit-workspace]');
    const form = root?.querySelector('[data-audit-filters]');
    if (!form) return;
    const endpoint = root.dataset.endpoint;
    const body = root.querySelector('[data-audit-rows]');
    const results = root.querySelector('[data-audit-results]');
    const status = root.querySelector('[data-audit-status]');
    const dialog = document.querySelector('[data-audit-dialog]');
    let pending, detailPending, timer, currentCursor = new URL(location.href).searchParams.get('cursor') || '';
    const node = (tag, text, className) => { const el = document.createElement(tag); if (text != null) el.textContent = String(text); if (className) el.className = className; return el; };
    const filters = () => { const values = new URLSearchParams(new FormData(form)); values.delete('page'); if (currentCursor) values.set('cursor', currentCursor); return values; };
    const fail = (container, error, retry) => {
        container.replaceChildren(node('span', error.message));
        if (error.status === 401) { const link = node('a', 'Sign in again', 'button button-secondary'); link.href = '/auth/login.php'; container.append(link); }
        else if (error.status !== 403) { const button = node('button', 'Retry', 'button button-secondary'); button.type = 'button'; button.addEventListener('click', retry); container.append(button); }
    };
    async function request(url, signal) {
        const response = await fetch(url, {signal, headers:{Accept:'application/json'}});
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload?.ok) { const error = new Error(payload?.message || 'History could not be loaded. Please retry.'); error.status = response.status; throw error; }
        return payload;
    }
    function syncSort() {
        root.querySelectorAll('[data-audit-sort]').forEach(button => {
            const selected = button.dataset.auditSort === form.elements.sort.value;
            button.classList.toggle('is-active', selected);
            button.closest('th').setAttribute('aria-sort', selected ? (form.elements.direction.value === 'asc' ? 'ascending' : 'descending') : 'none');
            button.setAttribute('aria-label', `Sort by ${button.textContent.trim()}, ${selected && form.elements.direction.value === 'asc' ? 'descending' : 'ascending'}`);
        });
    }
    async function load() {
        pending?.abort(); pending = new AbortController(); const signal = pending.signal;
        results.setAttribute('aria-busy', 'true'); status.classList.remove('is-error'); status.textContent = 'Loading history…'; syncSort();
        const values = filters();
        const url = new URL(location.href); url.search = values; url.searchParams.set('page','audit-trail'); url.searchParams.delete('entry'); history.replaceState(null,'',url);
        try {
            const data = await request(`${endpoint}?${values}`, signal); if (signal.aborted) return;
            form.elements.sort.value = data.filters.sort; form.elements.direction.value = data.filters.direction; syncSort();
            body.replaceChildren();
            for (const entry of data.items) {
                const row = node('tr'); row.dataset.auditEntry = entry.id;
                const time = node('td'); const stamp = node('time', entry.time); stamp.dateTime = entry.iso; time.append(stamp, node('span', `#${entry.id}`, 'audit-cell-subtitle')); row.append(time);
                const who = node('td'); const actor = node('button',entry.actor_name,'audit-actor-link'); actor.type='button'; actor.title='Show changes by this actor'; actor.addEventListener('click',()=>{const value=entry.actor_id?String(entry.actor_id):'system';if(![...form.elements.actor.options].some(option=>option.value===value)){const option=node('option',entry.actor_name);option.value=value;form.elements.actor.append(option);}form.elements.actor.value=value;window.refreshSearchableSelect?.(form.elements.actor);form.elements.actor.dispatchEvent(new Event('change',{bubbles:true}));}); who.append(actor,node('span', [entry.actor_school_id, entry.actor_role].filter(Boolean).join(' · '), 'audit-cell-subtitle')); row.append(who);
                const action = node('td'); const link = node('a', entry.title, 'audit-entry-link'); link.href = `/admin/?page=audit-trail&entry=${entry.id}`; link.dataset.auditOpen = entry.id; action.append(link); if (entry.operation) { const badge = node('span', {INSERT:'Created',UPDATE:'Updated',DELETE:'Deleted'}[entry.operation], 'audit-operation'); badge.dataset.operation = entry.operation; action.append(node('br'), badge); } row.append(action);
                row.append(node('td', entry.entity_label), node('td', entry.entity_id || '—'), node('td', entry.source === 'database' ? 'Database change' : 'Application action'));
                const details = node('td'); const more = node('a', 'View details', 'entity-link'); more.href = link.href; more.dataset.auditOpen = entry.id; details.append(more); row.append(details); body.append(row);
            }
            if (!data.items.length) { const row = node('tr'); const empty = node('td','No entries match these filters. Try a broader date range or clear the filters.','empty-table-cell'); empty.colSpan = 7; row.append(empty); body.append(row); }
            status.textContent = data.items.length ? 'History loaded. Newest entries appear first unless you choose another sort.' : 'No matching entries.';
            root.querySelector('[data-audit-count]').textContent = `${data.items.length} entries shown${data.next ? ' · more available' : ''}`;
            for (const way of ['previous','next']) { const link = root.querySelector(`[data-audit-${way}]`); link.hidden = !data[way]; link.dataset.cursor = data[way] || ''; const destination = new URL(url); if (data[way]) destination.searchParams.set('cursor',data[way]); link.href = destination.href; }
        } catch (error) { if (error.name !== 'AbortError') { status.classList.add('is-error'); fail(status,error,load); } }
        finally { if (!signal.aborted) results.setAttribute('aria-busy','false'); }
    }
    async function detail(id) {
        detailPending?.abort(); detailPending = new AbortController(); const signal = detailPending.signal;
        const container = dialog.querySelector('[data-audit-detail-body]'); container.replaceChildren(node('p','Loading entry…','muted'));
        if (!dialog.open) dialog.showModal();
        dialog.querySelector('[data-audit-permalink]').href = `/admin/?page=audit-trail&entry=${id}`;
        try {
            const data = await request(`${endpoint}?id=${encodeURIComponent(id)}`,signal); if (signal.aborted) return;
            const entry = data.entry; dialog.querySelector('#audit-entry-title').textContent = entry.title; dialog.querySelector('[data-audit-detail-summary]').textContent = `${entry.entity_label} · Record ${entry.entity_id || '—'} · ${entry.time}`;
            container.replaceChildren(); const meta = node('dl',null,'audit-entry-meta');
            for (const [label,value] of [['Actor',entry.actor_name],['School ID / role',[entry.actor_school_id,entry.actor_role].filter(Boolean).join(' · ')],['Server timestamp',entry.iso],['History source',entry.source==='database'?'Database change':'Application action'],['IP address',entry.ip_address || 'Not recorded'],['Request ID',entry.request_id || 'Not recorded in legacy history']]) { const item = node('div'); item.append(node('dt',label),node('dd',value || '—')); meta.append(item); } container.append(meta);
            if (entry.changes.length) {
                container.append(node('h3','Recorded changes')); const wrap = node('div',null,'table-wrap'); const table = node('table',null,'audit-changes'); const head = node('thead'); const row = node('tr'); for (const text of ['Field','Before','After']) row.append(node('th',text)); head.append(row); table.append(head); const rows = node('tbody');
                for (const change of entry.changes) { const row = node('tr'); row.append(node('td',change.field.replaceAll('_',' '))); for (const key of ['before','after']) { const value = change[key] == null ? '—' : typeof change[key] === 'object' ? JSON.stringify(change[key],null,2) : String(change[key]); row.append(node('td',value,value==='[redacted]'?'is-redacted':`${key}-value`)); } rows.append(row); } table.append(rows); wrap.append(table); container.append(wrap);
            } else container.append(node('p','This application action contains a summary. Row-level before/after values are available for database changes recorded after tracking was enabled.','muted'));
            if (entry.details) container.append(node('h3','Activity details'),node('div',entry.details,'audit-detail-note'));
        } catch (error) { if (error.name !== 'AbortError') fail(container,error,()=>detail(id)); }
    }
    const reload = () => { currentCursor = ''; clearTimeout(timer); load(); };
    form.addEventListener('submit',event=>{event.preventDefault();reload();});
    form.addEventListener('input',event=>{ if (event.target.name==='q' || event.target.name==='record') {clearTimeout(timer);timer=setTimeout(reload,250);} });
    form.addEventListener('change',event=>{if (event.target.matches('select,input[type=date]')) reload();});
    root.querySelector('[data-audit-refresh]').addEventListener('click',reload);
    root.querySelector('[data-audit-clear]').addEventListener('click',event=>{event.preventDefault();location.href=event.currentTarget.href;});
    root.querySelectorAll('[data-audit-sort]').forEach(button=>button.addEventListener('click',()=>{const key=button.dataset.auditSort;form.elements.direction.value=form.elements.sort.value===key && form.elements.direction.value==='asc'?'desc':'asc';form.elements.sort.value=key;reload();}));
    for (const way of ['previous','next']) root.querySelector(`[data-audit-${way}]`).addEventListener('click',event=>{if (event.ctrlKey||event.metaKey||event.shiftKey||event.altKey) return;event.preventDefault();currentCursor=event.currentTarget.dataset.cursor;load();});
    body.addEventListener('click',event=>{if (event.ctrlKey||event.metaKey||event.shiftKey||event.altKey) return;const link=event.target.closest('[data-audit-open]'); const row=event.target.closest('[data-audit-entry]'); if (!link && event.target.closest('a,button,input')) return; if (link||row) {event.preventDefault();detail(link?.dataset.auditOpen || row.dataset.auditEntry);} });
    dialog.addEventListener('close',()=>detailPending?.abort());
    const entry = root.dataset.entry; load(); if (Number(entry)>0) detail(entry);
})();
