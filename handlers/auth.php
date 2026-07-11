<?php

declare(strict_types=1);

defined('BASED') || exit;

function handle_register(array $params): void
{
    $input = Validator::check(Request::body(), [
        'name' => 'required|string|min:1|max:255',
        'email' => 'required|email|max:255',
        'password' => 'required|string|min:8|max:200',
    ]);

    // No 409 "email taken": that is a user-enumeration oracle. But the response
    // must also be constant-TIME — user_create() runs bcrypt (~200ms) while the
    // skip path is ~10ms, which leaks existence via timing. So the exists-branch
    // does equivalent bcrypt work, mirroring Auth::dummyHash() in login.
    if (!user_email_exists($input['email'])) {
        try {
            user_create($input['name'], $input['email'], $input['password']);
        } catch (PDOException $e) {
            // Lost a race to a concurrent registration of the same email. Respond
            // identically (202 below) — a 409 here would leak that the email exists.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    } else {
        password_hash($input['password'], PASSWORD_DEFAULT);
    }

    Response::json([
        'message' => 'Registration received. If the address was new, you can now log in.',
    ], 202);
}

function handle_login(array $params): void
{
    $input = Validator::check(Request::body(), [
        'email' => 'required|email',
        'password' => 'required|string',
    ]);

    $ip = Request::ip();

    if (Auth::throttled($input['email'], $ip)) {
        header('Retry-After: 900');
        Response::error('Too many login attempts. Try again later.', 429);
    }

    $record = user_find_auth_record($input['email']);

    // password_verify ALWAYS runs — against a dummy hash when the account does
    // not exist — so a missing account and a wrong password take the same
    // wall-clock time. Returning early on !$record is a user-enumeration oracle.
    $hash = $record['password_hash'] ?? Auth::dummyHash();
    $verified = password_verify($input['password'], $hash);

    if (!$verified || $record === null || $record['status'] !== 'active') {
        Auth::recordFailure($input['email'], $ip);
        error_log('[based] failed login for ' . $input['email'] . ' from ' . $ip);

        Response::error('Invalid credentials', 401);
    }

    // PASSWORD_DEFAULT is not stable across PHP versions. Without this, every user
    // is locked out on a PHP upgrade — which on cPanel is a dropdown click.
    if (password_needs_rehash($record['password_hash'], PASSWORD_DEFAULT)) {
        DB::run('UPDATE users SET password_hash = :hash WHERE id = :id', [
            'hash' => password_hash($input['password'], PASSWORD_DEFAULT),
            'id' => (int) $record['id'],
        ]);
    }

    Auth::clearFailures($input['email']);

    // env() returns '' (not the default) for a present-but-blank key, and (int)''
    // is 0 -> instantly-expiring tokens. `?: '86400'` covers blank/0, and the
    // 60s floor rejects a negative or absurdly tiny configured value.
    $ttl = max(60, (int) (env('JWT_TTL') ?: '86400'));

    $token = Jwt::encode([
        'sub' => (int) $record['id'],
        'tv' => (int) $record['token_version'],
    ], $ttl);

    Response::json([
        'token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => $ttl,
    ]);
}

function handle_me(array $params): void
{
    Response::json(Auth::user());
}
