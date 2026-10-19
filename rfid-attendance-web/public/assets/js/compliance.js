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
        specific._choicesInstance?.destroy();
        specific._choicesInstance = null;
        specific.replaceChildren(new Option(entry ? 'Select a specific violation' : 'Select a violation first', ''));
        (entry?.items || []).forEach((item) => specific.add(new Option(item, item)));
        if (entry && !entry.items.includes('Other')) specific.add(new Option('Other — enter manually', 'Other'));
        specific.disabled = !entry;
        window.initializeSearchableSelect?.(specific);
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
        if (!form.reportValidity()) event.preventDefault();
    });
    dialog.addEventListener('close', () => {
        syncSpecific();
        syncProgressive();
    });
    syncSpecific();
    syncProgressive();
});

(() => {
    'use strict';
    const workspace = document.querySelector('[data-compliance-workspace]');
    if (!workspace) return;
    if (location.hash === '#reports-audit-trail') {
        const destination = new URL('/admin/', location.origin);
        destination.searchParams.set('page', 'audit-trail');
        for (const [oldKey, newKey] of [['audit_q','q'],['audit_entity','entity']]) {
            const value = new URL(location.href).searchParams.get(oldKey);
            if (value) destination.searchParams.set(newKey, value);
        }
        location.replace(destination.href);
        return;
    }
    const legacy = {
        '#reports-deficiencies': ['students', 'compliance-results'],
        '#reports-class-attendance': ['classes', 'compliance-class-attendance'],
        '#reports-event-attendance': ['events', 'compliance-event-attendance'],
        '#reports-audit-trail': ['audit', 'compliance-audit-trail'],
    }[location.hash];
    if (legacy && workspace.querySelector(`[data-compliance-tab="${legacy[0]}"]`)) {
        const destination = new URL(location.href);
        destination.searchParams.set('tab', legacy[0]);
        destination.hash = legacy[1];
        if (workspace.dataset.currentTab !== legacy[0]) {
            location.replace(destination.href);
            return;
        }
        history.replaceState(null, '', destination.href);
        document.getElementById(legacy[1])?.scrollIntoView();
    }
    const exportLink = workspace.querySelector('[data-compliance-export]');
    const filters = workspace.querySelector('[data-live-filter]');
    if (exportLink && filters) {
        // Live filtering replaces only the table. Resolve export scope at click time.
        exportLink.addEventListener('click', () => {
            const destination = new URL(exportLink.href);
            const values = new FormData(filters);
            for (const field of ['q', 'program_id', 'section_id']) {
                const value = String(values.get(field) || '').trim();
                if (value) destination.searchParams.set(field, value);
                else destination.searchParams.delete(field);
            }
            exportLink.href = destination.href;
        });
    }
})();
