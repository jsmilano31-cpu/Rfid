<?php
declare(strict_types=1);

final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message) { parent::__construct($message); }
}

final class Api
{
    public static function respond(array $data, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function method(string $expected): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $expected) {
            header('Allow: ' . $expected);
            throw new ApiError(405, 'method_not_allowed', 'This request method is not supported.');
        }
    }

    public static function token(): string { return $_SESSION['mobile_csrf'] ??= bin2hex(random_bytes(32)); }

    public static function write(int $maxBytes = 16384): array
    {
        self::csrf();
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new ApiError(415, 'json_required', 'Send a JSON request.');
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (strlen($raw) > $maxBytes) throw new ApiError(413, 'request_too_large', 'The request is too large.');
        try { $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); } catch (JsonException) { throw new ApiError(400, 'invalid_json', 'The request could not be read.'); }
        if (!is_array($data) || array_is_list($data) && $data !== []) throw new ApiError(400, 'invalid_json', 'Send a JSON object.');
        return $data;
    }

    public static function csrf(): void
    {
        self::method('POST');
        if (!hash_equals(self::token(), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) throw new ApiError(419, 'session_expired', 'Your form session expired. Please try again.');
    }

    public static function text(array $data, string $key, int $max, bool $required = true): string
    {
        if (isset($data[$key]) && !is_string($data[$key])) throw new ApiError(422, 'validation', 'Check the ' . str_replace('_', ' ', $key) . ' field.');
        $value = trim($data[$key] ?? '');
        if (($required && $value === '') || mb_strlen($value) > $max) throw new ApiError(422, 'validation', 'Enter a valid ' . str_replace('_', ' ', $key) . '.');
        return $value;
    }

    public static function password(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || strlen($value) < 8 || strlen($value) > 72) throw new ApiError(422, 'validation', 'Passwords must contain 8 to 72 bytes.');
        return $value;
    }

    public static function db(): PDO { return \App\Core\Database::connection(); }
    public static function rows(string $sql, array $params = []): array
    {
        $query = self::db()->prepare($sql); $query->execute($params); return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function user(?string $requiredRole = null): array
    {
        if (empty($_SESSION['user_id'])) throw new ApiError(401, 'unauthenticated', 'Sign in to continue.');
        $rows = self::rows('SELECT users.*, roles.name AS role_name FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?', [$_SESSION['user_id']]);
        $user = $rows[0] ?? null;
        if (!$user || $user['status'] !== 'active' || !hash_equals($_SESSION['password_version'] ?? '', hash('sha256', $user['password_hash']))) {
            $_SESSION = []; session_regenerate_id(true); throw new ApiError(401, 'unauthenticated', 'Your session ended. Please sign in again.');
        }
        $roles = self::rows('SELECT roles.name FROM user_roles JOIN roles ON roles.id=user_roles.role_id WHERE user_id=?', [$user['id']]);
        $names = array_column($roles, 'name');
        if (!array_intersect(['Student','Teacher'], $names)) throw new ApiError(403, 'portal_role_required', 'Use the web portal for your role.');
        if ($requiredRole !== null && !in_array($requiredRole, $names, true)) throw new ApiError(403, 'role_required', 'This workspace is not available for your account.');
        return $user;
    }

    public static function rateLimit(string $scope, int $limit, int $seconds): void
    {
        $directory = BASE_PATH . '/storage/mobile-rate-limits';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Rate-limit storage unavailable');
        $path = $directory . '/' . hash('sha256', $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'local')) . '.json';
        $file = fopen($path, 'c+');
        if (!$file || !flock($file, LOCK_EX)) throw new RuntimeException('Rate-limit lock unavailable');
        try {
            $state = json_decode(stream_get_contents($file), true) ?: ['start' => time(), 'count' => 0];
            if (time() - $state['start'] >= $seconds) $state = ['start' => time(), 'count' => 0];
            if ($state['count'] >= $limit) { header('Retry-After: ' . max(1, $seconds - (time() - $state['start']))); throw new ApiError(429, 'rate_limited', 'Too many attempts. Please wait before trying again.'); }
            $state['count']++; rewind($file); ftruncate($file, 0); fwrite($file, json_encode($state));
        } finally { flock($file, LOCK_UN); fclose($file); }
    }

    public static function asset(?string $path): ?string
    {
        if (!$path) return null;
        return rtrim((string) config('app')['url'], '/') . '/' . ltrim($path, '/');
    }
}
