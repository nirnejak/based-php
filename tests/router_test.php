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

// The fold lives in dispatch(), so exercise it there: a HEAD request must run
// the GET handler. Without this the fold could be deleted and no test would fail.
test('a HEAD request runs the GET handler', function (): void {
    Router::reset();
    $ran = false;
    Router::get('/users', function (array $params) use (&$ran): void {
        $ran = true;
    });
    Router::dispatch('HEAD', '/users');
    assert_true($ran, 'HEAD must dispatch to the GET handler');
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

test('a trailing newline does not match a route', function () use ($noop): void {
    Router::reset();
    Router::get('/health', $noop);
    assert_null(Router::match('GET', "/health\n"), 'a trailing control byte must not match');
});
