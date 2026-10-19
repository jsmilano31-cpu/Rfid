(() => {
    const toggle = document.querySelector('[data-auth-theme-toggle]');
    if (!toggle) return;

    const root = document.documentElement;
    const sync = () => {
        const dark = root.dataset.theme === 'dark';
        const label = `Switch to ${dark ? 'light' : 'dark'} mode`;
        toggle.setAttribute('aria-label', label);
        toggle.setAttribute('aria-pressed', String(dark));
        toggle.title = label;
    };

    sync();
    toggle.addEventListener('click', () => {
        root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem('campustrack-login-theme', root.dataset.theme); } catch (_) {}
        sync();
    });

    const visual = document.querySelector('.auth-visual');
    const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
    if (!visual || reducedMotion.matches) return;

    // Motion mini runs these transform-only loops through the browser animation engine.
    // The matching CSS animations remain as the offline fallback.
    import('https://cdn.jsdelivr.net/npm/motion@13.4.0/mini/+esm').then(({ animate }) => {
        const first = visual.querySelector('.auth-flow-one');
        const second = visual.querySelector('.auth-flow-two');
        if (!first || !second) return;
        let animations = [];
        const start = () => {
            if (animations.length || reducedMotion.matches) return;
            animations = [
                animate(first, { transform: ['translate3d(-8%, -8%, 0)', 'translate3d(34%, 12%, 0)'] }, { duration: 26, ease: 'easeInOut', repeat: Infinity, repeatType: 'reverse' }),
                animate(second, { transform: ['translate3d(8%, 8%, 0)', 'translate3d(-24%, -16%, 0)'] }, { duration: 32, ease: 'easeInOut', repeat: Infinity, repeatType: 'reverse' }),
            ];
            visual.classList.add('has-motion-library');
        };
        const stop = () => {
            animations.forEach((animation) => animation.stop());
            animations = [];
            visual.classList.remove('has-motion-library');
        };
        start();
        reducedMotion.addEventListener('change', (event) => event.matches ? stop() : start());
        document.addEventListener('visibilitychange', () => {
            animations.forEach((animation) => document.hidden ? animation.pause() : animation.play());
        });
    }).catch(() => {
        // The CSS transform animation continues without an external dependency.
    });
})();
