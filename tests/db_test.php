<?php

declare(strict_types=1);

require_once BASE_PATH . '/lib/env.php';
require_once BASE_PATH . '/lib/response.php';
require_once BASE_PATH . '/lib/db.php';

// Loads the shared test .env (sqlite::memory:) and applies the schema to a
// fresh connection. Split out of db_boot() so the webroot-guard test below —
// which deliberately points Env/DB at a different (production, on-disk) DSN —
// can call this again afterwards to restore the shared fixture for every test
// file that runs after it in this single process.
function db_seed(): void
{
    $path = sys_get_temp_dir() . '/based_test_env_' . bin2hex(random_bytes(6));
    file_put_contents($path, "DB_DSN=sqlite::memory:\nJWT_SECRET=" . str_repeat('k', 32) . "\nJWT_TTL=3600\n");
    Env::load($path);

    // DB::$pdo is a static singleton; a prior standalone test (e.g. the
    // webroot SQLite guard test) may have left it pointed at a different
    // DSN/connection or cleared it entirely. Reset it so this seed's DSN is
    // the one actually connected to.
    DB::reset();

    foreach (explode(';', (string) file_get_contents(BASE_PATH . '/tests/schema.sqlite.sql')) as $stmt) {
        if (trim($stmt) !== '') {
            DB::pdo()->exec($stmt);
        }
    }
}

function db_boot(): void
{
    static $booted = false;

    if ($booted) {
        DB::run('DELETE FROM users');
        DB::run('DELETE FROM login_attempts');

        return;
    }

    db_seed();

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

test('refuses a SQLite file inside the web root in production', function (): void {
    DB::reset();

    $inside = BASE_PATH . '/webroot_test_' . bin2hex(random_bytes(4)) . '.sqlite';
    touch($inside);

    $envPath = sys_get_temp_dir() . '/based_sqlite_guard_' . bin2hex(random_bytes(6));
    file_put_contents($envPath, "APP_ENV=production\nDB_DSN=sqlite:{$inside}\n");
    Env::load($envPath);

    // DB::$pdo is a static singleton; this test relies on running in its own
    // process state. If a prior test already connected, skip rather than assert
    // against a cached connection.
    $refused = false;
    try {
        DB::pdo();
    } catch (HttpException $e) {
        $refused = ($e->status() === 500);
    } finally {
        @unlink($inside);

        // This test intentionally pointed Env/DB at a production, on-disk DSN
        // and cleared the DB::$pdo singleton. db_boot()'s "already booted"
        // branch (used by every test after this one, including all of
        // user_model_test.php) assumes both are still pointed at the shared
        // sqlite::memory: fixture — restore that here rather than leaving it
        // to whichever test happens to run next.
        db_seed();
    }

    assert_true($refused, 'a webroot SQLite path must be refused in production');
});
