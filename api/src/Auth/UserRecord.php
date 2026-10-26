<?php

declare(strict_types=1);

namespace DevisCrm\Api\Auth;

final class UserRecord
{
    public function __construct(
        public string $id,
        public string $login,
        public string $passwordHash,
    ) {
    }
}
