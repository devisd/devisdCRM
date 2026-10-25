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
