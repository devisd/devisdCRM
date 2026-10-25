import type { Client, Order, PaymentStatus } from "@/types";
import { listClients } from "@/lib/repositories/clients";
import { listOrders } from "@/lib/repositories/orders";
import { calcPaymentStatus } from "@/lib/services/paymentStatus";
import { calcRemainingMinor } from "@/lib/services/money";

export type OrderListRow = {
  order: Order;
  clientName: string;
  paymentStatus: PaymentStatus;
  remainingMinor: number;
};

export async function listOrderRows(options?: {
  includeArchived?: boolean;
}): Promise<OrderListRow[]> {
  const [orders, clients] = await Promise.all([
    listOrders({ includeArchived: options?.includeArchived ?? true }),
    listClients({ includeArchived: true }),
  ]);

  const clientNames = new Map(clients.map((client) => [client.id, client.name]));
  return buildOrderRows(orders, clientNames);
}

export function buildOrderRows(
  orders: Order[],
  clientNames: Map<string, string>,
): OrderListRow[] {
  return orders.map((order) => ({
    order,
    clientName: clientNames.get(order.clientId) ?? "Неизвестный клиент",
    paymentStatus: calcPaymentStatus(order.totalMinor, order.paidMinor),
    remainingMinor: calcRemainingMinor(order.totalMinor, order.paidMinor),
  }));
}

export type OrderSortKey = "date" | "number" | "total" | "dueDate";

export type OrderListFilters = {
  query: string;
  status: Order["status"] | "all";
  paymentStatus: PaymentStatus | "all";
  archiveFilter: "active" | "archived" | "all";
  periodFrom: string;
  periodTo: string;
  sortKey: OrderSortKey;
};

export const defaultOrderListFilters: OrderListFilters = {
  query: "",
  status: "all",
  paymentStatus: "all",
  archiveFilter: "active",
  periodFrom: "",
  periodTo: "",
  sortKey: "date",
};

export function filterAndSortOrderRows(
  rows: OrderListRow[],
  filters: OrderListFilters,
): OrderListRow[] {
  const query = filters.query.trim().toLowerCase();

  let result = rows.filter(({ order, clientName, paymentStatus }) => {
    if (filters.archiveFilter === "active" && order.isArchived) return false;
    if (filters.archiveFilter === "archived" && !order.isArchived) return false;
    if (filters.status !== "all" && order.status !== filters.status) return false;
    if (
      filters.paymentStatus !== "all" &&
      paymentStatus !== filters.paymentStatus
    ) {
      return false;
    }

    const orderDay = order.orderDate.slice(0, 10);
    if (filters.periodFrom && orderDay < filters.periodFrom) return false;
    if (filters.periodTo && orderDay > filters.periodTo) return false;

    if (!query) return true;

    const haystack = [
      String(order.number),
      order.title,
      clientName,
      order.description,
    ]
      .filter(Boolean)
      .join(" ")
      .toLowerCase();

    return haystack.includes(query);
  });

  result = [...result].sort((a, b) => {
    switch (filters.sortKey) {
      case "number":
        return b.order.number - a.order.number;
      case "total":
        return b.order.totalMinor - a.order.totalMinor;
      case "dueDate": {
        const aDue = a.order.dueDate ?? "9999";
        const bDue = b.order.dueDate ?? "9999";
        return aDue.localeCompare(bDue);
      }
      case "date":
      default:
        return b.order.orderDate.localeCompare(a.order.orderDate);
    }
  });

  return result;
}

export function clientsToNameMap(clients: Client[]): Map<string, string> {
  return new Map(clients.map((client) => [client.id, client.name]));
}
