import { z } from "zod";
import { parseRubToMinor } from "@/lib/format/currency";
import { dateInputToIso } from "@/lib/format/dates";
import {
  maxLengthMessage,
  paymentMethodSchema,
  TEXT_LIMITS,
} from "@/lib/validation/schemas";
import { assertPaymentWithinRemaining } from "@/lib/services/money";

export const paymentFormSchema = z.object({
  amount: z.string().trim().min(1, "Укажите сумму"),
  paymentDate: z.string().min(1, "Укажите дату оплаты"),
  method: paymentMethodSchema,
  comment: z
    .string()
    .trim()
    .max(TEXT_LIMITS.long, maxLengthMessage(TEXT_LIMITS.long)),
});

export type PaymentFormValues = z.infer<typeof paymentFormSchema>;

export function createEmptyPaymentFormValues(
  remainingMinor: number,
  paymentDate: string,
): PaymentFormValues {
  const amount =
    remainingMinor > 0
      ? (remainingMinor / 100).toFixed(remainingMinor % 100 === 0 ? 0 : 2).replace(
          ".",
          ",",
        )
      : "";

  return {
    amount,
    paymentDate,
    method: "bank_transfer",
    comment: "",
  };
}

export function parsePaymentFormToInput(
  values: PaymentFormValues,
  remainingMinor: number,
) {
  const amountMinor = parseRubToMinor(values.amount);
  if (amountMinor === null || amountMinor <= 0) {
    throw new Error("Сумма должна быть больше нуля");
  }

  const paymentDate = dateInputToIso(values.paymentDate);
  if (!paymentDate) {
    throw new Error("Некорректная дата оплаты");
  }

  assertPaymentWithinRemaining(remainingMinor, amountMinor);

  const comment = values.comment.trim();

  return {
    amountMinor,
    paymentDate,
    method: values.method,
    comment: comment === "" ? undefined : comment,
  };
}

export function validatePaymentForm(
  values: PaymentFormValues,
  remainingMinor: number,
):
  | { ok: true; input: ReturnType<typeof parsePaymentFormToInput> }
  | { ok: false; errors: Record<string, string> } {
  const parsed = paymentFormSchema.safeParse(values);
  if (!parsed.success) {
    const errors: Record<string, string> = {};
    for (const issue of parsed.error.issues) {
      const key = String(issue.path[0] ?? "form");
      if (!errors[key]) {
        errors[key] = issue.message;
      }
    }
    return { ok: false, errors };
  }

  try {
    const input = parsePaymentFormToInput(parsed.data, remainingMinor);
    return { ok: true, input };
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "Некорректные данные платежа";
    const errors: Record<string, string> = {};
    if (message.toLowerCase().includes("дат")) {
      errors.paymentDate = message;
    } else {
      errors.amount = message;
    }
    return { ok: false, errors };
  }
}
