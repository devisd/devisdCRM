import type { Order, Payment } from "@/types";
import { isOrderCountableForRevenue } from "./money";

/** Revenue = sum of payments in period for non-cancelled orders. */
export function calcRevenueFromPayments(input: {
  orders: Pick<Order, "id" | "status">[];
  payments: Pick<Payment, "orderId" | "amountMinor" | "paymentDate">[];
  periodStartIso: string;
  periodEndIso: string;
}): number {
  const countableOrderIds = new Set(
    input.orders
      .filter((order) => isOrderCountableForRevenue(order.status))
      .map((order) => order.id),
  );

  const start = Date.parse(input.periodStartIso);
  const end = Date.parse(input.periodEndIso);

  let total = 0;
  for (const payment of input.payments) {
    if (!countableOrderIds.has(payment.orderId)) {
      continue;
    }

    const paidAt = Date.parse(payment.paymentDate);
    if (Number.isNaN(paidAt) || paidAt < start || paidAt > end) {
      continue;
    }

    total += payment.amountMinor;
  }

  return total;
}

export function calcOutstandingMinor(
  orders: Pick<Order, "status" | "totalMinor" | "paidMinor" | "isArchived">[],
): number {
  return orders.reduce((sum, order) => {
    if (order.isArchived || order.status === "cancelled") {
      return sum;
    }
    return sum + Math.max(order.totalMinor - order.paidMinor, 0);
  }, 0);
}
