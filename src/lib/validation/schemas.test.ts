import { describe, expect, it } from "vitest";
import {
  clientSchema,
  MAX_UINT32,
  maxLengthMessage,
  orderItemSchema,
  orderSchema,
  paymentSchema,
  TEXT_LIMITS,
} from "./schemas";

const now = "2026-10-01T10:00:00.000Z";
const clientId = "11111111-1111-4111-8111-111111111111";
const orderId = "22222222-2222-4222-8222-222222222222";

function client(overrides: Record<string, unknown> = {}) {
  return {
    id: clientId,
    name: "Клиент",
    isArchived: false,
    createdAt: now,
    updatedAt: now,
    ...overrides,
  };
}

function order(overrides: Record<string, unknown> = {}) {
  return {
    id: orderId,
    number: 1,
    clientId,
    title: "Заказ",
    status: "new",
    orderDate: now,
    discountMinor: 0,
    totalMinor: 0,
    paidMinor: 0,
    isArchived: false,
    createdAt: now,
    updatedAt: now,
    ...overrides,
  };
}

function item(overrides: Record<string, unknown> = {}) {
  return {
    id: "33333333-3333-4333-8333-333333333333",
    orderId,
    name: "Позиция",
    quantity: 1,
    unit: "шт.",
    unitPriceMinor: 100,
    lineTotalMinor: 100,
    sortOrder: 0,
    ...overrides,
  };
}

function payment(overrides: Record<string, unknown> = {}) {
  return {
    id: "44444444-4444-4444-8444-444444444444",
    orderId,
    amountMinor: 100,
    paymentDate: now,
    method: "cash",
    createdAt: now,
    ...overrides,
  };
}

type Parse = (value: string) => { success: boolean; error?: { issues: { message: string }[] } };

const textFields: [string, Parse, number][] = [
  ["client.name", (v) => clientSchema.safeParse(client({ name: v })), TEXT_LIMITS.short],
  ["client.contactName", (v) => clientSchema.safeParse(client({ contactName: v })), TEXT_LIMITS.short],
  ["client.phone", (v) => clientSchema.safeParse(client({ phone: v })), TEXT_LIMITS.short],
  ["client.telegram", (v) => clientSchema.safeParse(client({ telegram: v })), TEXT_LIMITS.short],
  ["client.maxContact", (v) => clientSchema.safeParse(client({ maxContact: v })), TEXT_LIMITS.short],
  ["client.source", (v) => clientSchema.safeParse(client({ source: v })), TEXT_LIMITS.short],
  ["client.otherContact", (v) => clientSchema.safeParse(client({ otherContact: v })), TEXT_LIMITS.long],
  ["client.notes", (v) => clientSchema.safeParse(client({ notes: v })), TEXT_LIMITS.long],
  ["order.title", (v) => orderSchema.safeParse(order({ title: v })), TEXT_LIMITS.short],
  ["order.description", (v) => orderSchema.safeParse(order({ description: v })), TEXT_LIMITS.long],
  ["order.notes", (v) => orderSchema.safeParse(order({ notes: v })), TEXT_LIMITS.long],
  ["orderItem.name", (v) => orderItemSchema.safeParse(item({ name: v })), TEXT_LIMITS.short],
  ["orderItem.unit", (v) => orderItemSchema.safeParse(item({ unit: v })), TEXT_LIMITS.unit],
  ["orderItem.description", (v) => orderItemSchema.safeParse(item({ description: v })), TEXT_LIMITS.long],
  ["payment.comment", (v) => paymentSchema.safeParse(payment({ comment: v })), TEXT_LIMITS.long],
];

describe("limits match variant C", () => {
  it("uses VARCHAR(255), VARCHAR(64) and TEXT-safe bounds", () => {
    expect(TEXT_LIMITS).toEqual({ short: 255, unit: 64, long: 20_000 });
    expect(MAX_UINT32).toBe(4_294_967_295);
  });
});

describe.each(textFields)("%s length limit", (_label, parse, limit) => {
  it("accepts a Cyrillic string exactly at the limit", () => {
    expect(parse("ж".repeat(limit)).success).toBe(true);
  });

  it("rejects one character more with a Russian message", () => {
    const result = parse("ж".repeat(limit + 1));
    expect(result.success).toBe(false);
    expect(result.error?.issues[0]?.message).toBe(maxLengthMessage(limit));
  });

  it("counts an emoji as one code point, like MySQL VARCHAR", () => {
    expect("😀".length).toBe(2);
    // Zod 4 measures string .max() in Unicode code points, not UTF-16 units (string.length).
    const atLimit = `${"ж".repeat(limit - 1)}😀`;
    expect(atLimit.length).toBe(limit + 1);
    expect(parse(atLimit).success).toBe(true);
    expect(parse(`${"ж".repeat(limit)}😀`).success).toBe(false);
  });

  it("measures the length after trimming", () => {
    expect(parse(`  ${"ж".repeat(limit)}  `).success).toBe(true);
  });
});

