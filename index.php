<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('BasedPHP requires PHP 8.0 or newer.');
}

// `php -S localhost:8080 index.php` — let the dev server serve real static files.
// Scoped to /static/ only, mirroring the .htaccess `RewriteRule ^static/ - [L]`.
// A blanket real-file passthrough here would let the built-in server hand out
// database.sql, .sqlite files, or model source directly from the docroot.
if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if (str_starts_with($path, '/static/')) {
        // realpath() resolves '..' and symlinks; then confirm the result is
        // genuinely inside static/ before serving it. A naive __DIR__ . $path
        // concat would let /static/../.env escape the directory and be served raw.
        $file = realpath(__DIR__ . $path);
        $root = realpath(__DIR__ . '/static');

        if ($file !== false && $root !== false
            && str_starts_with($file, $root . DIRECTORY_SEPARATOR)
            && is_file($file)
            && !str_ends_with($file, '.php')) {
            return false;
        }
    }
}

define('BASED', true);
define('BASE_PATH', __DIR__);

require __DIR__ . '/lib/env.php';
require __DIR__ . '/lib/response.php';
require __DIR__ . '/lib/request.php';
require __DIR__ . '/lib/router.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/jwt.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/validator.php';
require __DIR__ . '/models/user.php';
require __DIR__ . '/handlers/health.php';
require __DIR__ . '/handlers/auth.php';
require __DIR__ . '/handlers/users.php';

Env::load(BASE_PATH . '/.env');

error_reporting(E_ALL);
ini_set('display_errors', '0');

// Registered before anything else can throw.
set_exception_handler(function (Throwable $e): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($e instanceof HttpException) {
        Response::error($e->getMessage(), $e->status(), $e->details());
    }

    error_log(sprintf(
        '[based] %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    // PDOException::getMessage() embeds the DSN and username. Never leak it.
    $debug = env('APP_DEBUG') === 'true';

    Response::error($debug ? $e->getMessage() : 'Internal server error', 500);
});

// Without this, a fatal leaks a blank 200 or an HTML error page into your JSON API.
register_shutdown_function(function (): void {
    $error = error_get_last();

    if ($error === null) {
        return;
    }

    if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    error_log(sprintf('[based] fatal: %s in %s:%d', $error['message'], $error['file'], $error['line']));

    Response::error('Internal server error', 500);
});

Response::cors();

require __DIR__ . '/routes.php';

Router::dispatch(Request::method(), Request::path());
