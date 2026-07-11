<?php

declare(strict_types=1);

defined('BASED') || exit;

final class Request
{
    private const MAX_BODY_BYTES = 1048576; // 1MB

    private static ?array $body = null;

    public static function normalizePath(string $rawPath, string $scriptName): string
    {
        $path = strtok($rawPath, '?');

        if ($path === false || $path === '') {
            $path = '/';
        }

        // An encoded '.', '/', or '\' in a path is always an evasion attempt —
        // these characters never need encoding as literal path data. Reject
        // pre-decode, matching Apache's AllowEncodedSlashes Off default. (A
        // double-encoded payload like %252E survives as an inert literal segment;
        // that is acceptable because path() output feeds only route matching,
        // which has no filesystem sink.)
        if (preg_match('#%2e|%2f|%5c#i', $path) === 1) {
            throw new HttpException(400, 'Bad request');
        }

        $path = rawurldecode($path);

        if (str_contains($path, "\0")) {
            throw new HttpException(400, 'Bad request');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw new HttpException(400, 'Bad request');
            }
        }

        $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($base !== '' && $base !== '.' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }

        return '/' . trim($path, '/');
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function path(): string
    {
        // PATH_INFO first: this IS the no-mod_rewrite fallback.
        $raw = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '/';

        return self::normalizePath((string) $raw, (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        $value = $_GET[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (preg_match('/^Bearer\s+(\S+)$/i', (string) $header, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public static function ip(): string
    {
        // X-Forwarded-For is client-controlled and spoofable, which would defeat
        // any rate limiting keyed on it. Only REMOTE_ADDR is trusted.
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }

        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($length > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Request body too large');
        }

        $type = strtolower((string) self::header('Content-Type'));

        if (str_contains($type, 'multipart/form-data')) {
            return self::$body = $_POST;
        }

        // Bound the read itself — the CONTENT_LENGTH pre-check above does not fire
        // when the header is absent (chunked transfer), so this is the real cap.
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);

        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Request body too large');
        }

        if ($raw === '') {
            return self::$body = [];
        }

        if (str_contains($type, 'json')) {
            try {
                $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                // A body you cannot parse is 400, not 422.
                throw new HttpException(400, 'Malformed JSON body');
            }

            return self::$body = is_array($decoded) ? $decoded : [];
        }

        // PHP does not populate $_POST for PUT/PATCH, even with a form content-type.
        $parsed = [];
        parse_str($raw, $parsed);

        return self::$body = $parsed;
    }
}
