<?php

declare(strict_types=1);

require_once BASE_PATH . '/lib/env.php';
require_once BASE_PATH . '/lib/response.php';
require_once BASE_PATH . '/lib/request.php';
require_once BASE_PATH . '/lib/db.php';
require_once BASE_PATH . '/lib/jwt.php';
require_once BASE_PATH . '/lib/auth.php';
require_once BASE_PATH . '/models/user.php';

test('throttled is false below the threshold', function (): void {
    db_boot();
    for ($i = 0; $i < 4; $i++) {
        Auth::recordFailure('a@t.co', '1.1.1.1');
    }
    assert_eq(false, Auth::throttled('a@t.co', '1.1.1.1'));
});

// bcrypt cost 10 is ~10ms, so an unthrottled endpoint allows thousands of
// guesses per minute. This is the single most important control in the auth system.
test('throttled is true at the threshold', function (): void {
    db_boot();
    for ($i = 0; $i < 5; $i++) {
        Auth::recordFailure('a@t.co', '1.1.1.1');
    }
    assert_true(Auth::throttled('a@t.co', '1.1.1.1'));
});

test('a successful login clears the failure counter', function (): void {
    db_boot();
    for ($i = 0; $i < 5; $i++) {
        Auth::recordFailure('a@t.co', '1.1.1.1');
    }
    Auth::clearFailures('a@t.co');
    assert_eq(false, Auth::throttled('a@t.co', '2.2.2.2'));
});

// Throttling on email alone would let an attacker lock a victim out of their
// own account just by failing five logins against their address.
test('throttling one email does not throttle a different one', function (): void {
    db_boot();
    for ($i = 0; $i < 5; $i++) {
        Auth::recordFailure('victim@t.co', '9.9.9.9');
    }
    assert_eq(false, Auth::throttled('someone-else@t.co', '1.1.1.1'));
});

test('the dummy hash is a real verifiable bcrypt hash', function (): void {
    $hash = Auth::dummyHash();
    assert_true(password_verify('invalid-placeholder', $hash));
    // Derived from PASSWORD_DEFAULT, so it can never drift from real hashes and
    // silently reopen the timing oracle it exists to close.
    assert_eq(false, password_needs_rehash($hash, PASSWORD_DEFAULT));
});

test('the dummy hash is stable within a request', function (): void {
    assert_eq(Auth::dummyHash(), Auth::dummyHash());
});

// Regression: the throttle window must be timezone-independent. The old code
// computed the boundary with local-tz date() while the DB stored another clock,
// so on a positive-UTC-offset host the throttle silently never fired.
test('throttle fires regardless of the process timezone', function (): void {
    db_boot();
    $tz = date_default_timezone_get();
    date_default_timezone_set('Asia/Tokyo');

    try {
        for ($i = 0; $i < 5; $i++) {
            Auth::recordFailure('tz@t.co', '5.5.5.5');
        }
        assert_true(Auth::throttled('tz@t.co', '5.5.5.5'), 'throttle must fire under a non-UTC timezone');
    } finally {
        date_default_timezone_set($tz);
    }
});
