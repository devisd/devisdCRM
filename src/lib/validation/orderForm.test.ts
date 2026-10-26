import { describe, expect, it } from "vitest";
import {
  createEmptyOrderItem,
  orderFormSchema,
  parseOrderFormToInput,
  type OrderFormValues,
} from "./orderForm";
import { maxLengthMessage, TEXT_LIMITS } from "./schemas";

function validOrderForm(): OrderFormValues {
  return {
    clientId: "11111111-1111-4111-8111-111111111111",
    title: "Заказ",
    description: "",
    status: "new",
    orderDate: "2026-10-23",
    dueDate: "",
    discount: "0",
    notes: "",
    items: [
      {
        ...createEmptyOrderItem(),
        name: "Позиция",
        quantity: "1",
        unit: "шт.",
        unitPrice: "100",
      },
    ],
  };
}

function issueAt(values: OrderFormValues, path: string) {
  const parsed = orderFormSchema.safeParse(values);
  if (parsed.success) return undefined;
  return parsed.error.issues.find((issue) => issue.path.join(".") === path)?.message;
}

describe("orderFormSchema length limits", () => {
  const orderFields: ["title" | "description" | "notes", number][] = [
    ["title", TEXT_LIMITS.short],
    ["description", TEXT_LIMITS.long],
    ["notes", TEXT_LIMITS.long],
  ];

  it.each(orderFields)("%s: accepts the limit, rejects one more", (field, limit) => {
    expect(orderFormSchema.safeParse({ ...validOrderForm(), [field]: "ж".repeat(limit) }).success).toBe(true);
    expect(issueAt({ ...validOrderForm(), [field]: "ж".repeat(limit + 1) }, field)).toBe(
      maxLengthMessage(limit),
    );
  });

  const itemFields: ["name" | "unit" | "description", number][] = [
    ["name", TEXT_LIMITS.short],
    ["unit", TEXT_LIMITS.unit],
    ["description", TEXT_LIMITS.long],
  ];

  it.each(itemFields)("items.%s: accepts the limit, rejects one more", (field, limit) => {
    const withValue = (value: string): OrderFormValues => {
      const values = validOrderForm();
      values.items[0] = { ...values.items[0], [field]: value };
      return values;
    };

    expect(orderFormSchema.safeParse(withValue("ж".repeat(limit))).success).toBe(true);
    expect(issueAt(withValue("ж".repeat(limit + 1)), `items.0.${field}`)).toBe(
      maxLengthMessage(limit),
    );
  });
});

describe("orderForm 15 cards scenario", () => {
  it("accepts an order with 15 piece items and calculates totals", () => {
    const values = {
      clientId: "11111111-1111-4111-8111-111111111111",
      title: "Карточки товара для Wildberries",
      description: "",
      status: "new" as const,
      orderDate: "2026-10-23",
      dueDate: "",
      discount: "0",
      notes: "",
      items: [
        {
          ...createEmptyOrderItem(),
          name: "Дизайн карточки товара",
          quantity: "15",
          unit: "шт.",
          unitPrice: "500",
        },
      ],
    };

    const parsed = orderFormSchema.safeParse(values);
    expect(parsed.success).toBe(true);
    if (!parsed.success) return;

    const input = parseOrderFormToInput(parsed.data);
    expect(input.items[0].quantity).toBe(15);
    expect(input.items[0].unitPriceMinor).toBe(50000);
    expect(input.discountMinor).toBe(0);
  });

  it("rejects fractional quantity for piece units", () => {
    const parsed = orderFormSchema.safeParse({
      clientId: "11111111-1111-4111-8111-111111111111",
      title: "Тест",
      description: "",
      status: "new",
      orderDate: "2026-10-23",
      dueDate: "",
      discount: "0",
      notes: "",
      items: [
        {
          ...createEmptyOrderItem(),
          name: "Карточка",
          quantity: "1.5",
          unit: "шт.",
          unitPrice: "100",
        },
      ],
    });

    expect(parsed.success).toBe(false);
  });
});
