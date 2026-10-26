<?php

declare(strict_types=1);

namespace DevisCrm\Api\Import;

use DevisCrm\Api\Clients\ClientValidator;
use DevisCrm\Api\Exceptions\ApiException;
use DevisCrm\Api\Orders\Money;
use DevisCrm\Api\Orders\OrderValidator;
use DevisCrm\Api\Validation\Fields;

/**
 * Checks a backup file exported by the app (exportBackup in src/lib/services/backupOps.ts,
 * backupFileSchema in src/lib/validation/schemas.ts) and turns it into rows for the server.
 *
 * Kept as in the file: ids, order numbers, texts, statuses, dates (completedAt included),
 * archive flags, quantities, prices, discounts and payments. Recomputed with Money:
 * line totals and order totals; paidMinor must equal the sum of the order's payments.
 * The file has no order counter: as the app's own restore, the next number is max + 1.
 *
 * Nothing is dropped or repaired: every problem is reported and blocks the import.
 * Reports name records by position and id, never by their texts.
 */
final class BackupAnalyzer
{
    public const APP_NAME = 'Devis Studio Work Tracker';
    public const BACKUP_VERSION = 1;

    /** Whole backup in one request; nginx and post_max_size must allow it as well. */
    public const MAX_BODY_BYTES = 8388608;

    private const MAX_REPORTED_ERRORS = 200;

    /** @var list<array{path: string, message: string}> */
    private array $errors = [];
    private int $errorCount = 0;

    /**
     * @param array<string, mixed> $backup Decoded JSON of the file.
     * @return array{
     *   errors: list<array{path: string, message: string}>, errorCount: int,
     *   counts: array{clients: int, orders: int, orderItems: int, payments: int},
     *   clients: list<array<string, mixed>>, orders: list<array<string, mixed>>,
     *   orderItems: list<array<string, mixed>>, payments: list<array<string, mixed>>,
     *   nextOrderNumber: int, moneyChanges: list<array<string, mixed>>
     * }
     */
    public static function analyze(array $backup): array
    {
        return (new self())->run($backup);
    }

    /**
     * @param array<string, mixed> $backup
     * @return array<string, mixed>
     */
    private function run(array $backup): array
    {
        $this->attempt('root', static fn () => Fields::rejectUnexpected($backup, ['app', 'backupVersion', 'exportedAt', 'data'], []));
        if (($backup['app'] ?? null) !== self::APP_NAME) {
            $this->error('app', 'is not a Devis Studio backup');
        }
        if (($backup['backupVersion'] ?? null) !== self::BACKUP_VERSION) {
            $this->error('backupVersion', 'is not a supported backup version');
        }
        $this->attempt('root', static fn () => Fields::isoDateTime('exportedAt', $backup['exportedAt'] ?? null));

        $data = $backup['data'] ?? null;
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            $this->error('data', 'must be an object');
            $data = [];
        }
        $this->attempt('data', static fn () => Fields::rejectUnexpected($data, ['clients', 'orders', 'orderItems', 'payments'], []));

        $clients = $this->records($data, 'clients', $this->client(...));
        $orders = $this->records($data, 'orders', $this->order(...));
        $items = $this->records($data, 'orderItems', $this->item(...));
        $payments = $this->records($data, 'payments', $this->payment(...));

        $this->unique($clients, 'clients', 'id');
        $this->unique($orders, 'orders', 'id');
        $this->unique($orders, 'orders', 'number');
        $this->unique($items, 'orderItems', 'id');
        $this->unique($payments, 'payments', 'id');

        // References are checked against every id present in the file: a record that is there
        // but invalid is already reported once, its children are not reported again.
        $clientIds = self::presentIds($data['clients'] ?? null);
        $orderIds = self::presentIds($data['orders'] ?? null);
        foreach ($orders as ['path' => $path, 'row' => $order]) {
            if (!isset($clientIds[$order['clientId']])) {
                $this->error($path . '.clientId', 'refers to a client that is not in the backup');
            }
        }
        foreach ([[$items, 'orderId'], [$payments, 'orderId']] as [$records, $field]) {
            foreach ($records as ['path' => $path, 'row' => $row]) {
                if (!isset($orderIds[$row[$field]])) {
                    $this->error($path . '.' . $field, 'refers to an order that is not in the backup');
                }
            }
        }

        [$orderRows, $moneyChanges] = $this->money($orders, $items, $payments, $data);

        $maxNumber = 0;
        foreach ($orderRows as $order) {
            $maxNumber = max($maxNumber, $order['number']);
        }
        if ($maxNumber >= Fields::MAX_UINT32) {
            $this->error('data.orders', 'order numbering could not continue after the highest number');
        }

