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

        // A SQLite file inside the web root is the whole database over HTTP (and
        // the -wal sidecar leaks recent writes even if the main file is denied).
        // Refuse it in production — a file the framework won't open beats an
        // .htaccess rule that may be ignored. `:memory:` and dev are exempt.
        if (str_starts_with($dsn, 'sqlite:') && env('APP_ENV', 'production') === 'production') {
            $sqlitePath = substr($dsn, strlen('sqlite:'));
            $resolved = realpath($sqlitePath);

            // realpath BOTH sides: comparing a canonicalized path against a
            // symlinked BASE_PATH (e.g. /var -> /private/var, or a symlinked
            // deploy dir) would fail open. Compare against root + separator, not
            // bare root, so a sibling sharing a name prefix (/home/u/app vs
            // /home/u/app-staging) is not falsely refused.
            $root = defined('BASE_PATH') ? realpath((string) BASE_PATH) : false;

            if ($sqlitePath !== ':memory:'
                && $resolved !== false
                && $root !== false
                && str_starts_with($resolved . DIRECTORY_SEPARATOR, rtrim($root, '/\\') . DIRECTORY_SEPARATOR)) {
                throw new HttpException(500, 'Refusing to open a SQLite database inside the web root');
            }
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

    public static function reset(): void
    {
        self::$pdo = null;
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
