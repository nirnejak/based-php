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

        if (self::$user === null) {
            return self::deny();
        }

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
        // UNIX seconds, same clock as the stored attempted_at — no PHP/DB timezone mismatch.
        $since = time() - self::WINDOW_MINUTES * 60;

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
            'INSERT INTO login_attempts (identifier, ip, attempted_at) VALUES (:id, :ip, :ts)',
            ['id' => $email, 'ip' => $ip, 'ts' => time()]
        );

        // Opportunistic cleanup on ~1% of failures. No cron needed.
        if (random_int(1, 100) === 1) {
            DB::run(
                'DELETE FROM login_attempts WHERE attempted_at < :cutoff',
                ['cutoff' => time() - 86400]
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
