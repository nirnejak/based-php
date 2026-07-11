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

// min/max must bound numerically for an int field regardless of rule ORDER.
// If they were order-dependent, `max:5|int` would length-check "9" (len 1 <= 5)
// and wrongly ACCEPT a value that violates the numeric max — a validation bypass.
test('int min/max bound numerically even when written before the int rule', function (): void {
    assert_throws(function (): void {
        Validator::check(['age' => '9'], ['age' => 'required|max:5|int']);
    });
    // and a numerically-valid value must NOT be rejected on string length
    assert_eq(99, Validator::check(['age' => '99'], ['age' => 'required|min:18|int'])['age']);
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
