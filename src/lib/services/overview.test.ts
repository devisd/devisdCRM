import { describe, expect, it } from "vitest";
import { buildOverviewStats } from "./overview";
import type { Client, Order, Payment } from "@/types";

const client: Client = {
  id: "11111111-1111-4111-8111-111111111111",
  name: "Клиент А",
  isArchived: false,
  createdAt: "2026-01-01T00:00:00.000Z",
  updatedAt: "2026-01-01T00:00:00.000Z",
};

const now = new Date("2026-10-15T12:00:00.000Z");

const orders: Order[] = [
  {
    id: "22222222-2222-4222-8222-222222222222",
    number: 1,
    clientId: client.id,
    title: "В работе",
    status: "in_progress",
    orderDate: "2026-10-10T12:00:00.000Z",
    dueDate: "2026-10-18T12:00:00.000Z",
    discountMinor: 0,
    totalMinor: 100000,
    paidMinor: 40000,
    isArchived: false,
    createdAt: "2026-10-10T12:00:00.000Z",
    updatedAt: "2026-10-10T12:00:00.000Z",
  },
  {
    id: "33333333-3333-4333-8333-333333333333",
    number: 2,
    clientId: client.id,
    title: "Новый",
    status: "new",
    orderDate: "2026-10-12T12:00:00.000Z",
    discountMinor: 0,
    totalMinor: 50000,
    paidMinor: 0,
    isArchived: false,
    createdAt: "2026-10-12T12:00:00.000Z",
    updatedAt: "2026-10-12T12:00:00.000Z",
  },
  {
    id: "44444444-4444-4444-8444-444444444444",
    number: 3,
    clientId: client.id,
    title: "Завершён",
    status: "completed",
    orderDate: "2026-10-01T12:00:00.000Z",
    completedAt: "2026-10-05T12:00:00.000Z",
    discountMinor: 0,
    totalMinor: 20000,
    paidMinor: 20000,
    isArchived: false,
    createdAt: "2026-10-01T12:00:00.000Z",
    updatedAt: "2026-10-05T12:00:00.000Z",
  },
  {
    id: "55555555-5555-4555-8555-555555555555",
    number: 4,
    clientId: client.id,
    title: "Отменён",
    status: "cancelled",
    orderDate: "2026-10-03T12:00:00.000Z",
    discountMinor: 0,
    totalMinor: 90000,
    paidMinor: 90000,
    isArchived: false,
    createdAt: "2026-10-03T12:00:00.000Z",
    updatedAt: "2026-10-03T12:00:00.000Z",
  },
];

const payments: Payment[] = [
  {
    id: "66666666-6666-4666-8666-666666666666",
    orderId: orders[0].id,
    amountMinor: 40000,
    paymentDate: "2026-10-11T12:00:00.000Z",
    method: "cash",
    createdAt: "2026-10-11T12:00:00.000Z",
  },
  {
    id: "77777777-7777-4777-8777-777777777777",
    orderId: orders[2].id,
    amountMinor: 20000,
    paymentDate: "2026-10-05T12:00:00.000Z",
    method: "bank_transfer",
    createdAt: "2026-10-05T12:00:00.000Z",
  },
  {
    id: "88888888-8888-4888-8888-888888888888",
    orderId: orders[3].id,
    amountMinor: 90000,
    paymentDate: "2026-10-04T12:00:00.000Z",
    method: "other",
    createdAt: "2026-10-04T12:00:00.000Z",
  },
];

describe("buildOverviewStats", () => {
  it("returns empty flag for empty database", () => {
    const stats = buildOverviewStats({
      clients: [],
      orders: [],
      payments: [],
      now,
    });
    expect(stats.isEmpty).toBe(true);
  });

  it("computes KPI from real payments and active orders", () => {
    const stats = buildOverviewStats({
      clients: [client],
      orders,
      payments,
      now,
    });

    expect(stats.isEmpty).toBe(false);
    expect(stats.inProgressCount).toBe(1);
    expect(stats.newCount).toBe(1);
    // 40000 + 20000, cancelled order payment excluded
    expect(stats.paidThisMonthMinor).toBe(60000);
    // remaining on active non-cancelled: 60000 + 50000
    expect(stats.outstandingMinor).toBe(110000);
    expect(stats.completedThisMonthCount).toBe(1);
    expect(stats.recentOrders[0].number).toBe(2);
    expect(stats.dueSoonOrders[0].title).toBe("В работе");
  });
});
