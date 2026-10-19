<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access denied · <?= e(config('app')['name']) ?></title>
    <link rel="icon" href="<?= e(url('/assets/logos/favicons/favicon.ico')) ?>" sizes="any">
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body class="auth-shell">
    <main class="auth-wrap">
        <div class="auth-card">
            <p class="eyebrow">403</p>
            <h1>Access denied</h1>
            <p>You do not have permission to open this page.</p>
            <a class="button button-primary" href="<?= e(url('/')) ?>">Return to dashboard</a>
        </div>
    </main>
</body>
</html>
