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
 * Normalize whitespace before storing a human-readable label.
 */
function normalize_label_whitespace(mixed $value): string
{
    return trim((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
}

/**
 * Format a person's name consistently, including initials, apostrophes, and Mc names.
 */
function normalize_person_name(mixed $value): string
{
    $name = normalize_label_whitespace($value);
    if ($name === '') {
        return '';
    }

    $name = mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    $name = (string) preg_replace_callback(
        "/(?<=['’])(\p{Ll})/u",
        static fn(array $match): string => mb_strtoupper($match[1], 'UTF-8'),
        $name
    );
    $name = (string) preg_replace_callback(
        '/\bMc(\p{Ll})/u',
        static fn(array $match): string => 'Mc' . mb_strtoupper($match[1], 'UTF-8'),
        $name
    );
    $name = (string) preg_replace_callback(
        '/\b(?:Ii|Iii|Iv|Vi{0,3}|Ix|X|Jr|Sr)\.?\b/u',
        static fn(array $match): string => mb_strtoupper(rtrim($match[0], '.'), 'UTF-8')
            . (str_ends_with($match[0], '.') ? '.' : ''),
        $name
    );

    return $name;
}

/**
 * Format course titles and other named entities while retaining common acronyms.
 */
function normalize_title_case(mixed $value): string
{
    $label = normalize_label_whitespace($value);
    if ($label === '') {
        return '';
    }

    $label = mb_convert_case(mb_strtolower($label, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    $minorWords = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'nor', 'of', 'on', 'or', 'the', 'to', 'via', 'with'];
    $words = preg_split('/\s+/u', $label) ?: [];
    $lastIndex = count($words) - 1;
    foreach ($words as $index => &$word) {
        $plain = mb_strtolower(trim($word, "\"'()[]{}.,:;!?"), 'UTF-8');
        if ($index > 0 && $index < $lastIndex && in_array($plain, $minorWords, true)) {
            $word = (string) preg_replace('/\p{L}+/u', $plain, $word, 1);
        }
    }
    unset($word);
    $label = implode(' ', $words);

    $acronyms = [
        'AI', 'API', 'BSCS', 'BSEMC', 'BSIT', 'CAS', 'CBA', 'CCS', 'CED', 'COE', 'CON', 'CSS', 'CTH',
        'HTML', 'ICT', 'IT', 'IoT', 'LAB', 'LEC', 'PHP', 'RFID', 'SQL', 'STEM', 'TVL', 'UI', 'USLS', 'UX',
    ];
    foreach ($acronyms as $acronym) {
        $label = (string) preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($acronym, '/') . '(?![\p{L}\p{N}])/iu', $acronym, $label);
    }
    $label = (string) preg_replace_callback(
        '/\b(?:Ii|Iii|Iv|Vi{0,3}|Ix|X)\b/u',
        static fn(array $match): string => mb_strtoupper($match[0], 'UTF-8'),
        $label
    );

    return $label;
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
    $destination = str_starts_with($path, 'http') ? $path : url($path);
    $isFetchPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        && strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';

    if ($isFetchPost) {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        $error = null;
        foreach ($messages as $message) {
            if (in_array($message['type'] ?? '', ['danger', 'error', 'warning'], true)) {
                $error = $message;
            }
        }
        $last = $error ?? ($messages ? $messages[array_key_last($messages)] : null);
        $loginRedirect = str_contains(parse_url($destination, PHP_URL_PATH) ?: '', '/auth/login.php');
        $ok = $error === null && !$loginRedirect;

        http_response_code($loginRedirect ? 401 : ($ok ? 200 : 422));
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => $ok,
            'message' => (string) ($last['message'] ?? ($ok ? 'Changes saved.' : 'The action could not be completed.')),
            'redirect' => $destination,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    $status = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 303 : 302;
    header('Location: ' . $destination, true, $status);
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
