import { describe, expect, it } from "vitest";
import {
  buildClientSummaries,
  filterClientSummaries,
  sortClientSummaries,
} from "./clientSummaries";
import type { Client, Order, Payment } from "@/types";

const clientA: Client = {
  id: "11111111-1111-4111-8111-111111111111",
  name: "Альфа",
  phone: "+79990001111",
  isArchived: false,
  createdAt: "2026-01-01T00:00:00.000Z",
  updatedAt: "2026-01-01T00:00:00.000Z",
};

const clientB: Client = {
  id: "22222222-2222-4222-8222-222222222222",
  name: "Бета",
  email: "beta@example.com",
  isArchived: false,
  createdAt: "2026-01-01T00:00:00.000Z",
  updatedAt: "2026-01-01T00:00:00.000Z",
};

const orders: Order[] = [
  {
    id: "33333333-3333-4333-8333-333333333333",
    number: 1,
    clientId: clientA.id,
    title: "Заказ 1",
    status: "completed",
    orderDate: "2026-02-01T00:00:00.000Z",
    discountMinor: 0,
    totalMinor: 10000,
    paidMinor: 10000,
    isArchived: false,
    createdAt: "2026-02-01T00:00:00.000Z",
    updatedAt: "2026-02-01T00:00:00.000Z",
  },
  {
    id: "44444444-4444-4444-8444-444444444444",
    number: 2,
    clientId: clientB.id,
    title: "Заказ 2",
    status: "new",
    orderDate: "2026-03-01T00:00:00.000Z",
    discountMinor: 0,
    totalMinor: 20000,
    paidMinor: 5000,
    isArchived: false,
    createdAt: "2026-03-01T00:00:00.000Z",
    updatedAt: "2026-03-01T00:00:00.000Z",
  },
];

const payments: Payment[] = [
  {
    id: "55555555-5555-4555-8555-555555555555",
    orderId: orders[0].id,
    amountMinor: 10000,
    paymentDate: "2026-02-02T00:00:00.000Z",
    method: "cash",
    createdAt: "2026-02-02T00:00:00.000Z",
  },
  {
    id: "66666666-6666-4666-8666-666666666666",
    orderId: orders[1].id,
    amountMinor: 5000,
    paymentDate: "2026-03-02T00:00:00.000Z",
    method: "bank_transfer",
    createdAt: "2026-03-02T00:00:00.000Z",
  },
];

describe("clientSummaries", () => {
  it("builds per-client aggregates", () => {
    const summaries = buildClientSummaries([clientA, clientB], orders, payments);
    expect(summaries[0].orderCount).toBe(1);
    expect(summaries[0].paidMinor).toBe(10000);
    expect(summaries[1].lastOrderDate).toBe("2026-03-01T00:00:00.000Z");
  });

  it("filters by contact fields", () => {
    const summaries = buildClientSummaries([clientA, clientB], orders, payments);
    expect(filterClientSummaries(summaries, "beta@").map((row) => row.client.name)).toEqual([
      "Бета",
    ]);
    expect(filterClientSummaries(summaries, "999000").map((row) => row.client.name)).toEqual([
      "Альфа",
    ]);
  });

  it("sorts by paid amount", () => {
    const summaries = buildClientSummaries([clientA, clientB], orders, payments);
    const sorted = sortClientSummaries(summaries, "paid");
    expect(sorted[0].client.name).toBe("Альфа");
  });
});
