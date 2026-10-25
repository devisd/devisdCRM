import { describe, expect, it } from "vitest";
import {
  buildOrderRows,
  defaultOrderListFilters,
  filterAndSortOrderRows,
} from "./orderList";
import type { Order } from "@/types";

const orders: Order[] = [
  {
    id: "11111111-1111-4111-8111-111111111111",
    number: 1,
    clientId: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
    title: "Карточки товара для Wildberries",
    status: "in_progress",
    orderDate: "2026-10-10T12:00:00.000Z",
    dueDate: "2026-10-20T12:00:00.000Z",
    discountMinor: 0,
    totalMinor: 750000,
    paidMinor: 250000,
    isArchived: false,
    createdAt: "2026-10-10T12:00:00.000Z",
    updatedAt: "2026-10-10T12:00:00.000Z",
  },
  {
    id: "22222222-2222-4222-8222-222222222222",
    number: 2,
    clientId: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
    title: "Логотип",
    status: "new",
    orderDate: "2026-10-15T12:00:00.000Z",
    discountMinor: 0,
    totalMinor: 100000,
    paidMinor: 0,
    isArchived: false,
    createdAt: "2026-10-15T12:00:00.000Z",
    updatedAt: "2026-10-15T12:00:00.000Z",
  },
];

describe("orderList filters", () => {
  const rows = buildOrderRows(
    orders,
    new Map([
      ["aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "Клиент А"],
      ["bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb", "Клиент Б"],
    ]),
  );

  it("finds order by title and client", () => {
    const byTitle = filterAndSortOrderRows(rows, {
      ...defaultOrderListFilters,
      query: "wildberries",
    });
    expect(byTitle).toHaveLength(1);
    expect(byTitle[0].order.number).toBe(1);

    const byClient = filterAndSortOrderRows(rows, {
      ...defaultOrderListFilters,
      query: "клиент б",
    });
    expect(byClient).toHaveLength(1);
    expect(byClient[0].order.number).toBe(2);
  });

  it("filters by payment status", () => {
    const partial = filterAndSortOrderRows(rows, {
      ...defaultOrderListFilters,
      paymentStatus: "partially_paid",
    });
    expect(partial.map((row) => row.order.number)).toEqual([1]);
  });

  it("sorts by total descending", () => {
    const sorted = filterAndSortOrderRows(rows, {
      ...defaultOrderListFilters,
      sortKey: "total",
    });
    expect(sorted.map((row) => row.order.number)).toEqual([1, 2]);
  });
});
