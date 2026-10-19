(() => {
    'use strict';
    const terms = ['prelim', 'midterm', 'final'];
    const labels = { prelim: 'Prelims', midterm: 'Midterms', final: 'Endterms' };
    let settings = JSON.parse(document.querySelector('#campus-grading-settings').textContent);
    const sync = (root = document, locked = null) => {
        if (root.matches?.('form') && locked !== null) root.dataset.gradeLocked = locked ? '1' : '0';
        if (root.matches?.('[data-grade-term]')) root.hidden = !settings[`has_${root.dataset.gradeTerm}`];
        root.querySelectorAll('[data-grade-term]').forEach(node => { node.hidden = !settings[`has_${node.dataset.gradeTerm}`]; });
        root.querySelectorAll('input[name="prelim"],input[name="midterm"],input[name="final"]').forEach(input => {
            const enabled = !!settings[`has_${input.name}`];
            const isLocked = locked ?? input.closest('form')?.dataset.gradeLocked === '1';
            input.required = enabled && !isLocked;
            input.disabled = !enabled || isLocked;
            input.closest('label').hidden = !enabled;
        });
    };
    const overall = form => {
        let total = 0;
        for (const term of terms.filter(term => settings[`has_${term}`])) {
            const raw = form.elements[term]?.value.trim();
            const score = Number(raw);
            if (!raw || !Number.isFinite(score) || score < 0 || score > 100) return null;
            total += score * settings[`${term}_weight`] / 100;
        }
        return Math.round(total * 100) / 100;
    };
    window.CampusGrading = { get settings() { return settings; }, sync, overall, update(value) { if (value) { settings = value; sync(); } } };
    sync();
    document.querySelectorAll('[data-grading-policy-form]').forEach(form => {
        const feedback = form.querySelector('[data-grading-policy-feedback]');
        const button = form.querySelector('[data-save-grading-policy]');
        let saving = false;
        const update = () => {
            const parts = [];
            let total = 0, valid = true;
            for (const term of terms) {
                const toggle = form.elements[`has_${term}`];
                const weight = form.elements[`${term}_weight`];
                const card = form.querySelector(`[data-term-card="${term}"]`);
                if (!toggle.checked && !weight.disabled) {
                    weight.dataset.previousWeight = weight.value;
                    weight.value = '0';
                } else if (toggle.checked && weight.disabled && weight.dataset.previousWeight) {
                    weight.value = weight.dataset.previousWeight;
                }
                weight.disabled = !toggle.checked;
                weight.required = toggle.checked;
                card.classList.toggle('is-enabled', toggle.checked);
                card.classList.toggle('is-disabled', !toggle.checked);
                form.querySelector(`[data-term-toggle-label="${term}"]`).textContent = toggle.checked ? 'Enabled' : 'Disabled';
                if (toggle.checked) {
                    const value = Number(weight.value);
                    valid = valid && value > 0 && value <= 100;
                    total += value;
                    parts.push(`${labels[term]} × ${value}%`);
                }
            }
            total = Math.round(total * 100) / 100;
            valid = valid && parts.length > 0 && total === 100;
            form.querySelector('[data-grading-formula-preview]').textContent = parts.length ? parts.join(' + ') : 'Choose at least one grading period';
            const sum = form.querySelector('[data-grading-weight-sum]');
            sum.textContent = `Total weight: ${total}%`;
            sum.style.color = valid ? 'var(--success)' : 'var(--danger)';
            form.querySelector('[data-grading-weight-status]').textContent = valid ? 'Ready to save. Enabled periods add up to 100%.' : 'Enable at least one period and give each a positive weight. Total must be 100%.';
            button.disabled = !valid || saving;
        };
        form.addEventListener('input', update);
        form.addEventListener('change', update);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (saving || button.disabled || !form.reportValidity()) return;
            saving = true; update();
            feedback.textContent = 'Saving university-wide grading rules…';
            feedback.classList.remove('is-error');
            try {
                const response = await fetch(form.dataset.endpoint, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || 'Could not save grading rules. Try again.');
                settings = payload.settings;
                sync();
                document.dispatchEvent(new CustomEvent('campus-grading-updated'));
                feedback.textContent = 'Saved. These periods and weights now apply university-wide. Existing finalized grades remain unchanged.';
            } catch (error) {
                feedback.textContent = error.message;
                feedback.classList.add('is-error');
            } finally { saving = false; update(); }
        });
        update();
    });
})();
