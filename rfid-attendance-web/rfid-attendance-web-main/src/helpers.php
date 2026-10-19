<?php

declare(strict_types=1);

function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        // Apache's Windows MPM is threaded. putenv() changes process-global state and can expose
        // partially loaded credentials when roster and calendar requests start together. Keep the
        // resolved environment request-local in $_ENV while still honoring non-empty server values.
        $serverValue = array_key_exists($key, $_ENV) ? $_ENV[$key] : getenv($key);
        $_ENV[$key] = $serverValue === false || $serverValue === '' ? $value : $serverValue;
    }
}

function env(string $key, mixed $default = null): mixed
{
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

function config(string $file): array
{
    static $items = [];
    return $items[$file] ??= require BASE_PATH . "/config/{$file}.php";
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Convert either a 24-hour database value or a 12-hour form value to MySQL TIME.
 */
function normalize_time(mixed $value, bool $allowEmpty = false): ?string
{
    $time = strtoupper(trim((string) $value));
    if ($time === '') {
        if ($allowEmpty) {
            return null;
        }
        throw new DomainException('Select a valid time.');
    }

    foreach (['!H:i:s', '!H:i', '!g:i A', '!h:i A'] as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $time);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $parsed->format('H:i:s');
        }
    }

    throw new DomainException('Select a valid time in AM/PM format.');
}

function format_time(mixed $value, string $fallback = '—'): string
{
    $time = trim((string) $value);
    if ($time === '') {
        return $fallback;
    }

    try {
        return (new DateTimeImmutable($time))->format('g:i A');
    } catch (Throwable) {
        return $fallback;
    }
}

function format_datetime(mixed $value, string $fallback = '—'): string
{
    $dateTime = trim((string) $value);
    if ($dateTime === '') {
        return $fallback;
    }

    try {
        return (new DateTimeImmutable($dateTime))->format('M j, Y · g:i A');
    } catch (Throwable) {
        return $fallback;
    }
}

function url(string $path = ''): string
{
    return config('app')['url'] . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

function request_input(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}
