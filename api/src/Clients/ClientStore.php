<?php

declare(strict_types=1);

namespace DevisCrm\Api\Clients;

/**
 * Storage of clients. A client is an array with the keys of the frontend model:
 * id, name, contactName, phone, email, telegram, maxContact, otherContact, source, notes
 * (string|null), tags (list<string>|null), isArchived (bool), createdAt, updatedAt
 * (ISO-8601 UTC with milliseconds, as Date#toISOString()).
 */
interface ClientStore
{
    /**
     * All clients, archived included, ordered by name.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @param array<string, mixed> $client */
    public function insert(array $client): void;

    /**
     * Saves every field except id and createdAt.
     *
     * @param array<string, mixed> $client
     * @return array<string, mixed>|null The stored client, null when it no longer exists.
     */
    public function update(array $client): ?array;

    /** Clients with orders are never deleted (they are archived instead). */
    public function deleteIfNoOrders(string $id): ClientDeletion;
}