        return [
            'errors' => $this->errors,
            'errorCount' => $this->errorCount,
            'counts' => array_map(
                static fn (mixed $list): int => is_array($list) ? count($list) : 0,
                ['clients' => $data['clients'] ?? null, 'orders' => $data['orders'] ?? null, 'orderItems' => $data['orderItems'] ?? null, 'payments' => $data['payments'] ?? null],
            ),
            'clients' => array_column($clients, 'row'),
            'orders' => $orderRows,
            'orderItems' => array_column($items, 'row'),
            'payments' => array_column($payments, 'row'),
            'nextOrderNumber' => $maxNumber + 1,
            'moneyChanges' => $moneyChanges,
        ];
    }

    /**
     * Recomputes line and order totals; checks payments against the new totals.
     *
     * @param list<array{path: string, row: array<string, mixed>}> $orders
     * @param list<array{path: string, row: array<string, mixed>}> $items Line totals are replaced in place.
     * @param list<array{path: string, row: array<string, mixed>}> $payments
     * @param array<string, mixed> $data
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function money(array $orders, array &$items, array $payments, array $data): array
    {
        $itemsByOrder = [];
        foreach ($items as $index => ['row' => $item]) {
            $itemsByOrder[$item['orderId']][] = $index;
        }
        // Orders with an unreadable item are not computed: their sums would be meaningless.
        $brokenOrders = [];
        $readable = array_flip(array_column($items, 'index'));
        foreach (is_array($data['orderItems'] ?? null) ? $data['orderItems'] : [] as $index => $raw) {
            if (!isset($readable[$index]) && is_array($raw) && is_string($raw['orderId'] ?? null)) {
                $brokenOrders[$raw['orderId']] = true;
            }
        }
        $paidByOrder = [];
        foreach ($payments as ['row' => $payment]) {
            $paidByOrder[$payment['orderId']] = ($paidByOrder[$payment['orderId']] ?? 0) + $payment['amountMinor'];
        }

        $rows = [];
        $changes = [];
        foreach ($orders as ['path' => $path, 'row' => $order]) {
            $indexes = $itemsByOrder[$order['id']] ?? [];
            if ($indexes === []) {
                $this->error($path, 'has no items (an order needs at least one)');
            }
            if ($indexes === [] || isset($brokenOrders[$order['id']])) {
                $rows[] = $order;
                continue;
            }

            try {
                $totals = Money::totals(array_map(static fn (int $i): array => $items[$i]['row'], $indexes), $order['discountMinor']);
            } catch (ApiException $exception) {
                $this->error($path, $exception->getMessage());
                $rows[] = $order;
                continue;
            }

            $lineChanges = [];
            foreach ($indexes as $position => $i) {
                $before = $items[$i]['row']['lineTotalMinor'];
                $after = $totals['lineTotals'][$position];
                if ($before !== $after) {
                    $lineChanges[] = ['itemId' => $items[$i]['row']['id'], 'lineTotalBefore' => $before, 'lineTotalAfter' => $after];
                }
                $items[$i]['row']['lineTotalMinor'] = $after;
            }

            $paid = $paidByOrder[$order['id']] ?? 0;
            if (!is_int($paid) || $paid > Fields::MAX_SAFE_INTEGER) {
                $this->error($path, 'payments add up to an amount that is too large');
            } elseif ($paid !== $order['paidMinor']) {
                $this->error($path . '.paidMinor', 'does not match the sum of the order\'s payments');
            } elseif ($paid > $totals['total']) {
                $this->error($path, sprintf('payments (%d) exceed the recalculated total (%d)', $paid, $totals['total']));
            }

            if ($lineChanges !== [] || $totals['total'] !== $order['totalMinor']) {
                $changes[] = [
                    'orderId' => $order['id'],
                    'number' => $order['number'],
                    'totalBefore' => $order['totalMinor'],
                    'totalAfter' => $totals['total'],
                    'items' => $lineChanges,
                ];
            }
            $rows[] = ['totalMinor' => $totals['total']] + $order;
        }
        usort($changes, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

        return [$rows, $changes];
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function client(array $client): array
    {
        foreach (['id', 'isArchived', 'createdAt', 'updatedAt'] as $required) {
            if (!array_key_exists($required, $client)) {
                throw Fields::invalid($required, 'is required');
            }
        }

        return ['id' => Fields::uuid('id', $client['id'])]
            + ClientValidator::forCreate(array_diff_key($client, ['id' => true, 'createdAt' => true, 'updatedAt' => true]))
            + ['createdAt' => Fields::isoDateTime('createdAt', $client['createdAt']), 'updatedAt' => Fields::isoDateTime('updatedAt', $client['updatedAt'])];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function order(array $order): array
    {
        Fields::rejectUnexpected($order, [
            'id', 'number', 'clientId', 'title', 'description', 'status', 'orderDate', 'dueDate', 'completedAt',
            'discountMinor', 'totalMinor', 'paidMinor', 'notes', 'isArchived', 'createdAt', 'updatedAt',
        ], []);

        return [
            'id' => Fields::uuid('id', $order['id'] ?? null),
            'number' => Fields::integer('number', $order['number'] ?? null, 1, Fields::MAX_UINT32),
            'clientId' => Fields::uuid('clientId', $order['clientId'] ?? null),
            'title' => Fields::requiredText('title', $order['title'] ?? null, 255),
            'description' => Fields::optionalText('description', $order['description'] ?? null, 20000),
            'status' => Fields::oneOf('status', $order['status'] ?? null, OrderValidator::STATUSES),
            'orderDate' => Fields::isoDateTime('orderDate', $order['orderDate'] ?? null),
            'dueDate' => Fields::optionalIsoDateTime('dueDate', $order['dueDate'] ?? null),
            'completedAt' => Fields::optionalIsoDateTime('completedAt', $order['completedAt'] ?? null),
            'discountMinor' => Fields::integer('discountMinor', $order['discountMinor'] ?? null, 0, Fields::MAX_SAFE_INTEGER),
            'totalMinor' => Fields::integer('totalMinor', $order['totalMinor'] ?? null, 0, Fields::MAX_SAFE_INTEGER),
            'paidMinor' => Fields::integer('paidMinor', $order['paidMinor'] ?? null, 0, Fields::MAX_SAFE_INTEGER),
            'notes' => Fields::optionalText('notes', $order['notes'] ?? null, 20000),
            'isArchived' => Fields::boolean('isArchived', $order['isArchived'] ?? null),
            'createdAt' => Fields::isoDateTime('createdAt', $order['createdAt'] ?? null),
            'updatedAt' => Fields::isoDateTime('updatedAt', $order['updatedAt'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function item(array $item): array
    {
        Fields::rejectUnexpected($item, ['id', 'orderId', 'name', 'description', 'quantity', 'unit', 'unitPriceMinor', 'lineTotalMinor', 'sortOrder'], []);
        $quantity = $item['quantity'] ?? null;
        if (!(is_int($quantity) || is_float($quantity)) || !is_finite((float) $quantity) || $quantity <= 0) {
            throw Fields::invalid('quantity', 'must be a number greater than zero');
        }

        return [
            'id' => Fields::uuid('id', $item['id'] ?? null),
            'orderId' => Fields::uuid('orderId', $item['orderId'] ?? null),
            'name' => Fields::requiredText('name', $item['name'] ?? null, 255),
            'description' => Fields::optionalText('description', $item['description'] ?? null, 20000),
            'quantity' => $quantity,
            'unit' => Fields::requiredText('unit', $item['unit'] ?? null, 64),
            'unitPriceMinor' => Fields::integer('unitPriceMinor', $item['unitPriceMinor'] ?? null, 0, Fields::MAX_SAFE_INTEGER),
            'lineTotalMinor' => Fields::integer('lineTotalMinor', $item['lineTotalMinor'] ?? null, 0, Fields::MAX_SAFE_INTEGER),
            'sortOrder' => Fields::integer('sortOrder', $item['sortOrder'] ?? null, 0, Fields::MAX_UINT32),
        ];
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function payment(array $payment): array
    {
        foreach (['id', 'orderId', 'createdAt'] as $required) {
            if (!array_key_exists($required, $payment)) {
                throw Fields::invalid($required, 'is required');
            }
        }

        return ['id' => Fields::uuid('id', $payment['id']), 'orderId' => Fields::uuid('orderId', $payment['orderId'])]
            + OrderValidator::payment(array_diff_key($payment, ['id' => true, 'orderId' => true, 'createdAt' => true]))
            + ['createdAt' => Fields::isoDateTime('createdAt', $payment['createdAt'])];
    }

    /**
     * Valid records of one list with their paths; invalid ones are reported.
     *
     * @param array<string, mixed> $data
     * @param callable(array<string, mixed>): array<string, mixed> $read
     * @return list<array{path: string, index: int, row: array<string, mixed>}>
     */
    private function records(array $data, string $key, callable $read): array
    {
        $list = $data[$key] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            $this->error('data.' . $key, 'must be a list');

            return [];
        }

        $records = [];
        foreach ($list as $index => $raw) {
            $path = sprintf('data.%s[%d]', $key, $index);
            if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
                $this->error($path, 'must be an object');
                continue;
            }
            $row = $this->attempt($path, static fn (): array => $read($raw));
            if ($row !== null) {
                $records[] = ['path' => $path, 'index' => $index, 'row' => $row];
            }
        }

        return $records;
    }

    /** @return array<string, int> */
    private static function presentIds(mixed $list): array
    {
        $ids = [];
        foreach (is_array($list) ? $list : [] as $record) {
            if (is_array($record) && is_string($record['id'] ?? null)) {
                $ids[$record['id']] = 1;
            }
        }

        return $ids;
    }

    /**
     * @param list<array{path: string, row: array<string, mixed>}> $records
     */
    private function unique(array $records, string $key, string $field): void
    {
        $seen = [];
        foreach ($records as ['path' => $path, 'row' => $row]) {
            $value = (string) $row[$field];
            if (isset($seen[$value])) {
                $this->error($path . '.' . $field, 'repeats the value of ' . $seen[$value]);
            } else {
                $seen[$value] = $path;
            }
        }
    }

    private function attempt(string $path, callable $check): mixed
    {
        try {
            return $check();
        } catch (ApiException $exception) {
            $this->error($path, $exception->getMessage());

            return null;
        }
    }

    private function error(string $path, string $message): void
    {
        $this->errorCount++;
        if (count($this->errors) < self::MAX_REPORTED_ERRORS) {
            $this->errors[] = ['path' => $path, 'message' => $message];
        }
    }
}
