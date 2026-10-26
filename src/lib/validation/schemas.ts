import { z } from "zod";

/**
 * String length limits shared with api/migrations/001_init.sql.
 * Zod 4 measures string length in Unicode code points — not UTF-16 units
 * (string.length) and not bytes — which is also how MySQL counts VARCHAR(n).
 *
 * short → VARCHAR(255), unit → VARCHAR(64): the same limit in characters.
 * long → MEDIUMTEXT: 20 000 code points are at most 80 000 UTF-8 bytes,
 * well within MEDIUMTEXT (16 MB); TEXT (65 535 bytes) would not fit 4-byte characters.
 */
export const TEXT_LIMITS = {
  short: 255,
  unit: 64,
  long: 20_000,
} as const;

/** Upper bound of MySQL INT UNSIGNED (orders.number, order_items.sort_order). */
export const MAX_UINT32 = 4_294_967_295;

export function maxLengthMessage(limit: number): string {
  return `Не более ${limit.toLocaleString("ru-RU")} символов`;
}

/** Whole non-negative kopecks (integer). */
export const minorAmountSchema = z
  .number()
  .int("Сумма должна быть целым числом копеек")
  .nonnegative("Сумма не может быть отрицательной")
  .finite();

/** Positive kopecks for payments. */
export const positiveMinorAmountSchema = z
  .number()
  .int("Сумма должна быть целым числом копеек")
  .positive("Сумма должна быть больше нуля")
  .finite();

export const isoDateTimeSchema = z.string().min(1);

export const orderStatusSchema = z.enum([
  "new",
  "in_progress",
  "waiting",
  "completed",
  "cancelled",
]);

export const paymentMethodSchema = z.enum([
  "cash",
  "bank_transfer",
  "other",
]);

export const paymentStatusSchema = z.enum([
  "unpaid",
  "partially_paid",
  "paid",
  "not_required",
]);

function blankToUndefined(value: unknown): unknown {
  if (typeof value !== "string") {
    return value;
  }
  const trimmed = value.trim();
  return trimmed === "" ? undefined : trimmed;
}

/** Optional non-empty text; empty string becomes undefined. */
function optionalTrimmed(maxLength?: number) {
  const text = z.string().min(1);
  return z.preprocess(
    blankToUndefined,
    (maxLength === undefined
      ? text
      : text.max(maxLength, maxLengthMessage(maxLength))
    ).optional(),
  );
}

function requiredTrimmed(requiredMessage: string, maxLength: number) {
  return z
    .string()
    .trim()
    .min(1, requiredMessage)
    .max(maxLength, maxLengthMessage(maxLength));
}

export const clientSchema = z.object({
  id: z.string().uuid(),
  name: requiredTrimmed("Укажите имя или название", TEXT_LIMITS.short),
  contactName: optionalTrimmed(TEXT_LIMITS.short),
  phone: optionalTrimmed(TEXT_LIMITS.short),
  email: z.preprocess(
    blankToUndefined,
    z
      .string()
      .max(TEXT_LIMITS.short, maxLengthMessage(TEXT_LIMITS.short))
      .email("Некорректный email")
      .optional(),
  ),
  telegram: optionalTrimmed(TEXT_LIMITS.short),
  maxContact: optionalTrimmed(TEXT_LIMITS.short),
  otherContact: optionalTrimmed(TEXT_LIMITS.long),
  source: optionalTrimmed(TEXT_LIMITS.short),
  notes: optionalTrimmed(TEXT_LIMITS.long),
  tags: z.array(z.string().trim().min(1)).optional(),
  isArchived: z.boolean(),
  createdAt: isoDateTimeSchema,
  updatedAt: isoDateTimeSchema,
});

export const orderItemSchema = z.object({
  id: z.string().uuid(),
  orderId: z.string().uuid(),
  name: requiredTrimmed("Укажите название позиции", TEXT_LIMITS.short),
  description: optionalTrimmed(TEXT_LIMITS.long),
  quantity: z
    .number()
    .positive("Количество должно быть больше нуля")
    .finite(),
  unit: requiredTrimmed("Укажите единицу измерения", TEXT_LIMITS.unit),
  unitPriceMinor: minorAmountSchema,
  lineTotalMinor: minorAmountSchema,
  sortOrder: z
    .number()
    .int()
    .nonnegative()
    .max(MAX_UINT32, "Порядок позиции вне допустимого диапазона"),
});

export const orderSchema = z.object({
  id: z.string().uuid(),
  number: z
    .number()
    .int()
    .positive()
    .max(MAX_UINT32, "Номер заказа вне допустимого диапазона"),
  clientId: z.string().uuid(),
  title: requiredTrimmed("Укажите название заказа", TEXT_LIMITS.short),
  description: optionalTrimmed(TEXT_LIMITS.long),
  status: orderStatusSchema,
  orderDate: isoDateTimeSchema,
  dueDate: optionalTrimmed(),
  completedAt: optionalTrimmed(),
  discountMinor: minorAmountSchema,
  totalMinor: minorAmountSchema,
  paidMinor: minorAmountSchema,
  notes: optionalTrimmed(TEXT_LIMITS.long),
  isArchived: z.boolean(),
  createdAt: isoDateTimeSchema,
  updatedAt: isoDateTimeSchema,
});

export const paymentSchema = z.object({
  id: z.string().uuid(),
  orderId: z.string().uuid(),
  amountMinor: positiveMinorAmountSchema,
  paymentDate: isoDateTimeSchema,
  method: paymentMethodSchema,
  comment: optionalTrimmed(TEXT_LIMITS.long),
  createdAt: isoDateTimeSchema,
});

export const backupDataSchema = z.object({
  clients: z.array(clientSchema),
  orders: z.array(orderSchema),
  orderItems: z.array(orderItemSchema),
  payments: z.array(paymentSchema),
});

export const backupFileSchema = z.object({
  app: z.literal("Devis Studio Work Tracker"),
  backupVersion: z.literal(1),
  exportedAt: isoDateTimeSchema,
  data: backupDataSchema,
});

export type Client = z.infer<typeof clientSchema>;
export type Order = z.infer<typeof orderSchema>;
export type OrderItem = z.infer<typeof orderItemSchema>;
export type Payment = z.infer<typeof paymentSchema>;
export type OrderStatus = z.infer<typeof orderStatusSchema>;
export type PaymentMethod = z.infer<typeof paymentMethodSchema>;
export type PaymentStatus = z.infer<typeof paymentStatusSchema>;
export type BackupFile = z.infer<typeof backupFileSchema>;
export type BackupData = z.infer<typeof backupDataSchema>;
