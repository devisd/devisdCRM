<?php

declare(strict_types=1);

namespace DevisCrm\Api\Controllers;

use DevisCrm\Api\Auth\AuthSession;
use DevisCrm\Api\Exceptions\ApiException;
use DevisCrm\Api\Http\JsonResponse;
use DevisCrm\Api\Http\Request;
use DevisCrm\Api\Orders\OrderService;
use DevisCrm\Api\Orders\OrderValidator;
use DevisCrm\Api\Validation\Fields;

/**
 * Owner-only orders API (session required; Origin and CSRF for changes, see Router):
 *   GET /api/orders, GET /api/orders/{id} (with items and payments), POST /api/orders,
 *   PATCH /api/orders/{id}, GET /api/orders/{id}/payments, POST /api/orders/{id}/payments,
 *   GET /api/payments.
 * Orders are archived (PATCH isArchived), never deleted; payments are only added.
 * Records use the shape of the frontend model: absent optional fields are omitted.
 */
final class OrdersController
{
    public function __construct(private OrderService $orders)
    {
    }

    public function list(Request $request): JsonResponse
    {
        return JsonResponse::success(['orders' => array_map(self::present(...), $this->orders->list())]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, AuthSession $session, array $params): JsonResponse
    {
        $found = $this->orders->get(self::id($params));

        return JsonResponse::success([
            'order' => self::present($found['order']),
            'items' => array_map(self::present(...), $found['items']),
            'payments' => array_map(self::present(...), $found['payments']),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $saved = $this->orders->create(OrderValidator::forCreate($request->jsonBody(OrderValidator::MAX_BODY_BYTES)));

        return JsonResponse::success(self::presentSaved($saved), 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, AuthSession $session, array $params): JsonResponse
    {
        $id = self::id($params);
        $saved = $this->orders->update($id, OrderValidator::forPatch($request->jsonBody(OrderValidator::MAX_BODY_BYTES)));

        return JsonResponse::success(self::presentSaved($saved));
    }

    /** GET /api/payments: every payment, for overviews and client summaries. */
    public function allPayments(Request $request): JsonResponse
    {
        return JsonResponse::success(['payments' => array_map(self::present(...), $this->orders->allPayments())]);
    }

    /**
     * @param array<string, string> $params
     */
    public function payments(Request $request, AuthSession $session, array $params): JsonResponse
    {
        return JsonResponse::success(['payments' => array_map(self::present(...), $this->orders->payments(self::id($params)))]);
    }

    /**
     * @param array<string, string> $params
     */
    public function addPayment(Request $request, AuthSession $session, array $params): JsonResponse
    {
        $id = self::id($params);
        $saved = $this->orders->addPayment($id, OrderValidator::payment($request->jsonBody(OrderValidator::PAYMENT_MAX_BODY_BYTES)));

        return JsonResponse::success(['payment' => self::present($saved['payment']), 'order' => self::present($saved['order'])], 201);
    }

    /**
     * @param array{order: array<string, mixed>, items: list<array<string, mixed>>} $saved
     * @return array<string, mixed>
     */
    private static function presentSaved(array $saved): array
    {
        return ['order' => self::present($saved['order']), 'items' => array_map(self::present(...), $saved['items'])];
    }

    /**
     * @param array<string, string> $params
     */
    private static function id(array $params): string
    {
        $id = $params['id'] ?? '';

        // Anything that is not a UUID cannot name an order; it never reaches the database.
        return Fields::isUuid($id) ? $id : throw new ApiException(404, 'not_found', 'Order not found');
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function present(array $record): array
    {
        return array_filter($record, static fn (mixed $value): bool => $value !== null);
    }
}
