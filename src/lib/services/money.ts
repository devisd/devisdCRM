import type { OrderItem, OrderStatus, Payment } from "@/types";

export class MoneyError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "MoneyError";
  }
}

function assertFiniteNonNegativeInteger(
  value: number,
  label: string,
): asserts value is number {
  if (!Number.isFinite(value) || Number.isNaN(value)) {
    throw new MoneyError(`${label}: недопустимое число`);
  }
  if (!Number.isInteger(value)) {
    throw new MoneyError(`${label}: ожидается целое число копеек`);
  }
  if (value < 0) {
    throw new MoneyError(`${label}: не может быть отрицательным`);
  }
}

/** Line total in kopecks: quantity × unit price, rounded to nearest kopeck. */
export function calcLineTotalMinor(
  quantity: number,
  unitPriceMinor: number,
): number {
  if (!Number.isFinite(quantity) || quantity <= 0) {
    throw new MoneyError("Количество должно быть больше нуля");
  }
  assertFiniteNonNegativeInteger(unitPriceMinor, "Цена за единицу");

  const raw = quantity * unitPriceMinor;
  if (!Number.isFinite(raw)) {
    throw new MoneyError("Некорректная сумма позиции");
  }

  return Math.round(raw);
}

export function calcItemsSubtotalMinor(
  items: Pick<OrderItem, "lineTotalMinor">[],
): number {
  let sum = 0;
  for (const item of items) {
    assertFiniteNonNegativeInteger(item.lineTotalMinor, "Сумма позиции");
    sum += item.lineTotalMinor;
  }
  return sum;
}

export function calcOrderTotalMinor(
  itemsSubtotalMinor: number,
  discountMinor: number,
): number {
  assertFiniteNonNegativeInteger(itemsSubtotalMinor, "Сумма позиций");
  assertFiniteNonNegativeInteger(discountMinor, "Скидка");

  if (discountMinor > itemsSubtotalMinor) {
    throw new MoneyError("Скидка не может превышать сумму позиций");
  }

  return itemsSubtotalMinor - discountMinor;
}

export function calcPaidMinor(
  payments: Pick<Payment, "amountMinor">[],
): number {
  let sum = 0;
  for (const payment of payments) {
    assertFiniteNonNegativeInteger(payment.amountMinor, "Сумма платежа");
    if (payment.amountMinor <= 0) {
      throw new MoneyError("Сумма платежа должна быть больше нуля");
    }
    sum += payment.amountMinor;
  }
  return sum;
}

export function calcRemainingMinor(
  totalMinor: number,
  paidMinor: number,
): number {
  assertFiniteNonNegativeInteger(totalMinor, "Итог заказа");
  assertFiniteNonNegativeInteger(paidMinor, "Оплачено");
  return Math.max(totalMinor - paidMinor, 0);
}

export function assertPaymentWithinRemaining(
  remainingMinor: number,
  amountMinor: number,
): void {
  assertFiniteNonNegativeInteger(remainingMinor, "Остаток");
  if (!Number.isInteger(amountMinor) || amountMinor <= 0) {
    throw new MoneyError("Сумма платежа должна быть целым числом больше нуля");
  }
  if (amountMinor > remainingMinor) {
    throw new MoneyError("Платёж не может превышать остаток к оплате");
  }
}

/** Orders that count toward revenue / active totals (exclude cancelled). */
export function isOrderCountableForRevenue(status: OrderStatus): boolean {
  return status !== "cancelled";
}

/**
 * When editing order totals that already have payments:
 * paid must not exceed the new total.
 */
export function assertPaidNotExceedsTotal(
  totalMinor: number,
  paidMinor: number,
): void {
  assertFiniteNonNegativeInteger(totalMinor, "Итог заказа");
  assertFiniteNonNegativeInteger(paidMinor, "Оплачено");
  if (paidMinor > totalMinor) {
    throw new MoneyError(
      "Сумма оплат превышает новый итог. Сначала скорректируйте платежи или итог.",
    );
  }
}

export function recalculateOrderMoney(input: {
  items: Pick<OrderItem, "quantity" | "unitPriceMinor" | "lineTotalMinor">[];
  discountMinor: number;
  payments: Pick<Payment, "amountMinor">[];
  trustStoredLineTotals?: boolean;
}): {
  itemsSubtotalMinor: number;
  totalMinor: number;
  paidMinor: number;
  remainingMinor: number;
  lineTotalsMinor: number[];
} {
  const lineTotalsMinor = input.items.map((item) => {
    if (input.trustStoredLineTotals) {
      assertFiniteNonNegativeInteger(item.lineTotalMinor, "Сумма позиции");
      return item.lineTotalMinor;
    }
    return calcLineTotalMinor(item.quantity, item.unitPriceMinor);
  });

  const itemsSubtotalMinor = lineTotalsMinor.reduce((sum, value) => sum + value, 0);
  const totalMinor = calcOrderTotalMinor(
    itemsSubtotalMinor,
    input.discountMinor,
  );
  const paidMinor = calcPaidMinor(input.payments);
  assertPaidNotExceedsTotal(totalMinor, paidMinor);

  return {
    itemsSubtotalMinor,
    totalMinor,
    paidMinor,
    remainingMinor: calcRemainingMinor(totalMinor, paidMinor),
    lineTotalsMinor,
  };
}
