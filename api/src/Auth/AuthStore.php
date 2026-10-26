<?php

declare(strict_types=1);

namespace DevisCrm\Api\Auth;

/**
 * Persistence for users, sessions and login attempts (tables of migration 002_auth).
 * Implementations throw Exceptions\DatabaseUnavailableException on storage failures.
 */
interface AuthStore
{
    public function findUserByLogin(string $normalizedLogin): ?UserRecord;

    public function createSession(AuthSession $session, string $tokenHash): void;

    public function findSessionByTokenHash(string $tokenHash): ?AuthSession;

    public function touchSession(string $sessionId, \DateTimeImmutable $lastSeenAt): void;

    public function deleteSession(string $sessionId): void;

    /** Removes sessions past their absolute expiry or idle since before $idleCutoff. */
    public function deleteExpiredSessions(\DateTimeImmutable $now, \DateTimeImmutable $idleCutoff): void;

    public function recordAttempt(string $normalizedLogin, string $ipAddress, \DateTimeImmutable $at, bool $succeeded): void;

    /** @return list<\DateTimeImmutable> Failed attempts since $since, oldest first. */
    public function failureTimesForLogin(string $normalizedLogin, \DateTimeImmutable $since): array;

    /** @return list<\DateTimeImmutable> Failed attempts since $since, oldest first. */
    public function failureTimesForIp(string $ipAddress, \DateTimeImmutable $since): array;

    public function purgeAttemptsBefore(\DateTimeImmutable $cutoff): void;
}
