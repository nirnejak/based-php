<?php

declare(strict_types=1);

defined('BASED') || exit;

final class Jwt
{
    // PINNED. The algorithm is asserted against, never dispatched on.
    // A verifier that supports exactly one algorithm cannot have an
    // algorithm-confusion bug — this is the whole argument for hand-rolling.
    private const ALG = 'HS256';
    private const MAX_LENGTH = 4096;
    private const LEEWAY_SECONDS = 30;

    private static function secret(): string
    {
        return Env::must('JWT_SECRET', 32);
    }

    private static function b64(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): ?string
    {
        $binary = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($binary === false) {
            return null;
        }

        // Canonical form only. Without this round-trip, padding variants and
        // out-of-alphabet characters let an attacker mutate the token text.
        if (self::b64($binary) !== $encoded) {
            return null;
        }

        return $binary;
    }

    public static function encode(array $claims, int $ttl): string
    {
        $now = time();

        // Caller-supplied claims win, so an explicit exp is never overwritten.
        $claims = array_merge(['iat' => $now, 'exp' => $now + $ttl], $claims);

        $header = self::b64(json_encode(['alg' => self::ALG, 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::b64(json_encode($claims, JSON_THROW_ON_ERROR));

        $signature = hash_hmac('sha256', $header . '.' . $payload, self::secret(), true);

        return $header . '.' . $payload . '.' . self::b64($signature);
    }

    public static function decode(string $token): ?array
    {
        if ($token === '' || strlen($token) > self::MAX_LENGTH) {
            return null;
        }

        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        $given = self::unb64($signature);

        if ($given === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $header . '.' . $payload, self::secret(), true);

        // VERIFY BEFORE PARSE. Nothing below runs on an unverified token, so
        // attacker-controlled bytes never reach the JSON parser or the database.
        // hash_equals on RAW BYTES: === is not constant-time, and == type-juggles
        // hex digests that look like scientific notation ("0e123" == "0e456").
        if (!hash_equals($expected, $given)) {
            return null;
        }

        $decodedHeader = json_decode((string) self::unb64($header), true, 8);

        if (!is_array($decodedHeader) || ($decodedHeader['alg'] ?? null) !== self::ALG) {
            return null;
        }

        $claims = json_decode((string) self::unb64($payload), true, 8);

        if (!is_array($claims)) {
            return null;
        }

        // A missing or non-integer exp must never mean "never expires".
        if (!isset($claims['exp']) || !is_int($claims['exp'])) {
            return null;
        }

        if (time() >= $claims['exp'] + self::LEEWAY_SECONDS) {
            return null;
        }

        return $claims;
    }
}
