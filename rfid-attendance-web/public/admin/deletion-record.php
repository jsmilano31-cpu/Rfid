<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Services\DeletionConflictService;

Auth::requireLogin();

try {
    $record = DeletionConflictService::linkedRecord(
        (string) ($_GET['type'] ?? ''),
        (int) ($_GET['id'] ?? 0)
    );
    View::render('admin/deletion_record', [
        'title' => $record['title'],
        'active' => 'compliance',
        'record' => $record,
    ]);
} catch (DomainException $exception) {
    $status = str_contains(strtolower($exception->getMessage()), 'permission') ? 403 : 404;
    http_response_code($status);
    View::render('admin/deletion_record', [
        'title' => 'Linked record unavailable',
        'active' => 'compliance',
        'record' => null,
        'error' => $exception->getMessage(),
    ]);
}
