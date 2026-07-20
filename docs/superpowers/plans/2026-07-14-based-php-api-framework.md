# BasedPHP API Framework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a zero-dependency, no-build-step PHP micro-framework for JSON APIs that deploys by dragging a folder onto cPanel/FTP.

**Architecture:** A single front controller (`index.php`) behind an `.htaccess` rewrite dispatches to an Express-style route table. Framework code lives in `lib/` (8 flat files, no namespaces, no autoloader — just explicit `require`s). App code lives in `models/` (plain functions writing explicit SQL) and `handlers/`. Auth is hand-rolled HS256 JWT. Every component is split into a **pure, unit-testable core** and a thin terminal shell that sends headers and exits.

**Tech Stack:** PHP 8.0+, PDO (MySQL in prod, SQLite in tests), Apache `.htaccess`. **No Composer, no third-party libraries, no build step.**

**Spec:** `docs/superpowers/specs/2026-07-14-based-php-api-framework-design.md`

## Global Constraints

Every task's requirements implicitly include these. Copied verbatim from the spec.

- **PHP 8.0 floor.** No `readonly`, no `enum`, no `never`, no `array_is_list` — those are 8.1 and are a *parse* error on an 8.0 host, not a graceful failure.
- **Zero dependencies.** No Composer, no `vendor/`, no build step, no CLI at deploy time.
- **`lib/` stays under 600 lines total.** This is the enforcement mechanism for "readable in one sitting." Weigh every addition against it.
- `declare(strict_types=1);` at the top of every PHP file.
- **No closing `?>` tag in any file.** One stray byte after it triggers "headers already sent," which silently no-ops every `header()` call.
- **No side effects at include time.** Every file except `index.php` may only *define* functions and classes — never echo, connect, or query. This is what makes a direct HTTP hit on a source file output nothing even if `.htaccess` is ignored.
- Every file in `lib/`, `models/`, `handlers/` starts with `defined('BASED') || exit;` after the `declare`.
- **Middleware fails closed:** it must return `true` to continue. The dispatcher halts on anything else.
- **Models write explicit SQL with enumerated column lists.** Never `SELECT *`. No query builder, no `$fillable`, no `$hidden`.
- Keep every route lowercase — Linux is case-sensitive and your laptop is not.
- `tests/` is dev-only and is **never uploaded to the host**.

---

### Task 1: Test harness + repo cleanup

Delete the broken scaffolding and stand up a zero-dependency test runner. Nothing else can be tested until this exists.

**Files:**
- Create: `tests/run.php`, `tests/schema.sqlite.sql`, `.editorconfig`
- Modify: `.gitignore`, `start.sh`
- Delete: `utils/database.php`, `utils/.gitkeep`, `api/user.php`, `layout/layout.php`, `common/header.php`, `common/footer.php`, `common/meta.php`, `static/index.php`, `static/css/index.php`, `static/js/index.php`, `lib/.gitkeep`

**Interfaces:**
- Consumes: nothing.
- Produces: `test(string $name, callable $fn): void`, `assert_eq($expected, $actual, string $msg = ''): void`, `assert_true($actual, string $msg = ''): void`, `assert_null($actual, string $msg = ''): void`, `assert_throws(callable $fn, string $msg = ''): void`. Test files are `tests/*_test.php` and are auto-discovered.

- [ ] **Step 1: Delete the dead scaffolding**

`utils/database.php` cannot run: it binds `:row_limit` for a `LIMIT` clause as a string while setting `ATTR_EMULATE_PREPARES => false`, which MySQL rejects (error 1210; and 1064 with emulation on). It also connects and queries at include time, uses MySQL-only `&&`, and has a trailing `?>`. The credentials in it are placeholders (`root`/`password`/`dbname`, confirmed in commit `7bd4efa`), so **no git-history rewrite is needed.**

```bash
git rm -r utils api layout common lib/.gitkeep \
         static/index.php static/css/index.php static/js/index.php
```

- [ ] **Step 2: Write `.gitignore`**

```
temp/
.env
.env.*
!.env.example
*.sqlite
*.sqlite-wal
*.sqlite-shm
*.sqlite-journal
*.bak
*.log
.DS_Store
.idea/
.vscode/
```

- [ ] **Step 3: Write `.editorconfig`**

```ini
root = true

[*]
indent_style = space
indent_size = 4
end_of_line = lf
charset = utf-8
insert_final_newline = true
trim_trailing_whitespace = true
```

- [ ] **Step 4: Fix `start.sh`**

Without the router-script argument the built-in server 404s every route *and* serves `/.env` in plaintext to anyone on the same network.

```bash
#!/bin/sh
php -S localhost:8080 index.php
```

- [ ] **Step 5: Write `tests/run.php`**

```php
<?php

declare(strict_types=1);

define('BASED', true);
define('BASE_PATH', dirname(__DIR__));

$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

function assert_eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new Exception(sprintf(
            "%s\n    expected: %s\n    actual:   %s",
            $msg !== '' ? $msg : 'assert_eq failed',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_true($actual, string $msg = ''): void
{
    assert_eq(true, $actual, $msg !== '' ? $msg : 'expected true');
}

function assert_null($actual, string $msg = ''): void
{
    assert_eq(null, $actual, $msg !== '' ? $msg : 'expected null');
}

function assert_throws(callable $fn, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        return;
    }
    throw new Exception($msg !== '' ? $msg : 'expected a throw, but none happened');
}

foreach (glob(__DIR__ . '/*_test.php') as $file) {
    require $file;
}

$pass = 0;
$fail = 0;

foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    try {
        $fn();
        $pass++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } catch (Throwable $e) {
        $fail++;
        echo "  \033[31m✗\033[0m {$name}\n";
        echo "    " . str_replace("\n", "\n    ", $e->getMessage()) . "\n";
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 6: Write `tests/schema.sqlite.sql`**

One schema file cannot serve both MySQL and SQLite (`AUTO_INCREMENT` vs `AUTOINCREMENT`). Don't pretend otherwise — this is the test-only SQLite twin of the MySQL `database.sql` built in Task 7.

```sql
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT NOT NULL,
  email         TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  token_version INTEGER NOT NULL DEFAULT 0,
  status        TEXT NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  identifier   TEXT NOT NULL,
  ip           TEXT NOT NULL,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_identifier_time ON login_attempts (identifier, attempted_at);
CREATE INDEX IF NOT EXISTS idx_ip_time ON login_attempts (ip, attempted_at);
```

- [ ] **Step 7: Prove the harness works — write a self-test**

Create `tests/harness_test.php`:

```php
<?php

declare(strict_types=1);

test('assert_eq passes on identical values', function (): void {
    assert_eq(1, 1);
});

test('assert_eq is strict about type', function (): void {
    assert_throws(function (): void {
        assert_eq(1, '1');
    }, 'assert_eq must not treat 1 and "1" as equal');
});

test('assert_throws fails when nothing throws', function (): void {
    $caught = false;
    try {
        assert_throws(function (): void {
            // does not throw
        });
    } catch (Throwable $e) {
        $caught = true;
    }
    assert_true($caught, 'assert_throws should complain when the callable does not throw');
});
```

- [ ] **Step 8: Run the tests**

Run: `php tests/run.php`
Expected: `3 passed, 0 failed`, exit code 0.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "chore: remove broken scaffolding, add zero-dep test harness

utils/database.php could not run: it bound a LIMIT value as a string while
setting EMULATE_PREPARES=false, which MySQL rejects. Credentials in it were
placeholders, so no history rewrite is needed."
```

---

### Task 2: `lib/env.php` — config loader

**Files:**
- Create: `lib/env.php`, `tests/env_test.php`, `.env.example`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Env::load(string $path): void`
  - `Env::get(string $key, ?string $default = null): ?string`
  - `Env::must(string $key, int $minLen = 1): string` — throws `RuntimeException` if missing or too short
  - `env(string $key, ?string $default = null): ?string` — global shorthand for `Env::get`

- [ ] **Step 1: Write the failing test**

Create `tests/env_test.php`:

```php
<?php

declare(strict_types=1);

require_once BASE_PATH . '/lib/env.php';

function env_fixture(string $contents): string
{
    $path = sys_get_temp_dir() . '/based_env_' . bin2hex(random_bytes(6));
    file_put_contents($path, $contents);
    return $path;
}

test('parses simple key=value pairs', function (): void {
    Env::load(env_fixture("APP_ENV=production\nDB_HOST=localhost\n"));
    assert_eq('production', Env::get('APP_ENV'));
    assert_eq('localhost', Env::get('DB_HOST'));
});

test('returns the default when a key is absent', function (): void {
    Env::load(env_fixture(''));
    assert_eq('fallback', Env::get('NOPE', 'fallback'));
    assert_null(Env::get('NOPE'));
});

test('ignores comments and blank lines', function (): void {
    Env::load(env_fixture("# a comment\n\nA=1\n"));
    assert_eq('1', Env::get('A'));
    assert_null(Env::get('#'));
});

