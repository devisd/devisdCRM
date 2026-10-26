<?php

declare(strict_types=1);

namespace DevisCrm\Api\Controllers;

use DevisCrm\Api\Auth\AuthSession;
use DevisCrm\Api\Auth\Clock;
use DevisCrm\Api\Clients\ClientDeletion;
use DevisCrm\Api\Clients\ClientStore;
use DevisCrm\Api\Clients\ClientValidator;
use DevisCrm\Api\Exceptions\ApiException;
use DevisCrm\Api\Http\JsonResponse;
use DevisCrm\Api\Http\Request;
use DevisCrm\Api\Uuid;
use DevisCrm\Api\Validation\Fields;

/**
 * Owner-only CRUD of clients (session required; Origin and CSRF for changes, see Router):
 *   GET /api/clients, GET /api/clients/{id}, POST /api/clients,
 *   PATCH /api/clients/{id}, DELETE /api/clients/{id}.
 * A client is returned in the shape of the frontend model: absent optional fields are
 * omitted (not null), timestamps are ISO-8601 UTC strings.
 */
final class ClientsController
{
    private const FIELD_ORDER = [
        'id', 'name', 'contactName', 'phone', 'email', 'telegram', 'maxContact', 'otherContact',
        'source', 'notes', 'tags', 'isArchived', 'createdAt', 'updatedAt',
    ];

    public function __construct(
        private ClientStore $clients,
        private Clock $clock,
    ) {
    }

    public function list(Request $request): JsonResponse
    {
        return JsonResponse::success(['clients' => array_map(self::present(...), $this->clients->all())]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, AuthSession $session, array $params): JsonResponse
    {
        $client = $this->clients->find(self::id($params)) ?? throw self::notFound();

        return JsonResponse::success(['client' => self::present($client)]);
    }

    public function create(Request $request): JsonResponse
    {
        $values = ClientValidator::forCreate($request->jsonBody(ClientValidator::MAX_BODY_BYTES));
        $now = $this->now();
        $client = ['id' => Uuid::v4()] + $values + ['createdAt' => $now, 'updatedAt' => $now];
        $this->clients->insert($client);

        return JsonResponse::success(['client' => self::present($client)], 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, AuthSession $session, array $params): JsonResponse
    {
        $id = self::id($params);
        $changes = ClientValidator::forPatch($request->jsonBody(ClientValidator::MAX_BODY_BYTES));
        $existing = $this->clients->find($id) ?? throw self::notFound();

        $saved = $this->clients->update(['updatedAt' => $this->now()] + $changes + $existing) ?? throw self::notFound();

        return JsonResponse::success(['client' => self::present($saved)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, AuthSession $session, array $params): JsonResponse
    {
        return match ($this->clients->deleteIfNoOrders(self::id($params))) {
            ClientDeletion::Deleted => JsonResponse::success([]),
            ClientDeletion::NotFound => throw self::notFound(),
            ClientDeletion::HasOrders => throw new ApiException(409, 'client_has_orders', 'A client with orders cannot be deleted; archive it instead'),
        };
    }

    /**
     * @param array<string, string> $params
     */
    private static function id(array $params): string
    {
        $id = $params['id'] ?? '';

        // Anything that is not a UUID cannot name a client; it never reaches the database.
        return Fields::isUuid($id) ? $id : throw self::notFound();
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private static function present(array $client): array
    {
        $shown = [];
        foreach (self::FIELD_ORDER as $field) {
            if (($client[$field] ?? null) !== null) {
                $shown[$field] = $client[$field];
            }
        }

        return $shown;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function notFound(): ApiException
    {
        return new ApiException(404, 'not_found', 'Client not found');
    }
}
