<?php

declare(strict_types=1);

namespace DevisCrm\Api\Auth;

/**
 * Set-Cookie values for the session cookie. The __Host- prefix requires Secure, Path=/ and
 * no Domain, so the cookie is bound to crm.devisd.online over HTTPS only.
 */
final class SessionCookie
{
    private const ATTRIBUTES = 'Path=/; Secure; HttpOnly; SameSite=Strict';

    public static function issue(string $token, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): string
    {
        $maxAge = max(0, $expiresAt->getTimestamp() - $now->getTimestamp());

        return sprintf(
            '%s=%s; Max-Age=%d; Expires=%s GMT; %s',
            AuthSettings::COOKIE_NAME,
            $token,
            $maxAge,
            gmdate('D, d M Y H:i:s', $expiresAt->getTimestamp()),
            self::ATTRIBUTES,
        );
    }

    public static function clear(): string
    {
        return AuthSettings::COOKIE_NAME . '=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; ' . self::ATTRIBUTES;
    }
}
