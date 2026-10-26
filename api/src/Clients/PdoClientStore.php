<?php

declare(strict_types=1);

namespace DevisCrm\Api\Clients;

use DevisCrm\Api\Exceptions\DatabaseUnavailableException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * ClientStore on the `clients` table of 001_init.sql. Prepared statements only;
 * DATETIME(3) values in UTC. Errors keep only SQLSTATE and the driver code
 * (no SQL, no parameters, no client data).
 */
final class PdoClientStore implements ClientStore
{
    private const DB_TIME_FORMAT = 'Y-m-d H:i:s.v';
    private const ISO_TIME_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    /** Model field => column, for every field except id, created_at and updated_at. */
    private const COLUMNS = [
        'name' => 'name',
        'contactName' => 'contact_name',
        'phone' => 'phone',
        'email' => 'email',
        'telegram' => 'telegram',
        'maxContact' => 'max_contact',
        'otherContact' => 'other_contact',
        'source' => 'source',
        'notes' => 'notes',
        'tags' => 'tags',
        'isArchived' => 'is_archived',
    ];

    private const SELECT = 'SELECT id, name, contact_name, phone, email, telegram, max_contact, other_contact, source, notes,'
        . ' tags, is_archived, created_at, updated_at FROM clients';

    /** @var \Closure(): PDO */
    private \Closure $pdo;

    /**
     * @param callable(): PDO $pdo Usually fn () => $db->pdo().
     */
    public function __construct(callable $pdo)
    {
        $this->pdo = \Closure::fromCallable($pdo);
    }

    public function all(): array
    {
        $rows = $this->run(self::SELECT . ' ORDER BY name, id', [])->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::fromRow(...), $rows);
    }

    public function find(string $id): ?array
    {
        $row = $this->run(self::SELECT . ' WHERE id = ? LIMIT 1', [$id])->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::fromRow($row) : null;
    }

    public function insert(array $client): void
    {
        $values = self::columnValues($client);
        $this->run(
            'INSERT INTO clients (id, ' . implode(', ', array_keys($values)) . ', created_at, updated_at)'
            . ' VALUES (?, ' . implode(', ', array_fill(0, count($values), '?')) . ', ?, ?)',
            [(string) $client['id'], ...array_values($values), self::toDb((string) $client['createdAt']), self::toDb((string) $client['updatedAt'])],
        );
    }

    public function update(array $client): ?array
    {
        $values = self::columnValues($client);
        $assignments = array_map(static fn (string $column): string => $column . ' = ?', array_keys($values));
        $this->run(
            'UPDATE clients SET ' . implode(', ', $assignments) . ', updated_at = ? WHERE id = ?',
            [...array_values($values), self::toDb((string) $client['updatedAt']), (string) $client['id']],
        );

        return $this->find((string) $client['id']);
    }

    public function deleteIfNoOrders(string $id): ClientDeletion
    {
        try {
            $deleted = $this->run(
                'DELETE FROM clients WHERE id = ? AND NOT EXISTS (SELECT 1 FROM orders WHERE client_id = ?)',
                [$id, $id],
                rethrowIntegrity: true,
            )->rowCount();
        } catch (PDOException) {
            // fk_orders_client (ON DELETE RESTRICT): an order was added concurrently.
            return ClientDeletion::HasOrders;
        }
        if ($deleted > 0) {
            return ClientDeletion::Deleted;
        }

        return $this->find($id) === null ? ClientDeletion::NotFound : ClientDeletion::HasOrders;
    }

    /**
     * Column => value for the writable fields, in COLUMNS order.
     *
     * @param array<string, mixed> $client
     * @return array<string, string|int|null>
     */
    private static function columnValues(array $client): array
    {
        $values = [];
        foreach (self::COLUMNS as $field => $column) {
            $value = $client[$field] ?? null;
            $values[$column] = match ($field) {
                'tags' => $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'isArchived' => $value === true ? 1 : 0,
                default => $value === null ? null : (string) $value,
            };
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function fromRow(array $row): array
    {
        $client = ['id' => (string) $row['id']];
        foreach (self::COLUMNS as $field => $column) {
            $value = $row[$column];
            $client[$field] = match ($field) {
                'tags' => $value === null ? null : self::decodeTags((string) $value),
                'isArchived' => (int) $value === 1,
                default => $value === null ? null : (string) $value,
            };
        }
        $client['createdAt'] = self::fromDb((string) $row['created_at']);
        $client['updatedAt'] = self::fromDb((string) $row['updated_at']);

        return $client;
    }

    /**
     * @return list<string>
     */
    private static function decodeTags(string $json): array
    {
        try {
            $tags = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $tags = null;
        }
        if (!is_array($tags) || !array_is_list($tags) || array_filter($tags, 'is_string') !== $tags) {
            throw new DatabaseUnavailableException('Client store returned unreadable tags');
        }

        return $tags;
    }

    /**
     * @param list<string|int|null> $params
     */
    private function run(string $sql, array $params, bool $rethrowIntegrity = false): PDOStatement
    {
        $pdo = ($this->pdo)();
        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);

            return $statement;
        } catch (PDOException | \ErrorException $exception) {
            if ($rethrowIntegrity && $exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                throw $exception;
            }
            $driverCode = $exception instanceof PDOException && is_array($exception->errorInfo) ? ($exception->errorInfo[1] ?? null) : null;
            throw new DatabaseUnavailableException(sprintf(
                'Client query failed: SQLSTATE %s, driver code %s',
                (string) $exception->getCode(),
                $driverCode === null ? 'n/a' : (string) $driverCode,
            ));
        }
    }

    private static function toDb(string $isoTime): string
    {
        $time = \DateTimeImmutable::createFromFormat('!' . self::ISO_TIME_FORMAT, $isoTime, new \DateTimeZone('UTC'));
        if ($time === false) {
            throw new \InvalidArgumentException('Client timestamps must use ' . self::ISO_TIME_FORMAT);
        }

        return $time->format(self::DB_TIME_FORMAT);
    }

    private static function fromDb(string $value): string
    {
        $utc = new \DateTimeZone('UTC');
        $time = \DateTimeImmutable::createFromFormat('!' . self::DB_TIME_FORMAT, $value, $utc)
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);
        if ($time === false) {
            throw new DatabaseUnavailableException('Client store returned an unreadable timestamp');
        }

        return $time->format(self::ISO_TIME_FORMAT);
    }
}
