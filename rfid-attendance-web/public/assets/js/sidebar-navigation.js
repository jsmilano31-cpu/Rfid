(() => {
    const sidebar = document.querySelector('#sidebar');
    const handle = sidebar?.querySelector('[data-sidebar-resize]');
    const collapseButton = sidebar?.querySelector('[data-sidebar-collapse]');
    const storageKey = document.body.dataset.sidebarStorageKey;
    if (!sidebar || !handle || !storageKey) return;

    const root = document.documentElement;
    const desktop = window.matchMedia('(min-width: 761px)');
    const minWidth = 72;
    const maxWidth = 360;
    const expandedMinWidth = 210;
    const defaultWidth = 250;
    let width = parseInt(root.style.getPropertyValue('--sidebar-width'), 10) || defaultWidth;
    let dragging = false;
    let startX = 0;
    let startWidth = width;
    let expandedWidth = 250;
    let committedWidth = width;
    let peeking = false;
    let closeTimer = null;

    const save = (key, value) => {
        try { localStorage.setItem(key, value); } catch (_) {}
    };
    const read = (key) => {
        try { return localStorage.getItem(key); } catch (_) { return null; }
    };
    const normalize = (value) => value < 150 ? minWidth : Math.min(maxWidth, Math.max(expandedMinWidth, value));
    expandedWidth = normalize(Number(read(`${storageKey}.expanded`)) || (width > minWidth ? width : defaultWidth));
    const applyWidth = (value, persist = false) => {
        width = normalize(value);
        root.style.setProperty('--sidebar-visual-width', `${width}px`);
        if (!peeking || persist) root.style.setProperty('--sidebar-width', `${width}px`);
        if (width === minWidth) root.dataset.sidebarCompact = 'true';
        else delete root.dataset.sidebarCompact;
        handle.setAttribute('aria-valuenow', String(width));
        handle.setAttribute('aria-valuetext', width === minWidth ? 'Icons only' : `${width} pixels`);
        const collapseAction = peeking ? 'Keep navigation expanded' : width === minWidth ? 'Expand navigation' : 'Collapse navigation';
        collapseButton?.setAttribute('aria-label', collapseAction);
        collapseButton?.setAttribute('title', collapseAction);
        if (persist) {
            committedWidth = width;
            save(storageKey, String(width));
            if (width > minWidth) { expandedWidth = width; save(`${storageKey}.expanded`, String(width)); }
        }
    };
    applyWidth(width);

    collapseButton?.addEventListener('click', () => {
        clearTimeout(closeTimer);
        peeking = false;
        delete root.dataset.sidebarPeek;
        applyWidth(committedWidth === minWidth ? expandedWidth : minWidth, true);
    });
    const showPeek = () => {
        clearTimeout(closeTimer);
        if (!desktop.matches || committedWidth !== minWidth || peeking) return;
        peeking = true;
        root.dataset.sidebarPeek = 'true';
        applyWidth(expandedWidth);
    };
    const hidePeekSoon = () => {
        if (!peeking) return;
        clearTimeout(closeTimer);
        closeTimer = setTimeout(() => {
            if (sidebar.matches(':hover') || sidebar.contains(document.activeElement)) return;
            peeking = false;
            delete root.dataset.sidebarPeek;
            applyWidth(minWidth);
        }, 100);
    };
    sidebar.addEventListener('mouseenter', showPeek);
    sidebar.addEventListener('mouseleave', hidePeekSoon);
    sidebar.addEventListener('focusin', (event) => {
        if (event.target === handle || event.target === collapseButton) return;
        showPeek();
    });
    sidebar.addEventListener('focusout', hidePeekSoon);

    const groups = [...sidebar.querySelectorAll('[data-sidebar-group]')];
    for (const group of groups) {
        const key = `${storageKey}.group.${group.dataset.sidebarGroup}`;
        const saved = read(key);
        if (saved === 'open') group.open = true;
        else if (saved === 'closed') group.open = false;
        group.addEventListener('toggle', () => save(key, group.open ? 'open' : 'closed'));
    }

    handle.addEventListener('pointerdown', (event) => {
        if (!desktop.matches || event.button !== 0) return;
        event.preventDefault();
        clearTimeout(closeTimer);
        peeking = false;
        delete root.dataset.sidebarPeek;
        dragging = true;
        startX = event.clientX;
        startWidth = width;
        handle.setPointerCapture(event.pointerId);
        document.body.classList.add('is-resizing-sidebar');
    });
    handle.addEventListener('pointermove', (event) => {
        if (dragging) applyWidth(startWidth + event.clientX - startX);
    });
    const endDrag = () => {
        if (!dragging) return;
        dragging = false;
        document.body.classList.remove('is-resizing-sidebar');
        applyWidth(width, true);
    };
    handle.addEventListener('pointerup', endDrag);
    handle.addEventListener('pointercancel', endDrag);
    handle.addEventListener('lostpointercapture', endDrag);
    handle.addEventListener('dblclick', () => applyWidth(defaultWidth, true));
    handle.addEventListener('keydown', (event) => {
        if (!desktop.matches) return;
        const next = event.key === 'ArrowLeft' ? (width === expandedMinWidth ? minWidth : width - 24)
            : event.key === 'ArrowRight' ? (width === minWidth ? expandedMinWidth : width + 24)
            : event.key === 'Home' ? minWidth
            : event.key === 'End' ? maxWidth : null;
        if (next === null) return;
        event.preventDefault();
        applyWidth(next, true);
    });
})();
