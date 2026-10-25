import { z } from "zod";

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

/** Optional non-empty text; empty string becomes undefined. */
const optionalTrimmed = z.preprocess((value) => {
  if (typeof value !== "string") {
    return value;
  }
  const trimmed = value.trim();
  return trimmed === "" ? undefined : trimmed;
}, z.string().min(1).optional());

export const clientSchema = z.object({
  id: z.string().uuid(),
  name: z.string().trim().min(1, "Укажите имя или название"),
  contactName: optionalTrimmed,
  phone: optionalTrimmed,
  email: z.preprocess((value) => {
    if (typeof value !== "string") {
      return value;
    }
    const trimmed = value.trim();
    return trimmed === "" ? undefined : trimmed;
  }, z.string().email("Некорректный email").optional()),
  telegram: optionalTrimmed,
  maxContact: optionalTrimmed,
  otherContact: optionalTrimmed,
  source: optionalTrimmed,
  notes: optionalTrimmed,
  tags: z.array(z.string().trim().min(1)).optional(),
  isArchived: z.boolean(),
  createdAt: isoDateTimeSchema,
  updatedAt: isoDateTimeSchema,
});

export const orderItemSchema = z.object({
  id: z.string().uuid(),
  orderId: z.string().uuid(),
  name: z.string().trim().min(1, "Укажите название позиции"),
  description: optionalTrimmed,
  quantity: z
    .number()
    .positive("Количество должно быть больше нуля")
    .finite(),
  unit: z.string().trim().min(1, "Укажите единицу измерения"),
  unitPriceMinor: minorAmountSchema,
  lineTotalMinor: minorAmountSchema,
  sortOrder: z.number().int().nonnegative(),
});

export const orderSchema = z.object({
  id: z.string().uuid(),
  number: z.number().int().positive(),
  clientId: z.string().uuid(),
  title: z.string().trim().min(1, "Укажите название заказа"),
  description: optionalTrimmed,
  status: orderStatusSchema,
  orderDate: isoDateTimeSchema,
  dueDate: optionalTrimmed,
  completedAt: optionalTrimmed,
  discountMinor: minorAmountSchema,
  totalMinor: minorAmountSchema,
  paidMinor: minorAmountSchema,
  notes: optionalTrimmed,
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
  comment: optionalTrimmed,
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
