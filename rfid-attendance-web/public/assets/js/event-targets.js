(() => {
    const broadLabels = { everyone: 'Everyone', all_students: 'All students', all_teachers: 'All teachers', all_staff: 'All staff' };
    const cohortTypes = ['department', 'program', 'section'];
    const builders = new WeakMap();

    const setup = (builder) => {
        const form = builder.closest('form');
        const options = JSON.parse(builder.querySelector('[data-event-target-options]').textContent);
        const input = builder.querySelector('[data-event-target-json]');
        const chips = builder.querySelector('[data-event-target-chips]');
        const typeSelect = builder.querySelector('[data-event-target-type]');
        const entityField = builder.querySelector('[data-event-target-entity-field]');
        const entitySelect = builder.querySelector('[data-event-target-entity]');
        const roleField = builder.querySelector('[data-event-target-role-field]');
        const roleSelect = builder.querySelector('[data-event-target-role]');
        const personField = builder.querySelector('[data-event-target-person-field]');
        const personSearch = builder.querySelector('[data-event-target-person-search]');
        const personResults = builder.querySelector('[data-event-target-person-results]');
        const addButton = builder.querySelector('[data-event-target-add]');
        const status = builder.querySelector('[data-event-target-status]');
        let targets = [];
        let timer = null;
        let controller = null;

        const key = (target) => `${target.type}:${target.id || 0}:${target.roleFilter || 'any'}`;
        const entityItems = (type) => options[`${type}s`] || [];
        const labelFor = (target) => {
            if (target.label) return target.label;
            if (broadLabels[target.type]) return broadLabels[target.type];
            if (target.type === 'user') return `Person #${target.id}`;
            const entity = entityItems(target.type).find((item) => Number(item.id) === Number(target.id));
            if (!entity) return `${target.type} #${target.id}`;
            let label = target.type === 'section' ? `${entity.program_code} · ${entity.name}` : `${entity.code} · ${entity.name}`;
            if (target.roleFilter && target.roleFilter !== 'any') label += ` · ${target.roleFilter === 'staff' ? 'Staff' : `${target.roleFilter[0].toUpperCase()}${target.roleFilter.slice(1)}s`}`;
            return label;
        };
        const editable = () => form.dataset.editable !== '0' && !builder.dataset.readonly;
        const render = () => {
            input.value = JSON.stringify(targets.map(({ type, id, roleFilter }) => ({ type, id: id || null, roleFilter: roleFilter || 'any' })));
            chips.replaceChildren();
            if (!targets.length) {
                const empty = document.createElement('span');
                empty.className = 'event-target-empty';
                empty.textContent = 'No recipients selected yet.';
                chips.append(empty);
            }
            targets.forEach((target, index) => {
                const chip = document.createElement('span');
                chip.className = 'event-target-chip';
                const label = document.createElement('span');
                label.textContent = labelFor(target);
                chip.append(label);
                if (editable()) {
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'event-target-chip-remove';
                    remove.setAttribute('aria-label', `Remove ${label.textContent}`);
                    remove.textContent = '×';
                    remove.addEventListener('click', () => {
                        targets.splice(index, 1);
                        render();
                        status.textContent = `${label.textContent} removed.`;
                    });
                    chip.append(remove);
                }
                chips.append(chip);
            });
        };
        const add = (target) => {
            if (target.type === 'everyone') targets = [];
            else targets = targets.filter((item) => item.type !== 'everyone');
            if (targets.some((item) => key(item) === key(target))) {
                status.textContent = 'That recipient is already selected.';
                return;
            }
            if (targets.length >= 40) { status.textContent = 'Choose no more than 40 recipients.'; return; }
            targets.push(target);
            render();
            status.textContent = `${labelFor(target)} added.`;
        };
        const syncType = () => {
            const type = typeSelect.value;
            entityField.hidden = !cohortTypes.includes(type);
            roleField.hidden = !cohortTypes.includes(type);
            personField.hidden = type !== 'user';
            addButton.hidden = type === 'user';
            const choices = [{ value: '', label: 'Select a group', selected: true }];
            for (const item of entityItems(type)) {
                const label = type === 'section' ? `${item.program_code} · ${item.name}` : `${item.code} · ${item.name}`;
                choices.push({ value: String(item.id), label });
            }
            if (entitySelect._choicesInstance) entitySelect._choicesInstance.setChoices(choices, 'value', 'label', true);
            else { entitySelect.replaceChildren(); choices.forEach((choice) => entitySelect.add(new Option(choice.label, choice.value))); }
            if (type !== 'user') { personResults.hidden = true; personSearch.value = ''; }
            status.textContent = type === 'user' ? 'Search an active person by name or school ID.' : 'Add another group or person to the selected recipients.';
        };
        const showPeople = (items) => {
            personResults.replaceChildren();
            if (!items.length) {
                const empty = document.createElement('p');
                empty.className = 'event-target-person-empty';
                empty.textContent = 'No matching active people.';
                personResults.append(empty);
            }
            for (const person of items) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'event-target-person-option';
                button.setAttribute('role', 'option');
                const name = document.createElement('strong');
                name.textContent = person.label;
                const subtitle = document.createElement('small');
                subtitle.textContent = [person.identifier, person.role, person.section_name].filter(Boolean).join(' · ');
                button.append(name, subtitle);
                button.addEventListener('click', () => {
                    add({ type: 'user', id: Number(person.id), roleFilter: 'any', label: `${person.label} · ${person.identifier}` });
                    personSearch.value = '';
                    personResults.hidden = true;
                    personSearch.focus();
                });
                personResults.append(button);
            }
            personResults.hidden = false;
        };
        const searchPeople = async () => {
            const query = personSearch.value.trim();
            if (query.length < 2) { personResults.hidden = true; status.textContent = 'Type at least two characters to find a person.'; return; }
            controller?.abort();
            controller = new AbortController();
            status.textContent = 'Searching people…';
            const url = new URL(builder.dataset.lookupUrl, location.origin);
            url.searchParams.set('context', 'event_target_people');
            url.searchParams.set('q', query);
            try {
                const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json' } });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to search people.');
                if (personSearch.value.trim() !== query) return;
                showPeople(payload.items || []);
                status.textContent = `${payload.items?.length || 0} matching people. Choose one to add.`;
            } catch (error) {
                if (error.name !== 'AbortError') { status.textContent = error.message || 'Unable to search people.'; personResults.hidden = true; }
            }
        };

        typeSelect.addEventListener('change', syncType);
        addButton.addEventListener('click', () => {
            const type = typeSelect.value;
            if (cohortTypes.includes(type) && !entitySelect.value) { status.textContent = 'Choose a group first.'; entitySelect.focus(); return; }
            add({ type, id: cohortTypes.includes(type) ? Number(entitySelect.value) : null, roleFilter: cohortTypes.includes(type) ? roleSelect.value : 'any' });
        });
        personSearch.addEventListener('input', () => { clearTimeout(timer); controller?.abort(); timer = setTimeout(searchPeople, 250); });
        personSearch.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') personResults.hidden = true;
            if (event.key === 'ArrowDown' && !personResults.hidden) { event.preventDefault(); personResults.querySelector('button')?.focus(); }
        });
        personResults.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { personResults.hidden = true; personSearch.focus(); }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const buttons = [...personResults.querySelectorAll('button')];
                const index = buttons.indexOf(document.activeElement);
                buttons[(index + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length]?.focus();
            }
        });
        form.addEventListener('submit', (event) => {
            if (targets.length) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            status.textContent = 'Add at least one recipient before saving the event.';
            typeSelect.focus();
        }, true);

        const setTargets = (incoming, canEdit = true) => {
            targets = Array.isArray(incoming) ? incoming.filter((target) => target && target.type).map((target) => ({ type: target.type, id: target.id || null, roleFilter: target.roleFilter || 'any', label: target.label || '' })) : [];
            builder.dataset.readonly = canEdit ? '' : '1';
            builder.querySelectorAll('.event-target-controls input, .event-target-controls select, .event-target-controls button').forEach((control) => { control.disabled = !canEdit; });
            render();
            syncType();
        };
        builders.set(form, setTargets);
        setTargets([{ type: 'all_students', id: null, roleFilter: 'any' }]);
    };

    document.querySelectorAll('[data-event-target-builder]').forEach(setup);
    window.setEventTargets = (form, targets, editable = true) => builders.get(form)?.(targets, editable);
})();
