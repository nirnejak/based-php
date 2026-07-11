<?php

declare(strict_types=1);

require_once BASE_PATH . '/lib/response.php';
require_once BASE_PATH . '/lib/request.php';

test('normalizes a simple path at the document root', function (): void {
    assert_eq('/users', Request::normalizePath('/users', '/index.php'));
});

test('drops the query string', function (): void {
    assert_eq('/users', Request::normalizePath('/users?page=2', '/index.php'));
});

test('normalizes a trailing slash', function (): void {
    assert_eq('/users', Request::normalizePath('/users/', '/index.php'));
});

test('normalizes the root path', function (): void {
    assert_eq('/', Request::normalizePath('/', '/index.php'));
});

// The normal cPanel case: the app is dropped in public_html/myapi/.
test('strips the base path for a subfolder deploy', function (): void {
    assert_eq('/users/1', Request::normalizePath('/myapi/users/1', '/myapi/index.php'));
});

// The no-mod_rewrite fallback. Without this, a host with AllowOverride None
// serves a dead API instead of a working one.
test('strips a leading /index.php (PATH_INFO fallback)', function (): void {
    assert_eq('/users/1', Request::normalizePath('/index.php/users/1', '/index.php'));
});

test('handles the PATH_INFO fallback inside a subfolder', function (): void {
    assert_eq('/users/1', Request::normalizePath('/myapi/index.php/users/1', '/myapi/index.php'));
});

test('decodes percent-encoded segments', function (): void {
    assert_eq('/users/a b', Request::normalizePath('/users/a%20b', '/index.php'));
});

// Both branches must normalize identically, or a route behaves differently on
// the host than on your laptop.
test('rejects a traversal segment', function (): void {
    assert_throws(function (): void {
        Request::normalizePath('/users/../../.env', '/index.php');
    });
});

test('rejects an encoded traversal segment', function (): void {
    assert_throws(function (): void {
        Request::normalizePath('/users/%2E%2E/%2E%2E/.env', '/index.php');
    });
});

test('rejects a null byte', function (): void {
    assert_throws(function (): void {
        Request::normalizePath('/users/%00', '/index.php');
    });
});

test('reads a header case-insensitively', function (): void {
    $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
    assert_eq('application/json', Request::header('Content-Type'));
    assert_eq('application/json', Request::header('content-type'));
    unset($_SERVER['HTTP_CONTENT_TYPE']);
});

test('returns null for an absent header', function (): void {
    assert_null(Request::header('X-Nope'));
});

test('extracts a bearer token', function (): void {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc.def.ghi';
    assert_eq('abc.def.ghi', Request::bearerToken());
    unset($_SERVER['HTTP_AUTHORIZATION']);
});

test('extracts a bearer token case-insensitively', function (): void {
    $_SERVER['HTTP_AUTHORIZATION'] = 'bearer abc';
    assert_eq('abc', Request::bearerToken());
    unset($_SERVER['HTTP_AUTHORIZATION']);
});

// Apache CGI/FastCGI strips Authorization. The .htaccess rewrite puts it back
// under REDIRECT_HTTP_AUTHORIZATION. Without this fallback, JWT works locally
// and silently 401s on every request in production.
test('falls back to REDIRECT_HTTP_AUTHORIZATION', function (): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer xyz';
    assert_eq('xyz', Request::bearerToken());
    unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
});

test('returns null when there is no bearer token', function (): void {
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    assert_null(Request::bearerToken());
});

test('ignores a non-bearer authorization scheme', function (): void {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
    assert_null(Request::bearerToken());
    unset($_SERVER['HTTP_AUTHORIZATION']);
});

test('method returns the request method uppercased', function (): void {
    $_SERVER['REQUEST_METHOD'] = 'post';
    assert_eq('POST', Request::method());
    unset($_SERVER['REQUEST_METHOD']);
});

test('method defaults to GET when absent', function (): void {
    unset($_SERVER['REQUEST_METHOD']);
    assert_eq('GET', Request::method());
});

// PATH_INFO is the no-mod_rewrite fallback and MUST take precedence, or a route
// resolves differently under mod_rewrite than without it.
test('path prefers PATH_INFO over REQUEST_URI', function (): void {
    $_SERVER['PATH_INFO'] = '/users/1';
    $_SERVER['REQUEST_URI'] = '/something/else?x=1';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    assert_eq('/users/1', Request::path());
    unset($_SERVER['PATH_INFO'], $_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME']);
});

test('path falls back to REQUEST_URI when PATH_INFO is absent', function (): void {
    unset($_SERVER['PATH_INFO']);
    $_SERVER['REQUEST_URI'] = '/users/2?page=3';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    assert_eq('/users/2', Request::path());
    unset($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME']);
});

test('query returns a value and falls back to the default', function (): void {
    $_GET['page'] = '2';
    assert_eq('2', Request::query('page'));
    assert_eq('z', Request::query('missing', 'z'));
    assert_null(Request::query('missing'));
    unset($_GET['page']);
});

// ?key[]=x makes $_GET['key'] an array; query() must not return it as a string.
test('query returns the default for an array-typed value', function (): void {
    $_GET['key'] = ['x'];
    assert_eq('fallback', Request::query('key', 'fallback'));
    unset($_GET['key']);
});

// The login rate-limiter keys on ip(). If ip() honoured X-Forwarded-For, an
// attacker could rotate a spoofed header and evade the limiter entirely.
test('ip returns REMOTE_ADDR and ignores X-Forwarded-For', function (): void {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
    assert_eq('203.0.113.7', Request::ip());
    unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
});

test('normalizePath rejects an encoded slash in a segment', function (): void {
    assert_throws(function (): void {
        Request::normalizePath('/users/1%2F2', '/index.php');
    });
});

test('normalizePath rejects an encoded dot (single-encoded traversal)', function (): void {
    assert_throws(function (): void {
        Request::normalizePath('/a/%2e%2e/b', '/index.php');
    });
});

// A legitimate encoded percent sign (e.g. "50% off" -> 50%25off) must NOT be a
// false positive. This is why the guard targets %2e/%2f/%5c, not all residual %.
test('normalizePath allows a legitimately encoded percent sign', function (): void {
    assert_eq('/search/50%off', Request::normalizePath('/search/50%25off', '/index.php'));
});
