<?php

declare(strict_types=1);

namespace DevisCrm\Api\Controllers;

use DevisCrm\Api\Auth\AuthService;
use DevisCrm\Api\Auth\AuthSession;
use DevisCrm\Api\Auth\AuthSettings;
use DevisCrm\Api\Auth\SessionCookie;
use DevisCrm\Api\Exceptions\ApiException;
use DevisCrm\Api\Http\JsonResponse;
use DevisCrm\Api\Http\Request;

/**
 * POST /api/auth/login (public, Origin-checked), POST /api/auth/logout (session + CSRF),
 * GET /api/auth/me (session). Responses never contain passwords, hashes or session tokens.
 */
final class AuthController
{
    public function __construct(private AuthService $auth)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $body = $request->jsonBody();
        $login = $body['login'] ?? null;
        $password = $body['password'] ?? null;
        if (!is_string($login) || !is_string($password)) {
            throw self::invalidInput();
        }

        $normalized = AuthService::normalizeLogin($login);
        if (
            $normalized === ''
            || preg_match('//u', $normalized) !== 1
            || preg_match_all('/./us', $normalized) > AuthSettings::MAX_LOGIN_LENGTH
            || $password === ''
            || strlen($password) > AuthSettings::MAX_PASSWORD_BYTES
        ) {
            throw self::invalidInput();
        }

        $result = $this->auth->login($normalized, $password, $request->remoteAddress());

        // A previous session in this browser is ended, not reused.
        $previous = $request->cookie(AuthSettings::COOKIE_NAME);
        if ($previous !== null) {
            $this->auth->revokeToken($previous);
        }

        return JsonResponse::success(self::sessionData($result->session))
            ->withAddedHeader('Set-Cookie', SessionCookie::issue($result->token, $result->session->expiresAt, $result->session->createdAt));
    }

    public function logout(Request $request, AuthSession $session): JsonResponse
    {
        $this->auth->logout($session);

        return JsonResponse::success([])->withAddedHeader('Set-Cookie', SessionCookie::clear());
    }

    public function me(Request $request, AuthSession $session): JsonResponse
    {
        return JsonResponse::success(self::sessionData($session));
    }

    /**
     * @return array{user: array{login: string}, csrfToken: string}
     */
    private static function sessionData(AuthSession $session): array
    {
        return ['user' => ['login' => $session->login], 'csrfToken' => $session->csrfToken];
    }

    private static function invalidInput(): ApiException
    {
        return new ApiException(400, 'validation_error', 'Login and password are required');
    }
}
