<?php

declare(strict_types=1);

require_once BASE_PATH . '/lib/env.php';
require_once BASE_PATH . '/lib/response.php';
require_once BASE_PATH . '/lib/db.php';
require_once BASE_PATH . '/models/user.php';

// THE test that stops the framework shipping every user's bcrypt hash to the
// internet. An enumerated SELECT makes the leak structurally impossible; a
// `$hidden` config against a column named password_hash is a silent no-op.
test('user_find never returns password_hash', function (): void {
    db_boot();
    $id = user_create('A', 'a@t.co', 'secret123');
    $user = user_find($id);
    assert_true(!array_key_exists('password_hash', $user), 'user_find leaked the password hash');
    assert_true(!array_key_exists('token_version', $user));
    assert_eq('a@t.co', $user['email']);
});

test('user_all never returns password_hash', function (): void {
    db_boot();
    user_create('A', 'a@t.co', 'secret123');
    user_create('B', 'b@t.co', 'secret123');
    foreach (user_all() as $user) {
        assert_true(!array_key_exists('password_hash', $user), 'user_all leaked a password hash');
    }
});

test('user_create hashes the password — it is never stored in plaintext', function (): void {
    db_boot();
    user_create('A', 'a@t.co', 'secret123');
    $record = user_find_auth_record('a@t.co');
    assert_true($record['password_hash'] !== 'secret123', 'password stored in plaintext');
    assert_true(password_verify('secret123', $record['password_hash']));
});

test('user_find_auth_record is the one place password_hash surfaces', function (): void {
    db_boot();
    user_create('A', 'a@t.co', 'secret123');
    $record = user_find_auth_record('a@t.co');
    assert_true(array_key_exists('password_hash', $record));
    assert_eq(0, (int) $record['token_version']);
});

test('user_find_auth_record returns null for an unknown email', function (): void {
    db_boot();
    assert_null(user_find_auth_record('ghost@t.co'));
});

test('user_find returns null for an unknown id', function (): void {
    db_boot();
    assert_null(user_find(99999));
});

test('user_update changes name and email', function (): void {
    db_boot();
    $id = user_create('A', 'a@t.co', 'secret123');
    assert_eq(1, user_update($id, 'Z', 'z@t.co'));
    assert_eq('Z', user_find($id)['name']);
});

test('user_delete removes the row', function (): void {
    db_boot();
    $id = user_create('A', 'a@t.co', 'secret123');
    assert_eq(1, user_delete($id));
    assert_null(user_find($id));
});

test('user_email_exists reports correctly', function (): void {
    db_boot();
    user_create('A', 'a@t.co', 'secret123');
    assert_true(user_email_exists('a@t.co'));
    assert_eq(false, user_email_exists('ghost@t.co'));
});

test('user_find_auth_state exposes token_version and status', function (): void {
    db_boot();
    $id = user_create('A', 'a@t.co', 'secret123');
    $state = user_find_auth_state($id);
    assert_eq(0, (int) $state['token_version']);
    assert_eq('active', $state['status']);
});

test('a duplicate email insert throws a 23000 constraint violation', function (): void {
    db_boot();
    user_create('A', 'dup@t.co', 'password123');
    $code = null;
    try {
        user_create('B', 'dup@t.co', 'password123');
    } catch (PDOException $e) {
        $code = $e->getCode();
    }
    assert_eq('23000', $code, 'the email UNIQUE violation must surface as SQLSTATE 23000');
});
