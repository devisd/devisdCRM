import type { Client, Order, Payment } from "@/types";
import { listClients } from "@/lib/repositories/clients";
import { listOrders } from "@/lib/repositories/orders";
import { getDb } from "@/lib/db/database";

export type ClientSummary = {
  client: Client;
  orderCount: number;
  lastOrderDate?: string;
  paidMinor: number;
};

export async function listClientSummaries(options?: {
  includeArchived?: boolean;
}): Promise<ClientSummary[]> {
  const [clients, orders] = await Promise.all([
    listClients({ includeArchived: options?.includeArchived ?? true }),
    listOrders({ includeArchived: true }),
  ]);

  const payments = await getDb().payments.toArray();
  return buildClientSummaries(clients, orders, payments);
}

export function buildClientSummaries(
  clients: Client[],
  orders: Order[],
  payments: Payment[],
): ClientSummary[] {
  const ordersByClient = new Map<string, Order[]>();
  for (const order of orders) {
    const list = ordersByClient.get(order.clientId) ?? [];
    list.push(order);
    ordersByClient.set(order.clientId, list);
  }

  const paidByOrder = new Map<string, number>();
  for (const payment of payments) {
    paidByOrder.set(
      payment.orderId,
      (paidByOrder.get(payment.orderId) ?? 0) + payment.amountMinor,
    );
  }

  return clients.map((client) => {
    const clientOrders = ordersByClient.get(client.id) ?? [];
    let lastOrderDate: string | undefined;
    let paidMinor = 0;

    for (const order of clientOrders) {
      if (!lastOrderDate || order.orderDate > lastOrderDate) {
        lastOrderDate = order.orderDate;
      }
      paidMinor += paidByOrder.get(order.id) ?? order.paidMinor;
    }

    return {
      client,
      orderCount: clientOrders.length,
      lastOrderDate,
      paidMinor,
    };
  });
}

export function filterClientSummaries(
  summaries: ClientSummary[],
  query: string,
): ClientSummary[] {
  const normalized = query.trim().toLowerCase();
  if (!normalized) {
    return summaries;
  }

  return summaries.filter(({ client }) => {
    const haystack = [
      client.name,
      client.contactName,
      client.phone,
      client.email,
      client.telegram,
      client.maxContact,
      client.otherContact,
      client.source,
    ]
      .filter(Boolean)
      .join(" ")
      .toLowerCase();

    return haystack.includes(normalized);
  });
}

export type ClientSortKey = "name" | "lastOrder" | "paid" | "orders";

export function sortClientSummaries(
  summaries: ClientSummary[],
  sortKey: ClientSortKey,
): ClientSummary[] {
  const copy = [...summaries];

  copy.sort((a, b) => {
    switch (sortKey) {
      case "orders":
        return b.orderCount - a.orderCount || a.client.name.localeCompare(b.client.name, "ru");
      case "paid":
        return b.paidMinor - a.paidMinor || a.client.name.localeCompare(b.client.name, "ru");
      case "lastOrder": {
        const aDate = a.lastOrderDate ?? "";
        const bDate = b.lastOrderDate ?? "";
        return bDate.localeCompare(aDate) || a.client.name.localeCompare(b.client.name, "ru");
      }
      case "name":
      default:
        return a.client.name.localeCompare(b.client.name, "ru");
    }
  });

  return copy;
}
