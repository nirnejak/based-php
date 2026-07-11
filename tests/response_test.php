<?php

declare(strict_types=1);

require_once BASE_PATH . '/lib/env.php';
require_once BASE_PATH . '/lib/response.php';

test('encode produces compact JSON without escaping slashes', function (): void {
    assert_eq('{"url":"https://a.b/c"}', Response::encode(['url' => 'https://a.b/c']));
});

test('encode leaves unicode unescaped', function (): void {
    assert_eq('{"name":"José"}', Response::encode(['name' => 'José']));
});

// json_encode() returns false on invalid UTF-8 (e.g. a latin1 MySQL column) and
// PHP happily emits a 200 with an empty body. JSON_THROW_ON_ERROR turns that
// silent data loss into a catchable error.
test('encode throws on invalid UTF-8 rather than silently emitting nothing', function (): void {
    assert_throws(function (): void {
        Response::encode(['bad' => "\xB1\x31"]);
    });
});

test('errorBody omits details when there are none', function (): void {
    assert_eq(['error' => 'Not found'], Response::errorBody('Not found'));
});

test('errorBody includes details when present', function (): void {
    assert_eq(
        ['error' => 'Validation failed', 'details' => ['email' => 'is required']],
        Response::errorBody('Validation failed', ['email' => 'is required'])
    );
});

// cPanel commonly sits behind LiteSpeed Cache or Cloudflare. A 200
// application/json with no Cache-Control is heuristically cacheable — meaning
// the login response CONTAINING THE BEARER TOKEN can be stored by an
// intermediary and served to the next visitor.
test('security headers include no-store', function (): void {
    assert_true(in_array('Cache-Control: no-store', Response::securityHeaders(), true));
});

test('security headers include nosniff and a JSON content type', function (): void {
    $headers = Response::securityHeaders();
    assert_true(in_array('X-Content-Type-Options: nosniff', $headers, true));
    assert_true(in_array('Content-Type: application/json; charset=utf-8', $headers, true));
});

test('cors reflects an allowlisted origin', function (): void {
    $headers = Response::corsHeadersFor('https://app.test', ['https://app.test']);
    assert_true(in_array('Access-Control-Allow-Origin: https://app.test', $headers, true));
});

test('cors ignores an origin that is not allowlisted', function (): void {
    $headers = Response::corsHeadersFor('https://evil.test', ['https://app.test']);
    foreach ($headers as $h) {
        assert_true(!str_starts_with($h, 'Access-Control-Allow-Origin'), "leaked: {$h}");
    }
});

test('cors emits no allow-origin when the allowlist is empty', function (): void {
    $headers = Response::corsHeadersFor('https://app.test', []);
    foreach ($headers as $h) {
        assert_true(!str_starts_with($h, 'Access-Control-Allow-Origin'), "leaked: {$h}");
    }
});

// Without Vary: Origin a CDN caches one origin's ACAO header and serves it to another.
test('cors always emits Vary: Origin', function (): void {
    assert_true(in_array('Vary: Origin', Response::corsHeadersFor(null, []), true));
});

// The design is bearer-only and cookieless. Allow-Credentials buys nothing and
// pre-arms a footgun: any allowlist slip becomes account takeover.
test('cors never emits Allow-Credentials', function (): void {
    $headers = Response::corsHeadersFor('https://app.test', ['https://app.test']);
    foreach ($headers as $h) {
        assert_true(!str_contains($h, 'Allow-Credentials'), "should not ship credentials mode: {$h}");
    }
});

test('HttpException carries status and details', function (): void {
    $e = new HttpException(422, 'Validation failed', ['email' => 'is required']);
    assert_eq(422, $e->status());
    assert_eq('Validation failed', $e->getMessage());
    assert_eq(['email' => 'is required'], $e->details());
});

test('HttpException defaults details to an empty array', function (): void {
    assert_eq([], (new HttpException(404, 'Not found'))->details());
});
