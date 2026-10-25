import { z } from "zod";
import { parseRubToMinor } from "@/lib/format/currency";
import { dateInputToIso } from "@/lib/format/dates";
import { orderStatusSchema } from "@/lib/validation/schemas";
import { calcLineTotalMinor, calcOrderTotalMinor } from "@/lib/services/money";

const pieceUnits = new Set(["шт.", "шт", "штука", "штуки"]);

function parseQuantity(raw: string): number | null {
  const normalized = raw.trim().replace(",", ".");
  if (!normalized) return null;
  if (!/^\d+(\.\d+)?$/.test(normalized)) return null;
  const value = Number(normalized);
  if (!Number.isFinite(value) || value <= 0) return null;
  return value;
}

export const orderItemFormSchema = z
  .object({
    key: z.string().min(1),
    id: z.string().uuid().optional(),
    name: z.string().trim().min(1, "Укажите название позиции"),
    description: z.string().trim(),
    quantity: z.string().trim().min(1, "Укажите количество"),
    unit: z.string().trim().min(1, "Укажите единицу"),
    unitPrice: z.string().trim().min(1, "Укажите цену"),
  })
  .superRefine((item, ctx) => {
    const quantity = parseQuantity(item.quantity);
    if (quantity === null) {
      ctx.addIssue({
        code: "custom",
        path: ["quantity"],
        message: "Количество должно быть числом больше нуля",
      });
    } else if (pieceUnits.has(item.unit.toLowerCase()) || item.unit === "шт.") {
      if (!Number.isInteger(quantity)) {
        ctx.addIssue({
          code: "custom",
          path: ["quantity"],
          message: "Для штучных работ количество должно быть целым",
        });
      }
    }

    const price = parseRubToMinor(item.unitPrice);
    if (price === null || price < 0) {
      ctx.addIssue({
        code: "custom",
        path: ["unitPrice"],
        message: "Некорректная цена",
      });
    }
  });

export const orderFormSchema = z
  .object({
    clientId: z.string().uuid("Выберите клиента"),
    title: z.string().trim().min(1, "Укажите название заказа"),
    description: z.string().trim(),
    status: orderStatusSchema,
    orderDate: z.string().min(1, "Укажите дату заказа"),
    dueDate: z.string().trim(),
    discount: z.string().trim(),
    notes: z.string().trim(),
    items: z.array(orderItemFormSchema).min(1, "Добавьте хотя бы одну позицию"),
  })
  .superRefine((form, ctx) => {
    if (!dateInputToIso(form.orderDate)) {
      ctx.addIssue({
        code: "custom",
        path: ["orderDate"],
        message: "Некорректная дата заказа",
      });
    }

    if (form.dueDate && !dateInputToIso(form.dueDate)) {
      ctx.addIssue({
        code: "custom",
        path: ["dueDate"],
        message: "Некорректный срок",
      });
    }

    const discountRaw = form.discount.trim() === "" ? "0" : form.discount;
    const discountMinor = parseRubToMinor(discountRaw);
    if (discountMinor === null || discountMinor < 0) {
      ctx.addIssue({
        code: "custom",
        path: ["discount"],
        message: "Некорректная скидка",
      });
      return;
    }

    try {
      const lineTotals = form.items.map((item) => {
        const quantity = parseQuantity(item.quantity);
        const unitPriceMinor = parseRubToMinor(item.unitPrice);
        if (quantity === null || unitPriceMinor === null) {
          throw new Error("invalid item");
        }
        return calcLineTotalMinor(quantity, unitPriceMinor);
      });
      const subtotal = lineTotals.reduce((sum, value) => sum + value, 0);
      calcOrderTotalMinor(subtotal, discountMinor);
    } catch (error) {
      ctx.addIssue({
        code: "custom",
        path: ["discount"],
        message:
          error instanceof Error
            ? error.message
            : "Скидка не может превышать сумму позиций",
      });
    }
  });

export type OrderItemFormValues = z.infer<typeof orderItemFormSchema>;
export type OrderFormValues = z.infer<typeof orderFormSchema>;

export function createEmptyOrderItem(): OrderItemFormValues {
  return {
    key: crypto.randomUUID(),
    name: "",
    description: "",
    quantity: "1",
    unit: "шт.",
    unitPrice: "",
  };
}

export function createEmptyOrderFormValues(
  clientId = "",
): OrderFormValues {
  return {
    clientId,
    title: "",
    description: "",
    status: "new",
    orderDate: "",
    dueDate: "",
    discount: "0",
    notes: "",
    items: [createEmptyOrderItem()],
  };
}

export function parseOrderFormToInput(values: OrderFormValues) {
  const discountRaw = values.discount.trim() === "" ? "0" : values.discount;
  const discountMinor = parseRubToMinor(discountRaw);
  const orderDate = dateInputToIso(values.orderDate);
  if (discountMinor === null || orderDate === null) {
    throw new Error("Некорректные данные формы");
  }

  const dueDate = values.dueDate ? dateInputToIso(values.dueDate) : undefined;
  if (values.dueDate && !dueDate) {
    throw new Error("Некорректный срок");
  }

  const items = values.items.map((item, index) => {
    const quantity = parseQuantity(item.quantity);
    const unitPriceMinor = parseRubToMinor(item.unitPrice);
    if (quantity === null || unitPriceMinor === null) {
      throw new Error("Некорректная позиция");
    }

    return {
      id: item.id,
      name: item.name.trim(),
      description: item.description.trim() || undefined,
      quantity,
      unit: item.unit.trim(),
      unitPriceMinor,
      sortOrder: index,
    };
  });

  const emptyToUndefined = (value: string) => {
    const trimmed = value.trim();
    return trimmed === "" ? undefined : trimmed;
  };

  return {
    clientId: values.clientId,
    title: values.title.trim(),
    description: emptyToUndefined(values.description),
    status: values.status,
    orderDate,
    dueDate: dueDate ?? undefined,
    completedAt:
      values.status === "completed" ? (orderDate as string) : undefined,
    clearCompletedAt: values.status !== "completed",
    discountMinor,
    notes: emptyToUndefined(values.notes),
    items,
  };
}
