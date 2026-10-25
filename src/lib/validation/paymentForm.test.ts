import { describe, expect, it } from "vitest";
import { validatePaymentForm } from "./paymentForm";

describe("validatePaymentForm", () => {
  it("accepts a partial payment within remaining", () => {
    const result = validatePaymentForm(
      {
        amount: "2500",
        paymentDate: "2026-10-23",
        method: "bank_transfer",
        comment: "Первый платёж",
      },
      750000,
    );

    expect(result.ok).toBe(true);
    if (!result.ok) return;
    expect(result.input.amountMinor).toBe(250000);
    expect(result.input.method).toBe("bank_transfer");
    expect(result.input.comment).toBe("Первый платёж");
  });

  it("rejects overpayment", () => {
    const result = validatePaymentForm(
      {
        amount: "1000",
        paymentDate: "2026-10-23",
        method: "cash",
        comment: "",
      },
      50000,
    );

    expect(result.ok).toBe(false);
    if (result.ok) return;
    expect(result.errors.amount).toMatch(/остаток/i);
  });

  it("rejects zero or invalid amount", () => {
    const result = validatePaymentForm(
      {
        amount: "0",
        paymentDate: "2026-10-23",
        method: "other",
        comment: "",
      },
      10000,
    );

    expect(result.ok).toBe(false);
  });
});
