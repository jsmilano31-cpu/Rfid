(() => {
    'use strict';
    const cache = new Map();
    const endpoint = '/api/holidays.php';
    const request = async (url, options = {}) => {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, ...options });
        let data;
        try { data = await response.json(); } catch { throw new Error('Unable to load holidays. Refresh the page and try again.'); }
        if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to load holidays.');
        return data;
    };
    const load = (year) => {
        if (!cache.has(year)) cache.set(year, request(`${endpoint}?year=${year}`).catch(error => { cache.delete(year); throw error; }));
        return cache.get(year);
    };
    const open = (entry) => {
        const dialog = document.querySelector('#campus-holiday-dialog');
        if (!dialog) return;
        const form = dialog.querySelector('[data-holiday-form]');
        const tags = entry.customTags;
        form.reset();
        form.elements.date.value = tags.date;
        form.elements.holiday_key.value = tags.holidayKey;
        form.elements.no_classes.checked = Boolean(tags.noClasses);
        form.elements.no_classes.disabled = !tags.editable;
        dialog.querySelector('#campus-holiday-title').textContent = entry.title;
        dialog.querySelector('[data-holiday-date]').textContent = new Date(`${tags.date}T12:00:00`).toLocaleDateString([], { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        dialog.querySelector('[data-holiday-type]').textContent = tags.typeLabel;
        dialog.querySelector('[data-holiday-policy]').textContent = tags.noClasses ? 'Campus decision: no classes' : 'Campus decision: follow normal scheduling rules';
        dialog.querySelector('[data-holiday-choice]').hidden = !tags.canManage;
        dialog.querySelector('[data-holiday-save]').hidden = !tags.editable;
        dialog.querySelector('[data-holiday-access]').textContent = tags.editable
            ? 'Save to confirm this campus-wide decision. Removing the override restores normal scheduling rules.'
            : (tags.canManage ? 'Past class dates are read-only to preserve attendance history.' : 'An academic calendar manager can set this date as a campus no-class holiday.');
        dialog.querySelector('[data-holiday-status]').textContent = '';
        if (!dialog.open) dialog.showModal();
        requestAnimationFrame(() => (tags.editable ? form.elements.no_classes : dialog.querySelector('[data-dialog-close]')).focus());
    };
    window.CampusHolidays = { load, open };
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.querySelector('[data-holiday-form]');
        form?.addEventListener('submit', async event => {
            event.preventDefault();
            const button = form.querySelector('[data-holiday-save]');
            if (button.disabled || button.hidden) return;
            const status = form.querySelector('[data-holiday-status]');
            const data = new FormData(form);
            data.set('no_classes', form.elements.no_classes.checked ? '1' : '0');
            button.disabled = true;
            status.textContent = 'Saving campus decision…'; status.className = 'ajax-form-status';
            try {
                const result = await request(endpoint, { method: 'POST', body: data });
                const year = Number(form.elements.date.value.slice(0, 4));
                cache.set(year, Promise.resolve(result));
                form.closest('dialog').close();
                document.dispatchEvent(new CustomEvent('campus:holidays-updated', { detail: { year, result } }));
                const toast = document.createElement('div'); toast.className = 'app-toast'; toast.setAttribute('role', 'status'); toast.textContent = result.message;
                document.querySelector('[data-toast-region]')?.append(toast); setTimeout(() => toast.remove(), 6000);
            } catch (error) { status.textContent = error.message; status.className = 'ajax-form-status is-error'; }
            finally { button.disabled = false; }
        });
    });
})();
