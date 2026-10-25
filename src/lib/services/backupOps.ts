import {
  BACKUP_APP_NAME,
  BACKUP_VERSION,
  buildBackupFilename,
  validateBackupPayload,
  type BackupValidationIssue,
} from "@/lib/services/backup";
import { getDb, META_KEYS } from "@/lib/db/database";
import { createId } from "@/lib/id";
import { toIsoNow } from "@/lib/format/dates";
import {
  calcLineTotalMinor,
  calcOrderTotalMinor,
  calcPaidMinor,
} from "@/lib/services/money";
import type { BackupFile, Client, Order, OrderItem, Payment } from "@/types";

export type BackupCounts = {
  clients: number;
  orders: number;
  orderItems: number;
  payments: number;
};

export type ImportBackupResult =
  | { ok: true; counts: BackupCounts }
  | { ok: false; issues: BackupValidationIssue[] };

/** Recalculate money fields from items/payments; do not trust imported totals. */
export function prepareBackupEntities(backup: BackupFile): {
  clients: Client[];
  orders: Order[];
  orderItems: OrderItem[];
  payments: Payment[];
  nextOrderNumber: number;
} {
  const paymentsByOrder = new Map<string, Payment[]>();
  for (const payment of backup.data.payments) {
    const list = paymentsByOrder.get(payment.orderId) ?? [];
    list.push(payment);
    paymentsByOrder.set(payment.orderId, list);
  }

  const orderItems: OrderItem[] = backup.data.orderItems.map((item) => ({
    ...item,
    lineTotalMinor: calcLineTotalMinor(item.quantity, item.unitPriceMinor),
  }));

  const orders: Order[] = backup.data.orders.map((order) => {
    const items = orderItems.filter((item) => item.orderId === order.id);
    const payments = paymentsByOrder.get(order.id) ?? [];
    const subtotal = items.reduce((sum, item) => sum + item.lineTotalMinor, 0);
    const totalMinor = calcOrderTotalMinor(subtotal, order.discountMinor);
    const paidMinor = calcPaidMinor(payments);
    return {
      ...order,
      totalMinor,
      paidMinor,
    };
  });

  const maxNumber = orders.reduce((max, order) => Math.max(max, order.number), 0);

  return {
    clients: backup.data.clients,
    orders,
    orderItems,
    payments: backup.data.payments,
    nextOrderNumber: maxNumber + 1,
  };
}

export async function exportBackup(): Promise<{
  backup: BackupFile;
  filename: string;
  json: string;
}> {
  const db = getDb();
  const [clients, orders, orderItems, payments] = await Promise.all([
    db.clients.toArray(),
    db.orders.toArray(),
    db.orderItems.toArray(),
    db.payments.toArray(),
  ]);

  const backup: BackupFile = {
    app: BACKUP_APP_NAME,
    backupVersion: BACKUP_VERSION,
    exportedAt: toIsoNow(),
    data: {
      clients,
      orders,
      orderItems,
      payments,
    },
  };

  const json = `${JSON.stringify(backup, null, 2)}\n`;
  return {
    backup,
    filename: buildBackupFilename(),
    json,
  };
}

export async function replaceDatabaseWithBackup(
  backup: BackupFile,
): Promise<BackupCounts> {
  const prepared = prepareBackupEntities(backup);
  const db = getDb();

  await db.transaction(
    "rw",
    db.clients,
    db.orders,
    db.orderItems,
    db.payments,
    db.appMeta,
    async () => {
      await Promise.all([
        db.clients.clear(),
        db.orders.clear(),
        db.orderItems.clear(),
        db.payments.clear(),
        db.appMeta.clear(),
      ]);

      if (prepared.clients.length > 0) {
        await db.clients.bulkAdd(prepared.clients);
      }
      if (prepared.orders.length > 0) {
        await db.orders.bulkAdd(prepared.orders);
      }
      if (prepared.orderItems.length > 0) {
        await db.orderItems.bulkAdd(prepared.orderItems);
      }
      if (prepared.payments.length > 0) {
        await db.payments.bulkAdd(prepared.payments);
      }

      await db.appMeta.put({
        key: META_KEYS.nextOrderNumber,
        value: prepared.nextOrderNumber,
      });
      await db.appMeta.put({
        key: META_KEYS.schemaVersion,
        value: 1,
      });
    },
  );

  return {
    clients: prepared.clients.length,
    orders: prepared.orders.length,
    orderItems: prepared.orderItems.length,
    payments: prepared.payments.length,
  };
}

