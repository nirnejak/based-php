# BasedPHP — API Framework Design

**Date:** 2026-07-14
**Status:** Approved design, ready for implementation planning

## Purpose

BasedPHP is a deliberately small PHP framework for building JSON APIs — "Express/Hono for PHP, plus a little more." It ships CRUD, auth, and a database layer, and it is designed to be deployed by dragging a folder onto an FTP server or a cPanel file manager.

## Hard constraints

These are non-negotiable and every decision below is subordinate to them.

- **Vanilla PHP 8.0+ only.** Zero Composer, zero third-party dependencies, zero build step.
- **Deploy by dragging the folder.** No CLI at deploy time — shared hosts do not reliably offer SSH.
- **Target host:** cPanel / typical Apache shared hosting (mod_rewrite, MySQL, selectable PHP 8.x).
- **Readable in one sitting.** Enforced by a hard budget: the framework (`lib/`) stays **under ~600 lines**. Every proposed feature is weighed against that number. Without a stated budget, "simple" is a vibe and it erodes.

PHP 8.0 is a floor, not a suggestion: no `readonly`, no `enum`, no `never`, no `array_is_list`. Those are 8.1, and using one is a *parse* error on an 8.0 host — not a graceful failure. `index.php` asserts `PHP_VERSION_ID >= 80000` at boot.

## Decisions

| Decision | Choice |
|---|---|
| Scope | API-first (JSON), with one optional ~10-line HTML render helper |
| Routing | Single front controller + `.htaccess`, Express-style `Router::get('/users/:id', …)` |
| Auth | JWT bearer tokens, hand-rolled HS256 via `hash_hmac` (keeps the zero-dependency promise) |
| Batteries | `.env` loader, input validation, CORS + JSON helpers, thin PDO layer |
| Brute-force protection | Included — `login_attempts` table, 5 failures / 15 min → 429 |
| Transport | `Cache-Control: no-store` on all JSON; HTTPS redirect **on** by default; HSTS |
| Revocation | `users.token_version` INT column, checked on every authenticated request |

## Architecture

Flat tree. No `public/` web-root split — on cPanel the document root is a fixed `public_html` and relocating it needs exactly the panel/CLI access we assumed away. Safety is bought back with layered in-band defenses (below) instead.

```
.htaccess              # highest-leverage file in the repo
.user.ini              # the only way to set PHP ini on cPanel PHP-FPM
.env.example           # committed; .env is gitignored
.gitignore
index.php              # front controller (~30 lines)
routes.php             # the entire API surface at a glance. This file IS the docs.
database.sql           # MySQL. CREATE TABLE IF NOT EXISTS only.
README.md
lib/                   # the framework. ~600 LOC ceiling.
  env.php              # ~30  parse .env, env(), must()
  router.php           # ~60  method+path table, :params, 404/405
  request.php          # ~50  method/path/body/headers/bearer
  response.php         # ~50  json(), error(), cors(), html()
  db.php               # ~60  lazy PDO + the typed-bind chokepoint
  jwt.php              # ~60  hand-rolled HS256
  auth.php             # ~50  bearer → user, middleware, throttle
  validator.php        # ~60  7 rules + Closure escape hatch
models/user.php        # plain functions. No base class, no ORM.
handlers/auth.php      # register, login, me
handlers/users.php     # CRUD
```

**No namespaces and no autoloader.** For ~8 classes with globally-unique names, `spl_autoload_register` buys nothing and introduces a nasty failure mode: macOS is case-insensitive and Linux hosting is not, so `App\Models\User` resolves locally and fatals with "class not found" *only in production*, with no stack trace because `display_errors` is off. Instead: ~10 explicit `require` lines in `index.php`. Greppable, zero magic, and a missing file fails loudly at boot rather than lazily at first use.

**Hard invariant — no side effects at include time.** Every file except `index.php` may only define functions and classes. It never echoes, connects, or queries on include. This means a direct HTTP hit on a source file outputs nothing *even if `.htaccess` is ignored entirely*. It is defense-in-depth that costs zero lines. The current `utils/database.php`, `api/user.php`, and `models/user.php` all violate it.

### Components

Each is independently readable and depends only on what is listed.

