import type { Order, OrderItem, Payment } from "@/types";
import { getDb, META_KEYS } from "@/lib/db/database";
import { DbError } from "@/lib/db/errors";
import { createId } from "@/lib/id";
import { toIsoNow } from "@/lib/format/dates";
import {
  orderItemSchema,
  orderSchema,
  paymentSchema,
} from "@/lib/validation/schemas";
import {
  assertPaymentWithinRemaining,
  calcRemainingMinor,
  recalculateOrderMoney,
} from "@/lib/services/money";

export type OrderItemInput = Omit<OrderItem, "id" | "orderId" | "lineTotalMinor"> & {
  id?: string;
  lineTotalMinor?: number;
};

export type CreateOrderInput = {
  clientId: string;
  title: string;
  description?: string;
  status?: Order["status"];
  orderDate: string;
  dueDate?: string;
  completedAt?: string;
  discountMinor?: number;
  notes?: string;
  items: OrderItemInput[];
};

export type UpdateOrderInput = Partial<
  Omit<CreateOrderInput, "items" | "clientId" | "completedAt">
> & {
  clientId?: string;
  items?: OrderItemInput[];
  isArchived?: boolean;
  /** Pass `null` to clear completedAt. */
  completedAt?: string | null;
};

async function ensureClientExists(clientId: string): Promise<void> {
  const client = await getDb().clients.get(clientId);
  if (!client) {
    throw new DbError("Клиент для заказа не найден");
  }
}

async function allocateOrderNumber(): Promise<number> {
  const db = getDb();
  const meta = await db.appMeta.get(META_KEYS.nextOrderNumber);
  const next = typeof meta?.value === "number" ? meta.value : 1;
  await db.appMeta.put({ key: META_KEYS.nextOrderNumber, value: next + 1 });
  return next;
}

function buildItems(
  orderId: string,
  items: OrderItemInput[],
  lineTotalsMinor: number[],
): OrderItem[] {
  if (items.length === 0) {
    throw new DbError("В заказе должна быть хотя бы одна позиция");
  }

  return items.map((item, index) =>
    orderItemSchema.parse({
      id: item.id ?? createId(),
      orderId,
      name: item.name,
      description: item.description,
      quantity: item.quantity,
      unit: item.unit,
      unitPriceMinor: item.unitPriceMinor,
      lineTotalMinor: lineTotalsMinor[index],
      sortOrder: item.sortOrder ?? index,
    }),
  );
}

export async function listOrders(options?: {
  includeArchived?: boolean;
}): Promise<Order[]> {
  const orders = await getDb().orders.orderBy("orderDate").reverse().toArray();
  if (options?.includeArchived) {
    return orders;
  }
  return orders.filter((order) => !order.isArchived);
}

export async function getOrderById(id: string): Promise<Order | undefined> {
  return getDb().orders.get(id);
}

export async function getOrdersByClientId(clientId: string): Promise<Order[]> {
  const orders = await getDb().orders.where("clientId").equals(clientId).toArray();
  return orders.sort((a, b) => b.orderDate.localeCompare(a.orderDate));
}

export async function getOrderItems(orderId: string): Promise<OrderItem[]> {
  return getDb()
    .orderItems.where("orderId")
    .equals(orderId)
    .sortBy("sortOrder");
}

export async function getOrderPayments(orderId: string): Promise<Payment[]> {
  const payments = await getDb().payments.where("orderId").equals(orderId).toArray();
  return payments.sort((a, b) => b.paymentDate.localeCompare(a.paymentDate));
}

export async function createOrder(input: CreateOrderInput): Promise<{
  order: Order;
  items: OrderItem[];
}> {
  await ensureClientExists(input.clientId);

  const money = recalculateOrderMoney({
    items: input.items.map((item) => ({
      quantity: item.quantity,
      unitPriceMinor: item.unitPriceMinor,
      lineTotalMinor: 0,
    })),
    discountMinor: input.discountMinor ?? 0,
    payments: [],
  });

  const db = getDb();
  const now = toIsoNow();
  const orderId = createId();

  const result = await db.transaction(
    "rw",
    db.clients,
    db.orders,
    db.orderItems,
    db.payments,
    db.appMeta,
    async () => {
      await ensureClientExists(input.clientId);
      const number = await allocateOrderNumber();
      const items = buildItems(orderId, input.items, money.lineTotalsMinor);

      const order = orderSchema.parse({
        id: orderId,
        number,
        clientId: input.clientId,
        title: input.title,
        description: input.description,
        status: input.status ?? "new",
        orderDate: input.orderDate,
        dueDate: input.dueDate,
        completedAt: input.completedAt,
        discountMinor: input.discountMinor ?? 0,
        totalMinor: money.totalMinor,
        paidMinor: 0,
        notes: input.notes,
        isArchived: false,
        createdAt: now,
        updatedAt: now,
      });

      await db.orders.add(order);
      await db.orderItems.bulkAdd(items);
      return { order, items };
    },
  );

  return result;
}

