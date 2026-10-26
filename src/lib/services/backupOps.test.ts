import { describe, expect, it } from "vitest";
import {
  BACKUP_APP_NAME,
  validateBackupPayload,
} from "@/lib/services/backup";
import { prepareBackupEntities } from "@/lib/services/backupOps";
import type { BackupFile } from "@/types";

const clientId = "11111111-1111-4111-8111-111111111111";
const orderId = "22222222-2222-4222-8222-222222222222";
const itemId = "33333333-3333-4333-8333-333333333333";
const paymentId = "44444444-4444-4444-8444-444444444444";

function sampleBackup(overrides?: Partial<BackupFile["data"]>): BackupFile {
  return {
    app: BACKUP_APP_NAME,
    backupVersion: 1,
    exportedAt: "2026-10-23T07:00:00.000Z",
    data: {
      clients: [
        {
          id: clientId,
          name: "Клиент",
          isArchived: false,
          createdAt: "2026-10-01T10:00:00.000Z",
          updatedAt: "2026-10-01T10:00:00.000Z",
        },
      ],
      orders: [
        {
          id: orderId,
          number: 7,
          clientId,
          title: "Заказ",
          status: "in_progress",
          orderDate: "2026-10-05T10:00:00.000Z",
          discountMinor: 0,
          totalMinor: 100000,
          paidMinor: 40000,
          isArchived: false,
          createdAt: "2026-10-05T10:00:00.000Z",
          updatedAt: "2026-10-05T10:00:00.000Z",
        },
      ],
      orderItems: [
        {
          id: itemId,
          orderId,
          name: "Работа",
          quantity: 2,
          unit: "шт.",
          unitPriceMinor: 50000,
          lineTotalMinor: 100000,
          sortOrder: 0,
        },
      ],
      payments: [
        {
          id: paymentId,
          orderId,
          amountMinor: 40000,
          paymentDate: "2026-10-06T12:00:00.000Z",
          method: "cash",
          createdAt: "2026-10-06T12:00:00.000Z",
        },
      ],
      ...overrides,
    },
  };
}

describe("prepareBackupEntities", () => {
  it("recalculates totals and next order number from source data", () => {
    const backup = sampleBackup();
    // Lie in stored totals — prepare must recompute.
    backup.data.orders[0].totalMinor = 1;
    backup.data.orders[0].paidMinor = 1;
    backup.data.orderItems[0].lineTotalMinor = 1;

    const prepared = prepareBackupEntities(backup);
    expect(prepared.orderItems[0].lineTotalMinor).toBe(100000);
    expect(prepared.orders[0].totalMinor).toBe(100000);
    expect(prepared.orders[0].paidMinor).toBe(40000);
    expect(prepared.nextOrderNumber).toBe(8);
  });
});

describe("import validation gate", () => {
  it("rejects invalid JSON structure before any DB write would happen", () => {
    const result = validateBackupPayload({ app: "wrong" });
    expect(result.ok).toBe(false);
  });

  it("rejects broken relations without accepting the backup", () => {
    const backup = sampleBackup();
    backup.data.orders[0].clientId = "99999999-9999-4999-8999-999999999999";
    const result = validateBackupPayload(backup);
    expect(result.ok).toBe(false);
  });

  it("accepts a consistent backup ready for replace", () => {
    const result = validateBackupPayload(sampleBackup());
    expect(result.ok).toBe(true);
  });
});

describe("backups made with the previous rounding rule", () => {
  it("are accepted, reported and recalculated without touching the source data", () => {
    const backup = sampleBackup();
    // Previous rule: Math.round(1.005 × 100) = 100; the current exact rule gives 101.
    Object.assign(backup.data.orderItems[0], { quantity: 1.005, unitPriceMinor: 100, lineTotalMinor: 100 });
    Object.assign(backup.data.orders[0], { totalMinor: 100, paidMinor: 40 });
    backup.data.payments[0].amountMinor = 40;
    const source = structuredClone(backup);

    const result = validateBackupPayload(backup);
    expect(result.ok && result.recalculation).toEqual({ changedOrders: 1, totalDifferenceMinor: 1 });

    const prepared = prepareBackupEntities(backup);
    expect(prepared.orderItems[0]).toEqual({ ...source.data.orderItems[0], lineTotalMinor: 101 });
    expect(prepared.orders[0]).toEqual({ ...source.data.orders[0], totalMinor: 101 });
    expect(prepared.clients).toEqual(source.data.clients);
    expect(prepared.payments).toEqual(source.data.payments);
    expect(prepared.nextOrderNumber).toBe(8);
  });

  it("are rejected when the payments exceed the recalculated total", () => {
    const backup = sampleBackup();
    // 3 × 0.16666666666666666: previous rule Math.round(0.5) = 1, exact rule 0.49999999999999998 → 0.
    Object.assign(backup.data.orderItems[0], { quantity: 1 / 6, unitPriceMinor: 3, lineTotalMinor: 1 });
    Object.assign(backup.data.orders[0], { totalMinor: 1, paidMinor: 1 });
    backup.data.payments[0].amountMinor = 1;

    const result = validateBackupPayload(backup);
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.issues).toContainEqual({
        path: `orders.${orderId}`,
        message: "Сумма оплат превышает пересчитанный итог заказа",
      });
    }
  });
});
