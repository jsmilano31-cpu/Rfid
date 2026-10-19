(() => {
  const form = document.querySelector('[data-settings-form]');
  if (!form) return;
  const root = document.documentElement;
  const apply = () => {
    root.dataset.theme = form.elements.theme_mode.value;
    root.dataset.accent = form.elements.accent_theme.value;
    root.dataset.motion = form.querySelector('input[type="checkbox"][name="reduced_motion"]').checked ? 'reduced' : 'full';
    root.dataset.density = form.querySelector('input[type="checkbox"][name="compact_mode"]').checked ? 'compact' : 'comfortable';
  };
  form.addEventListener('change', apply);
  apply();
})();
