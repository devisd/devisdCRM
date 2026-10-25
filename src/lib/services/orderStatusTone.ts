import type { OrderStatus, PaymentStatus } from "@/types";
import type { BadgeProps } from "@/components/ui/Badge";

export function orderStatusTone(
  status: OrderStatus,
): NonNullable<BadgeProps["tone"]> {
  switch (status) {
    case "completed":
      return "completed";
    case "cancelled":
      return "cancelled";
    case "in_progress":
      return "inProgress";
    case "waiting":
      return "waiting";
    case "new":
    default:
      return "new";
  }
}

export function paymentStatusTone(
  status: PaymentStatus,
): NonNullable<BadgeProps["tone"]> {
  switch (status) {
    case "paid":
      return "paid";
    case "partially_paid":
      return "partial";
    case "not_required":
      return "neutral";
    case "unpaid":
    default:
      return "unpaid";
  }
}
