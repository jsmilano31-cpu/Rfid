<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Services\UserPreferenceService;

if ($user = Auth::user()) {
    redirect(UserPreferenceService::landingUrl((int)$user['id'], Auth::usesAdminArea()));
}

$error = null;
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    try {
        Csrf::verify();
        if ($identifier === '' || $password === '') {
            $error = 'Enter your school ID or email and password.';
        } elseif (!Auth::attempt($identifier, $password)) {
            $error = 'The supplied credentials are invalid or the account is inactive.';
        } else {
            $user = Auth::user();
            redirect(UserPreferenceService::landingUrl((int)$user['id'], Auth::usesAdminArea()));
        }
    } catch (\Throwable $exception) {
        $error = config('app')['debug'] ? $exception->getMessage() : 'Unable to sign in right now.';
    }
}

View::render('auth/login', [
    'title' => 'Sign in',
    'error' => $error,
    'identifier' => $identifier,
], 'auth');