export async function updateOrder(
  id: string,
  input: UpdateOrderInput,
): Promise<{ order: Order; items: OrderItem[] }> {
  const db = getDb();
  const existing = await db.orders.get(id);
  if (!existing) {
    throw new DbError("Заказ не найден");
  }

  if (input.clientId) {
    await ensureClientExists(input.clientId);
  }

  const currentItems = await getOrderItems(id);
  const currentPayments = await getOrderPayments(id);
  const nextItemsInput = input.items ?? currentItems;

  const money = recalculateOrderMoney({
    items: nextItemsInput.map((item) => ({
      quantity: item.quantity,
      unitPriceMinor: item.unitPriceMinor,
      lineTotalMinor: "lineTotalMinor" in item ? (item.lineTotalMinor ?? 0) : 0,
    })),
    discountMinor: input.discountMinor ?? existing.discountMinor,
    payments: currentPayments,
  });

  const now = toIsoNow();

  return db.transaction(
    "rw",
    db.clients,
    db.orders,
    db.orderItems,
    db.payments,
    db.appMeta,
    async () => {
      const items = buildItems(id, nextItemsInput, money.lineTotalsMinor);

      const order = orderSchema.parse({
        ...existing,
        clientId: input.clientId ?? existing.clientId,
        title: input.title ?? existing.title,
        description:
          input.description !== undefined
            ? input.description
            : existing.description,
        status: input.status ?? existing.status,
        orderDate: input.orderDate ?? existing.orderDate,
        dueDate: input.dueDate !== undefined ? input.dueDate : existing.dueDate,
        completedAt:
          input.completedAt === null
            ? undefined
            : input.completedAt !== undefined
              ? input.completedAt
              : existing.completedAt,
        discountMinor: input.discountMinor ?? existing.discountMinor,
        totalMinor: money.totalMinor,
        paidMinor: money.paidMinor,
        notes: input.notes !== undefined ? input.notes : existing.notes,
        isArchived: input.isArchived ?? existing.isArchived,
        updatedAt: now,
      });

      await db.orders.put(order);
      await db.orderItems.where("orderId").equals(id).delete();
      await db.orderItems.bulkAdd(items);
      return { order, items };
    },
  );
}

export async function archiveOrder(id: string): Promise<Order> {
  const { order } = await updateOrder(id, { isArchived: true });
  return order;
}

export async function unarchiveOrder(id: string): Promise<Order> {
  const { order } = await updateOrder(id, { isArchived: false });
  return order;
}

export async function updateOrderStatus(
  id: string,
  status: Order["status"],
): Promise<Order> {
  const existing = await getOrderById(id);
  if (!existing) {
    throw new DbError("Заказ не найден");
  }

  const { order } = await updateOrder(id, {
    status,
    completedAt:
      status === "completed"
        ? (existing.completedAt ?? toIsoNow())
        : null,
  });
  return order;
}

export async function addPayment(input: {
  orderId: string;
  amountMinor: number;
  paymentDate: string;
  method: Payment["method"];
  comment?: string;
}): Promise<{ payment: Payment; order: Order }> {
  const db = getDb();
  const order = await db.orders.get(input.orderId);
  if (!order) {
    throw new DbError("Заказ не найден");
  }

  const remaining = calcRemainingMinor(order.totalMinor, order.paidMinor);
  assertPaymentWithinRemaining(remaining, input.amountMinor);

  const payment = paymentSchema.parse({
    id: createId(),
    orderId: input.orderId,
    amountMinor: input.amountMinor,
    paymentDate: input.paymentDate,
    method: input.method,
    comment: input.comment,
    createdAt: toIsoNow(),
  });

  return db.transaction("rw", db.orders, db.payments, async () => {
    const current = await db.orders.get(input.orderId);
    if (!current) {
      throw new DbError("Заказ не найден");
    }

    const remainingNow = calcRemainingMinor(
      current.totalMinor,
      current.paidMinor,
    );
    assertPaymentWithinRemaining(remainingNow, input.amountMinor);

    await db.payments.add(payment);
    const updated = orderSchema.parse({
      ...current,
      paidMinor: current.paidMinor + payment.amountMinor,
      updatedAt: toIsoNow(),
    });
    await db.orders.put(updated);
    return { payment, order: updated };
  });
}
