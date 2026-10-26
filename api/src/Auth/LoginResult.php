<?php

declare(strict_types=1);

namespace DevisCrm\Api\Auth;

final class LoginResult
{
    /**
     * @param string $token Raw session token, only for the Set-Cookie header.
     */
    public function __construct(
        public string $token,
        public AuthSession $session,
    ) {
    }
}
