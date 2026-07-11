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
