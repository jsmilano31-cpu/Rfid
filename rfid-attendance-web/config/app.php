<?php

declare(strict_types=1);

return [
    'name' => 'CampusTrack',
    'environment' => env('APP_ENV', 'production'),
    'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
    'url' => rtrim((string) env('APP_URL', 'http://127.0.0.1'), '/'),
    'timezone' => 'Asia/Taipei',
];
