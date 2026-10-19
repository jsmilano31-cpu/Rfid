(() => {
    'use strict';
    const selections = new Map();
    let tooltipId = 0;
    const labels = { department: 'departments', program: 'programs', section: 'sections', course: 'courses', offering: 'class offerings', user: 'accounts', room: 'rooms', device: 'scanners', event: 'events', recollection: 'activities', role: 'roles', preset: 'presets' };
    const selector = '[data-bulk-type], [data-bulk-collection]';
    const stateFor = type => {
        if (!selections.has(type)) selections.set(type, { selected: new Map(), busy: false, message: '' });
        return selections.get(type);
    };
    const rowInputs = root => [...root.querySelectorAll('input[data-bulk-id]')].filter(input => input.getClientRects().length && !input.disabled);
    const checkbox = (id, label) => {
        const input = document.createElement('input'); input.type = 'checkbox'; input.dataset.bulkId = String(id); input.dataset.bulkLabel = label;
        input.className = 'bulk-checkbox'; input.setAttribute('aria-label', `Select ${label}`); return input;
    };
    const identify = (row, type) => {
        const button = row.querySelector(`[data-manage-academic="${type}"]`) || row.querySelector(`[data-manage-${type}]`);
        const id = row.dataset.bulkId || button?.dataset.entityId || button?.dataset[`${type}Id`] || row.dataset[`${type}Row`];
        if (!id || Number(id) < 1) return null;
        const label = row.dataset.bulkLabel || row.querySelector('strong')?.textContent.trim() || button?.getAttribute('aria-label') || `${type} #${id}`;
        return { id: String(id), label };
    };
    const update = (root, type) => {
        const state = stateFor(type), bar = root.querySelector(':scope > .bulk-toolbar'); if (!bar) return;
        root.querySelectorAll('input[data-bulk-id]').forEach(input => {
            input.checked = state.selected.has(input.dataset.bulkId);
            input.disabled = state.busy;
            const row = input.closest('tr, [data-bulk-item]'); row?.classList.toggle('is-bulk-selected', input.checked);
        });
        const visible = rowInputs(root), count = visible.filter(input => input.checked).length;
        root.querySelectorAll('[data-bulk-all]').forEach(input => { input.checked = visible.length > 0 && count === visible.length; input.indeterminate = count > 0 && count < visible.length; input.disabled = state.busy || !visible.length; });
        const setText = (element, value) => { if (element.textContent !== value) element.textContent = value; };
        setText(bar.querySelector('[data-bulk-count]'), `${state.selected.size} selected`);
        setText(bar.querySelector('[data-bulk-feedback]'), state.message);
        bar.querySelectorAll('button').forEach(button => {
            button.disabled = state.busy || !state.selected.size;
            const hint = button.closest('.bulk-action');
            const needsSelection = !state.selected.size;
            hint.toggleAttribute('data-needs-selection', needsSelection);
            if (needsSelection) {
                hint.setAttribute('tabindex', '0');
                hint.setAttribute('aria-label', `${button.textContent.trim()} unavailable`);
                hint.setAttribute('aria-describedby', hint.querySelector('[role="tooltip"]').id);
            } else {
                hint.removeAttribute('tabindex');
                hint.removeAttribute('aria-label');
                hint.removeAttribute('aria-describedby');
                hint.removeAttribute('data-tooltip-dismissed');
            }
        });
        setText(bar.querySelector('[data-bulk-delete]'), state.busy ? 'Deleting…' : 'Delete selected');
    };
    const updateType = type => document.querySelectorAll(selector).forEach(element => {
        if ((element.dataset.bulkType || element.dataset.bulkCollection) === type) update(element.matches('table') ? element.closest('.table-wrap') : element, type);
    });
    window.noteBulkDeleted = (type, ids) => {
        ids.forEach(id => stateFor(type).selected.delete(String(id)));
        updateType(type);
    };
    const request = async (url, options = {}) => {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' }, ...options });
        let payload; try { payload = await response.json(); } catch { throw new Error('The server returned an unexpected response. Refresh the page before trying again.'); }
        if (!response.ok && payload.code !== 'deletion_conflict') throw new Error(payload.message || 'The request could not be completed.');
        return payload;
    };
    const removeDeleted = (type, deleted) => {
        const ids = new Set(deleted.map(record => String(record.id)));
        document.querySelectorAll(selector).forEach(element => {
            if ((element.dataset.bulkType || element.dataset.bulkCollection) !== type) return;
            const instance = element._dataTableInstance;
            if (instance) {
                const indexes = instance.data.data.map((row, index) => ids.has(row.attributes?.['data-bulk-id']) ? index : -1).filter(index => index >= 0);
                if (indexes.length) {
                    const query = element.closest('.table-wrap').querySelector('.datatable-input')?.value || '', page = instance.currentPage;
                    instance.rows.remove(indexes);
                    // Search indexes refer to original positions; recompute after rows move.
                    if (query) instance.search(query);
                    instance.page(Math.min(page, instance.totalPages || 1));
                }
            } else element.querySelectorAll('[data-bulk-id]').forEach(row => {
                if (row.matches('input') || !ids.has(row.dataset.bulkId)) return;
                row.remove();
            });
        });
        if (type === 'user') document.querySelector('[data-live-filter]')?.dispatchEvent(new CustomEvent('live-filter:refresh'));
        document.dispatchEvent(new CustomEvent('bulk-delete:complete', { detail: { type, ids: [...ids] } }));
        if (type === 'role') {
            ids.forEach(id => document.getElementById(`role-permissions-${id}`)?.remove());
            document.querySelector('[data-role-tab].is-active')?.click();
            if (!document.querySelector('[data-role-tab].is-active')) document.querySelector('[data-role-tab]')?.click();
        }
    };
    const bind = (root, type) => {
        if (root._bulkBound) return;
        root._bulkBound = true;
        const bar = document.createElement('div'); bar.className = 'bulk-toolbar';
        bar.innerHTML = '<div class="bulk-selection-copy"><strong data-bulk-count aria-live="polite">0 selected</strong><small>Selection stays across pages and filters · up to 100</small></div><div class="bulk-actions"><button type="button" class="button button-secondary button-small" data-bulk-clear>Clear</button><button type="button" class="button button-secondary button-small" data-bulk-review>Review conflicts</button><button type="button" class="button button-danger button-small" data-bulk-delete>Delete selected</button></div><p class="bulk-feedback" data-bulk-feedback role="status"></p>';
        // A focusable wrapper makes the hint available even when the native button
        // cannot receive pointer or keyboard events. Create it only once per action.
        bar.querySelectorAll('.bulk-actions button').forEach(button => {
            button.disabled = true;
            const hint = document.createElement('span'); hint.className = 'bulk-action'; hint.setAttribute('role', 'group');
            const tooltip = document.createElement('span'); tooltip.className = 'bulk-action-tooltip';
            tooltip.id = `bulk-selection-hint-${++tooltipId}`; tooltip.setAttribute('role', 'tooltip'); tooltip.textContent = 'Select items first.';
            button.before(hint); hint.append(button, tooltip);
            hint.addEventListener('keydown', event => {
                if (event.key === 'Escape' && hint.hasAttribute('data-needs-selection')) { hint.setAttribute('data-tooltip-dismissed', ''); event.stopPropagation(); }
            });
            hint.addEventListener('pointerleave', () => { if (!hint.contains(document.activeElement)) hint.removeAttribute('data-tooltip-dismissed'); });
            hint.addEventListener('focusout', event => { if (!hint.contains(event.relatedTarget)) hint.removeAttribute('data-tooltip-dismissed'); });
        });
        root.prepend(bar);
        root.closest('details')?.addEventListener('toggle', () => update(root, type));
        root.addEventListener('input', event => {
            if (!event.target.matches('[data-bulk-search]')) return;
            const query = event.target.value.trim().toLocaleLowerCase();
            root.querySelectorAll('[data-bulk-item]').forEach(row => { row.hidden = !row.textContent.toLocaleLowerCase().includes(query); });
            update(root, type);
        });
        root.addEventListener('change', event => {
            const input = event.target, state = stateFor(type);
            if (!(input instanceof HTMLInputElement) || state.busy) return;
            if (input.matches('[data-bulk-all]')) {
                rowInputs(root).forEach(item => { if (input.checked && state.selected.size < 100) state.selected.set(item.dataset.bulkId, item.dataset.bulkLabel); else if (!input.checked) state.selected.delete(item.dataset.bulkId); });
            } else if (input.dataset.bulkId) {
                if (input.checked && (state.selected.has(input.dataset.bulkId) || state.selected.size < 100)) state.selected.set(input.dataset.bulkId, input.dataset.bulkLabel);
                else state.selected.delete(input.dataset.bulkId);
            } else return;
            state.message = state.selected.size >= 100 ? 'Selection limit reached. Delete this batch before selecting more.' : ''; updateType(type);
        });
        bar.addEventListener('click', async event => {
            const button = event.target.closest('button'); if (!button) return;
            const state = stateFor(type), endpoint = document.body.dataset.bulkEndpoint;
            if (button.disabled || state.busy || !state.selected.size) return;
            if (button.matches('[data-bulk-clear]')) { state.selected.clear(); state.message = ''; updateType(type); return; }
            if (button.matches('[data-bulk-review]')) {
                button.disabled = true;
                try { const url = new URL(endpoint, location.origin); url.searchParams.set('type', type); url.searchParams.set('ids', [...state.selected.keys()].join(',')); window.showDeletionConflict?.(await request(url)); }
                catch (error) { state.message = error.message; }
                finally { updateType(type); } return;
            }
            if (!button.matches('[data-bulk-delete]')) return;
            const entries = [...state.selected];
            const relatedWarning = { offering: 'Unused offerings also lose their enrollment and schedule links.', room: 'Deleting a room also removes its saved layout.', device: 'The scanner registration and its linked scanner records will be removed.', preset: 'Saved room layouts remain available when their preset is removed.' }[type] || '';
            const accepted = await window.confirmDestructiveAction({ title: `Delete ${entries.length} ${labels[type]}?`, name: entries.slice(0, 10).map(([, label]) => label).join(', ') + (entries.length > 10 ? `, and ${entries.length - 10} more` : ''), message: 'Deletable records will be permanently removed. This action cannot be undone.', warning: `Blocked records are retained and stay selected. Their dependencies will appear as clickable links. ${relatedWarning}`.trim() });
            if (!accepted) return;
            state.busy = true; state.message = ''; updateType(type);
            try {
                const body = new FormData(); body.set('_token', document.body.dataset.bulkToken); body.set('type', type); body.set('ids', entries.map(([id]) => id).join(','));
                const payload = await request(endpoint, { method: 'POST', body });
                (payload.deleted || []).forEach(record => state.selected.delete(String(record.id)));
                removeDeleted(type, payload.deleted || []); state.message = payload.message;
                if (payload.blocked_count || payload.targets?.length) window.showDeletionConflict?.(payload);
            } catch (error) { state.message = error.message; }
            finally { state.busy = false; updateType(type); }
        });
        const observer = new MutationObserver(() => update(root, type));
        observer.observe(root, { childList: true, subtree: true });
    };
    window.prepareBulkSelection = table => {
        const type = table.dataset.bulkType; if (!type || !labels[type]) return;
        const root = table.closest('.table-wrap'); if (!root) return;
        if (!table.querySelector('thead [data-bulk-all]')) {
            const heading = document.createElement('th'); heading.dataset.sortable = 'false'; heading.className = 'bulk-select-cell';
            const input = document.createElement('input'); input.type = 'checkbox'; input.className = 'bulk-checkbox'; input.dataset.bulkAll = ''; input.setAttribute('aria-label', 'Select records on this page'); heading.append(input); table.tHead?.rows[0]?.prepend(heading);
            // Keep the original sort column as columns shift by one.
            table.dataset.defaultSortColumn = String(Number(table.dataset.bulkOriginalSort || table.dataset.defaultSortColumn || 0) + 1);
            table.dataset.bulkOriginalSort = String(Number(table.dataset.defaultSortColumn) - 1);
            [...(table.tBodies[0]?.rows || [])].forEach(row => {
                const identity = identify(row, type);
                if (!identity) { const cell = row.cells[0]; if (cell?.colSpan > 1) cell.colSpan += 1; return; }
                row.dataset.bulkId = identity.id;
                const cell = document.createElement('td'); cell.className = 'bulk-select-cell'; cell.append(checkbox(identity.id, identity.label)); row.prepend(cell);
            });
        }
        bind(root, type); update(root, type);
    };
    const prepareCollection = root => {
        const type = root.dataset.bulkCollection; if (!labels[type]) return;
        bind(root, type);
        root.querySelectorAll('[data-bulk-item]').forEach(row => {
            if (!row.querySelector('[data-bulk-id]')) { const identity = identify(row, type); if (identity) row.prepend(checkbox(identity.id, identity.label)); }
            const search = root.querySelector('[data-bulk-search]');
            if (search) row.hidden = !row.textContent.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase());
        });
        if (!root.querySelector(':scope > .bulk-page-choice')) {
            const label = document.createElement('label'); label.className = 'bulk-page-choice'; const input = document.createElement('input'); input.type = 'checkbox'; input.className = 'bulk-checkbox'; input.dataset.bulkAll = ''; label.append(input, document.createTextNode(' Select visible records')); root.querySelector('.bulk-toolbar').after(label);
        }
        update(root, type);
    };
    document.addEventListener('DOMContentLoaded', () => {
        const scan = () => {
            document.querySelectorAll('table[data-bulk-type]').forEach(table => { if (!table._dataTableInstance) window.prepareBulkSelection(table); });
            document.querySelectorAll('[data-bulk-collection]').forEach(prepareCollection);
        };
        scan();
        const updateFilteredSelection = event => {
            if (!event.target.matches('.datatable-input, .table-search-fallback, [data-collection-search], [data-collection-filter]')) return;
            // Native fallback tables and card filters only change visibility attributes;
            // those changes do not trigger the child-list observers used for pagination.
            requestAnimationFrame(() => document.querySelectorAll(selector).forEach(element => update(element.matches('table') ? element.closest('.table-wrap') : element, element.dataset.bulkType || element.dataset.bulkCollection)));
        };
        document.addEventListener('input', updateFilteredSelection);
        document.addEventListener('change', updateFilteredSelection);
        let scheduled = false;
        new MutationObserver(() => { if (scheduled) return; scheduled = true; requestAnimationFrame(() => { scheduled = false; scan(); }); }).observe(document.querySelector('.main-content') || document.querySelector('main'), { childList: true, subtree: true });
    });
})();