// parse_ini_file() treats # ! $ & | ? { } as reserved and mangles real passwords.
// This is the entire reason the parser is hand-rolled.
test('preserves special characters in values', function (): void {
    Env::load(env_fixture('DB_PASS=Kf!8$m#2&x|y?z{}' . "\n"));
    assert_eq('Kf!8$m#2&x|y?z{}', Env::get('DB_PASS'));
});

test('keeps an equals sign inside the value', function (): void {
    Env::load(env_fixture("SECRET=abc=def=\n"));
    assert_eq('abc=def=', Env::get('SECRET'));
});

// cPanel's web editor and Windows uploads append \r. A JWT_SECRET with a
// trailing \r produces HMACs that silently do not match your laptop's.
test('strips carriage returns and a UTF-8 BOM', function (): void {
    Env::load(env_fixture("\xEF\xBB\xBFA=1\r\nB=2\r\n"));
    assert_eq('1', Env::get('A'));
    assert_eq('2', Env::get('B'));
});

test('strips surrounding quotes', function (): void {
    Env::load(env_fixture("A=\"quoted\"\nB='single'\n"));
    assert_eq('quoted', Env::get('A'));
    assert_eq('single', Env::get('B'));
});

test('a missing file loads as empty rather than throwing', function (): void {
    Env::load('/definitely/not/a/real/path/.env');
    assert_null(Env::get('ANYTHING'));
});

// A blank JWT_SECRET does not throw on its own: hash_hmac(..., '') returns a
// perfectly valid signature that verifies against itself. Auth would appear to
// work while anyone could forge tokens. This guard is the only thing stopping that.
test('must() throws when the key is missing', function (): void {
    Env::load(env_fixture(''));
    assert_throws(function (): void {
        Env::must('JWT_SECRET', 32);
    });
});

test('must() throws when the value is shorter than the minimum', function (): void {
    Env::load(env_fixture("JWT_SECRET=tooshort\n"));
    assert_throws(function (): void {
        Env::must('JWT_SECRET', 32);
    });
});

test('must() returns a value that meets the minimum length', function (): void {
    $secret = str_repeat('a', 32);
    Env::load(env_fixture("JWT_SECRET={$secret}\n"));
    assert_eq($secret, Env::must('JWT_SECRET', 32));
});

