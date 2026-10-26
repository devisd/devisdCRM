<?php

declare(strict_types=1);

namespace DevisCrm\Api\Auth;

use DevisCrm\Api\Exceptions\ApiException;
use DevisCrm\Api\Http\Request;

/** Origin, session and CSRF checks applied by the router. */
final class RequestGuard
{
    public function __construct(
        private AuthService $auth,
        private string $allowedOrigin = AuthSettings::ALLOWED_ORIGIN,
    ) {
    }

    /** State-changing requests must come from the CRM origin (exact match). */
    public function requireSameOrigin(Request $request): void
    {
        $origin = $request->header('origin');
        if ($origin === null || !hash_equals($this->allowedOrigin, $origin)) {
            throw new ApiException(403, 'origin_not_allowed', 'Request origin is not allowed');
        }
    }

    public function requireSession(Request $request): AuthSession
    {
        $token = $request->cookie(AuthSettings::COOKIE_NAME);
        if ($token === null || $token === '') {
            throw new ApiException(401, 'unauthorized', 'Authentication required');
        }

        $session = $this->auth->authenticate($token);
        if ($session === null) {
            // A stale cookie is removed so that the browser stops sending it.
            throw new ApiException(401, 'unauthorized', 'Authentication required', ['Set-Cookie' => SessionCookie::clear()]);
        }

        return $session;
    }

    public function requireCsrf(Request $request, AuthSession $session): void
    {
        $token = $request->header('x-csrf-token');
        if ($token === null || !hash_equals($session->csrfToken, $token)) {
            throw new ApiException(403, 'csrf_token_invalid', 'CSRF token is missing or invalid');
        }
    }
}
