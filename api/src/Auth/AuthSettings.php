<?php

declare(strict_types=1);

namespace DevisCrm\Api\Auth;

/** Fixed authentication policy. No secrets here: tokens are random per session. */
final class AuthSettings
{
    public const COOKIE_NAME = '__Host-devis_session';

    /** The only origin allowed to send state-changing requests (the CRM frontend). */
    public const ALLOWED_ORIGIN = 'https://crm.devisd.online';

    /** A session ends this long after login, however active it is. */
    public const ABSOLUTE_LIFETIME_SECONDS = 14 * 24 * 3600;

    /** A session ends after this long without requests. */
    public const IDLE_TIMEOUT_SECONDS = 48 * 3600;

    /** last_seen_at is written at most this often. */
    public const TOUCH_INTERVAL_SECONDS = 300;

    public const MAX_FAILED_ATTEMPTS = 5;
    public const ATTEMPT_WINDOW_SECONDS = 15 * 60;

    /** Login attempt rows older than this are purged. */
    public const ATTEMPT_RETENTION_SECONDS = 24 * 3600;

    public const MAX_LOGIN_LENGTH = 64;
    public const MAX_PASSWORD_BYTES = 1024;
}
