<?php

declare(strict_types=1);

defined('BASED') || exit;

final class HttpException extends RuntimeException
{
    /** @var array<string,mixed> */
    private array $details;

    public function __construct(int $status, string $message, array $details = [])
    {
        parent::__construct($message, $status);
        $this->details = $details;
    }

    public function status(): int
    {
        $code = (int) $this->getCode();

        return $code >= 400 && $code <= 599 ? $code : 500;
    }

    public function details(): array
    {
        return $this->details;
    }
}

final class Response
{
    public static function encode($data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    public static function errorBody(string $message, array $details = []): array
    {
        $body = ['error' => $message];

        if ($details !== []) {
            $body['details'] = $details;
        }

        return $body;
    }

    /** @return string[] */
    public static function securityHeaders(): array
    {
        return [
            'Content-Type: application/json; charset=utf-8',
            'X-Content-Type-Options: nosniff',
            'Cache-Control: no-store',
            'Referrer-Policy: no-referrer',
            'Strict-Transport-Security: max-age=31536000',
        ];
    }

    /** @return string[] */
    public static function corsHeadersFor(?string $origin, array $allowed): array
    {
        $headers = ['Vary: Origin'];

        if ($origin !== null && $origin !== '' && in_array($origin, $allowed, true)) {
            $headers[] = 'Access-Control-Allow-Origin: ' . $origin;
            $headers[] = 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS';
            $headers[] = 'Access-Control-Allow-Headers: Authorization, Content-Type';
            $headers[] = 'Access-Control-Max-Age: 86400';
        }

        return $headers;
    }

    public static function json($data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);

            foreach (self::securityHeaders() as $header) {
                header($header);
            }
        }

        if ($status !== 204 && $status !== 304) {
            echo self::encode($data);
        }

        exit;
    }

    public static function error(string $message, int $status = 400, array $details = []): void
    {
        self::json(self::errorBody($message, $details), $status);
    }

    public static function noContent(): void
    {
        self::json(null, 204);
    }

    public static function html(string $view, array $data = []): void
    {
        $path = BASE_PATH . '/views/' . basename($view) . '.php';

        if (!is_file($path)) {
            throw new HttpException(500, 'View not found');
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        extract($data, EXTR_SKIP);
        require $path;

        exit;
    }

    public static function cors(): void
    {
        $raw = (string) env('CORS_ORIGINS', '');
        $allowed = array_values(array_filter(array_map('trim', explode(',', $raw))));

        foreach (self::corsHeadersFor(Request::header('Origin'), $allowed) as $header) {
            header($header, false);
        }

        // Preflights carry no Authorization header and no body. If CORS ran after
        // auth or routing, every cross-origin POST from a browser would fail.
        if (Request::method() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
