import { describe, expect, it } from "vitest";
import { validateBackupPayload } from "./backup";
import { MAX_UINT32, TEXT_LIMITS } from "@/lib/validation/schemas";
import backupFixture from "./backup.fixture.json";

const clientId = "11111111-1111-4111-8111-111111111111";
const orderId = "22222222-2222-4222-8222-222222222222";
const itemId = "33333333-3333-4333-8333-333333333333";
const paymentId = "44444444-4444-4444-8444-444444444444";

function validBackup() {
  return {
    app: "Devis Studio Work Tracker",
    backupVersion: 1,
    exportedAt: "2026-10-23T07:00:00.000Z",
    data: {
      clients: [
        {
          id: clientId,
          name: "Тестовый клиент",
          isArchived: false,
          createdAt: "2026-10-01T10:00:00.000Z",
          updatedAt: "2026-10-01T10:00:00.000Z",
        },
      ],
      orders: [
        {
          id: orderId,
          number: 1,
          clientId,
          title: "Карточки товара для Wildberries",
          status: "in_progress",
          orderDate: "2026-10-05T10:00:00.000Z",
          discountMinor: 0,
          totalMinor: 750000,
          paidMinor: 250000,
          isArchived: false,
          createdAt: "2026-10-05T10:00:00.000Z",
          updatedAt: "2026-10-05T10:00:00.000Z",
        },
      ],
      orderItems: [
        {
          id: itemId,
          orderId,
          name: "Дизайн карточки товара",
          quantity: 15,
          unit: "шт.",
          unitPriceMinor: 50000,
          lineTotalMinor: 750000,
          sortOrder: 0,
        },
      ],
      payments: [
        {
          id: paymentId,
          orderId,
          amountMinor: 250000,
          paymentDate: "2026-10-06T12:00:00.000Z",
          method: "bank_transfer",
          createdAt: "2026-10-06T12:00:00.000Z",
        },
      ],
    },
  };
}

describe("validateBackupPayload", () => {
  it("accepts a valid backup", () => {
    const result = validateBackupPayload(validBackup());
    expect(result.ok).toBe(true);
  });

  it("accepts the backup fixture that api/tests/run.php imports into the server", () => {
    expect(validateBackupPayload(backupFixture)).toEqual({
      ok: true,
      backup: backupFixture,
      recalculation: { changedOrders: 0, totalDifferenceMinor: 0 },
    });
  });

  it("rejects wrong app name / version", () => {
    const payload = {
      ...validBackup(),
      app: "Other App",
    };
    const result = validateBackupPayload(payload);
    expect(result.ok).toBe(false);
  });

  it("rejects broken client relation", () => {
    const payload = validBackup();
    payload.data.orders[0].clientId = "99999999-9999-4999-8999-999999999999";
    const result = validateBackupPayload(payload);
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.issues.some((issue) => issue.path.includes("clientId"))).toBe(
        true,
      );
    }
  });

  it("rejects incorrect line arithmetic", () => {
    const payload = validBackup();
    payload.data.orderItems[0].lineTotalMinor = 1;
    const result = validateBackupPayload(payload);
    expect(result.ok).toBe(false);
  });

  it("rejects overpaid order", () => {
    const payload = validBackup();
    payload.data.payments[0].amountMinor = 800000;
    payload.data.orders[0].paidMinor = 800000;
    const result = validateBackupPayload(payload);
    expect(result.ok).toBe(false);
  });

  it("rejects duplicate entity ids", () => {
    const payload = validBackup();
    payload.data.clients.push({ ...payload.data.clients[0] });
    const result = validateBackupPayload(payload);
    expect(result.ok).toBe(false);
  });

  it("accepts values exactly at the variant C limits", () => {
    const payload = validBackup();
    const client = payload.data.clients[0] as Record<string, unknown>;
    client.name = "ж".repeat(TEXT_LIMITS.short);
    client.otherContact = "ж".repeat(TEXT_LIMITS.long);
    (payload.data.orderItems[0] as Record<string, unknown>).unit = "ж".repeat(TEXT_LIMITS.unit);
    payload.data.orders[0].number = MAX_UINT32;
    expect(validateBackupPayload(payload).ok).toBe(true);
  });

  it.each([
    ["data.clients.0.name", (p: ReturnType<typeof validBackup>) => {
      p.data.clients[0].name = "ж".repeat(TEXT_LIMITS.short + 1);
    }],
    ["data.clients.0.otherContact", (p: ReturnType<typeof validBackup>) => {
      (p.data.clients[0] as Record<string, unknown>).otherContact = "ж".repeat(TEXT_LIMITS.long + 1);
    }],
    ["data.orderItems.0.unit", (p: ReturnType<typeof validBackup>) => {
      p.data.orderItems[0].unit = "ж".repeat(TEXT_LIMITS.unit + 1);
    }],
    ["data.orders.0.number", (p: ReturnType<typeof validBackup>) => {
      p.data.orders[0].number = MAX_UINT32 + 1;
    }],
  ])("rejects a backup with %s over the limit", (path, mutate) => {
    const payload = validBackup();
    mutate(payload);
    const result = validateBackupPayload(payload);
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.issues.map((issue) => issue.path)).toContain(path);
    }
  });
});
