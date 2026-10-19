(() => {
    const eyeMarkup = `
        <svg class="password-eye-on" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/>
            <circle cx="12" cy="12" r="2.75"/>
        </svg>
        <svg class="password-eye-off" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3 3l18 18M10.6 6.15A10.8 10.8 0 0 1 12 6c6 0 9.5 6 9.5 6a15.4 15.4 0 0 1-2.1 2.75M6.2 6.2C3.85 8 2.5 12 2.5 12s3.5 6 9.5 6a10.3 10.3 0 0 0 4.05-.82M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
        </svg>`;

    const enhancePasswordInput = (input) => {
        if (!(input instanceof HTMLInputElement)
            || input.dataset.passwordToggle === 'false'
            || input.closest('.password-input-shell')) return;

        const shell = document.createElement('span');
        shell.className = 'password-input-shell';
        input.before(shell);
        shell.append(input);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'password-visibility-toggle';
        button.setAttribute('aria-label', 'Show password');
        button.setAttribute('aria-pressed', 'false');
        button.title = 'Show password';
        button.innerHTML = eyeMarkup;
        shell.append(button);

        const syncDisabledState = () => { button.disabled = input.disabled; };
        syncDisabledState();

        button.addEventListener('click', () => {
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            const action = showing ? 'Show password' : 'Hide password';
            button.setAttribute('aria-label', action);
            button.setAttribute('aria-pressed', showing ? 'false' : 'true');
            button.title = action;
            input.focus({ preventScroll: true });
            try { input.setSelectionRange(input.value.length, input.value.length); } catch (_) {}
        });

        new MutationObserver(syncDisabledState).observe(input, { attributes: true, attributeFilter: ['disabled'] });
    };

    const enhanceWithin = (root) => {
        if (root instanceof HTMLInputElement && root.matches('input[type="password"]')) enhancePasswordInput(root);
        root.querySelectorAll?.('input[type="password"]').forEach(enhancePasswordInput);
    };

    const start = () => {
        enhanceWithin(document);
        new MutationObserver((mutations) => mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
            if (node instanceof Element) enhanceWithin(node);
        }))).observe(document.body, { childList: true, subtree: true });
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
