<?php

declare(strict_types=1);

namespace DevisCrm\Api\Orders;

use DevisCrm\Api\Validation\Fields;

/**
 * Request rules for orders, items and payments, following orderSchema, orderItemSchema and
 * paymentSchema of src/lib/validation/schemas.ts and the tables of 001_init.sql.
 * Everything the server derives is rejected when sent: number, line and order totals,
 * paidMinor, completedAt, ids of new records and timestamps.
 */
final class OrderValidator
{
    /**
     * An order carries two 20 000-character texts and any number of items, each with its own
     * 20 000-character description; in JSON a character may take up to 6 bytes.
     */
    public const MAX_BODY_BYTES = 1048576;

    /** One 20 000-character comment at up to 6 bytes per character. */
    public const PAYMENT_MAX_BODY_BYTES = 131072;

    public const STATUSES = ['new', 'in_progress', 'waiting', 'completed', 'cancelled'];
    public const PAYMENT_METHODS = ['cash', 'bank_transfer', 'other'];

    private const SHORT_TEXT = 255;
    private const UNIT_TEXT = 64;
    private const LONG_TEXT = 20000;

    private const ORDER_FIELDS = ['clientId', 'title', 'description', 'status', 'orderDate', 'dueDate', 'discountMinor', 'notes', 'items'];
    private const ORDER_SERVER_FIELDS = ['id', 'number', 'totalMinor', 'paidMinor', 'completedAt', 'createdAt', 'updatedAt'];
    private const ITEM_FIELDS = ['name', 'description', 'quantity', 'unit', 'unitPriceMinor', 'sortOrder'];
    private const ITEM_SERVER_FIELDS = ['orderId', 'lineTotalMinor'];
    private const PAYMENT_FIELDS = ['amountMinor', 'paymentDate', 'method', 'comment'];
    private const PAYMENT_SERVER_FIELDS = ['id', 'orderId', 'createdAt'];

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed> Every field; optional ones null, status "new" and discount 0 by default.
     */
    public static function forCreate(array $body): array
    {
        Fields::rejectUnexpected($body, self::ORDER_FIELDS, [...self::ORDER_SERVER_FIELDS, 'isArchived']);
        foreach (['clientId', 'title', 'orderDate', 'items'] as $required) {
            if (!array_key_exists($required, $body)) {
                throw Fields::invalid($required, 'is required');
            }
        }

        return self::fields($body, itemIdsAllowed: false)
            + ['description' => null, 'status' => 'new', 'dueDate' => null, 'discountMinor' => 0, 'notes' => null];
    }

    /**
     * Only the fields present. Items, when sent, replace all current items; an item may keep
     * its id only if it is one of the order's current items (checked by the service).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function forPatch(array $body): array
    {
        Fields::rejectUnexpected($body, [...self::ORDER_FIELDS, 'isArchived'], self::ORDER_SERVER_FIELDS);

        return self::fields($body, itemIdsAllowed: true);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{amountMinor: int, paymentDate: string, method: string, comment: string|null}
     */
    public static function payment(array $body): array
    {
        Fields::rejectUnexpected($body, self::PAYMENT_FIELDS, self::PAYMENT_SERVER_FIELDS);
        foreach (['amountMinor', 'paymentDate', 'method'] as $required) {
            if (!array_key_exists($required, $body)) {
                throw Fields::invalid($required, 'is required');
            }
        }

        return [
            'amountMinor' => Fields::integer('amountMinor', $body['amountMinor'], 1, Fields::MAX_SAFE_INTEGER),
            'paymentDate' => Fields::isoDateTime('paymentDate', $body['paymentDate']),
            'method' => Fields::oneOf('method', $body['method'], self::PAYMENT_METHODS),
            'comment' => Fields::optionalText('comment', $body['comment'] ?? null, self::LONG_TEXT),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function fields(array $body, bool $itemIdsAllowed): array
    {
        $values = [];
        foreach ($body as $field => $value) {
            $values[$field] = match ($field) {
                'clientId' => Fields::uuid($field, $value),
                'title' => Fields::requiredText($field, $value, self::SHORT_TEXT),
                'description', 'notes' => Fields::optionalText($field, $value, self::LONG_TEXT),
                'status' => Fields::oneOf($field, $value, self::STATUSES),
                'orderDate' => Fields::isoDateTime($field, $value),
                'dueDate' => Fields::optionalIsoDateTime($field, $value),
                'discountMinor' => Fields::integer($field, $value, 0, Fields::MAX_SAFE_INTEGER),
                'isArchived' => Fields::boolean($field, $value),
                'items' => self::items($value, $itemIdsAllowed),
            };
        }

        return $values;
    }

    /**
     * @return list<array{id: string|null, name: string, description: string|null, quantity: int|float, unit: string, unitPriceMinor: int, sortOrder: int}>
     */
    private static function items(mixed $value, bool $idsAllowed): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw Fields::invalid('items', 'must be a list');
        }
        if ($value === []) {
            throw Fields::invalid('items', 'must contain at least one item');
        }

        $items = [];
        $ids = [];
        foreach ($value as $index => $item) {
            $label = 'items[' . $index . ']';
            if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                throw Fields::invalid($label, 'must be an object');
            }
            Fields::rejectUnexpected($item, $idsAllowed ? [...self::ITEM_FIELDS, 'id'] : self::ITEM_FIELDS, $idsAllowed ? self::ITEM_SERVER_FIELDS : [...self::ITEM_SERVER_FIELDS, 'id'], $label . '.');
            foreach (['name', 'quantity', 'unit', 'unitPriceMinor'] as $required) {
                if (!array_key_exists($required, $item)) {
                    throw Fields::invalid($label . '.' . $required, 'is required');
                }
            }

            $id = isset($item['id']) ? Fields::uuid($label . '.id', $item['id']) : null;
            if ($id !== null) {
                if (isset($ids[strtolower($id)])) {
                    throw Fields::invalid($label . '.id', 'is repeated');
                }
                $ids[strtolower($id)] = true;
            }

            $quantity = $item['quantity'];
            if (!(is_int($quantity) || is_float($quantity)) || !is_finite((float) $quantity) || $quantity <= 0) {
                throw Fields::invalid($label . '.quantity', 'must be a number greater than zero');
            }

            $items[] = [
                'id' => $id,
                'name' => Fields::requiredText($label . '.name', $item['name'], self::SHORT_TEXT),
                'description' => Fields::optionalText($label . '.description', $item['description'] ?? null, self::LONG_TEXT),
                'quantity' => $quantity,
                'unit' => Fields::requiredText($label . '.unit', $item['unit'], self::UNIT_TEXT),
                'unitPriceMinor' => Fields::integer($label . '.unitPriceMinor', $item['unitPriceMinor'], 0, Fields::MAX_SAFE_INTEGER),
                'sortOrder' => array_key_exists('sortOrder', $item) && $item['sortOrder'] !== null
                    ? Fields::integer($label . '.sortOrder', $item['sortOrder'], 0, Fields::MAX_UINT32)
                    : $index,
            ];
        }

        return $items;
    }
}