describe("long text fields fit MEDIUMTEXT", () => {
  // MySQL MEDIUMTEXT capacity in bytes; TEXT holds only 65 535.
  const MEDIUMTEXT_BYTES = 16_777_215;
  const longFields = textFields.filter(([, , limit]) => limit === TEXT_LIMITS.long);
  const utf8Bytes = (value: string) => new TextEncoder().encode(value).length;

  // Checks this test's own field list only; api/migrations/001_init.sql is not read.
  it("lists the six long-text fields (labels only, not checked against SQL)", () => {
    expect(longFields.map(([label]) => label)).toEqual([
      "client.otherContact",
      "client.notes",
      "order.description",
      "order.notes",
      "orderItem.description",
      "payment.comment",
    ]);
  });

  it("worst case at the limit (4-byte characters) exceeds TEXT but fits MEDIUMTEXT", () => {
    const worstCase = "😀".repeat(TEXT_LIMITS.long);
    expect(utf8Bytes(worstCase)).toBe(80_000);
    expect(utf8Bytes(worstCase)).toBeGreaterThan(65_535);
    expect(utf8Bytes(worstCase)).toBeLessThanOrEqual(MEDIUMTEXT_BYTES);
  });

  it.each(longFields)("%s: 20 000 emoji pass, 20 001 code points are rejected", (_label, parse) => {
    expect(parse("😀".repeat(TEXT_LIMITS.long)).success).toBe(true);

    const tooLong = parse("😀".repeat(TEXT_LIMITS.long + 1));
    expect(tooLong.success).toBe(false);
    expect(tooLong.error?.issues[0]?.message).toBe(maxLengthMessage(TEXT_LIMITS.long));

    const mixed = `${"ж".repeat(TEXT_LIMITS.long - 1)}😀`;
    expect(parse(mixed).success).toBe(true);
    expect(parse(`${mixed}ж`).success).toBe(false);
  });
});

describe("client email limit", () => {
  const domain = "@example.com";

  it("accepts a 255-character address and rejects 256", () => {
    const atLimit = `${"a".repeat(TEXT_LIMITS.short - domain.length)}${domain}`;
    expect(atLimit.length).toBe(255);
    expect(clientSchema.safeParse(client({ email: atLimit })).success).toBe(true);

    const result = clientSchema.safeParse(client({ email: `a${atLimit}` }));
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues[0]?.message).toBe(maxLengthMessage(TEXT_LIMITS.short));
    }
  });

  it("still rejects an invalid address", () => {
    expect(clientSchema.safeParse(client({ email: "not-an-email" })).success).toBe(false);
  });
});

describe("numeric ranges", () => {
  it("orders.number: 1…4 294 967 295", () => {
    for (const number of [1, MAX_UINT32]) {
      expect(orderSchema.safeParse(order({ number })).success).toBe(true);
    }
    for (const number of [0, -1, 1.5, MAX_UINT32 + 1]) {
      expect(orderSchema.safeParse(order({ number })).success).toBe(false);
    }
  });

  it("order_items.sort_order: 0…4 294 967 295", () => {
    for (const sortOrder of [0, MAX_UINT32]) {
      expect(orderItemSchema.safeParse(item({ sortOrder })).success).toBe(true);
    }
    for (const sortOrder of [-1, 0.5, MAX_UINT32 + 1]) {
      expect(orderItemSchema.safeParse(item({ sortOrder })).success).toBe(false);
    }
  });
});

describe("optional text semantics", () => {
  it("keeps undefined, empty and blank strings as undefined", () => {
    for (const value of [undefined, "", "   "]) {
      const parsed = clientSchema.safeParse(client({ contactName: value, email: value }));
      expect(parsed.success).toBe(true);
      if (parsed.success) {
        expect(parsed.data.contactName).toBeUndefined();
        expect(parsed.data.email).toBeUndefined();
      }
    }
  });

  it("trims non-empty values", () => {
    const parsed = clientSchema.safeParse(client({ contactName: "  Анна  " }));
    expect(parsed.success).toBe(true);
    if (parsed.success) {
      expect(parsed.data.contactName).toBe("Анна");
    }
  });

  it("rejects null", () => {
    expect(clientSchema.safeParse(client({ contactName: null })).success).toBe(false);
    expect(clientSchema.safeParse(client({ email: null })).success).toBe(false);
  });
});
