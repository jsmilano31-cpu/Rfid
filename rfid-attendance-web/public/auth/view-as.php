<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;

Auth::requireLogin();
try {
    Csrf::verify();
    $roleId = filter_var($_POST['role_id'] ?? null, FILTER_VALIDATE_INT);
    if ($roleId === false || $roleId < 1) throw new DomainException('Select a valid role.');
    Auth::setPreviewRole((int) $roleId);
    redirect(Auth::usesAdminArea() ? '/admin/' : '/index/');
} catch (DomainException $exception) {
    flash('danger', $exception->getMessage());
    redirect('/admin/');
}
