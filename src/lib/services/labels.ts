import type { OrderStatus, PaymentMethod } from "@/types";

export const ORDER_STATUS_LABELS: Record<OrderStatus, string> = {
  new: "Новый",
  in_progress: "В работе",
  waiting: "Ожидает ответа/материалов",
  completed: "Завершён",
  cancelled: "Отменён",
};

export const PAYMENT_METHOD_LABELS: Record<PaymentMethod, string> = {
  cash: "Наличные",
  bank_transfer: "Перевод",
  other: "Другое",
};

export const DEFAULT_UNITS = ["шт.", "час", "комплект", "проект"] as const;
