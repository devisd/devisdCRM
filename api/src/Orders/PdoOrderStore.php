<?php

declare(strict_types=1);

namespace DevisCrm\Api\Orders;

use DevisCrm\Api\Exceptions\DatabaseUnavailableException;
use DevisCrm\Api\Exceptions\IntegrityConflictException;
use DevisCrm\Api\Validation\Fields;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Orders, order items, payments and the order number counter on the tables of 001_init.sql.
 * Prepared statements only; DATETIME(3) values in UTC. Errors keep only SQLSTATE and the
 * driver code (no SQL, no parameters, no order data).
 *
 * Locking (MySQL/InnoDB; SQLite, used in tests, locks the whole database per write instead):
 *   app_meta counter row -> client row (shared) -> order row -> its items and payments.
 * Every change of an order's money takes the order row first, so concurrent payments and
 * edits of the same order are serialized.
 */
final class PdoOrderStore
{
    public const NEXT_ORDER_NUMBER_KEY = 'nextOrderNumber';

    private const DB_TIME_FORMAT = 'Y-m-d H:i:s.v';
    private const ISO_TIME_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    private const ORDER_COLUMNS = 'id, number, client_id, title, description, status, order_date, due_date, completed_at,'
        . ' discount_minor, total_minor, paid_minor, notes, is_archived, created_at, updated_at';
    private const ITEM_COLUMNS = 'id, order_id, name, description, quantity, unit, unit_price_minor, line_total_minor, sort_order';
    private const PAYMENT_COLUMNS = 'id, order_id, amount_minor, payment_date, method, comment, created_at';

    /** @var \Closure(): PDO */
    private \Closure $pdo;

    /**
     * @param callable(): PDO $pdo Usually fn () => $db->pdo().
     */
    public function __construct(callable $pdo)
    {
        $this->pdo = \Closure::fromCallable($pdo);
    }

    /**
     * Runs $work in one transaction: committed when it returns, rolled back when it throws.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $pdo = ($this->pdo)();
        try {
            $pdo->beginTransaction();
        } catch (PDOException $exception) {
            throw self::unavailable($exception);
        }

        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (PDOException) {
                    // The original failure is the one worth reporting.
                }
            }
            if ($exception instanceof PDOException) {
                throw self::unavailable($exception);
            }
            throw $exception;
        }
    }

    /**
     * Takes the next order number and advances the counter (a missing counter means 1, as in
     * src/lib/repositories/orders.ts). Must run inside transaction(): the counter row stays
     * locked until commit, and a rollback returns the number.
     */
    public function allocateOrderNumber(): int
    {
        $upsert = $this->isMysql()
            // Takes an exclusive lock on the counter row whether it exists or not.
            ? 'INSERT INTO app_meta (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_key = meta_key'
            : 'INSERT INTO app_meta (meta_key, meta_value) VALUES (?, ?) ON CONFLICT (meta_key) DO NOTHING';
        $this->run($upsert, [self::NEXT_ORDER_NUMBER_KEY, '1']);

        $value = $this->run('SELECT meta_value FROM app_meta WHERE meta_key = ?' . $this->forUpdate(), [self::NEXT_ORDER_NUMBER_KEY])->fetchColumn();
        $number = is_string($value) || is_int($value) ? trim((string) $value) : '';
        if (preg_match('/\A[1-9]\d{0,9}\z/', $number) !== 1 || (int) $number > Fields::MAX_UINT32) {
            throw new DatabaseUnavailableException('Order number counter holds an unusable value');
        }

        $this->run('UPDATE app_meta SET meta_value = ? WHERE meta_key = ?', [(string) ((int) $number + 1), self::NEXT_ORDER_NUMBER_KEY]);

        return (int) $number;
    }

    /** Locks the client row (shared) so that it cannot be deleted before the order is saved. */
    public function lockClient(string $clientId): bool
    {
        return $this->run('SELECT id FROM clients WHERE id = ?' . $this->forShare(), [$clientId])->fetchColumn() !== false;
    }