| Component | Does | Depends on |
|---|---|---|
| `env` | Parses `.env` into a private static array. `env($k, $default)`, `must($k)`. | — |
| `router` | Compiles `:param` patterns, matches method+path, dispatches. 404/405 + `Allow` header. | `response` |
| `request` | Reads method, path, query, JSON/form body, headers, bearer token. Owns path resolution. | — |
| `response` | `json()`, `error()`, `cors()`, `html()`. All terminal (they `exit`). | — |
| `db` | Lazy PDO singleton. **One function executes every statement.** | `env` |
| `jwt` | `encode()` / `decode()`. HS256 only. | `env` |
| `auth` | Bearer → user row. Route middleware. Login throttle. | `jwt`, `db`, `request`, `response` |
| `validator` | `check($input, $rules): array` — returns only validated, type-cast keys. | `response` |

### Path resolution

Resolved **once**, in `Request`, for both deploy modes — never branched on downstream:

1. `PATH_INFO` if present (this *is* the no-mod_rewrite fallback), else `strtok($_SERVER['REQUEST_URI'], '?')` to drop the query string.
2. `rawurldecode()`.
3. **Reject** any path containing `%00`, a raw NUL, or a `..` segment → `400`.
4. Strip `dirname($_SERVER['SCRIPT_NAME'])` — this is what makes subfolder deploys work with no config — then strip a leading `/index.php`.
5. Normalize to `'/' . trim($uri, '/')`.

Both branches must go through all five steps. If only the `REQUEST_URI` branch is normalized, the same request produces different `$params` depending on whether mod_rewrite is active — so a route behaves differently on the host than on your laptop.

Trailing slashes are normalized **in PHP, never via a 301**. Redirecting `/users/` → `/users` looks tidy and quietly breaks writes: clients drop the body and downgrade POST/PUT to GET on redirect.

Route order is match order: registering `/users/:id` before `/users/me` swallows `me` as an id. Static segments first, always. Keep every route lowercase — Linux is case-sensitive and your laptop is not.

### The `db` API surface

Five functions. `DB::run()` is the only one that touches PDO; the rest delegate to it.

```php
DB::run(string $sql, array $params = []): PDOStatement   // the chokepoint
DB::all(string $sql, array $params = []): array          // list of assoc rows
DB::first(string $sql, array $params = []): ?array       // one row or null
DB::insert(string $sql, array $params = []): int         // runs, returns (int) lastInsertId
DB::tx(callable $fn): mixed                              // begin/commit/rollback
```

There is no `DB::table()`, no `where()`, no chaining. Anything the helpers do not cover — JOINs, aggregates, upserts — is written as SQL and passed to `DB::all()`, which is exactly as safe because it goes through the same typed-bind chokepoint. The escape hatch is not a bypass.

PDO attributes: `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`, `STRINGIFY_FETCHES=false`. **No `ATTR_PERSISTENT`** — it looks like free performance and is a footgun on cPanel: connections outlive the request, so a leaked transaction or table lock poisons the next request that reuses it, and shared hosts cap concurrent connections hard.

`lastInsertId()` returns a *string*; cast it, or your API silently emits `"id": "7"`. Same class of bug as leaving `STRINGIFY_FETCHES` on, which turns your whole contract all-strings.

DSN comes from `.env`: `DB_DSN` wins verbatim if set (this is the entire SQLite story — `DB_DSN=sqlite:/home/acct/data/app.sqlite`), else a MySQL DSN is assembled from `DB_HOST`/`DB_PORT`/`DB_NAME` with `charset=utf8mb4`. One env var, no driver abstraction layer. If SQLite is used, the file **must** live outside the web root — a `.sqlite` inside `public_html` is your entire database over HTTP, and the `-wal` sidecar leaks the most recent writes even if the main file is denied.

Wrap the `PDO` constructor in try/catch and rethrow a generic `HttpException(500, 'Database connection failed')`. `PDOException::getMessage()` embeds the DSN and username, and `getTraceAsString()` shows the password as a constructor argument — so the trace must not surface even when `APP_DEBUG=true`.

### Request lifecycle

