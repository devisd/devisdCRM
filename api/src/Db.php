<?php

declare(strict_types=1);

namespace DevisCrm\Api;

use DevisCrm\Api\Exceptions\DatabaseUnavailableException;
use PDO;
use PDOException;

/**
 * Lazy PDO connection to MySQL. Configuration is read and the connection is opened
 * only on the first call to pdo()/ping(), never on construction.
 */
final class Db implements DatabaseProbe
{
    /** @var \Closure(): Config */
    private \Closure $configLoader;

    private ?PDO $pdo = null;

    /**
     * @param callable(): Config $configLoader
     */
    public function __construct(callable $configLoader)
    {
        $this->configLoader = \Closure::fromCallable($configLoader);
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $config = ($this->configLoader)();

        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new DatabaseUnavailableException('PDO MySQL driver (pdo_mysql) is not available');
        }

        try {
            $this->pdo = new PDO($config->dbDsn(), $config->dbUser(), $config->dbPassword(), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException | \ErrorException $exception) {
            // mysqlnd may raise a PHP warning (converted to ErrorException) before the PDOException.
            // Driver messages may contain the DB host or user name, so only codes are kept.
            throw new DatabaseUnavailableException('Database connection failed: ' . self::describe($exception));
        }

        return $this->pdo;
    }

    public function ping(): void
    {
        $pdo = $this->pdo();

        try {
            $statement = $pdo->query('SELECT 1');
            $value = $statement === false ? false : $statement->fetchColumn();
        } catch (PDOException | \ErrorException $exception) {
            $this->pdo = null;
            throw new DatabaseUnavailableException('Database ping failed: ' . self::describe($exception));
        }

        if ((string) $value !== '1') {
            throw new DatabaseUnavailableException('Database ping returned an unexpected result');
        }
    }

    private static function describe(PDOException | \ErrorException $exception): string
    {
        if ($exception instanceof \ErrorException) {
            return sprintf('PHP warning from the database driver (severity %d)', $exception->getSeverity());
        }

        $driverCode = is_array($exception->errorInfo) ? ($exception->errorInfo[1] ?? null) : null;

        return sprintf('SQLSTATE %s, driver code %s', (string) $exception->getCode(), $driverCode === null ? 'n/a' : (string) $driverCode);
    }
}
