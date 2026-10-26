<?php

declare(strict_types=1);

namespace DevisCrm\Api\Auth;

use DevisCrm\Api\Exceptions\ApiException;

/**
 * Owner login, server-side sessions and brute-force throttling.
 * Session tokens are never stored: the database keeps their SHA-256 digests.
 */
final class AuthService
{
    // password_verify() targets for unknown logins, so that a missing user costs the same as
    // a wrong password. Hashes of a random string nobody knows; not secrets.
    private const DUMMY_ARGON2ID_HASH = '$argon2id$v=19$m=65536,t=4,p=1$Y2pFVHB6V1EyNlhIMGtTNQ$C9LcwjAILFd0aoVi0tUJifbBb7/F6mVa+8YfKl8Dq08';
    private const DUMMY_BCRYPT_HASH = '$2y$10$jdvEjM7F.RD5nA.HRCJf6eezCoWNgdmeGsqajDX2DFy6G0.1IGw9O';

    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(
        private AuthStore $store,
        private Clock $clock,
    ) {
    }

    /** The same normalization for login and for counting attempts. */
    public static function normalizeLogin(string $login): string
    {
        return strtolower(trim($login));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function dummyHash(): string
    {
        return defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)
            ? self::DUMMY_ARGON2ID_HASH
            : self::DUMMY_BCRYPT_HASH;
    }

    /**
     * Per-IP limits only for public addresses. Behind a local reverse proxy REMOTE_ADDR is the
     * proxy, shared by every client, and a per-IP limit would lock everyone out.
     */
    public static function ipLimitApplies(string $ipAddress): bool
    {
        return $ipAddress !== ''
            && filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @throws ApiException 429 when throttled, 401 for any wrong login or password.
     */
    public function login(string $normalizedLogin, string $password, string $ipAddress): LoginResult
    {
        $now = $this->clock->now();

        $retryAfter = $this->retryAfterSeconds($normalizedLogin, $ipAddress, $now);
        if ($retryAfter > 0) {
            throw new ApiException(429, 'too_many_attempts', 'Too many login attempts, try again later', ['Retry-After' => (string) $retryAfter]);
        }

        $user = $this->store->findUserByLogin($normalizedLogin);
        $verified = password_verify($password, $user !== null ? $user->passwordHash : self::dummyHash());
        $succeeded = $user !== null && $verified;

        $this->store->recordAttempt($normalizedLogin, $ipAddress !== '' ? $ipAddress : 'unknown', $now, $succeeded);
        if (!$succeeded) {
            throw new ApiException(401, 'invalid_credentials', 'Invalid login or password');
        }

        $this->store->deleteExpiredSessions($now, self::secondsBefore($now, AuthSettings::IDLE_TIMEOUT_SECONDS));
        $this->store->purgeAttemptsBefore(self::secondsBefore($now, AuthSettings::ATTEMPT_RETENTION_SECONDS));

        $token = self::newToken();
        $session = new AuthSession(
            self::newUuid(),
            $user->id,
            $user->login,
            bin2hex(random_bytes(32)),
            $now,
            $now,
            $now->modify('+' . AuthSettings::ABSOLUTE_LIFETIME_SECONDS . ' seconds'),
        );
        $this->store->createSession($session, self::hashToken($token));

        return new LoginResult($token, $session);
    }

    /** The live session for a cookie token, or null. Expired and idle sessions are deleted. */
    public function authenticate(string $token): ?AuthSession
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }
        $session = $this->store->findSessionByTokenHash(self::hashToken($token));
        if ($session === null) {
            return null;
        }

        $now = $this->clock->now();
        $idle = self::secondsBetween($session->lastSeenAt, $now);
        if ($now >= $session->expiresAt || $idle >= AuthSettings::IDLE_TIMEOUT_SECONDS) {
            $this->store->deleteSession($session->id);

            return null;
        }
        if ($idle >= AuthSettings::TOUCH_INTERVAL_SECONDS) {
            $this->store->touchSession($session->id, $now);
            $session->lastSeenAt = $now;
        }

        return $session;
    }

    public function logout(AuthSession $session): void
    {
        $this->store->deleteSession($session->id);
    }

    /** Ends the session behind a token, if any (used when a new login replaces it). */
    public function revokeToken(string $token): void
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return;
        }
        $session = $this->store->findSessionByTokenHash(self::hashToken($token));
        if ($session !== null) {
            $this->store->deleteSession($session->id);
        }
    }

    private function retryAfterSeconds(string $normalizedLogin, string $ipAddress, \DateTimeImmutable $now): int
    {
        $since = self::secondsBefore($now, AuthSettings::ATTEMPT_WINDOW_SECONDS);
        $wait = self::waitSeconds($this->store->failureTimesForLogin($normalizedLogin, $since), $now);
        if (self::ipLimitApplies($ipAddress)) {
            $wait = max($wait, self::waitSeconds($this->store->failureTimesForIp($ipAddress, $since), $now));
        }

        return $wait;
    }

    /**
     * Seconds until fewer than MAX_FAILED_ATTEMPTS failures remain in the window, or 0.
     *
     * @param list<\DateTimeImmutable> $failures Oldest first.
     */
    private static function waitSeconds(array $failures, \DateTimeImmutable $now): int
    {
        $count = count($failures);
        if ($count < AuthSettings::MAX_FAILED_ATTEMPTS) {
            return 0;
        }
        $oldestCounted = $failures[$count - AuthSettings::MAX_FAILED_ATTEMPTS];
        $remaining = AuthSettings::ATTEMPT_WINDOW_SECONDS - self::secondsBetween($oldestCounted, $now);

        return $remaining > 0 ? (int) ceil($remaining) : 0;
    }

    private static function secondsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        return (float) $to->format('U.u') - (float) $from->format('U.u');
    }

    private static function secondsBefore(\DateTimeImmutable $time, int $seconds): \DateTimeImmutable
    {
        return $time->modify('-' . $seconds . ' seconds');
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
