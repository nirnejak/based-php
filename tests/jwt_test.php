<?php

declare(strict_types=1);

require_once BASE_PATH . '/lib/env.php';
require_once BASE_PATH . '/lib/jwt.php';

function jwt_boot(): void
{
    $path = sys_get_temp_dir() . '/based_jwt_env_' . bin2hex(random_bytes(6));
    file_put_contents($path, 'JWT_SECRET=' . str_repeat('s', 32) . "\n");
    Env::load($path);
}

test('a freshly encoded token round-trips', function (): void {
    jwt_boot();
    $claims = Jwt::decode(Jwt::encode(['sub' => 7, 'tv' => 0], 3600));
    assert_eq(7, $claims['sub']);
    assert_eq(0, $claims['tv']);
});

test('encode adds iat and exp', function (): void {
    jwt_boot();
    $claims = Jwt::decode(Jwt::encode(['sub' => 1], 3600));
    assert_true(is_int($claims['exp']));
    assert_true(is_int($claims['iat']));
    assert_true($claims['exp'] > time());
});

test('a token signed with a different secret is rejected', function (): void {
    jwt_boot();
    $token = Jwt::encode(['sub' => 1], 3600);

    $other = sys_get_temp_dir() . '/based_jwt_env_' . bin2hex(random_bytes(6));
    file_put_contents($other, 'JWT_SECRET=' . str_repeat('x', 32) . "\n");
    Env::load($other);

    assert_null(Jwt::decode($token), 'a token from another secret must not verify');
});

test('a tampered payload is rejected', function (): void {
    jwt_boot();
    [$h, $p, $s] = explode('.', Jwt::encode(['sub' => 1], 3600));
    $forged = rtrim(strtr(base64_encode('{"sub":999,"exp":9999999999}'), '+/', '-_'), '=');
    assert_null(Jwt::decode("{$h}.{$forged}.{$s}"), 'a rewritten payload must not verify');
});

// The entire JWT CVE catalogue. There is only ONE code path and it always calls
// hash_hmac — so a token claiming alg:none has no valid signature and dies at
// the signature check, before any JSON is parsed.
test('the alg:none attack is rejected', function (): void {
    jwt_boot();
    $header = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode('{"sub":1,"exp":9999999999}'), '+/', '-_'), '=');
    assert_null(Jwt::decode("{$header}.{$payload}."), 'alg:none must be rejected');
});

test('an alg swap to HS512 is rejected', function (): void {
    jwt_boot();
    $secret = str_repeat('s', 32);
    $header = rtrim(strtr(base64_encode('{"alg":"HS512","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode('{"sub":1,"exp":9999999999}'), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha512', "{$header}.{$payload}", $secret, true)), '+/', '-_'), '=');
    assert_null(Jwt::decode("{$header}.{$payload}.{$sig}"), 'the algorithm is pinned, never selected from the token');
});

test('an expired token is rejected', function (): void {
    jwt_boot();
    assert_null(Jwt::decode(Jwt::encode(['sub' => 1], -3600)), 'an expired token must not verify');
});

// $claims['exp'] ?? PHP_INT_MAX is a real bug people write. It makes an immortal token.
test('a token with no exp is rejected', function (): void {
    jwt_boot();
    $secret = str_repeat('s', 32);
    $header = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode('{"sub":1}'), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true)), '+/', '-_'), '=');
    assert_null(Jwt::decode("{$header}.{$payload}.{$sig}"), 'a missing exp must not mean "never expires"');
});

test('a non-integer exp is rejected', function (): void {
    jwt_boot();
    $secret = str_repeat('s', 32);
    $header = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode('{"sub":1,"exp":"9999999999"}'), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true)), '+/', '-_'), '=');
    assert_null(Jwt::decode("{$header}.{$payload}.{$sig}"));
});

// is_int also rejects float and array exp — pin them, since any of these slipping
// through the type check would produce an immortal token.
test('a float exp is rejected', function (): void {
    jwt_boot();
    $secret = str_repeat('s', 32);
    $header = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode('{"sub":1,"exp":9999999999.5}'), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true)), '+/', '-_'), '=');
    assert_null(Jwt::decode("{$header}.{$payload}.{$sig}"));
});

test('an array exp is rejected', function (): void {
    jwt_boot();
    $secret = str_repeat('s', 32);
    $header = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode('{"sub":1,"exp":[9999999999]}'), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true)), '+/', '-_'), '=');
    assert_null(Jwt::decode("{$header}.{$payload}.{$sig}"));
});

test('garbage input returns null instead of throwing', function (): void {
    jwt_boot();
    assert_null(Jwt::decode(''));
    assert_null(Jwt::decode('garbage'));
    assert_null(Jwt::decode('a.b'));
    assert_null(Jwt::decode('a.b.c.d'));
    assert_null(Jwt::decode('!!!.???.***'));
});

// base64_decode without $strict silently DISCARDS out-of-alphabet characters, so
// "abc!!!def" and "abcdef" decode identically — letting an attacker mutate the
// token text without changing the bytes you verify.
test('a non-canonical base64 signature is rejected', function (): void {
    jwt_boot();
    [$h, $p, $s] = explode('.', Jwt::encode(['sub' => 1], 3600));
    assert_null(Jwt::decode("{$h}.{$p}.{$s}==="), 'padding variants must not verify');
});

test('an oversized token is rejected without doing work', function (): void {
    jwt_boot();
    assert_null(Jwt::decode(str_repeat('a', 5000) . '.b.c'));
});

// A blank secret produces a perfectly valid signature that verifies against
// itself — auth would APPEAR to work while anyone could forge admin tokens.
test('a missing secret throws rather than signing with an empty key', function (): void {
    $path = sys_get_temp_dir() . '/based_jwt_env_' . bin2hex(random_bytes(6));
    file_put_contents($path, "JWT_SECRET=\n");
    Env::load($path);

    assert_throws(function (): void {
        Jwt::encode(['sub' => 1], 3600);
    }, 'a blank JWT_SECRET must be fatal, not silently accepted');
});

test('a short secret throws', function (): void {
    $path = sys_get_temp_dir() . '/based_jwt_env_' . bin2hex(random_bytes(6));
    file_put_contents($path, "JWT_SECRET=tooshort\n");
    Env::load($path);

    assert_throws(function (): void {
        Jwt::encode(['sub' => 1], 3600);
    });
});
