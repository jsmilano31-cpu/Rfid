<div class="auth-card">
    <div class="auth-brand">
        <span class="brand-mark" aria-hidden="true">RF</span>
        <div>
            <p class="eyebrow">Campus operations</p>
            <h1>RFID Attendance</h1>
        </div>
    </div>

    <div class="auth-copy">
        <h2>Welcome back</h2>
        <p>Sign in with your school ID or institutional email.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="stack-lg">
        <?= \App\Core\Csrf::field() ?>
        <label class="field">
            <span>School ID or email</span>
            <input type="text" name="identifier" value="<?= e($identifier) ?>" autocomplete="username" required autofocus>
        </label>
        <label class="field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="button button-primary button-block" type="submit">Sign in</button>
    </form>

    <?php if (config('app')['environment'] !== 'production'): ?>
        <div class="demo-note">
            <strong>Demo administrator</strong>
            <span>ADMIN-001 / Password123!</span>
        </div>
    <?php endif; ?>
</div>
