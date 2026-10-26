<?php

declare(strict_types=1);

namespace DevisCrm\Api\Validation;

use DevisCrm\Api\Exceptions\ApiException;

/**
 * Field checks shared by the request validators, following src/lib/validation/schemas.ts:
 * strings are trimmed like JavaScript, lengths are Unicode code points (as MySQL VARCHAR(n)),
 * integers stay within Number.MAX_SAFE_INTEGER (z.number().int() of Zod 4), and timestamps
 * use the Date#toISOString() form stored as DATETIME(3) in UTC.
 * Every failure is a 400 validation_error naming the field, never echoing its value.
 */
final class Fields
{
    public const MAX_SAFE_INTEGER = 9007199254740991;

    /** Upper bound of MySQL INT UNSIGNED (MAX_UINT32 in schemas.ts). */
    public const MAX_UINT32 = 4294967295;

    /** String.prototype.trim(): WhiteSpace and LineTerminator of ECMAScript. */
    private const JS_WHITESPACE = '[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    private const ISO_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    private const UUID_PATTERN = '/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/';

    /** Trims like JavaScript; null when the value is not valid UTF-8. */
    public static function trim(string $value): ?string
    {
        $trimmed = preg_replace('/\A' . self::JS_WHITESPACE . '+|' . self::JS_WHITESPACE . '+\z/u', '', $value);

        return is_string($trimmed) ? $trimmed : null;
    }

    public static function length(string $text): int
    {
        return (int) preg_match_all('/./su', $text);
    }

    public static function isUuid(string $value): bool
    {
        return preg_match(self::UUID_PATTERN, $value) === 1;
    }

    /**
     * Rejects keys owned by the server and keys outside the allowed list (no mass assignment).
     *
     * @param array<array-key, mixed> $body
     * @param list<string> $allowed
     * @param list<string> $serverOwned
     */
    public static function rejectUnexpected(array $body, array $allowed, array $serverOwned, string $prefix = ''): void
    {
        foreach (array_keys($body) as $field) {
            $field = (string) $field;
            if (in_array($field, $serverOwned, true)) {
                throw new ApiException(400, 'validation_error', sprintf('Field "%s%s" is set by the server and cannot be changed', $prefix, $field));
            }
            if (!in_array($field, $allowed, true)) {
                throw new ApiException(400, 'validation_error', 'Unknown field in request body');
            }
        }
    }

    public static function requiredText(string $field, mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            throw self::invalid($field, 'must be a string');
        }
        $text = self::trim($value);
        if ($text === null || $text === '') {
            throw self::invalid($field, 'is required');
        }
        if (self::length($text) > $maxLength) {
            throw self::invalid($field, 'is too long');
        }

        return $text;
    }

    /** null or a blank string means "absent". */
    public static function optionalText(string $field, mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw self::invalid($field, 'must be a string or null');
        }
        $text = self::trim($value);
        if ($text === null) {
            throw self::invalid($field, 'must be valid text');
        }
        if ($text === '') {
            return null;
        }
        if (self::length($text) > $maxLength) {
            throw self::invalid($field, 'is too long');
        }

        return $text;
    }

    /** JSON integer (an integral float such as 1e2 is accepted, as by Zod) within the range. */
    public static function integer(string $field, mixed $value, int $min, int $max): int
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= self::MAX_SAFE_INTEGER) {
            $value = (int) $value;
        }
        if (!is_int($value)) {
            throw self::invalid($field, 'must be an integer');
        }
        if ($value < $min || $value > $max) {
            throw self::invalid($field, 'is out of range');
        }

        return $value;
    }

    public static function boolean(string $field, mixed $value): bool
    {
        return is_bool($value) ? $value : throw self::invalid($field, 'must be a boolean');
    }

    /**
     * @param list<string> $allowed
     */
    public static function oneOf(string $field, mixed $value, array $allowed): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : throw self::invalid($field, 'has an unsupported value');
    }

    public static function uuid(string $field, mixed $value): string
    {
        return is_string($value) && self::isUuid($value) ? $value : throw self::invalid($field, 'must be a UUID');
    }

    /** "2026-10-26T09:00:00.000Z": the form produced by Date#toISOString(), years 1000..9999. */
    public static function isoDateTime(string $field, mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[1-9]\d{3}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/', $value) !== 1) {
            throw self::invalid($field, 'must be an ISO-8601 UTC timestamp');
        }
        $time = \DateTimeImmutable::createFromFormat('!' . self::ISO_FORMAT, $value, new \DateTimeZone('UTC'));
        if ($time === false || $time->format(self::ISO_FORMAT) !== $value) {
            throw self::invalid($field, 'must be an ISO-8601 UTC timestamp');
        }

        return $value;
    }

    /** null or a blank string means "absent". */
    public static function optionalIsoDateTime(string $field, mixed $value): ?string
    {
        if ($value === null || (is_string($value) && self::trim($value) === '')) {
            return null;
        }

        return self::isoDateTime($field, is_string($value) ? self::trim($value) : $value);
    }

    public static function invalid(string $field, string $problem): ApiException
    {
        return new ApiException(400, 'validation_error', sprintf('Field "%s" %s', $field, $problem));
    }
}
