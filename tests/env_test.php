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

// .env.example ships a commented-out `# DB_DSN=sqlite:...` line, so pin the
// behaviour that a commented key is never readable. Note this holds even if the
// '#' guard were removed: explode('=') would key it as "# DB_DSN", not "DB_DSN".
// The guard is hygiene (it keeps junk keys out of the store), not a security control.
test('ignores a comment line that contains an equals sign', function (): void {
    Env::load(env_fixture("# DB_DSN=sqlite:/tmp/should-not-load.sqlite\nA=1\n"));
    assert_eq('1', Env::get('A'));
    assert_null(Env::get('DB_DSN'), 'a commented-out key must not become a live value');
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
