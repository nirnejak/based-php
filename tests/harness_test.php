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