```
Apache → .htaccess rewrite → index.php
  → assert PHP >= 8.0
  → require lib/*
  → set_exception_handler + register_shutdown_function   (JSON errors, always)
  → Response::cors()        (answers OPTIONS preflight and exits)
  → require routes.php      (registers the table)
  → Router::dispatch()
      → match method+path → run middleware → run handler
      → handler: Validator::check() → model → Response::json()
```

CORS is handled **before** routing and **before** auth. Preflight `OPTIONS` requests carry no `Authorization` header, so anything that runs auth first will 401 your own browser clients.

## Security model

Two bugs were found by *composing* the components; each is invisible when you read a component in isolation. Both are resolved structurally, by deleting code rather than adding it.

### Explicit SQL in models

Models write SQL with **enumerated column lists**. There is no query builder, no `$fillable`, no `$hidden`.

```php
function user_find(int $id): ?array {
    return DB::first('SELECT id, name, email, created_at FROM users WHERE id = :id', ['id' => $id]);
}

function user_create(string $name, string $email, string $password): int {
    return DB::insert(
        'INSERT INTO users (name, email, password_hash) VALUES (:n, :e, :p)',
        ['n' => $name, 'e' => $email, 'p' => password_hash($password, PASSWORD_DEFAULT)]
    );
}
```

- An enumerated `SELECT` means **`password_hash` cannot leak**. A `$hidden = ['password']` config against a column actually named `password_hash` is a silent no-op — that is how frameworks ship every user's bcrypt hash to unauthenticated callers.
- An enumerated `INSERT`/`UPDATE` means **mass assignment is impossible**. A builder that accepts any valid column name turns `PATCH /users/1` with `{"password_hash": "<attacker's hash>"}` into account takeover, and `{"token_version": 0}` into un-revoking tokens an admin just killed.

`password_hash` is read by exactly one function (`user_find_auth_record()`), used only by the login handler.

### The typed-bind chokepoint

`DB::run()` is the only function that executes a statement. It binds every value by its real PHP type:

```php
foreach ($params as $k => $v) {
    if (!is_scalar($v) && !is_null($v)) {
        throw new InvalidArgumentException('Bind values must be scalar or null');
    }
    $type = is_int($v) ? PDO::PARAM_INT
          : (is_bool($v) ? PDO::PARAM_INT
          : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR));
    $stmt->bindValue($k, is_bool($v) ? (int) $v : $v, $type);
}
```

Six lines, and it permanently fixes the `LIMIT` bug (below) everywhere — including in hand-written SQL — rather than making every caller remember a special case. The scalar guard matters: `(string) $array` emits a warning that a strict error handler turns into a 500, and `?email[]=a` reaches it trivially.

### JWT

The cryptographic design is the strongest part and ships close to as-specified:

1. **Verify before parse.** Recompute the HMAC over the raw `header.payload` substrings and `hash_equals` it against the decoded signature *before any JSON is parsed*. Nothing downstream runs if it fails.
2. **Pin the algorithm, never dispatch on it.** `private const ALG = 'HS256'`, asserted for defense-in-depth. There is exactly one code path and it always calls `hash_hmac('sha256', …, $secret)`. **A verifier that supports one algorithm cannot have an algorithm-confusion bug** — this is the entire argument for hand-rolling, and it is correct. `{"alg":"none"}` fails at step 1.
3. **Raw-byte `hash_equals`.** Never `===` (not constant-time), never `==` on hex digests (`"0e123…" == "0e456…"` is `true` in PHP).
4. **`base64_decode($s, true)`** (strict) plus a canonicalization round-trip — non-strict mode silently discards out-of-alphabet characters, so an attacker can mutate the token text without changing the bytes you verify.
5. **Require an integer `exp`.** `$claims['exp'] ?? PHP_INT_MAX` is an immortal token.
6. **Fail closed on the secret.** `JWT_SECRET` from `.env`, ≥32 chars, no default, no fallback. `hash_hmac('sha256', $d, '')` returns a perfectly valid signature that verifies against itself — a blank secret means auth *appears to work* while anyone can forge admin tokens. And FTP clients hide dotfiles, so `.env` failing to upload is the common case, not the edge case.
7. **Minimal claims:** `sub`, `tv`, `iat`, `exp`. The payload is base64url, not encryption — anyone can read it.
8. `Jwt::decode()` returns `?array` and never throws on attacker input. Every failure collapses to one identical 401 — don't tell the client *why* the token was rejected.

