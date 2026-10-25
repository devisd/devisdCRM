import type { Client, Order, Payment } from "@/types";
import { getDb } from "@/lib/db/database";
import { listClients } from "@/lib/repositories/clients";
import { listOrders } from "@/lib/repositories/orders";
import { calcOutstandingMinor, calcRevenueFromPayments } from "./stats";
import { calcRemainingMinor } from "./money";
import { calcPaymentStatus } from "./paymentStatus";

export type OverviewOrderPreview = {
  id: string;
  number: number;
  title: string;
  clientName: string;
  orderDate: string;
  dueDate?: string;
  status: Order["status"];
  totalMinor: number;
  paidMinor: number;
  remainingMinor: number;
  paymentStatus: ReturnType<typeof calcPaymentStatus>;
};

export type OverviewStats = {
  isEmpty: boolean;
  clientCount: number;
  orderCount: number;
  inProgressCount: number;
  newCount: number;
  paidThisMonthMinor: number;
  outstandingMinor: number;
  completedThisMonthCount: number;
  recentOrders: OverviewOrderPreview[];
  dueSoonOrders: OverviewOrderPreview[];
};

function startOfLocalMonth(now = new Date()): Date {
  return new Date(now.getFullYear(), now.getMonth(), 1, 0, 0, 0, 0);
}

function endOfLocalMonth(now = new Date()): Date {
  return new Date(now.getFullYear(), now.getMonth() + 1, 0, 23, 59, 59, 999);
}

function isInLocalMonth(iso: string, now = new Date()): boolean {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) {
    return false;
  }
  return (
    date.getFullYear() === now.getFullYear() &&
    date.getMonth() === now.getMonth()
  );
}

function toPreview(
  order: Order,
  clientNames: Map<string, string>,
): OverviewOrderPreview {
  return {
    id: order.id,
    number: order.number,
    title: order.title,
    clientName: clientNames.get(order.clientId) ?? "Неизвестный клиент",
    orderDate: order.orderDate,
    dueDate: order.dueDate,
    status: order.status,
    totalMinor: order.totalMinor,
    paidMinor: order.paidMinor,
    remainingMinor: calcRemainingMinor(order.totalMinor, order.paidMinor),
    paymentStatus: calcPaymentStatus(order.totalMinor, order.paidMinor),
  };
}

export function buildOverviewStats(input: {
  clients: Client[];
  orders: Order[];
  payments: Payment[];
  now?: Date;
}): OverviewStats {
  const now = input.now ?? new Date();
  const clientNames = new Map(
    input.clients.map((client) => [client.id, client.name]),
  );

  const activeOrders = input.orders.filter(
    (order) => !order.isArchived && order.status !== "cancelled",
  );

  const inProgressCount = activeOrders.filter(
    (order) => order.status === "in_progress",
  ).length;
  const newCount = activeOrders.filter((order) => order.status === "new").length;

  const paidThisMonthMinor = calcRevenueFromPayments({
    orders: input.orders,
    payments: input.payments,
    periodStartIso: startOfLocalMonth(now).toISOString(),
    periodEndIso: endOfLocalMonth(now).toISOString(),
  });

  const outstandingMinor = calcOutstandingMinor(input.orders);

  const completedThisMonthCount = input.orders.filter((order) => {
    if (order.isArchived || order.status !== "completed") {
      return false;
    }
    const completedAt = order.completedAt ?? order.updatedAt;
    return isInLocalMonth(completedAt, now);
  }).length;

  const recentOrders = [...input.orders]
    .filter((order) => !order.isArchived)
    .sort((a, b) => b.orderDate.localeCompare(a.orderDate))
    .slice(0, 5)
    .map((order) => toPreview(order, clientNames));

  const dueSoonOrders = activeOrders
    .filter((order) => Boolean(order.dueDate))
    .sort((a, b) => (a.dueDate ?? "").localeCompare(b.dueDate ?? ""))
    .slice(0, 5)
    .map((order) => toPreview(order, clientNames));

  return {
    isEmpty: input.clients.length === 0 && input.orders.length === 0,
    clientCount: input.clients.length,
    orderCount: input.orders.length,
    inProgressCount,
    newCount,
    paidThisMonthMinor,
    outstandingMinor,
    completedThisMonthCount,
    recentOrders,
    dueSoonOrders,
  };
}

export async function loadOverviewStats(): Promise<OverviewStats> {
  const [clients, orders, payments] = await Promise.all([
    listClients({ includeArchived: true }),
    listOrders({ includeArchived: true }),
    getDb().payments.toArray(),
  ]);

  return buildOverviewStats({ clients, orders, payments });
}
