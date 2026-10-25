import { describe, expect, it } from "vitest";
import {
  assertPaymentWithinRemaining,
  calcLineTotalMinor,
  calcOrderTotalMinor,
  calcPaidMinor,
  calcRemainingMinor,
  isOrderCountableForRevenue,
  MoneyError,
  recalculateOrderMoney,
} from "./money";
import { calcPaymentStatus } from "./paymentStatus";
import { calcRevenueFromPayments } from "./stats";

describe("calcLineTotalMinor", () => {
  it("multiplies quantity by unit price in kopecks", () => {
    expect(calcLineTotalMinor(15, 50000)).toBe(750000);
  });

  it("rounds fractional hour totals to nearest kopeck", () => {
    expect(calcLineTotalMinor(1.5, 10001)).toBe(15002);
  });

  it("rejects non-positive quantity", () => {
    expect(() => calcLineTotalMinor(0, 100)).toThrow(MoneyError);
  });
});

describe("order totals and discount", () => {
  it("subtracts discount from items subtotal", () => {
    expect(calcOrderTotalMinor(100000, 15000)).toBe(85000);
  });

  it("rejects discount greater than subtotal", () => {
    expect(() => calcOrderTotalMinor(1000, 1001)).toThrow(MoneyError);
  });

  it("rejects negative discount via integer validation", () => {
    expect(() => calcOrderTotalMinor(1000, -1)).toThrow(MoneyError);
  });
});

describe("payments and remaining", () => {
  it("sums payments and remaining balance", () => {
    expect(calcPaidMinor([{ amountMinor: 100 }, { amountMinor: 250 }])).toBe(
      350,
    );
    expect(calcRemainingMinor(1000, 350)).toBe(650);
    expect(calcRemainingMinor(1000, 1000)).toBe(0);
  });

  it("forbids overpayment", () => {
    expect(() => assertPaymentWithinRemaining(100, 101)).toThrow(MoneyError);
    expect(() => assertPaymentWithinRemaining(100, 100)).not.toThrow();
  });
});

describe("payment status", () => {
  it("returns not_required for zero total", () => {
    expect(calcPaymentStatus(0, 0)).toBe("not_required");
  });

  it("detects unpaid, partial and paid", () => {
    expect(calcPaymentStatus(1000, 0)).toBe("unpaid");
    expect(calcPaymentStatus(1000, 400)).toBe("partially_paid");
    expect(calcPaymentStatus(1000, 1000)).toBe("paid");
  });
});

describe("recalculateOrderMoney", () => {
  it("recalculates from items and payments", () => {
    const result = recalculateOrderMoney({
      items: [
        { quantity: 10, unitPriceMinor: 1000, lineTotalMinor: 0 },
        { quantity: 2, unitPriceMinor: 5000, lineTotalMinor: 0 },
      ],
      discountMinor: 1000,
      payments: [{ amountMinor: 5000 }],
    });

    expect(result.itemsSubtotalMinor).toBe(20000);
    expect(result.totalMinor).toBe(19000);
    expect(result.paidMinor).toBe(5000);
    expect(result.remainingMinor).toBe(14000);
  });

  it("rejects paid amount above new total", () => {
    expect(() =>
      recalculateOrderMoney({
        items: [{ quantity: 1, unitPriceMinor: 1000, lineTotalMinor: 0 }],
        discountMinor: 0,
        payments: [{ amountMinor: 1500 }],
      }),
    ).toThrow(MoneyError);
  });
});

describe("cancelled orders and revenue", () => {
  it("excludes cancelled orders from countable revenue set", () => {
    expect(isOrderCountableForRevenue("completed")).toBe(true);
    expect(isOrderCountableForRevenue("cancelled")).toBe(false);
  });

  it("does not count payments of cancelled orders in period revenue", () => {
    const revenue = calcRevenueFromPayments({
      orders: [
        { id: "11111111-1111-4111-8111-111111111111", status: "completed" },
        { id: "22222222-2222-4222-8222-222222222222", status: "cancelled" },
      ],
      payments: [
        {
          orderId: "11111111-1111-4111-8111-111111111111",
          amountMinor: 50000,
          paymentDate: "2026-10-10T10:00:00.000Z",
        },
        {
          orderId: "22222222-2222-4222-8222-222222222222",
          amountMinor: 90000,
          paymentDate: "2026-10-12T10:00:00.000Z",
        },
      ],
      periodStartIso: "2026-10-01T00:00:00.000Z",
      periodEndIso: "2026-10-31T23:59:59.999Z",
    });

    expect(revenue).toBe(50000);
  });
});