No refresh tokens. `token_version` delivers the security property refresh rotation is actually bought for (immediate revocation) for one INT column instead of ~150 lines and a second token type. The README will state honestly that this makes it "a session token that happens to be signed."

### Middleware fails closed

Middleware **must return `true` to continue**; the dispatcher halts on anything else.

```php
foreach ($mws as $mw) { if ($mw($params) !== true) return; }
```

The natural-looking alternative (`if ($mw($params) === false) return;`) is **fail-open**: a `void` middleware returns `null`, and `null === false` is `false`, so the short-circuit never trips. It "works" only as a side effect of `Response::json()` calling `exit` deep inside the rejection path. An auth seam must fail closed by construction, not by relying on a transitive `exit`.

### Login throttle

`login_attempts (id, identifier, ip, attempted_at, INDEX(identifier, attempted_at))`. Before `password_verify`, count failures for the email in the last 15 minutes; at ≥5, return `429` with `Retry-After` and **do not even hash**. Throttle on email *and* IP independently so one attacker cannot lock out a victim. Delete rows for that identifier on success; opportunistically purge rows older than a day on ~1% of requests, so no cron is needed.

Login is otherwise constant-time: always run `password_verify()` — against a dummy hash when the user does not exist — so a missing account and a wrong password take the same wall-clock time. The dummy hash is **derived from the same `PASSWORD_DEFAULT` config as real hashes**, never a hardcoded cost-10 literal, or the timing oracle silently reopens the day the host's PHP default cost changes.

`/auth/register` returns a generic response rather than `409 email_taken`, which is a user-enumeration oracle that walks straight past the constant-time login defense.

Every auth failure is logged via `error_log()` — it lands in the cPanel domain error log, which the operator can actually read. With no rate limiting *and* no logging, a credential-stuffing run is both cheap and invisible.

### Transport

`Response::json()` unconditionally emits `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, and `Content-Type: application/json; charset=utf-8`. cPanel commonly sits behind LiteSpeed Cache or Cloudflare, and a `200 application/json` with no cache headers is heuristically cacheable — meaning the login response *containing the bearer token* can be stored by an intermediary and served to the next visitor.

HTTPS redirect is **on by default** in `.htaccess` (cPanel AutoSSL gives free Let's Encrypt), plus `Strict-Transport-Security` and `Referrer-Policy: no-referrer`. A bearer token over plain HTTP is a 24-hour password in cleartext.

CORS reflects an exact origin from a comma-separated `CORS_ORIGINS` allowlist, defaulting to **empty** — a framework that ships permissive CORS by default ships a vulnerability by default. Always emits `Vary: Origin`. **No `Access-Control-Allow-Credentials`**: the design is bearer-only and cookieless, so it buys nothing and pre-arms a footgun where any allowlist slip becomes account takeover.

## The `.htaccess`

This is the highest-leverage file in the repo and deserves more review than the router.

```apache
DirectoryIndex index.php
Options -Indexes -MultiViews

<IfModule mod_rewrite.c>
  RewriteEngine On
  # No RewriteBase — relative substitution works at docroot AND in a subfolder.

  # HTTPS by default. Comment out for local/plain-HTTP.
  RewriteCond %{HTTPS} !=on
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

  # CGI/FastCGI strips Authorization → JWT silently dies. Put it back.
  RewriteCond %{HTTP:Authorization} .
  RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

  # Real assets only. NOT a blanket -f check — see below.
  RewriteRule ^static/ - [L]

  # Everything else is the app. The original query string carries over for free:
  # never write `index.php?url=$1`, never add [QSA].
  RewriteRule ^ index.php [L]
</IfModule>

# Second layer, for hosts where AllowOverride kills mod_rewrite.
# Dual syntax: a bare `Require all denied` is a 500 on Apache 2.2.
<FilesMatch "(^\.|\.(env|sql|sh|md|log|bak|ini)$)">
  <IfModule mod_authz_core.c>Require all denied</IfModule>
  <IfModule !mod_authz_core.c>Order allow,deny
Deny from all</IfModule>
</FilesMatch>

