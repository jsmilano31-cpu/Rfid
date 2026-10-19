(() => {
    const activeForms = new WeakMap();
    const makePicker = (selected = 'LEC') => {
        const picker = document.createElement('fieldset');
        picker.className = 'offering-components';
        picker.dataset.offeringComponents = '';
        const legend = document.createElement('legend');
        legend.textContent = 'Class component';
        const options = document.createElement('div');
        options.className = 'offering-component-options';
        [['LEC', 'Lecture', 'Classroom instruction'], ['LAB', 'Laboratory', 'Practical instruction']].forEach(([value, name, detail]) => {
            const label = document.createElement('label');
            label.className = 'offering-component-option';
            const radio = document.createElement('input');
            radio.type = 'radio'; radio.name = 'component_type'; radio.value = value;
            radio.required = true; radio.checked = value === selected;
            const track = document.createElement('span'); track.className = 'component-switch'; track.setAttribute('aria-hidden', 'true');
            const copy = document.createElement('span');
            const title = document.createElement('strong'); title.textContent = `${name} (${value})`;
            const help = document.createElement('small'); help.textContent = detail;
            copy.append(title, help); label.append(radio, track, copy); options.append(label);
        });
        const feedback = document.createElement('p'); feedback.className = 'field-help component-availability';
        feedback.dataset.componentAvailability = ''; feedback.setAttribute('aria-live', 'polite');
        const note = document.createElement('p'); note.className = 'field-help';
        note.textContent = 'Lecture and lab for the same course, section, and semester share one grade. Their teachers, rooms, and meetings can differ.';
        picker.append(legend, options, feedback, note);
        return picker;
    };
    const initialize = (picker) => {
        if (picker.dataset.initialized) return;
        picker.dataset.initialized = '1';
        const form = picker.closest('form'); if (!form) return;
        activeForms.get(form)?.();
        const radios = [...picker.querySelectorAll('[name="component_type"]')];
        const feedback = picker.querySelector('[data-component-availability]');
        let controller, timer, generation = 0, ready = false;
        const refresh = async () => {
            const request = ++generation;
            controller?.abort(); controller = new AbortController(); ready = false;
            const values = ['course_id', 'section_id', 'semester_id'].map(name => form.elements[name]?.value || '');
            radios.forEach(radio => { radio.disabled = true; });
            feedback.replaceChildren();
            if (values.some(value => !value)) { feedback.textContent = 'Choose a course, section, and semester to see available components.'; return; }
            feedback.textContent = 'Checking existing components…';
            const url = new URL('/admin/academic-entity.php', window.location.origin);
            url.searchParams.set('mode', 'components');
            ['course_id', 'section_id', 'semester_id'].forEach((name, i) => url.searchParams.set(name, values[i]));
            url.searchParams.set('exclude_id', form.elements.id?.value || '0');
            try {
                const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.message || 'Could not check components.');
                if (request !== generation || !picker.isConnected) return;
                const occupied = new Set(data.components.map(item => item.component));
                radios.forEach(radio => { radio.disabled = occupied.has(radio.value); if (radio.disabled) radio.checked = false; });
                if (!radios.some(radio => radio.checked)) { const available = radios.find(radio => !radio.disabled); if (available) available.checked = true; }
                feedback.replaceChildren();
                if (!data.components.length) feedback.textContent = 'Both components are available. Choose one to create.';
                data.components.forEach((item, index) => {
                    if (index) feedback.append(document.createTextNode(' · '));
                    const link = document.createElement('a'); link.className = 'entity-link'; link.href = item.url;
                    link.target = '_blank'; link.rel = 'noopener';
                    link.textContent = `${item.component} already exists${item.status !== 'active' ? ` (${item.status})` : ''}. Edit offering ↗`;
                    feedback.append(link);
                });
                if (radios.every(radio => radio.disabled)) feedback.append(document.createTextNode(' Both components exist; add or change meetings in their editors.'));
                ready = true;
            } catch (error) {
                if (error.name === 'AbortError' || request !== generation) return;
                feedback.textContent = error.message + ' ';
                const retry = document.createElement('button'); retry.type = 'button'; retry.className = 'button button-secondary button-small';
                retry.textContent = 'Retry'; retry.addEventListener('click', refresh); feedback.append(retry);
            }
        };
        const scheduleRefresh = () => {
            if (!picker.isConnected) return;
            ready = false; ++generation; controller?.abort(); radios.forEach(radio => { radio.disabled = true; });
            clearTimeout(timer); timer = setTimeout(refresh, 120);
        };
        const onChange = event => {
            if (['course_id', 'section_id', 'semester_id', 'program_id', 'offering_program_id', 'school_year_id', 'offering_school_year_id'].includes(event.target.name)) scheduleRefresh();
        };
        const onReset = () => setTimeout(scheduleRefresh, 0);
        const onSubmit = event => {
            if (ready && radios.some(radio => radio.checked && !radio.disabled)) return;
            event.preventDefault(); event.stopImmediatePropagation(); feedback.focus();
            if (!ready) refresh();
        };
        form.addEventListener('change', onChange);
        form.addEventListener('reset', onReset);
        form.addEventListener('submit', onSubmit, true);
        activeForms.set(form, () => {
            ++generation; controller?.abort(); clearTimeout(timer);
            form.removeEventListener('change', onChange); form.removeEventListener('reset', onReset); form.removeEventListener('submit', onSubmit, true);
        });
        feedback.tabIndex = -1; refresh();
    };
    window.CampusOfferingComponents = { makePicker, initialize };
    document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[data-offering-components]').forEach(initialize));
})();
