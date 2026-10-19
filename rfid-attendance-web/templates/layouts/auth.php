<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101828">
    <title><?= e($title) ?> · <?= e(config('app')['name']) ?></title>
    <link rel="icon" href="<?= e(url('/assets/logos/favicons/favicon.ico')) ?>" sizes="any">
    <link rel="apple-touch-icon" href="<?= e(url('/assets/logos/favicons/apple-touch-icon.png')) ?>">
    <link rel="manifest" href="<?= e(url('/assets/logos/web/site.webmanifest')) ?>">
    <link rel="preload" as="image" href="<?= e(url('/assets/logos/backgrounds/bg2.webp')) ?>">
    <script>
        try {
            const savedTheme = localStorage.getItem('campustrack-login-theme');
            document.documentElement.dataset.theme = savedTheme === 'dark' || savedTheme === 'light'
                ? savedTheme : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        } catch (_) {
            document.documentElement.dataset.theme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
    </script>
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css?v=' . (string) filemtime(PUBLIC_PATH . '/assets/css/app.css'))) ?>">
</head>
<body class="auth-shell auth-login-shell">
    <main class="auth-layout">
        <button type="button" class="auth-theme-toggle" data-auth-theme-toggle aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode">
            <svg class="auth-theme-moon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20.2 15.7A8.8 8.8 0 0 1 8.3 3.8 8.8 8.8 0 1 0 20.2 15.7Z"/></svg>
            <svg class="auth-theme-sun" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
        </button>
        <section class="auth-visual" aria-label="CampusTrack at the University of St. La Salle">
            <div class="auth-photo auth-photo-first" aria-hidden="true"></div>
            <div class="auth-photo auth-photo-second" aria-hidden="true"></div>
            <div class="auth-visual-shade" aria-hidden="true"></div>
            <div class="auth-flow auth-flow-one" aria-hidden="true"></div>
            <div class="auth-flow auth-flow-two" aria-hidden="true"></div>
            <div class="auth-visual-content">
                <img class="auth-hero-logo" src="<?= e(url('/assets/logos/logos/png/campustrack-logo-dark.png')) ?>" alt="CampusTrack">
                <div class="auth-visual-message">
                    <p class="auth-visual-kicker">Your campus, connected</p>
                    <h2>Every moment<br>on track.</h2>
                    <p>Attendance, academics, and campus life in one place.</p>
                </div>
                <p class="auth-visual-caption">#LiveLoveLaSalle</p>
            </div>
        </section>
        <section class="auth-form-panel" aria-label="Sign in">
            <div class="auth-wrap"><?= $content ?></div>
        </section>
    </main>
    <script src="<?= e(url('/assets/js/password-toggle.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/password-toggle.js'))) ?>" defer></script>
    <script src="<?= e(url('/assets/js/auth-login.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/auth-login.js'))) ?>" defer></script>
</body>
</html>