# If auth 401s on your host, uncomment. Do NOT ship it enabled:
# CGIPassAuth only exists in Apache 2.4.13+, and an unknown directive
# is a hard 500 on the whole site.
# CGIPassAuth On

# NEVER add php_value/php_flag here — they 500 on cPanel PHP-FPM/CGI. Use .user.ini.
```

**The critical line is what is *absent*: `RewriteCond %{REQUEST_FILENAME} !-f`.** That rule — which nearly every PHP front-controller tutorial teaches — means *"if the file exists on disk, hand it over."* `.env` exists on disk. Apache has no handler for it, so it serves it as `text/plain`. **`GET /.env` returns your JWT secret and DB password.** Same for `database.sql` and every model source file. Scoping the real-file passthrough to `/static/` is what makes `GET /.env` route to the router and return a JSON 404 instead.

## Config files

| File | Purpose |
|---|---|
| `.env` (gitignored) | Secrets and per-machine values **only**: `DB_*`, `JWT_SECRET`, `APP_ENV`, `CORS_ORIGINS`. If it does not change between your laptop and the host, it is not an env var. |
| `.env.example` (committed) | Every key present with placeholder values. It doubles as the only documentation of what the app needs to run. |
| `.htaccess` | Routing, header rescue, deny rules, HTTPS. |
| `.user.ini` | `display_errors=0`, `log_errors=1`, `expose_php=0`, `zend.exception_ignore_args=1`. **This is the only mechanism that works on cPanel** — `php_flag` in `.htaccess` 500s under PHP-FPM/CGI. It also applies at compile time, so it suppresses parse-error source leaks that no PHP-level handler can ever catch. |
| `.gitignore` | Add `.env`, `.env.*`, `!.env.example`, `*.sqlite*`, `*.bak`, `.DS_Store`. |
| `.editorconfig` | 4-space, LF, UTF-8, final newline. (Current repo mixes 2-space and 0-space.) |
| `database.sql` | MySQL. `CREATE TABLE IF NOT EXISTS` only — **phpMyAdmin rejects `CREATE DATABASE`/`USE`**, because cPanel prefixes DB names and your DB user lacks the privilege. |

The `.env` parser is **hand-rolled, not `parse_ini_file()`**. `parse_ini_file` treats `#`, `!`, `$`, `&`, `|`, `?`, `{`, `}` as reserved, so a generated password like `Kf!8$m#2` silently truncates or errors. It also coerces bare `true`/`false`/`yes`/`no`/`null`. A 20-line parser has a smaller surprise surface than the standard-library function's edge cases. It must also strip `\r` and a UTF-8 BOM — cPanel's web editor and Windows uploads append `\r` to every value, and a `JWT_SECRET` with a trailing `\r` produces HMACs that silently do not match your laptop's.

Values live in a private static array. **Never `putenv()` or `$_ENV`** — `putenv()` exposes secrets to subprocesses and `phpinfo()`, and many hosts set `variables_order` without `E`, so `$_ENV` is simply empty.

`APP_ENV` defaults to `production` and `APP_DEBUG` to `false`. Missing config must fail *safe* (quiet, generic 500), never fail *verbose* (stack trace with the DSN).

## Database schema

```sql
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(255) NOT NULL,
  email         VARCHAR(255) NOT NULL UNIQUE,   -- load-bearing: without it, login is non-deterministic
  password_hash VARCHAR(255) NOT NULL,          -- 255: PASSWORD_DEFAULT may become Argon2 and grow
  token_version INT NOT NULL DEFAULT 0,
  status        VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  identifier   VARCHAR(255) NOT NULL,
  ip           VARCHAR(45) NOT NULL,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_identifier_time (identifier, attempted_at),
  INDEX idx_ip_time (ip, attempted_at)
);
```

Login calls `password_needs_rehash()` and transparently re-hashes. `PASSWORD_DEFAULT` is **not stable across PHP versions** — without this, every user is locked out on a PHP upgrade, which on cPanel is a dropdown a client can click by accident.

`Auth::required()` checks `status` alongside `token_version` — the row is already loaded, so it costs one `if`. Rule to document: *any* change to who a user is or whether they may act (ban, suspend, delete, password change) bumps `token_version`.

## Deployment (cPanel / FTP)

