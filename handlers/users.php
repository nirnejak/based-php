<?php

declare(strict_types=1);

defined('BASED') || exit;

function handle_users_index(array $params): void
{
    $query = Validator::check($_GET, [
        'limit' => 'int|min:1|max:100',
        'offset' => 'int|min:0',
    ]);

    Response::json(user_all($query['limit'] ?? 50, $query['offset'] ?? 0));
}

function handle_users_show(array $params): void
{
    // Route params are raw, attacker-controlled path input. Validate, never trust.
    $id = Validator::check($params, ['id' => 'required|int|min:1'])['id'];

    $user = user_find($id);

    if ($user === null) {
        throw new HttpException(404, 'User not found');
    }

    Response::json($user);
}

function handle_users_update(array $params): void
{
    $id = Validator::check($params, ['id' => 'required|int|min:1'])['id'];

    if (user_find($id) === null) {
        throw new HttpException(404, 'User not found');
    }

    // Only name and email are accepted. There is no path by which a request body
    // reaches password_hash or token_version — the model's SQL names its columns.
    $input = Validator::check(Request::body(), [
        'name' => 'required|string|min:1|max:255',
        'email' => 'required|email|max:255',
    ]);

    try {
        user_update($id, $input['name'], $input['email']);
    } catch (PDOException $e) {
        // 23000 = integrity constraint (the email UNIQUE index). A DB error is
        // otherwise a real 500 and must keep propagating.
        if ($e->getCode() === '23000') {
            throw new HttpException(409, 'Email already in use');
        }

        throw $e;
    }

    Response::json(user_find($id));
}

function handle_users_delete(array $params): void
{
    $id = Validator::check($params, ['id' => 'required|int|min:1'])['id'];

    if (user_delete($id) === 0) {
        throw new HttpException(404, 'User not found');
    }

    Response::noContent();
}
