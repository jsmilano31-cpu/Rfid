(() => {
    'use strict';

    const requestJson = async (url, options = {}) => {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', ...(options.headers || {}) }, ...options });
        let payload;
        try { payload = await response.json(); } catch { throw new Error('The server returned an invalid attendance-policy response.'); }
        if (!response.ok || !payload.ok) throw new Error(payload.message || 'The attendance policy could not be loaded.');
        return payload;
    };
    const notify = (message, isError = false) => {
        const toast = document.createElement('div');
        toast.className = `app-toast${isError ? ' is-error' : ''}`;
        toast.setAttribute('role', isError ? 'alert' : 'status');
        toast.innerHTML = `<span class="app-toast-icon">${isError ? '!' : '✓'}</span><span class="app-toast-copy"><strong>${isError ? 'Couldn\'t save' : 'Saved'}</strong><span></span></span><button class="app-toast-close" type="button" aria-label="Dismiss">×</button>`;
        toast.querySelector('.app-toast-copy span').textContent = message;
        toast.querySelector('button').addEventListener('click', () => toast.remove());
        (document.querySelector('dialog[open]') || document.querySelector('[data-toast-region]') || document.body).append(toast);
        window.setTimeout(() => toast.remove(), isError ? 8000 : 5000);
    };
    const updateOutputs = (form) => {
        const values = {
            early_check_in_minutes: Number(form.elements.early_check_in_minutes?.value || 0),
            grace_period_minutes: Number(form.elements.grace_period_minutes?.value || 0),
            absent_after_minutes: Number(form.elements.absent_after_minutes?.value || 0),
            tardies_per_absence: Number(form.elements.tardies_per_absence?.value || 0),
        };
        form.querySelectorAll('[data-policy-output]').forEach((output) => {
            const field = output.dataset.policyOutput;
            output.textContent = field === 'tardies_per_absence'
                ? `${values[field]} ${values[field] === 1 ? 'tardy' : 'tardies'}`
                : `${values[field]} ${values[field] === 1 ? 'minute' : 'minutes'}`;
        });
        const present = form.querySelector('[data-policy-present-copy]');
        const late = form.querySelector('[data-policy-late-copy]');
        const absent = form.querySelector('[data-policy-absent-copy]');
        if (present) present.textContent = `Present: 0-${values.grace_period_minutes} min`;
        if (late) late.textContent = `Late: ${values.grace_period_minutes + 1}-${Math.max(values.grace_period_minutes + 1, values.absent_after_minutes - 1)} min`;
        if (absent) absent.textContent = `Absent: ${values.absent_after_minutes}+ min`;
    };
    const bindRanges = (form) => {
        const grace = form.elements.grace_period_minutes;
        const absent = form.elements.absent_after_minutes;
        if (!grace || !absent) return;
        form.querySelectorAll('input[type="range"]').forEach((input) => input.addEventListener('input', () => {
            if (input === grace && Number(absent.value) <= Number(grace.value)) absent.value = Math.min(Number(absent.max), Number(grace.value) + 1);
            if (input === absent && Number(absent.value) <= Number(grace.value)) grace.value = Math.max(Number(grace.min), Number(absent.value) - 1);
            updateOutputs(form);
        }));
        updateOutputs(form);
    };

    document.querySelectorAll('[data-attendance-rules-form]').forEach(bindRanges);
    document.querySelectorAll('[data-attendance-policy-dialog]').forEach((dialog) => {
        const form = dialog.querySelector('[data-attendance-policy-form]');
        const useDefault = form?.querySelector('[data-policy-default]');
        const controls = form?.querySelector('[data-policy-controls]');
        if (!form || !useDefault || !controls) return;
        bindRanges(form);
        const setDefaultState = () => {
            controls.classList.toggle('is-disabled', useDefault.checked);
            controls.querySelectorAll('input').forEach((input) => { input.disabled = useDefault.checked; });
        };
        useDefault.addEventListener('change', setDefaultState);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const button = event.submitter;
            const feedback = form.querySelector('[data-policy-feedback]');
            feedback.textContent = 'Saving policy...';
            feedback.classList.remove('is-error');
            button.disabled = true;
            try {
                const data = new FormData(form);
                if (useDefault.checked) data.set('use_campus_default', '1');
                const payload = await requestJson(dialog.dataset.endpoint, { method: 'POST', body: data });
                feedback.textContent = payload.message;
                notify(payload.message);
                dialog.close();
            } catch (error) {
                feedback.textContent = error.message;
                feedback.classList.add('is-error');
                notify(error.message, true);
            } finally { button.disabled = false; }
        });
        dialog._openPolicy = async (offeringId) => {
            form.reset();
            form.elements.class_offering_id.value = offeringId;
            const feedback = form.querySelector('[data-policy-feedback]');
            feedback.textContent = 'Loading policy...';
            feedback.classList.remove('is-error');
            if (!dialog.open) dialog.showModal();
            try {
                const url = new URL(dialog.dataset.endpoint, location.origin);
                url.searchParams.set('mode', 'policy');
                url.searchParams.set('offering_id', offeringId);
                const payload = await requestJson(url);
                const policy = payload.policy;
                dialog.querySelector('[data-policy-title]').textContent = `${policy.subject.course_code} attendance rules`;
                dialog.querySelector('[data-policy-subtitle]').textContent = `${policy.subject.course_title} · ${policy.subject.section_name}`;
                form.elements.class_offering_id.value = policy.subject.id;
                useDefault.checked = policy.uses_campus_default;
                Object.entries(policy.effective).forEach(([field, value]) => { if (form.elements[field]) form.elements[field].value = value; });
                form.querySelector('[data-policy-campus-summary]').textContent = `Opens ${policy.campus.early_check_in_minutes} min early · Present through ${policy.campus.grace_period_minutes} min · Late until ${policy.campus.absent_after_minutes - 1} min · Absent at ${policy.campus.absent_after_minutes}+ min`;
                setDefaultState();
                updateOutputs(form);
                feedback.textContent = policy.uses_campus_default ? 'This subject currently inherits the campus policy.' : `Custom policy${policy.updated_by ? ` · Updated by ${policy.updated_by}` : ''}`;
            } catch (error) {
                feedback.textContent = error.message;
                feedback.classList.add('is-error');
            }
        };
    });

    window.openAttendancePolicy = (offeringId, selector = '') => {
        const dialog = selector ? document.querySelector(selector) : document.querySelector('[data-attendance-policy-dialog]');
        if (!dialog?._openPolicy || !Number(offeringId)) return;
        dialog._openPolicy(Number(offeringId));
    };
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-attendance-policy-current], [data-attendance-policy-open]');
        if (!button) return;
        window.openAttendancePolicy(button.dataset.offeringId, button.dataset.policyDialog || '');
    });
})();