    /** @return array<string, mixed>|null */
    public function lockOrder(string $id): ?array
    {
        $row = $this->run('SELECT ' . self::ORDER_COLUMNS . ' FROM orders WHERE id = ?' . $this->forUpdate(), [$id])->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::orderFromRow($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findOrder(string $id): ?array
    {
        $row = $this->run('SELECT ' . self::ORDER_COLUMNS . ' FROM orders WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::orderFromRow($row) : null;
    }

    /**
     * All orders, archived included, newest order date first.
     *
     * @return list<array<string, mixed>>
     */
    public function allOrders(): array
    {
        $rows = $this->run('SELECT ' . self::ORDER_COLUMNS . ' FROM orders ORDER BY order_date DESC, id DESC', [])->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::orderFromRow(...), $rows);
    }

    /** @return list<array<string, mixed>> */
    public function items(string $orderId): array
    {
        $rows = $this->run('SELECT ' . self::ITEM_COLUMNS . ' FROM order_items WHERE order_id = ? ORDER BY sort_order, id', [$orderId])->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::itemFromRow(...), $rows);
    }

    /**
     * Newest payment date first.
     *
     * @return list<array<string, mixed>>
     */
    public function payments(string $orderId): array
    {
        $rows = $this->run('SELECT ' . self::PAYMENT_COLUMNS . ' FROM payments WHERE order_id = ? ORDER BY payment_date DESC, id DESC', [$orderId])->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::paymentFromRow(...), $rows);
    }

