<?php

declare(strict_types=1);

namespace DevisCrm\Api\Import;

use DevisCrm\Api\Exceptions\DatabaseUnavailableException;
use DevisCrm\Api\Exceptions\IntegrityConflictException;
use DevisCrm\Api\Orders\PdoOrderStore;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Import bookkeeping on existing tables (no migration needed):
 *   - app_meta row "backupImport" marks the completed import. It is inserted first in the
 *     importing transaction, so its primary key serializes concurrent imports and, once
 *     committed, refuses any later import, whatever the data tables contain;
 *   - the data tables must be empty, checked with locking reads (MySQL: FOR UPDATE takes
 *     next-key locks, so nothing can be inserted until the import commits).
 * The importing transaction itself is PdoOrderStore::transaction() on the same connection.
 */
final class PdoImportStore
{
    public const IMPORT_MARKER_KEY = 'backupImport';

    private const DATA_TABLES = ['clients', 'orders', 'order_items', 'payments'];

    /** @var \Closure(): PDO */
    private \Closure $pdo;

    /**
     * @param callable(): PDO $pdo Usually fn () => $db->pdo().
     */
    public function __construct(callable $pdo)
    {
        $this->pdo = \Closure::fromCallable($pdo);
    }

    public function isImported(): bool
    {
        return $this->run('SELECT 1 FROM app_meta WHERE meta_key = ?', [self::IMPORT_MARKER_KEY])->fetchColumn() !== false;
    }

    /** @param bool $lock Inside the importing transaction: keep the tables empty until commit. */
    public function isEmpty(bool $lock): bool
    {
        $suffix = $lock && $this->isMysql() ? ' FOR UPDATE' : '';
        foreach (self::DATA_TABLES as $table) {
            if ($this->run('SELECT 1 FROM ' . $table . ' LIMIT 1' . $suffix, [])->fetchColumn() !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $summary
     * @throws IntegrityConflictException when another import already holds or committed the marker.
     */
    public function markImported(array $summary): void
    {
        $this->run('INSERT INTO app_meta (meta_key, meta_value) VALUES (?, ?)', [
            self::IMPORT_MARKER_KEY,
            json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    /** Sets the order counter, never lowering an existing value. */
    public function setNextOrderNumber(int $next): void
    {
        $key = PdoOrderStore::NEXT_ORDER_NUMBER_KEY;
        $current = $this->run('SELECT meta_value FROM app_meta WHERE meta_key = ?' . ($this->isMysql() ? ' FOR UPDATE' : ''), [$key])->fetchColumn();
        if ($current === false) {
            $this->run('INSERT INTO app_meta (meta_key, meta_value) VALUES (?, ?)', [$key, (string) $next]);

            return;
        }
        $existing = trim((string) $current);
        $value = preg_match('/\A\d{1,10}\z/', $existing) === 1 ? max((int) $existing, $next) : $next;
        $this->run('UPDATE app_meta SET meta_value = ? WHERE meta_key = ?', [(string) $value, $key]);
    }

    private function isMysql(): bool
    {
        return ($this->pdo)()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /**
     * @param list<string|int|null> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $pdo = ($this->pdo)();
        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);

            return $statement;
        } catch (PDOException | \ErrorException $exception) {
            $driverCode = $exception instanceof PDOException && is_array($exception->errorInfo) ? ($exception->errorInfo[1] ?? null) : null;
            $description = sprintf('Import query failed: SQLSTATE %s, driver code %s', (string) $exception->getCode(), $driverCode === null ? 'n/a' : (string) $driverCode);
            if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                throw new IntegrityConflictException($description);
            }
            throw new DatabaseUnavailableException($description);
        }
    }
}
