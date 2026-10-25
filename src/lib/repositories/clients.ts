import type { Client } from "@/types";
import { getDb } from "@/lib/db/database";
import { DbError } from "@/lib/db/errors";
import { createId } from "@/lib/id";
import { toIsoNow } from "@/lib/format/dates";
import { clientSchema } from "@/lib/validation/schemas";

export type ClientInput = Omit<
  Client,
  "id" | "createdAt" | "updatedAt" | "isArchived"
> & {
  isArchived?: boolean;
};

export async function listClients(options?: {
  includeArchived?: boolean;
}): Promise<Client[]> {
  const db = getDb();
  const clients = await db.clients.orderBy("name").toArray();

  if (options?.includeArchived) {
    return clients;
  }

  return clients.filter((client) => !client.isArchived);
}

export async function getClientById(id: string): Promise<Client | undefined> {
  return getDb().clients.get(id);
}

export async function createClient(input: ClientInput): Promise<Client> {
  const now = toIsoNow();
  const client = clientSchema.parse({
    ...input,
    id: createId(),
    isArchived: input.isArchived ?? false,
    createdAt: now,
    updatedAt: now,
  });

  await getDb().clients.add(client);
  return client;
}

export async function updateClient(
  id: string,
  patch: Partial<ClientInput>,
): Promise<Client> {
  const db = getDb();
  const existing = await db.clients.get(id);
  if (!existing) {
    throw new DbError("Клиент не найден");
  }

  const updated = clientSchema.parse({
    ...existing,
    ...patch,
    id: existing.id,
    createdAt: existing.createdAt,
    updatedAt: toIsoNow(),
  });

  await db.clients.put(updated);
  return updated;
}

export async function archiveClient(id: string): Promise<Client> {
  return updateClient(id, { isArchived: true });
}

export async function unarchiveClient(id: string): Promise<Client> {
  return updateClient(id, { isArchived: false });
}

export async function deleteClientIfNoOrders(id: string): Promise<void> {
  const db = getDb();
  const orderCount = await db.orders.where("clientId").equals(id).count();

  if (orderCount > 0) {
    throw new DbError(
      "Нельзя удалить клиента с заказами. Используйте архивирование.",
    );
  }

  const existing = await db.clients.get(id);
  if (!existing) {
    throw new DbError("Клиент не найден");
  }

  await db.clients.delete(id);
}
