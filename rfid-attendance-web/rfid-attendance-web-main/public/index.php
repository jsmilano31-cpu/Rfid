<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Services\UserPreferenceService;

$user = Auth::user();
if (!$user) {
    redirect('/auth/login.php');
}

redirect(UserPreferenceService::landingUrl((int)$user['id'], Auth::usesAdminArea()));
