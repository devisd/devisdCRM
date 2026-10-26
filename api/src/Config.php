<?php

declare(strict_types=1);

namespace DevisCrm\Api;

use DevisCrm\Api\Exceptions\ConfigException;

/**
 * Database settings loaded from a PHP file that returns an array
 * (see config/local.php.example). Never exposes values in exception messages.
 */
final class Config
{
    /** Marker used in config/local.php.example; such values are rejected. */
    public const PLACEHOLDER = '__CHANGE_ME__';

    private function __construct(
        private string $dbHost,
        private int $dbPort,
        private string $dbName,
        private string $dbUser,
        private string $dbPassword,
    ) {
    }

    public static function fromFile(string $path): self
    {
        // "@": an open_basedir restriction raises a warning, which must surface as ConfigException.
        if (!@is_file($path) || !@is_readable($path)) {
            throw new ConfigException('Configuration file is missing or unreadable: ' . $path);
        }

        try {
            $values = (static function (string $file): mixed {
                return require $file;
            })($path);
        } catch (\Throwable $exception) {
            // Parser messages may quote file contents (secrets), so keep only the error type.
            throw new ConfigException(sprintf('Configuration file could not be loaded (%s): %s', $exception::class, $path));
        }

        if (!is_array($values)) {
            throw new ConfigException('Configuration file must return an array: ' . $path);
        }

        return self::fromArray($values);
    }

    /**
     * @param array<mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $db = $values['db'] ?? null;
        if (!is_array($db)) {
            throw new ConfigException('Configuration section "db" is missing');
        }

        $host = self::requireDsnPart($db, 'host');
        $name = self::requireDsnPart($db, 'name');
        $user = self::requireString($db, 'user', false);
        $password = self::requireString($db, 'password', true);

        $port = $db['port'] ?? 3306;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new ConfigException('Configuration value db.port must be an integer between 1 and 65535');
        }

        return new self($host, $port, $name, $user, $password);
    }

    public function dbDsn(): string
    {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->dbHost, $this->dbPort, $this->dbName);
    }

    public function dbUser(): string
    {
        return $this->dbUser;
    }

    public function dbPassword(): string
    {
        return $this->dbPassword;
    }

    /**
     * Values interpolated into the DSN must not be able to add DSN options.
     *
     * @param array<mixed> $section
     */
    private static function requireDsnPart(array $section, string $key): string
    {
        $value = self::requireString($section, $key, false);
        if (preg_match('/[;\x00-\x1F\x7F]/', $value) === 1) {
            throw new ConfigException(sprintf('Configuration value db.%s contains forbidden characters', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $section
     */
    private static function requireString(array $section, string $key, bool $allowEmpty): string
    {
        $value = $section[$key] ?? null;
        if (!is_string($value)) {
            throw new ConfigException(sprintf('Configuration value db.%s must be a string', $key));
        }
        if (!$allowEmpty && trim($value) === '') {
            throw new ConfigException(sprintf('Configuration value db.%s must not be empty', $key));
        }
        if ($value === self::PLACEHOLDER) {
            throw new ConfigException(sprintf('Configuration value db.%s still contains the example placeholder', $key));
        }

        return $value;
    }
}
