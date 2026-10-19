document.addEventListener('DOMContentLoaded', () => {
    const showOneTimeScannerCredentials = (credentials) => {
        if (!credentials?.api_key) return;
        const device = credentials.device || credentials;
        const dialog = document.createElement('dialog');
        dialog.className = 'app-dialog scanner-credentials-dialog';
        dialog.innerHTML = '<div class="app-dialog-shell"><header class="app-dialog-header"><div><p class="eyebrow">Scanner ready</p><h2>Save the API key</h2><p class="muted">This key is shown once. Copy it into the scanner configuration.</p></div></header><div class="app-dialog-body stack"><div class="scanner-credential-device"><span>Device code</span><strong data-code></strong><small data-room></small></div><div class="scanner-secret-panel"><div><span class="field-label">API key</span></div><div class="scanner-secret-row"><code data-key></code><button type="button" class="button button-secondary" data-copy>Copy key</button></div></div><div class="alert alert-warning"><strong>Keep this key private.</strong> Closing this window permanently hides it.</div><div class="form-actions"><span class="ajax-form-status" data-copy-status></span><button type="button" class="button button-primary" data-close>I\'ve saved the key</button></div></div></div>';
        dialog.querySelector('[data-code]').textContent = device.device_code || '';
        dialog.querySelector('[data-room]').textContent = device.room_code ? `Assigned to ${device.room_code}` : 'Available for event use';
        dialog.querySelector('[data-key]').textContent = credentials.api_key;
        dialog.querySelector('[data-copy]').addEventListener('click', async () => {
            await navigator.clipboard.writeText(credentials.api_key);
            dialog.querySelector('[data-copy-status]').textContent = 'API key copied.';
        });
        dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => dialog.remove());
        document.body.append(dialog);
        dialog.showModal();
    };
    const pendingScannerCredentials = document.querySelector('[data-pending-scanner-credentials]');
    if (pendingScannerCredentials) {
        try { showOneTimeScannerCredentials(JSON.parse(pendingScannerCredentials.dataset.pendingScannerCredentials)); } catch {}
    }
    const sidebar = document.querySelector('#sidebar');
    document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', () => {
        sidebar?.classList.toggle('is-open');
    });

    const pageContent = document.querySelector('[data-page-content]');
    const pageSkeleton = document.querySelector('[data-page-loading-skeleton]');

    const clearPageLoading = () => {
        document.body.classList.remove('is-page-loading');
        pageContent?.removeAttribute('aria-busy');
        pageSkeleton?.setAttribute('aria-hidden', 'true');
    };

    const showPageLoading = () => {
        if (!pageContent || !pageSkeleton) return;
        document.body.classList.add('is-page-loading');
        pageContent.setAttribute('aria-busy', 'true');
        pageSkeleton.setAttribute('aria-hidden', 'false');
        sidebar?.classList.remove('is-open');
    };

    const isPageNavigation = (link) => {
        if (!link || link.hasAttribute('download') || link.closest('[data-no-page-loader], [data-live-page]')) return false;
        if (link.target && link.target.toLowerCase() !== '_self') return false;

        let destination;
        try {
            destination = new URL(link.href, window.location.href);
        } catch {
            return false;
        }

        if (!['http:', 'https:'].includes(destination.protocol) || destination.origin !== window.location.origin) return false;
        const current = new URL(window.location.href);
        return destination.pathname !== current.pathname
            || destination.search !== current.search
            || (!destination.hash && current.hash);
    };

    document.addEventListener('click', (event) => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!isPageNavigation(link)) return;

        if (!event.defaultPrevented) showPageLoading();
    });

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form');
        const submitTarget = event.submitter?.formTarget || form?.target || '';
        if (!form || form.method.toLowerCase() === 'dialog' || form.closest('[data-no-page-loader]')) return;
        if (submitTarget && submitTarget.toLowerCase() !== '_self') return;

        window.queueMicrotask(() => {
            if (!event.defaultPrevented) showPageLoading();
        });
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) clearPageLoading();
    });

    const destructiveDialog = document.createElement('dialog');
    destructiveDialog.className = 'app-dialog destructive-confirm-dialog';
    destructiveDialog.setAttribute('aria-labelledby', 'destructive-confirm-title');
    destructiveDialog.innerHTML = '<div class="app-dialog-shell"><header class="app-dialog-header"><div><p class="eyebrow">Permanent action</p><h2 id="destructive-confirm-title">Delete record?</h2><p class="muted" data-destructive-message></p></div><button type="button" class="dialog-close" data-destructive-cancel aria-label="Cancel deletion"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"></path></svg></button></header><div class="app-dialog-body"><div class="alert alert-warning" data-destructive-warning></div><p class="destructive-confirm-name" data-destructive-name></p><div class="form-actions"><button type="button" class="button button-secondary" data-destructive-cancel>Keep record</button><button type="button" class="button button-danger" data-destructive-accept>Delete permanently</button></div></div></div>';
    document.body.append(destructiveDialog);
    let destructiveResolver = null;
    const settleDestructiveConfirmation = (confirmed) => {
        if (!destructiveResolver) return;
        const resolve = destructiveResolver;
        destructiveResolver = null;
        resolve(confirmed);
    };
    destructiveDialog.querySelectorAll('[data-destructive-cancel]').forEach((button) => button.addEventListener('click', () => {
        settleDestructiveConfirmation(false);
        destructiveDialog.close();
    }));
    destructiveDialog.querySelector('[data-destructive-accept]').addEventListener('click', () => {
        settleDestructiveConfirmation(true);
        destructiveDialog.close();
    });
    destructiveDialog.addEventListener('cancel', () => settleDestructiveConfirmation(false));
    destructiveDialog.addEventListener('close', () => settleDestructiveConfirmation(false));
    window.confirmDestructiveAction = ({ title = 'Delete record?', name = '', message = 'This action cannot be undone.', warning = 'Related records may prevent deletion.' } = {}) => {
        if (destructiveResolver) settleDestructiveConfirmation(false);
        destructiveDialog.querySelector('#destructive-confirm-title').textContent = title;
        destructiveDialog.querySelector('[data-destructive-message]').textContent = message;
        destructiveDialog.querySelector('[data-destructive-warning]').textContent = warning;
        destructiveDialog.querySelector('[data-destructive-name]').textContent = name;
        destructiveDialog.showModal();
        window.requestAnimationFrame(() => destructiveDialog.querySelector('[data-destructive-cancel]').focus());
        return new Promise((resolve) => { destructiveResolver = resolve; });
    };

    document.querySelectorAll('[data-section-navigation]').forEach((page) => {
        const links = [...page.querySelectorAll('.section-navigation-link[href^="#"]')];
        if (!links.length) return;

        const setActive = (id) => {
            links.forEach((link) => {
                const active = link.hash === `#${id}`;
                link.classList.toggle('is-active', active);
                if (active) link.setAttribute('aria-current', 'location');
                else link.removeAttribute('aria-current');
            });
        };

        let frame;
        const updateFromScroll = () => {
            window.cancelAnimationFrame(frame);
            frame = window.requestAnimationFrame(() => {
                const sections = links.map((link) => document.querySelector(link.hash)).filter(Boolean);
                if (!sections.length) return;
                const marker = Math.min(160, window.innerHeight * .28);
                let current = sections[0];
                sections.forEach((section) => {
                    if (section.getBoundingClientRect().top <= marker) current = section;
                });
                if (window.scrollY > 0 && window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 4) {
                    current = sections[sections.length - 1];
                }
                setActive(current.id);
            });
        };

        links.forEach((link) => {
            link.addEventListener('click', (event) => {
                const target = document.querySelector(link.hash);
                if (!target) return;
                event.preventDefault();
                target.scrollIntoView({
                    behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                    block: 'start',
                });
                window.history.replaceState(null, '', link.hash);
                setActive(target.id);
            });
        });

        window.addEventListener('scroll', updateFromScroll, { passive: true });
        window.addEventListener('resize', updateFromScroll);
        updateFromScroll();
    });

    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-dialog-open]');
        if (opener) {
            const dialog = document.querySelector(opener.dataset.dialogOpen);
            if (dialog && !dialog.open) {
                dialog.showModal();
                window.requestAnimationFrame(() => {
                    dialog.querySelector('input:not([type="hidden"]), select, textarea')?.focus();
                });
            }
            return;
        }

        const closer = event.target.closest('[data-dialog-close]');
        if (closer) closer.closest('dialog')?.close();
    });
    document.querySelectorAll('dialog[data-modal]').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
        dialog.addEventListener('close', () => {
            dialog.querySelectorAll('form').forEach((form) => {
                form.reset();
                form.querySelectorAll('select').forEach((select) => {
                    select._choicesInstance?.setChoiceByValue(select.value);
                });
            });
        });
    });

    document.querySelectorAll('[data-permission-workspace]').forEach((workspace) => {
        const activate = (tab) => {
            const tabs = [...workspace.querySelectorAll('[data-role-tab]')];
            const panels = [...workspace.querySelectorAll('[data-role-panel]')];
            tabs.forEach((item) => {
                const active = item === tab;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
                item.tabIndex = active ? 0 : -1;
            });
            panels.forEach((panel) => { panel.hidden = panel.dataset.rolePanel !== tab.dataset.roleTab; });
        };
        workspace.addEventListener('click', (event) => {
            const tab = event.target.closest('[data-role-tab]');
            if (tab && workspace.contains(tab)) activate(tab);
        });
        workspace.addEventListener('keydown', (event) => {
            const tab = event.target.closest('[data-role-tab]');
            if (!tab || !['ArrowDown', 'ArrowUp', 'ArrowRight', 'ArrowLeft'].includes(event.key)) return;
            event.preventDefault();
            const tabs = [...workspace.querySelectorAll('[data-role-tab]')];
            const index = tabs.indexOf(tab);
            const direction = ['ArrowDown', 'ArrowRight'].includes(event.key) ? 1 : -1;
            const next = tabs[(index + direction + tabs.length) % tabs.length];
            activate(next);
            next.focus();
        });
        const initialTab = workspace.querySelector('[data-role-tab].is-active') || workspace.querySelector('[data-role-tab]');
        if (initialTab) activate(initialTab);
    });

    const roleMetadataDialog = document.querySelector('#edit-role-dialog');
    if (roleMetadataDialog) {
        const roleDeleteButton = document.createElement('button');
        roleDeleteButton.type = 'button';
        roleDeleteButton.className = 'button button-danger';
        roleDeleteButton.textContent = 'Delete role';
        roleMetadataDialog.querySelector('.form-actions')?.prepend(roleDeleteButton);
        roleDeleteButton.addEventListener('click', async () => {
            const form = roleMetadataDialog.querySelector('[data-role-metadata-form]');
            const roleName = form.elements.name.value.trim();
            const confirmed = window.confirmDestructiveAction
                ? await window.confirmDestructiveAction({ title: 'Delete custom role?', name: roleName, message: `Are you sure you want to permanently delete ${roleName}?`, warning: 'Built-in roles and roles still assigned to users cannot be deleted.' })
                : window.confirm(`Are you sure you want to permanently delete ${roleName}?`);
            if (!confirmed) return;
            form.elements.action.value = 'delete_role';
            form.requestSubmit();
            form.elements.action.value = 'update_role_metadata';
        });
    }
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-manage-role]');
        if (!button || !roleMetadataDialog) return;
        const form = roleMetadataDialog.querySelector('[data-role-metadata-form]');
        form.elements.role_id.value = button.dataset.roleId;
        form.elements.name.value = button.dataset.roleName || '';
        form.elements.description.value = button.dataset.roleDescription || '';
        roleMetadataDialog.showModal();
        window.requestAnimationFrame(() => form.elements.name.focus());
    });

    document.querySelectorAll('[data-live-filter]').forEach((form) => {
        let timer;
        let controller;
        let requestNumber = 0;
        const cache = new Map();
        const status = form.querySelector('[data-live-status]');
        const render = (html) => {
            const currentTarget = document.querySelector(form.dataset.liveTarget);
            if (!currentTarget) return;
            const fragment = document.createRange().createContextualFragment(html);
            const replacement = fragment.querySelector(form.dataset.liveTarget);
            if (replacement) currentTarget.replaceWith(replacement);
        };
        const publicUrl = (pageNumber) => {
            const params = new URLSearchParams(new FormData(form));
            if (form.dataset.livePageParam) params.set(form.dataset.livePageParam, String(pageNumber));
            const url = new URL(window.location.href);
            url.search = params.toString();
            return url;
        };
        const load = async (pageNumber = 1) => {
            window.clearTimeout(timer);
            controller?.abort();
            controller = new AbortController();
            const currentRequest = ++requestNumber;
            const requestUrl = new URL(form.dataset.liveUrl, window.location.origin);
            const params = new URLSearchParams(new FormData(form));
            params.set('partial', form.dataset.livePartial);
            if (form.dataset.livePageParam) params.set(form.dataset.livePageParam, String(pageNumber));
            requestUrl.search = params.toString();
            const cacheKey = requestUrl.toString();
            status.textContent = 'Searching…';
            document.querySelector(form.dataset.liveTarget)?.setAttribute('aria-busy', 'true');
            try {
                let html = cache.get(cacheKey);
                if (!html) {
                    const response = await fetch(requestUrl, {
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'fetch' },
                        signal: controller.signal,
                    });
                    if (!response.ok) throw new Error(`Search request failed (${response.status})`);
                    html = await response.text();
                    cache.set(cacheKey, html);
                    if (cache.size > 30) cache.delete(cache.keys().next().value);
                }
                if (currentRequest !== requestNumber) return;
                render(html);
                window.history.replaceState({}, '', publicUrl(pageNumber));
                status.textContent = 'Results updated';
            } catch (error) {
                if (error.name !== 'AbortError') status.textContent = 'Unable to update results';
            } finally {
                if (currentRequest === requestNumber) {
                    document.querySelector(form.dataset.liveTarget)?.setAttribute('aria-busy', 'false');
                    window.setTimeout(() => { if (status.textContent === 'Results updated') status.textContent = ''; }, 1200);
                }
            }
        };
        const schedule = () => {
            window.clearTimeout(timer);
            controller?.abort();
            timer = window.setTimeout(() => load(1), 250);
        };
        form.addEventListener('submit', (event) => { event.preventDefault(); load(1); });
        form.querySelectorAll('input[type="search"]').forEach((input) => input.addEventListener('input', schedule));
        form.querySelectorAll('select').forEach((select) => select.addEventListener('change', () => load(1)));
        form.querySelector('[data-live-clear]')?.addEventListener('click', (event) => {
            event.preventDefault();
            form.reset();
            form.querySelectorAll('select').forEach((select) => {
                select._choicesInstance?.setChoiceByValue('');
            });
            load(1);
        });
        form.addEventListener('live-filter:refresh', () => {
            cache.clear();
            const currentPage = form.dataset.livePageParam
                ? Number(new URL(window.location.href).searchParams.get(form.dataset.livePageParam) || 1)
                : 1;
            load(currentPage);
        });
        document.addEventListener('click', (event) => {
            const link = event.target.closest(`${form.dataset.liveTarget} [data-live-page]`);
            if (!link) return;
            event.preventDefault();
            load(Number(link.dataset.livePage));
        });
    });

    document.addEventListener('click', (event) => {
        const element = event.target.closest('[data-confirm]');
        if (element && !window.confirm(element.dataset.confirm || 'Continue with this action?')) {
            event.preventDefault();
        }
    });

    if (window.flatpickr) {
        document.querySelectorAll('input[type="date"], input[type="time"]').forEach((input) => {
            const isTime = input.type === 'time';
            const initialValue = input.value;
            input.type = 'text';
            input.autocomplete = 'off';
            const dialog = input.closest('dialog');
            const instance = window.flatpickr(input, {
                allowInput: true,
                altInput: true,
                altInputClass: `date-time-input ${isTime ? 'time-input' : 'date-input'}`,
                altFormat: isTime ? 'h:i K' : 'F j, Y',
                static: true,
                dateFormat: isTime ? 'H:i' : 'Y-m-d',
                defaultDate: initialValue || null,
                disableMobile: true,
                enableTime: isTime,
                minuteIncrement: 5,
                noCalendar: isTime,
                time_24hr: false,
            });
            input.dataset.pickerType = isTime ? 'time' : 'date';
            input.form?.addEventListener('reset', () => {
                window.setTimeout(() => instance.setDate(input.value || null, false, isTime ? 'H:i' : 'Y-m-d'));
            });
        });
    }

    document.querySelectorAll('[data-image-preview-input]').forEach((input) => {
        input.addEventListener('change', () => {
            const file = input.files?.[0];
            const preview = input.closest('form')?.querySelector('[data-user-profile-image], [data-event-banner-preview]');
            if (!file || !preview) return;
            const objectUrl = URL.createObjectURL(file);
            preview.src = objectUrl;
            preview.hidden = false;
            preview.onload = () => URL.revokeObjectURL(objectUrl);
        });
    });

    const initializeDataTable = (table) => {
        if (window.simpleDatatables?.DataTable) {
            table._dataTableInstance = new window.simpleDatatables.DataTable(table, {
                perPage: 10,
                perPageSelect: [10, 25, 50, 100],
                searchable: true,
                sortable: true,
                labels: {
                    placeholder: 'Search records…',
                    perPage: 'records per page',
                    noRows: 'No records found',
                    noResults: 'No matching records',
                    info: 'Showing {start}–{end} of {rows} records',
                },
            });
            return;
        }
        if (!table.closest('.table-wrap')?.previousElementSibling?.classList.contains('table-search-fallback')) {
            const input = document.createElement('input');
            input.type = 'search';
            input.className = 'datatable-input table-search-fallback';
            input.placeholder = 'Search records…';
            input.setAttribute('aria-label', 'Search table records');
            table.closest('.table-wrap').before(input);
            input.addEventListener('input', () => {
                const query = input.value.trim().toLocaleLowerCase();
                [...(table.tBodies[0]?.rows ?? [])].forEach((row) => {
                    row.hidden = query !== '' && !row.textContent.toLocaleLowerCase().includes(query);
                });
            });
        }
    };
    document.querySelectorAll('.table-wrap table:not([data-no-datatable])').forEach(initializeDataTable);

    document.querySelectorAll('[data-weekly-timetable]').forEach((timetable) => {
        const timelineStart = Number(timetable.dataset.timetableStart || 0);
        const pixelsPerMinute = Number(timetable.dataset.pixelsPerMinute || 1);
        const placeCluster = (cluster) => {
            const laneEnds = [];
            cluster.forEach((item) => {
                let lane = laneEnds.findIndex((endMinute) => endMinute <= item.start);
                if (lane === -1) lane = laneEnds.length;
                laneEnds[lane] = item.end;
                item.lane = lane;
            });
            const laneCount = Math.max(1, laneEnds.length);
            cluster.forEach((item) => {
                item.element.style.setProperty('--event-lane', item.lane);
                item.element.style.setProperty('--event-lanes', laneCount);
            });
        };

        timetable.querySelectorAll('[data-timetable-day]').forEach((column) => {
            const entries = [...column.querySelectorAll('[data-timetable-event]')]
                .map((element) => ({
                    element,
                    start: Number(element.dataset.startMinute),
                    end: Number(element.dataset.endMinute),
                    lane: 0,
                }))
                .filter((item) => Number.isFinite(item.start) && Number.isFinite(item.end) && item.end > item.start)
                .sort((first, second) => first.start - second.start || first.end - second.end);

            let cluster = [];
            let clusterEnd = -Infinity;
            entries.forEach((item) => {
                if (cluster.length && item.start >= clusterEnd) {
                    placeCluster(cluster);
                    cluster = [];
                    clusterEnd = -Infinity;
                }
                cluster.push(item);
                clusterEnd = Math.max(clusterEnd, item.end);
                const top = Math.max(0, (item.start - timelineStart) * pixelsPerMinute) + 2;
                const height = Math.max(34, (item.end - item.start) * pixelsPerMinute - 4);
                item.element.style.setProperty('--event-top', `${top}px`);
                item.element.style.setProperty('--event-height', `${height}px`);
            });
            if (cluster.length) placeCluster(cluster);
        });
        timetable.classList.add('is-ready');
    });

    document.querySelectorAll('[data-schedule-view-switcher]').forEach((switcher) => {
        const buttons = [...switcher.querySelectorAll('[data-schedule-view]')];
        const panels = buttons.map((button) => document.getElementById(button.getAttribute('aria-controls'))).filter(Boolean);
        const storageKey = 'rfid-schedule-view';
        const setView = (view, persist = true) => {
            if (!buttons.some((button) => button.dataset.scheduleView === view)) view = 'timetable';
            buttons.forEach((button) => {
                const active = button.dataset.scheduleView === view;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', String(active));
            });
            panels.forEach((panel) => { panel.hidden = panel.dataset.schedulePanel !== view; });
            if (view === 'list') {
                panels.find((panel) => panel.dataset.schedulePanel === 'list')
                    ?.querySelector('table')?._dataTableInstance?.refresh();
            }
            if (persist) {
                try { window.localStorage.setItem(storageKey, view); } catch { /* Storage can be unavailable in private contexts. */ }
            }
        };
        buttons.forEach((button) => button.addEventListener('click', () => setView(button.dataset.scheduleView)));
        let initialView = 'timetable';
        try { initialView = window.localStorage.getItem(storageKey) || initialView; } catch { /* Use the timetable default. */ }
        setView(initialView, false);
    });

    document.querySelectorAll('[data-record-view-switcher]').forEach((switcher) => {
        const target = document.getElementById(switcher.dataset.viewTarget || '');
        if (!target) return;
        const buttons = [...switcher.querySelectorAll('[data-record-view]')];
        const storageKey = target.dataset.viewStorage || `rfid-record-view-${target.id}`;
        const setView = (view, persist = true) => {
            if (!buttons.some((button) => button.dataset.recordView === view)) view = 'cards';
            buttons.forEach((button) => {
                const active = button.dataset.recordView === view;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', String(active));
            });
            target.classList.toggle('is-card-view', view === 'cards');
            target.classList.toggle('is-list-view', view === 'list');
            if (view === 'list') {
                window.requestAnimationFrame(() => target.querySelector('table')?._dataTableInstance?.refresh());
            }
            if (persist) {
                try { window.localStorage.setItem(storageKey, view); } catch { /* Use the current view without persistence. */ }
            }
        };
        buttons.forEach((button) => button.addEventListener('click', () => setView(button.dataset.recordView)));
        let initialView = 'cards';
        try { initialView = window.localStorage.getItem(storageKey) || initialView; } catch { /* Keep card view. */ }
        setView(initialView, false);
    });

    document.querySelectorAll('[data-activity-carousel]').forEach((carousel) => {
        const viewport = carousel.querySelector('[data-carousel-viewport]');
        const previous = carousel.querySelector('[data-carousel-previous]');
        const next = carousel.querySelector('[data-carousel-next]');
        if (!viewport) return;

        const scrollAmount = () => {
            const firstSlide = viewport.querySelector('[data-carousel-slide]');
            if (!firstSlide) return viewport.clientWidth;
            const track = firstSlide.parentElement;
            const gap = Number.parseFloat(window.getComputedStyle(track).columnGap || window.getComputedStyle(track).gap || 0);
            return firstSlide.getBoundingClientRect().width + gap;
        };
        const updateControls = () => {
            const maximum = Math.max(0, viewport.scrollWidth - viewport.clientWidth);
            if (previous) previous.disabled = viewport.scrollLeft <= 2;
            if (next) next.disabled = viewport.scrollLeft >= maximum - 2;
        };
        const move = (direction) => viewport.scrollBy({ left: scrollAmount() * direction, behavior: 'smooth' });

        previous?.addEventListener('click', () => move(-1));
        next?.addEventListener('click', () => move(1));
        viewport.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            move(event.key === 'ArrowRight' ? 1 : -1);
        });
        viewport.addEventListener('scroll', updateControls, { passive: true });
        if (window.ResizeObserver) new ResizeObserver(updateControls).observe(viewport);
        window.requestAnimationFrame(updateControls);
    });

    const eventDetailsDialog = document.querySelector('#event-details-dialog');
    const eventEditForm = eventDetailsDialog?.querySelector('[data-event-edit-form]');
    const syncEventScannerPanel = (toggle) => {
        const panel = toggle.closest('form')?.querySelector('[data-event-scanner-panel]');
        if (!panel) return;
        panel.hidden = !toggle.checked;
        panel.querySelectorAll('input, select').forEach((control) => { control.disabled = !toggle.checked; });
    };
    document.querySelectorAll('[data-event-attendance-toggle]').forEach((toggle) => {
        toggle.addEventListener('change', () => syncEventScannerPanel(toggle));
        syncEventScannerPanel(toggle);
    });
    let eventDirectory = document.querySelector('[data-event-directory]');
    if (eventDetailsDialog && eventEditForm && eventDirectory) {
        const directoryStatus = document.querySelector('[data-event-directory-status]');
        const editStatus = eventEditForm.querySelector('[data-event-edit-status]');
        const submitButton = eventEditForm.querySelector('button[type="submit"]');
        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'button button-danger';
        deleteButton.textContent = 'Delete event';
        deleteButton.hidden = true;
        eventEditForm.querySelector('.table-actions')?.prepend(deleteButton);
        let activeButton = null;
        let editable = false;

        const setStatus = (element, message = '', state = '') => {
            if (!element) return;
            element.textContent = message;
            element.className = `ajax-form-status${state ? ` is-${state}` : ''}`;
        };
        const setSelectField = (name, value) => {
            const select = eventEditForm.elements[name];
            if (!select) return;
            select.value = value == null ? '' : String(value);
            select._choicesInstance?.setChoiceByValue(select.value);
        };
        const setDateField = (name, value) => {
            const input = eventEditForm.elements[name];
            if (!input) return;
            input.value = String(value || '').slice(0, 10);
            input._flatpickr?.setDate(input.value || null, false, 'Y-m-d');
        };
        const setTimeField = (name, value) => {
            const storage = eventEditForm.elements[name];
            if (!storage) return;
            const canonical = String(value || '').slice(0, 5);
            storage.value = canonical;
            storage._flatpickr?.setDate(canonical || null, false, 'H:i');
        };
        const syncAudience = () => {
            const sectionField = eventEditForm.querySelector('[data-event-section-field]');
            const sectionSelect = eventEditForm.elements.target_id;
            const usesSection = eventEditForm.elements.audience.value === 'section';
            sectionField.hidden = !usesSection;
            sectionSelect.required = editable && usesSection;
            sectionSelect.disabled = !editable || !usesSection;
            if (sectionSelect._choicesInstance) {
                editable && usesSection ? sectionSelect._choicesInstance.enable() : sectionSelect._choicesInstance.disable();
            }
        };
        const setEditable = (canEdit) => {
            editable = canEdit;
            eventEditForm.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach((field) => {
                field.disabled = !editable;
            });
            eventEditForm.querySelectorAll('.date-time-input').forEach((field) => {
                field.disabled = !editable;
            });
            eventEditForm.querySelectorAll('select').forEach((select) => {
                if (!select._choicesInstance) return;
                editable ? select._choicesInstance.enable() : select._choicesInstance.disable();
            });
            submitButton.hidden = !editable;
            syncAudience();
        };
        const openEventDetails = (button) => {
            let details;
            try {
                details = JSON.parse(button.dataset.eventDetails || '{}');
            } catch {
                setStatus(directoryStatus, 'Unable to read this event’s details. Refresh the page and try again.', 'error');
                return;
            }
            activeButton = button;
            eventEditForm.reset();
            eventEditForm.elements.action.value = 'update_event';
            eventEditForm.elements.event_id.value = details.id;
            eventEditForm.elements.title.value = details.title || '';
            eventEditForm.elements.venue.value = details.venue || '';
            eventEditForm.elements.description.value = details.description || '';
            setDateField('event_date', details.eventDate);
            setDateField('end_date', details.endDate);
            setTimeField('start_time', details.startTime);
            setTimeField('end_time', details.endTime);
            setTimeField('check_in_start', details.checkInStart);
            setTimeField('check_in_end', details.checkInEnd);
            setSelectField('audience', details.audience || 'all_students');
            setSelectField('target_id', details.targetId);
            eventEditForm.elements.attendance_required.checked = Boolean(details.attendanceRequired);
            syncEventScannerPanel(eventEditForm.elements.attendance_required);
            if (eventEditForm.elements.suspends_classes) eventEditForm.elements.suspends_classes.checked = Boolean(details.suspendsClasses);
            eventEditForm.elements.check_out_required.checked = Boolean(details.checkOutRequired);
            Array.from(eventEditForm.elements['scanner_ids[]']?.options || []).forEach((option) => {
                option.selected = (details.scannerIds || []).map(Number).includes(Number(option.value));
            });
            eventEditForm.elements['scanner_ids[]']?._choicesInstance?.removeActiveItems();
            eventEditForm.elements['scanner_ids[]']?._choicesInstance?.setChoiceByValue((details.scannerIds || []).map(String));

            const preview = eventEditForm.querySelector('[data-event-banner-preview]');
            if (details.bannerImagePath) preview.src = details.bannerImagePath;
            else preview.removeAttribute('src');
            preview.hidden = !details.bannerImagePath;

            const canEdit = ['draft', 'scheduled'].includes(details.status);
            deleteButton.hidden = !['draft', 'scheduled', 'cancelled'].includes(details.status);
            eventDetailsDialog.querySelector('[data-event-dialog-title]').textContent = canEdit ? 'Edit event details' : 'Event details';
            eventDetailsDialog.querySelector('[data-event-dialog-subtitle]').textContent = canEdit
                ? 'Update the schedule, audience, attendance settings, or banner.'
                : 'This event is read-only because attendance is already open, closed, or cancelled.';
            setEditable(canEdit);
            setStatus(editStatus, canEdit ? '' : 'Historical attendance is protected from event changes.');
            if (!eventDetailsDialog.open) eventDetailsDialog.showModal();
            if (canEdit) window.requestAnimationFrame(() => eventEditForm.elements.title.focus());
        };
        const formatTime = (value) => {
            const [hour, minute] = String(value || '').slice(0, 5).split(':').map(Number);
            return `${hour % 12 || 12}:${String(minute || 0).padStart(2, '0')} ${hour < 12 ? 'AM' : 'PM'}`;
        };
        const updateEventRow = (details) => {
            const row = document.querySelector(`[data-event-row="${details.id}"]`);
            if (!row) return;
            row.querySelector('[data-event-row-title]').textContent = details.title;
            row.querySelector('[data-event-row-description]').textContent = details.description || '';
            row.querySelector('[data-event-row-schedule]').innerHTML = `<strong>${details.eventDate}</strong>${details.endDate ? `<br><span class="muted">through ${details.endDate}</span>` : ''}<br>${formatTime(details.startTime)}–${formatTime(details.endTime)}`;
            row.querySelector('[data-event-row-venue]').textContent = details.venue;
            const title = row.querySelector('.event-table-title');
            let banner = row.querySelector('[data-event-row-banner]');
            if (details.bannerImagePath) {
                if (!banner) {
                    banner = document.createElement('img');
                    banner.className = 'event-table-banner';
                    banner.dataset.eventRowBanner = '';
                    banner.alt = '';
                    banner.loading = 'lazy';
                    title.prepend(banner);
                }
                banner.src = details.bannerImagePath;
            } else {
                banner?.remove();
            }
        };

        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-manage-event]');
            if (button && eventDirectory?.contains(button)) openEventDetails(button);
        });
        eventEditForm.elements.audience.addEventListener('change', syncAudience);
        deleteButton.addEventListener('click', async () => {
            const eventId = eventEditForm.elements.event_id.value;
            const eventName = eventEditForm.elements.title.value.trim();
            const confirmed = window.confirmDestructiveAction
                ? await window.confirmDestructiveAction({ title: 'Delete event?', name: eventName, message: `Are you sure you want to permanently delete ${eventName}?`, warning: 'Events with attendance history cannot be deleted. Cancel them instead to preserve their records.' })
                : window.confirm(`Are you sure you want to permanently delete ${eventName}?`);
            if (!confirmed) return;
            const data = new FormData();
            data.set('_token', eventEditForm.elements._token.value);
            data.set('action', 'delete_event');
            data.set('event_id', eventId);
            deleteButton.disabled = true;
            setStatus(editStatus, 'Deleting event…');
            try {
                const response = await fetch(eventEditForm.dataset.endpoint, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' }, body: data });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to delete the event.');
                const pageResponse = await fetch(window.location.href, { credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'fetch' } });
                const pageDocument = new DOMParser().parseFromString(await pageResponse.text(), 'text/html');
                const incoming = pageDocument.querySelector('[data-event-directory]');
                if (incoming) {
                    eventDirectory.replaceWith(incoming);
                    eventDirectory = incoming;
                    initializeDataTable(eventDirectory.querySelector('table'));
                }
                eventDetailsDialog.close();
                showToast(payload.message || 'Event deleted.');
            } catch (error) {
                setStatus(editStatus, error.message || 'Unable to delete the event.', 'error');
            } finally {
                deleteButton.disabled = false;
            }
        });
        eventEditForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!editable || !eventEditForm.reportValidity()) return;
            const originalLabel = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.textContent = 'Saving…';
            setStatus(editStatus, 'Saving event…');
            try {
                const response = await fetch(eventEditForm.dataset.endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    body: new FormData(eventEditForm),
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to update the event.');
                const tags = payload.event.customTags || {};
                const details = {
                    id: tags.sourceId,
                    title: payload.event.title,
                    description: payload.event.description || '',
                    eventDate: payload.event.from.slice(0, 10),
                    endDate: tags.endDate || null,
                    startTime: payload.event.from.slice(11, 19),
                    endTime: payload.event.to.slice(11, 19),
                    venue: payload.event.location || '',
                    bannerImagePath: tags.bannerImagePath || null,
                    attendanceRequired: Boolean(tags.attendanceRequired),
                    suspendsClasses: Boolean(tags.suspendsClasses),
                    scannerIds: tags.scannerIds || [],
                    checkInStart: tags.checkInStart,
                    checkInEnd: tags.checkInEnd,
                    checkOutRequired: Boolean(tags.checkOutRequired),
                    status: tags.status,
                    audience: tags.audience || 'all_students',
                    targetId: tags.targetId,
                };
                if (activeButton) activeButton.dataset.eventDetails = JSON.stringify(details);
                updateEventRow(details);
                eventDetailsDialog.close();
                showOneTimeScannerCredentials(payload.scanner_credentials);
                setStatus(directoryStatus, payload.message || 'Event updated.', 'success');
            } catch (error) {
                setStatus(editStatus, error.message || 'Unable to update the event.', 'error');
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = originalLabel;
            }
        });
    }

    const entityField = /^(user_id|student_id|teacher_id|role_id|department_id|program_id|section_id|course_id|semester_id|room_id|class_offering_id|event_id|recollection_id|enrollment|target_id)$/;
    const searchableSelects = [...document.querySelectorAll('select:not([data-no-search])')].filter((select) => (
        select.hasAttribute('data-searchable') || select.hasAttribute('data-participant-select') || entityField.test(select.name) || select.options.length >= 8
    ));
    const initializeSearchableSelect = (select) => {
        if (window.Choices) {
            if (select._choicesInstance) return;
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
            return;
        }
        if (!select.previousElementSibling?.classList.contains('select-search-fallback')) {
            const input = document.createElement('input');
            input.type = 'search';
            input.className = 'select-search-fallback';
            input.placeholder = 'Type to filter options…';
            input.setAttribute('aria-label', 'Filter selection options');
            select.before(input);
            input.addEventListener('input', () => {
                const query = input.value.trim().toLocaleLowerCase();
                [...select.options].forEach((option) => {
                    option.hidden = query !== '' && !option.textContent.toLocaleLowerCase().includes(query);
                });
            });
        }
    };
    searchableSelects.forEach(initializeSearchableSelect);

    const replaceSelectOptions = (select, placeholder, rows) => {
        select.replaceChildren(new Option(placeholder, ''));
        rows.forEach((row) => select.add(new Option(row.label, String(row.value))));
        select.disabled = rows.length === 0;
        if (rows.length === 1) {
            select.value = String(rows[0].value);
            window.queueMicrotask(() => select.dispatchEvent(new Event('change', { bubbles: true })));
        }
    };
    const registerDependency = (control, sources) => {
        if (!control) return;
        control.dataset.dependencyControl = '';
        control._dependencySources = sources.filter(Boolean);
    };
    const dependencySource = (source) => {
        if (source?.matches?.('[data-class-offering-id]')) {
            return source.closest('[data-class-offering-selector]')?.querySelector('[data-class-semester]') || source;
        }
        return source;
    };
    const unresolvedDependencies = (control, visited = new Set()) => {
        if (!control || visited.has(control)) return [];
        visited.add(control);
        const blockers = [];
        (control._dependencySources || []).forEach((rawSource) => {
            const source = dependencySource(rawSource);
            if (!source || String(source.value || '').trim() !== '') return;
            const upstream = unresolvedDependencies(source, visited);
            if (source.disabled && upstream.length) blockers.push(...upstream);
            else blockers.push(source);
        });
        return [...new Set(blockers)];
    };
    const flashDependencyFields = (controls) => {
        controls.forEach((control) => {
            const field = control.closest('.field');
            if (!field) return;
            field.classList.remove('is-dependency-needed');
            void field.offsetWidth;
            field.classList.add('is-dependency-needed');
            window.setTimeout(() => field.classList.remove('is-dependency-needed'), 950);
        });
    };
    document.addEventListener('pointerdown', (event) => {
        const field = event.target.closest('.field');
        const blockedControl = field?.querySelector('[data-dependency-control]:disabled');
        if (!blockedControl) return;
        const blockers = unresolvedDependencies(blockedControl);
        if (!blockers.length) return;
        event.preventDefault();
        flashDependencyFields(blockers);
    });
    const uniqueRows = (rows, valueKey, labelKey) => {
        const seen = new Set();
        return rows.reduce((result, row) => {
            const value = String(row[valueKey] ?? '');
            if (!value || seen.has(value)) return result;
            seen.add(value);
            result.push({ value, label: String(row[labelKey] ?? value), source: row });
            return result;
        }, []);
    };

    document.querySelectorAll('[data-class-offering-selector]').forEach((selector) => {
        let offerings = [];
        try { offerings = JSON.parse(selector.dataset.offerings || '[]'); } catch { offerings = []; }
        const course = selector.querySelector('[data-class-course]');
        const title = selector.querySelector('[data-class-course-title]');
        const program = selector.querySelector('[data-class-program]');
        const programName = selector.querySelector('[data-class-program-name]');
        const section = selector.querySelector('[data-class-section]');
        const schoolYear = selector.querySelector('[data-class-school-year]');
        const semester = selector.querySelector('[data-class-semester]');
        const offeringId = selector.querySelector('[data-class-offering-id]');
        const status = selector.querySelector('[data-class-selector-status]');
        const form = selector.closest('form');

        registerDependency(program, [course]);
        registerDependency(section, [program]);
        registerDependency(schoolYear, [section]);
        registerDependency(semester, [schoolYear]);

        const clearOffering = () => {
            offeringId.value = '';
            offeringId.dispatchEvent(new Event('change', { bubbles: true }));
        };
        course.addEventListener('change', () => {
            clearOffering();
            const matching = offerings.filter((row) => String(row.course_id) === course.value);
            title.value = matching[0]?.course_title || '';
            programName.value = '';
            replaceSelectOptions(program, 'Select a program', uniqueRows(matching, 'program_id', 'program_code'));
            replaceSelectOptions(section, 'Select a program first', []);
            replaceSelectOptions(schoolYear, 'Select a year & section first', []);
            replaceSelectOptions(semester, 'Select a school year first', []);
        });
        program.addEventListener('change', () => {
            clearOffering();
            const matching = offerings.filter((row) => String(row.course_id) === course.value && String(row.program_id) === program.value);
            programName.value = matching[0]?.program_name || '';
            const sections = uniqueRows(matching, 'section_id', 'section_code').map((option) => ({
                ...option,
                label: `${option.source.year_level || ''}${option.source.section_code || ''}` || option.source.section_name,
            }));
            replaceSelectOptions(section, 'Select year & section', sections);
            replaceSelectOptions(schoolYear, 'Select a year & section first', []);
            replaceSelectOptions(semester, 'Select a school year first', []);
        });
        section.addEventListener('change', () => {
            clearOffering();
            const matching = offerings.filter((row) => String(row.course_id) === course.value && String(row.program_id) === program.value && String(row.section_id) === section.value);
            replaceSelectOptions(schoolYear, 'Select a school year', uniqueRows(matching, 'school_year_id', 'school_year_name'));
            replaceSelectOptions(semester, 'Select a school year first', []);
        });
        schoolYear.addEventListener('change', () => {
            clearOffering();
            const matching = offerings.filter((row) => String(row.course_id) === course.value && String(row.program_id) === program.value && String(row.section_id) === section.value && String(row.school_year_id) === schoolYear.value);
            replaceSelectOptions(semester, 'Select a semester', uniqueRows(matching, 'id', 'semester_name'));
        });
        semester.addEventListener('change', () => {
            offeringId.value = semester.value;
            offeringId.dispatchEvent(new Event('change', { bubbles: true }));
            status.textContent = semester.value ? 'Class offering selected. You can now continue.' : 'Select a semester to finish choosing the class.';
        });
        form?.addEventListener('submit', (event) => {
            if (offeringId.value) return;
            event.preventDefault();
            semester.setCustomValidity('Complete all class fields and select a semester.');
            semester.reportValidity();
            status.textContent = 'Complete Course Code, Program, Year & Section, School Year, and Semester.';
        });
        semester.addEventListener('change', () => semester.setCustomValidity(''));
    });

    document.querySelectorAll('[data-linked-detail]').forEach((select) => {
        const target = document.getElementById(select.dataset.linkedDetail);
        if (!target) return;
        const refresh = () => { target.value = select.selectedOptions[0]?.dataset.detail || ''; };
        select.addEventListener('change', refresh);
        refresh();
    });

    document.querySelectorAll('[data-create-offering-form]').forEach((form) => {
        const program = form.querySelector('[data-offering-program]');
        const programName = form.querySelector('[data-offering-program-name]');
        const section = form.querySelector('[data-offering-section]');
        const schoolYear = form.querySelector('[data-offering-school-year]');
        const semester = form.querySelector('[data-offering-semester]');
        registerDependency(section, [program]);
        registerDependency(semester, [schoolYear]);
        let sectionOptions = [...section.options].slice(1).map((option) => ({ value: option.value, label: option.textContent, programId: option.dataset.programId }));
        const semesterOptions = [...semester.options].slice(1).map((option) => ({ value: option.value, label: option.textContent, schoolYearId: option.dataset.schoolYearId }));
        program.addEventListener('change', () => {
            programName.value = program.selectedOptions[0]?.dataset.detail || '';
            replaceSelectOptions(section, 'Select year & section', sectionOptions.filter((row) => row.programId === program.value));
        });
        schoolYear.addEventListener('change', () => {
            replaceSelectOptions(semester, 'Select a semester', semesterOptions.filter((row) => row.schoolYearId === schoolYear.value));
        });
        form.addEventListener('ajax:sections-updated', (event) => {
            const knownIds = new Set(sectionOptions.map((row) => row.value));
            (event.detail?.options || []).forEach((row) => {
                if (!knownIds.has(row.value)) {
                    sectionOptions.push(row);
                    knownIds.add(row.value);
                }
            });
            const selectedSection = section.value;
            replaceSelectOptions(section, 'Select year & section', sectionOptions.filter((row) => row.programId === program.value));
            if ([...section.options].some((option) => option.value === selectedSection)) section.value = selectedSection;
        });
    });

    document.querySelectorAll('[data-academic-calendar-form]').forEach((form) => {
        let records = {};
        try { records = JSON.parse(form.dataset.calendarRecords || '{}'); } catch { records = {}; }
        const terms = { first: 'First Semester', second: 'Second Semester', summer: 'Summer Class' };
        const setDate = (input, value) => {
            input.value = value || '';
            input._flatpickr?.setDate(value || null, false, 'Y-m-d');
        };
        const refresh = () => {
            const record = records[String(form.elements.school_year_id.value)] || {};
            setDate(form.elements.school_year_starts_on, record.startsOn);
            setDate(form.elements.school_year_ends_on, record.endsOn);
            Object.entries(terms).forEach(([key, name]) => {
                const term = record.semesters?.[name] || {};
                setDate(form.elements[`semester_starts_on[${key}]`], term.startsOn);
                setDate(form.elements[`semester_ends_on[${key}]`], term.endsOn);
                form.elements[`semester_status[${key}]`].value = term.status || 'upcoming';
            });
        };
        form.elements.school_year_id.addEventListener('change', refresh);
        form.addEventListener('ajax:calendar-updated', (event) => {
            records = event.detail?.records || records;
            form.dataset.calendarRecords = JSON.stringify(records);
        });
    });

    document.querySelectorAll('[data-department-days-form]').forEach((form) => {
        const department = form.elements.department_id;
        let policies = JSON.parse(form.dataset.policies || '{}');
        const checkboxes = [...form.querySelectorAll('input[name="days_off[]"]')];
        const refresh = () => {
            const selectedDays = policies[String(department.value)] || [];
            checkboxes.forEach((checkbox) => { checkbox.checked = selectedDays.includes(checkbox.value); });
        };
        department.addEventListener('change', refresh);
        form.addEventListener('ajax:department-days-updated', (event) => {
            policies = event.detail?.policies || policies;
            form.dataset.policies = JSON.stringify(policies);
        });
        refresh();
    });

    document.querySelectorAll('[data-section-builder]').forEach((form) => {
        const program = form.elements.program_id;
        const year = form.elements.year_level;
        const section = form.elements.section_code;
        const preview = form.querySelector('[data-section-name-preview] strong');
        if (!program || !year || !section || !preview) return;

        const refresh = () => {
            section.value = section.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10);
            const programCode = program.selectedOptions[0]?.dataset.programCode || '';
            const validYear = /^[1-4]$/.test(year.value);
            preview.textContent = programCode && validYear && section.value
                ? `${programCode} ${year.value}${section.value}`
                : 'Choose a year and section';
        };

        program.addEventListener('change', refresh);
        year.addEventListener('input', refresh);
        section.addEventListener('input', refresh);
        refresh();
    });

    document.querySelectorAll('[data-event-participant-correction]').forEach((form) => {
        const eventSelect = form.elements.event_id;
        const sectionSelect = form.elements.section_id;
        registerDependency(sectionSelect, [eventSelect]);
        let controller;
        const resetSections = (label = 'Select an event first') => {
            sectionSelect.replaceChildren(new Option(label, ''));
            sectionSelect.disabled = true;
            sectionSelect.dispatchEvent(new Event('change', { bubbles: true }));
        };
        eventSelect.addEventListener('change', async () => {
            controller?.abort();
            if (!eventSelect.value) {
                resetSections();
                return;
            }
            resetSections('Loading sections…');
            controller = new AbortController();
            try {
                const lookupEndpoint = form.querySelector('[data-remote-user-lookup]')?.dataset.endpoint || '/lookups/users.php';
                const endpoint = new URL(lookupEndpoint, window.location.origin);
                endpoint.searchParams.set('context', 'event_sections');
                endpoint.searchParams.set('event_id', eventSelect.value);
                const response = await fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to load event sections.');
                sectionSelect.replaceChildren(new Option('Select a section', ''));
                payload.options.forEach((option) => sectionSelect.add(new Option(option.label, option.value)));
                sectionSelect.disabled = payload.options.length === 0;
                if (!payload.options.length) sectionSelect.options[0].textContent = 'No participant sections found';
                sectionSelect.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (error) {
                if (error.name === 'AbortError') return;
                resetSections(error.message || 'Unable to load sections');
            }
        });
    });

    document.querySelectorAll('[data-remote-user-lookup]').forEach((lookup) => {
        const form = lookup.closest('form');
        const input = lookup.querySelector('[data-remote-user-input]');
        const selectedInput = lookup.querySelector('[data-remote-user-id]');
        const results = lookup.querySelector('[data-remote-user-results]');
        const footer = lookup.querySelector('[data-remote-user-footer]');
        const pageInfo = lookup.querySelector('[data-lookup-page-info]');
        const previous = lookup.querySelector('[data-lookup-page="previous"]');
        const next = lookup.querySelector('[data-lookup-page="next"]');
        const status = lookup.querySelector('[data-remote-user-status]');
        const parent = lookup.dataset.parentName ? form?.elements[lookup.dataset.parentName] : null;
        const section = lookup.dataset.sectionName ? form?.elements[lookup.dataset.sectionName] : null;
        registerDependency(input, [parent, section]);
        let page = 1;
        let pages = 1;
        let debounceTimer;
        let controller;

        const ready = () => (!parent || Boolean(parent.value)) && (!section || Boolean(section.value));
        const clearSelection = () => {
            selectedInput.value = '';
            selectedInput.dispatchEvent(new Event('change', { bubbles: true }));
        };
        const closeResults = () => {
            results.hidden = true;
            footer.hidden = true;
        };
        const reset = () => {
            controller?.abort();
            window.clearTimeout(debounceTimer);
            input.value = '';
            clearSelection();
            results.replaceChildren();
            closeResults();
            input.disabled = !ready();
            status.textContent = ready()
                ? 'Type at least 2 characters to search. Results load 20 at a time.'
                : `Select ${section && !section.value ? 'an event and section' : 'a class or activity'} first.`;
        };
        const render = (payload) => {
            results.replaceChildren();
            if (!payload.items.length) {
                const empty = document.createElement('p');
                empty.className = 'user-lookup-message';
                empty.textContent = 'No matching participants found.';
                results.appendChild(empty);
            } else {
                payload.items.forEach((item) => {
                    const option = document.createElement('button');
                    const title = document.createElement('strong');
                    const identifier = document.createElement('small');
                    const meta = document.createElement('small');
                    option.type = 'button';
                    option.className = 'remote-user-option';
                    title.textContent = item.label;
                    identifier.textContent = item.identifier || '';
                    meta.textContent = [item.role, item.email].filter(Boolean).join('  ');
                    option.append(title, identifier, meta);
                    option.addEventListener('click', () => {
                        input.value = item.label;
                        selectedInput.value = item.id;
                        selectedInput.dispatchEvent(new Event('change', { bubbles: true }));
                        input.setCustomValidity('');
                        closeResults();
                        status.textContent = `Selected: ${item.label}`;
                        lookup.dispatchEvent(new CustomEvent('remote-user:selected', { bubbles: true, detail: { user: item } }));
                    });
                    results.appendChild(option);
                });
            }
            page = payload.pagination.page;
            pages = payload.pagination.pages;
            previous.disabled = page <= 1;
            next.disabled = page >= pages;
            pageInfo.textContent = `Page ${page} of ${pages} · ${payload.pagination.total} result${payload.pagination.total === 1 ? '' : 's'}`;
            results.hidden = false;
            footer.hidden = payload.pagination.total === 0;
            status.textContent = payload.pagination.total ? 'Choose a person from the results.' : 'Try a different school ID or name.';
        };
        const search = async (requestedPage = 1) => {
            const query = input.value.trim();
            if (!ready() || query.length < 2) {
                closeResults();
                status.textContent = ready() ? 'Type at least 2 characters to search.' : 'Complete the selections above first.';
                return;
            }
            controller?.abort();
            controller = new AbortController();
            status.textContent = 'Searching…';
            try {
                const endpoint = new URL(lookup.dataset.endpoint, window.location.origin);
                endpoint.searchParams.set('context', lookup.dataset.context);
                endpoint.searchParams.set('q', query);
                endpoint.searchParams.set('page', String(requestedPage));
                if (parent) endpoint.searchParams.set(lookup.dataset.parentName, parent.value);
                if (section) endpoint.searchParams.set(lookup.dataset.sectionName, section.value);
                const response = await fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to search the directory.');
                render(payload);
            } catch (error) {
                if (error.name === 'AbortError') return;
                closeResults();
                status.textContent = error.message || 'Unable to search the directory.';
            }
        };

        input.addEventListener('input', () => {
            clearSelection();
            input.setCustomValidity('');
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => search(1), 250);
        });
        input.addEventListener('focus', () => {
            if (input.value.trim().length >= 2 && !selectedInput.value) search(page);
        });
        previous.addEventListener('click', () => search(Math.max(1, page - 1)));
        next.addEventListener('click', () => search(Math.min(pages, page + 1)));
        parent?.addEventListener('change', reset);
        section?.addEventListener('change', reset);
        form?.addEventListener('ajax:reset-lookups', reset);
        form?.addEventListener('submit', (event) => {
            const group = lookup.closest('[data-recipient-group]');
            if (group?.hidden || input.disabled || lookup.dataset.optional === '1') return;
            if (!selectedInput.value) {
                event.preventDefault();
                input.setCustomValidity('Select a person from the search results.');
                input.reportValidity();
            }
        });
        document.addEventListener('click', (event) => {
            if (!lookup.contains(event.target)) closeResults();
        });
        if (!selectedInput.value) {
            reset();
        } else {
            input.disabled = !ready();
            closeResults();
            status.textContent = `Selected: ${input.value}`;
        }
    });

    document.querySelectorAll('[data-recollection-people-picker]').forEach((picker) => {
        const form = picker.closest('form');
        const lookup = picker.querySelector('[data-remote-user-lookup]');
        const input = lookup?.querySelector('[data-remote-user-input]');
        const candidate = lookup?.querySelector('[data-remote-user-id]');
        const status = lookup?.querySelector('[data-remote-user-status]');
        const selected = picker.querySelector('[data-selected-people]');
        const empty = picker.querySelector('[data-selected-people-empty]');
        if (!form || !lookup || !input || !candidate || !selected) return;

        const selectedIds = () => new Set([...selected.querySelectorAll('input[name="other_user_ids[]"]')].map((field) => field.value));
        const refreshEmpty = () => { if (empty) empty.hidden = selected.querySelector('input[name="other_user_ids[]"]') !== null; };
        const addPerson = (person) => {
            const userId = String(person.id || '');
            if (!userId || selectedIds().has(userId)) {
                if (status) status.textContent = person.label ? `${person.label} is already included.` : 'That person is already included.';
                return;
            }
            const chip = document.createElement('span');
            const hidden = document.createElement('input');
            const label = document.createElement('span');
            const remove = document.createElement('button');
            chip.className = 'selected-person';
            hidden.type = 'hidden';
            hidden.name = 'other_user_ids[]';
            hidden.value = userId;
            label.textContent = [person.label, person.identifier].filter(Boolean).join(' · ');
            remove.type = 'button';
            remove.setAttribute('aria-label', `Remove ${person.label || 'person'}`);
            remove.title = 'Remove';
            remove.innerHTML = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 6 8 8M14 6l-8 8"/></svg>';
            remove.addEventListener('click', () => { chip.remove(); refreshEmpty(); });
            chip.append(hidden, label, remove);
            selected.appendChild(chip);
            refreshEmpty();
            input.value = '';
            candidate.value = '';
            if (status) status.textContent = 'Person added. Search again to add someone else.';
            input.focus();
        };

        lookup.addEventListener('remote-user:selected', (event) => addPerson(event.detail.user));
        form.addEventListener('ajax:reset-lookups', () => {
            selected.querySelectorAll('.selected-person').forEach((chip) => chip.remove());
            refreshEmpty();
        });
        refreshEmpty();
    });

    document.querySelectorAll('[data-recipient-form]').forEach((form) => {
        const typeSelect = form.elements.recipient_type;
        const targetInput = form.elements.target_id;
        const groups = [...form.querySelectorAll('[data-recipient-group]')];
        const refresh = () => {
            groups.forEach((group) => {
                const select = group.querySelector('[data-recipient-target], [data-remote-user-id]');
                const active = group.dataset.recipientGroup === typeSelect.value;
                group.hidden = !active;
                select.disabled = !active;
                group.querySelector('[data-remote-user-input]')?.toggleAttribute('disabled', !active);
                if (select._choicesInstance) {
                    active ? select._choicesInstance.enable() : select._choicesInstance.disable();
                }
                if (active) targetInput.value = select.value;
            });
            if (typeSelect.value === 'all') targetInput.value = '';
        };
        typeSelect.addEventListener('change', refresh);
        groups.forEach((group) => group.querySelector('[data-recipient-target], [data-remote-user-id]').addEventListener('change', refresh));
        refresh();
    });

    document.querySelectorAll('[data-collection-controls]').forEach((controls) => {
        const collection = document.querySelector(controls.dataset.target);
        if (!collection) return;
        const search = controls.querySelector('[data-collection-search]');
        const filters = [...controls.querySelectorAll('[data-collection-filter]')];
        const refresh = () => {
            const query = search?.value.trim().toLocaleLowerCase() ?? '';
            collection.querySelectorAll('[data-search-item]').forEach((item) => {
                const matchesText = query === '' || item.textContent.toLocaleLowerCase().includes(query);
                const matchesFilters = filters.every((filter) => filter.value === '' || item.dataset[filter.dataset.collectionFilter] === filter.value);
                item.hidden = !matchesText || !matchesFilters;
            });
        };
        search?.addEventListener('input', refresh);
        filters.forEach((filter) => filter.addEventListener('change', refresh));
    });

    document.querySelectorAll('[data-user-lookup]').forEach((lookup) => {
        const input = lookup.querySelector('[data-user-lookup-input]');
        const valueInput = lookup.querySelector('[data-user-lookup-value]');
        const results = lookup.querySelector('[data-user-lookup-results]');
        const cache = new Map();
        let timer;
        let controller;
        let requestNumber = 0;
        let activeIndex = -1;

        const setExpanded = (expanded) => {
            results.hidden = !expanded;
            input.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            if (!expanded) {
                activeIndex = -1;
                input.removeAttribute('aria-activedescendant');
            }
        };
        const renderMessage = (message) => {
            results.replaceChildren();
            const item = document.createElement('div');
            item.className = 'user-lookup-message';
            item.textContent = message;
            results.append(item);
            setExpanded(true);
        };
        const choose = (user) => {
            valueInput.value = user.id;
            input.value = `${user.school_id} — ${user.first_name} ${user.last_name}`;
            input.setCustomValidity('');
            setExpanded(false);
            lookup.dispatchEvent(new CustomEvent('user-lookup:selected', { bubbles: true, detail: { user } }));
        };
        const renderUsers = (users) => {
            results.replaceChildren();
            if (!users.length) {
                renderMessage('No active users found.');
                return;
            }
            users.forEach((user, index) => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'user-lookup-option';
                option.id = `${results.id}-option-${index}`;
                option.setAttribute('role', 'option');
                const name = document.createElement('strong');
                name.textContent = `${user.school_id} — ${user.first_name} ${user.last_name}`;
                const details = document.createElement('small');
                details.textContent = `${user.email} · ${user.role_name}`;
                option.append(name, details);
                option.addEventListener('click', () => choose(user));
                results.append(option);
            });
            setExpanded(true);
        };
        const load = async () => {
            const query = input.value.trim();
            if (query === '') {
                setExpanded(false);
                return;
            }

            controller?.abort();
            controller = new AbortController();
            const currentRequest = ++requestNumber;
            const cacheKey = query.toLocaleLowerCase();
            renderMessage('Searching…');
            try {
                let users = cache.get(cacheKey);
                if (!users) {
                    const requestUrl = new URL(lookup.dataset.endpoint, window.location.origin);
                    requestUrl.searchParams.set('q', query);
                    const response = await fetch(requestUrl, {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.ok) throw new Error(payload.message || 'User search failed.');
                    users = payload.users;
                    cache.set(cacheKey, users);
                    if (cache.size > 30) cache.delete(cache.keys().next().value);
                }
                if (currentRequest === requestNumber) renderUsers(users);
            } catch (error) {
                if (error.name !== 'AbortError' && currentRequest === requestNumber) {
                    renderMessage(error.message || 'Unable to search users.');
                }
            }
        };
        const schedule = () => {
            window.clearTimeout(timer);
            controller?.abort();
            valueInput.value = '';
            input.setCustomValidity('');
            lookup.dispatchEvent(new CustomEvent('user-lookup:cleared', { bubbles: true }));
            timer = window.setTimeout(load, 250);
        };

        input.setAttribute('aria-expanded', 'false');
        input.addEventListener('input', schedule);
        input.addEventListener('keydown', (event) => {
            const options = [...results.querySelectorAll('.user-lookup-option')];
            if (!options.length || !['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].includes(event.key)) return;
            event.preventDefault();
            if (event.key === 'Escape') {
                setExpanded(false);
                return;
            }
            if (event.key === 'Enter' && activeIndex >= 0) {
                options[activeIndex].click();
                return;
            }
            if (event.key === 'ArrowDown') activeIndex = (activeIndex + 1) % options.length;
            if (event.key === 'ArrowUp') activeIndex = (activeIndex - 1 + options.length) % options.length;
            options.forEach((option, index) => option.classList.toggle('is-active', index === activeIndex));
            input.setAttribute('aria-activedescendant', options[activeIndex].id);
            options[activeIndex].scrollIntoView({ block: 'nearest' });
        });
        input.form.addEventListener('submit', (event) => {
            if (valueInput.value !== '') return;
            event.preventDefault();
            input.setCustomValidity('Select a user from the search results.');
            input.reportValidity();
        });
        document.addEventListener('click', (event) => {
            if (!lookup.contains(event.target)) setExpanded(false);
        });
        lookup.closest('dialog')?.addEventListener('close', () => {
            window.clearTimeout(timer);
            controller?.abort();
            input.setCustomValidity('');
            valueInput.value = '';
            setExpanded(false);
        });
    });

    document.querySelectorAll('[data-rfid-registration]').forEach((form) => {
        const scanner = form.querySelector('[data-rfid-scanner]');
        const lookup = form.querySelector('[data-user-lookup]');
        const lookupInput = lookup.querySelector('[data-user-lookup-input]');
        const userIdInput = lookup.querySelector('[data-user-lookup-value]');
        const uidInput = form.querySelector('[data-rfid-uid]');
        const deviceInput = form.querySelector('[data-rfid-device]');
        const requestTokenInput = form.querySelector('[data-rfid-request-token]');
        const scanButton = form.querySelector('[data-rfid-start-scan]');
        const scanButtonLabel = form.querySelector('[data-rfid-scan-button-label]');
        const confirmButton = form.querySelector('[data-rfid-confirm]');
        const review = form.querySelector('[data-rfid-review]');
        const reviewMessage = form.querySelector('[data-rfid-review-message]');
        const reassignment = form.querySelector('[data-rfid-reassignment]');
        const reassignmentMessage = form.querySelector('[data-rfid-reassignment-message]');
        const reassignmentConfirm = form.querySelector('[data-rfid-reassignment-confirm]');
        const statusTitle = form.querySelector('[data-rfid-status-title]');
        const statusMessage = form.querySelector('[data-rfid-status-message]');
        const cardPhoto = form.querySelector('[data-rfid-card-photo]');
        const cardName = form.querySelector('[data-rfid-card-name]');
        const cardRole = form.querySelector('[data-rfid-card-role]');
        const cardId = form.querySelector('[data-rfid-card-id]');
        const cardDepartment = form.querySelector('[data-rfid-card-department]');
        const defaultPhoto = cardPhoto.src;
        let selectedUser = null;
        let pollTimer;

        const setState = (state, title, message) => {
            scanner.dataset.state = state;
            statusTitle.textContent = title;
            statusMessage.textContent = message;
        };
        const resetScan = (keepUser = true) => {
            window.clearTimeout(pollTimer);
            requestTokenInput.value = '';
            uidInput.value = '';
            uidInput.setCustomValidity('');
            review.hidden = true;
            reassignment.hidden = true;
            reassignmentConfirm.checked = false;
            confirmButton.disabled = true;
            scanButton.disabled = false;
            scanButtonLabel.textContent = 'Start card scan';
            if (keepUser && selectedUser && deviceInput.value) {
                setState('idle', 'Ready to scan', selectedUser.rfid_uid
                    ? `This user currently has card ${selectedUser.rfid_uid}. A confirmed scan will replace it.`
                    : 'Start the scan, then tap the card on the selected physical reader.');
            } else {
                setState('idle', 'Ready to scan', 'Select a user and physical scanner to begin.');
            }
        };
        const showComplete = (registration) => {
            uidInput.value = registration.uid;
            setState('complete', 'Card captured', 'Check the selected user and RFID UID before confirming the assignment.');
            review.hidden = false;
            if (registration.requires_reassignment) {
                const owner = registration.current_owner;
                reassignment.hidden = false;
                reassignmentMessage.textContent = `${registration.uid} is assigned to ${owner.name} (${owner.school_id}). Confirm before moving it.`;
                reviewMessage.textContent = 'This assignment will move the card from another user.';
            } else {
                reassignment.hidden = true;
                reviewMessage.textContent = 'Check the selected user and RFID UID, then confirm the assignment.';
            }
            confirmButton.disabled = registration.requires_reassignment && !reassignmentConfirm.checked;
            scanButton.disabled = false;
            scanButtonLabel.textContent = 'Scan again';
        };
        const showError = (message) => {
            setState('error', 'Scan could not be completed', message);
            review.hidden = true;
            reassignment.hidden = true;
            confirmButton.disabled = true;
            scanButton.disabled = false;
            scanButtonLabel.textContent = 'Try again';
        };
        const clearSelectedUser = () => {
            selectedUser = null;
            cardPhoto.src = defaultPhoto;
            cardName.textContent = 'Select a user';
            cardRole.textContent = 'Account role';
            cardId.textContent = 'School ID';
            cardDepartment.textContent = 'Department';
            resetScan(false);
        };

        lookup.addEventListener('user-lookup:selected', (event) => {
            selectedUser = event.detail.user;
            cardPhoto.src = selectedUser.profile_image_path || defaultPhoto;
            cardName.textContent = `${selectedUser.first_name} ${selectedUser.last_name}`;
            cardRole.textContent = selectedUser.role_names_label || selectedUser.role_name;
            cardId.textContent = selectedUser.school_id;
            cardDepartment.textContent = selectedUser.department_name || 'No department assigned';
            resetScan(true);
        });
        lookup.addEventListener('user-lookup:cleared', clearSelectedUser);

        deviceInput.addEventListener('change', () => resetScan(true));
        reassignmentConfirm.addEventListener('change', () => {
            confirmButton.disabled = !reassignment.hidden && !reassignmentConfirm.checked;
        });

        const pollRegistration = async () => {
            if (!requestTokenInput.value) return;
            try {
                const response = await fetch(`${form.dataset.endpoint}?token=${encodeURIComponent(requestTokenInput.value)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Could not check the scanner.');
                const registration = payload.registration;
                if (registration.status === 'captured') return showComplete(registration);
                if (registration.status !== 'pending') {
                    showError(registration.status === 'expired' ? 'The scan request expired. Start another scan.' : 'The scan request is no longer active.');
                    return;
                }
                pollTimer = window.setTimeout(pollRegistration, 1000);
            } catch (error) {
                showError(error.message || 'Could not check the scanner.');
            }
        };

        scanButton.addEventListener('click', async () => {
            window.clearTimeout(pollTimer);
            if (!selectedUser || !userIdInput.value) {
                lookupInput.setCustomValidity('Select a user from the search results before scanning.');
                lookupInput.reportValidity();
                showError('Select an active user from the search results, then try again.');
                return;
            }
            if (!deviceInput.value) {
                deviceInput.setCustomValidity('Select a configured physical scanner.');
                deviceInput.reportValidity();
                showError('Select a scanner, then try again.');
                return;
            }
            deviceInput.setCustomValidity('');
            uidInput.value = '';
            review.hidden = true;
            confirmButton.disabled = true;
            scanButton.disabled = true;
            scanButtonLabel.textContent = 'Scanning…';
            setState('scanning', 'Waiting for card…', 'Tap the physical card on the selected scanner within two minutes.');
            const payload = new FormData(form);
            payload.set('action', 'start');
            try {
                const response = await fetch(form.dataset.endpoint, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: payload });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'The scan could not be started.');
                requestTokenInput.value = result.registration.request_token;
                pollRegistration();
            } catch (error) {
                showError(error.message || 'The scan could not be started.');
            }
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!selectedUser || !userIdInput.value) {
                lookupInput.setCustomValidity('Select a user from the search results.');
                lookupInput.reportValidity();
                showError('A user must be selected before the card can be assigned.');
                return;
            }
            if (!requestTokenInput.value || !/^[A-F0-9]{8,20}$/.test(uidInput.value)) {
                uidInput.setCustomValidity('Enter an 8–20 character hexadecimal RFID UID.');
                uidInput.reportValidity();
                showError('The RFID UID must contain only hexadecimal characters (0–9 and A–F).');
                return;
            }

            if (!reassignment.hidden && !reassignmentConfirm.checked) {
                showError('Confirm that this card may be removed from its current user.');
                return;
            }

            confirmButton.disabled = true;
            scanButton.disabled = true;
            const confirmLabel = confirmButton.textContent;
            confirmButton.textContent = 'Assigning…';
            setState('saving', 'Assigning card…', 'Saving the verified card assignment.');
            const payload = new FormData(form);
            payload.set('action', 'complete');
            payload.set('allow_reassignment', reassignmentConfirm.checked ? '1' : '0');
            try {
                const response = await fetch(form.dataset.endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    body: payload,
                });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'The RFID card could not be assigned.');
                selectedUser.rfid_uid = result.registration.uid;
                setState('assigned', 'RFID assigned', `${result.registration.uid} is now assigned to ${selectedUser.first_name} ${selectedUser.last_name}.`);
                review.hidden = true;
                scanButton.disabled = false;
                scanButtonLabel.textContent = 'Scan another card';
                document.querySelector('[data-live-filter]')?.dispatchEvent(new CustomEvent('live-filter:refresh'));
            } catch (error) {
                showError(error.message || 'The RFID card could not be assigned. Check the details and try again.');
                confirmButton.disabled = false;
            } finally {
                confirmButton.textContent = confirmLabel;
            }
        });

        form.closest('dialog')?.addEventListener('close', () => {
            window.clearTimeout(pollTimer);
            selectedUser = null;
            clearSelectedUser();
        });
    });

    document.querySelectorAll('[data-enrollment-select]').forEach((select) => {
        select.addEventListener('change', () => {
            const [offeringId = '', studentId = ''] = select.value.split(':');
            select.form.elements.class_offering_id.value = offeringId;
            select.form.elements.student_id.value = studentId;
        });
    });

    document.querySelectorAll('[data-participant-select]').forEach((select) => {
        select.addEventListener('change', () => {
            const [entityId = '', userId = ''] = select.value.split(':');
            select.form.elements[select.dataset.entityField].value = entityId;
            select.form.elements.user_id.value = userId;
        });
    });

    const userDialog = document.querySelector('[data-user-dialog]');
    if (userDialog) {
        const loading = userDialog.querySelector('[data-user-dialog-loading]');
        const content = userDialog.querySelector('[data-user-dialog-content]');
        const dialogError = userDialog.querySelector('[data-user-dialog-error]');
        const dialogTitle = userDialog.querySelector('[data-user-dialog-title]');
        const dialogSubtitle = userDialog.querySelector('[data-user-dialog-subtitle]');
        const managementForms = [...userDialog.querySelectorAll('[data-user-manage-form]')];
        const detailsForm = managementForms.find((form) => form.elements.action.value === 'update_user');
        const profileImageForm = managementForms.find((form) => form.elements.action.value === 'update_user_profile_image');
        const rfidForm = managementForms.find((form) => form.elements.action.value === 'update_user_rfid');
        const passwordForm = managementForms.find((form) => form.elements.action.value === 'reset_user_password');
        const endpoint = userDialog.dataset.endpoint;
        const userDeleteButton = document.createElement('button');
        userDeleteButton.type = 'button';
        userDeleteButton.className = 'button button-danger';
        userDeleteButton.textContent = 'Delete account';
        detailsForm.querySelector('.ajax-form-footer')?.insertBefore(userDeleteButton, detailsForm.querySelector('button[type="submit"]'));
        let managedUser = null;
        let userLoadController;
        let userLoadRequest = 0;

        const requestJson = async (requestUrl, options = {}) => {
            const response = await fetch(requestUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', ...(options.headers || {}) },
                ...options,
            });
            let payload;
            try {
                payload = await response.json();
            } catch (error) {
                throw new Error(response.status === 401 ? 'Your session has expired. Sign in again.' : 'The server returned an invalid response.');
            }
            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'The request could not be completed.');
            }
            return payload;
        };

        const setSelectValue = (select, value) => {
            const normalizedValue = value === null || value === undefined ? '' : String(value);
            select.value = normalizedValue;
            select._choicesInstance?.setChoiceByValue(normalizedValue);
        };

        const setMultiSelectValues = (select, values) => {
            const normalizedValues = (values || []).map(String);
            [...select.options].forEach((option) => { option.selected = normalizedValues.includes(option.value); });
            if (select._choicesInstance) {
                select._choicesInstance.removeActiveItems();
                if (normalizedValues.length) select._choicesInstance.setChoiceByValue(normalizedValues);
            }
        };

        const populateUser = (user) => {
            managedUser = user;
            userDeleteButton.hidden = (user.role_ids || [user.role_id]).map(Number).includes(1);
            managementForms.forEach((form) => { form.elements.user_id.value = user.id; });
            ['school_id', 'email', 'first_name', 'middle_name', 'last_name'].forEach((name) => {
                detailsForm.elements[name].value = user[name] || '';
            });
            ['role_id', 'department_id', 'program_id', 'section_id', 'status'].forEach((name) => {
                setSelectValue(detailsForm.elements[name], user[name]);
            });
            const additionalRoles = (user.role_ids || []).filter((roleId) => Number(roleId) !== Number(user.role_id));
            setMultiSelectValues(detailsForm.elements['role_ids[]'], additionalRoles);
            rfidForm.elements.rfid_uid.value = user.rfid_uid || '';
            profileImageForm.hidden = (user.role_ids || [user.role_id]).map(Number).includes(1);
            profileImageForm.querySelector('[data-user-profile-image]').src = user.profile_image_path || '/assets/images/default-avatar.svg';
            profileImageForm.elements.profile_image.value = '';
            dialogTitle.textContent = `${user.first_name} ${user.last_name}`;
            dialogSubtitle.textContent = `${user.school_id} · ${user.role_names_label || user.role_name}`;
        };

        const setFormStatus = (form, message = '', state = '') => {
            const status = form.querySelector('[data-ajax-status]');
            status.textContent = message;
            status.classList.toggle('is-success', state === 'success');
            status.classList.toggle('is-error', state === 'error');
        };

        const openUser = async (userId) => {
            userLoadController?.abort();
            userLoadController = new AbortController();
            const currentRequest = ++userLoadRequest;
            managementForms.forEach((form) => {
                form.reset();
                setFormStatus(form);
            });
            loading.hidden = false;
            content.hidden = true;
            dialogError.hidden = true;
            dialogError.textContent = '';
            dialogTitle.textContent = 'Manage user';
            dialogSubtitle.textContent = 'Loading account details…';
            if (!userDialog.open) userDialog.showModal();

            try {
                const requestUrl = new URL(endpoint, window.location.origin);
                requestUrl.searchParams.set('id', userId);
                const payload = await requestJson(requestUrl, { signal: userLoadController.signal });
                if (currentRequest !== userLoadRequest) return;
                populateUser(payload.user);
                loading.hidden = true;
                content.hidden = false;
            } catch (error) {
                if (error.name === 'AbortError') return;
                loading.hidden = true;
                dialogError.textContent = error.message;
                dialogError.hidden = false;
            }
        };

        document.addEventListener('click', (event) => {
            const manageButton = event.target.closest('[data-manage-user]');
            if (manageButton) {
                openUser(manageButton.dataset.userId);
                return;
            }
            if (event.target.closest('[data-user-dialog-close]')) {
                userDialog.close();
            }
        });
        userDialog.addEventListener('click', (event) => {
            if (event.target === userDialog) userDialog.close();
        });
        userDialog.addEventListener('close', () => userLoadController?.abort());

        userDeleteButton.addEventListener('click', async () => {
            if (!managedUser) return;
            const name = `${managedUser.first_name} ${managedUser.last_name} (${managedUser.school_id})`;
            const confirmed = window.confirmDestructiveAction
                ? await window.confirmDestructiveAction({ title: 'Delete user account?', name, message: `Are you sure you want to permanently delete ${name}?`, warning: 'Accounts with attendance, enrollment, grade, teaching, messaging, or other institutional history cannot be deleted. Deactivate them instead.' })
                : window.confirm(`Are you sure you want to permanently delete ${name}?`);
            if (!confirmed) return;
            const data = new FormData();
            data.set('_token', detailsForm.elements._token.value);
            data.set('action', 'delete_user');
            data.set('user_id', managedUser.id);
            userDeleteButton.disabled = true;
            setFormStatus(detailsForm, 'Deleting account…');
            try {
                const payload = await requestJson(endpoint, { method: 'POST', body: data });
                userDialog.close();
                managedUser = null;
                document.querySelector('[data-live-filter]')?.dispatchEvent(new CustomEvent('live-filter:refresh'));
                showToast(payload.message || 'User account deleted.');
            } catch (error) {
                setFormStatus(detailsForm, error.message, 'error');
            } finally {
                userDeleteButton.disabled = false;
            }
        });

        managementForms.forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (!form.reportValidity()) return;

                const submitButton = form.querySelector('button[type="submit"]');
                const originalLabel = submitButton.textContent;
                submitButton.disabled = true;
                submitButton.textContent = 'Saving…';
                setFormStatus(form, 'Saving changes…');

                try {
                    const payload = await requestJson(endpoint, { method: 'POST', body: new FormData(form) });
                    const action = form.elements.action.value;
                    if (payload.user && (action === 'update_user' || action === 'update_user_profile_image')) {
                        populateUser(payload.user);
                    } else if (payload.user && action === 'update_user_rfid') {
                        rfidForm.elements.rfid_uid.value = payload.user.rfid_uid || '';
                    } else if (action === 'reset_user_password') {
                        passwordForm.elements.new_password.value = '';
                        passwordForm.elements.confirm_password.value = '';
                    }
                    setFormStatus(form, payload.message, 'success');
                    document.querySelector('[data-live-filter]')?.dispatchEvent(new CustomEvent('live-filter:refresh'));
                } catch (error) {
                    setFormStatus(form, error.message, 'error');
                } finally {
                    submitButton.disabled = false;
                    submitButton.textContent = originalLabel;
                }
            });
        });
    }

    const calendarElement = document.querySelector('[data-dashboard-calendar]');
    if (calendarElement) {
        const feedback = document.querySelector('[data-calendar-feedback]');
        const eventDialog = document.querySelector('#calendar-event-dialog');
        const eventForm = eventDialog?.querySelector('[data-calendar-event-form]');
        const recollectionDialog = document.querySelector('#calendar-recollection-dialog');
        const recollectionForm = recollectionDialog?.querySelector('[data-calendar-recollection-form]');
        const timeOffDialog = document.querySelector('#calendar-time-off-dialog');
        const timeOffForm = timeOffDialog?.querySelector('[data-calendar-time-off-form]');
        const monthLabel = document.querySelector('[data-calendar-month-label]');
        const agendaDate = document.querySelector('[data-calendar-agenda-date]');
        const agendaList = document.querySelector('[data-calendar-agenda-list]');
        const canManageEvents = calendarElement.dataset.canManageEvents === '1';
        const canManageRecollections = calendarElement.dataset.canManageRecollections === '1';
        const canManageTimeOff = calendarElement.dataset.canManageTimeOff === '1';
        let calendar;
        let persistedEvents = [];
        let selectedDate = new Date();
        let rangeRenderFrame;

        const setFeedback = (message, state = '') => {
            feedback.textContent = message;
            feedback.classList.toggle('is-success', state === 'success');
            feedback.classList.toggle('is-error', state === 'error');
        };
        const parseCalendarEvent = (event) => ({
            ...event,
            from: new Date(event.from),
            to: new Date(event.to),
        });
        const dateInputValue = (date) => {
            const value = date instanceof Date && !Number.isNaN(date.valueOf()) ? date : new Date();
            const year = value.getFullYear();
            const month = String(value.getMonth() + 1).padStart(2, '0');
            const day = String(value.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        };
        const formatAgendaTime = (date) => date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
        const updateMonthLabel = (date) => {
            const value = date instanceof Date ? date : calendar?.getCurrentDisplayDate() || new Date();
            monthLabel.textContent = value.toLocaleDateString([], { month: 'long', year: 'numeric' });
        };
        const markSelectedCell = () => {
            calendarElement.querySelectorAll('.is-selected-date').forEach((cell) => cell.classList.remove('is-selected-date'));
            if (!calendar) return;
            const displayDate = calendar.getCurrentDisplayDate();
            const firstCellDate = new Date(displayDate.getFullYear(), displayDate.getMonth(), 1);
            firstCellDate.setDate(firstCellDate.getDate() - ((firstCellDate.getDay() + 6) % 7));
            const utc = (date) => Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
            const cellIndex = Math.round((utc(selectedDate) - utc(firstCellDate)) / 86400000) + 1;
            if (cellIndex < 1 || cellIndex > 42) return;
            calendarElement.querySelector(`#calendar-day-${cellIndex}`)?.classList.add('is-selected-date');
        };
        const renderCalendarRangeBars = () => {
            const renderedEvents = [...calendarElement.querySelectorAll('.full-month-view .event[event-id]')];
            const weekRows = [...calendarElement.querySelectorAll('.full-month-view .row-cells.days')];
            calendarElement.querySelectorAll('.calendar-range-origin').forEach((cell) => cell.classList.remove('calendar-range-origin'));
            weekRows.forEach((row) => row.style.removeProperty('min-height'));
            renderedEvents.forEach((element) => {
                element.classList.remove('calendar-lane-event', 'calendar-range-event', 'range-continues-before', 'range-continues-after');
                element.style.removeProperty('display');
                element.style.removeProperty('width');
                element.style.removeProperty('max-width');
                element.style.removeProperty('position');
                element.style.removeProperty('inset');
                element.style.removeProperty('top');
                element.style.removeProperty('left');
                element.style.removeProperty('margin');
            });

            const eventsById = new Map(persistedEvents.map(parseCalendarEvent).map((event) => [String(event.id), event]));
            const copyIndexes = new Map();
            renderedEvents.forEach((element) => {
                const id = element.getAttribute('event-id');
                const cellIndex = Number(element.closest('.cell[id^="calendar-day-"]')?.id.match(/calendar-day-(\d+)/)?.[1]);
                if (!id || !cellIndex) return;
                if (!copyIndexes.has(id)) copyIndexes.set(id, []);
                copyIndexes.get(id).push(cellIndex);
            });

            weekRows.forEach((row) => {
                const cells = [...row.querySelectorAll(':scope > .cell[id^="calendar-day-"]')];
                const segmentsById = new Map();
                cells.forEach((cell, column) => {
                    cell.querySelectorAll(':scope > .event[event-id]').forEach((element) => {
                        const id = element.getAttribute('event-id');
                        if (!segmentsById.has(id)) segmentsById.set(id, []);
                        segmentsById.get(id).push({ element, cell, column });
                    });
                });

                const segments = [...segmentsById.entries()].map(([id, copies]) => {
                    copies.sort((first, second) => first.column - second.column);
                    const calendarEvent = eventsById.get(id);
                    return {
                        id,
                        copies,
                        calendarEvent,
                        start: copies[0].column,
                        end: copies[copies.length - 1].column,
                        isRange: Boolean(calendarEvent && dateInputValue(calendarEvent.from) !== dateInputValue(calendarEvent.to)),
                    };
                }).sort((first, second) => (
                    first.start - second.start
                    || (second.end - second.start) - (first.end - first.start)
                    || first.id.localeCompare(second.id)
                ));

                const occupiedUntil = [];
                segments.forEach((segment) => {
                    let lane = occupiedUntil.findIndex((endColumn) => endColumn < segment.start);
                    if (lane === -1) lane = occupiedUntil.length;
                    occupiedUntil[lane] = segment.end;

                    const leader = segment.copies[0].element;
                    const firstCell = segment.copies[0].cell;
                    const lastCell = segment.copies[segment.copies.length - 1].cell;
                    const rowRect = row.getBoundingClientRect();
                    const firstCellRect = firstCell.getBoundingClientRect();
                    const lastCellRect = lastCell.getBoundingClientRect();
                    const horizontalInset = 7;
                    const availableWidth = Math.max(0, rowRect.right - firstCellRect.left - horizontalInset * 2);
                    const segmentWidth = Math.max(0, lastCellRect.right - firstCellRect.left - horizontalInset * 2);
                    const width = Math.min(availableWidth, segmentWidth);

                    firstCell.classList.add('calendar-range-origin');
                    leader.classList.add('calendar-lane-event');
                    leader.style.setProperty('position', 'absolute', 'important');
                    leader.style.setProperty('top', `${40 + lane * 27}px`, 'important');
                    leader.style.setProperty('left', `${horizontalInset}px`, 'important');
                    leader.style.setProperty('width', `${Math.floor(width)}px`, 'important');
                    leader.style.setProperty('max-width', `${Math.floor(availableWidth)}px`, 'important');
                    leader.style.setProperty('margin', '0', 'important');

                    if (segment.isRange) {
                        const allIndexes = copyIndexes.get(segment.id) || [];
                        const segmentIndexes = segment.copies.map(({ cell }) => Number(cell.id.match(/calendar-day-(\d+)/)?.[1])).filter(Boolean);
                        const firstIndex = Math.min(...segmentIndexes);
                        const lastIndex = Math.max(...segmentIndexes);
                        leader.classList.add('calendar-range-event');
                        leader.classList.toggle('range-continues-before', firstIndex > Math.min(...allIndexes));
                        leader.classList.toggle('range-continues-after', lastIndex < Math.max(...allIndexes));
                        segment.copies.slice(1).forEach(({ element }) => element.style.setProperty('display', 'none', 'important'));
                    }
                });

                const rowHeight = Math.max(86, 47 + occupiedUntil.length * 27);
                row.style.setProperty('min-height', `${rowHeight}px`, 'important');
            });
        };
        const scheduleCalendarRangeBars = () => {
            window.cancelAnimationFrame(rangeRenderFrame);
            rangeRenderFrame = window.requestAnimationFrame(() => window.requestAnimationFrame(renderCalendarRangeBars));
        };
        const calendarRenderObserver = new MutationObserver((mutations) => {
            const eventsWereRedrawn = mutations.some((mutation) => [...mutation.addedNodes].some((node) => (
                node.nodeType === Node.ELEMENT_NODE
                && (node.matches?.('.full-month-view .event') || node.querySelector?.('.full-month-view .event'))
            )));
            if (eventsWereRedrawn) scheduleCalendarRangeBars();
        });
        calendarRenderObserver.observe(calendarElement, { childList: true, subtree: true });
        const renderAgenda = (date = selectedDate) => {
            selectedDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());
            agendaDate.textContent = selectedDate.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' });
            const entries = persistedEvents
                .map(parseCalendarEvent)
                .filter((item) => {
                    const selectedDay = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), selectedDate.getDate()).valueOf();
                    const startDay = new Date(item.from.getFullYear(), item.from.getMonth(), item.from.getDate()).valueOf();
                    const endDay = new Date(item.to.getFullYear(), item.to.getMonth(), item.to.getDate()).valueOf();
                    return selectedDay >= startDay && selectedDay <= endDay;
                })
                .sort((first, second) => first.from - second.from);
            agendaList.replaceChildren();
            if (!entries.length) {
                const empty = document.createElement('p');
                empty.className = 'calendar-agenda-empty';
                empty.textContent = 'No events scheduled for this date.';
                agendaList.appendChild(empty);
            } else {
                entries.forEach((item) => {
                    const agendaItem = document.createElement('button');
                    const accent = document.createElement('span');
                    const content = document.createElement('span');
                    const title = document.createElement('strong');
                    const details = document.createElement('span');
                    const kind = item.customTags?.kind || 'event';
                    agendaItem.type = 'button';
                    agendaItem.className = `calendar-agenda-item${kind === 'recollection' ? ' is-recollection' : ''}${kind === 'teacher_time_off' ? ' is-time-off' : ''}`;
                    accent.className = 'calendar-agenda-accent';
                    accent.style.backgroundColor = item.color || (kind === 'recollection' ? '#f79009' : '#175cd3');
                    content.className = 'calendar-agenda-content';
                    title.textContent = item.title;
                    details.textContent = `${formatAgendaTime(item.from)}–${formatAgendaTime(item.to)}${item.location ? ` · ${item.location}` : ''}`;
                    content.append(title, details);
                    agendaItem.append(accent, content);
                    agendaItem.addEventListener('click', () => manageCalendarItem(item));
                    agendaList.appendChild(agendaItem);
                });
            }
            window.requestAnimationFrame(markSelectedCell);
        };
        const syncCalendarDate = (date) => {
            updateMonthLabel(date);
            const isTodayMonth = date.getMonth() === new Date().getMonth() && date.getFullYear() === new Date().getFullYear();
            renderAgenda(isTodayMonth ? new Date() : new Date(date.getFullYear(), date.getMonth(), 1));
        };
        const syncAudience = () => {
            if (!eventForm) return;
            const sectionField = eventForm.querySelector('[data-calendar-section-field]');
            const sectionSelect = eventForm.elements.target_id;
            const usesSection = eventForm.elements.audience.value === 'section';
            const editable = eventForm.dataset.editable === '1';
            sectionField.hidden = !usesSection;
            sectionSelect.disabled = !editable || !usesSection;
            sectionSelect.required = editable && usesSection;
            if (sectionSelect._choicesInstance) {
                editable && usesSection ? sectionSelect._choicesInstance.enable() : sectionSelect._choicesInstance.disable();
            }
        };
        const setCalendarFormEditable = (form, editable) => {
            form.dataset.editable = editable ? '1' : '0';
            form.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach((field) => {
                field.disabled = !editable;
            });
            form.querySelectorAll('select').forEach((select) => {
                if (!select._choicesInstance) return;
                editable ? select._choicesInstance.enable() : select._choicesInstance.disable();
            });
            form.querySelector('button[type="submit"]').hidden = !editable;
        };
        const setCalendarDateField = (form, name, value) => {
            const storage = form?.elements[name];
            if (!storage) return;
            storage.value = value || '';
            storage._flatpickr?.setDate(storage.value || null, false, 'Y-m-d');
        };
        const setCalendarTimeField = (form, name, value) => {
            const storage = form?.elements[name];
            if (!storage) return;
            const canonical = String(value || '').slice(0, 5);
            storage.value = canonical;
            storage._flatpickr?.setDate(canonical || null, false, 'H:i');
        };
        const openEventForm = (date = new Date(), calendarEvent = null) => {
            if (!eventDialog || !eventForm) return;
            eventForm.reset();
            const editing = Boolean(calendarEvent?.customTags?.kind === 'event' || calendarEvent?.customTags?.sourceId);
            const tags = calendarEvent?.customTags || {};
            const editable = editing
                ? canManageEvents && ['draft', 'scheduled', 'active'].includes(tags.status || 'scheduled')
                : canManageEvents;
            eventForm.elements.action.value = editing ? 'update_event' : 'create_event';
            eventForm.elements.event_id.value = editing ? (tags.sourceId || '') : '';
            const eventDate = editing
                ? (tags.eventDate || (calendarEvent.from instanceof Date ? dateInputValue(calendarEvent.from) : calendarEvent.from))
                : dateInputValue(date);
            setCalendarDateField(eventForm, 'event_date', eventDate);
            const endDate = editing ? (tags.endDate || '') : '';
            setCalendarDateField(eventForm, 'end_date', endDate);
            eventForm.elements.title.value = editing ? (calendarEvent.title || '') : '';
            eventForm.elements.venue.value = editing ? (calendarEvent.location || tags.venue || '') : '';
            eventForm.elements.description.value = editing ? (calendarEvent.description || '') : '';
            eventForm.elements.audience.value = editing ? (tags.audience || 'all_students') : 'all_students';
            eventForm.elements.target_id.value = editing && tags.targetId ? String(tags.targetId) : '';
            eventForm.elements.target_id._choicesInstance?.setChoiceByValue(eventForm.elements.target_id.value);
            eventForm.elements.attendance_required.checked = editing ? Boolean(tags.attendanceRequired) : true;
            if (eventForm.elements.suspends_classes) eventForm.elements.suspends_classes.checked = editing ? Boolean(tags.suspendsClasses) : false;
            eventForm.elements.check_out_required.checked = editing ? Boolean(tags.checkOutRequired) : false;
            Array.from(eventForm.elements['scanner_ids[]']?.options || []).forEach((option) => {
                option.selected = editing && (tags.scannerIds || []).map(Number).includes(Number(option.value));
            });
            eventForm.elements['scanner_ids[]']?._choicesInstance?.removeActiveItems();
            if (editing) eventForm.elements['scanner_ids[]']?._choicesInstance?.setChoiceByValue((tags.scannerIds || []).map(String));
            if (eventForm.elements.is_announcement) eventForm.elements.is_announcement.checked = editing ? Boolean(tags.isAnnouncement) : false;

            const startTimeVal = editing
                ? (tags.startTime ? String(tags.startTime).slice(0, 5) : (calendarEvent.from instanceof Date ? `${String(calendarEvent.from.getHours()).padStart(2, '0')}:${String(calendarEvent.from.getMinutes()).padStart(2, '0')}` : '09:00'))
                : '09:00';
            const endTimeVal = editing
                ? (tags.endTime ? String(tags.endTime).slice(0, 5) : (calendarEvent.to instanceof Date ? `${String(calendarEvent.to.getHours()).padStart(2, '0')}:${String(calendarEvent.to.getMinutes()).padStart(2, '0')}` : '11:00'))
                : '11:00';

            setCalendarTimeField(eventForm, 'start_time', startTimeVal);
            setCalendarTimeField(eventForm, 'end_time', endTimeVal);
            setCalendarTimeField(eventForm, 'check_in_start', editing ? (tags.checkInStart || '08:30') : '08:30');
            setCalendarTimeField(eventForm, 'check_in_end', editing ? (tags.checkInEnd || '09:30') : '09:30');
            const bannerPreview = eventForm.querySelector('[data-event-banner-preview]');
            const bannerPath = editing ? (tags.bannerImagePath || '') : '';
            if (bannerPath) bannerPreview.src = bannerPath;
            else bannerPreview.removeAttribute('src');
            bannerPreview.hidden = !bannerPath;
            eventDialog.querySelector('[data-calendar-dialog-title]').textContent = editing ? (editable ? 'Manage event' : 'Event details') : 'Create event';
            eventDialog.querySelector('[data-calendar-dialog-subtitle]').textContent = editing
                ? (editable ? 'Update this scheduled event or replace its banner.' : 'This event is read-only because its attendance is already in progress or finalized.')
                : 'Schedule an activity and assign its attendance audience.';
            eventForm.querySelector('button[type="submit"]').textContent = editing ? 'Save changes' : 'Create event';
            eventForm.querySelector('[data-calendar-cancel-event]').hidden = !editing || !editable;
            eventForm.querySelector('[data-calendar-form-status]').textContent = editable ? '' : 'Historical attendance is protected from schedule changes.';
            setCalendarFormEditable(eventForm, editable);
            syncEventScannerPanel(eventForm.elements.attendance_required);
            syncAudience();
            if (!eventDialog.open) eventDialog.showModal();
            window.requestAnimationFrame(() => (editable ? eventForm.elements.title : eventDialog.querySelector('[data-dialog-close]')).focus());
        };
        window.openCalendarEventForm = openEventForm;
        const openRecollectionForm = (calendarEvent) => {
            if (!recollectionDialog || !recollectionForm) return;
            recollectionForm.reset();
            const tags = calendarEvent.customTags || {};
            const editable = canManageRecollections && ['draft', 'scheduled'].includes(tags.status);
            recollectionForm.elements.recollection_id.value = tags.sourceId || '';
            recollectionForm.elements.title.value = calendarEvent.title || '';
            recollectionForm.elements.type.value = tags.type || 'Recollection';
            recollectionForm.elements.section_id.value = tags.sectionId ? String(tags.sectionId) : '';
            recollectionForm.elements.section_id._choicesInstance?.setChoiceByValue(recollectionForm.elements.section_id.value);
            recollectionForm.elements.venue.value = calendarEvent.location || '';
            recollectionForm.elements.facilitator.value = tags.facilitator || '';
            recollectionForm.elements.attendance_required.checked = Boolean(tags.attendanceRequired);
            setCalendarDateField(recollectionForm, 'date', dateInputValue(calendarEvent.from));
            setCalendarTimeField(recollectionForm, 'start_time', `${String(calendarEvent.from.getHours()).padStart(2, '0')}:${String(calendarEvent.from.getMinutes()).padStart(2, '0')}`);
            setCalendarTimeField(recollectionForm, 'end_time', `${String(calendarEvent.to.getHours()).padStart(2, '0')}:${String(calendarEvent.to.getMinutes()).padStart(2, '0')}`);
            recollectionDialog.querySelector('[data-recollection-dialog-title]').textContent = editable ? 'Manage recollection or retreat' : 'Recollection or retreat details';
            recollectionDialog.querySelector('[data-recollection-dialog-subtitle]').textContent = editable
                ? 'Update the formation activity without leaving the calendar.'
                : 'This activity is read-only because attendance is already in progress or finalized.';
            recollectionForm.querySelector('[data-calendar-cancel-recollection]').hidden = !editable;
            recollectionForm.querySelector('[data-recollection-form-status]').textContent = editable ? '' : 'Historical attendance is protected from schedule changes.';
            setCalendarFormEditable(recollectionForm, editable);
            if (!recollectionDialog.open) recollectionDialog.showModal();
            window.requestAnimationFrame(() => (editable ? recollectionForm.elements.title : recollectionDialog.querySelector('[data-dialog-close]')).focus());
        };
        const openTimeOffForm = (date = new Date(), calendarEvent = null) => {
            if (!timeOffDialog || !timeOffForm) return;
            timeOffForm.reset();
            const editing = calendarEvent?.customTags?.kind === 'teacher_time_off';
            const tags = calendarEvent?.customTags || {};
            const editable = canManageTimeOff && (!editing || Boolean(tags.editable));
            timeOffForm.elements.time_off_id.value = editing ? tags.sourceId || '' : '';
            setCalendarDateField(timeOffForm, 'starts_on', editing ? tags.startsOn : dateInputValue(date));
            setCalendarDateField(timeOffForm, 'ends_on', editing && tags.endsOn !== tags.startsOn ? tags.endsOn : '');
            timeOffForm.elements.reason.value = editing ? tags.reason || '' : '';
            timeOffDialog.querySelector('[data-time-off-dialog-title]').textContent = editing ? (editable ? 'Manage scheduled time off' : 'Teacher time off') : 'Schedule time off';
            timeOffDialog.querySelector('[data-time-off-dialog-subtitle]').textContent = editing
                ? `${tags.teacherName || 'This teacher'} will not hold classes from ${tags.startsOn} through ${tags.endsOn}.`
                : 'Classes you teach in this range will be suspended, and students will not be marked absent.';
            timeOffForm.querySelector('button[type="submit"]').textContent = editing ? 'Save changes' : 'Schedule time off';
            timeOffForm.querySelector('[data-calendar-cancel-time-off]').hidden = !editing || !editable;
            timeOffForm.querySelector('[data-time-off-form-status]').textContent = editable ? '' : 'This time-off entry is read-only.';
            setCalendarFormEditable(timeOffForm, editable);
            if (!timeOffDialog.open) timeOffDialog.showModal();
            window.requestAnimationFrame(() => (editable ? timeOffForm.elements.starts_on : timeOffDialog.querySelector('[data-dialog-close]')).focus());
        };
        const manageCalendarItem = (calendarEvent) => {
            renderAgenda(calendarEvent.from);
            const kind = calendarEvent.customTags?.kind || 'event';
            if (kind === 'teacher_time_off') {
                openTimeOffForm(calendarEvent.from, calendarEvent);
                return;
            }
            if (kind === 'recollection') {
                openRecollectionForm(calendarEvent);
                return;
            }
            openEventForm(calendarEvent.from, calendarEvent);
        };
        const restorePersistedEvents = () => {
            if (!calendar) return;
            calendar.setEvents(persistedEvents.map(parseCalendarEvent), true, false);
            scheduleCalendarRangeBars();
        };

        document.querySelectorAll('[data-calendar-create-event]').forEach((btn) => btn.addEventListener('click', () => openEventForm(new Date())));
        document.querySelector('[data-calendar-create-selected]')?.addEventListener('click', () => openEventForm(selectedDate));
        document.querySelector('[data-calendar-create-time-off]')?.addEventListener('click', () => openTimeOffForm(new Date()));
        document.querySelector('[data-calendar-previous]')?.addEventListener('click', () => calendar?.moveToPreviousMonth());
        document.querySelector('[data-calendar-next]')?.addEventListener('click', () => calendar?.moveToNextMonth());
        document.querySelector('[data-calendar-today]')?.addEventListener('click', () => calendar?.moveToToday());
        calendarElement.addEventListener('click', (event) => {
            if (event.target.closest('.event')) return;
            const cell = event.target.closest('.full-month-view .row-cells.days .cell');
            const cellIndex = Number(cell?.id.match(/^calendar-day-(\d+)$/)?.[1]);
            if (!calendar || !cellIndex) return;
            const displayDate = calendar.getCurrentDisplayDate();
            const cellDate = new Date(displayDate.getFullYear(), displayDate.getMonth(), 1);
            cellDate.setDate(cellDate.getDate() - ((cellDate.getDay() + 6) % 7) + cellIndex - 1);
            renderAgenda(cellDate);
        });
        calendarElement.addEventListener('contextmenu', (event) => {
            if (!canManageTimeOff || event.target.closest('.event')) return;
            const cell = event.target.closest('.full-month-view .row-cells.days .cell');
            const cellIndex = Number(cell?.id.match(/^calendar-day-(\d+)$/)?.[1]);
            if (!calendar || !cellIndex) return;
            event.preventDefault();
            const displayDate = calendar.getCurrentDisplayDate();
            const cellDate = new Date(displayDate.getFullYear(), displayDate.getMonth(), 1);
            cellDate.setDate(cellDate.getDate() - ((cellDate.getDay() + 6) % 7) + cellIndex - 1);
            renderAgenda(cellDate);
            openTimeOffForm(cellDate);
        });
        eventForm?.elements.audience.addEventListener('change', syncAudience);
        eventForm?.querySelector('[data-calendar-cancel-event]')?.addEventListener('click', async () => {
            if (eventForm.dataset.editable !== '1') return;
            if (!window.confirm('Cancel this event? It will be removed from the active calendar.')) return;
            const status = eventForm.querySelector('[data-calendar-form-status]');
            const formData = new FormData(eventForm);
            formData.set('action', 'cancel_event');
            try {
                const response = await fetch(eventForm.dataset.endpoint, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: formData });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to cancel the event.');
                persistedEvents = persistedEvents.filter((item) => item.id !== payload.event_id);
                restorePersistedEvents();
                renderAgenda(selectedDate);
                eventDialog.close();
                showOneTimeScannerCredentials(payload.scanner_credentials);
                setFeedback(payload.message, 'success');
            } catch (error) {
                status.textContent = error.message || 'Unable to cancel the event.';
                status.className = 'ajax-form-status is-error';
            }
        });
        eventForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (eventForm.dataset.editable !== '1' || !eventForm.reportValidity()) return;
            const status = eventForm.querySelector('[data-calendar-form-status]');
            const submitButton = eventForm.querySelector('button[type="submit"]');
            const label = submitButton.textContent;
            submitButton.disabled = true;
            const editing = eventForm.elements.action.value === 'update_event';
            submitButton.textContent = editing ? 'Saving…' : 'Creating…';
            status.textContent = 'Saving event…';
            status.className = 'ajax-form-status';
            try {
                const response = await fetch(eventForm.dataset.endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' },
                    body: new FormData(eventForm),
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to save the event.');
                if (payload.event) {
                    const existingIndex = persistedEvents.findIndex((item) => item.id === payload.event.id);
                    if (existingIndex >= 0) persistedEvents[existingIndex] = payload.event;
                    else persistedEvents.push(payload.event);
                    restorePersistedEvents();
                    renderAgenda(new Date(payload.event.from));
                }
                eventDialog.close();
                setFeedback(payload.message, 'success');
                if (typeof showToast === 'function') showToast(payload.message || 'Event saved successfully.');

                // Live-refresh the events feed section so changes reflect immediately
                try {
                    const pageResponse = await fetch(window.location.href, { credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'fetch' } });
                    const pageDocument = new DOMParser().parseFromString(await pageResponse.text(), 'text/html');
                    const incomingEventsFeed = pageDocument.querySelector('#dashboard-events-feed');
                    const currentEventsFeed = document.querySelector('#dashboard-events-feed');
                    if (incomingEventsFeed && currentEventsFeed) {
                        currentEventsFeed.replaceWith(incomingEventsFeed);
                        initEventsSocialFeed();
                    }
                } catch {}
            } catch (error) {
                status.textContent = error.message || 'Unable to save the event.';
                status.classList.add('is-error');
                if (typeof showToast === 'function') showToast(error.message || 'Unable to save the event.', 'error');
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = label;
            }
        });
        recollectionForm?.querySelector('[data-calendar-cancel-recollection]')?.addEventListener('click', async () => {
            if (recollectionForm.dataset.editable !== '1') return;
            if (!window.confirm('Cancel this recollection or retreat? It will be removed from the active calendar.')) return;
            const status = recollectionForm.querySelector('[data-recollection-form-status]');
            const formData = new FormData(recollectionForm);
            formData.set('action', 'cancel_recollection');
            try {
                const response = await fetch(recollectionForm.dataset.endpoint, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: formData });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to cancel the activity.');
                persistedEvents = persistedEvents.filter((item) => item.id !== payload.event_id);
                restorePersistedEvents();
                renderAgenda(selectedDate);
                recollectionDialog.close();
                setFeedback(payload.message, 'success');
            } catch (error) {
                status.textContent = error.message || 'Unable to cancel the activity.';
                status.className = 'ajax-form-status is-error';
            }
        });
        recollectionForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (recollectionForm.dataset.editable !== '1' || !recollectionForm.reportValidity()) return;
            const status = recollectionForm.querySelector('[data-recollection-form-status]');
            const submitButton = recollectionForm.querySelector('button[type="submit"]');
            const label = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.textContent = 'Saving\u2026';
            status.textContent = 'Saving activity\u2026';
            status.className = 'ajax-form-status';
            try {
                const response = await fetch(recollectionForm.dataset.endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    body: new FormData(recollectionForm),
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to update the activity.');
                const existingIndex = persistedEvents.findIndex((item) => item.id === payload.event.id);
                if (existingIndex >= 0) persistedEvents[existingIndex] = payload.event;
                else persistedEvents.push(payload.event);
                restorePersistedEvents();
                renderAgenda(new Date(payload.event.from));
                recollectionDialog.close();
                setFeedback(payload.message, 'success');
            } catch (error) {
                status.textContent = error.message || 'Unable to update the activity.';
                status.className = 'ajax-form-status is-error';
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = label;
            }
        });

        timeOffForm?.querySelector('[data-calendar-cancel-time-off]')?.addEventListener('click', async () => {
            if (timeOffForm.dataset.editable !== '1') return;
            if (!window.confirm('Remove this scheduled time off? Classes will return to their normal schedule.')) return;
            const status = timeOffForm.querySelector('[data-time-off-form-status]');
            const formData = new FormData(timeOffForm);
            formData.set('action', 'cancel_teacher_time_off');
            try {
                const response = await fetch(timeOffForm.dataset.endpoint, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: formData });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to remove the scheduled time off.');
                persistedEvents = persistedEvents.filter((item) => item.id !== payload.event_id);
                restorePersistedEvents();
                renderAgenda(selectedDate);
                timeOffDialog.close();
                setFeedback(payload.message, 'success');
            } catch (error) {
                status.textContent = error.message || 'Unable to remove the scheduled time off.';
                status.className = 'ajax-form-status is-error';
            }
        });
        timeOffForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (timeOffForm.dataset.editable !== '1' || !timeOffForm.reportValidity()) return;
            const status = timeOffForm.querySelector('[data-time-off-form-status]');
            const submitButton = timeOffForm.querySelector('button[type="submit"]');
            const label = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.textContent = 'Saving…';
            status.textContent = 'Checking affected classes…';
            status.className = 'ajax-form-status';
            try {
                const response = await fetch(timeOffForm.dataset.endpoint, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: new FormData(timeOffForm) });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to schedule time off.');
                const existingIndex = persistedEvents.findIndex((item) => item.id === payload.event.id);
                if (existingIndex >= 0) persistedEvents[existingIndex] = payload.event;
                else persistedEvents.push(payload.event);
                restorePersistedEvents();
                renderAgenda(new Date(payload.event.from));
                timeOffDialog.close();
                setFeedback(payload.message, 'success');
            } catch (error) {
                status.textContent = error.message || 'Unable to schedule time off.';
                status.className = 'ajax-form-status is-error';
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = label;
            }
        });

        const initializeCalendar = async () => {
            if (!window.calendarJs) {
                setFeedback('Calendar.js could not be loaded. Refresh the page to try again.', 'error');
                return;
            }
            try {
                const response = await fetch(calendarElement.dataset.endpoint, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to load calendar events.');
                persistedEvents = payload.events;
                calendar = new window.calendarJs(calendarElement.id, {
                    data: persistedEvents.map(parseCalendarEvent),
                    manualEditingEnabled: canManageEvents,
                    dragAndDropForEventsEnabled: false,
                    exportEventsEnabled: false,
                    importEventsEnabled: false,
                    autoRefreshTimerDelay: 0,
                    useAmPmForTimeDisplays: true,
                    showDayNumberOrdinals: false,
                    fullScreenModeEnabled: false,
                    weekendDays: [0],
                    useTemplateWhenAddingNewEvent: true,
                    views: {
                        fullDay: { enabled: false },
                        fullWeek: { enabled: false },
                        fullMonth: {
                            maximumEventsPerDayDisplay: 0,
                            minimumDayHeight: 86,
                            showExtraTitleBarButtons: false,
                            showPreviousNextMonthNames: false,
                            showPreviousNextYears: false,
                            showMonthButtonsInYearDropDownMenu: false,
                            showYearDropDownButtonIcon: false,
                        },
                        fullYear: { enabled: false },
                        timeline: { enabled: false },
                        allEvents: { enabled: false },
                    },
                    events: {
                        onRender: () => {
                            window.requestAnimationFrame(markSelectedCell);
                            scheduleCalendarRangeBars();
                        },
                        onPreviousMonth: syncCalendarDate,
                        onNextMonth: syncCalendarDate,
                        onToday: () => syncCalendarDate(new Date()),
                        onSetDate: syncCalendarDate,
                        onBeforeEventAddEdit: (calendarEvent) => {
                            if (!canManageEvents) return;
                            if (calendarEvent?.customTags?.kind) {
                                manageCalendarItem(calendarEvent);
                                return;
                            }
                            openEventForm(calendarEvent?.from || calendar?.getCurrentDisplayDate() || new Date());
                        },
                        onEventClick: (calendarEvent) => {
                            manageCalendarItem(calendarEvent);
                        },
                        onFullMonthEventRender: (element, calendarEvent) => {
                            element.dataset.calendarKind = calendarEvent.customTags?.kind || 'event';
                            element.setAttribute('aria-label', `${calendarEvent.title}, ${formatAgendaTime(calendarEvent.from)}`);
                            return false;
                        },
                        onEventRemoved: restorePersistedEvents,
                        onEventUpdated: restorePersistedEvents,
                        onEventDragDrop: restorePersistedEvents,
                    },
                });
                updateMonthLabel(calendar.getCurrentDisplayDate());
                renderAgenda(new Date());
                scheduleCalendarRangeBars();
                setFeedback(canManageTimeOff
                    ? 'Select a date to review its agenda. Right-click a date to schedule teaching time off.'
                    : (canManageEvents
                        ? 'Select a date to review its agenda. Click an entry to manage it, or double-click a date to create an event.'
                        : 'Select a date to review its agenda. Click an entry to view its details.'));
            } catch (error) {
                setFeedback(error.message || 'Unable to load calendar events.', 'error');
            }
        };
        if (window.ResizeObserver) new ResizeObserver(scheduleCalendarRangeBars).observe(calendarElement);
        else window.addEventListener('resize', scheduleCalendarRangeBars);
        initializeCalendar();
    }

    const showToast = (message, type = 'success', title = '') => {
        const toast = document.createElement('div');
        const icon = document.createElement('span');
        const copy = document.createElement('span');
        const heading = document.createElement('strong');
        const body = document.createElement('span');
        const close = document.createElement('button');
        const titles = { success: 'Saved', warning: 'Please check', error: 'Couldn\'t save' };

        toast.className = `app-toast${type === 'success' ? '' : ` is-${type}`}`;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        icon.className = 'app-toast-icon';
        icon.textContent = type === 'success' ? '\u2713' : '!';
        copy.className = 'app-toast-copy';
        heading.textContent = title || titles[type] || titles.success;
        body.textContent = message;
        close.type = 'button';
        close.className = 'app-toast-close';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.textContent = '\u00d7';
        copy.append(heading, body);
        toast.append(icon, copy, close);

        const remove = () => toast.remove();
        close.addEventListener('click', remove);
        const host = document.querySelector('dialog[open]') || document.querySelector('[data-toast-region]') || document.body;
        host.append(toast);
        window.setTimeout(remove, type === 'error' ? 8000 : 5000);
    };

    document.querySelectorAll('[data-grade-manager]').forEach((manager) => {
        const endpoint = manager.dataset.endpoint;
        const subjectBrowser = manager.querySelector('[data-grade-subject-browser]');
        const rosterBrowser = manager.querySelector('[data-grade-roster-browser]');
        const subjectResults = manager.querySelector('[data-grade-subject-results]');
        const rosterResults = manager.querySelector('[data-grade-roster-results]');
        const subjectSearch = manager.querySelector('[data-grade-subject-search]');
        const studentSearch = manager.querySelector('[data-grade-student-search]');
        const subjectSummary = manager.querySelector('[data-grade-subject-summary]');
        const rosterSummary = manager.querySelector('[data-grade-roster-summary]');
        const subjectLoading = manager.querySelector('[data-grade-subject-loading]');
        const rosterLoading = manager.querySelector('[data-grade-roster-loading]');
        const subjectPagination = manager.querySelector('[data-grade-subject-pagination]');
        const rosterPagination = manager.querySelector('[data-grade-roster-pagination]');
        const workspaceTitle = manager.querySelector('[data-grade-workspace-title]');
        const workspaceDescription = manager.querySelector('[data-grade-workspace-description]');
        const editDialog = document.querySelector('[data-grade-edit-dialog]');
        const editForm = editDialog.querySelector('[data-grade-edit-form]');
        const advancedDialog = document.querySelector(`#${manager.id}-advanced`);
        const advancedForm = advancedDialog?.querySelector('[data-grade-advanced-form]');
        const subjectCache = new Map();
        const rosterCache = new Map();
        let view = localStorage.getItem('rfid-grade-subject-view') === 'list' ? 'list' : 'cards';
        let subjects = [];
        let students = [];
        let currentSubject = null;
        let canReopen = false;
        let subjectController;
        let rosterController;
        let searchTimer;
        let rosterTimer;

        const element = (tag, className = '', text = '') => {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== '') node.textContent = text;
            return node;
        };
        const requestJson = async (url, options = {}) => {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', ...(options.headers || {}) },
                ...options,
            });
            let payload;
            try { payload = await response.json(); } catch { throw new Error('The server returned an invalid grade response.'); }
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'The grade request could not be completed.');
            return payload;
        };
        const number = (value) => value === null || value === undefined || value === '' ? '—' : Number(value).toFixed(2).replace(/\.00$/, '');
        const statusLabel = (status) => status === 'unentered' ? 'Not entered' : status.charAt(0).toUpperCase() + status.slice(1);
        const statusClass = (status) => `grade-status is-${status}`;
        const scheduleText = (subject) => subject.schedule?.length ? subject.schedule.join(' · ') : 'Schedule not set';

        const renderPagination = (host, pagination, load) => {
            host.replaceChildren();
            if (!pagination || pagination.pages <= 1) return;
            const start = Math.max(1, pagination.page - 2);
            const end = Math.min(pagination.pages, pagination.page + 2);
            for (let page = start; page <= end; page += 1) {
                const button = element('button', page === pagination.page ? 'is-active' : '', String(page));
                button.type = 'button';
                if (page === pagination.page) button.setAttribute('aria-current', 'page');
                button.addEventListener('click', () => load(page));
                host.append(button);
            }
        };
        const updateViewButtons = () => {
            manager.querySelectorAll('[data-grade-view]').forEach((button) => {
                const active = button.dataset.gradeView === view;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        };
        const subjectButton = (subject, listMode = false) => {
            const button = element('button', listMode ? 'grade-subject-list-row' : 'grade-subject-card');
            button.type = 'button';
            button.dataset.gradeSubjectId = subject.id;
            button.setAttribute('aria-label', `Open ${subject.course_code}, ${subject.section_name}`);
            if (listMode) {
                [
                    [subject.course_code, subject.course_title],
                    [subject.section_name, `${subject.program_code} · ${subject.program_name}`],
                    [`${subject.graded_count} of ${subject.student_count}`, 'Grades entered'],
                    [subject.class_average === null ? '—' : number(subject.class_average), 'Class average'],
                ].forEach(([primary, secondary]) => {
                    const cell = element('span', 'grade-subject-list-cell');
                    cell.append(element('strong', '', primary), element('small', '', secondary));
                    button.append(cell);
                });
                const arrow = element('span', 'grade-subject-arrow');
                arrow.innerHTML = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7.5 5 5 5-5 5"/></svg>';
                button.append(arrow);
                return button;
            }
            const heading = element('span', 'grade-subject-card-heading');
            const code = element('span', 'grade-subject-code', subject.course_code);
            const term = element('span', 'grade-subject-term', subject.semester_name);
            heading.append(code, term);
            const title = element('strong', 'grade-subject-title', subject.course_title);
            const section = element('span', 'grade-subject-section', `${subject.program_code} · ${subject.section_name}`);
            const schedule = element('span', 'grade-subject-schedule');
            schedule.innerHTML = '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="M10 6v4l3 2"/></svg>';
            schedule.append(document.createTextNode(scheduleText(subject)));
            const progress = element('span', 'grade-subject-progress');
            const progressCopy = element('span');
            progressCopy.append(element('strong', '', `${subject.graded_count}/${subject.student_count}`), document.createTextNode(' graded'));
            const bar = element('span', 'grade-progress-track');
            const fill = element('i');
            fill.style.width = `${subject.student_count ? Math.min(100, subject.graded_count / subject.student_count * 100) : 0}%`;
            bar.append(fill);
            progress.append(progressCopy, bar);
            const footer = element('span', 'grade-subject-footer');
            footer.append(element('span', '', subject.teacher_names || 'No teacher assigned'), element('span', '', 'Open roster →'));
            button.append(heading, title, section, schedule, progress, footer);
            return button;
        };
        const renderSubjects = (payload) => {
            subjects = payload.subjects;
            subjectResults.replaceChildren();
            subjectResults.className = view === 'cards' ? 'grade-subject-grid' : 'grade-subject-list';
            if (!subjects.length) {
                const empty = element('div', 'grade-empty-state');
                empty.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5h16v13H4zM8 9h8M8 13h5"/></svg>';
                empty.append(element('strong', '', 'No subjects found'), element('span', '', subjectSearch.value ? 'Try a different course code, title, program, or section.' : 'No active subjects are currently available for grade management.'));
                subjectResults.append(empty);
            } else if (view === 'cards') {
                subjects.forEach((subject) => subjectResults.append(subjectButton(subject)));
            } else {
                const header = element('div', 'grade-subject-list-header');
                ['Subject', 'Class', 'Progress', 'Average', ''].forEach((label) => header.append(element('span', '', label)));
                subjectResults.append(header);
                subjects.forEach((subject) => subjectResults.append(subjectButton(subject, true)));
            }
            const { total, page, pages } = payload.pagination;
            subjectSummary.textContent = `${total} subject${total === 1 ? '' : 's'}${pages > 1 ? ` · Page ${page} of ${pages}` : ''}`;
            renderPagination(subjectPagination, payload.pagination, loadSubjects);
        };
        const loadSubjects = async (page = 1, force = false) => {
            const query = subjectSearch.value.trim();
            const key = `${query}|${page}`;
            if (!force && subjectCache.has(key)) { renderSubjects(subjectCache.get(key)); return; }
            subjectController?.abort();
            subjectController = new AbortController();
            subjectLoading.hidden = false;
            subjectResults.setAttribute('aria-busy', 'true');
            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('mode', 'subjects');
            url.searchParams.set('q', query);
            url.searchParams.set('page', page);
            try {
                const payload = await requestJson(url, { signal: subjectController.signal });
                subjectCache.set(key, payload);
                renderSubjects(payload);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    subjectSummary.textContent = error.message;
                    showToast(error.message, 'error', 'Unable to load subjects');
                }
            } finally {
                subjectLoading.hidden = true;
                subjectResults.removeAttribute('aria-busy');
            }
        };
        const studentRow = (student) => {
            const row = document.createElement('tr');
            row.dataset.gradeStudentId = student.student_id;
            const identityCell = document.createElement('td');
            const identity = element('a', 'grade-student-cell');
            identity.href = `/index/?page=profile&user_id=${encodeURIComponent(student.student_id)}`;
            identity.title = `View ${student.student_name}'s profile`;
            const avatar = document.createElement('img');
            avatar.src = student.profile_image_path || '/assets/images/default-avatar.svg';
            avatar.alt = '';
            avatar.loading = 'lazy';
            const copy = element('span');
            copy.append(element('strong', '', student.student_name), element('small', '', student.school_id));
            identity.append(avatar, copy);
            identityCell.append(identity);
            [student.prelim, student.midterm, student.final].forEach((value) => {
                const cell = document.createElement('td');
                cell.textContent = number(value);
                row.append(cell);
            });
            const overall = document.createElement('td');
            overall.append(element('strong', '', number(student.overall)));
            const status = document.createElement('td');
            status.append(element('span', statusClass(student.status), statusLabel(student.status)));
            const action = document.createElement('td');
            const edit = element('button', 'icon-action-button');
            edit.type = 'button';
            edit.dataset.gradeEditStudent = student.student_id;
            edit.title = student.status === 'finalized' ? 'Review finalized grade' : 'Edit grade';
            edit.setAttribute('aria-label', `${edit.title} for ${student.student_name}`);
            edit.innerHTML = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M11.5 4.5H5.8A1.8 1.8 0 0 0 4 6.3v7.9A1.8 1.8 0 0 0 5.8 16h7.9a1.8 1.8 0 0 0 1.8-1.8V8.5"/><path d="m10 11 1.1-3.1 4.6-4.6a1.4 1.4 0 0 1 2 2l-4.6 4.6L10 11Z"/></svg>';
            action.append(edit);
            row.prepend(identityCell);
            row.append(overall, status, action);
            return row;
        };
        const renderRoster = (payload) => {
            currentSubject = payload.subject;
            students = payload.students;
            canReopen = payload.can_reopen;
            manager.querySelector('[data-grade-subject-name]').textContent = `${currentSubject.course_code} — ${currentSubject.course_title}`;
            manager.querySelector('[data-grade-subject-detail]').textContent = `${currentSubject.program_code} · ${currentSubject.section_name} · ${currentSubject.school_year_name} · ${currentSubject.semester_name}`;
            rosterResults.replaceChildren();
            students.forEach((student) => rosterResults.append(studentRow(student)));
            if (!students.length) {
                const row = document.createElement('tr');
                const cell = element('td', 'empty-state', studentSearch.value ? 'No students match that search.' : 'No active students are enrolled in this subject.');
                cell.colSpan = 7;
                row.append(cell);
                rosterResults.append(row);
            }
            const { total, page, pages } = payload.pagination;
            rosterSummary.textContent = `${total} student${total === 1 ? '' : 's'}${pages > 1 ? ` · Page ${page} of ${pages}` : ''}`;
            renderPagination(rosterPagination, payload.pagination, loadRoster);
        };
        const loadRoster = async (page = 1, force = false) => {
            if (!currentSubject?.id) return;
            const query = studentSearch.value.trim();
            if (query.length === 1) {
                rosterSummary.textContent = 'Type at least 2 characters to search.';
                return;
            }
            const key = `${currentSubject.id}|${query}|${page}`;
            if (!force && rosterCache.has(key)) {
                const cached = rosterCache.get(key);
                renderRoster(cached);
                return cached;
            }
            rosterController?.abort();
            rosterController = new AbortController();
            rosterLoading.hidden = false;
            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('mode', 'roster');
            url.searchParams.set('offering_id', currentSubject.id);
            url.searchParams.set('q', query);
            url.searchParams.set('page', page);
            try {
                const payload = await requestJson(url, { signal: rosterController.signal });
                rosterCache.set(key, payload);
                renderRoster(payload);
                return payload;
            } catch (error) {
                if (error.name !== 'AbortError') {
                    rosterSummary.textContent = error.message;
                    showToast(error.message, 'error', 'Unable to load roster');
                }
            } finally { rosterLoading.hidden = true; }
        };
        const showSubjects = () => {
            currentSubject = null;
            rosterBrowser.hidden = true;
            subjectBrowser.hidden = false;
            workspaceTitle.textContent = 'Assigned subjects';
            workspaceDescription.textContent = 'Choose a subject to open its class roster and manage student grades.';
            if (!subjects.length) loadSubjects();
        };
        const openSubject = (subject) => {
            currentSubject = subject;
            studentSearch.value = '';
            subjectBrowser.hidden = true;
            rosterBrowser.hidden = false;
            workspaceTitle.textContent = 'Class roster';
            workspaceDescription.textContent = 'Search students, review recorded grades, or use the edit action to make changes.';
            loadRoster(1);
        };
        const calculateOverall = (form) => {
            const rawValues = ['prelim', 'midterm', 'final'].map((name) => form.elements[name].value.trim());
            const values = rawValues.map(Number);
            const preview = form.querySelector('[data-grade-overall-preview]');
            preview.textContent = rawValues.every((value) => value !== '') && values.every(Number.isFinite)
                ? number(values.reduce((sum, value) => sum + value, 0) / 3)
                : '—';
        };
        const populateEditForm = (student) => {
            editForm.reset();
            editForm.elements.class_offering_id.value = currentSubject.id;
            editForm.elements.student_id.value = student.student_id;
            editForm.elements.grade_id.value = student.grade_id || '';
            ['prelim', 'midterm', 'final'].forEach((name) => { editForm.elements[name].value = student[name] ?? ''; });
            editForm.querySelector('[data-grade-student-avatar]').src = student.profile_image_path || '/assets/images/default-avatar.svg';
            editForm.querySelector('[data-grade-student-name]').textContent = student.student_name;
            editForm.querySelector('[data-grade-student-id]').textContent = student.school_id;
            editDialog.querySelector('[data-grade-edit-subject]').textContent = `${currentSubject.course_code} · ${currentSubject.section_name}`;
            const status = editForm.querySelector('[data-grade-edit-status]');
            status.className = statusClass(student.status);
            status.textContent = statusLabel(student.status);
            const finalized = student.status === 'finalized';
            ['prelim', 'midterm', 'final'].forEach((name) => { editForm.elements[name].disabled = finalized; });
            editForm.querySelector('[data-grade-save-draft]').hidden = finalized;
            editForm.querySelector('[data-grade-finalize]').hidden = finalized;
            editForm.querySelector('[data-grade-reopen]').hidden = !finalized || !canReopen;
            editForm.querySelector('[data-grade-finalized-note]').hidden = !finalized;
            editForm.querySelector('[data-grade-edit-feedback]').textContent = '';
            calculateOverall(editForm);
            if (!editDialog.open) editDialog.showModal();
            window.requestAnimationFrame(() => {
                const target = finalized
                    ? (canReopen ? editForm.querySelector('[data-grade-reopen]') : editDialog.querySelector('[data-dialog-close]'))
                    : editForm.elements.prelim;
                target?.focus();
            });
        };
        const saveForm = async (form, submitter, feedback) => {
            if (!form.reportValidity()) return;
            const data = new FormData(form);
            if (submitter?.hasAttribute('data-grade-finalize')) data.set('finalize', '1');
            const buttons = [...form.querySelectorAll('button[type="submit"]')];
            buttons.forEach((button) => { button.disabled = true; });
            feedback.classList.remove('is-error');
            feedback.textContent = submitter?.hasAttribute('data-grade-finalize') ? 'Finalizing grade…' : 'Saving draft…';
            try {
                const payload = await requestJson(endpoint, { method: 'POST', body: data });
                subjectCache.clear();
                rosterCache.clear();
                feedback.textContent = payload.message;
                if (Number(currentSubject?.id) === Number(data.get('class_offering_id'))) await loadRoster(1, true);
                form.closest('dialog')?.close();
                showToast(payload.message);
            } catch (error) {
                feedback.textContent = error.message;
                feedback.classList.add('is-error');
                showToast(error.message, 'error', 'Grade not saved');
            } finally { buttons.forEach((button) => { button.disabled = false; }); }
        };

        manager.querySelectorAll('[data-grade-view]').forEach((button) => button.addEventListener('click', () => {
            view = button.dataset.gradeView;
            localStorage.setItem('rfid-grade-subject-view', view);
            updateViewButtons();
            renderSubjects({ subjects, pagination: { total: subjects.length, page: 1, pages: 1 } });
            loadSubjects(1);
        }));
        subjectResults.addEventListener('click', (event) => {
            const button = event.target.closest('[data-grade-subject-id]');
            if (!button) return;
            const subject = subjects.find((item) => Number(item.id) === Number(button.dataset.gradeSubjectId));
            if (subject) openSubject(subject);
        });
        rosterResults.addEventListener('click', (event) => {
            const button = event.target.closest('[data-grade-edit-student]');
            if (!button) return;
            const student = students.find((item) => Number(item.student_id) === Number(button.dataset.gradeEditStudent));
            if (student) populateEditForm(student);
        });
        manager.querySelector('[data-grade-back]').addEventListener('click', showSubjects);
        subjectSearch.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => loadSubjects(1), 280);
        });
        studentSearch.addEventListener('input', () => {
            window.clearTimeout(rosterTimer);
            rosterTimer = window.setTimeout(() => loadRoster(1), 280);
        });
        editForm.querySelectorAll('input[type="number"]').forEach((input) => input.addEventListener('input', () => calculateOverall(editForm)));
        editForm.addEventListener('submit', (event) => { event.preventDefault(); saveForm(editForm, event.submitter, editForm.querySelector('[data-grade-edit-feedback]')); });
        editForm.querySelector('[data-grade-reopen]').addEventListener('click', async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            const data = new FormData();
            data.set('_token', editForm.elements._token.value);
            data.set('action', 'reopen_grade');
            data.set('grade_id', editForm.elements.grade_id.value);
            try {
                const payload = await requestJson(endpoint, { method: 'POST', body: data });
                subjectCache.clear(); rosterCache.clear();
                await loadRoster(1, true);
                const refreshed = students.find((student) => Number(student.student_id) === Number(payload.student.student_id)) || payload.student;
                populateEditForm(refreshed);
                showToast(payload.message);
            } catch (error) { showToast(error.message, 'error', 'Grade not reopened'); }
            finally { button.disabled = false; }
        });
        advancedForm?.addEventListener('submit', (event) => { event.preventDefault(); saveForm(advancedForm, event.submitter, advancedForm.querySelector('[data-grade-advanced-feedback]')); });
        advancedForm?.querySelector('[data-remote-user-lookup]')?.addEventListener('remote-user:selected', async (event) => {
            const offeringId = Number(advancedForm.elements.class_offering_id.value);
            if (!offeringId) return;
            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('mode', 'roster');
            url.searchParams.set('offering_id', offeringId);
            url.searchParams.set('q', event.detail.user.identifier || event.detail.user.label);
            try {
                const payload = await requestJson(url);
                const student = payload.students.find((item) => Number(item.student_id) === Number(event.detail.user.id));
                if (!student) return;
                const finalized = student.status === 'finalized';
                ['prelim', 'midterm', 'final'].forEach((name) => {
                    advancedForm.elements[name].value = student[name] ?? '';
                    advancedForm.elements[name].disabled = finalized;
                });
                advancedForm.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = finalized; });
                const feedback = advancedForm.querySelector('[data-grade-advanced-feedback]');
                feedback.textContent = student.status === 'finalized' ? 'This grade is finalized. Open the subject roster to review or reopen it.' : student.status === 'draft' ? 'Existing draft loaded.' : 'No grade entered yet.';
            } catch (error) { advancedForm.querySelector('[data-grade-advanced-feedback]').textContent = error.message; }
        });
        updateViewButtons();
        const gradeUrlParameters = new URLSearchParams(window.location.search);
        const linkedOfferingId = Number(gradeUrlParameters.get('offering_id'));
        if (linkedOfferingId > 0 && gradeUrlParameters.get('view') !== 'personal') openSubject({ id: linkedOfferingId });
        else loadSubjects();
    });

    const refreshDataTables = (responseDocument) => {
        const currentTables = [...document.querySelectorAll('.table-wrap table:not([data-no-datatable])')];
        const responseTables = [...responseDocument.querySelectorAll('.table-wrap table:not([data-no-datatable])')];
        currentTables.forEach((table, index) => {
            const responseTable = responseTables[index];
            if (!responseTable) return;
            try { table._dataTableInstance?.destroy(); } catch { /* The table can still be refreshed without the plugin. */ }
            table._dataTableInstance = null;
            table.innerHTML = responseTable.innerHTML;
            initializeDataTable(table);
        });
    };

    const mergeSelectOptions = (selector, responseDocument) => {
        const incomingOptions = [...responseDocument.querySelectorAll(selector)]
            .flatMap((select) => [...select.options])
            .filter((option) => option.value !== '');
        if (!incomingOptions.length) return;

        document.querySelectorAll(selector).forEach((select) => {
            const selectedValue = select.value;
            const knownValues = new Set([...select.options].map((option) => option.value));
            const newOptions = incomingOptions.filter((option) => !knownValues.has(option.value));
            if (!newOptions.length) return;

            if (select._choicesInstance) {
                select._choicesInstance.destroy();
                select._choicesInstance = null;
            }
            newOptions.forEach((option) => select.add(option.cloneNode(true)));
            select.value = selectedValue;
            initializeSearchableSelect(select);
            select._choicesInstance?.setChoiceByValue(selectedValue);
        });
    };

    const syncSuccessfulAction = (action, responseDocument, submittedForm) => {
        refreshDataTables(responseDocument);

        if (action === 'create_course') mergeSelectOptions('select[name="course_id"]', responseDocument);
        if (action === 'create_room') mergeSelectOptions('select[name="room_id"]', responseDocument);
        if (action === 'create_event') mergeSelectOptions('select[name="event_id"]', responseDocument);
        if (action === 'create_recollection') mergeSelectOptions('select[name="recollection_id"]', responseDocument);
        if (action === 'create_role') {
            mergeSelectOptions('select[name="role_id"]', responseDocument);
            mergeSelectOptions('select[name="role_ids[]"]', responseDocument);
            const workspace = document.querySelector('[data-permission-workspace]');
            const responseWorkspace = responseDocument.querySelector('[data-permission-workspace]');
            if (workspace && responseWorkspace) workspace.innerHTML = responseWorkspace.innerHTML;
        }
        if (action === 'create_section') {
            const options = [...(responseDocument.querySelector('[data-offering-section]')?.options || [])]
                .filter((option) => option.value !== '')
                .map((option) => ({
                    value: option.value,
                    label: option.textContent,
                    programId: option.dataset.programId,
                }));
            document.querySelector('[data-create-offering-form]')?.dispatchEvent(new CustomEvent('ajax:sections-updated', {
                detail: { options },
            }));
        }
        if (action === 'create_user') {
            document.querySelector('[data-live-filter]')?.dispatchEvent(new CustomEvent('live-filter:refresh'));
        }
        if (action === 'issue_violation') {
            document.querySelector('[data-live-filter]')?.dispatchEvent(new CustomEvent('live-filter:refresh'));
        }
        if (action === 'update_role_permissions') {
            const roleId = submittedForm.elements.role_id?.value;
            const panel = document.querySelector(`[data-role-panel="${roleId}"]`);
            const responsePanel = responseDocument.querySelector(`[data-role-panel="${roleId}"]`);
            if (panel && responsePanel) panel.innerHTML = responsePanel.innerHTML;
        }
        if (action === 'update_role_metadata' || action === 'delete_role') {
            const workspace = document.querySelector('[data-permission-workspace]');
            const responseWorkspace = responseDocument.querySelector('[data-permission-workspace]');
            if (workspace && responseWorkspace) workspace.innerHTML = responseWorkspace.innerHTML;
        }
        if (action === 'update_academic_calendar') {
            const responseForm = responseDocument.querySelector('[data-academic-calendar-form]');
            let records = {};
            try { records = JSON.parse(responseForm?.dataset.calendarRecords || '{}'); } catch { records = {}; }
            submittedForm.dispatchEvent(new CustomEvent('ajax:calendar-updated', { detail: { records } }));
        }
        if (action === 'update_department_days_off') {
            const responseForm = responseDocument.querySelector('[data-department-days-form]');
            let policies = {};
            try { policies = JSON.parse(responseForm?.dataset.policies || '{}'); } catch { policies = {}; }
            submittedForm.dispatchEvent(new CustomEvent('ajax:department-days-updated', { detail: { policies } }));
        }
    };

    const resetSuccessfulForm = (form) => {
        form.reset();
        form.dispatchEvent(new CustomEvent('ajax:reset-lookups'));
        form.querySelectorAll('select').forEach((select) => {
            select._choicesInstance?.setChoiceByValue(select.value);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        form.querySelectorAll('input[data-picker-type]').forEach((input) => {
            input._flatpickr?.setDate(input.value || null, false, input.dataset.pickerType === 'time' ? 'H:i' : 'Y-m-d');
        });
        form.querySelectorAll('[data-remote-user-results], [data-user-lookup-results]').forEach((results) => {
            results.hidden = true;
            results.replaceChildren();
        });
        form.querySelectorAll('[data-event-banner-preview]').forEach((preview) => {
            preview.removeAttribute('src');
            preview.hidden = true;
        });
    };

    if (document.body.dataset.adminArea === '1') {
    const dedicatedAjaxForms = '[data-user-manage-form], [data-rfid-registration], [data-event-edit-form], [data-calendar-event-form], [data-calendar-recollection-form], [data-grade-edit-form], [data-grade-advanced-form], [data-attendance-edit-form], [data-attendance-advanced-form], [data-attendance-rules-form], [data-facility-status-form], [data-no-ajax]';
        const resetActions = new Set([
            'create_course', 'create_section', 'create_offering', 'create_room', 'create_device',
            'create_event', 'create_recollection', 'create_role', 'create_user', 'send_message',
            'issue_violation',
        ]);

        document.addEventListener('submit', async (event) => {
            const form = event.target.closest('form');
            if (!form || !form.closest('.page-content') || form.method.toLowerCase() !== 'post') return;
            if (form.matches(dedicatedAjaxForms) || event.defaultPrevented) return;
            if (!form.reportValidity()) return;

            event.preventDefault();
            if (form.dataset.ajaxSubmitting === '1') return;
            form.dataset.ajaxSubmitting = '1';

            const action = form.elements.action?.value || '';
            const submitButton = event.submitter || form.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
            const inputButton = submitButton instanceof HTMLInputElement;
            const iconButton = submitButton?.classList.contains('icon-action-button');
            const originalLabel = submitButton ? (inputButton ? submitButton.value : submitButton.textContent) : '';
            const originalMarkup = !inputButton && submitButton ? submitButton.innerHTML : '';
            if (submitButton) {
                submitButton.disabled = true;
                if (inputButton) submitButton.value = 'Saving\u2026';
                else if (!iconButton) submitButton.textContent = 'Saving\u2026';
            }

            try {
                const response = await fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'text/html', 'X-Requested-With': 'fetch' },
                    body: new FormData(form),
                });
                if (response.redirected && new URL(response.url).pathname.includes('/auth/login')) {
                    throw new Error('Your session expired. Sign in again, then retry your changes.');
                }

                const responseHtml = await response.text();
                const responseDocument = new DOMParser().parseFromString(responseHtml, 'text/html');
                const errorAlert = responseDocument.querySelector('.page-content-inner > .alert-danger');
                const successAlert = responseDocument.querySelector('.page-content-inner > .alert-success');
                if (!response.ok || errorAlert) {
                    throw new Error(errorAlert?.textContent.trim() || `The request could not be completed (${response.status}).`);
                }

                syncSuccessfulAction(action, responseDocument, form);
                if (resetActions.has(action) && form.isConnected) resetSuccessfulForm(form);
                if (['create_role', 'create_user', 'update_role_metadata', 'delete_role', 'issue_violation'].includes(action)) form.closest('dialog')?.close();
                showToast(successAlert?.textContent.trim() || 'Your changes were saved successfully.');
            } catch (error) {
                showToast(error.message || 'The request could not be completed. Check the form and try again.', 'error');
            } finally {
                delete form.dataset.ajaxSubmitting;
                if (submitButton?.isConnected) {
                    submitButton.disabled = false;
                    if (inputButton) submitButton.value = originalLabel;
                    else if (iconButton) submitButton.innerHTML = originalMarkup;
                    else submitButton.textContent = originalLabel;
                }
            }
        });
    }

    // ==========================================================================
    // Events Showcase (Categorized Horizontal Layout & Carousel Pagination)
    // ==========================================================================
    const initEventsSocialFeed = () => {
        const feedSection = document.querySelector('#dashboard-events-feed, [data-section-target="#dashboard-events-feed"], .events-section, .events-feed-section');
        if (!feedSection) return;

        // 1. Photo Lightbox Modal
        const lightbox = document.querySelector('#event-lightbox-dialog');
        const lightboxImg = lightbox?.querySelector('[data-lightbox-image]');
        const lightboxCounter = lightbox?.querySelector('[data-lightbox-counter]');
        const lightboxPrev = lightbox?.querySelector('[data-lightbox-prev]');
        const lightboxNext = lightbox?.querySelector('[data-lightbox-next]');

        let currentGalleryImages = [];
        let currentImageIndex = 0;

        const updateLightboxView = () => {
            if (!lightboxImg || !currentGalleryImages.length) return;
            lightboxImg.src = currentGalleryImages[currentImageIndex];
            if (lightboxCounter) {
                lightboxCounter.textContent = `${currentImageIndex + 1} / ${currentGalleryImages.length}`;
            }
            if (lightboxPrev) lightboxPrev.style.display = currentGalleryImages.length > 1 ? '' : 'none';
            if (lightboxNext) lightboxNext.style.display = currentGalleryImages.length > 1 ? '' : 'none';
        };

        document.querySelectorAll('[data-open-lightbox]').forEach((item) => {
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                const card = item.closest('.event-card, .event-post-card, .event-horizontal-card');
                if (!card) return;
                try {
                    currentGalleryImages = JSON.parse(card.dataset.images || '[]');
                } catch {
                    currentGalleryImages = [];
                }
                if (!currentGalleryImages.length) return;
                currentImageIndex = parseInt(item.dataset.openLightbox || '0', 10);
                if (currentImageIndex < 0 || currentImageIndex >= currentGalleryImages.length) {
                    currentImageIndex = 0;
                }
                updateLightboxView();
                if (lightbox && !lightbox.open) {
                    lightbox.showModal();
                }
            });
        });

        lightboxPrev?.addEventListener('click', () => {
            if (!currentGalleryImages.length) return;
            currentImageIndex = (currentImageIndex - 1 + currentGalleryImages.length) % currentGalleryImages.length;
            updateLightboxView();
        });

        lightboxNext?.addEventListener('click', () => {
            if (!currentGalleryImages.length) return;
            currentImageIndex = (currentImageIndex + 1) % currentGalleryImages.length;
            updateLightboxView();
        });

        window.addEventListener('keydown', (e) => {
            if (!lightbox || !lightbox.open) return;
            if (e.key === 'ArrowLeft') {
                lightboxPrev?.click();
            } else if (e.key === 'ArrowRight') {
                lightboxNext?.click();
            }
        });

        // 2. See more / See less text clamp
        document.querySelectorAll('[data-expandable-description]').forEach((container) => {
            const textEl = container.querySelector('.event-description-text');
            const btn = container.querySelector('[data-see-more-btn]');
            if (!textEl || !btn) return;
            if (textEl.textContent.trim().length > 130 || textEl.scrollHeight > 45) {
                container.classList.add('is-clamped');
                btn.hidden = false;
                btn.addEventListener('click', () => {
                    const isClamped = container.classList.toggle('is-clamped');
                    btn.textContent = isClamped ? 'See more' : 'See less';
                });
            }
        });

        // 3. Category Tabs Filtering
        const categoryTabs = document.querySelectorAll('[data-category-tabs] [data-tab-filter]');
        const categoryBlocks = document.querySelectorAll('.events-category-group[data-category-section], .event-category-block[data-category-section]');

        categoryTabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                categoryTabs.forEach((t) => t.classList.remove('is-active'));
                tab.classList.add('is-active');
                const filter = tab.dataset.tabFilter;

                categoryBlocks.forEach((block) => {
                    if (filter === 'all') {
                        block.hidden = false;
                    } else {
                        block.hidden = block.dataset.categorySection !== filter;
                    }
                });
            });
        });

        // 4. Horizontal Track Carousel Pagination (Load 5 at a time)
        document.querySelectorAll('[data-carousel-track]').forEach((track) => {
            const sectionName = track.dataset.carouselTrack;
            const controls = document.querySelector(`[data-carousel-controls="${sectionName}"]`);
            if (!controls) return;

            const prevBtn = controls.querySelector('[data-carousel-prev]');
            const nextBtn = controls.querySelector('[data-carousel-next]');
            const indicator = controls.querySelector('[data-carousel-indicator]');
            const cards = track.querySelectorAll('.event-card, .event-horizontal-card');

            const pageSize = 5;
            const totalPages = Math.max(1, Math.ceil(cards.length / pageSize));
            let currentPage = 0;

            const updateControls = () => {
                if (indicator) {
                    indicator.textContent = `${currentPage + 1} / ${totalPages}`;
                }
                if (prevBtn) prevBtn.disabled = currentPage <= 0;
                if (nextBtn) nextBtn.disabled = currentPage >= totalPages - 1;
            };

            const scrollToPage = (pageIndex) => {
                currentPage = Math.max(0, Math.min(pageIndex, totalPages - 1));
                const targetCardIndex = currentPage * pageSize;
                const targetCard = cards[targetCardIndex];
                if (targetCard) {
                    track.scrollTo({
                        left: targetCard.offsetLeft - track.offsetLeft,
                        behavior: 'smooth',
                    });
                }
                updateControls();
            };

            prevBtn?.addEventListener('click', () => scrollToPage(currentPage - 1));
            nextBtn?.addEventListener('click', () => scrollToPage(currentPage + 1));

            // Sync indicator on scroll
            let scrollTimer;
            track.addEventListener('scroll', () => {
                clearTimeout(scrollTimer);
                scrollTimer = setTimeout(() => {
                    if (!cards.length) return;
                    const scrollLeft = track.scrollLeft;
                    let closestCardIndex = 0;
                    cards.forEach((card, idx) => {
                        const dist = Math.abs(card.offsetLeft - track.offsetLeft - scrollLeft);
                        const closestDist = Math.abs(cards[closestCardIndex].offsetLeft - track.offsetLeft - scrollLeft);
                        if (dist < closestDist) closestCardIndex = idx;
                    });
                    currentPage = Math.floor(closestCardIndex / pageSize);
                    updateControls();
                }, 100);
            });

            updateControls();
        });

        // 5. 3-Dots Dropdown Menu & Event Actions (Edit / Delete)
        document.addEventListener('click', (e) => {
            const menuBtn = e.target.closest('[data-card-menu-btn]');
            const allDropdowns = document.querySelectorAll('[data-card-dropdown]');

            if (menuBtn) {
                e.stopPropagation();
                const dropdown = menuBtn.closest('[data-card-menu-wrap]')?.querySelector('[data-card-dropdown]');
                const isCurrentlyOpen = dropdown && !dropdown.hidden;
                allDropdowns.forEach((d) => (d.hidden = true));
                if (dropdown) dropdown.hidden = isCurrentlyOpen;
                return;
            }

            // Close dropdowns when clicking outside
            if (!e.target.closest('[data-card-menu-wrap]')) {
                allDropdowns.forEach((d) => (d.hidden = true));
            }
        });

        // Handle Edit / Delete actions
        document.querySelectorAll('[data-event-action]').forEach((btn) => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const action = btn.dataset.eventAction;
                const eventId = btn.dataset.eventId;
                const eventTitle = btn.dataset.eventTitle || 'this event';
                const dropdown = btn.closest('[data-card-dropdown]');
                if (dropdown) dropdown.hidden = true;

                if (action === 'edit') {
                    const card = btn.closest('.event-card, .event-horizontal-card');
                    let rawData = null;
                    try {
                        rawData = JSON.parse(card?.dataset?.eventData || '{}');
                    } catch {}

                    if (rawData && rawData.id && typeof window.openCalendarEventForm === 'function') {
                        const startFrom = rawData.event_date ? new Date(`${rawData.event_date}T${(rawData.start_time || '09:00').slice(0, 5)}:00`) : new Date();
                        const endTo = rawData.event_date ? new Date(`${rawData.end_date || rawData.event_date}T${(rawData.end_time || '11:00').slice(0, 5)}:00`) : new Date();

                        const syntheticEvent = {
                            title: rawData.title || '',
                            location: rawData.venue || '',
                            description: rawData.description || '',
                            from: startFrom,
                            to: endTo,
                            customTags: {
                                kind: 'event',
                                sourceId: rawData.id,
                                status: rawData.status || 'scheduled',
                                eventDate: rawData.event_date || '',
                                endDate: rawData.end_date || '',
                                startTime: rawData.start_time || '',
                                endTime: rawData.end_time || '',
                                audience: rawData.audience || 'everyone',
                                targetId: rawData.target_id || '',
                                attendanceRequired: rawData.attendance_required,
                                suspendsClasses: rawData.suspends_classes,
                                checkOutRequired: rawData.check_out_required,
                                isAnnouncement: rawData.is_announcement,
                                checkInStart: rawData.check_in_start || '08:30',
                                checkInEnd: rawData.check_in_end || '09:30',
                                bannerImagePath: rawData.banner_image_path || (rawData.images ? rawData.images[0] : ''),
                            }
                        };
                        window.openCalendarEventForm(startFrom, syntheticEvent);
                    } else {
                        const calendarEntry = document.querySelector(`[data-event-id="event-${eventId}"]`);
                        if (calendarEntry) {
                            calendarEntry.click();
                        }
                    }
                } else if (action === 'delete') {
                    const confirmed = window.confirmDestructiveAction
                        ? await window.confirmDestructiveAction({
                            title: 'Delete event?',
                            name: eventTitle,
                            message: `Are you sure you want to permanently delete "${eventTitle}"?`,
                            warning: 'Events with attendance history cannot be deleted.',
                        })
                        : window.confirm(`Are you sure you want to permanently delete "${eventTitle}"?`);

                    if (!confirmed) return;

                    const tokenInput = document.querySelector('input[name="_token"]');
                    const token = tokenInput ? tokenInput.value : '';
                    const formData = new FormData();
                    formData.set('_token', token);
                    formData.set('action', 'delete_event');
                    formData.set('event_id', eventId);

                    try {
                        const response = await fetch('/admin/calendar-events.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' },
                            body: formData,
                        });
                        const payload = await response.json();
                        if (!response.ok || !payload.ok) {
                            throw new Error(payload.message || 'Unable to delete the event.');
                        }

                        // Smoothly remove the card
                        const card = btn.closest('.event-card, .event-horizontal-card');
                        const track = card?.closest('[data-carousel-track]');
                        if (card) {
                            card.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
                            card.style.opacity = '0';
                            card.style.transform = 'scale(0.95)';
                            setTimeout(() => {
                                card.remove();
                                if (track) {
                                    const sectionName = track.dataset.carouselTrack;
                                    const remaining = track.querySelectorAll('.event-card, .event-horizontal-card');
                                    const controls = document.querySelector(`[data-carousel-controls="${sectionName}"]`);
                                    const indicator = controls?.querySelector('[data-carousel-indicator]');
                                    const countBadge = track.closest('.events-category-group')?.querySelector('.events-group-count');
                                    if (countBadge) countBadge.textContent = remaining.length;
                                    if (indicator) {
                                        const newTotalPages = Math.max(1, Math.ceil(remaining.length / 5));
                                        indicator.textContent = `1 / ${newTotalPages}`;
                                    }
                                    if (remaining.length === 0) {
                                        const empty = document.createElement('div');
                                        empty.className = 'events-empty-card';
                                        empty.innerHTML = '<p class="muted">No events in this section.</p>';
                                        track.appendChild(empty);
                                    }
                                }
                            }, 200);
                        }

                        showToast(payload.message || `Event "${eventTitle}" was deleted.`);
                    } catch (error) {
                        showToast(error.message || 'Unable to delete the event.', 'error');
                    }
                }
            });
        });

        // 6. Multiple Image Upload Preview
        document.querySelectorAll('[data-multiple-image-preview-input]').forEach((input) => {
            const form = input.closest('form');
            const container = form?.querySelector('[data-preview-container], [data-multiple-previews-container]');
            if (!container) return;

            input.addEventListener('change', () => {
                container.replaceChildren();
                if (!input.files || !input.files.length) {
                    container.hidden = true;
                    return;
                }
                container.hidden = false;
                Array.from(input.files).forEach((file, index) => {
                    if (!file.type.startsWith('image/')) return;
                    const box = document.createElement('div');
                    box.className = 'preview-thumb-box';
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.alt = `Preview ${index + 1}`;
                    box.appendChild(img);
                    if (index === 0) {
                        const badge = document.createElement('span');
                        badge.className = 'preview-primary-badge';
                        badge.textContent = 'Cover';
                        box.appendChild(badge);
                    }
                    container.appendChild(box);
                });
            });
        });
    };

    initEventsSocialFeed();
});
