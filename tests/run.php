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
