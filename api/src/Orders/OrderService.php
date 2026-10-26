<?php

declare(strict_types=1);

namespace DevisCrm\Api\Orders;

use DevisCrm\Api\Auth\Clock;
use DevisCrm\Api\Exceptions\ApiException;
use DevisCrm\Api\Exceptions\IntegrityConflictException;
use DevisCrm\Api\Uuid;
use DevisCrm\Api\Validation\Fields;

/**
 * Order rules of src/lib/repositories/orders.ts with the server as the source of truth:
 *   - numbers come from the app_meta counter inside the creating transaction;
 *   - line totals, total and paid amount are always computed here, never taken from the request;
 *   - an edit replaces all items and may not bring the total below the payments made;
 *   - payments are only added, never above the remaining amount;
 *   - completedAt is derived from the status: set to the server time when an order becomes
 *     completed, kept while it stays completed, cleared otherwise;
 *   - orders are archived, not deleted.
 * Input arrives validated by OrderValidator.
 */
final class OrderService
{
    public function __construct(
        private PdoOrderStore $store,
        private Clock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->store->allOrders();
    }

    /** @return array{order: array<string, mixed>, items: list<array<string, mixed>>, payments: list<array<string, mixed>>} */
    public function get(string $id): array
    {
        return $this->store->transaction(function () use ($id): array {
            $order = $this->store->findOrder($id) ?? throw self::notFound();

            return ['order' => $order, 'items' => $this->store->items($id), 'payments' => $this->store->payments($id)];
        });
    }

    /** @return list<array<string, mixed>> */
    public function allPayments(): array
    {
        return $this->store->allPayments();
    }

    /** @return list<array<string, mixed>> */
    public function payments(string $orderId): array
    {
        return $this->store->transaction(function () use ($orderId): array {
            $this->store->findOrder($orderId) ?? throw self::notFound();

            return $this->store->payments($orderId);
        });
    }

    /**
     * @param array<string, mixed> $input OrderValidator::forCreate()
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>}
     */
    public function create(array $input): array
    {
        $money = Money::totals($input['items'], $input['discountMinor']);
        $now = $this->now();
        $orderId = Uuid::v4();

        return $this->write(function () use ($input, $money, $now, $orderId): array {
            $number = $this->store->allocateOrderNumber();
            if (!$this->store->lockClient($input['clientId'])) {
                throw self::clientNotFound();
            }

            $this->store->insertOrder([
                'id' => $orderId,
                'number' => $number,
                'clientId' => $input['clientId'],
                'title' => $input['title'],
                'description' => $input['description'],
                'status' => $input['status'],
                'orderDate' => $input['orderDate'],
                'dueDate' => $input['dueDate'],
                'completedAt' => $input['status'] === 'completed' ? $now : null,
                'discountMinor' => $input['discountMinor'],
                'totalMinor' => $money['total'],
                'paidMinor' => 0,
                'notes' => $input['notes'],
                'isArchived' => false,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
            $this->store->insertItems(self::buildItems($orderId, $input['items'], $money['lineTotals']));

            return $this->saved($orderId);
        });
    }

    /**
     * @param array<string, mixed> $changes OrderValidator::forPatch()
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>}
     */
    public function update(string $id, array $changes): array
    {
        $now = $this->now();

        return $this->write(function () use ($id, $changes, $now): array {
            $existing = $this->store->lockOrder($id) ?? throw self::notFound();
            if (isset($changes['clientId']) && !$this->store->lockClient($changes['clientId'])) {
                throw self::clientNotFound();
            }

            $currentItems = $this->store->items($id);
            $nextItems = $changes['items'] ?? $currentItems;
            if (isset($changes['items'])) {
                $currentIds = array_column($currentItems, 'id');
                foreach ($nextItems as $index => $item) {
                    if ($item['id'] !== null && !in_array($item['id'], $currentIds, true)) {
                        throw Fields::invalid('items[' . $index . '].id', 'is not an item of this order');
                    }
                }
            }

            $discountMinor = $changes['discountMinor'] ?? $existing['discountMinor'];
            $money = Money::totals($nextItems, $discountMinor);
            $paidMinor = $this->store->paidTotal($id);
            if ($paidMinor > $money['total']) {
                throw new ApiException(409, 'paid_exceeds_total', 'Payments already exceed the new order total');
            }

            $status = $changes['status'] ?? $existing['status'];
            unset($changes['items']);
            $this->store->updateOrder(array_merge($existing, $changes, [
                'discountMinor' => $discountMinor,
                'totalMinor' => $money['total'],
                'paidMinor' => $paidMinor,
                'completedAt' => $status !== 'completed' ? null
                    : ($existing['status'] === 'completed' && $existing['completedAt'] !== null ? $existing['completedAt'] : $now),
                'updatedAt' => $now,
            ]));
            $this->store->replaceItems($id, self::buildItems($id, $nextItems, $money['lineTotals']));

            return $this->saved($id);
        });
    }

    /**
     * @param array{amountMinor: int, paymentDate: string, method: string, comment: string|null} $input
     * @return array{payment: array<string, mixed>, order: array<string, mixed>}
     */
    public function addPayment(string $orderId, array $input): array
    {
        $now = $this->now();

        return $this->write(function () use ($orderId, $input, $now): array {
            $order = $this->store->lockOrder($orderId) ?? throw self::notFound();
            $paidMinor = $this->store->paidTotal($orderId);
            if ($input['amountMinor'] > max($order['totalMinor'] - $paidMinor, 0)) {
                throw new ApiException(409, 'payment_exceeds_remaining', 'Payment exceeds the remaining amount of the order');
            }

            $payment = ['id' => Uuid::v4(), 'orderId' => $orderId, ...$input, 'createdAt' => $now];
            $this->store->insertPayment($payment);
            $this->store->updateOrder(['paidMinor' => $paidMinor + $input['amountMinor'], 'updatedAt' => $now] + $order);

            return ['payment' => $payment, 'order' => $this->store->findOrder($orderId)];
        });
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<int> $lineTotals
     * @return list<array<string, mixed>>
     */
    private static function buildItems(string $orderId, array $items, array $lineTotals): array
    {
        $built = [];
        foreach ($items as $index => $item) {
            $built[] = [
                'id' => $item['id'] ?? Uuid::v4(),
                'orderId' => $orderId,
                'name' => $item['name'],
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unitPriceMinor' => $item['unitPriceMinor'],
                'lineTotalMinor' => $lineTotals[$index],
                'sortOrder' => $item['sortOrder'],
            ];
        }

        return $built;
    }

    /** @return array{order: array<string, mixed>, items: list<array<string, mixed>>} */
    private function saved(string $orderId): array
    {
        return ['order' => $this->store->findOrder($orderId), 'items' => $this->store->items($orderId)];
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function write(callable $work): mixed
    {
        try {
            return $this->store->transaction($work);
        } catch (IntegrityConflictException) {
            throw new ApiException(409, 'conflict', 'The change conflicts with existing data');
        }
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function notFound(): ApiException
    {
        return new ApiException(404, 'not_found', 'Order not found');
    }

    private static function clientNotFound(): ApiException
    {
        return new ApiException(409, 'client_not_found', 'The client of the order does not exist');
    }
}
