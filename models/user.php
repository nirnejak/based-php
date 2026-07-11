<?php

declare(strict_types=1);

defined('BASED') || exit;

// Columns are enumerated, never SELECT *. password_hash and token_version can
// only leave the database through user_find_auth_record() / user_find_auth_state().
const USER_PUBLIC_COLUMNS = 'id, name, email, status, created_at';

function user_find(int $id): ?array
{
    return DB::first('SELECT ' . USER_PUBLIC_COLUMNS . ' FROM users WHERE id = :id', ['id' => $id]);
}

function user_all(int $limit = 50, int $offset = 0): array
{
    return DB::all(
        'SELECT ' . USER_PUBLIC_COLUMNS . ' FROM users ORDER BY id LIMIT :limit OFFSET :offset',
        ['limit' => $limit, 'offset' => $offset]
    );
}

function user_find_auth_record(string $email): ?array
{
    return DB::first(
        'SELECT id, email, password_hash, token_version, status FROM users WHERE email = :email',
        ['email' => $email]
    );
}

function user_find_auth_state(int $id): ?array
{
    return DB::first('SELECT id, token_version, status FROM users WHERE id = :id', ['id' => $id]);
}

// Columns are named explicitly, so a request body can never write password_hash
// or token_version. There is no $fillable to forget.
function user_create(string $name, string $email, string $password): int
{
    return DB::insert(
        'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :hash)',
        [
            'name' => $name,
            'email' => $email,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
        ]
    );
}

function user_update(int $id, string $name, string $email): int
{
    return DB::run(
        'UPDATE users SET name = :name, email = :email WHERE id = :id',
        ['name' => $name, 'email' => $email, 'id' => $id]
    )->rowCount();
}

function user_delete(int $id): int
{
    return DB::run('DELETE FROM users WHERE id = :id', ['id' => $id])->rowCount();
}

function user_email_exists(string $email): bool
{
    return DB::first('SELECT id FROM users WHERE email = :email', ['email' => $email]) !== null;
}

// Any change to who a user is, or whether they may act, bumps token_version —
// which instantly kills every outstanding token for that user.
function user_set_password(int $id, string $password): void
{
    DB::run(
        'UPDATE users SET password_hash = :hash, token_version = token_version + 1 WHERE id = :id',
        ['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id]
    );
}