test('env() shorthand reads the same store', function (): void {
    Env::load(env_fixture("A=1\n"));
    assert_eq('1', env('A'));
    assert_eq('z', env('MISSING', 'z'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Class "Env" not found`.

- [ ] **Step 3: Write `lib/env.php`**

```php
<?php

declare(strict_types=1);

defined('BASED') || exit;

final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];

    public static function load(string $path): void
    {
        self::$vars = [];

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $i => $line) {
            if ($i === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            }

            // trim() strips \r, so CRLF files are handled here.
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$vars[$key] ?? $default;
    }

    public static function must(string $key, int $minLen = 1): string
    {
        $value = self::$vars[$key] ?? '';

        if (strlen($value) < $minLen) {
            throw new RuntimeException(
                "Config error: {$key} is missing from .env or is shorter than {$minLen} characters."
            );
        }

        return $value;
    }
}

function env(string $key, ?string $default = null): ?string
{
    return Env::get($key, $default);
}
```

Note the values live in a private static array. **Never `putenv()` or `$_ENV`** — `putenv()` exposes secrets to subprocesses and `phpinfo()`, and many hosts set `variables_order` without `E`, so `$_ENV` is simply empty.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all env tests PASS.

- [ ] **Step 5: Write `.env.example`**

```
# Copy to .env and fill in. .env is gitignored and must NEVER be committed.
# Your FTP client hides dotfiles by default — enable "show hidden files"
# or .env and .htaccess will silently fail to upload.

APP_ENV=production
APP_DEBUG=false

# cPanel prefixes the DB name and user with your account name, e.g. acct_appdb.
# The host is almost always localhost.
DB_HOST=localhost
DB_PORT=3306
DB_NAME=
DB_USER=
DB_PASS=

# Overrides DB_* entirely if set. This is the whole SQLite story.
# A .sqlite file inside public_html is your entire database over HTTP —
# if you use it, put it OUTSIDE the web root.
# DB_DSN=sqlite:/home/acct/data/app.sqlite

# Must be at least 32 characters. The app refuses to boot without it.
# Generate one:  php -r "echo bin2hex(random_bytes(32));"
JWT_SECRET=
JWT_TTL=86400

# Comma-separated. Empty means no cross-origin requests are allowed.
CORS_ORIGINS=
```

- [ ] **Step 6: Commit**

```bash
git add lib/env.php tests/env_test.php .env.example
git commit -m "feat: add .env loader with fail-closed must()

Hand-rolled parser, not parse_ini_file() — the latter treats # ! \$ & | ? { }
as reserved and mangles real passwords."
```

---

### Task 3: `lib/response.php` — responses, errors, CORS

**Files:**
- Create: `lib/response.php`, `tests/response_test.php`

**Interfaces:**
- Consumes: `env()` (Task 2), `Request::method()` / `Request::header()` (Task 4 — used only inside `Response::cors()`, which is not unit-tested).
- Produces:
  - `HttpException::__construct(int $status, string $message, array $details = [])`, `->status(): int`, `->details(): array`
  - `Response::encode($data): string`
  - `Response::errorBody(string $message, array $details = []): array`
  - `Response::securityHeaders(): array` — list of header strings
  - `Response::corsHeadersFor(?string $origin, array $allowed): array`
  - `Response::json($data, int $status = 200): void` — **terminal, exits**
  - `Response::error(string $message, int $status = 400, array $details = []): void` — **terminal, exits**
  - `Response::noContent(): void` — **terminal, exits**
  - `Response::html(string $view, array $data = []): void` — **terminal, exits**
  - `Response::cors(): void` — sends CORS headers; exits on `OPTIONS`

The pure functions (`encode`, `errorBody`, `securityHeaders`, `corsHeadersFor`) exist so the logic is unit-testable. The terminal functions call `exit`, so they cannot be tested in-process — they get curl smoke tests in Task 6.

- [ ] **Step 1: Write the failing test**

Create `tests/response_test.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Class "Response" not found`.

- [ ] **Step 3: Write `lib/response.php`**

```php
<?php

declare(strict_types=1);

defined('BASED') || exit;

final class HttpException extends RuntimeException
{
    /** @var array<string,mixed> */
    private array $details;

    public function __construct(int $status, string $message, array $details = [])
    {
        parent::__construct($message, $status);
        $this->details = $details;
    }

    public function status(): int
    {
        $code = (int) $this->getCode();

        return $code >= 400 && $code <= 599 ? $code : 500;
    }

    public function details(): array
    {
        return $this->details;
    }
}

final class Response
{
    public static function encode($data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    public static function errorBody(string $message, array $details = []): array
    {
        $body = ['error' => $message];

        if ($details !== []) {
            $body['details'] = $details;
        }

        return $body;
    }

    /** @return string[] */
    public static function securityHeaders(): array
    {
        return [
            'Content-Type: application/json; charset=utf-8',
            'X-Content-Type-Options: nosniff',
            'Cache-Control: no-store',
            'Referrer-Policy: no-referrer',
            'Strict-Transport-Security: max-age=31536000',
        ];
    }

    /** @return string[] */
    public static function corsHeadersFor(?string $origin, array $allowed): array
    {
        $headers = ['Vary: Origin'];

        if ($origin !== null && $origin !== '' && in_array($origin, $allowed, true)) {
            $headers[] = 'Access-Control-Allow-Origin: ' . $origin;
            $headers[] = 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS';
            $headers[] = 'Access-Control-Allow-Headers: Authorization, Content-Type';
            $headers[] = 'Access-Control-Max-Age: 86400';
        }

        return $headers;
    }

    public static function json($data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);

            foreach (self::securityHeaders() as $header) {
                header($header);
            }
        }

        if ($status !== 204 && $status !== 304) {
            echo self::encode($data);
        }

        exit;
    }

    public static function error(string $message, int $status = 400, array $details = []): void
    {
        self::json(self::errorBody($message, $details), $status);
    }

    public static function noContent(): void
    {
        self::json(null, 204);
    }

    public static function html(string $view, array $data = []): void
    {
        $path = BASE_PATH . '/views/' . basename($view) . '.php';

        if (!is_file($path)) {
            throw new HttpException(500, 'View not found');
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        extract($data, EXTR_SKIP);
        require $path;

        exit;
    }

    public static function cors(): void
    {
        $raw = (string) env('CORS_ORIGINS', '');
        $allowed = array_values(array_filter(array_map('trim', explode(',', $raw))));

        foreach (self::corsHeadersFor(Request::header('Origin'), $allowed) as $header) {
            header($header, false);
        }

        // Preflights carry no Authorization header and no body. If CORS ran after
        // auth or routing, every cross-origin POST from a browser would fail.
        if (Request::method() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
```

`Response::html()` uses `basename()` so a user-controlled view name cannot traverse to `../../.env`. Escaping in views is the view's job — use `htmlspecialchars()`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all response tests PASS.

- [ ] **Step 5: Commit**

```bash
git add lib/response.php tests/response_test.php
git commit -m "feat: add JSON response, error envelope, and CORS helpers

Cache-Control: no-store is unconditional — cPanel commonly sits behind
LiteSpeed/Cloudflare, and an uncached login response leaks the bearer token
to the next visitor. No Allow-Credentials: the design is bearer-only."
```

---

### Task 4: `lib/request.php` — request parsing and path resolution

**Files:**
- Create: `lib/request.php`, `tests/request_test.php`

**Interfaces:**
- Consumes: `HttpException` (Task 3).
- Produces:
  - `Request::normalizePath(string $rawPath, string $scriptName): string` — **pure**
  - `Request::method(): string`
  - `Request::path(): string`
  - `Request::query(string $key, ?string $default = null): ?string`
  - `Request::body(): array`
  - `Request::header(string $name): ?string`
  - `Request::bearerToken(): ?string`
  - `Request::ip(): string`

- [ ] **Step 1: Write the failing test**

Create `tests/request_test.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Class "Request" not found`.

- [ ] **Step 3: Write `lib/request.php`**

```php
<?php

declare(strict_types=1);

defined('BASED') || exit;

final class Request
{
    private const MAX_BODY_BYTES = 1048576; // 1MB

    private static ?array $body = null;

    public static function normalizePath(string $rawPath, string $scriptName): string
    {
        $path = strtok($rawPath, '?');

        if ($path === false || $path === '') {
            $path = '/';
        }

        $path = rawurldecode($path);

        if (str_contains($path, "\0")) {
            throw new HttpException(400, 'Bad request');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw new HttpException(400, 'Bad request');
            }
        }

        $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($base !== '' && $base !== '.' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }

        return '/' . trim($path, '/');
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function path(): string
    {
        // PATH_INFO first: this IS the no-mod_rewrite fallback.
        $raw = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '/';

        return self::normalizePath((string) $raw, (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        $value = $_GET[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (preg_match('/^Bearer\s+(\S+)$/i', (string) $header, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public static function ip(): string
    {
        // X-Forwarded-For is client-controlled and spoofable, which would defeat
        // any rate limiting keyed on it. Only REMOTE_ADDR is trusted.
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }

        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($length > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Request body too large');
        }

        $type = strtolower((string) self::header('Content-Type'));

        if (str_contains($type, 'multipart/form-data')) {
            return self::$body = $_POST;
        }

        $raw = (string) file_get_contents('php://input');

        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Request body too large');
        }

        if ($raw === '') {
            return self::$body = [];
        }

        if (str_contains($type, 'json')) {
            try {
                $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                // A body you cannot parse is 400, not 422.
                throw new HttpException(400, 'Malformed JSON body');
            }

            return self::$body = is_array($decoded) ? $decoded : [];
        }

        // PHP does not populate $_POST for PUT/PATCH, even with a form content-type.
        $parsed = [];
        parse_str($raw, $parsed);

        return self::$body = $parsed;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all request tests PASS.

- [ ] **Step 5: Commit**

```bash
git add lib/request.php tests/request_test.php
git commit -m "feat: add request parsing with unified path resolution

Both the REQUEST_URI and PATH_INFO branches go through the same normalizer,
so a route cannot behave differently on the host than locally. Bearer token
reads REDIRECT_HTTP_AUTHORIZATION too — Apache CGI strips the real header."
```

---

### Task 5: `lib/router.php` — the route table

**Files:**
- Create: `lib/router.php`, `tests/router_test.php`

**Interfaces:**
- Consumes: `Response` (Task 3).
- Produces:
  - `Router::compile(string $pattern): array` — returns `[string $regex, string[] $keys]`. **Pure.**
  - `Router::add(string $method, string $path, callable $handler, array $middleware = []): void`
  - `Router::get|post|put|patch|delete(string $path, callable $handler, array $middleware = []): void`
  - `Router::match(string $method, string $path): ?array` — returns `['handler' => callable, 'middleware' => callable[], 'params' => array<string,string>]` or `null`
  - `Router::allowedMethods(string $path): string[]`
  - `Router::dispatch(string $method, string $path): void`
  - `Router::reset(): void` — clears the table (used by tests)

Handler signature: `function (array $params): void`. Middleware signature: `function (array $params): bool`.

- [ ] **Step 1: Write the failing test**

Create `tests/router_test.php`:

```php
<?php

declare(strict_types=1);

require_once BASE_PATH . '/lib/response.php';
require_once BASE_PATH . '/lib/router.php';

$noop = function (array $params): void {
};

test('compiles a static pattern', function () use ($noop): void {
    [$regex, $keys] = Router::compile('/users');
    assert_eq([], $keys);
    assert_eq(1, preg_match($regex, '/users'));
    assert_eq(0, preg_match($regex, '/users/1'));
});

test('compiles a :param pattern and names the key', function (): void {
    [$regex, $keys] = Router::compile('/users/:id');
    assert_eq(['id'], $keys);
    assert_eq(1, preg_match($regex, '/users/42'));
    assert_eq(0, preg_match($regex, '/users'));
});

// A :param must not swallow a slash, or /users/:id would match /users/1/posts.
test('a :param does not span a slash', function (): void {
    [$regex] = Router::compile('/users/:id');
    assert_eq(0, preg_match($regex, '/users/1/posts'));
});

test('compiles the root pattern', function (): void {
    [$regex] = Router::compile('/');
    assert_eq(1, preg_match($regex, '/'));
});

// preg_quote escapes ':', which quietly breaks the naive
// "quote the pattern then swap :name" approach. Segment-wise compilation avoids it.
test('escapes regex metacharacters in literal segments', function (): void {
    [$regex] = Router::compile('/a.b');
    assert_eq(1, preg_match($regex, '/a.b'));
    assert_eq(0, preg_match($regex, '/axb'));
});

test('matches and extracts params', function () use ($noop): void {
    Router::reset();
    Router::get('/users/:id', $noop);
    $route = Router::match('GET', '/users/42');
    assert_eq(['id' => '42'], $route['params']);
});

test('extracts multiple params', function () use ($noop): void {
    Router::reset();
    Router::get('/users/:uid/posts/:pid', $noop);
    $route = Router::match('GET', '/users/7/posts/9');
    assert_eq(['uid' => '7', 'pid' => '9'], $route['params']);
});

test('returns null when nothing matches', function () use ($noop): void {
    Router::reset();
    Router::get('/users', $noop);
    assert_null(Router::match('GET', '/nope'));
});

test('does not match a different method', function () use ($noop): void {
    Router::reset();
    Router::get('/users', $noop);
    assert_null(Router::match('POST', '/users'));
});

// Route order is match order: /users/:id registered first would swallow "me" as an id.
test('first match wins, so static segments must be registered first', function () use ($noop): void {
    Router::reset();
    Router::get('/users/me', $noop);
    Router::get('/users/:id', $noop);
    assert_eq([], Router::match('GET', '/users/me')['params']);
    assert_eq(['id' => '9'], Router::match('GET', '/users/9')['params']);
});

test('allowedMethods reports the verbs registered for a path', function () use ($noop): void {
    Router::reset();
    Router::get('/users', $noop);
    Router::post('/users', $noop);
    $allowed = Router::allowedMethods('/users');
    sort($allowed);
    assert_eq(['GET', 'POST'], $allowed);
});

test('allowedMethods is empty for an unknown path', function () use ($noop): void {
    Router::reset();
    Router::get('/users', $noop);
    assert_eq([], Router::allowedMethods('/nope'));
});

test('HEAD folds into GET', function () use ($noop): void {
    Router::reset();
    Router::get('/users', $noop);
    assert_true(Router::match('GET', '/users') !== null);
    assert_eq(['GET'], Router::allowedMethods('/users'));
});

// THE fail-closed test. A middleware that rejects a request by returning null
// (the natural way to write `function (array $p): void`) must NOT let the
// handler run. The tempting `=== false` check is fail-OPEN, because
// null === false is false.
test('middleware returning null blocks the handler', function (): void {
    Router::reset();
    $ran = false;
    Router::get('/x', function (array $params) use (&$ran): void {
        $ran = true;
    }, [
        function (array $params) {
            return null;
        },
    ]);
    Router::dispatch('GET', '/x');
    assert_eq(false, $ran, 'handler must NOT run when middleware does not return true');
});

test('middleware returning false blocks the handler', function (): void {
    Router::reset();
    $ran = false;
    Router::get('/x', function (array $params) use (&$ran): void {
        $ran = true;
    }, [
        function (array $params): bool {
            return false;
        },
    ]);
    Router::dispatch('GET', '/x');
    assert_eq(false, $ran);
});

test('middleware returning true lets the handler run', function (): void {
    Router::reset();
    $ran = false;
    Router::get('/x', function (array $params) use (&$ran): void {
        $ran = true;
    }, [
        function (array $params): bool {
            return true;
        },
    ]);
    Router::dispatch('GET', '/x');
    assert_true($ran);
});

test('the handler receives the route params', function (): void {
    Router::reset();
    $seen = null;
    Router::get('/users/:id', function (array $params) use (&$seen): void {
        $seen = $params;
    });
    Router::dispatch('GET', '/users/5');
    assert_eq(['id' => '5'], $seen);
});

test('all middleware must pass for the handler to run', function (): void {
    Router::reset();
    $ran = false;
    Router::get('/x', function (array $params) use (&$ran): void {
        $ran = true;
    }, [
        function (array $params): bool {
            return true;
        },
        function (array $params): bool {
            return false;
        },
    ]);
    Router::dispatch('GET', '/x');
    assert_eq(false, $ran);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Class "Router" not found`.

- [ ] **Step 3: Write `lib/router.php`**

```php
<?php

declare(strict_types=1);

defined('BASED') || exit;

final class Router
{
    /** @var array<string,array<int,array{regex:string,keys:string[],handler:callable,middleware:array}>> */
    private static array $routes = [];

    /** @return array{0:string,1:string[]} */
    public static function compile(string $pattern): array
    {
        $keys = [];
        $regex = '';

        foreach (explode('/', trim($pattern, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment[0] === ':') {
                $keys[] = substr($segment, 1);
                $regex .= '/([^/]+)';
            } else {
                $regex .= '/' . preg_quote($segment, '#');
            }
        }

        if ($regex === '') {
            $regex = '/';
        }

        return ['#^' . $regex . '$#', $keys];
    }

    public static function add(string $method, string $path, callable $handler, array $middleware = []): void
    {
        [$regex, $keys] = self::compile($path);

        self::$routes[strtoupper($method)][] = [
            'regex' => $regex,
            'keys' => $keys,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public static function get(string $path, callable $handler, array $middleware = []): void
    {
        self::add('GET', $path, $handler, $middleware);
    }

    public static function post(string $path, callable $handler, array $middleware = []): void
    {
        self::add('POST', $path, $handler, $middleware);
    }

    public static function put(string $path, callable $handler, array $middleware = []): void
    {
        self::add('PUT', $path, $handler, $middleware);
    }

    public static function patch(string $path, callable $handler, array $middleware = []): void
    {
        self::add('PATCH', $path, $handler, $middleware);
    }

    public static function delete(string $path, callable $handler, array $middleware = []): void
    {
        self::add('DELETE', $path, $handler, $middleware);
    }

    public static function match(string $method, string $path): ?array
    {
        foreach (self::$routes[strtoupper($method)] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $m) === 1) {
                $params = [];

                foreach ($route['keys'] as $i => $key) {
                    $params[$key] = $m[$i + 1];
                }

                return [
                    'handler' => $route['handler'],
                    'middleware' => $route['middleware'],
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    /** @return string[] */
    public static function allowedMethods(string $path): array
    {
        $allowed = [];

        foreach (self::$routes as $method => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        return $allowed;
    }

    public static function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);

        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $route = self::match($method, $path);

        if ($route === null) {
            $allowed = self::allowedMethods($path);

            if ($allowed !== []) {
                header('Allow: ' . implode(', ', $allowed));
                Response::error('Method not allowed', 405);
            }

            Response::error('Not found', 404);
        }

        // FAIL CLOSED. Middleware must return true to continue.
        // `=== false` would be fail-open: a void middleware returns null,
        // and null === false is false, so the short-circuit would never trip.
        foreach ($route['middleware'] as $middleware) {
            if ($middleware($route['params']) !== true) {
                return;
            }
        }

        ($route['handler'])($route['params']);
    }

    public static function reset(): void
    {
        self::$routes = [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all router tests PASS — in particular *"middleware returning null blocks the handler"*.

- [ ] **Step 5: Commit**

```bash
git add lib/router.php tests/router_test.php
git commit -m "feat: add router with fail-closed middleware contract

Middleware must return true to continue. The tempting `=== false` check is
fail-open: a void middleware returns null, and null === false is false, so
auth would never short-circuit and the handler would run unauthenticated."
```

---

### Task 6: Front controller, `.htaccess`, `.user.ini` — first deployable milestone

This is the task that surfaces every shared-hosting failure. It ends with a working `GET /health` and a curl smoke suite.

**Files:**
- Create: `index.php`, `routes.php`, `.htaccess`, `.user.ini`, `handlers/health.php`, `tests/smoke.sh`

**Interfaces:**
- Consumes: `Env`, `Response`, `HttpException`, `Request`, `Router` (Tasks 2–5).
- Produces: `handle_health(array $params): void`. Defines constants `BASED` and `BASE_PATH`.

- [ ] **Step 1: Write `.htaccess`**

The single highest-leverage file in the repo. **The critical line is the one that is absent: `RewriteCond %{REQUEST_FILENAME} !-f`.** That rule — which nearly every PHP front-controller tutorial teaches — means "if the file exists on disk, hand it over." `.env` exists on disk, and Apache has no handler for it, so `GET /.env` would return your JWT secret and DB password as `text/plain`.

```apache
DirectoryIndex index.php

# -MultiViews is NOT optional: with it on (the EA4 default), GET /users
# content-negotiates straight to a stray users.php and never reaches the router.
Options -Indexes -MultiViews

<IfModule mod_rewrite.c>
  RewriteEngine On
  # No RewriteBase. A relative substitution works at the document root AND in a
  # subfolder (public_html/myapi/), which is the normal cPanel case.

  # HTTPS by default. A bearer token over plain HTTP is a 24h password in the clear.
  # cPanel AutoSSL gives free Let's Encrypt. Comment out for local plain-HTTP.
  RewriteCond %{HTTPS} !=on
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

  # CGI/FastCGI strips the Authorization header, so every JWT request 401s on the
  # host while working locally. Put it back.
  RewriteCond %{HTTP:Authorization} .
  RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

  # Real assets only. Deliberately NOT a blanket -f check.
  RewriteRule ^static/ - [L]

  # Everything else is the app. The original query string carries over for free:
  # never write `index.php?url=$1`, and never add [QSA].
  RewriteRule ^ index.php [L]
</IfModule>

# Second layer, for hosts where AllowOverride disables mod_rewrite.
# Dual syntax: a bare `Require all denied` is a hard 500 on Apache 2.2.
<FilesMatch "(^\.|\.(env|sql|sh|md|log|bak|ini)$)">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</FilesMatch>

# If auth 401s on your host, uncomment the line below.
# Do NOT ship it enabled: CGIPassAuth only exists in Apache 2.4.13+, and an
# unknown directive is a hard 500 on the WHOLE SITE the moment you upload.
# CGIPassAuth On

# NEVER add php_value or php_flag here. They 500 under cPanel's PHP-FPM/CGI.
# Use .user.ini instead.
```

- [ ] **Step 2: Write `.user.ini`**

`php_flag`/`php_value` in `.htaccess` 500s under cPanel's PHP-FPM/CGI, so this is the *only* mechanism that works. It also applies at compile time, which means it suppresses parse-error source leaks that no PHP-level error handler can ever reach.

```ini
display_errors = 0
log_errors = 1
expose_php = 0
zend.exception_ignore_args = 1
```

- [ ] **Step 3: Write `handlers/health.php`**

A route that touches nothing — no DB, no auth. It is the single most useful thing on a first cPanel deploy: it isolates "is the rewrite working" from "is the DB configured" from "is the Authorization header surviving."

```php
<?php

declare(strict_types=1);

defined('BASED') || exit;

function handle_health(array $params): void
{
    Response::json([
        'status' => 'ok',
        'php' => PHP_VERSION,
    ]);
}
```

- [ ] **Step 4: Write `routes.php`**

This file is the entire API surface at a glance — it *is* the documentation. Static segments are registered before `:param` ones, always.

```php
<?php

declare(strict_types=1);

defined('BASED') || exit;

Router::get('/health', 'handle_health');
```

- [ ] **Step 5: Write `index.php`**

```php
<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('BasedPHP requires PHP 8.0 or newer.');
}

// `php -S localhost:8080 index.php` — let the dev server serve real static files.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

define('BASED', true);
define('BASE_PATH', __DIR__);

require __DIR__ . '/lib/env.php';
require __DIR__ . '/lib/response.php';
require __DIR__ . '/lib/request.php';
require __DIR__ . '/lib/router.php';
require __DIR__ . '/handlers/health.php';

Env::load(BASE_PATH . '/.env');

error_reporting(E_ALL);
ini_set('display_errors', '0');

// Registered before anything else can throw.
set_exception_handler(function (Throwable $e): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($e instanceof HttpException) {
        Response::error($e->getMessage(), $e->status(), $e->details());
    }

    error_log(sprintf(
        '[based] %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    // PDOException::getMessage() embeds the DSN and username. Never leak it.
    $debug = env('APP_DEBUG') === 'true';

    Response::error($debug ? $e->getMessage() : 'Internal server error', 500);
});

// Without this, a fatal leaks a blank 200 or an HTML error page into your JSON API.
register_shutdown_function(function (): void {
    $error = error_get_last();

    if ($error === null) {
        return;
    }

    if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    error_log(sprintf('[based] fatal: %s in %s:%d', $error['message'], $error['file'], $error['line']));

    Response::error('Internal server error', 500);
});

Response::cors();

require __DIR__ . '/routes.php';

Router::dispatch(Request::method(), Request::path());
```

- [ ] **Step 6: Write `tests/smoke.sh`**

Terminal functions (`Response::json` and friends) call `exit`, so they cannot be unit-tested in-process. This is how they get covered. It is also the **post-deploy security checklist** — point `BASE` at the real host after deploying.

```bash
#!/bin/sh
# Usage:  sh tests/smoke.sh [base-url]
# Local:  sh tests/smoke.sh
# Host:   sh tests/smoke.sh https://yoursite.com
set -e

BASE="${1:-http://localhost:8080}"
FAIL=0

check() {
    desc="$1"; expected="$2"; actual="$3"
    if [ "$expected" = "$actual" ]; then
        printf '  \033[32m✓\033[0m %s\n' "$desc"
    else
        printf '  \033[31m✗\033[0m %s (expected %s, got %s)\n' "$desc" "$expected" "$actual"
        FAIL=1
    fi
}

status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "Smoke testing ${BASE}"

check "GET /health is 200 (the rewrite works)" 200 "$(status "${BASE}/health")"
check "unknown route is 404" 404 "$(status "${BASE}/nope")"
check "wrong method is 405" 405 "$(status -X POST "${BASE}/health")"

# The security checklist. On a drag-onto-FTP deploy with no CI, THIS is the test suite.
check ".env is not served" 404 "$(status "${BASE}/.env")"
check "database.sql is not served" 404 "$(status "${BASE}/database.sql")"
check "lib/ source is not served" 404 "$(status "${BASE}/lib/db.php")"

if curl -s "${BASE}/health" | grep -qi 'hash\|password'; then
    printf '  \033[31m✗\033[0m /health leaks a secret-looking field\n'
    FAIL=1
else
    printf '  \033[32m✓\033[0m /health leaks no secret-looking field\n'
fi

[ "$FAIL" -eq 0 ] && echo "\nsmoke: all passed" || { echo "\nsmoke: FAILURES"; exit 1; }
```

Note: locally `.env` returns 404 because `php -S` routes everything through `index.php`. On the real host it should be 403 or 404 — **if it returns the file contents, stop and rotate the secret.**

- [ ] **Step 7: Run the smoke tests**

```bash
php -S localhost:8080 index.php &
sleep 1
sh tests/smoke.sh
kill %1
```

Expected: `smoke: all passed`.

- [ ] **Step 8: Run the unit tests to confirm nothing regressed**

Run: `php tests/run.php`
Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add index.php routes.php .htaccess .user.ini handlers/health.php tests/smoke.sh
git commit -m "feat: add front controller, .htaccess, .user.ini, GET /health

The .htaccess deliberately omits `RewriteCond %{REQUEST_FILENAME} !-f`: that
rule serves .env (JWT secret + DB password) as plaintext. CGIPassAuth ships
commented — it is a hard 500 on Apache < 2.4.13."
```

---

### Task 7: `lib/db.php`, `database.sql`, `models/user.php`

**Files:**
- Create: `lib/db.php`, `database.sql`, `models/user.php`, `tests/db_test.php`, `tests/user_model_test.php`

**Interfaces:**
- Consumes: `Env`, `env()`, `HttpException`.
- Produces:
  - `DB::pdo(): PDO`, `DB::run(string $sql, array $params = []): PDOStatement`, `DB::all(string $sql, array $params = []): array`, `DB::first(string $sql, array $params = []): ?array`, `DB::insert(string $sql, array $params = []): int`, `DB::tx(callable $fn)`
  - `user_find(int $id): ?array` — **never returns `password_hash`**
  - `user_all(int $limit = 50, int $offset = 0): array`
  - `user_find_auth_record(string $email): ?array` — the **only** function returning `password_hash`
  - `user_find_auth_state(int $id): ?array` — returns `id`, `token_version`, `status`
  - `user_create(string $name, string $email, string $password): int`
  - `user_update(int $id, string $name, string $email): int`
  - `user_delete(int $id): int`
  - `user_email_exists(string $email): bool`

- [ ] **Step 1: Write the failing test**

Create `tests/db_test.php`:

```php
<?php

declare(strict_types=1);

require_once BASE_PATH . '/lib/env.php';
require_once BASE_PATH . '/lib/response.php';
require_once BASE_PATH . '/lib/db.php';

function db_boot(): void
{
    static $booted = false;

    if ($booted) {
        DB::run('DELETE FROM users');
        DB::run('DELETE FROM login_attempts');

        return;
    }

    $path = sys_get_temp_dir() . '/based_test_env_' . bin2hex(random_bytes(6));
    file_put_contents($path, "DB_DSN=sqlite::memory:\nJWT_SECRET=" . str_repeat('k', 32) . "\nJWT_TTL=3600\n");
    Env::load($path);

    foreach (explode(';', (string) file_get_contents(BASE_PATH . '/tests/schema.sqlite.sql')) as $stmt) {
        if (trim($stmt) !== '') {
            DB::pdo()->exec($stmt);
        }
    }

    $booted = true;
}

test('run + first round-trips a row', function (): void {
    db_boot();
    DB::run("INSERT INTO users (name, email, password_hash) VALUES ('A', 'a@t.co', 'h')");
    $row = DB::first('SELECT name, email FROM users WHERE email = :e', ['e' => 'a@t.co']);
    assert_eq(['name' => 'A', 'email' => 'a@t.co'], $row);
});

test('first returns null when there is no row', function (): void {
    db_boot();
    assert_null(DB::first('SELECT id FROM users WHERE email = :e', ['e' => 'ghost@t.co']));
});

test('insert returns the new id as an int, not a string', function (): void {
    db_boot();
    $id = DB::insert('INSERT INTO users (name, email, password_hash) VALUES (:n, :e, :p)', [
        'n' => 'B', 'e' => 'b@t.co', 'p' => 'h',
    ]);
    assert_true(is_int($id), 'lastInsertId() returns a string; it must be cast, or the API emits "id": "7"');
    assert_true($id > 0);
});

// THE bug in the old utils/database.php. It bound :row_limit as a string while
// setting EMULATE_PREPARES=false, so MySQL rejected `LIMIT '10'` (error 1210).
// The typed bindValue loop is what fixes it permanently, everywhere.
test('an int LIMIT binds correctly under native prepares', function (): void {
    db_boot();
    DB::run("INSERT INTO users (name, email, password_hash) VALUES ('A', 'a@t.co', 'h')");
    DB::run("INSERT INTO users (name, email, password_hash) VALUES ('B', 'b@t.co', 'h')");
    DB::run("INSERT INTO users (name, email, password_hash) VALUES ('C', 'c@t.co', 'h')");
    $rows = DB::all('SELECT id FROM users ORDER BY id LIMIT :n', ['n' => 2]);
    assert_eq(2, count($rows));
});

test('an int OFFSET binds correctly too', function (): void {
    db_boot();
    DB::run("INSERT INTO users (name, email, password_hash) VALUES ('A', 'a@t.co', 'h')");
    DB::run("INSERT INTO users (name, email, password_hash) VALUES ('B', 'b@t.co', 'h')");
    $rows = DB::all('SELECT name FROM users ORDER BY id LIMIT :n OFFSET :o', ['n' => 5, 'o' => 1]);
    assert_eq(1, count($rows));
    assert_eq('B', $rows[0]['name']);
});

// `?email[]=a` makes $v an array. (string) $array emits a warning that a strict
// handler turns into a 500. The guard makes the raw-SQL escape hatch honestly
// as safe as it claims to be.
test('rejects a non-scalar bind value', function (): void {
    db_boot();
    assert_throws(function (): void {
        DB::first('SELECT id FROM users WHERE email = :e', ['e' => ['array']]);
    });
});

test('binds null correctly', function (): void {
    db_boot();
    $row = DB::first('SELECT :v AS v', ['v' => null]);
    assert_null($row['v']);
});

test('a bound value cannot inject SQL', function (): void {
    db_boot();
    DB::run("INSERT INTO users (name, email, password_hash) VALUES ('A', 'a@t.co', 'h')");
    $rows = DB::all('SELECT id FROM users WHERE email = :e', ['e' => "' OR '1'='1"]);
    assert_eq(0, count($rows));
});

test('tx commits on success', function (): void {
    db_boot();
    DB::tx(function (): void {
        DB::run("INSERT INTO users (name, email, password_hash) VALUES ('T', 't@t.co', 'h')");
    });
    assert_true(DB::first('SELECT id FROM users WHERE email = :e', ['e' => 't@t.co']) !== null);
});

test('tx rolls back on throw', function (): void {
    db_boot();
    assert_throws(function (): void {
        DB::tx(function (): void {
            DB::run("INSERT INTO users (name, email, password_hash) VALUES ('R', 'r@t.co', 'h')");
            throw new RuntimeException('boom');
        });
    });
    assert_null(DB::first('SELECT id FROM users WHERE email = :e', ['e' => 'r@t.co']));
});
```

Create `tests/user_model_test.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Class "DB" not found`.

- [ ] **Step 3: Write `lib/db.php`**

```php
<?php

declare(strict_types=1);

defined('BASED') || exit;

final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = (string) env('DB_DSN', '');

        if ($dsn === '') {
            $host = (string) env('DB_HOST', 'localhost');
            $port = (string) env('DB_PORT', '3306');
            $name = Env::must('DB_NAME');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        }

        try {
            $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                // No ATTR_PERSISTENT: on cPanel a leaked transaction or table lock
                // would poison the next request that reuses the connection.
            ]);
        } catch (PDOException $e) {
            // getMessage() embeds the DSN and username; getTraceAsString() shows the
            // password as a constructor arg. Neither may reach the client.
            error_log('[based] database connection failed: ' . $e->getMessage());

            throw new HttpException(500, 'Database connection failed');
        }

        if (str_starts_with($dsn, 'sqlite:')) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return self::$pdo = $pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);

        // The chokepoint. execute($params) would bind EVERYTHING as PARAM_STR,
        // which is what breaks `LIMIT :n` under native prepares. Binding by the
        // caller's real PHP type fixes it once, here, for every query in the app.
        foreach ($params as $key => $value) {
            if (!is_scalar($value) && !is_null($value)) {
                throw new InvalidArgumentException('Bind values must be scalar or null');
            }

            if (is_bool($value)) {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
            } elseif (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } elseif (is_null($value)) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
        }

        $stmt->execute();

        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);

        return (int) self::pdo()->lastInsertId();
    }

    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $result = $fn();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }
}
```

- [ ] **Step 4: Write `models/user.php`**

Explicit SQL with enumerated column lists. This is what makes a `password_hash` leak and mass assignment *structurally impossible* rather than a convention someone has to remember.

```php
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
```

- [ ] **Step 5: Write `database.sql`**

MySQL only. **No `CREATE DATABASE` and no `USE`** — cPanel prefixes DB names and your DB user has no `CREATE DATABASE` privilege, so phpMyAdmin rejects the import.

```sql
-- Import via cPanel → phpMyAdmin. Create the database in
-- cPanel → MySQL Databases first; cPanel prefixes it (e.g. acct_appdb).

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(255) NOT NULL,
  -- UNIQUE is load-bearing: without it duplicate signups make login non-deterministic.
  email         VARCHAR(255) NOT NULL UNIQUE,
  -- 255: PASSWORD_DEFAULT is not stable across PHP versions and may grow (Argon2).
  password_hash VARCHAR(255) NOT NULL,
  token_version INT NOT NULL DEFAULT 0,
  status        VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  identifier   VARCHAR(255) NOT NULL,
  ip           VARCHAR(45) NOT NULL,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_identifier_time (identifier, attempted_at),
  INDEX idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: all DB and user-model tests PASS — in particular *"user_find never returns password_hash"* and *"an int LIMIT binds correctly under native prepares"*.

- [ ] **Step 7: Commit**

```bash
git add lib/db.php models/user.php database.sql tests/db_test.php tests/user_model_test.php tests/schema.sqlite.sql
git commit -m "feat: add PDO layer with typed-bind chokepoint and explicit-SQL models

DB::run binds by real PHP type, which permanently fixes the LIMIT bug that made
the old utils/database.php unrunnable. Models enumerate columns, making a
password_hash leak and mass assignment structurally impossible."
```

---

### Task 8: `lib/jwt.php` — hand-rolled HS256

**Files:**
- Create: `lib/jwt.php`, `tests/jwt_test.php`

**Interfaces:**
- Consumes: `Env::must()` (Task 2).
- Produces:
  - `Jwt::encode(array $claims, int $ttl): string`
  - `Jwt::decode(string $token): ?array` — returns claims, or `null` on **any** failure. Never throws on attacker input.

- [ ] **Step 1: Write the failing test**

Create `tests/jwt_test.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Class "Jwt" not found`.

- [ ] **Step 3: Write `lib/jwt.php`**

```php
<?php

declare(strict_types=1);

defined('BASED') || exit;

final class Jwt
{
    // PINNED. The algorithm is asserted against, never dispatched on.
    // A verifier that supports exactly one algorithm cannot have an
    // algorithm-confusion bug — this is the whole argument for hand-rolling.
    private const ALG = 'HS256';
    private const MAX_LENGTH = 4096;
    private const LEEWAY_SECONDS = 30;

    private static function secret(): string
    {
        return Env::must('JWT_SECRET', 32);
    }

    private static function b64(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): ?string
    {
        $binary = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($binary === false) {
            return null;
        }

        // Canonical form only. Without this round-trip, padding variants and
        // out-of-alphabet characters let an attacker mutate the token text.
        if (self::b64($binary) !== $encoded) {
            return null;
        }

        return $binary;
    }

    public static function encode(array $claims, int $ttl): string
    {
        $now = time();

        // Caller-supplied claims win, so an explicit exp is never overwritten.
        $claims = array_merge(['iat' => $now, 'exp' => $now + $ttl], $claims);

        $header = self::b64(json_encode(['alg' => self::ALG, 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::b64(json_encode($claims, JSON_THROW_ON_ERROR));

        $signature = hash_hmac('sha256', $header . '.' . $payload, self::secret(), true);

        return $header . '.' . $payload . '.' . self::b64($signature);
    }

    public static function decode(string $token): ?array
    {
        if ($token === '' || strlen($token) > self::MAX_LENGTH) {
            return null;
        }

        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        $given = self::unb64($signature);

        if ($given === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $header . '.' . $payload, self::secret(), true);

        // VERIFY BEFORE PARSE. Nothing below runs on an unverified token, so
        // attacker-controlled bytes never reach the JSON parser or the database.
        // hash_equals on RAW BYTES: === is not constant-time, and == type-juggles
        // hex digests that look like scientific notation ("0e123" == "0e456").
        if (!hash_equals($expected, $given)) {
            return null;
        }

        $decodedHeader = json_decode((string) self::unb64($header), true, 8);

        if (!is_array($decodedHeader) || ($decodedHeader['alg'] ?? null) !== self::ALG) {
            return null;
        }

        $claims = json_decode((string) self::unb64($payload), true, 8);

        if (!is_array($claims)) {
            return null;
        }

        // A missing or non-integer exp must never mean "never expires".
        if (!isset($claims['exp']) || !is_int($claims['exp'])) {
            return null;
        }

        if (time() >= $claims['exp'] + self::LEEWAY_SECONDS) {
            return null;
        }

        return $claims;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all JWT tests PASS — in particular *"the alg:none attack is rejected"* and *"a missing secret throws rather than signing with an empty key"*.

- [ ] **Step 5: Commit**

```bash
git add lib/jwt.php tests/jwt_test.php
git commit -m "feat: add hand-rolled HS256 JWT

Verify-before-parse, pinned algorithm, raw-byte hash_equals, strict base64 with
a canonicalization round-trip, mandatory integer exp, fail-closed 32-char secret."
```

---

### Task 9: `lib/auth.php` + `handlers/auth.php` — login, throttle, revocation

**Files:**
- Create: `lib/auth.php`, `handlers/auth.php`, `tests/auth_test.php`
- Modify: `routes.php`, `index.php`

**Interfaces:**
- Consumes: `Jwt`, `DB`, `Request`, `Response`, `Validator` (Task 10 — **register routes for these handlers only after Task 10 lands**), the `user_*` model functions.
- Produces:
  - `Auth::required(array $params): bool` — middleware
  - `Auth::user(): ?array`
  - `Auth::throttled(string $email, string $ip): bool`
  - `Auth::recordFailure(string $email, string $ip): void`
  - `Auth::clearFailures(string $email): void`
  - `Auth::dummyHash(): string`
  - `handle_register(array $params): void`, `handle_login(array $params): void`, `handle_me(array $params): void`

> **Ordering note:** `handlers/auth.php` calls `Validator::check()`, which is built in Task 10. Build `lib/validator.php` (Task 10, Steps 1–4) *before* running this task's handler smoke tests, or do Task 10 first. The unit tests in this task do not touch the handlers, so they pass independently.

- [ ] **Step 1: Write the failing test**

Create `tests/auth_test.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Class "Auth" not found`.

- [ ] **Step 3: Write `lib/auth.php`**

```php
<?php

declare(strict_types=1);

defined('BASED') || exit;

final class Auth
{
    private const MAX_FAILURES = 5;
    private const WINDOW_MINUTES = 15;

    private static ?array $user = null;

    public static function required(array $params): bool
    {
        $token = Request::bearerToken();

        if ($token === null) {
            return self::deny();
        }

        $claims = Jwt::decode($token);

        if ($claims === null || !isset($claims['sub'])) {
            return self::deny();
        }

        $state = user_find_auth_state((int) $claims['sub']);

        if ($state === null) {
            return self::deny();
        }

        // A banned or suspended user keeps API access until their token expires
        // unless status is checked here. The row is already loaded, so it is one if.
        if ($state['status'] !== 'active') {
            return self::deny();
        }

        // The revocation kill switch. Bumping token_version instantly invalidates
        // every outstanding token for this user.
        if ((int) $state['token_version'] !== (int) ($claims['tv'] ?? -1)) {
            return self::deny();
        }

        self::$user = user_find((int) $claims['sub']);

        return true;
    }

    private static function deny(): bool
    {
        // With no logging, a credential-stuffing run is invisible to the operator.
        error_log('[based] auth rejected for ' . Request::ip());

        // Terminal — Response::error() exits. The `return false` keeps the
        // fail-closed contract honest even if that ever changes.
        Response::error('Unauthorized', 401);

        return false;
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function throttled(string $email, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);

        $byEmail = DB::first(
            'SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = :id AND attempted_at > :since',
            ['id' => $email, 'since' => $since]
        );

        $byIp = DB::first(
            'SELECT COUNT(*) AS c FROM login_attempts WHERE ip = :ip AND attempted_at > :since',
            ['ip' => $ip, 'since' => $since]
        );

        // The IP threshold is looser so one attacker behind a shared NAT cannot
        // lock out everyone else on it.
        return (int) ($byEmail['c'] ?? 0) >= self::MAX_FAILURES
            || (int) ($byIp['c'] ?? 0) >= self::MAX_FAILURES * 4;
    }

    public static function recordFailure(string $email, string $ip): void
    {
        DB::insert(
            'INSERT INTO login_attempts (identifier, ip) VALUES (:id, :ip)',
            ['id' => $email, 'ip' => $ip]
        );

        // Opportunistic cleanup on ~1% of failures. No cron needed.
        if (random_int(1, 100) === 1) {
            DB::run(
                'DELETE FROM login_attempts WHERE attempted_at < :cutoff',
                ['cutoff' => date('Y-m-d H:i:s', time() - 86400)]
            );
        }
    }

    public static function clearFailures(string $email): void
    {
        DB::run('DELETE FROM login_attempts WHERE identifier = :id', ['id' => $email]);
    }

    public static function dummyHash(): string
    {
        static $hash = null;

        // Derived from PASSWORD_DEFAULT, never a hardcoded cost-10 literal — a
        // pinned literal drifts the day the host's default cost changes, and the
        // timing oracle it exists to close silently reopens.
        if ($hash === null) {
            $hash = password_hash('invalid-placeholder', PASSWORD_DEFAULT);
        }

        return $hash;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all auth tests PASS.

- [ ] **Step 5: Write `handlers/auth.php`**

```php
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

    // No 409 "email taken": that is a user-enumeration oracle that walks straight
    // past the constant-time login defence. The response is identical either way.
    if (!user_email_exists($input['email'])) {
        user_create($input['name'], $input['email'], $input['password']);
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

    $ttl = (int) env('JWT_TTL', '86400');

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
```

- [ ] **Step 6: Wire the new files into `index.php`**

Add these `require` lines after the existing ones (order matters — `lib/` before `models/` before `handlers/`):

```php
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/jwt.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/validator.php';
require __DIR__ . '/models/user.php';
require __DIR__ . '/handlers/auth.php';
```

- [ ] **Step 7: Add the auth routes to `routes.php`**

```php
Router::get('/health', 'handle_health');

Router::post('/auth/register', 'handle_register');
Router::post('/auth/login', 'handle_login');
Router::get('/auth/me', 'handle_me', ['Auth::required']);
```

- [ ] **Step 8: Commit**

```bash
git add lib/auth.php handlers/auth.php tests/auth_test.php index.php routes.php
git commit -m "feat: add JWT auth with login throttle and token_version revocation

Login always runs password_verify (dummy hash when the account is missing) so
a missing account and a wrong password take the same time. Register returns a
generic response rather than 409, which would be an enumeration oracle."
```

---

### Task 10: `lib/validator.php` + `handlers/users.php` — validation and CRUD

**Files:**
- Create: `lib/validator.php`, `handlers/users.php`, `tests/validator_test.php`
- Modify: `routes.php`

**Interfaces:**
- Consumes: `HttpException`, `Request`, `Response`, `Auth`, the `user_*` model functions.
- Produces:
  - `Validator::check(array $input, array $rules): array` — throws `HttpException(422, 'Validation failed', $errors)`. Returns **only the validated keys, type-cast.**
  - `handle_users_index`, `handle_users_show`, `handle_users_update`, `handle_users_delete` — each `(array $params): void`

Rules: `required`, `string`, `int`, `email`, `min:N`, `max:N`, `in:a,b,c`, plus a `Closure` escape hatch (so the rule DSL never has to grow).

- [ ] **Step 1: Write the failing test**

Create `tests/validator_test.php`:

```php
<?php

declare(strict_types=1);

require_once BASE_PATH . '/lib/response.php';
require_once BASE_PATH . '/lib/validator.php';

test('passes valid input through', function (): void {
    $clean = Validator::check(['email' => 'a@t.co'], ['email' => 'required|email']);
    assert_eq(['email' => 'a@t.co'], $clean);
});

// The anti-mass-assignment boundary: you pass $clean to the model, never the
// raw body. If check() returned the whole input, that guarantee would evaporate.
test('returns ONLY the validated keys, dropping everything else', function (): void {
    $clean = Validator::check(
        ['email' => 'a@t.co', 'is_admin' => true, 'password_hash' => 'pwned'],
        ['email' => 'required|email']
    );
    assert_eq(['email' => 'a@t.co'], $clean);
});

test('casts an int rule to a real int', function (): void {
    $clean = Validator::check(['age' => '30'], ['age' => 'required|int']);
    assert_true(is_int($clean['age']));
    assert_eq(30, $clean['age']);
});

test('throws 422 when a required field is missing', function (): void {
    try {
        Validator::check([], ['email' => 'required|email']);
        throw new Exception('should have thrown');
    } catch (HttpException $e) {
        assert_eq(422, $e->status());
        assert_eq(['email' => 'is required'], $e->details());
    }
});

test('throws when a required field is an empty string', function (): void {
    assert_throws(function (): void {
        Validator::check(['email' => ''], ['email' => 'required|email']);
    });
});

test('rejects a malformed email', function (): void {
    assert_throws(function (): void {
        Validator::check(['email' => 'not-an-email'], ['email' => 'required|email']);
    });
});

test('rejects a non-integer for an int rule', function (): void {
    assert_throws(function (): void {
        Validator::check(['age' => 'abc'], ['age' => 'required|int']);
    });
});

test('enforces min on string length', function (): void {
    assert_throws(function (): void {
        Validator::check(['password' => 'short'], ['password' => 'required|string|min:8']);
    });
});

test('enforces max on string length', function (): void {
    assert_throws(function (): void {
        Validator::check(['name' => str_repeat('a', 300)], ['name' => 'required|string|max:255']);
    });
});

test('enforces min and max on int values', function (): void {
    assert_throws(function (): void {
        Validator::check(['age' => '5'], ['age' => 'required|int|min:18']);
    });
    assert_eq(21, Validator::check(['age' => '21'], ['age' => 'required|int|min:18'])['age']);
});

test('enforces the in rule', function (): void {
    assert_eq('active', Validator::check(['s' => 'active'], ['s' => 'required|in:active,banned'])['s']);
    assert_throws(function (): void {
        Validator::check(['s' => 'admin'], ['s' => 'required|in:active,banned']);
    });
});

test('an optional absent field is simply omitted', function (): void {
    $clean = Validator::check(['email' => 'a@t.co'], ['email' => 'required|email', 'nick' => 'string']);
    assert_eq(['email' => 'a@t.co'], $clean);
    assert_eq(false, array_key_exists('nick', $clean));
});

// ?email[]=a makes the value an array. filter_var on an array returns false for
// email, but `required` alone would let it through to the model.
test('rejects a non-scalar value', function (): void {
    assert_throws(function (): void {
        Validator::check(['email' => ['a@t.co']], ['email' => 'required|email']);
    });
});

test('collects errors for every failing field at once', function (): void {
    try {
        Validator::check([], ['email' => 'required|email', 'name' => 'required|string']);
        throw new Exception('should have thrown');
    } catch (HttpException $e) {
        assert_eq(2, count($e->details()));
    }
});

test('supports a Closure escape hatch', function (): void {
    $rule = function ($value) {
        return $value === 'magic' ? true : 'must be magic';
    };
    assert_eq(['x' => 'magic'], Validator::check(['x' => 'magic'], ['x' => $rule]));
    assert_throws(function () use ($rule): void {
        Validator::check(['x' => 'nope'], ['x' => $rule]);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Class "Validator" not found`.

- [ ] **Step 3: Write `lib/validator.php`**

```php
<?php

declare(strict_types=1);

defined('BASED') || exit;

final class Validator
{
    /**
     * Returns ONLY the validated keys, type-cast. That return value is the
     * anti-mass-assignment boundary: pass $clean to the model, never the raw body.
     */
    public static function check(array $input, array $rules): array
    {
        $clean = [];
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = $input[$field] ?? null;

            if ($rule instanceof Closure) {
                $result = $rule($value);

                if ($result !== true) {
                    $errors[$field] = is_string($result) ? $result : 'is invalid';

                    continue;
                }

                $clean[$field] = $value;

                continue;
            }

            $parts = explode('|', (string) $rule);
            $required = in_array('required', $parts, true);
            $present = $value !== null && $value !== '';

            if (!$present) {
                if ($required) {
                    $errors[$field] = 'is required';
                }

                continue;
            }

            if (!is_scalar($value)) {
                $errors[$field] = 'must be a single value';

                continue;
            }

            $cast = $value;
            $error = null;

            foreach ($parts as $part) {
                if ($part === 'required') {
                    continue;
                }

                [$name, $arg] = array_pad(explode(':', $part, 2), 2, null);

                switch ($name) {
                    case 'string':
                        $cast = (string) $cast;

                        break;

                    case 'int':
                        if (filter_var($cast, FILTER_VALIDATE_INT) === false) {
                            $error = 'must be an integer';

                            break 2;
                        }

                        $cast = (int) $cast;

                        break;

                    case 'email':
                        if (filter_var((string) $cast, FILTER_VALIDATE_EMAIL) === false) {
                            $error = 'must be a valid email address';

                            break 2;
                        }

                        break;

                    case 'min':
                        if (is_int($cast)) {
                            if ($cast < (int) $arg) {
                                $error = "must be at least {$arg}";

                                break 2;
                            }
                        } elseif (self::length((string) $cast) < (int) $arg) {
                            $error = "must be at least {$arg} characters";

                            break 2;
                        }

                        break;

                    case 'max':
                        if (is_int($cast)) {
                            if ($cast > (int) $arg) {
                                $error = "must be at most {$arg}";

                                break 2;
                            }
                        } elseif (self::length((string) $cast) > (int) $arg) {
                            $error = "must be at most {$arg} characters";

                            break 2;
                        }

                        break;

                    case 'in':
                        if (!in_array((string) $cast, explode(',', (string) $arg), true)) {
                            $error = "must be one of: {$arg}";

                            break 2;
                        }

                        break;
                }
            }

            if ($error !== null) {
                $errors[$field] = $error;

                continue;
            }

            $clean[$field] = $cast;
        }

        if ($errors !== []) {
            // 422: a syntactically valid body that fails semantic rules.
            // (A body you cannot parse at all is a 400 — see Request::body().)
            throw new HttpException(422, 'Validation failed', $errors);
        }

        return $clean;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all validator tests PASS.

- [ ] **Step 5: Write `handlers/users.php`**

```php
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

    user_update($id, $input['name'], $input['email']);

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
```

- [ ] **Step 6: Finish `routes.php`**

The whole API surface, at a glance. Static segments before `:param` segments — `/users/:id` registered first would swallow any literal route below it.

```php
<?php

declare(strict_types=1);

defined('BASED') || exit;

Router::get('/health', 'handle_health');

Router::post('/auth/register', 'handle_register');
Router::post('/auth/login', 'handle_login');
Router::get('/auth/me', 'handle_me', ['Auth::required']);

Router::get('/users', 'handle_users_index', ['Auth::required']);
Router::get('/users/:id', 'handle_users_show', ['Auth::required']);
Router::patch('/users/:id', 'handle_users_update', ['Auth::required']);
Router::delete('/users/:id', 'handle_users_delete', ['Auth::required']);
```

- [ ] **Step 7: Add `handlers/users.php` to `index.php`**

```php
require __DIR__ . '/handlers/users.php';
```

- [ ] **Step 8: Extend `tests/smoke.sh` with an auth round-trip**

Append before the final `[ "$FAIL" -eq 0 ]` line:

```bash
# Auth round-trip. Requires a configured DB and an imported schema.
if [ -n "$SMOKE_DB" ]; then
    EMAIL="smoke$(date +%s)@test.co"
    curl -s -X POST "${BASE}/auth/register" -H 'Content-Type: application/json' \
        -d "{\"name\":\"Smoke\",\"email\":\"${EMAIL}\",\"password\":\"password123\"}" > /dev/null

    TOKEN=$(curl -s -X POST "${BASE}/auth/login" -H 'Content-Type: application/json' \
        -d "{\"email\":\"${EMAIL}\",\"password\":\"password123\"}" \
        | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')

    check "login returns a token" "yes" "$([ -n "$TOKEN" ] && echo yes || echo no)"
    check "a garbage token is 401" 401 \
        "$(status -H 'Authorization: Bearer garbage' "${BASE}/auth/me")"
    check "a real token reaches /auth/me (the Authorization header survived)" 200 \
        "$(status -H "Authorization: Bearer ${TOKEN}" "${BASE}/auth/me")"
    check "/users needs auth" 401 "$(status "${BASE}/users")"

    if curl -s -H "Authorization: Bearer ${TOKEN}" "${BASE}/users" | grep -qi 'password\|hash'; then
        printf '  \033[31m✗\033[0m /users LEAKS PASSWORD HASHES\n'
        FAIL=1
    else
        printf '  \033[32m✓\033[0m /users leaks no password hash\n'
    fi
fi
```

- [ ] **Step 9: Run the full suite**

```bash
php tests/run.php
php -S localhost:8080 index.php &
sleep 1
sh tests/smoke.sh
kill %1
```

Expected: all unit tests PASS; `smoke: all passed`.

- [ ] **Step 10: Commit**

```bash
git add lib/validator.php handlers/users.php routes.php index.php tests/validator_test.php tests/smoke.sh
git commit -m "feat: add validation and user CRUD

Validator::check returns only the validated, type-cast keys — that return value
is the anti-mass-assignment boundary. Handlers pass it to models; never the raw body."
```

---

### Task 11: `README.md` — quickstart, deploy, troubleshooting

**Files:**
- Create: `README.md` (replaces the current 3-line file)

**Interfaces:**
- Consumes: everything. Produces: documentation only.

- [ ] **Step 1: Write `README.md`**

````markdown
# BasedPHP

A simple and bare PHP framework for building JSON APIs.

Express/Hono, but PHP — and it deploys by dragging a folder onto FTP.
No Composer. No dependencies. No build step. ~600 lines you can read in one sitting.

## Quickstart

```bash
cp .env.example .env
php -r "echo bin2hex(random_bytes(32));"   # paste into JWT_SECRET
sh start.sh                                # http://localhost:8080
curl localhost:8080/health
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

## Auth

JWT bearer tokens, HS256, hand-rolled — one algorithm, one code path, so it
cannot have an algorithm-confusion bug.

```
POST /auth/register  → 202
POST /auth/login     → { "token": "...", "token_type": "Bearer", "expires_in": 86400 }
GET  /auth/me        → the current user   (Authorization: Bearer <token>)
```

There is no server-side logout — a plain logout is client-side (drop the token).
To revoke immediately, bump `users.token_version`; every outstanding token for
that user dies at once. Do this on password change, ban, suspend, and delete.

Honest framing: because `token_version` is checked on every request, this is a
session token that happens to be signed. That is the trade that buys you revocation.

Keep the token in memory on the client, not `localStorage` — an XSS on the
consuming app steals a token you cannot revoke without a `token_version` bump.

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

### Verify the deploy — this is the security test suite

```bash
sh tests/smoke.sh https://yoursite.com
```

Or by hand — every one of these must hold:

```
curl -i https://site/.env       → 403/404, NOT the file contents
curl -i https://site/health     → 200   (the rewrite works)
curl -H 'Authorization: Bearer garbage' https://site/auth/me   → 401, not 200
```

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

- **`lib/` stays under 600 lines.** Weigh every feature against it.
- **No side effects at include time.** Every file but `index.php` only *defines* things.
- **No writable directory, no cache.** Zero chmod story. Logs go to `error_log()`
  → the cPanel error log. Do not add a file logger or a route cache.
- **No closing `?>`.** One stray byte after it silently no-ops every `header()`.
````

- [ ] **Step 2: Verify the whole suite still passes**

```bash
php tests/run.php
php -S localhost:8080 index.php &
sleep 1
sh tests/smoke.sh
kill %1
```

Expected: all PASS.

- [ ] **Step 3: Check the line budget**

```bash
find lib -name '*.php' -exec cat {} + | grep -vc '^\s*$'
```

Expected: **under 600.** If it is over, cut a feature — do not raise the number.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs: add quickstart, cPanel deploy guide, and troubleshooting table"
```

---

## Self-Review

**Spec coverage:** Routing (T5, T6) · JWT auth (T8, T9) · DB + models (T7) · `.env` config (T2) · validation (T10) · CORS/JSON helpers (T3) · login throttle (T9) · `token_version` revocation (T7, T9) · transport hardening (T3, T6) · `.htaccess`/`.user.ini` (T6) · config files (T1, T2, T6) · schema (T7) · deploy + verification checklist (T6, T11) · repo cleanup (T1) · HTML helper (T3, `Response::html`). No gaps.

**Type consistency check:** `user_find_auth_record(string $email)` is used by `handle_login` (T9) and returns `password_hash`/`token_version`/`status`; `user_find_auth_state(int $id)` is used by `Auth::required` (T9) and returns `token_version`/`status`. `Validator::check` returns an array in every caller. `Router` middleware is `callable` returning `bool` everywhere. `DB::insert` returns `int` and is used as such by `user_create`.

**Known cross-task ordering constraint:** `handlers/auth.php` (T9) calls `Validator::check()`, defined in T10. T9's *unit tests* do not exercise the handlers, so T9 passes standalone; the handler smoke tests only work once T10 lands. Flagged inline in T9.