1. cPanel → **MySQL Databases** → create the DB and user. cPanel prefixes both with your account name (`acct_appdb`, `acct_appuser`); the host is `localhost`.
2. cPanel → **phpMyAdmin** → import `database.sql`.
3. cPanel → **MultiPHP Manager** → set PHP **8.0+**.
4. Copy `.env.example` → `.env`, fill it in. Generate the secret with `php -r "echo bin2hex(random_bytes(32));"` — or copy the one the boot error prints.
5. FTP the folder into `public_html`. **Enable "show hidden files" in your FTP client first** — FileZilla and cPanel File Manager hide `.htaccess` and `.env` by default, and the single most common "every route 404s" cause is that `.htaccess` never made it to the server.
6. Permissions: **755 dirs / 644 files. Never 777** — under suEXEC/LSAPI a world-writable file is *refused*, producing a 500. PHP runs as your cPanel user, so 755 is already writable.

The framework has **no writable directory and no cache**. That is a stated design rule, not an accident: it means zero chmod story and zero stale-cache debugging. Logging goes through `error_log()` to the cPanel domain error log. Nobody adds a compiled-route cache or a file logger later.

If mod_rewrite is unavailable, the API is still fully reachable at `/index.php/users/1` via `PATH_INFO`. This is a documented feature, not an accident — it is the difference between a bad first deploy and a dead one.

**Do not overwrite `.htaccess` on redeploy.** cPanel's MultiPHP injects an `AddHandler application/x-httpd-ea-php8x .php` line into it; wiping that silently reverts the site to the host's default PHP (often 5.6/7.x) → instant fatal.

### Post-deploy verification checklist

On a drag-onto-FTP deploy with no CI, **this checklist is the security test suite.** It ships in the README as commands, not prose.

```
curl -i https://site/.env                        → expect 403/404, NOT text
curl -i https://site/database.sql                → expect 403/404
curl -i https://site/lib/db.php                  → expect 403/404 or empty
curl -i https://site/                            → no directory listing
curl -i https://site/health                      → 200 (proves the rewrite works)
curl -i https://site/users | grep -i hash        → NO output
curl -H 'Authorization: Bearer garbage' https://site/auth/me   → 401, not 200
curl -H "Authorization: Bearer $REAL" https://site/auth/me     → 200 (proves the header survived)
```

If `/.env` returns text: **stop and rotate the secret.**

A `GET /health` route that touches nothing — no DB, no auth — is the single most useful thing on a first deploy: it isolates "is the rewrite working" from "is the DB configured" from "is the Authorization header surviving."

Local dev: `php -S localhost:8080 index.php`. The router-script argument is mandatory; without it the built-in server 404s every route *and* serves `/.env` in plaintext to anyone on the same network. `index.php` includes a `PHP_SAPI === 'cli-server'` guard so real static files still serve.

## Changes to the current repo

| Action | File | Why |
|---|---|---|
| **Delete** | `utils/database.php` | Cannot actually run. It binds `:row_limit` for a `LIMIT` clause as a string while setting `ATTR_EMULATE_PREPARES => false` on line 11 — MySQL rejects `LIMIT '10'` with error 1210 (and 1064 with emulation on). It is broken *both* ways. It also opens a connection and runs queries **at include time**, uses `&&` (MySQL-only, breaks SQLite), uses `FETCH_OBJ` (wrong for a JSON API), lacks `ERRMODE_EXCEPTION`, and has a trailing `?>` — one stray byte after which makes every future `header()` call a silent no-op. **The committed credentials are placeholders (`root`/`password`/`dbname`, confirmed in commit `7bd4efa`), so no git-history rewrite is needed.** Just `git rm`. |
| **Delete** | `api/user.php`, `api/` | `echo $_REQUEST['method']` — reflected unescaped input. `$_REQUEST` merges COOKIE per `request_order`, so it is cookie-poisonable. Handlers move to `handlers/`; no directory should share a name with a route prefix. |
| **Delete** | `layout/`, `common/{header,footer,meta}.php` | Four stubs that all `echo "Cannot GET/"`. Replaced by one ~10-line `Response::html()`. |
| **Delete** | `static/*.php`, `lib/.gitkeep`, `utils/` | Directory-listing guards that `Options -Indexes` does for free. |
| **Rewrite** | `index.php` | The `$http_response_header = array(...)` block is **dead code** — that variable is *populated by* PHP after an HTTP stream call; assigning to it sets no headers. The "404" never 404s; the real response is a `200 text/html`. It also returns a literal `"password" => "password"` field — the exact shape of bug this design exists to prevent. |
| **Rewrite** | `models/user.php` | Plain functions with explicit SQL. |
| **Fix** | `start.sh` | → `php -S localhost:8080 index.php`. |
| **Fill** | `database.sql` | Currently empty. |
| **Extend** | `.gitignore` | Currently only `temp/`. |