    /**
     * Every payment, newest payment date first (ties by id), as payments() orders them.
     *
     * @return list<array<string, mixed>>
     */
    public function allPayments(): array
    {
        $rows = $this->run('SELECT ' . self::PAYMENT_COLUMNS . ' FROM payments ORDER BY payment_date DESC, id DESC', [])->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::paymentFromRow(...), $rows);
    }

    /** Sum of the order's payments, read with a lock so that it is current, not a snapshot. */
    public function paidTotal(string $orderId): int
    {
        return (int) $this->run('SELECT COALESCE(SUM(amount_minor), 0) FROM payments WHERE order_id = ?' . $this->forShare(), [$orderId])->fetchColumn();
    }

    /** @param array<string, mixed> $order */
    public function insertOrder(array $order): void
    {
        $this->run(
            'INSERT INTO orders (' . self::ORDER_COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $order['id'], $order['number'], ...self::orderValues($order),
                self::toDb($order['createdAt']), self::toDb($order['updatedAt']),
            ],
        );
    }

    /**
     * Saves every field except id, number and createdAt.
     *
     * @param array<string, mixed> $order
     */
    public function updateOrder(array $order): void
    {
        $this->run(
            'UPDATE orders SET client_id = ?, title = ?, description = ?, status = ?, order_date = ?, due_date = ?, completed_at = ?,'
            . ' discount_minor = ?, total_minor = ?, paid_minor = ?, notes = ?, is_archived = ?, updated_at = ? WHERE id = ?',
            [...self::orderValues($order), self::toDb($order['updatedAt']), $order['id']],
        );
    }

    /**
     * Replaces all items of the order (updateOrder in orders.ts deletes and re-adds them).
     *
     * @param list<array<string, mixed>> $items
     */
    public function replaceItems(string $orderId, array $items): void
    {
        $this->run('DELETE FROM order_items WHERE order_id = ?', [$orderId]);
        $this->insertItems($items);
    }

    /** @param list<array<string, mixed>> $items */
    public function insertItems(array $items): void
    {
        foreach ($items as $item) {
            $this->run(
                'INSERT INTO order_items (' . self::ITEM_COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $item['id'], $item['orderId'], $item['name'], $item['description'], (float) $item['quantity'], $item['unit'],
                    $item['unitPriceMinor'], $item['lineTotalMinor'], $item['sortOrder'],
                ],
            );
        }
    }

    /** @param array<string, mixed> $payment */
    public function insertPayment(array $payment): void
    {
        $this->run(
            'INSERT INTO payments (' . self::PAYMENT_COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $payment['id'], $payment['orderId'], $payment['amountMinor'], self::toDb($payment['paymentDate']),
                $payment['method'], $payment['comment'], self::toDb($payment['createdAt']),
            ],
        );
    }

    /**
     * client_id .. is_archived, in ORDER_COLUMNS order.
     *
     * @param array<string, mixed> $order
     * @return list<string|int|null>
     */
    private static function orderValues(array $order): array
    {
        return [
            $order['clientId'], $order['title'], $order['description'], $order['status'],
            self::toDb($order['orderDate']), self::toDbOrNull($order['dueDate']), self::toDbOrNull($order['completedAt']),
            $order['discountMinor'], $order['totalMinor'], $order['paidMinor'], $order['notes'], $order['isArchived'] ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function orderFromRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'number' => (int) $row['number'],
            'clientId' => (string) $row['client_id'],
            'title' => (string) $row['title'],
            'description' => self::textOrNull($row['description']),
            'status' => (string) $row['status'],
            'orderDate' => self::fromDb((string) $row['order_date']),
            'dueDate' => $row['due_date'] === null ? null : self::fromDb((string) $row['due_date']),
            'completedAt' => $row['completed_at'] === null ? null : self::fromDb((string) $row['completed_at']),
            'discountMinor' => (int) $row['discount_minor'],
            'totalMinor' => (int) $row['total_minor'],
            'paidMinor' => (int) $row['paid_minor'],
            'notes' => self::textOrNull($row['notes']),
            'isArchived' => (int) $row['is_archived'] === 1,
            'createdAt' => self::fromDb((string) $row['created_at']),
            'updatedAt' => self::fromDb((string) $row['updated_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function itemFromRow(array $row): array
    {
        $quantity = (float) $row['quantity'];

        return [
            'id' => (string) $row['id'],
            'orderId' => (string) $row['order_id'],
            'name' => (string) $row['name'],
            'description' => self::textOrNull($row['description']),
            // Whole quantities as JSON integers, like JSON.stringify in the browser.
            'quantity' => floor($quantity) === $quantity && $quantity <= 9007199254740991 ? (int) $quantity : $quantity,
            'unit' => (string) $row['unit'],
            'unitPriceMinor' => (int) $row['unit_price_minor'],
            'lineTotalMinor' => (int) $row['line_total_minor'],
            'sortOrder' => (int) $row['sort_order'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function paymentFromRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'orderId' => (string) $row['order_id'],
            'amountMinor' => (int) $row['amount_minor'],
            'paymentDate' => self::fromDb((string) $row['payment_date']),
            'method' => (string) $row['method'],
            'comment' => self::textOrNull($row['comment']),
            'createdAt' => self::fromDb((string) $row['created_at']),
        ];
    }

    private function isMysql(): bool
    {
        return ($this->pdo)()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    private function forUpdate(): string
    {
        return $this->isMysql() ? ' FOR UPDATE' : '';
    }

    private function forShare(): string
    {
        return $this->isMysql() ? ' FOR SHARE' : '';
    }

    /**
     * @param list<string|int|float|null> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $pdo = ($this->pdo)();
        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);

            return $statement;
        } catch (PDOException | \ErrorException $exception) {
            if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                throw new IntegrityConflictException(self::describe($exception));
            }
            throw self::unavailable($exception);
        }
    }

    private static function unavailable(PDOException | \ErrorException $exception): DatabaseUnavailableException
    {
        return new DatabaseUnavailableException(self::describe($exception));
    }

    private static function describe(PDOException | \ErrorException $exception): string
    {
        $driverCode = $exception instanceof PDOException && is_array($exception->errorInfo) ? ($exception->errorInfo[1] ?? null) : null;

        return sprintf('Order query failed: SQLSTATE %s, driver code %s', (string) $exception->getCode(), $driverCode === null ? 'n/a' : (string) $driverCode);
    }

    private static function textOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function toDb(string $isoTime): string
    {
        $time = \DateTimeImmutable::createFromFormat('!' . self::ISO_TIME_FORMAT, $isoTime, new \DateTimeZone('UTC'));
        if ($time === false) {
            throw new \InvalidArgumentException('Order timestamps must use ' . self::ISO_TIME_FORMAT);
        }

        return $time->format(self::DB_TIME_FORMAT);
    }

    private static function toDbOrNull(?string $isoTime): ?string
    {
        return $isoTime === null ? null : self::toDb($isoTime);
    }

    private static function fromDb(string $value): string
    {
        $utc = new \DateTimeZone('UTC');
        $time = \DateTimeImmutable::createFromFormat('!' . self::DB_TIME_FORMAT, $value, $utc)
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);
        if ($time === false) {
            throw new DatabaseUnavailableException('Order store returned an unreadable timestamp');
        }

        return $time->format(self::ISO_TIME_FORMAT);
    }
}