export async function importBackupFromJson(
  text: string,
): Promise<ImportBackupResult> {
  let payload: unknown;
  try {
    payload = JSON.parse(text) as unknown;
  } catch {
    return {
      ok: false,
      issues: [
        {
          path: "root",
          message: "Файл не является корректным JSON",
        },
      ],
    };
  }

  const validated = validateBackupPayload(payload);
  if (!validated.ok) {
    return validated;
  }

  const counts = await replaceDatabaseWithBackup(validated.backup);
  return { ok: true, counts };
}

export async function clearAllData(): Promise<void> {
  const db = getDb();
  await db.transaction(
    "rw",
    db.clients,
    db.orders,
    db.orderItems,
    db.payments,
    db.appMeta,
    async () => {
      await Promise.all([
        db.clients.clear(),
        db.orders.clear(),
        db.orderItems.clear(),
        db.payments.clear(),
        db.appMeta.clear(),
      ]);
      await db.appMeta.put({ key: META_KEYS.nextOrderNumber, value: 1 });
      await db.appMeta.put({ key: META_KEYS.schemaVersion, value: 1 });
    },
  );
}

/** Minimal demo dataset — only on explicit user action. */
export async function seedDemoData(): Promise<BackupCounts> {
  const now = toIsoNow();
  const clientAId = createId();
  const clientBId = createId();
  const order1Id = createId();
  const order2Id = createId();
  const item1Id = createId();
  const item2Id = createId();
  const item3Id = createId();
  const payment1Id = createId();

  const clients: Client[] = [
    {
      id: clientAId,
      name: "Демо: Магазин на WB",
      contactName: "Анна",
      telegram: "@demo_anna",
      source: "Тестовые данные",
      notes: "Пример клиента для проверки интерфейса",
      isArchived: false,
      createdAt: now,
      updatedAt: now,
    },
    {
      id: clientBId,
      name: "Демо: Локальный бренд",
      phone: "+7 900 000-00-00",
      isArchived: false,
      createdAt: now,
      updatedAt: now,
    },
  ];

  const orderItems: OrderItem[] = [
    {
      id: item1Id,
      orderId: order1Id,
      name: "Дизайн карточки товара",
      quantity: 15,
      unit: "шт.",
      unitPriceMinor: 50000,
      lineTotalMinor: 750000,
      sortOrder: 0,
    },
    {
      id: item2Id,
      orderId: order1Id,
      name: "Редактирование описаний",
      quantity: 1,
      unit: "проект",
      unitPriceMinor: 100000,
      lineTotalMinor: 100000,
      sortOrder: 1,
    },
    {
      id: item3Id,
      orderId: order2Id,
      name: "Консультация",
      quantity: 2,
      unit: "час",
      unitPriceMinor: 250000,
      lineTotalMinor: 500000,
      sortOrder: 0,
    },
  ];

  const payments: Payment[] = [
    {
      id: payment1Id,
      orderId: order1Id,
      amountMinor: 400000,
      paymentDate: now,
      method: "bank_transfer",
      comment: "Частичная предоплата",
      createdAt: now,
    },
  ];

  const orders: Order[] = [
    {
      id: order1Id,
      number: 1,
      clientId: clientAId,
      title: "Карточки товара для Wildberries",
      status: "in_progress",
      orderDate: now,
      dueDate: now,
      discountMinor: 50000,
      totalMinor: 800000,
      paidMinor: 400000,
      notes: "Демо-заказ с несколькими позициями",
      isArchived: false,
      createdAt: now,
      updatedAt: now,
    },
    {
      id: order2Id,
      number: 2,
      clientId: clientBId,
      title: "Консультация по оформлению",
      status: "new",
      orderDate: now,
      discountMinor: 0,
      totalMinor: 500000,
      paidMinor: 0,
      isArchived: false,
      createdAt: now,
      updatedAt: now,
    },
  ];

  const backup: BackupFile = {
    app: BACKUP_APP_NAME,
    backupVersion: BACKUP_VERSION,
    exportedAt: now,
    data: { clients, orders, orderItems, payments },
  };

  const validated = validateBackupPayload(backup);
  if (!validated.ok) {
    throw new Error(
      validated.issues[0]?.message ?? "Не удалось подготовить тестовые данные",
    );
  }

  return replaceDatabaseWithBackup(validated.backup);
}

export function downloadJsonFile(filename: string, json: string): void {
  const blob = new Blob([json], { type: "application/json;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(url);
}

export function countLabel(counts: BackupCounts): string {
  return [
    `${counts.clients} клиентов`,
    `${counts.orders} заказов`,
    `${counts.orderItems} позиций`,
    `${counts.payments} платежей`,
  ].join(", ");
}
