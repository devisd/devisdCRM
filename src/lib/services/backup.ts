import {
  backupFileSchema,
  type BackupFile,
  type OrderItem,
} from "@/lib/validation/schemas";
import {
  calcLineTotalMinor,
  calcOrderTotalMinor,
  calcPaidMinor,
  calcItemsSubtotalMinor,
} from "./money";

export const BACKUP_APP_NAME = "Devis Studio Work Tracker" as const;
export const BACKUP_VERSION = 1 as const;

export type BackupValidationIssue = {
  path: string;
  message: string;
};

export type BackupValidationResult =
  | { ok: true; backup: BackupFile }
  | { ok: false; issues: BackupValidationIssue[] };

function uniqueIds(ids: string[], label: string): BackupValidationIssue[] {
  const seen = new Set<string>();
  const issues: BackupValidationIssue[] = [];

  for (const id of ids) {
    if (seen.has(id)) {
      issues.push({
        path: label,
        message: `Дублирующийся ID: ${id}`,
      });
    }
    seen.add(id);
  }

  return issues;
}

function validateArithmetic(backup: BackupFile): BackupValidationIssue[] {
  const issues: BackupValidationIssue[] = [];
  const itemsByOrder = new Map<string, OrderItem[]>();

  for (const item of backup.data.orderItems) {
    const list = itemsByOrder.get(item.orderId) ?? [];
    list.push(item);
    itemsByOrder.set(item.orderId, list);
  }

  const paymentsByOrder = new Map<string, typeof backup.data.payments>();
  for (const payment of backup.data.payments) {
    const list = paymentsByOrder.get(payment.orderId) ?? [];
    list.push(payment);
    paymentsByOrder.set(payment.orderId, list);
  }

  for (const order of backup.data.orders) {
    const items = itemsByOrder.get(order.id) ?? [];
    if (items.length === 0) {
      issues.push({
        path: `orders.${order.id}`,
        message: "У заказа должна быть хотя бы одна позиция",
      });
      continue;
    }

    for (const item of items) {
      try {
        const expected = calcLineTotalMinor(item.quantity, item.unitPriceMinor);
        if (item.lineTotalMinor !== expected) {
          issues.push({
            path: `orderItems.${item.id}`,
            message: `Неверная сумма позиции: ожидалось ${expected}, получено ${item.lineTotalMinor}`,
          });
        }
      } catch (error) {
        issues.push({
          path: `orderItems.${item.id}`,
          message:
            error instanceof Error
              ? error.message
              : "Ошибка расчёта позиции",
        });
      }
    }

    try {
      const subtotal = calcItemsSubtotalMinor(
        items.map((item) => ({
          lineTotalMinor: calcLineTotalMinor(item.quantity, item.unitPriceMinor),
        })),
      );
      const expectedTotal = calcOrderTotalMinor(subtotal, order.discountMinor);
      if (order.totalMinor !== expectedTotal) {
        issues.push({
          path: `orders.${order.id}.totalMinor`,
          message: `Неверный итог заказа: ожидалось ${expectedTotal}, получено ${order.totalMinor}`,
        });
      }

      const payments = paymentsByOrder.get(order.id) ?? [];
      const expectedPaid = calcPaidMinor(payments);
      if (order.paidMinor !== expectedPaid) {
        issues.push({
          path: `orders.${order.id}.paidMinor`,
          message: `Неверная сумма оплат: ожидалось ${expectedPaid}, получено ${order.paidMinor}`,
        });
      }

      if (expectedPaid > expectedTotal) {
        issues.push({
          path: `orders.${order.id}`,
          message: "Сумма оплат превышает итог заказа",
        });
      }
    } catch (error) {
      issues.push({
        path: `orders.${order.id}`,
        message:
          error instanceof Error ? error.message : "Ошибка расчёта заказа",
      });
    }
  }

  return issues;
}

function validateRelations(backup: BackupFile): BackupValidationIssue[] {
  const issues: BackupValidationIssue[] = [];
  const clientIds = new Set(backup.data.clients.map((client) => client.id));
  const orderIds = new Set(backup.data.orders.map((order) => order.id));

  for (const order of backup.data.orders) {
    if (!clientIds.has(order.clientId)) {
      issues.push({
        path: `orders.${order.id}.clientId`,
        message: `Заказ ссылается на несуществующего клиента ${order.clientId}`,
      });
    }
  }

  for (const item of backup.data.orderItems) {
    if (!orderIds.has(item.orderId)) {
      issues.push({
        path: `orderItems.${item.id}.orderId`,
        message: `Позиция ссылается на несуществующий заказ ${item.orderId}`,
      });
    }
  }

  for (const payment of backup.data.payments) {
    if (!orderIds.has(payment.orderId)) {
      issues.push({
        path: `payments.${payment.id}.orderId`,
        message: `Платёж ссылается на несуществующий заказ ${payment.orderId}`,
      });
    }
  }

  const orderNumbers = backup.data.orders.map((order) => order.number);
  const numberSet = new Set<number>();
  for (const number of orderNumbers) {
    if (numberSet.has(number)) {
      issues.push({
        path: "orders.number",
        message: `Дублирующийся номер заказа: ${number}`,
      });
    }
    numberSet.add(number);
  }

  return issues;
}

export function validateBackupPayload(payload: unknown): BackupValidationResult {
  const parsed = backupFileSchema.safeParse(payload);
  if (!parsed.success) {
    return {
      ok: false,
      issues: parsed.error.issues.map((issue) => ({
        path: issue.path.join(".") || "root",
        message: issue.message,
      })),
    };
  }

  const backup = parsed.data;
  const issues: BackupValidationIssue[] = [
    ...uniqueIds(
      backup.data.clients.map((client) => client.id),
      "clients",
    ),
    ...uniqueIds(
      backup.data.orders.map((order) => order.id),
      "orders",
    ),
    ...uniqueIds(
      backup.data.orderItems.map((item) => item.id),
      "orderItems",
    ),
    ...uniqueIds(
      backup.data.payments.map((payment) => payment.id),
      "payments",
    ),
    ...validateRelations(backup),
    ...validateArithmetic(backup),
  ];

  if (issues.length > 0) {
    return { ok: false, issues };
  }

  return { ok: true, backup };
}

export function buildBackupFilename(date = new Date()): string {
  const yyyy = date.getFullYear();
  const mm = String(date.getMonth() + 1).padStart(2, "0");
  const dd = String(date.getDate()).padStart(2, "0");
  return `devis-studio-backup-${yyyy}-${mm}-${dd}.json`;
}
