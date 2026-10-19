document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.querySelector('#issue-violation-dialog');
    if (!dialog) return;
    const form = dialog.querySelector('[data-violation-form]');
    const group = form.elements.violation_group;
    const specific = form.elements.specific_violation;
    const classification = form.elements.classification;
    const otherField = form.querySelector('[data-other-violation]');
    const otherInput = form.elements.other_violation;
    const progressive = form.querySelector('[data-progressive-toggle]');
    const progressiveFields = [...form.querySelectorAll('[data-progressive-field]')];
    let catalog = {};
    try { catalog = JSON.parse(dialog.dataset.violationCatalog || '{}'); } catch {}

    const syncSpecific = () => {
        const entry = catalog[group.value];
        specific.replaceChildren(new Option(entry ? 'Select a specific violation' : 'Select a violation first', ''));
        (entry?.items || []).forEach((item) => specific.add(new Option(item, item)));
        if (entry && !entry.items.includes('Other')) specific.add(new Option('Other — enter manually', 'Other'));
        specific.disabled = !entry;
        classification.value = entry?.classification || '';
        syncOther();
    };
    const syncOther = () => {
        const visible = specific.value === 'Other';
        otherField.hidden = !visible;
        otherInput.required = visible;
        otherInput.disabled = !visible;
        if (!visible) otherInput.value = '';
    };
    const syncProgressive = () => {
        progressiveFields.forEach((field) => {
            field.hidden = !progressive.checked;
            const select = field.querySelector('select');
            select.required = progressive.checked;
            select.disabled = !progressive.checked;
        });
        if (progressive.checked) form.elements.current_occurrence.value = String(Number(form.elements.previous_infractions.value) + 1);
    };
    const open = (button) => {
        form.reset();
        form.elements.student_id.value = button.dataset.studentId;
        dialog.querySelector('[data-violation-student]').textContent = button.dataset.studentName;
        dialog.querySelector('[data-violation-school-id]').textContent = button.dataset.studentSchoolId;
        dialog.querySelector('[data-violation-initial]').textContent = (button.dataset.studentName || '?').trim().charAt(0).toUpperCase();
        syncSpecific();
        syncProgressive();
        dialog.showModal();
        window.requestAnimationFrame(() => group.focus());
    };

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-issue-violation]');
        if (button) open(button);
    });
    group.addEventListener('change', syncSpecific);
    specific.addEventListener('change', syncOther);
    progressive.addEventListener('change', syncProgressive);
    form.elements.previous_infractions.addEventListener('change', () => {
        form.elements.current_occurrence.value = String(Number(form.elements.previous_infractions.value) + 1);
    });
    form.addEventListener('submit', (event) => {
        if (!form.reportValidity() || form.dataset.submitting === '1') { event.preventDefault(); return; }
        form.dataset.submitting = '1';
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        button.textContent = 'Issuing…';
    });
    dialog.addEventListener('close', () => {
        delete form.dataset.submitting;
        const button = form.querySelector('[type="submit"]');
        button.disabled = false;
        button.textContent = 'Issue Violation';
        syncSpecific();
        syncProgressive();
    });
    syncSpecific();
    syncProgressive();
});
