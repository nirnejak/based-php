# BasedPHP

A simple and bare PHP framework for building JSON APIs.

Express/Hono, but PHP — and it deploys by dragging a folder onto FTP.
No Composer. No dependencies. No build step. Small enough to read in one sitting.

## Quickstart

```bash
cp .env.example .env
php -r "echo bin2hex(random_bytes(32));"   # paste into JWT_SECRET
sh start.sh                                # http://localhost:8080
curl localhost:8080/health
# => {"status":"ok","php":"8.5"}
```

Requires PHP 8.0+. On macOS: `brew install php` (macOS 12+ ships none).

## Routing

`routes.php` is the entire API surface:

```php
Router::get('/users/:id', 'handle_users_show', ['Auth::required']);
```

Handlers take `array $params` and end in a `Response::json()`:

```php
function handle_users_show(array $params): void
{
    $id = Validator::check($params, ['id' => 'required|int|min:1'])['id'];
    $user = user_find($id);

    if ($user === null) {
        throw new HttpException(404, 'User not found');
    }

    Response::json($user);
}
```

Middleware must **return `true`** to let the handler run. Anything else stops it.

Register static segments before `:param` ones — first match wins, so `/users/:id`
above `/users/me` would swallow `me` as an id.

## Models

Models write **explicit SQL with enumerated columns**. There is no query builder,
no `$fillable`, no `$hidden` — and that is a security feature, not a shortcut:

- An enumerated `SELECT` makes leaking `password_hash` impossible.
- An enumerated `INSERT` makes mass assignment impossible.

```php
function user_find(int $id): ?array
{
    return DB::first('SELECT id, name, email FROM users WHERE id = :id', ['id' => $id]);
}
```

`DB::run()` binds every value by its real PHP type, so `LIMIT :n` just works.

## API

Everything under `/users` sits behind `Auth::required` (a bearer JWT):

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/health` | – | liveness check, `{"status":"ok","php":"8.5"}` |
| POST | `/auth/register` | – | always `202`; generic message, never reveals whether the email exists |
| POST | `/auth/login` | – | `{ "token": "...", "token_type": "Bearer", "expires_in": 86400 }` |
| GET | `/auth/me` | required | the current user |
| GET | `/users` | required | pagination: `?limit=1..100&offset>=0` |
| GET | `/users/:id` | required | |
| PATCH | `/users/:id` | required | accepts only `name`, `email` — nothing else can be written |
| DELETE | `/users/:id` | required | `204` on success |

## Auth

JWT bearer tokens, HS256, hand-rolled — one algorithm, one code path, so it
cannot have an algorithm-confusion bug.

There is no server-side logout — a plain logout is client-side (drop the token).
To revoke immediately, bump `users.token_version`; every outstanding token for
that user dies at once. Do this on password change, ban, suspend, and delete.

Honest framing: because `token_version` is checked on every request, this is a
session token that happens to be signed. That is the trade that buys you revocation.

Keep the token in memory on the client, not `localStorage` — an XSS on the
consuming app steals a token you cannot revoke without a `token_version` bump.

Passwords are hashed with `password_hash($password, PASSWORD_DEFAULT)` —
currently bcrypt at cost 12 on PHP 8.x. The `password_hash` column is
`VARCHAR(255)`, not a fixed-width bcrypt column, because `PASSWORD_DEFAULT`
is not stable across PHP versions and may grow (e.g. to Argon2). On login,
`password_needs_rehash()` transparently re-hashes with the current default,
so a PHP upgrade never locks anyone out.

## Deploy to cPanel

1. **MySQL Databases** → create the DB and user. cPanel prefixes both with your
   account name (`acct_appdb`). The host is `localhost`.
2. **phpMyAdmin** → import `database.sql`.
3. **MultiPHP Manager** → set PHP **8.0+**.
4. Fill in `.env` (especially `JWT_SECRET` — the app refuses to boot without it).
5. FTP the folder into `public_html`. **Turn on "show hidden files" in your FTP
   client first** — otherwise `.htaccess` and `.env` silently do not upload, and
   that is the #1 cause of "every route 404s".
6. Permissions: **755 dirs, 644 files. Never 777** — under suEXEC a world-writable
   file is *refused*, producing a 500.

Do **not** upload `tests/`. Do **not** overwrite `.htaccess` on redeploy — cPanel
injects its PHP handler line into it, and wiping that reverts you to PHP 5.6.

### SQLite instead of MySQL

Setting `DB_DSN=sqlite:...` in `.env` overrides `DB_*` entirely — that is the
whole SQLite story. The file **must live outside the web root**. If
`APP_ENV=production` and the configured path resolves inside the app's own
directory, the framework refuses to open it and throws a 500 instead — a file
it won't open beats an `.htaccess` deny rule that a misconfigured host might
ignore. `:memory:` and non-production environments are exempt from the check.

### Verify the deploy — this is the security test suite

```bash
sh tests/smoke.sh https://yoursite.com
```

That is the automated form of this checklist. By hand, every one of these must hold:

```
curl -i https://site/.env       → 403 or 404, NOT the file contents
curl -i https://site/health     → 200   (the rewrite works)
curl -H 'Authorization: Bearer garbage' https://site/auth/me   → 401, not 200
```

A denied file (`.env`, `database.sql`, anything under `lib/`) returns **403**
on real Apache — the `<FilesMatch>` deny rule in `.htaccess` — or **404**
locally, where the router simply doesn't recognize the path. Both are correct;
neither is "the file contents."

**If `/.env` returns text, stop and rotate `JWT_SECRET` and the DB password.**

## Troubleshooting

| Symptom | Cause |
|---|---|
| Every route 404s | `.htaccess` did not upload (hidden dotfile), or `AllowOverride None` |
| 401 with a valid token | Apache stripped the `Authorization` header — uncomment `CGIPassAuth On` in `.htaccess` |
| Whole site 500s right after upload | An unknown `.htaccess` directive — `CGIPassAuth` on Apache < 2.4.13, or `Options` under a locked-down `AllowOverride` |
| Works locally, "class not found" in prod | Filename case. Linux is case-sensitive; macOS is not |
| `/users` behaves strangely | MultiViews — needs `Options -MultiViews` |
| DB error mentioning `LIMIT` | A `LIMIT` value bound as a string. Pass an `int` |
| App won't boot, config error | `JWT_SECRET` is missing or under 32 chars. This is deliberate — a blank secret makes every token forgeable |

No mod_rewrite? The whole API still works at `/index.php/users/1`.

## Design rules

- **The framework stays small and readable.** No query builder, no ORM,
  no autoloader. Weigh every feature against that.
- **No side effects at include time.** Every file but `index.php` only *defines* things.
- **No writable directory, no cache.** Zero chmod story. Logs go to `error_log()`
  → the cPanel error log. Do not add a file logger or a route cache.
- **No closing `?>`.** One stray byte after it silently no-ops every `header()`.
