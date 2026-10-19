(() => {
    'use strict';

    const targets = [...document.querySelectorAll('[data-timetable-context-target][data-offering-id]')];
    if (!targets.length) return;
    const touchMode = () => matchMedia('(pointer: coarse)').matches || innerWidth < 760;
    const setInstructions = () => document.querySelectorAll('[data-timetable-instruction]').forEach((node) => {
        node.textContent = touchMode() ? 'Tap a class for details · Hold for actions' : 'Hover or focus a class for details · Right-click for actions';
    });
    setInstructions();

    const menu = document.createElement('div');
    menu.className = 'timetable-context-menu';
    menu.hidden = true;
    menu.setAttribute('role', 'menu');
    menu.innerHTML = `
        <div class="timetable-menu-heading"><span data-menu-code>Subject</span><small data-menu-title>Subject actions</small></div>
        <a role="menuitem" data-action="grades"><svg viewBox="0 0 20 20"><path d="M4 4h12v12H4zM7 8h6M7 12h3"/></svg><span>View grades</span><kbd>G</kbd></a>
        <a role="menuitem" data-action="attendance"><svg viewBox="0 0 20 20"><path d="M4 5.5h12v11H4zM7 3.5v4M13 3.5v4M4 9h12"/></svg><span>View attendance</span><kbd>A</kbd></a>
        <a role="menuitem" data-action="roster"><svg viewBox="0 0 20 20"><circle cx="7" cy="7" r="2.5"/><circle cx="14" cy="8" r="2"/><path d="M2.5 16c.4-3 2-4.5 4.5-4.5s4.1 1.5 4.5 4.5M12 12c2.7 0 4.3 1.3 4.7 4"/></svg><span>View students</span></a>
        <a role="menuitem" data-action="seat-plan"><svg viewBox="0 0 20 20"><path d="M3 4h5v5H3zM12 4h5v5h-5zM3 12h5v5H3zM12 12h5v5h-5z"/></svg><span>View seat plan</span></a>
        <button type="button" role="menuitem" data-action="policy"><svg viewBox="0 0 20 20"><path d="M4 5h5M12 5h4M4 10h2M9 10h7M4 15h7M14 15h2"/><circle cx="10.5" cy="5" r="1.5"/><circle cx="7.5" cy="10" r="1.5"/><circle cx="12.5" cy="15" r="1.5"/></svg><span>Attendance rules</span></button>
        <div class="timetable-menu-separator" role="separator"></div>
        <a role="menuitem" data-action="teacher"><svg viewBox="0 0 20 20"><circle cx="10" cy="6.5" r="3"/><path d="M4.5 17v-1.5A4.5 4.5 0 0 1 9 11h2a4.5 4.5 0 0 1 4.5 4.5V17"/></svg><span data-teacher-label>View teacher</span></a>
        <button type="button" role="menuitem" aria-expanded="false" data-color-toggle><svg viewBox="0 0 20 20"><path d="M10 3a7 7 0 1 0 0 14h1.2a1.4 1.4 0 0 0 0-2.8h-.6a1.2 1.2 0 0 1 0-2.4H13A4 4 0 0 0 17 8c0-2.8-3.1-5-7-5Z"/><circle cx="7" cy="7" r=".7"/><circle cx="11" cy="6" r=".7"/><circle cx="14" cy="8.5" r=".7"/></svg><span>Change color</span><svg class="menu-chevron" viewBox="0 0 20 20"><path d="m7.5 5 5 5-5 5"/></svg></button>
        <div class="timetable-color-selector" data-color-selector hidden><strong>Choose subject color</strong><div class="timetable-color-grid" role="group" aria-label="Subject colors"></div><button type="button" class="timetable-color-reset" data-color-reset>Use automatic color</button></div>`;
    document.body.append(menu);

    const colors = [['Ocean','#175CD3'],['Indigo','#3538CD'],['Violet','#6941C6'],['Plum','#9333EA'],['Rose','#C11574'],['Coral','#D92D20'],['Amber','#B54708'],['Gold','#CA8504'],['Forest','#067647'],['Emerald','#027A48'],['Teal','#0E7090'],['Slate','#344054']];
    const colorGrid = menu.querySelector('.timetable-color-grid');
    colors.forEach(([name, color]) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'timetable-color-swatch';
        button.dataset.color = color;
        button.title = name;
        button.setAttribute('aria-label', name);
        button.style.setProperty('--swatch-color', color);
        button.innerHTML = '<svg viewBox="0 0 20 20"><path d="m5 10.5 3 3 7-7"/></svg>';
        colorGrid.append(button);
    });

    let target = null;
    let touchTimer = null;
    const storageKey = (id) => `rfid-timetable-color-${id}`;
    const savedColor = (id) => { try { return localStorage.getItem(storageKey(id)); } catch { return null; } };
    const applyColor = (id, color) => document.querySelectorAll(`[data-timetable-event][data-offering-id="${CSS.escape(String(id))}"]`).forEach((event) => {
        if (color) event.style.setProperty('--timetable-event-color', color);
        else event.style.removeProperty('--timetable-event-color');
    });
    [...new Set(targets.map((item) => item.dataset.offeringId))].forEach((id) => applyColor(id, savedColor(id)));

    const hide = () => {
        menu.hidden = true;
        menu.querySelector('[data-color-selector]').hidden = true;
        menu.querySelector('[data-color-toggle]').setAttribute('aria-expanded', 'false');
        target = null;
    };
    const setLink = (name, href) => {
        const link = menu.querySelector(`[data-action="${name}"]`);
        link.hidden = !href;
        if (href) link.href = href;
    };
    const position = (x, y) => {
        menu.hidden = false;
        menu.style.left = '0px';
        menu.style.top = '0px';
        const rect = menu.getBoundingClientRect();
        const left = Math.max(10, Math.min(x, innerWidth - rect.width - 10));
        const top = Math.max(10, Math.min(y, innerHeight - rect.height - 10));
        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
        menu.classList.toggle('opens-left', left + rect.width + 235 > innerWidth);
        menu.classList.toggle('opens-up', top + rect.height > innerHeight - 10);
    };
    const open = (contextTarget, x, y) => {
        hideDetails();
        target = contextTarget;
        menu.querySelector('[data-menu-code]').textContent = contextTarget.dataset.subjectCode || 'Subject';
        menu.querySelector('[data-menu-title]').textContent = contextTarget.dataset.subjectTitle || 'Subject actions';
        setLink('grades', contextTarget.dataset.gradesUrl);
        setLink('attendance', contextTarget.dataset.attendanceUrl);
        setLink('roster', contextTarget.dataset.rosterUrl);
        setLink('seat-plan', contextTarget.dataset.seatPlanUrl);
        menu.querySelector('[data-action="policy"]').hidden = !contextTarget.dataset.policyDialog;
        setLink('teacher', contextTarget.dataset.teacherProfileUrl);
        menu.querySelector('[data-teacher-label]').textContent = contextTarget.dataset.teacherName ? `View ${contextTarget.dataset.teacherName}` : 'View teacher';
        const selected = savedColor(contextTarget.dataset.offeringId);
        colorGrid.querySelectorAll('[data-color]').forEach((swatch) => swatch.classList.toggle('is-selected', swatch.dataset.color.toLowerCase() === String(selected).toLowerCase()));
        position(x, y);
        menu.querySelector('[role="menuitem"]:not([hidden])')?.focus({ preventScroll: true });
    };

    const appendFact = (list, label, value) => {
        if (!value) return;
        const wrapper = document.createElement('div');
        const term = document.createElement('dt');
        const detail = document.createElement('dd');
        term.textContent = label;
        detail.textContent = value;
        wrapper.append(term, detail);
        list.append(wrapper);
    };

    // A body-level panel stays outside the timetable's scrolling/clipping area.
    const details = document.createElement('div');
    details.className = 'timetable-details';
    details.id = 'timetable-hover-details';
    details.setAttribute('role', 'tooltip');
    details.hidden = true;
    document.body.append(details);
    let detailsTarget = null;
    let detailsTimer = null;
    let detailsFrame = null;
    let describedNodes = [];
    const hideDetails = () => {
        clearTimeout(detailsTimer);
        cancelAnimationFrame(detailsFrame);
        details.hidden = true;
        describedNodes.forEach((node) => {
            const ids = (node.getAttribute('aria-describedby') || '').split(/\s+/).filter((id) => id && id !== details.id);
            if (ids.length) node.setAttribute('aria-describedby', ids.join(' '));
            else node.removeAttribute('aria-describedby');
        });
        describedNodes = [];
        detailsTarget = null;
    };
    const showDetails = (card) => {
        if (!menu.hidden || !card.isConnected) return;
        hideDetails();
        detailsTarget = card;
        const code = document.createElement('span');
        code.className = 'timetable-details-code';
        code.textContent = card.dataset.subjectCode;
        const title = document.createElement('h3');
        title.textContent = card.dataset.subjectTitle;
        const facts = document.createElement('dl');
        appendFact(facts, 'Day', card.dataset.scheduleDay);
        appendFact(facts, 'Time', card.dataset.scheduleTime);
        appendFact(facts, 'Room', [card.dataset.roomCode || 'To be assigned', card.dataset.roomName].filter(Boolean).join(' · '));
        appendFact(facts, 'Location', [card.dataset.roomBuilding, card.dataset.roomFloor].filter(Boolean).join(' · '));
        appendFact(facts, 'Year & section', card.dataset.sectionName);
        appendFact(facts, 'Teacher', card.dataset.teacherName || 'To be assigned');
        appendFact(facts, 'Program', [card.dataset.programCode, card.dataset.programName].filter(Boolean).join(' · '));
        appendFact(facts, 'Department', [card.dataset.departmentCode, card.dataset.departmentName].filter(Boolean).join(' · '));
        appendFact(facts, 'Availability', card.dataset.scheduleSuspension);
        details.replaceChildren(code, title, facts);
        describedNodes = [card, ...card.querySelectorAll('a,button')];
        describedNodes.forEach((node) => node.setAttribute('aria-describedby', [...new Set([...(node.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean), details.id])].join(' ')));
        details.hidden = false;
        const rect = card.getBoundingClientRect();
        const panel = details.getBoundingClientRect();
        let left = rect.right + 8;
        let top = rect.top;
        if (left + panel.width > innerWidth - 12) left = rect.left - panel.width - 8;
        if (left < 12) {
            left = rect.left;
            top = rect.bottom + 8;
            if (top + panel.height > innerHeight - 12) top = rect.top - panel.height - 8;
        }
        details.style.left = `${Math.max(12, Math.min(left, innerWidth - panel.width - 12))}px`;
        details.style.top = `${Math.max(12, Math.min(top, innerHeight - panel.height - 12))}px`;
    };
    const queueDetailsHide = () => {
        clearTimeout(detailsTimer);
        detailsTimer = setTimeout(() => {
            if (!details.matches(':hover') && !detailsTarget?.matches(':hover') && !detailsTarget?.contains(document.activeElement)) hideDetails();
        }, 150);
    };
    details.addEventListener('pointerenter', () => clearTimeout(detailsTimer));
    details.addEventListener('pointerleave', queueDetailsHide);
    document.querySelectorAll('[data-timetable-event]').forEach((card) => {
        card.addEventListener('pointerenter', (event) => {
            if (event.pointerType === 'touch') return;
            clearTimeout(detailsTimer);
            detailsTimer = setTimeout(() => showDetails(card), 180);
        });
        card.addEventListener('pointerleave', queueDetailsHide);
        card.addEventListener('focusin', (event) => {
            if (!touchMode() || event.target.matches(':focus-visible')) showDetails(card);
        });
        card.addEventListener('focusout', queueDetailsHide);
        card.addEventListener('click', (event) => {
            if (!touchMode() || event.target.closest('a,button') || !menu.hidden) return;
            if (detailsTarget === card && !details.hidden) hideDetails();
            else showDetails(card);
        });
    });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') hideDetails(); });
    document.addEventListener('pointerdown', (event) => {
        if (!details.contains(event.target) && !event.target.closest('[data-timetable-event]')) hideDetails();
    });
    document.addEventListener('scroll', (event) => {
        if (details.contains(event.target)) return;
        const focused = detailsTarget?.contains(document.activeElement) ? detailsTarget : null;
        hideDetails();
        if (focused) detailsFrame = requestAnimationFrame(() => {
            const rect = focused.getBoundingClientRect();
            const viewport = focused.closest('.timetable-scroll').getBoundingClientRect();
            if (rect.bottom > Math.max(0, viewport.top) && rect.top < Math.min(innerHeight, viewport.bottom)
                && rect.right > Math.max(0, viewport.left) && rect.left < Math.min(innerWidth, viewport.right)) showDetails(focused);
        });
    }, true);
    addEventListener('resize', hideDetails);

    const openEntity = (type, contextTarget = target) => {
        hideDetails();
        if (!contextTarget?.dataset.entityDialog) return;
        const dialog = document.querySelector(contextTarget.dataset.entityDialog);
        if (!dialog) return;
        const facts = dialog.querySelector('[data-schedule-entity-facts]');
        const title = dialog.querySelector('[data-schedule-entity-title]');
        const subtitle = dialog.querySelector('[data-schedule-entity-subtitle]');
        const kind = dialog.querySelector('[data-schedule-entity-kind]');
        const manage = dialog.querySelector('[data-schedule-entity-manage]');
        const seatPlan = dialog.querySelector('[data-schedule-entity-seat-plan]');
        facts.replaceChildren();
        if (type === 'section') {
            kind.textContent = 'Class section';
            title.textContent = contextTarget.dataset.sectionName || 'Section';
            subtitle.textContent = 'Academic grouping connected to this class offering.';
            appendFact(facts, 'Program', [contextTarget.dataset.programCode, contextTarget.dataset.programName].filter(Boolean).join(' - '));
            appendFact(facts, 'Department', [contextTarget.dataset.departmentCode, contextTarget.dataset.departmentName].filter(Boolean).join(' - '));
            appendFact(facts, 'Year level', contextTarget.dataset.sectionYear);
            appendFact(facts, 'Section', contextTarget.dataset.sectionCode);
            manage.hidden = !contextTarget.dataset.sectionManageUrl;
            if (!manage.hidden) manage.href = contextTarget.dataset.sectionManageUrl;
            seatPlan.hidden = true;
        } else {
            kind.textContent = 'Classroom';
            title.textContent = contextTarget.dataset.roomCode || 'Room';
            subtitle.textContent = contextTarget.dataset.roomName || 'Room assigned to this scheduled class.';
            appendFact(facts, 'Room name', contextTarget.dataset.roomName);
            appendFact(facts, 'Building', contextTarget.dataset.roomBuilding);
            appendFact(facts, 'Floor / location', contextTarget.dataset.roomFloor);
            appendFact(facts, 'Scheduled subject', [contextTarget.dataset.subjectCode, contextTarget.dataset.subjectTitle].filter(Boolean).join(' - '));
            manage.hidden = !contextTarget.dataset.roomManageUrl;
            if (!manage.hidden) manage.href = contextTarget.dataset.roomManageUrl;
            seatPlan.hidden = !contextTarget.dataset.seatPlanUrl;
            if (!seatPlan.hidden) seatPlan.href = contextTarget.dataset.seatPlanUrl;
        }
        hide();
        if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
    };

    targets.forEach((contextTarget) => {
        contextTarget.addEventListener('contextmenu', (event) => {
            if (event.target.closest('a,button')) return;
            event.preventDefault();
            open(contextTarget, event.clientX, event.clientY);
        });
        contextTarget.addEventListener('keydown', (event) => {
            if (event.key !== 'ContextMenu' && !(event.shiftKey && event.key === 'F10')) return;
            event.preventDefault();
            const rect = contextTarget.getBoundingClientRect();
            open(contextTarget, rect.left + Math.min(32, rect.width / 2), rect.top + Math.min(32, rect.height / 2));
        });
        contextTarget.addEventListener('pointerdown', (event) => {
            if (!touchMode() || event.pointerType !== 'touch' || event.target.closest('a,button')) return;
            const rect = contextTarget.getBoundingClientRect();
            touchTimer = setTimeout(() => open(contextTarget, rect.left + Math.min(40, rect.width / 2), rect.top + Math.min(40, rect.height / 2)), 500);
        });
        ['pointerup','pointercancel','pointermove'].forEach((name) => contextTarget.addEventListener(name, () => clearTimeout(touchTimer)));
    });

    document.addEventListener('click', (event) => {
        const inline = event.target.closest('[data-timetable-inline-entity]');
        const contextTarget = inline?.closest('[data-timetable-context-target]');
        if (!contextTarget) return;
        event.preventDefault();
        event.stopPropagation();
        openEntity(inline.dataset.timetableInlineEntity, contextTarget);
    });
    menu.querySelector('[data-action="policy"]').addEventListener('click', () => {
        if (!target || typeof window.openAttendancePolicy !== 'function') return;
        const id = target.dataset.offeringId;
        const selector = target.dataset.policyDialog;
        hide();
        window.openAttendancePolicy(id, selector);
    });
    menu.querySelector('[data-color-toggle]').addEventListener('click', (event) => {
        const selector = menu.querySelector('[data-color-selector]');
        selector.hidden = !selector.hidden;
        event.currentTarget.setAttribute('aria-expanded', selector.hidden ? 'false' : 'true');
        menu.style.setProperty('--color-selector-top', `${event.currentTarget.offsetTop}px`);
        if (!selector.hidden) {
            const selectorTop = Number.parseFloat(menu.style.top) + event.currentTarget.offsetTop;
            menu.classList.toggle('opens-up', selectorTop + selector.getBoundingClientRect().height > innerHeight - 10);
            colorGrid.querySelector('button')?.focus();
        } else {
            menu.classList.remove('opens-up');
        }
    });
    colorGrid.addEventListener('click', (event) => {
        const swatch = event.target.closest('[data-color]');
        if (!swatch || !target) return;
        try { localStorage.setItem(storageKey(target.dataset.offeringId), swatch.dataset.color); } catch { /* Page-only color is acceptable. */ }
        applyColor(target.dataset.offeringId, swatch.dataset.color);
        hide();
    });
    menu.querySelector('[data-color-reset]').addEventListener('click', () => {
        if (!target) return;
        try { localStorage.removeItem(storageKey(target.dataset.offeringId)); } catch { /* Keep going. */ }
        applyColor(target.dataset.offeringId, null);
        hide();
    });
    menu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') { event.preventDefault(); const prior = target; hide(); prior?.focus(); return; }
        if (!target) return;
        if (event.key.toLowerCase() === 'g' && target.dataset.gradesUrl) location.href = target.dataset.gradesUrl;
        if (event.key.toLowerCase() === 'a' && target.dataset.attendanceUrl) location.href = target.dataset.attendanceUrl;
    });
    document.addEventListener('pointerdown', (event) => { if (!menu.hidden && !menu.contains(event.target)) hide(); });
    document.addEventListener('scroll', hide, true);
    addEventListener('resize', () => { hide(); setInstructions(); });
})();
