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

            // min/max mean "numeric bound" for an int field and "length bound" for
            // a string field. Decide by the DECLARED type, not by whether `int` has
            // been processed yet — otherwise `max:5|int` (bound before the type
            // token) would length-check "9" and wrongly pass, a validation bypass.
            $numericBound = in_array('int', $parts, true);

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
                        if ($numericBound) {
                            if ((int) $cast < (int) $arg) {
                                $error = "must be at least {$arg}";

                                break 2;
                            }
                        } elseif (self::length((string) $cast) < (int) $arg) {
                            $error = "must be at least {$arg} characters";

                            break 2;
                        }

                        break;

                    case 'max':
                        if ($numericBound) {
                            if ((int) $cast > (int) $arg) {
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
