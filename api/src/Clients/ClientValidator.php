<?php

declare(strict_types=1);

namespace DevisCrm\Api\Clients;

use DevisCrm\Api\Validation\Fields;

/**
 * Server-side rules of the client model, the same as clientSchema in
 * src/lib/validation/schemas.ts and the `clients` table of 001_init.sql:
 *   - name: required, trimmed, 1..255 characters;
 *   - other text fields: optional, trimmed, an empty value means "absent" (NULL);
 *   - email: additionally the e-mail pattern of Zod;
 *   - tags: optional list of non-empty trimmed strings;
 *   - isArchived: boolean.
 * Lengths are counted in Unicode code points, like MySQL VARCHAR(n) and Zod 4.
 * id, createdAt and updatedAt belong to the server; any other key is rejected.
 */
final class ClientValidator
{
    /**
     * Two 20 000-character fields may take up to 6 bytes per character in JSON (\u00XX
     * escapes), about 240 000 bytes, plus the short fields; the 16 KiB default is too small.
     */
    public const MAX_BODY_BYTES = 262144;

    public const SHORT_TEXT = 255;
    public const LONG_TEXT = 20000;

    /** Optional text fields and their maximum length. */
    private const OPTIONAL_TEXT = [
        'contactName' => self::SHORT_TEXT,
        'phone' => self::SHORT_TEXT,
        'email' => self::SHORT_TEXT,
        'telegram' => self::SHORT_TEXT,
        'maxContact' => self::SHORT_TEXT,
        'otherContact' => self::LONG_TEXT,
        'source' => self::SHORT_TEXT,
        'notes' => self::LONG_TEXT,
    ];

    private const SERVER_FIELDS = ['id', 'createdAt', 'updatedAt'];

    /** z.string().email() of Zod 4 (zod/v4/core/regexes.js). */
    private const EMAIL_PATTERN = "/\\A(?:[A-Za-z0-9_'+\\-]+\\.)*[A-Za-z0-9_'+\\-]*[A-Za-z0-9_+-]@(?:[A-Za-z0-9][A-Za-z0-9\\-]*\\.)+[A-Za-z]{2,}\\z/";

    /**
     * Every writable field: name, the optional texts (null when absent), tags, isArchived.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function forCreate(array $body): array
    {
        if (!array_key_exists('name', $body)) {
            self::rejectUnexpected($body);
            throw Fields::invalid('name', 'is required');
        }

        return self::forPatch($body) + array_fill_keys(array_keys(self::OPTIONAL_TEXT), null) + ['tags' => null, 'isArchived' => false];
    }

    /**
     * Only the fields present in the body. null or a blank string clears an optional field.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function forPatch(array $body): array
    {
        self::rejectUnexpected($body);

        $values = [];
        foreach ($body as $field => $value) {
            $values[$field] = match ($field) {
                'name' => Fields::requiredText('name', $value, self::SHORT_TEXT),
                'tags' => self::tags($value),
                'isArchived' => Fields::boolean($field, $value),
                'email' => self::email($value),
                default => Fields::optionalText($field, $value, self::OPTIONAL_TEXT[$field]),
            };
        }

        return $values;
    }

    /** Trims like JavaScript; null when the value is not valid UTF-8. */
    public static function trim(string $value): ?string
    {
        return Fields::trim($value);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function rejectUnexpected(array $body): void
    {
        Fields::rejectUnexpected($body, ['name', 'tags', 'isArchived', ...array_keys(self::OPTIONAL_TEXT)], self::SERVER_FIELDS);
    }

    private static function email(mixed $value): ?string
    {
        $email = Fields::optionalText('email', $value, self::SHORT_TEXT);
        if ($email !== null && preg_match(self::EMAIL_PATTERN, $email) !== 1) {
            throw Fields::invalid('email', 'must be a valid e-mail address');
        }

        return $email;
    }

    /**
     * @return list<string>|null
     */
    private static function tags(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw Fields::invalid('tags', 'must be a list of strings or null');
        }

        $tags = [];
        foreach ($value as $tag) {
            $trimmed = is_string($tag) ? Fields::trim($tag) : null;
            if ($trimmed === null || $trimmed === '') {
                throw Fields::invalid('tags', 'must contain only non-empty strings');
            }
            $tags[] = $trimmed;
        }

        return $tags;
    }
}
