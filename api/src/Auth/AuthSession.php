<?php

declare(strict_types=1);

namespace DevisCrm\Api\Auth;

/** A stored session joined with its user. Never contains the session token itself. */
final class AuthSession
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $login,
        public string $csrfToken,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $lastSeenAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