## Non-goals

Explicitly **not** building, and the reasons are load-bearing:

- **Fluent query builder** — the thing that turns a micro-framework into a framework, and the source of both critical security bugs. Explicit SQL is simpler *and* safer.
- **Namespaces + autoloader** — buys nothing for 8 classes; the case-sensitivity failure mode only appears in production.
- **`Model` base class with `$fillable`/`$hidden`** — the seed crystal of the ORM this project is defined against, and `$hidden` created a silent no-op leak.
- **Two-layer config** (`config.php` + dot-notation `config('db.pass')`) — one `env()`, cast at the call site.
- **`Router::group()`, `*` wildcard routes, overridable `notFound()`** — the wildcard is the only construct that produces an unbounded attacker-controlled path fragment.
- **`_method` / `X-HTTP-Method-Override`** — a WAF-bypass primitive and a pre-armed CSRF vector, justified by a mod_security scenario that is rare on modern EA4. Every real JSON client can send a real DELETE.
- **`set_error_handler` turning warnings into exceptions** — one deprecation on a slightly different PHP 8.x turns a working 200 into a 500. Keep only `set_exception_handler` + `register_shutdown_function` (the fatal catcher earns its 6 lines: it stops an HTML error page from corrupting your JSON).
- **Exception hierarchy** — one `HttpException(int $status, string $message, ?array $details)`. Envelope is flat: `{"error": "...", "details": {...}}`.
- **`Access-Control-Allow-Credentials`** — bearer-only and cookieless; it buys nothing and pre-arms a footgun.
- **Refresh tokens** — `token_version` delivers the actual property (immediate revocation) for one INT column.
- **`router-dev.php`, `dev.sh`, `lint.sh`, CI** — a build step on a project whose pitch is having none.
- **Validator rules** `number`, `bool`, `url`, `regex`, `confirmed` — ship `required|string|int|email|min|max|in` plus the Closure escape hatch, so the rule DSL never has to grow. (`regex` in particular hands a ReDoS to whoever writes the rule string.)

## Build order

1. `.htaccess` + `.user.ini` + `index.php` + `GET /health`. **Deploy to the real host and run the verification checklist before writing anything else.** Every rewrite/header/permission failure surfaces here, and every one of them is invisible locally.
2. `lib/env.php` + `.env.example` + the fail-closed secret guard.
3. `lib/response.php` + `lib/request.php` + the exception handler + CORS.
4. `lib/router.php` + `routes.php` (fail-closed middleware contract).
5. `lib/db.php` + `database.sql` + `models/user.php`.
6. `lib/jwt.php` + `lib/auth.php` + `handlers/auth.php` (register, login, me) + the throttle.
7. `lib/validator.php` + `handlers/users.php` (full CRUD).
8. `README.md` — Quickstart, Routing, cPanel deploy, Troubleshooting.

The README's Troubleshooting section is a symptom→cause table, because these are the failures and they are all non-obvious:

| Symptom | Cause |
|---|---|
| Every route 404s | `.htaccess` did not upload (hidden dotfile), or `AllowOverride None` |
| 401 with a valid token | Apache stripped the `Authorization` header — uncomment `CGIPassAuth On` |
| Whole site 500s right after upload | An unknown `.htaccess` directive (`CGIPassAuth` on Apache < 2.4.13, or `Options` under a locked-down `AllowOverride`) |
| Works locally, "class not found" in prod | Filename case — Linux is case-sensitive |
| `/users` returns something weird | MultiViews — needs `Options -MultiViews` |
| DB error mentions `LIMIT` | A `LIMIT` value bound as a string |
