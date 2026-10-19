<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Csrf
{
    public static function token(): string
    {
        return $_SESSION['_csrf_token'] ??= bin2hex(random_bytes(32));
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function verify(): void
    {
        $provided = (string) ($_POST['_token'] ?? '');
        if ($provided === '' || !hash_equals(self::token(), $provided)) {
            throw new RuntimeException('The form session expired. Please try again.');
        }
    }
}
