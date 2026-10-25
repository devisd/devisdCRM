import type { PaymentStatus } from "@/types";
import { calcRemainingMinor } from "./money";

export const PAYMENT_STATUS_LABELS: Record<PaymentStatus, string> = {
  unpaid: "Не оплачен",
  partially_paid: "Частично оплачен",
  paid: "Оплачен",
  not_required: "Не требуется",
};

export function calcPaymentStatus(
  totalMinor: number,
  paidMinor: number,
): PaymentStatus {
  if (totalMinor === 0) {
    return "not_required";
  }

  if (paidMinor <= 0) {
    return "unpaid";
  }

  if (paidMinor < totalMinor) {
    return "partially_paid";
  }

  return "paid";
}

export function getPaymentSummary(totalMinor: number, paidMinor: number) {
  const remainingMinor = calcRemainingMinor(totalMinor, paidMinor);
  const status = calcPaymentStatus(totalMinor, paidMinor);

  return {
    totalMinor,
    paidMinor,
    remainingMinor,
    status,
    statusLabel: PAYMENT_STATUS_LABELS[status],
  };
}
