import { describe, expect, it } from "vitest";
import {
  createEmptyOrderItem,
  orderFormSchema,
  parseOrderFormToInput,
} from "./orderForm";

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
